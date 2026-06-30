<?php
/**
 * Filename: class-wxr-importer.php
 * Author: wordpressdotorg, rmccue
 * Created: 2015-01-01
 * Version: 2.0.1
 * Last Modified: 2025-12-30
 * Description: Main importer class for WordPress XML (WXR) files
 */

class WXR_Importer extends WP_Importer {
	/**
	 * Maximum supported WXR version
	 */
	const MAX_WXR_VERSION = 1.2;

	/**
	 * Regular expression for checking if a post references an attachment
	 *
	 * Note: This is a quick, weak check just to exclude text-only posts. More
	 * vigorous checking is done later to verify.
	 */
	const REGEX_HAS_ATTACHMENT_REFS = '!
		(
			# Match anything with an image or attachment class
			class=[\'"].*?\b(wp-image-\d+|attachment-[\w\-]+)\b
		|
			# Match anything that looks like an upload URL
			src=[\'"][^\'"]*(
				[0-9]{4}/[0-9]{2}/[^\'"]+\.(jpg|jpeg|png|gif)
			|
				content/uploads[^\'"]+
			)[\'"]
		)!ix';

	/**
	 * Version of WXR we're importing.
	 *
	 * Defaults to 1.0 for compatibility. Typically overridden by a
	 * `<wp:wxr_version>` tag at the start of the file.
	 *
	 * @var string
	 */
	protected $version = '1.0';

	// information to import from WXR file
	protected $categories = array();
	protected $tags = array();
	protected $base_url = '';

	// Menu items that reference objects not yet imported; retried during remapping.
	protected $missing_menu_items = array();

	// NEW STYLE
	protected $mapping = array();
	protected $requires_remapping = array();
	protected $exists = array();
	protected $user_slug_override = array();

	protected $url_remap = array();
	protected $featured_images = array();

	/**
	 * Attachments queued for deferred download (post_id => data).
	 *
	 * @since 3.0.0
	 * @var array<int, array<string, mixed>>
	 */
	protected $pending_attachments = array();

	/**
	 * Whether import_start() has run for the current batch session.
	 *
	 * @since 3.0.0
	 * @var bool
	 */
	protected $import_batch_started = false;

	/**
	 * Logger instance.
	 *
	 * @var WP_Importer_Logger
	 */
	protected $logger;

	/**
	 * Constructor
	 *
	 * @param array $options {
	 *     @var bool $prefill_existing_posts Should we prefill `post_exists` calls? (True prefills and uses more memory, false checks once per imported post and takes longer. Default is true.)
	 *     @var bool $prefill_existing_comments Should we prefill `comment_exists` calls? (True prefills and uses more memory, false checks once per imported comment and takes longer. Default is true.)
	 *     @var bool $prefill_existing_terms Should we prefill `term_exists` calls? (True prefills and uses more memory, false checks once per imported term and takes longer. Default is true.)
	 *     @var bool $update_attachment_guids Should attachment GUIDs be updated to the new URL? (True updates the GUID, which keeps compatibility with v1, false doesn't update, and allows deduplication and reimporting. Default is false.)
	 *     @var bool $fetch_attachments Fetch attachments from the remote server. (True fetches and creates attachment posts, false skips attachments. Default is false.)
	 *     @var bool $aggressive_url_search Should we search/replace for URLs aggressively? (True searches all posts' content for old URLs and replaces, false checks for `<img class="wp-image-*">` only. Default is false.)
	 *     @var int $default_author User ID to use if author is missing or invalid. (Default is null, which leaves posts unassigned.)
	 * }
	 */
	public function __construct( $options = array() ) {
		// Initialize some important variables
		$empty_types = array(
			'post'    => array(),
			'comment' => array(),
			'term'    => array(),
			'user'    => array(),
		);

		$this->mapping = $empty_types;
		$this->mapping['user_slug'] = array();
		$this->mapping['term_id'] = array();
		$this->requires_remapping = $empty_types;
		$this->exists = $empty_types;

		$this->options = wp_parse_args( $options, array(
			'prefill_existing_posts'    => true,
			'prefill_existing_comments' => true,
			'prefill_existing_terms'    => true,
			'update_attachment_guids'   => false,
			'fetch_attachments'         => false,
			'defer_attachment_download' => false,
			'aggressive_url_search'     => false,
			'default_author'            => null,
			'cache_flush_interval'      => 200,
			'job_id'                    => 0,
			'post_meta_chunk_size'      => 25,
		) );
	}

	public function set_logger( $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Safely expand an XML node, handling errors gracefully.
	 *
	 * @param XMLReader $reader The XML reader instance.
	 * @param string $node_type Optional node type for error messages.
	 * @return DOMElement|false The expanded node, or false on error.
	 */
	protected function safe_expand( $reader, $node_type = 'XML' ) {
		// Clear any previous XML errors
		libxml_clear_errors();

		// Suppress the PHP warning that XMLReader::expand() emits on malformed nodes —
		// we handle the false return value ourselves below.
		$node = @$reader->expand();

		if ( false === $node ) {
			$xml_errors = libxml_get_errors();
			libxml_clear_errors();

			if ( $this->logger ) {
				$error_msg = sprintf( __( 'Skipping malformed %s node. The export file may be corrupted.', 'wordpress-importer' ), $node_type );
				$first_error = reset( $xml_errors );
				if ( $first_error && isset( $first_error->message ) ) {
					$error_msg .= ' ' . sprintf( __( 'Line %d: %s', 'wordpress-importer' ), $first_error->line, trim( $first_error->message ) );
				}
				$this->logger->warning( $error_msg );
			}
		}

		return $node;
	}

	/**
	 * Get a stream reader for the file.
	 *
	 * @param string $file Path to the XML file.
	 * @return XMLReader|WP_Error Reader instance on success, error otherwise.
	 */
	protected function get_reader( $file ) {
		// Avoid loading external entities for security
		// Note: libxml_disable_entity_loader is deprecated in PHP 8.0+
		// XMLReader now disables external entity loading by default in PHP 8.0+
		$old_value = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			$old_value = libxml_disable_entity_loader( true );
		}

		// Suppress XML errors and handle them manually
		libxml_use_internal_errors( true );
		libxml_clear_errors();

		$reader = new XMLReader();
		$status = $reader->open( $file );

		if ( ! is_null( $old_value ) && PHP_VERSION_ID < 80000 ) {
			libxml_disable_entity_loader( $old_value );
		}

		if ( ! $status ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			libxml_use_internal_errors( false );
			return new WP_Error( 'wxr_importer.cannot_parse', __( 'Could not open the file for parsing', 'wordpress-importer' ) );
		}

		return $reader;
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	public function get_preliminary_information( $file ) {
		// Suppress XML errors and handle them manually
		libxml_use_internal_errors( true );
		libxml_clear_errors();

		$reader = $this->get_reader( $file );
		if ( is_wp_error( $reader ) ) {
			libxml_use_internal_errors( false );
			return $reader;
		}

		// Raise memory limit — preliminary parse of a large file can be heavy
		wp_raise_memory_limit( 'admin' );
		set_time_limit( 0 );

		// Set the version to compatibility mode first
		$this->version = '1.0';

		$data = new WXR_Import_Info();
		$data->item_positions = array();

		// Track depth so we can read child elements of <item> without expand()
		$in_item        = false;
		$current_type   = '';

		while ( $reader->read() ) {

			// ── Entering a node ───────────────────────────────────
			if ( $reader->nodeType === XMLReader::ELEMENT ) {

				if ( $in_item ) {
					// We're inside an <item> — grab only what we need
					if ( $reader->name === 'wp:post_type' ) {
						$current_type = $reader->readString();
					}
					// Skip everything else inside the item — no expand()
					continue;
				}

				switch ( $reader->name ) {
					case 'wp:wxr_version':
						$this->version = $reader->readString();
						if ( version_compare( $this->version, self::MAX_WXR_VERSION, '>' ) && $this->logger ) {
							$this->logger->warning( sprintf(
								__( 'This WXR file (version %s) is newer than the importer (version %s) and may not be supported. Please consider updating.', 'wordpress-importer' ),
								$this->version,
								self::MAX_WXR_VERSION
							) );
						}
						$reader->next();
						break;

					case 'generator':
						$data->generator = $reader->readString();
						$reader->next();
						break;

					case 'title':
						// Only grab the channel title, not post titles
						if ( ! $in_item ) {
							$data->title = $reader->readString();
						}
						$reader->next();
						break;

					case 'wp:base_site_url':
						$data->siteurl = $reader->readString();
						$reader->next();
						break;

					case 'wp:base_blog_url':
						$data->home = $reader->readString();
						$reader->next();
						break;

					case 'wp:author':
						$node = $this->safe_expand( $reader, 'author' );
						if ( false === $node ) {
							$reader->next();
							break;
						}
						$parsed = $this->parse_author_node( $node );
						if ( is_wp_error( $parsed ) ) {
							$this->log_error( $parsed );
							$reader->next();
							break;
						}
						$data->users[] = $parsed;
						$reader->next();
						break;

					case 'item':
						// Enter item context — we'll count it when we exit
						$in_item      = true;
						$current_type = '';
						$byte_offset  = 0;
						if ( defined( 'XMLReader::PROPERTY_BYTE_OFFSET' ) ) {
							$byte_offset = (int) $reader->getProperty( XMLReader::PROPERTY_BYTE_OFFSET );
						}
						$data->item_positions[] = $byte_offset;
						break;

					case 'wp:category':
					case 'wp:tag':
					case 'wp:term':
						$data->term_count++;
						$reader->next();
						break;
				}

			// ── Leaving a node ────────────────────────────────────
			} elseif ( $reader->nodeType === XMLReader::END_ELEMENT ) {

				if ( $reader->name === 'item' && $in_item ) {
					// Tally the item we just finished reading
					if ( $current_type === 'attachment' ) {
						$data->media_count++;
					} elseif ( ! empty( $current_type ) ) {
						$data->post_count++;
					}
					$in_item      = false;
					$current_type = '';
				}
			}
		}

		$data->version = $this->version;

		libxml_clear_errors();
		libxml_use_internal_errors( false );

		return $data;
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	public function parse_authors( $file ) {
		// Suppress XML errors and handle them manually
		libxml_use_internal_errors( true );
		libxml_clear_errors();
		
		// Let's run the actual importer now, woot
		$reader = $this->get_reader( $file );
		if ( is_wp_error( $reader ) ) {
			libxml_use_internal_errors( false );
			return $reader;
		}

		// Set the version to compatibility mode first
		$this->version = '1.0';

		// Start parsing!
		$authors = array();
		while ( $reader->read() ) {
			// Only deal with element opens
			if ( $reader->nodeType !== XMLReader::ELEMENT ) {
				continue;
			}

			switch ( $reader->name ) {
				case 'wp:wxr_version':
					// Upgrade to the correct version
					$this->version = $reader->readString();

					if ( version_compare( $this->version, self::MAX_WXR_VERSION, '>' ) && $this->logger ) {
						$this->logger->warning( sprintf(
							__( 'This WXR file (version %s) is newer than the importer (version %s) and may not be supported. Please consider updating.', 'wordpress-importer' ),
							$this->version,
							self::MAX_WXR_VERSION
						) );
					}

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:author':
					$node = $this->safe_expand( $reader, 'author' );
					if ( false === $node ) {
						$reader->next();
						break;
					}

					$parsed = $this->parse_author_node( $node );
					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$authors[] = $parsed;

					// Handled everything in this node, move on to the next
					$reader->next();
					break;
			}
		}

		return $authors;
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	public function import( $file ) {
		// Suppress XML errors and handle them manually
		libxml_use_internal_errors( true );
		libxml_clear_errors();
		
		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		$result = $this->import_start( $file );
		if ( is_wp_error( $result ) ) {
			libxml_use_internal_errors( false );
			return $result;
		}

		// Let's run the actual importer now, woot
		$reader = $this->get_reader( $file );
		if ( is_wp_error( $reader ) ) {
			libxml_use_internal_errors( false );
			return $reader;
		}

		// Set the version to compatibility mode first
		$this->version = '1.0';

		// Reset other variables
		$this->base_url = '';

		// Start parsing!
		while ( $reader->read() ) {
			// Only deal with element opens
			if ( $reader->nodeType !== XMLReader::ELEMENT ) {
				continue;
			}

			switch ( $reader->name ) {
				case 'wp:wxr_version':
					// Upgrade to the correct version
					$this->version = $reader->readString();

					if ( version_compare( $this->version, self::MAX_WXR_VERSION, '>' ) ) {
						$this->logger->warning( sprintf(
							__( 'This WXR file (version %s) is newer than the importer (version %s) and may not be supported. Please consider updating.', 'wordpress-importer' ),
							$this->version,
							self::MAX_WXR_VERSION
						) );
					}

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:base_site_url':
					$this->base_url = $reader->readString();

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'item':
					$node = $this->safe_expand( $reader, 'item' );
					if ( false === $node ) {
						$reader->next();
						break;
					}
					
					$parsed = $this->parse_post_node( $node );
					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$this->process_post( $parsed['data'], $parsed['meta'], $parsed['comments'], $parsed['terms'] );

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:author':
					$node = $reader->expand();
					if ( false === $node ) {
						if ( $this->logger ) {
							$this->logger->warning( __( 'Skipping malformed author node.', 'wordpress-importer' ) );
						}
						$reader->next();
						break;
					}

					$parsed = $this->parse_author_node( $node );
					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$status = $this->process_author( $parsed['data'], $parsed['meta'] );
					if ( is_wp_error( $status ) ) {
						$this->log_error( $status );
					}

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:category':
					$node = $this->safe_expand( $reader, 'category' );
					if ( false === $node ) {
						$reader->next();
						break;
					}

					$parsed = $this->parse_term_node( $node, 'category' );
					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$status = $this->process_term( $parsed['data'], $parsed['meta'] );

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:tag':
					$node = $this->safe_expand( $reader, 'tag' );
					if ( false === $node ) {
						$reader->next();
						break;
					}

					$parsed = $this->parse_term_node( $node, 'tag' );
					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$status = $this->process_term( $parsed['data'], $parsed['meta'] );

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:term':
					$node = $this->safe_expand( $reader, 'term' );
					if ( false === $node ) {
						$reader->next();
						break;
					}

					$parsed = $this->parse_term_node( $node );
					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$status = $this->process_term( $parsed['data'], $parsed['meta'] );

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				default:
					// Skip this node, probably handled by something already
					break;
			}
		}

		// Now that we've done the main processing, do any required
		// post-processing and remapping.
		$this->post_process();

		if ( $this->options['aggressive_url_search'] ) {
			$this->replace_attachment_urls_in_content();
		}
		$this->remap_featured_images();

		$this->import_end();
	}

	/**
	 * Log an error instance to the logger.
	 *
	 * @param WP_Error $error Error instance to log.
	 */
	protected function log_error( WP_Error $error ) {
		if ( ! $this->logger ) {
			return;
		}
		
		$this->logger->warning( $error->get_error_message() );

		// Log the data as debug info too
		$data = $error->get_error_data();
		if ( ! empty( $data ) ) {
			$this->logger->debug( var_export( $data, true ) );
		}
	}

	/**
	 * Parses the WXR file and prepares us for the task of processing parsed data
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	protected function import_start( $file ) {
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'wxr_importer.file_missing', __( 'The file does not exist, please try again.', 'wordpress-importer' ) );
		}

		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'wxr_importer.file_not_readable', __( 'The file is not readable. Please check file permissions.', 'wordpress-importer' ) );
		}

		// Raise memory limit for large imports — WP will cap this at the server max.
		wp_raise_memory_limit( 'admin' );

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		// Suspend bunches of stuff in WP core
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		wp_suspend_cache_invalidation( true );

		// Prefill exists calls if told to
		if ( $this->options['prefill_existing_posts'] ) {
			$this->prefill_existing_posts();
		}
		if ( $this->options['prefill_existing_comments'] ) {
			$this->prefill_existing_comments();
		}
		if ( $this->options['prefill_existing_terms'] ) {
			$this->prefill_existing_terms();
		}

		/**
		 * Begin the import.
		 *
		 * Fires before the import process has begun. If you need to suspend
		 * caching or heavy processing on hooks, do so here.
		 */
		do_action( 'import_start' );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	protected function import_end() {
		// Restore XML error handling
		libxml_clear_errors();
		libxml_use_internal_errors( false );
		
		// Re-enable stuff in core
		wp_suspend_cache_invalidation( false );
		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		/**
		 * Complete the import.
		 *
		 * Fires after the import process has finished. If you need to update
		 * your cache or re-enable processing, do so here.
		 */
		do_action( 'import_end' );
	}

	/**
	 * Set the user mapping.
	 *
	 * @param array $mapping List of map arrays (containing `old_slug`, `old_id`, `new_id`)
	 */
	public function set_user_mapping( $mapping ) {
		foreach ( $mapping as $map ) {
			if ( empty( $map['old_slug'] ) || empty( $map['old_id'] ) || empty( $map['new_id'] ) ) {
				$this->logger->warning( __( 'Invalid author mapping', 'wordpress-importer' ) );
				$this->logger->debug( var_export( $map, true ) );
				continue;
			}

			$old_slug = $map['old_slug'];
			$old_id   = $map['old_id'];
			$new_id   = $map['new_id'];

			$this->mapping['user'][ $old_id ]        = $new_id;
			$this->mapping['user_slug'][ $old_slug ] = $new_id;
		}
	}

	/**
	 * Set the user slug overrides.
	 *
	 * Allows overriding the slug in the import with a custom/renamed version.
	 *
	 * @param string[] $overrides Map of old slug to new slug.
	 */
	public function set_user_slug_overrides( $overrides ) {
		foreach ( $overrides as $original => $renamed ) {
			$this->user_slug_override[ $original ] = $renamed;
		}
	}

	/**
	 * Parse a post node into post data.
	 *
	 * @param DOMElement $node Parent node of post data (typically `item`).
	 * @return array|WP_Error Post data array on success, error otherwise.
	 */
	protected function parse_post_node( $node ) {
		// Validate node
		if ( false === $node || ! is_object( $node ) || ! isset( $node->childNodes ) ) {
			return new WP_Error(
				'wxr_importer.parse.invalid_node',
				__( 'Invalid XML node. The export file may be corrupted.', 'wordpress-importer' )
			);
		}

		$data = array();
		$meta = array();
		$comments = array();
		$terms = array();

		foreach ( $node->childNodes as $child ) {
			// We only care about child elements
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}

			switch ( $child->tagName ) {
				case 'wp:post_type':
					$data['post_type'] = $child->textContent;
					break;

				case 'title':
					$data['post_title'] = $child->textContent;
					break;

				case 'guid':
					$data['guid'] = $child->textContent;
					break;

				case 'dc:creator':
					$data['post_author'] = $child->textContent;
					break;

				case 'content:encoded':
					$data['post_content'] = $child->textContent;
					break;

				case 'excerpt:encoded':
					$data['post_excerpt'] = $child->textContent;
					break;

				case 'wp:post_id':
					$data['post_id'] = $child->textContent;
					break;

				case 'wp:post_date':
					$data['post_date'] = $child->textContent;
					break;

				case 'wp:post_date_gmt':
					$data['post_date_gmt'] = $child->textContent;
					break;

				case 'wp:comment_status':
					$data['comment_status'] = $child->textContent;
					break;

				case 'wp:ping_status':
					$data['ping_status'] = $child->textContent;
					break;

				case 'wp:post_name':
					$data['post_name'] = $child->textContent;
					break;

				case 'wp:status':
					$data['post_status'] = $child->textContent;

					if ( $data['post_status'] === 'auto-draft' ) {
						// Bail now
						return new WP_Error(
							'wxr_importer.post.cannot_import_draft',
							__( 'Cannot import auto-draft posts' ),
							$data
						);
					}
					break;

				case 'wp:post_parent':
					$data['post_parent'] = $child->textContent;
					break;

				case 'wp:menu_order':
					$data['menu_order'] = $child->textContent;
					break;

				case 'wp:post_password':
					$data['post_password'] = $child->textContent;
					break;

				case 'wp:is_sticky':
					$data['is_sticky'] = $child->textContent;
					break;

				case 'wp:attachment_url':
					$data['attachment_url'] = $child->textContent;
					break;

				case 'wp:postmeta':
					$meta_item = $this->parse_meta_node( $child );
					if ( ! empty( $meta_item ) ) {
						$meta[] = $meta_item;
					}
					break;

				case 'wp:comment':
					$comment_item = $this->parse_comment_node( $child );
					if ( ! empty( $comment_item ) ) {
						$comments[] = $comment_item;
					}
					break;

				case 'category':
					$term_item = $this->parse_category_node( $child );
					if ( ! empty( $term_item ) ) {
						$terms[] = $term_item;
					}
					break;
			}
		}

		return compact( 'data', 'meta', 'comments', 'terms' );
	}

	/**
	 * Create new posts based on import information
	 *
	 * Posts marked as having a parent which doesn't exist will become top level items.
	 * Doesn't create a new post if: the post type doesn't exist, the given post ID
	 * is already noted as imported or a post with the same title and date already exists.
	 * Note that new/updated terms, comments and meta are imported for the last of the above.
	 */
	protected function process_post( $data, $meta, $comments, $terms ) {
		/**
		 * Pre-process post data.
		 *
		 * @param array $data Post data. (Return empty to skip.)
		 * @param array $meta Meta data.
		 * @param array $comments Comments on the post.
		 * @param array $terms Terms on the post.
		 */
		$data = apply_filters( 'wxr_importer.pre_process.post', $data, $meta, $comments, $terms );
		if ( empty( $data ) ) {
			return false;
		}

		$original_id = isset( $data['post_id'] )     ? (int) $data['post_id']     : 0;
		$parent_id   = isset( $data['post_parent'] ) ? (int) $data['post_parent'] : 0;
		$author_id   = isset( $data['post_author'] ) ? (int) $data['post_author'] : 0;

		// Have we already processed this?
		if ( isset( $this->mapping['post'][ $original_id ] ) ) {
			return;
		}

		// Ensure post_type is set
		if ( empty( $data['post_type'] ) ) {
			if ( $this->logger ) {
				$this->logger->warning( sprintf(
					__( 'Skipping post with missing post_type: "%s"', 'wordpress-importer' ),
					isset( $data['post_title'] ) ? $data['post_title'] : __( 'Unknown', 'wordpress-importer' )
				) );
			}
			return false;
		}

		$post_type_object = get_post_type_object( $data['post_type'] );

		// Is this type even valid?
		if ( ! $post_type_object ) {
			$this->logger->warning( sprintf(
				__( 'Failed to import "%s": Invalid post type %s', 'wordpress-importer' ),
				$data['post_title'],
				$data['post_type']
			) );
			return false;
		}

		$post_exists = $this->post_exists( $data );
		$resume_existing_import = $post_exists ? $this->is_resumable_import_post( (int) $post_exists, $original_id ) : false;
		if ( $post_exists && ! $resume_existing_import ) {
			$this->logger->info( sprintf(
				__( '%s "%s" already exists.', 'wordpress-importer' ),
				$post_type_object->labels->singular_name,
				$data['post_title']
			) );

			/**
			 * Post processing already imported.
			 *
			 * @param array $data Raw data imported for the post.
			 */
			do_action( 'wxr_importer.process_already_imported.post', $data );

			// Even though this post already exists, new comments might need importing
			$this->process_comments( $comments, $original_id, $data, $post_exists );

			return false;
		}

		// Map the parent post, or mark it as one we need to fix
		$requires_remapping = false;
		if ( $parent_id ) {
			if ( isset( $this->mapping['post'][ $parent_id ] ) ) {
				$data['post_parent'] = $this->mapping['post'][ $parent_id ];
			} else {
				$meta[] = array( 'key' => '_wxr_import_parent', 'value' => $parent_id );
				$requires_remapping = true;

				$data['post_parent'] = 0;
			}
		}

		// Map the author, or mark it as one we need to fix
		$author = sanitize_user( $data['post_author'], true );
		if ( empty( $author ) ) {
			// Missing or invalid author, use default if available.
			$data['post_author'] = $this->options['default_author'];
		} elseif ( isset( $this->mapping['user_slug'][ $author ] ) ) {
			$data['post_author'] = $this->mapping['user_slug'][ $author ];
		} else {
			$meta[] = array( 'key' => '_wxr_import_user_slug', 'value' => $author );
			$requires_remapping = true;

			$data['post_author'] = (int) get_current_user_id();
		}

		// Does the post look like it contains attachment images?
		if ( preg_match( self::REGEX_HAS_ATTACHMENT_REFS, $data['post_content'] ) ) {
			$meta[] = array( 'key' => '_wxr_import_has_attachment_refs', 'value' => true );
			$requires_remapping = true;
		}

		// Whitelist to just the keys we allow
		$postdata = array(
			'import_id' => $data['post_id'],
		);
		$allowed = array(
			'post_author'    => true,
			'post_date'      => true,
			'post_date_gmt'  => true,
			'post_content'   => true,
			'post_excerpt'   => true,
			'post_title'     => true,
			'post_status'    => true,
			'post_name'      => true,
			'comment_status' => true,
			'ping_status'    => true,
			'guid'           => true,
			'post_parent'    => true,
			'menu_order'     => true,
			'post_type'      => true,
			'post_password'  => true,
		);
		foreach ( $data as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			$postdata[ $key ] = $data[ $key ];
		}

		$postdata = apply_filters( 'wp_import_post_data_processed', $postdata, $data );

		if ( $resume_existing_import ) {
			$post_id = (int) $post_exists;
		} elseif ( 'attachment' === $postdata['post_type'] ) {
			if ( ! $this->options['fetch_attachments'] ) {
				$this->logger->notice( sprintf(
					__( 'Skipping attachment "%s", fetching attachments disabled' ),
					$data['post_title']
				) );
				/**
				 * Post processing skipped.
				 *
				 * @param array $data Raw data imported for the post.
				 * @param array $meta Raw meta data, already processed by {@see process_post_meta}.
				 */
				do_action( 'wxr_importer.process_skipped.post', $data, $meta );
				return false;
			}

			$remote_url = ! empty( $data['attachment_url'] ) ? $data['attachment_url'] : $data['guid'];

			if ( ! empty( $this->options['defer_attachment_download'] ) ) {
				$post_id = $this->queue_deferred_attachment( $postdata, $meta, $remote_url, $original_id );
			} else {
				$post_id = $this->process_attachment( $postdata, $meta, $remote_url );
			}
		} else {
			$post_id = wp_insert_post( $postdata, true );
			do_action( 'wp_import_insert_post', $post_id, $original_id, $postdata, $data );
		}

		if ( is_wp_error( $post_id ) ) {
			$this->logger->error( sprintf(
				__( 'Failed to import "%s" (%s)', 'wordpress-importer' ),
				$data['post_title'],
				$post_type_object->labels->singular_name
			) );
			$this->logger->debug( $post_id->get_error_message() );

			/**
			 * Post processing failed.
			 *
			 * @param WP_Error $post_id Error object.
			 * @param array $data Raw data imported for the post.
			 * @param array $meta Raw meta data, already processed by {@see process_post_meta}.
			 * @param array $comments Raw comment data, already processed by {@see process_comments}.
			 * @param array $terms Raw term data, already processed.
			 */
			do_action( 'wxr_importer.process_failed.post', $post_id, $data, $meta, $comments, $terms );
			return false;
		}

		$this->mark_resumable_import_post( $post_id, $original_id );

		// Ensure stickiness is handled correctly too
		if ( $data['is_sticky'] === '1' ) {
			stick_post( $post_id );
		}

		// map pre-import ID to local ID
		$this->mapping['post'][ $original_id ] = (int) $post_id;
		if ( $requires_remapping ) {
			$this->requires_remapping['post'][ $post_id ] = true;
		}
		$this->mark_post_exists( $data, $post_id );

		$this->logger->info( sprintf(
			__( 'Imported "%s" (%s)', 'wordpress-importer' ),
			$data['post_title'],
			$post_type_object->labels->singular_name
		) );
		$this->logger->debug( sprintf(
			__( 'Post %d remapped to %d', 'wordpress-importer' ),
			$original_id,
			$post_id
		) );

		// Handle the terms too
		$terms = apply_filters( 'wp_import_post_terms', $terms, $post_id, $data );

		if ( ! empty( $terms ) ) {
			$term_ids = array();
			foreach ( $terms as $term ) {
				$taxonomy = $term['taxonomy'];
				$key = sha1( $taxonomy . ':' . $term['slug'] );

				if ( isset( $this->mapping['term'][ $key ] ) ) {
					$term_ids[ $taxonomy ][] = (int) $this->mapping['term'][ $key ];
				} else {
					$meta[] = array( 'key' => '_wxr_import_term', 'value' => $term );
					$requires_remapping = true;
				}
			}

			foreach ( $term_ids as $tax => $ids ) {
				$tt_ids = wp_set_post_terms( $post_id, $ids, $tax );
				do_action( 'wp_import_set_post_terms', $tt_ids, $ids, $tax, $post_id, $data );
			}
		}

		$this->process_comments( $comments, $post_id, $data );
		$meta_complete = $this->process_post_meta_resumable( $meta, $post_id, $data );
		if ( ! $meta_complete ) {
			return array(
				'post_id'    => (int) $post_id,
				'incomplete' => true,
			);
		}

		if ( 'nav_menu_item' === $data['post_type'] ) {
			$this->process_menu_item_meta( $post_id, $data, $meta );
		}

		/**
		 * Post processing completed.
		 *
		 * @param int $post_id New post ID.
		 * @param array $data Raw data imported for the post.
		 * @param array $meta Raw meta data, already processed by {@see process_post_meta}.
		 * @param array $comments Raw comment data, already processed by {@see process_comments}.
		 * @param array $terms Raw term data, already processed.
		 */
		do_action( 'wxr_importer.processed.post', $post_id, $data, $meta, $comments, $terms );

		$flush_interval = max( 1, (int) $this->options['cache_flush_interval'] );
		if ( count( $this->mapping['post'] ) % $flush_interval === 0 ) {
			wp_cache_flush();
		}

		$this->clear_resumable_import_post( $post_id );

		return array(
			'post_id'    => (int) $post_id,
			'incomplete' => false,
		);
	}

	/**
	 * Attempt to create a new menu item from import data
	 *
	 * Fails for draft, orphaned menu items and those without an associated nav_menu
	 * or an invalid nav_menu term. If the post type or term object which the menu item
	 * represents doesn't exist then the menu item will not be imported (waits until the
	 * end of the import to retry again before discarding).
	 *
	 * @param array $item Menu item details from WXR file
	 */
	protected function process_menu_item_meta( $post_id, $data, $meta ) {

		$item_type = get_post_meta( $post_id, '_menu_item_type', true );
		$original_object_id = get_post_meta( $post_id, '_menu_item_object_id', true );
		$object_id = null;

		$this->logger->debug( sprintf( 'Processing menu item %s', $item_type ) );

		$requires_remapping = false;
		switch ( $item_type ) {
			case 'taxonomy':
				if ( isset( $this->mapping['term_id'][ $original_object_id ] ) ) {
					$object_id = $this->mapping['term_id'][ $original_object_id ];
				} else {
					add_post_meta( $post_id, '_wxr_import_menu_item', wp_slash( $original_object_id ) );
					$requires_remapping = true;
				}
				break;

			case 'post_type':
				if ( isset( $this->mapping['post'][ $original_object_id ] ) ) {
					$object_id = $this->mapping['post'][ $original_object_id ];
				} else {
					add_post_meta( $post_id, '_wxr_import_menu_item', wp_slash( $original_object_id ) );
					$requires_remapping = true;
				}
				break;

			case 'custom':
				// Custom refers to itself, wonderfully easy.
				$object_id = $post_id;
				break;

			default:
				// associated object is missing or not imported yet, we'll retry later
				$this->missing_menu_items[] = $data;
				$this->logger->debug( 'Unknown menu item type' );
				break;
		}

		if ( $requires_remapping ) {
			$this->requires_remapping['post'][ $post_id ] = true;
		}

		if ( empty( $object_id ) ) {
			// Nothing needed here.
			return;
		}

		$this->logger->debug( sprintf( 'Menu item %d mapped to %d', $original_object_id, $object_id ) );
		update_post_meta( $post_id, '_menu_item_object_id', wp_slash( $object_id ) );
	}

	/**
	 * Queue an attachment for deferred download after content import.
	 *
	 * @since 3.0.0
	 *
	 * @param array  $post        Attachment post data.
	 * @param array  $meta        Attachment meta.
	 * @param string $remote_url  Remote file URL.
	 * @param int    $original_id Original export post ID.
	 *
	 * @return int|WP_Error Post ID on success.
	 */
	protected function queue_deferred_attachment( $post, $meta, $remote_url, $original_id ) {
		$post_id = wp_insert_post( $post, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_wxr_import_pending_attachment_url', esc_url_raw( $remote_url ) );

		$this->pending_attachments[ $post_id ] = array(
			'remote_url' => $remote_url,
			'post'       => $post,
			'meta'       => $meta,
		);

		$this->logger->info( sprintf(
			__( 'Queued attachment "%s" for download', 'wordpress-importer' ),
			$post['post_title']
		) );

		return $post_id;
	}

	/**
	 * Download a batch of deferred attachments.
	 *
	 * @since 3.0.0
	 *
	 * @param int $batch_size Number of attachments per batch.
	 *
	 * @return array<string, mixed>
	 */
	public function download_pending_attachments_batch( $batch_size = 3 ) {
		$ids   = array_keys( $this->pending_attachments );
		$slice = array_slice( $ids, 0, max( 1, (int) $batch_size ) );
		$done  = 0;
		$failed = 0;

		foreach ( $slice as $post_id ) {
			if ( ! isset( $this->pending_attachments[ $post_id ] ) ) {
				continue;
			}

			$pending    = $this->pending_attachments[ $post_id ];
			$remote_url = $pending['remote_url'];
			$post       = $pending['post'];
			$meta       = $pending['meta'];

			$result = $this->process_attachment( $post, $meta, $remote_url, $post_id );
			if ( is_wp_error( $result ) ) {
				$failed++;
				$this->logger->warning( sprintf(
					__( 'Failed to download attachment "%s": %s', 'wordpress-importer' ),
					$post['post_title'],
					$result->get_error_message()
				) );
			} else {
				$done++;
				delete_post_meta( $post_id, '_wxr_import_pending_attachment_url' );
			}

			unset( $this->pending_attachments[ $post_id ] );
		}

		return array(
			'processed'   => $done + $failed,
			'downloaded'  => $done,
			'failed'      => $failed,
			'remaining'   => count( $this->pending_attachments ),
			'is_complete' => empty( $this->pending_attachments ),
		);
	}

	/**
	 * Get pending deferred attachments.
	 *
	 * @since 3.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_pending_attachments() {
		return $this->pending_attachments;
	}

	/**
	 * If fetching attachments is enabled then attempt to create a new attachment
	 *
	 * @param array  $post        Attachment post details from WXR.
	 * @param array  $meta        Attachment meta.
	 * @param string $url         URL to fetch attachment from.
	 * @param int    $existing_id Optional existing post ID for deferred downloads.
	 * @return int|WP_Error Post ID on success, WP_Error otherwise
	 */
	protected function process_attachment( $post, $meta, $url, $existing_id = 0 ) {
		// try to use _wp_attached file for upload folder placement to ensure the same location as the export site
		// e.g. location is 2003/05/image.jpg but the attachment post_date is 2010/09, see media_handle_upload()
		$post['upload_date'] = $post['post_date'];
		foreach ( $meta as $meta_item ) {
			if ( $meta_item['key'] !== '_wp_attached_file' ) {
				continue;
			}

			if ( preg_match( '%^[0-9]{4}/[0-9]{2}%', $meta_item['value'], $matches ) ) {
				$post['upload_date'] = $matches[0];
			}
			break;
		}

		// if the URL is absolute, but does not contain address, then upload it assuming base_site_url
		if ( preg_match( '|^/[\w\W]+$|', $url ) ) {
			$url = rtrim( $this->base_url, '/' ) . $url;
		}

		$upload = $this->fetch_remote_file( $url, $post );
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$info = wp_check_filetype( $upload['file'] );
		if ( ! $info ) {
			return new WP_Error( 'attachment_processing_error', __( 'Invalid file type', 'wordpress-importer' ) );
		}

		$post['post_mime_type'] = $info['type'];

		// WP really likes using the GUID for display. Allow updating it.
		// See https://core.trac.wordpress.org/ticket/33386
		if ( $this->options['update_attachment_guids'] ) {
			$post['guid'] = $upload['url'];
		}

		// as per wp-admin/includes/upload.php
		if ( $existing_id > 0 ) {
			$post_id = $existing_id;
			wp_update_post(
				array(
					'ID'             => $post_id,
					'post_mime_type' => $post['post_mime_type'],
					'guid'           => isset( $post['guid'] ) ? $post['guid'] : '',
				)
			);
			update_attached_file( $post_id, $upload['file'] );
		} else {
			$post_id = wp_insert_attachment( $post, $upload['file'] );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$attachment_metadata = wp_generate_attachment_metadata( $post_id, $upload['file'] );
		wp_update_attachment_metadata( $post_id, $attachment_metadata );

		// Map this image URL later if we need to
		$this->url_remap[ $url ] = $upload['url'];

		// If we have a HTTPS URL, ensure the HTTP URL gets replaced too
		if ( substr( $url, 0, 8 ) === 'https://' ) {
			$insecure_url = 'http' . substr( $url, 5 );
			$this->url_remap[ $insecure_url ] = $upload['url'];
		}

		if ( $this->options['aggressive_url_search'] ) {
			// remap resized image URLs, works by stripping the extension and remapping the URL stub.
			/*if ( preg_match( '!^image/!', $info['type'] ) ) {
				$parts = pathinfo( $remote_url );
				$name = basename( $parts['basename'], ".{$parts['extension']}" ); // PATHINFO_FILENAME in PHP 5.2

				$parts_new = pathinfo( $upload['url'] );
				$name_new = basename( $parts_new['basename'], ".{$parts_new['extension']}" );

				$this->url_remap[$parts['dirname'] . '/' . $name] = $parts_new['dirname'] . '/' . $name_new;
			}*/
		}

		return $post_id;
	}

	/**
	 * Parse a meta node into meta data.
	 *
	 * @param DOMElement $node Parent node of meta data (typically `wp:postmeta` or `wp:commentmeta`).
	 * @return array|null Meta data array on success, or null on error.
	 */
	protected function parse_meta_node( $node ) {
		// Validate node
		if ( false === $node || ! is_object( $node ) || ! isset( $node->childNodes ) ) {
			return null;
		}

		$key = '';
		$value = '';

		foreach ( $node->childNodes as $child ) {
			// We only care about child elements
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}

			switch ( $child->tagName ) {
				case 'wp:meta_key':
					$key = $child->textContent;
					break;

				case 'wp:meta_value':
					$value = $child->textContent;
					// Skip meta values that contain HTML error pages (corrupted exports)
					if ( ! empty( $value ) && (
						strpos( $value, '<!DOCTYPE html>' ) !== false ||
						strpos( $value, 'Fatal error' ) !== false ||
						strpos( $value, 'WordPress &rsaquo; Error' ) !== false ||
						( strpos( $value, '<html' ) !== false && strpos( $value, 'error-page' ) !== false )
					) ) {
						if ( $this->logger ) {
							$this->logger->warning( sprintf(
								__( 'Skipping corrupted meta value for key "%s" (contains HTML error page from failed export).', 'wordpress-importer' ),
								$key
							) );
						}
						return null;
					}
					break;
			}
		}

		if ( empty( $key ) ) {
			return null;
		}

		// Allow empty values but not missing keys
		return compact( 'key', 'value' );
	}

	/**
	 * Process and import post meta items.
	 *
	 * @param array $meta List of meta data arrays
	 * @param int $post_id Post to associate with
	 * @param array $post Post data
	 * @return int|WP_Error Number of meta items imported on success, error otherwise.
	 */
	protected function process_post_meta( $meta, $post_id, $post ) {
		if ( empty( $meta ) ) {
			return true;
		}

		foreach ( $meta as $meta_item ) {
			$this->process_single_post_meta( $meta_item, $post_id, $post );
		}

		return true;
	}

	/**
	 * Process post meta in resumable chunks for large web imports.
	 *
	 * @since 3.0.8
	 *
	 * @param array $meta    List of meta data arrays.
	 * @param int   $post_id Post to associate with.
	 * @param array $post    Post data.
	 *
	 * @return bool Whether all post meta has been processed.
	 */
	protected function process_post_meta_resumable( $meta, $post_id, $post ) {
		if ( empty( $meta ) ) {
			$this->clear_resumable_import_post( $post_id );
			return true;
		}

		$chunk_size = max( 1, (int) $this->options['post_meta_chunk_size'] );
		if ( empty( $this->options['job_id'] ) || count( $meta ) <= $chunk_size ) {
			$this->process_post_meta( $meta, $post_id, $post );
			$this->clear_resumable_import_post( $post_id );
			return true;
		}

		$total  = count( $meta );
		$offset = (int) get_post_meta( $post_id, '_wxr_import_meta_offset', true );
		$offset = max( 0, min( $offset, $total ) );
		$end    = min( $total, $offset + $chunk_size );

		update_post_meta( $post_id, '_wxr_import_meta_total', $total );

		for ( $i = $offset; $i < $end; $i++ ) {
			$this->process_single_post_meta( $meta[ $i ], $post_id, $post );
			update_post_meta( $post_id, '_wxr_import_meta_offset', $i + 1 );
		}

		if ( $end < $total ) {
			if ( $this->logger ) {
				$this->logger->info( sprintf(
					/* translators: 1: imported meta count, 2: total meta count, 3: post title */
					__( 'Imported %1$d of %2$d meta rows for "%3$s"; continuing this post in the next request.', 'wordpress-importer' ),
					$end,
					$total,
					isset( $post['post_title'] ) ? $post['post_title'] : sprintf( 'post %d', $post_id )
				) );
			}

			return false;
		}

		$this->clear_resumable_import_post( $post_id );
		return true;
	}

	/**
	 * Process one post meta row.
	 *
	 * @since 3.0.8
	 *
	 * @param array $meta_item Meta row.
	 * @param int   $post_id   Post ID.
	 * @param array $post      Post data.
	 *
	 * @return void
	 */
	protected function process_single_post_meta( $meta_item, $post_id, $post ) {
		/**
		 * Pre-process post meta data.
		 *
		 * @param array $meta_item Meta data. (Return empty to skip.)
		 * @param int $post_id Post the meta is attached to.
		 */
		$meta_item = apply_filters( 'wxr_importer.pre_process.post_meta', $meta_item, $post_id );
		if ( empty( $meta_item ) ) {
			return;
		}

		$key = apply_filters( 'import_post_meta_key', $meta_item['key'], $post_id, $post );
		$value = false;

		if ( '_edit_last' === $key ) {
			$value = intval( $meta_item['value'] );
			if ( ! isset( $this->mapping['user'][ $value ] ) ) {
				return;
			}

			$value = $this->mapping['user'][ $value ];
		}

		if ( ! $key ) {
			return;
		}

		// export gets meta straight from the DB so could have a serialized string
		if ( ! $value ) {
			$value = maybe_unserialize( $meta_item['value'] );
		}

		// If the value is (or contains) an incomplete class object, the source
		// site had a plugin that isn't installed here. Importing it would cause
		// a fatal error when WordPress tries to process the value. Skip it.
		if ( $this->value_has_incomplete_class( $value ) ) {
			if ( $this->logger ) {
				$this->logger->warning( sprintf(
					/* translators: 1: meta key, 2: post ID */
					__( 'Skipping meta key "%1$s" on post %2$d: contains a serialized object from a plugin that is not installed on this site.', 'wordpress-importer' ),
					$key,
					$post_id
				) );
			}
			return;
		}

		add_post_meta( $post_id, $key, $value );
		do_action( 'import_post_meta', $post_id, $key, $value );

		// if the post has a featured image, take note of this in case of remap
		if ( '_thumbnail_id' === $key ) {
			$this->featured_images[ $post_id ] = (int) $value;
		}
	}

	/**
	 * Parse a comment node into comment data.
	 *
	 * @param DOMElement $node Parent node of comment data (typically `wp:comment`).
	 * @return array Comment data array.
	 */
	protected function parse_comment_node( $node ) {
		// Validate node
		if ( false === $node || ! is_object( $node ) || ! isset( $node->childNodes ) ) {
			return array(
				'commentmeta' => array(),
			);
		}

		$data = array(
			'commentmeta' => array(),
		);

		foreach ( $node->childNodes as $child ) {
			// We only care about child elements
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}

			switch ( $child->tagName ) {
				case 'wp:comment_id':
					$data['comment_id'] = $child->textContent;
					break;
				case 'wp:comment_author':
					$data['comment_author'] = $child->textContent;
					break;

				case 'wp:comment_author_email':
					$data['comment_author_email'] = $child->textContent;
					break;

				case 'wp:comment_author_IP':
					$data['comment_author_IP'] = $child->textContent;
					break;

				case 'wp:comment_author_url':
					$data['comment_author_url'] = $child->textContent;
					break;

				case 'wp:comment_user_id':
					$data['comment_user_id'] = $child->textContent;
					break;

				case 'wp:comment_date':
					$data['comment_date'] = $child->textContent;
					break;

				case 'wp:comment_date_gmt':
					$data['comment_date_gmt'] = $child->textContent;
					break;

				case 'wp:comment_content':
					$data['comment_content'] = $child->textContent;
					break;

				case 'wp:comment_approved':
					$data['comment_approved'] = $child->textContent;
					break;

				case 'wp:comment_type':
					$data['comment_type'] = $child->textContent;
					break;

				case 'wp:comment_parent':
					$data['comment_parent'] = $child->textContent;
					break;

				case 'wp:commentmeta':
					$meta_item = $this->parse_meta_node( $child );
					if ( ! empty( $meta_item ) ) {
						$data['commentmeta'][] = $meta_item;
					}
					break;
			}
		}

		return $data;
	}

	/**
	 * Process and import comment data.
	 *
	 * @param array $comments List of comment data arrays.
	 * @param int $post_id Post to associate with.
	 * @param array $post Post data.
	 * @return int|WP_Error Number of comments imported on success, error otherwise.
	 */
	protected function process_comments( $comments, $post_id, $post, $post_exists = false ) {

		$comments = apply_filters( 'wp_import_post_comments', $comments, $post_id, $post );
		if ( empty( $comments ) ) {
			return 0;
		}

		$num_comments = 0;

		// Sort by ID to avoid excessive remapping later
		usort( $comments, array( $this, 'sort_comments_by_id' ) );

		foreach ( $comments as $key => $comment ) {
			/**
			 * Pre-process comment data
			 *
			 * @param array $comment Comment data. (Return empty to skip.)
			 * @param int $post_id Post the comment is attached to.
			 */
			$comment = apply_filters( 'wxr_importer.pre_process.comment', $comment, $post_id );
			if ( empty( $comment ) ) {
				return false;
			}

			$original_id = isset( $comment['comment_id'] )      ? (int) $comment['comment_id']      : 0;
			$parent_id   = isset( $comment['comment_parent'] )  ? (int) $comment['comment_parent']  : 0;
			$author_id   = isset( $comment['comment_user_id'] ) ? (int) $comment['comment_user_id'] : 0;

			// if this is a new post we can skip the comment_exists() check
			// TODO: Check comment_exists for performance
			if ( $post_exists ) {
				$existing = $this->comment_exists( $comment );
				if ( $existing ) {

					/**
					 * Comment processing already imported.
					 *
					 * @param array $comment Raw data imported for the comment.
					 */
					do_action( 'wxr_importer.process_already_imported.comment', $comment );

					$this->mapping['comment'][ $original_id ] = $existing;
					continue;
				}
			}

			// Remove meta from the main array
			$meta = isset( $comment['commentmeta'] ) ? $comment['commentmeta'] : array();
			unset( $comment['commentmeta'] );

			// Map the parent comment, or mark it as one we need to fix
			$requires_remapping = false;
			if ( $parent_id ) {
				if ( isset( $this->mapping['comment'][ $parent_id ] ) ) {
					$comment['comment_parent'] = $this->mapping['comment'][ $parent_id ];
				} else {
					// Prepare for remapping later
					$meta[] = array( 'key' => '_wxr_import_parent', 'value' => $parent_id );
					$requires_remapping = true;

					// Wipe the parent for now
					$comment['comment_parent'] = 0;
				}
			}

			// Map the author, or mark it as one we need to fix
			if ( $author_id ) {
				if ( isset( $this->mapping['user'][ $author_id ] ) ) {
					$comment['user_id'] = $this->mapping['user'][ $author_id ];
				} else {
					// Prepare for remapping later
					$meta[] = array( 'key' => '_wxr_import_user', 'value' => $author_id );
					$requires_remapping = true;

					// Wipe the user for now
					$comment['user_id'] = 0;
				}
			}

			// Run standard core filters
			$comment['comment_post_ID'] = $post_id;
			$comment = wp_filter_comment( $comment );

			// wp_insert_comment expects slashed data
			$comment_id = wp_insert_comment( wp_slash( $comment ) );
			$this->mapping['comment'][ $original_id ] = $comment_id;
			if ( $requires_remapping ) {
				$this->requires_remapping['comment'][ $comment_id ] = true;
			}
			$this->mark_comment_exists( $comment, $comment_id );

			/**
			 * Comment has been imported.
			 *
			 * @param int $comment_id New comment ID
			 * @param array $comment Comment inserted (`comment_id` item refers to the original ID)
			 * @param int $post_id Post parent of the comment
			 * @param array $post Post data
			 */
			do_action( 'wp_import_insert_comment', $comment_id, $comment, $post_id, $post );

			// Process the meta items
			foreach ( $meta as $meta_item ) {
				$value = maybe_unserialize( $meta_item['value'] );
				add_comment_meta( $comment_id, wp_slash( $meta_item['key'] ), wp_slash( $value ) );
			}

			/**
			 * Post processing completed.
			 *
			 * @param int $post_id New post ID.
			 * @param array $comment Raw data imported for the comment.
			 * @param array $meta Raw meta data, already processed by {@see process_post_meta}.
			 * @param array $post_id Parent post ID.
			 */
			do_action( 'wxr_importer.processed.comment', $comment_id, $comment, $meta, $post_id );

			$num_comments++;
		}

		return $num_comments;
	}

	protected function parse_category_node( $node ) {
		// Validate node
		if ( false === $node || ! is_object( $node ) ) {
			return null;
		}

		$data = array(
			// Default taxonomy to "category", since this is a `<category>` tag
			'taxonomy' => 'category',
		);
		$meta = array();

		if ( $node->hasAttribute( 'domain' ) ) {
			$data['taxonomy'] = $node->getAttribute( 'domain' );
		}
		if ( $node->hasAttribute( 'nicename' ) ) {
			$data['slug'] = $node->getAttribute( 'nicename' );
		}

		$data['name'] = $node->textContent;

		if ( empty( $data['slug'] ) ) {
			return null;
		}

		// Just for extra compatibility
		if ( $data['taxonomy'] === 'tag' ) {
			$data['taxonomy'] = 'post_tag';
		}

		return $data;
	}

	/**
	 * Callback for `usort` to sort comments by ID
	 *
	 * @param array $a Comment data for the first comment
	 * @param array $b Comment data for the second comment
	 * @return int
	 */
	public static function sort_comments_by_id( $a, $b ) {
		if ( empty( $a['comment_id'] ) ) {
			return 1;
		}

		if ( empty( $b['comment_id'] ) ) {
			return -1;
		}

		return $a['comment_id'] - $b['comment_id'];
	}

	protected function parse_author_node( $node ) {
		// Validate node
		if ( false === $node || ! is_object( $node ) || ! isset( $node->childNodes ) ) {
			return new WP_Error(
				'wxr_importer.parse.invalid_author_node',
				__( 'Invalid author XML node. The export file may be corrupted.', 'wordpress-importer' )
			);
		}

		$data = array();
		$meta = array();
		foreach ( $node->childNodes as $child ) {
			// We only care about child elements
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}

			switch ( $child->tagName ) {
				case 'wp:author_login':
					$data['user_login'] = $child->textContent;
					break;

				case 'wp:author_id':
					$data['ID'] = $child->textContent;
					break;

				case 'wp:author_email':
					$data['user_email'] = $child->textContent;
					break;

				case 'wp:author_display_name':
					$data['display_name'] = $child->textContent;
					break;

				case 'wp:author_first_name':
					$data['first_name'] = $child->textContent;
					break;

				case 'wp:author_last_name':
					$data['last_name'] = $child->textContent;
					break;
			}
		}

		return compact( 'data', 'meta' );
	}

	protected function process_author( $data, $meta ) {
		/**
		 * Pre-process user data.
		 *
		 * @param array $data User data. (Return empty to skip.)
		 * @param array $meta Meta data.
		 */
		$data = apply_filters( 'wxr_importer.pre_process.user', $data, $meta );
		if ( empty( $data ) ) {
			return false;
		}

		// Have we already handled this user?
		$original_id = isset( $data['ID'] ) ? $data['ID'] : 0;
		$original_slug = $data['user_login'];

		if ( isset( $this->mapping['user'][ $original_id ] ) ) {
			$existing = $this->mapping['user'][ $original_id ];

			// Note the slug mapping if we need to too
			if ( ! isset( $this->mapping['user_slug'][ $original_slug ] ) ) {
				$this->mapping['user_slug'][ $original_slug ] = $existing;
			}

			return false;
		}

		if ( isset( $this->mapping['user_slug'][ $original_slug ] ) ) {
			$existing = $this->mapping['user_slug'][ $original_slug ];

			// Ensure we note the mapping too
			$this->mapping['user'][ $original_id ] = $existing;

			return false;
		}

		// Allow overriding the user's slug
		$login = $original_slug;
		if ( isset( $this->user_slug_override[ $login ] ) ) {
			$login = $this->user_slug_override[ $login ];
		}

		$userdata = array(
			'user_login'   => sanitize_user( $login, true ),
			'user_pass'    => wp_generate_password(),
		);

		$allowed = array(
			'user_email'   => true,
			'display_name' => true,
			'first_name'   => true,
			'last_name'    => true,
		);
		foreach ( $data as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			$userdata[ $key ] = $data[ $key ];
		}

		$user_id = wp_insert_user( wp_slash( $userdata ) );
		if ( is_wp_error( $user_id ) ) {
			$this->logger->error( sprintf(
				__( 'Failed to import user "%s"', 'wordpress-importer' ),
				$userdata['user_login']
			) );
			$this->logger->debug( $user_id->get_error_message() );

			/**
			 * User processing failed.
			 *
			 * @param WP_Error $user_id Error object.
			 * @param array $userdata Raw data imported for the user.
			 */
			do_action( 'wxr_importer.process_failed.user', $user_id, $userdata );
			return false;
		}

		if ( $original_id ) {
			$this->mapping['user'][ $original_id ] = $user_id;
		}
		$this->mapping['user_slug'][ $original_slug ] = $user_id;

		$this->logger->info( sprintf(
			__( 'Imported user "%s"', 'wordpress-importer' ),
			$userdata['user_login']
		) );
		$this->logger->debug( sprintf(
			__( 'User %d remapped to %d', 'wordpress-importer' ),
			$original_id,
			$user_id
		) );

		// TODO: Implement meta handling once WXR includes it
		/**
		 * User processing completed.
		 *
		 * @param int $user_id New user ID.
		 * @param array $userdata Raw data imported for the user.
		 */
		do_action( 'wxr_importer.processed.user', $user_id, $userdata );
	}

	protected function parse_term_node( $node, $type = 'term' ) {
		// Validate node
		if ( false === $node || ! is_object( $node ) || ! isset( $node->childNodes ) ) {
			return new WP_Error(
				'wxr_importer.parse.invalid_term_node',
				__( 'Invalid term XML node. The export file may be corrupted.', 'wordpress-importer' )
			);
		}

		$data = array();
		$meta = array();

		$tag_name = array(
			'id'          => 'wp:term_id',
			'taxonomy'    => 'wp:term_taxonomy',
			'slug'        => 'wp:term_slug',
			'parent'      => 'wp:term_parent',
			'name'        => 'wp:term_name',
			'description' => 'wp:term_description',
		);
		$taxonomy = null;

		// Special casing!
		switch ( $type ) {
			case 'category':
				$tag_name['slug']        = 'wp:category_nicename';
				$tag_name['parent']      = 'wp:category_parent';
				$tag_name['name']        = 'wp:cat_name';
				$tag_name['description'] = 'wp:category_description';
				$tag_name['taxonomy']    = null;

				$data['taxonomy'] = 'category';
				break;

			case 'tag':
				$tag_name['slug']        = 'wp:tag_slug';
				$tag_name['parent']      = null;
				$tag_name['name']        = 'wp:tag_name';
				$tag_name['description'] = 'wp:tag_description';
				$tag_name['taxonomy']    = null;

				$data['taxonomy'] = 'post_tag';
				break;
		}

		foreach ( $node->childNodes as $child ) {
			// We only care about child elements
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}

			$key = array_search( $child->tagName, $tag_name );
			if ( $key ) {
				$data[ $key ] = $child->textContent;
			}
		}

		if ( empty( $data['taxonomy'] ) ) {
			return null;
		}

		// Compatibility with WXR 1.0
		if ( $data['taxonomy'] === 'tag' ) {
			$data['taxonomy'] = 'post_tag';
		}

		return compact( 'data', 'meta' );
	}

	protected function process_term( $data, $meta ) {
		/**
		 * Pre-process term data.
		 *
		 * @param array $data Term data. (Return empty to skip.)
		 * @param array $meta Meta data.
		 */
		$data = apply_filters( 'wxr_importer.pre_process.term', $data, $meta );
		if ( empty( $data ) ) {
			return false;
		}

		$original_id = isset( $data['id'] )      ? (int) $data['id']      : 0;
		$parent_id   = isset( $data['parent'] )  ? (int) $data['parent']  : 0;

		$mapping_key = sha1( $data['taxonomy'] . ':' . $data['slug'] );
		$existing = $this->term_exists( $data );
		if ( $existing ) {

			/**
			 * Term processing already imported.
			 *
			 * @param array $data Raw data imported for the term.
			 */
			do_action( 'wxr_importer.process_already_imported.term', $data );

			$this->mapping['term'][ $mapping_key ] = $existing;
			$this->mapping['term_id'][ $original_id ] = $existing;
			return false;
		}

		// WP really likes to repeat itself in export files
		if ( isset( $this->mapping['term'][ $mapping_key ] ) ) {
			return false;
		}

		$termdata = array();
		$allowed = array(
			'slug' => true,
			'description' => true,
		);

		// Map the parent comment, or mark it as one we need to fix
		// TODO: add parent mapping and remapping
		/*$requires_remapping = false;
		if ( $parent_id ) {
			if ( isset( $this->mapping['term'][ $parent_id ] ) ) {
				$data['parent'] = $this->mapping['term'][ $parent_id ];
			} else {
				// Prepare for remapping later
				$meta[] = array( 'key' => '_wxr_import_parent', 'value' => $parent_id );
				$requires_remapping = true;

				// Wipe the parent for now
				$data['parent'] = 0;
			}
		}*/

		foreach ( $data as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			$termdata[ $key ] = $data[ $key ];
		}

		$result = wp_insert_term( $data['name'], $data['taxonomy'], $termdata );
		if ( is_wp_error( $result ) ) {
			$this->logger->warning( sprintf(
				__( 'Failed to import %s %s', 'wordpress-importer' ),
				$data['taxonomy'],
				$data['name']
			) );
			$this->logger->debug( $result->get_error_message() );
			do_action( 'wp_import_insert_term_failed', $result, $data );

			/**
			 * Term processing failed.
			 *
			 * @param WP_Error $result Error object.
			 * @param array $data Raw data imported for the term.
			 * @param array $meta Meta data supplied for the term.
			 */
			do_action( 'wxr_importer.process_failed.term', $result, $data, $meta );
			return false;
		}

		$term_id = $result['term_id'];

		$this->mapping['term'][ $mapping_key ] = $term_id;
		$this->mapping['term_id'][ $original_id ] = $term_id;

		$this->logger->info( sprintf(
			__( 'Imported "%s" (%s)', 'wordpress-importer' ),
			$data['name'],
			$data['taxonomy']
		) );
		$this->logger->debug( sprintf(
			__( 'Term %d remapped to %d', 'wordpress-importer' ),
			$original_id,
			$term_id
		) );

		do_action( 'wp_import_insert_term', $term_id, $data );

		/**
		 * Term processing completed.
		 *
		 * @param int $term_id New term ID.
		 * @param array $data Raw data imported for the term.
		 */
		do_action( 'wxr_importer.processed.term', $term_id, $data );
	}

	/**
	 * Attempt to download a remote file attachment
	 *
	 * @param string $url URL of item to fetch
	 * @param array $post Attachment details
	 * @return array|WP_Error Local file location details on success, WP_Error otherwise
	 */
	protected function fetch_remote_file( $url, $post ) {
		// extract the file name and extension from the url
		$file_name = basename( $url );

		// get placeholder file in the upload dir with a unique, sanitized filename
		$upload = wp_upload_bits( $file_name, 0, '', $post['upload_date'] );
		if ( $upload['error'] ) {
			return new WP_Error( 'upload_dir_error', $upload['error'] );
		}

		// --- Local file fallback for offline/local testing ---
		// If the URL maps to a file that already exists on this server's upload directory,
		// copy it directly instead of making an HTTP request (which will fail offline).
		$upload_dir = wp_upload_dir();
		$local_path = false;

		// Check if the URL matches the local site's upload URL
		if ( ! empty( $upload_dir['baseurl'] ) ) {
			$base_url = trailingslashit( $upload_dir['baseurl'] );
			if ( strpos( $url, $base_url ) === 0 ) {
				$relative = substr( $url, strlen( $base_url ) );
				$candidate = trailingslashit( $upload_dir['basedir'] ) . $relative;
				if ( file_exists( $candidate ) ) {
					$local_path = $candidate;
				}
			}
		}

		// Also check if the URL matches the base_url from the export (cross-site local copy)
		if ( ! $local_path && ! empty( $this->base_url ) ) {
			$base = trailingslashit( $this->base_url );
			if ( strpos( $url, $base ) === 0 ) {
				$relative = substr( $url, strlen( $base ) );
				// Try mapping to local uploads
				if ( ! empty( $upload_dir['basedir'] ) ) {
					// Strip any leading 'wp-content/uploads/' prefix
					$relative_clean = preg_replace( '#^wp-content/uploads/#i', '', $relative );
					$candidate = trailingslashit( $upload_dir['basedir'] ) . $relative_clean;
					if ( file_exists( $candidate ) ) {
						$local_path = $candidate;
					}
				}
			}
		}

		if ( $local_path ) {
			// Copy the local file to the placeholder location
			if ( ! copy( $local_path, $upload['file'] ) ) {
				return new WP_Error( 'import_file_error', sprintf(
					__( 'Could not copy local file %s', 'wordpress-importer' ),
					$local_path
				) );
			}
			if ( $this->logger ) {
				$this->logger->debug( sprintf(
					__( 'Copied local file for attachment: %s', 'wordpress-importer' ),
					$file_name
				) );
			}
			return $upload;
		}
		// --- End local file fallback ---

		// fetch the remote url and write it to the placeholder file
		$response = wp_remote_get( $url, array(
			'stream' => true,
			'filename' => $upload['file'],
		) );

		// request failed
		if ( is_wp_error( $response ) ) {
			unlink( $upload['file'] );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// make sure the fetch was successful
		if ( $code !== 200 ) {
			unlink( $upload['file'] );
			return new WP_Error(
				'import_file_error',
				sprintf(
					__( 'Remote server returned %1$d %2$s for %3$s', 'wordpress-importer' ),
					$code,
					get_status_header_desc( $code ),
					$url
				)
			);
		}

		$filesize = filesize( $upload['file'] );
		$headers = wp_remote_retrieve_headers( $response );

		if ( isset( $headers['content-length'] ) && $filesize !== (int) $headers['content-length'] ) {
			unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __( 'Remote file is incorrect size', 'wordpress-importer' ) );
		}

		if ( 0 === $filesize ) {
			unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __( 'Zero size file downloaded', 'wordpress-importer' ) );
		}

		$max_size = (int) $this->max_attachment_size();
		if ( ! empty( $max_size ) && $filesize > $max_size ) {
			unlink( $upload['file'] );
			$message = sprintf( __( 'Remote file is too large, limit is %s', 'wordpress-importer' ), size_format( $max_size ) );
			return new WP_Error( 'import_file_error', $message );
		}

		return $upload;
	}

	protected function post_process() {
		// Time to tackle any left-over bits
		if ( ! empty( $this->requires_remapping['post'] ) ) {
			$this->post_process_posts( $this->requires_remapping['post'] );
		}
		if ( ! empty( $this->requires_remapping['comment'] ) ) {
			$this->post_process_comments( $this->requires_remapping['comment'] );
		}
	}

	protected function post_process_posts( $todo ) {
		foreach ( $todo as $post_id => $_ ) {
			$this->logger->debug( sprintf(
				// Note: title intentionally not used to skip extra processing
				// for when debug logging is off
				__( 'Running post-processing for post %d', 'wordpress-importer' ),
				$post_id
			) );

			$data = array();

			$parent_id = get_post_meta( $post_id, '_wxr_import_parent', true );
			if ( ! empty( $parent_id ) ) {
				// Have we imported the parent now?
				if ( isset( $this->mapping['post'][ $parent_id ] ) ) {
					$data['post_parent'] = $this->mapping['post'][ $parent_id ];
				} else {
					$this->logger->warning( sprintf(
						__( 'Could not find the post parent for "%s" (post #%d)', 'wordpress-importer' ),
						get_the_title( $post_id ),
						$post_id
					) );
					$this->logger->debug( sprintf(
						__( 'Post %d was imported with parent %d, but could not be found', 'wordpress-importer' ),
						$post_id,
						$parent_id
					) );
				}
			}

			$author_slug = get_post_meta( $post_id, '_wxr_import_user_slug', true );
			if ( ! empty( $author_slug ) ) {
				// Have we imported the user now?
				if ( isset( $this->mapping['user_slug'][ $author_slug ] ) ) {
					$data['post_author'] = $this->mapping['user_slug'][ $author_slug ];
				} else {
					$this->logger->warning( sprintf(
						__( 'Could not find the author for "%s" (post #%d)', 'wordpress-importer' ),
						get_the_title( $post_id ),
						$post_id
					) );
					$this->logger->debug( sprintf(
						__( 'Post %d was imported with author "%s", but could not be found', 'wordpress-importer' ),
						$post_id,
						$author_slug
					) );
				}
			}

			$has_attachments = get_post_meta( $post_id, '_wxr_import_has_attachment_refs', true );
			if ( ! empty( $has_attachments ) ) {
				$post = get_post( $post_id );
				$content = $post->post_content;

				// Replace all the URLs we've got
				$new_content = str_replace( array_keys( $this->url_remap ), $this->url_remap, $content );
				if ( $new_content !== $content ) {
					$data['post_content'] = $new_content;
				}
			}

			if ( get_post_type( $post_id ) === 'nav_menu_item' ) {
				$this->post_process_menu_item( $post_id );
			}

			// Do we have updates to make?
			if ( empty( $data ) ) {
				$this->logger->debug( sprintf(
					__( 'Post %d was marked for post-processing, but none was required.', 'wordpress-importer' ),
					$post_id
				) );
				continue;
			}

			// Run the update
			$data['ID'] = $post_id;
			$result = wp_update_post( $data, true );
			if ( is_wp_error( $result ) ) {
				$this->logger->warning( sprintf(
					__( 'Could not update "%s" (post #%d) with mapped data', 'wordpress-importer' ),
					get_the_title( $post_id ),
					$post_id
				) );
				$this->logger->debug( $result->get_error_message() );
				continue;
			}

			// Clear out our temporary meta keys
			delete_post_meta( $post_id, '_wxr_import_parent' );
			delete_post_meta( $post_id, '_wxr_import_user_slug' );
			delete_post_meta( $post_id, '_wxr_import_has_attachment_refs' );
		}
	}

	protected function post_process_menu_item( $post_id ) {
		$menu_object_id = get_post_meta( $post_id, '_wxr_import_menu_item', true );
		if ( empty( $menu_object_id ) ) {
			// No processing needed!
			return;
		}

		$menu_item_type = get_post_meta( $post_id, '_menu_item_type', true );
		switch ( $menu_item_type ) {
			case 'taxonomy':
				if ( isset( $this->mapping['term_id'][ $menu_object_id ] ) ) {
					$menu_object = $this->mapping['term_id'][ $menu_object_id ];
				}
				break;

			case 'post_type':
				if ( isset( $this->mapping['post'][ $menu_object_id ] ) ) {
					$menu_object = $this->mapping['post'][ $menu_object_id ];
				}
				break;

			default:
				// Cannot handle this.
				return;
		}

		if ( ! empty( $menu_object ) ) {
			update_post_meta( $post_id, '_menu_item_object_id', wp_slash( $menu_object ) );
		} else {
			$this->logger->warning( sprintf(
				__( 'Could not find the menu object for "%s" (post #%d)', 'wordpress-importer' ),
				get_the_title( $post_id ),
				$post_id
			) );
			$this->logger->debug( sprintf(
				__( 'Post %d was imported with object "%d" of type "%s", but could not be found', 'wordpress-importer' ),
				$post_id,
				$menu_object_id,
				$menu_item_type
			) );
		}

		delete_post_meta( $post_id, '_wxr_import_menu_item' );
	}


	protected function post_process_comments( $todo ) {
		foreach ( $todo as $comment_id => $_ ) {
			$data = array();

			$parent_id = get_comment_meta( $comment_id, '_wxr_import_parent', true );
			if ( ! empty( $parent_id ) ) {
				// Have we imported the parent now?
				if ( isset( $this->mapping['comment'][ $parent_id ] ) ) {
					$data['comment_parent'] = $this->mapping['comment'][ $parent_id ];
				} else {
					$this->logger->warning( sprintf(
						__( 'Could not find the comment parent for comment #%d', 'wordpress-importer' ),
						$comment_id
					) );
					$this->logger->debug( sprintf(
						__( 'Comment %d was imported with parent %d, but could not be found', 'wordpress-importer' ),
						$comment_id,
						$parent_id
					) );
				}
			}

			$author_id = get_comment_meta( $comment_id, '_wxr_import_user', true );
			if ( ! empty( $author_id ) ) {
				// Have we imported the user now?
				if ( isset( $this->mapping['user'][ $author_id ] ) ) {
					$data['user_id'] = $this->mapping['user'][ $author_id ];
				} else {
					$this->logger->warning( sprintf(
						__( 'Could not find the author for comment #%d', 'wordpress-importer' ),
						$comment_id
					) );
					$this->logger->debug( sprintf(
						__( 'Comment %d was imported with author %d, but could not be found', 'wordpress-importer' ),
						$comment_id,
						$author_id
					) );
				}
			}

			// Do we have updates to make?
			if ( empty( $data ) ) {
				continue;
			}

			// Run the update
			$data['comment_ID'] = $comment_id;
			$result = wp_update_comment( wp_slash( $data ) );
			if ( empty( $result ) ) {
				$this->logger->warning( sprintf(
					__( 'Could not update comment #%d with mapped data', 'wordpress-importer' ),
					$comment_id
				) );
				continue;
			}

			// Clear out our temporary meta keys
			delete_comment_meta( $comment_id, '_wxr_import_parent' );
			delete_comment_meta( $comment_id, '_wxr_import_user' );
		}
	}

	/**
	 * Use stored mapping information to update old attachment URLs
	 */
	protected function replace_attachment_urls_in_content() {
		global $wpdb;
		// make sure we do the longest urls first, in case one is a substring of another
		uksort( $this->url_remap, array( $this, 'cmpr_strlen' ) );

		foreach ( $this->url_remap as $from_url => $to_url ) {
			// remap urls in post_content
			$query = $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)", $from_url, $to_url );
			$wpdb->query( $query );

			// remap enclosure urls
			$query = $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key='enclosure'", $from_url, $to_url );
			$result = $wpdb->query( $query );
		}
	}

	/**
	 * Update _thumbnail_id meta to new, imported attachment IDs
	 */
	function remap_featured_images() {
		// cycle through posts that have a featured image
		foreach ( $this->featured_images as $post_id => $value ) {
			// Use the new-style mapping array instead of the deprecated processed_posts
			if ( isset( $this->mapping['post'][ $value ] ) ) {
				$new_id = $this->mapping['post'][ $value ];

				// only update if there's a difference
				if ( $new_id !== $value ) {
					update_post_meta( $post_id, '_thumbnail_id', $new_id );
				}
			}
		}
	}

	/**
	 * Decide if the given meta key maps to information we will want to import
	 *
	 * @param string $key The meta key to check
	 * @return string|bool The key if we do want to import, false if not
	 */
	public function is_valid_meta_key( $key ) {
		// skip attachment metadata since we'll regenerate it from scratch
		// skip _edit_lock as not relevant for import
		if ( in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock' ) ) ) {
			return false;
		}

		return $key;
	}

	/**
	 * Check whether a value (or any nested value) is an incomplete class object.
	 *
	 * This happens when a meta value was serialized on the source site by a plugin
	 * that is not installed on the destination site. Attempting to save such a value
	 * causes a fatal error in wp_unslash() / map_deep().
	 *
	 * @param mixed $value The unserialized meta value to inspect.
	 * @return bool True if the value contains an __PHP_Incomplete_Class object.
	 */
	protected function value_has_incomplete_class( $value ) {
		if ( is_object( $value ) ) {
			// get_class() returns '__PHP_Incomplete_Class' for objects whose class
			// definition was not available when unserialize() ran.
			return get_class( $value ) === '__PHP_Incomplete_Class';
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->value_has_incomplete_class( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Decide what the maximum file size for downloaded attachments is.
	 * Default is 0 (unlimited), can be filtered via import_attachment_size_limit
	 *
	 * @return int Maximum attachment file size to import
	 */
	protected function max_attachment_size() {
		return apply_filters( 'import_attachment_size_limit', 0 );
	}

	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import
	 *
	 * @access protected
	 * @return int 60
	 */
	function bump_request_timeout($val) {
		return 60;
	}

	// return the difference in length between two strings
	function cmpr_strlen( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	}

	/**
	 * Prefill existing post data.
	 *
	 * This preloads all GUIDs into memory, allowing us to avoid hitting the
	 * database when we need to check for existence. With larger imports, this
	 * becomes prohibitively slow to perform SELECT queries on each.
	 *
	 * By preloading all this data into memory, it's a constant-time lookup in
	 * PHP instead. However, this does use a lot more memory, so for sites doing
	 * small imports onto a large site, it may be a better tradeoff to use
	 * on-the-fly checking instead.
	 */
	protected function prefill_existing_posts() {
		global $wpdb;
		$posts = $wpdb->get_results( "SELECT ID, guid FROM {$wpdb->posts}" );

		foreach ( $posts as $item ) {
			$this->exists['post'][ $item->guid ] = $item->ID;
		}
	}

	/**
	 * Does the post exist?
	 *
	 * @param array $data Post data to check against.
	 * @return int|bool Existing post ID if it exists, false otherwise.
	 */
	protected function post_exists( $data ) {
		// Constant-time lookup if we prefilled
		$exists_key = $data['guid'];

		if ( $this->options['prefill_existing_posts'] ) {
			return isset( $this->exists['post'][ $exists_key ] ) ? $this->exists['post'][ $exists_key ] : false;
		}

		// No prefilling, but might have already handled it
		if ( isset( $this->exists['post'][ $exists_key ] ) ) {
			return $this->exists['post'][ $exists_key ];
		}

		// Still nothing, try post_exists, and cache it
		$exists = post_exists( $data['post_title'], $data['post_content'], $data['post_date'] );
		$this->exists['post'][ $exists_key ] = $exists;

		return $exists;
	}

	/**
	 * Mark the post as existing.
	 *
	 * @param array $data Post data to mark as existing.
	 * @param int $post_id Post ID.
	 */
	protected function mark_post_exists( $data, $post_id ) {
		$exists_key = $data['guid'];
		$this->exists['post'][ $exists_key ] = $post_id;
	}

	/**
	 * Mark a post as owned by the active WXR job for resumable processing.
	 *
	 * @since 3.0.8
	 *
	 * @param int $post_id     Local post ID.
	 * @param int $original_id Original WXR post ID.
	 *
	 * @return void
	 */
	protected function mark_resumable_import_post( $post_id, $original_id ) {
		if ( empty( $this->options['job_id'] ) ) {
			return;
		}

		update_post_meta( $post_id, '_wxr_import_job_id', (int) $this->options['job_id'] );
		update_post_meta( $post_id, '_wxr_import_original_id', (int) $original_id );
	}

	/**
	 * Check whether an existing post belongs to the active import job.
	 *
	 * @since 3.0.8
	 *
	 * @param int $post_id     Local post ID.
	 * @param int $original_id Original WXR post ID.
	 *
	 * @return bool
	 */
	protected function is_resumable_import_post( $post_id, $original_id ) {
		if ( empty( $this->options['job_id'] ) ) {
			return false;
		}

		$job_id = (int) get_post_meta( $post_id, '_wxr_import_job_id', true );
		if ( $job_id !== (int) $this->options['job_id'] ) {
			return false;
		}

		$stored_original_id = (int) get_post_meta( $post_id, '_wxr_import_original_id', true );
		return $stored_original_id === (int) $original_id;
	}

	/**
	 * Clear per-post resumable cursors after the post finishes.
	 *
	 * Keeps _wxr_import_job_id until final cleanup so remapping can still scope
	 * imported posts to this job.
	 *
	 * @since 3.0.8
	 *
	 * @param int $post_id Local post ID.
	 *
	 * @return void
	 */
	protected function clear_resumable_import_post( $post_id ) {
		delete_post_meta( $post_id, '_wxr_import_meta_offset' );
		delete_post_meta( $post_id, '_wxr_import_meta_total' );
	}

	/**
	 * Prefill existing comment data.
	 *
	 * @see self::prefill_existing_posts() for justification of why this exists.
	 */
	protected function prefill_existing_comments() {
		global $wpdb;
		$posts = $wpdb->get_results( "SELECT comment_ID, comment_author, comment_date FROM {$wpdb->comments}" );

		foreach ( $posts as $item ) {
			$exists_key = sha1( $item->comment_author . ':' . $item->comment_date );
			$this->exists['comment'][ $exists_key ] = $item->comment_ID;
		}
	}

	/**
	 * Does the comment exist?
	 *
	 * @param array $data Comment data to check against.
	 * @return int|bool Existing comment ID if it exists, false otherwise.
	 */
	protected function comment_exists( $data ) {
		$exists_key = sha1( $data['comment_author'] . ':' . $data['comment_date'] );

		// Constant-time lookup if we prefilled
		if ( $this->options['prefill_existing_comments'] ) {
			return isset( $this->exists['comment'][ $exists_key ] ) ? $this->exists['comment'][ $exists_key ] : false;
		}

		// No prefilling, but might have already handled it
		if ( isset( $this->exists['comment'][ $exists_key ] ) ) {
			return $this->exists['comment'][ $exists_key ];
		}

		// Still nothing, try comment_exists, and cache it
		$exists = comment_exists( $data['comment_author'], $data['comment_date'] );
		$this->exists['comment'][ $exists_key ] = $exists;

		return $exists;
	}

	/**
	 * Mark the comment as existing.
	 *
	 * @param array $data Comment data to mark as existing.
	 * @param int $comment_id Comment ID.
	 */
	protected function mark_comment_exists( $data, $comment_id ) {
		$exists_key = sha1( $data['comment_author'] . ':' . $data['comment_date'] );
		$this->exists['comment'][ $exists_key ] = $comment_id;
	}

	/**
	 * Prefill existing term data.
	 *
	 * @see self::prefill_existing_posts() for justification of why this exists.
	 */
	protected function prefill_existing_terms() {
		global $wpdb;
		$query = "SELECT t.term_id, tt.taxonomy, t.slug FROM {$wpdb->terms} AS t";
		$query .= " JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id";
		$terms = $wpdb->get_results( $query );

		foreach ( $terms as $item ) {
			$exists_key = sha1( $item->taxonomy . ':' . $item->slug );
			$this->exists['term'][ $exists_key ] = $item->term_id;
		}
	}

	/**
	 * Does the term exist?
	 *
	 * @param array $data Term data to check against.
	 * @return int|bool Existing term ID if it exists, false otherwise.
	 */
	protected function term_exists( $data ) {
		$exists_key = sha1( $data['taxonomy'] . ':' . $data['slug'] );

		// Constant-time lookup if we prefilled
		if ( $this->options['prefill_existing_terms'] ) {
			return isset( $this->exists['term'][ $exists_key ] ) ? $this->exists['term'][ $exists_key ] : false;
		}

		// No prefilling, but might have already handled it
		if ( isset( $this->exists['term'][ $exists_key ] ) ) {
			return $this->exists['term'][ $exists_key ];
		}

		// Still nothing, try comment_exists, and cache it
		$exists = term_exists( $data['slug'], $data['taxonomy'] );
		if ( is_array( $exists ) ) {
			$exists = $exists['term_id'];
		}

		$this->exists['term'][ $exists_key ] = $exists;

		return $exists;
	}

	/**
	 * Mark the term as existing.
	 *
	 * @param array $data Term data to mark as existing.
	 * @param int $term_id Term ID.
	 */
	protected function mark_term_exists( $data, $term_id ) {
		$exists_key = sha1( $data['taxonomy'] . ':' . $data['slug'] );
		$this->exists['term'][ $exists_key ] = $term_id;
	}

	/**
	 * Mark that import_start() has already run for this session.
	 *
	 * @since 3.0.0
	 *
	 * @param bool $started Whether import has started.
	 *
	 * @return void
	 */
	public function set_import_started( $started = true ) {
		$this->import_batch_started = (bool) $started;
	}

	/**
	 * Export mapping state for job persistence.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_mapping_state() {
		return array(
			'mapping'              => $this->mapping,
			'requires_remapping'   => $this->requires_remapping,
			'exists'               => $this->exists,
			'url_remap'            => $this->url_remap,
			'featured_images'      => $this->featured_images,
			'user_slug_override'   => $this->user_slug_override,
			'pending_attachments'  => $this->pending_attachments,
			'version'              => $this->version,
			'base_url'             => $this->base_url,
		);
	}

	/**
	 * Restore mapping state from a persisted job.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $state Persisted state.
	 *
	 * @return void
	 */
	public function set_mapping_state( array $state ) {
		if ( isset( $state['mapping'] ) && is_array( $state['mapping'] ) ) {
			$this->mapping = $state['mapping'];
		}
		if ( isset( $state['requires_remapping'] ) && is_array( $state['requires_remapping'] ) ) {
			$this->requires_remapping = $state['requires_remapping'];
		}
		if ( isset( $state['exists'] ) && is_array( $state['exists'] ) ) {
			$this->exists = $state['exists'];
		}
		if ( isset( $state['url_remap'] ) && is_array( $state['url_remap'] ) ) {
			$this->url_remap = $state['url_remap'];
		}
		if ( isset( $state['featured_images'] ) && is_array( $state['featured_images'] ) ) {
			$this->featured_images = $state['featured_images'];
		}
		if ( isset( $state['user_slug_override'] ) && is_array( $state['user_slug_override'] ) ) {
			$this->user_slug_override = $state['user_slug_override'];
		}
		if ( ! empty( $state['version'] ) ) {
			$this->version = $state['version'];
		}
		if ( ! empty( $state['base_url'] ) ) {
			$this->base_url = $state['base_url'];
		}
		if ( isset( $state['pending_attachments'] ) && is_array( $state['pending_attachments'] ) ) {
			$this->pending_attachments = $state['pending_attachments'];
		}
	}

	/**
	 * Build an ordered manifest of importable entities in the WXR file.
	 *
	 * @since 3.0.0
	 *
	 * @param string $file Path to WXR file.
	 *
	 * @return array<int, array<string, string>>|WP_Error
	 */
	public function build_import_manifest( $file ) {
		$reader = $this->get_reader( $file );
		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$manifest = array();

		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}

			switch ( $reader->name ) {
				case 'wp:author':
					$manifest[] = array( 'type' => 'author' );
					$reader->next( 'wp:author' );
					break;

				case 'wp:category':
					$manifest[] = array( 'type' => 'term', 'kind' => 'category' );
					$reader->next( 'wp:category' );
					break;

				case 'wp:tag':
					$manifest[] = array( 'type' => 'term', 'kind' => 'tag' );
					$reader->next( 'wp:tag' );
					break;

				case 'wp:term':
					$manifest[] = array( 'type' => 'term', 'kind' => 'term' );
					$reader->next( 'wp:term' );
					break;

				case 'item':
					$manifest[] = array( 'type' => 'item' );
					$reader->next( 'item' );
					break;
			}
		}

		return $manifest;
	}

	/**
	 * Process a batch of entities from the WXR file.
	 *
	 * @since 3.0.0
	 *
	 * @param string $file         Path to WXR file.
	 * @param int    $start_index  Zero-based manifest index to start from.
	 * @param int    $batch_size   Maximum entities to process.
	 * @param array  $item_manifest         Optional pre-built manifest.
	 * @param int    $manifest_entity_total Known entity count when manifest is not stored.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function import_batch( $file, $start_index, $batch_size, $item_manifest = array(), $manifest_entity_total = 0 ) {
		libxml_use_internal_errors( true );
		libxml_clear_errors();

		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		if ( ! $this->import_batch_started ) {
			$result = $this->import_start( $file );
			if ( is_wp_error( $result ) ) {
				libxml_use_internal_errors( false );
				return $result;
			}
			$this->import_batch_started = true;
		}

		if ( empty( $item_manifest ) && $manifest_entity_total <= 0 ) {
			$item_manifest = $this->build_import_manifest( $file );
			if ( is_wp_error( $item_manifest ) ) {
				libxml_use_internal_errors( false );
				return $item_manifest;
			}
		}

		$manifest_total = (int) $manifest_entity_total;
		if ( $manifest_total <= 0 ) {
			$manifest_total = count( $item_manifest );
		}

		$reader = $this->get_reader( $file );
		if ( is_wp_error( $reader ) ) {
			libxml_use_internal_errors( false );
			return $reader;
		}

		$this->version  = '1.0';
		$this->base_url = '';

		$entity_index   = 0;
		$batch_count    = 0;
		$skipped        = 0;
		$failed         = 0;
		$incomplete     = false;
		$counts         = array(
			'posts'    => 0,
			'comments' => 0,
			'terms'    => 0,
			'users'    => 0,
			'media'    => 0,
		);
		$comments_before = count( $this->mapping['comment'] );
		$item_results    = array();

		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}

			$importable = in_array(
				$reader->name,
				array( 'wp:author', 'wp:category', 'wp:tag', 'wp:term', 'item' ),
				true
			);

			if ( ! $importable ) {
				if ( 'wp:wxr_version' === $reader->name ) {
					$this->version = $reader->readString();
					$reader->next();
				} elseif ( 'wp:base_site_url' === $reader->name ) {
					$this->base_url = $reader->readString();
					$reader->next();
				}
				continue;
			}

			if ( $entity_index < $start_index ) {
				$entity_index++;
				continue;
			}

			if ( $batch_count >= $batch_size ) {
				break;
			}

			$batch_result = $this->process_manifest_entity( $reader );
			if ( is_array( $batch_result ) && ! empty( $batch_result['incomplete'] ) ) {
				$incomplete = true;
				break;
			}

			$manifest_entry = isset( $item_manifest[ $entity_index ] ) ? $item_manifest[ $entity_index ] : array();
			$item_results[] = $this->format_batch_item_record( $batch_result, $manifest_entry );

			if ( is_wp_error( $batch_result ) ) {
				$failed++;
			} elseif ( ! empty( $batch_result['skipped'] ) ) {
				$skipped++;
			} else {
				$type = isset( $batch_result['type'] ) ? $batch_result['type'] : '';
				if ( isset( $counts[ $type ] ) ) {
					$counts[ $type ]++;
				}
			}

			$entity_index++;
			$batch_count++;
		}

		$counts['comments'] = count( $this->mapping['comment'] ) - $comments_before;

		if ( $manifest_total > 0 ) {
			$is_complete = $entity_index >= $manifest_total;
		} else {
			// No manifest count — treat a short final batch as end-of-file.
			$is_complete = ( $batch_count > 0 && $batch_count < $batch_size );
		}

		return array(
			'processed'        => $batch_count,
			'skipped'          => $skipped,
			'failed'           => $failed,
			'counts'           => $counts,
			'item_results'     => $item_results,
			'next_item_index'  => $entity_index,
			'is_complete'      => $is_complete,
			'incomplete'       => $incomplete,
		);
	}

	/**
	 * Inspect one manifest entity without importing it.
	 *
	 * Used after a single-entity batch times out to determine whether WordPress
	 * inserted the post before the request was killed by the web server.
	 *
	 * @since 3.0.8
	 *
	 * @param string $file        Path to WXR file.
	 * @param int    $start_index Zero-based manifest index to inspect.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function inspect_manifest_entity( $file, $start_index ) {
		libxml_use_internal_errors( true );
		libxml_clear_errors();

		$reader = $this->get_reader( $file );
		if ( is_wp_error( $reader ) ) {
			libxml_use_internal_errors( false );
			return $reader;
		}

		$entity_index = 0;
		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}

			if ( ! in_array( $reader->name, array( 'wp:author', 'wp:category', 'wp:tag', 'wp:term', 'item' ), true ) ) {
				continue;
			}

			if ( $entity_index < $start_index ) {
				$entity_index++;
				continue;
			}

			if ( 'item' !== $reader->name ) {
				return array(
					'entity_type' => 'wp:author' === $reader->name ? 'users' : 'terms',
					'title'       => sprintf( 'XML entity %d', $start_index ),
				);
			}

			$node = $this->safe_expand( $reader, 'item' );
			if ( false === $node ) {
				libxml_use_internal_errors( false );
				return new WP_Error( 'wxr_importer.inspect.bad_item', __( 'Malformed item node.', 'wordpress-importer' ) );
			}

			$parsed = $this->parse_post_node( $node );
			if ( is_wp_error( $parsed ) ) {
				libxml_use_internal_errors( false );
				return $parsed;
			}

			$post_type = isset( $parsed['data']['post_type'] ) ? $parsed['data']['post_type'] : 'post';
			$old_id    = isset( $parsed['data']['post_id'] ) ? (string) $parsed['data']['post_id'] : '';
			$title     = isset( $parsed['data']['post_title'] ) ? (string) $parsed['data']['post_title'] : sprintf( 'XML entity %d', $start_index );
			$type      = 'attachment' === $post_type ? 'media' : 'posts';
			$new_id    = null;

			if ( 'attachment' !== $post_type ) {
				$existing = $this->post_exists( $parsed['data'] );
				$new_id   = $existing ? (int) $existing : null;
			}

			libxml_use_internal_errors( false );

			return array(
				'entity_type' => $type,
				'old_id'      => $old_id,
				'title'       => $title,
				'new_id'      => $new_id,
			);
		}

		libxml_use_internal_errors( false );

		return new WP_Error( 'wxr_importer.inspect.not_found', __( 'The XML entity could not be found.', 'wordpress-importer' ) );
	}

	/**
	 * Format a batch entity result for per-item tracking.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed>|WP_Error $batch_result   Entity result.
	 * @param array<string, mixed>          $manifest_entry Manifest entry.
	 *
	 * @return array<string, mixed>
	 */
	protected function format_batch_item_record( $batch_result, array $manifest_entry ) {
		$entity_type = 'posts';
		if ( is_array( $batch_result ) && ! empty( $batch_result['type'] ) ) {
			$entity_type = $batch_result['type'];
		} elseif ( ! empty( $manifest_entry['type'] ) ) {
			$type_map = array(
				'author' => 'users',
				'item'   => 'posts',
				'term'   => 'terms',
			);
			$entity_type = isset( $type_map[ $manifest_entry['type'] ] ) ? $type_map[ $manifest_entry['type'] ] : 'posts';
		}

		if ( is_wp_error( $batch_result ) ) {
			return array(
				'entity_type'   => $entity_type,
				'old_id'        => '',
				'title'         => '',
				'new_id'        => null,
				'status'        => 'failed',
				'error_message' => $batch_result->get_error_message(),
			);
		}

		$status = ! empty( $batch_result['skipped'] ) ? 'skipped' : 'imported';

		return array(
			'entity_type'   => $entity_type,
			'old_id'        => isset( $batch_result['old_id'] ) ? (string) $batch_result['old_id'] : '',
			'title'         => isset( $batch_result['title'] ) ? (string) $batch_result['title'] : '',
			'new_id'        => isset( $batch_result['new_id'] ) ? (int) $batch_result['new_id'] : null,
			'status'        => $status,
			'error_message' => '',
		);
	}

	/**
	 * Process a single importable entity at the current reader position.
	 *
	 * @since 3.0.0
	 *
	 * @param XMLReader $reader Active reader positioned on an importable element.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	protected function process_manifest_entity( $reader ) {
		switch ( $reader->name ) {
			case 'item':
				$node = $this->safe_expand( $reader, 'item' );
				if ( false === $node ) {
					return new WP_Error( 'wxr_importer.batch.bad_item', __( 'Malformed item node.', 'wordpress-importer' ) );
				}

				$parsed = $this->parse_post_node( $node );
				if ( is_wp_error( $parsed ) ) {
					$this->log_error( $parsed );
					return $parsed;
				}

				$post_type = isset( $parsed['data']['post_type'] ) ? $parsed['data']['post_type'] : 'post';
				$old_id    = isset( $parsed['data']['post_id'] ) ? (int) $parsed['data']['post_id'] : 0;
				$title     = isset( $parsed['data']['post_title'] ) ? $parsed['data']['post_title'] : '';
				$type      = 'attachment' === $post_type ? 'media' : 'posts';

				if ( 'attachment' === $post_type && empty( $this->options['fetch_attachments'] ) ) {
					return array(
						'skipped' => true,
						'type'    => $type,
						'old_id'  => $old_id,
						'title'   => $title,
						'new_id'  => null,
					);
				}

				$existing = $this->post_exists( $parsed['data'] );

				$post_status = $this->process_post( $parsed['data'], $parsed['meta'], $parsed['comments'], $parsed['terms'] );

				$new_id = isset( $this->mapping['post'][ $old_id ] ) ? (int) $this->mapping['post'][ $old_id ] : null;

				if ( is_array( $post_status ) ) {
					if ( ! empty( $post_status['post_id'] ) ) {
						$new_id = (int) $post_status['post_id'];
					}

					if ( ! empty( $post_status['incomplete'] ) ) {
						return array(
							'incomplete' => true,
							'type'       => $type,
							'old_id'     => $old_id,
							'title'      => $title,
							'new_id'     => $new_id,
						);
					}
				}

				$completed_resumed_post = is_array( $post_status ) && empty( $post_status['incomplete'] );
				if ( $existing && ! $completed_resumed_post ) {
					return array(
						'skipped' => true,
						'type'    => $type,
						'old_id'  => $old_id,
						'title'   => $title,
						'new_id'  => $new_id,
					);
				}

				return array(
					'type'   => $type,
					'old_id' => $old_id,
					'title'  => $title,
					'new_id' => $new_id,
				);

			case 'wp:author':
				$node = $reader->expand();
				if ( false === $node ) {
					return new WP_Error( 'wxr_importer.batch.bad_author', __( 'Malformed author node.', 'wordpress-importer' ) );
				}

				$parsed = $this->parse_author_node( $node );
				if ( is_wp_error( $parsed ) ) {
					$this->log_error( $parsed );
					return $parsed;
				}

				$status = $this->process_author( $parsed['data'], $parsed['meta'] );

				if ( is_wp_error( $status ) ) {
					return $status;
				}

				$old_id = isset( $parsed['data']['ID'] ) ? (int) $parsed['data']['ID'] : 0;
				$title  = isset( $parsed['data']['display_name'] ) ? $parsed['data']['display_name'] : '';
				if ( empty( $title ) && isset( $parsed['data']['user_login'] ) ) {
					$title = $parsed['data']['user_login'];
				}
				$new_id = isset( $this->mapping['user'][ $old_id ] ) ? (int) $this->mapping['user'][ $old_id ] : null;

				return array(
					'type'   => 'users',
					'old_id' => $old_id,
					'title'  => $title,
					'new_id' => $new_id,
				);

			case 'wp:category':
			case 'wp:tag':
			case 'wp:term':
				$kind = str_replace( 'wp:', '', $reader->name );
				if ( 'category' === $kind ) {
					$term_kind = 'category';
				} elseif ( 'tag' === $kind ) {
					$term_kind = 'tag';
				} else {
					$term_kind = 'term';
				}

				$node = $this->safe_expand( $reader, $kind );
				if ( false === $node ) {
					return new WP_Error( 'wxr_importer.batch.bad_term', __( 'Malformed term node.', 'wordpress-importer' ) );
				}

				$parsed = $this->parse_term_node( $node, 'wp:category' === $reader->name ? 'category' : ( 'wp:tag' === $reader->name ? 'tag' : null ) );
				if ( is_wp_error( $parsed ) ) {
					$this->log_error( $parsed );
					return $parsed;
				}

				$this->process_term( $parsed['data'], $parsed['meta'] );

				$old_id = isset( $parsed['data']['term_id'] ) ? (int) $parsed['data']['term_id'] : 0;
				$title  = isset( $parsed['data']['name'] ) ? $parsed['data']['name'] : '';
				$new_id = isset( $this->mapping['term_id'][ $old_id ] ) ? (int) $this->mapping['term_id'][ $old_id ] : null;

				return array(
					'type'   => 'terms',
					'old_id' => $old_id,
					'title'  => $title,
					'new_id' => $new_id,
				);
		}

		return array( 'skipped' => true );
	}

	/**
	 * Public wrapper for import_end().
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function import_end_public() {
		$this->import_end();
	}

	/**
	 * Posts requiring remapping.
	 *
	 * @since 3.0.0
	 *
	 * @return array<int, bool>
	 */
	public function get_requires_remapping_posts() {
		return $this->requires_remapping['post'];
	}

	/**
	 * Comments requiring remapping.
	 *
	 * @since 3.0.0
	 *
	 * @return array<int, bool>
	 */
	public function get_requires_remapping_comments() {
		return $this->requires_remapping['comment'];
	}

	/**
	 * Featured images map.
	 *
	 * @since 3.0.0
	 *
	 * @return array<int, int>
	 */
	public function get_featured_images() {
		return $this->featured_images;
	}

	/**
	 * Run post_process_posts for a batch (public for remapper).
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, bool> $todo Post IDs to process.
	 *
	 * @return void
	 */
	public function post_process_posts_public( array $todo ) {
		$this->post_process_posts( $todo );
	}

	/**
	 * Run post_process_comments for a batch (public for remapper).
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, bool> $todo Comment IDs to process.
	 *
	 * @return void
	 */
	public function post_process_comments_public( array $todo ) {
		$this->post_process_comments( $todo );
	}

	/**
	 * Run URL replacement (public for remapper).
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function replace_attachment_urls_in_content_public() {
		$this->replace_attachment_urls_in_content();
	}

	/**
	 * Remap featured images for a batch of posts.
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, int> $batch Featured image map subset.
	 *
	 * @return void
	 */
	public function remap_featured_images_batch( array $batch ) {
		$saved = $this->featured_images;
		$this->featured_images = $batch;
		$this->remap_featured_images();
		$this->featured_images = $saved;
	}
}
