<?php
/**
 * WXR entity parser — reads one entity from XML and returns normalized payload data.
 *
 * @package Better_WordPress_Importer
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses a single WXR entity into a normalized PHP array for queue storage.
 *
 * @since 1.1.0
 */
class Better_WXR_Parser {

	/**
	 * Importable top-level XML elements in document order.
	 *
	 * @since 1.1.0
	 */
	const ENTITY_ELEMENTS = array(
		'wp:author',
		'wp:category',
		'wp:tag',
		'wp:term',
		'item',
	);

	/**
	 * Parse a WXR file into a compact manifest and persisted queue payloads.
	 *
	 * @since 1.1.0
	 *
	 * @param string        $file_path       Absolute WXR path.
	 * @param callable|null $entity_callback Optional callback for each parsed entity.
	 *
	 * @return array<string, mixed>|WP_Error Parsed scan data.
	 */
	public function parse_file( $file_path, $entity_callback = null ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'better_importer.parse.unreadable',
				__( 'The export file could not be read.', 'better-wordpress-importer' )
			);
		}

		$format = Better_Format_Detector::validate_for_import( $file_path );
		if ( is_wp_error( $format ) ) {
			return $format;
		}

		$reader = $this->open_reader( $file_path );
		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		wp_raise_memory_limit( 'admin' );

		$manifest    = array();
		$payloads    = array();
		$index       = 0;
		$wxr_version = '1.0';
		$preflight   = array(
			'title'       => '',
			'generator'   => '',
			'home'        => '',
			'siteurl'     => '',
			'wxr_version' => $wxr_version,
			'authors'     => array(),
			'counts'      => array(
				'users'       => 0,
				'terms'       => 0,
				'posts'       => 0,
				'attachments' => 0,
				'comments'    => 0,
			),
		);

		$skip_depth = null;

		while ( $reader->read() ) {
			if ( null !== $skip_depth ) {
				if ( $reader->depth > $skip_depth || ( XMLReader::END_ELEMENT === $reader->nodeType && $reader->depth === $skip_depth ) ) {
					continue;
				}

				$skip_depth = null;
			}

			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}

			if ( ! in_array( $reader->name, self::ENTITY_ELEMENTS, true ) ) {
				switch ( $reader->name ) {
					case 'wp:wxr_version':
						$wxr_version = $reader->readString();
						$preflight['wxr_version'] = $wxr_version;
						break;
					case 'generator':
						$preflight['generator'] = $reader->readString();
						break;
					case 'title':
						$preflight['title'] = $reader->readString();
						break;
					case 'wp:base_site_url':
						$preflight['siteurl'] = $reader->readString();
						break;
					case 'wp:base_blog_url':
						$preflight['home'] = $reader->readString();
						break;
				}
				continue;
			}

			$element_name = $reader->name;
			$node = $reader->expand();
			if ( false === $node ) {
				$reader->close();
				return new WP_Error(
					'better_importer.parse.expand_failed',
					__( 'Could not read an entity from the export file.', 'better-wordpress-importer' )
				);
			}

			$parsed = $this->parse_entity_node( $element_name, $node );

			if ( is_wp_error( $parsed ) ) {
				$reader->close();
				return $parsed;
			}

			$summary = $this->payload_to_manifest_entry( $index, $parsed );
			if ( 'user' === $summary['t'] ) {
				$preflight['authors'][] = $this->payload_to_author_summary( $parsed );
			}
			$manifest[] = $summary;
			if ( is_callable( $entity_callback ) ) {
				$callback_result = call_user_func( $entity_callback, $summary, $parsed );
				if ( is_wp_error( $callback_result ) ) {
					$reader->close();
					return $callback_result;
				}
			} else {
				$payloads[ $index ] = $parsed;
			}
			$this->increment_counts( $preflight['counts'], $parsed );
			++$index;
			$skip_depth = $reader->depth;
		}

		$reader->close();

		if ( version_compare( $wxr_version, Better_Preflight::MAX_WXR_VERSION, '>' ) ) {
			return new WP_Error(
				'better_importer.parse.unsupported_version',
				sprintf(
					/* translators: 1: WXR version, 2: supported version */
					__( 'This WXR file (version %1$s) is newer than the importer supports (version %2$s).', 'better-wordpress-importer' ),
					$wxr_version,
					Better_Preflight::MAX_WXR_VERSION
				)
			);
		}

		$preflight['manifest_entity_total'] = count( $manifest );

		return array(
			'manifest'  => $manifest,
			'preflight' => $preflight,
			'payloads'  => $payloads,
		);
	}

	/**
	 * Parse a DOM node for the given WXR element name.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $element_name WXR element name.
	 * @param DOMNode   $node         Expanded DOM node.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	protected function parse_entity_node( $element_name, $node ) {
		switch ( $element_name ) {
			case 'wp:author':
				return $this->parse_author( $node );
			case 'wp:category':
				return $this->parse_term( $node, 'category' );
			case 'wp:tag':
				return $this->parse_term( $node, 'tag' );
			case 'wp:term':
				return $this->parse_term( $node, 'term' );
			case 'item':
				return $this->parse_post( $node );
		}

		return new WP_Error(
			'better_importer.parse.unsupported_entity',
			__( 'Unsupported entity type in export file.', 'better-wordpress-importer' )
		);
	}

	/**
	 * Parse a wp:author node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Author node.
	 *
	 * @return array<string, mixed>
	 */
	protected function parse_author( $node ) {
		$data = array();
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
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

		return array(
			'entity_kind' => 'user',
			'data'        => $data,
			'meta'        => array(),
			'comments'    => array(),
			'terms'       => array(),
		);
	}

	/**
	 * Parse a taxonomy node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Term node.
	 * @param string  $kind category, tag, or term.
	 *
	 * @return array<string, mixed>
	 */
	protected function parse_term( $node, $kind ) {
		$tag_map = array(
			'category' => array(
				'id'          => 'wp:term_id',
				'slug'        => 'wp:category_nicename',
				'parent'      => 'wp:category_parent',
				'name'        => 'wp:cat_name',
				'description' => 'wp:category_description',
				'taxonomy'    => 'category',
			),
			'tag'      => array(
				'id'          => 'wp:term_id',
				'slug'        => 'wp:tag_slug',
				'parent'      => null,
				'name'        => 'wp:tag_name',
				'description' => 'wp:tag_description',
				'taxonomy'    => 'post_tag',
			),
			'term'     => array(
				'id'          => 'wp:term_id',
				'slug'        => 'wp:term_slug',
				'parent'      => 'wp:term_parent',
				'name'        => 'wp:term_name',
				'description' => 'wp:term_description',
				'taxonomy'    => null,
			),
		);

		$map  = $tag_map[ $kind ];
		$data = array( 'taxonomy' => $map['taxonomy'] );

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			if ( $map['id'] === $child->tagName ) {
				$data['id'] = $child->textContent;
			} elseif ( $map['slug'] === $child->tagName ) {
				$data['slug'] = $child->textContent;
			} elseif ( $map['parent'] && $map['parent'] === $child->tagName ) {
				$data['parent'] = $child->textContent;
			} elseif ( $map['name'] === $child->tagName ) {
				$data['name'] = $child->textContent;
			} elseif ( $map['description'] === $child->tagName ) {
				$data['description'] = $child->textContent;
			} elseif ( 'term' === $kind && 'wp:term_taxonomy' === $child->tagName ) {
				$data['taxonomy'] = $child->textContent;
			}
		}

		return array(
			'entity_kind' => 'term',
			'term_kind'   => $kind,
			'data'        => $data,
			'meta'        => array(),
			'comments'    => array(),
			'terms'       => array(),
		);
	}

	/**
	 * Parse an item node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Item node.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	protected function parse_post( $node ) {
		$data     = array();
		$meta     = array();
		$comments = array();
		$terms    = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
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
					if ( 'auto-draft' === $data['post_status'] ) {
						return new WP_Error(
							'better_importer.parse.auto_draft',
							__( 'Cannot import auto-draft posts.', 'better-wordpress-importer' )
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

		return array(
			'entity_kind' => ( isset( $data['post_type'] ) && 'attachment' === $data['post_type'] ) ? 'attachment' : 'post',
			'data'        => $data,
			'meta'        => $meta,
			'comments'    => $comments,
			'terms'       => $terms,
		);
	}

	/**
	 * Parse a wp:postmeta or wp:commentmeta node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Meta node.
	 *
	 * @return array<string, string>|null
	 */
	protected function parse_meta_node( $node ) {
		$key   = '';
		$value = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			if ( 'wp:meta_key' === $child->tagName ) {
				$key = $child->textContent;
			} elseif ( 'wp:meta_value' === $child->tagName ) {
				$value = $child->textContent;
			}
		}

		if ( '' === $key ) {
			return null;
		}

		return array(
			'key'   => $key,
			'value' => $value,
		);
	}

	/**
	 * Parse a wp:comment node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Comment node.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function parse_comment_node( $node ) {
		$data = array();
		$meta = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
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
				case 'wp:comment_author_url':
					$data['comment_author_url'] = $child->textContent;
					break;
				case 'wp:comment_author_IP':
					$data['comment_author_IP'] = $child->textContent;
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
				case 'wp:comment_user_id':
					$data['comment_user_id'] = $child->textContent;
					break;
				case 'wp:commentmeta':
					$meta_item = $this->parse_meta_node( $child );
					if ( ! empty( $meta_item ) ) {
						$meta[] = $meta_item;
					}
					break;
			}
		}

		if ( empty( $data ) ) {
			return null;
		}

		$data['meta'] = $meta;

		return $data;
	}

	/**
	 * Parse a category node attached to a post item.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Category node.
	 *
	 * @return array<string, string>|null
	 */
	protected function parse_category_node( $node ) {
		$term = array(
			'name'     => $node->getAttribute( 'nicename' ),
			'slug'     => $node->getAttribute( 'nicename' ),
			'taxonomy' => 'category',
		);

		if ( $node->hasAttribute( 'domain' ) ) {
			$term['taxonomy'] = $node->getAttribute( 'domain' );
		}

		$term['name'] = $node->textContent;

		return $term;
	}

	/**
	 * Build the compact manifest entry for a parsed payload.
	 *
	 * @since 1.1.0
	 *
	 * @param int                  $index   Zero-based entity index.
	 * @param array<string, mixed> $payload Parsed entity payload.
	 *
	 * @return array<string, string|int>
	 */
	protected function payload_to_manifest_entry( $index, array $payload ) {
		$kind = isset( $payload['entity_kind'] ) ? $payload['entity_kind'] : 'post';
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		if ( 'user' === $kind ) {
			return array(
				'i'     => $index,
				't'     => 'user',
				'id'    => isset( $data['ID'] ) ? (string) $data['ID'] : '',
				'title' => isset( $data['user_login'] ) ? (string) $data['user_login'] : '',
			);
		}

		if ( 'term' === $kind ) {
			return array(
				'i'     => $index,
				't'     => 'term',
				'id'    => isset( $data['id'] ) ? (string) $data['id'] : '',
				'title' => isset( $data['name'] ) ? (string) $data['name'] : '',
			);
		}

		return array(
			'i'     => $index,
			't'     => ( 'attachment' === $kind ) ? 'attachment' : 'post',
			'id'    => isset( $data['post_id'] ) ? (string) $data['post_id'] : '',
			'title' => isset( $data['post_title'] ) ? (string) $data['post_title'] : '',
		);
	}

	/**
	 * Build a source author summary for import options.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, mixed> $payload Parsed user payload.
	 *
	 * @return array<string, string>
	 */
	protected function payload_to_author_summary( array $payload ) {
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		return array(
			'id'           => isset( $data['ID'] ) ? (string) $data['ID'] : '',
			'title'        => isset( $data['user_login'] ) ? (string) $data['user_login'] : '',
			'login'        => isset( $data['user_login'] ) ? (string) $data['user_login'] : '',
			'email'        => isset( $data['user_email'] ) ? (string) $data['user_email'] : '',
			'display_name' => isset( $data['display_name'] ) ? (string) $data['display_name'] : '',
		);
	}

	/**
	 * Increment preflight counters for one parsed payload.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, int>    $counts  Counter map.
	 * @param array<string, mixed>  $payload Parsed entity payload.
	 *
	 * @return void
	 */
	protected function increment_counts( array &$counts, array $payload ) {
		$kind = isset( $payload['entity_kind'] ) ? $payload['entity_kind'] : 'post';

		if ( 'user' === $kind ) {
			++$counts['users'];
		} elseif ( 'term' === $kind ) {
			++$counts['terms'];
		} elseif ( 'attachment' === $kind ) {
			++$counts['attachments'];
		} else {
			++$counts['posts'];
		}

		$counts['comments'] += count( $payload['comments'] ?? array() );
	}

	/**
	 * Open an XMLReader for the WXR file.
	 *
	 * @since 1.1.0
	 *
	 * @param string $file_path Absolute WXR path.
	 *
	 * @return XMLReader|WP_Error
	 */
	protected function open_reader( $file_path ) {
		libxml_use_internal_errors( true );
		libxml_clear_errors();

		$old_value = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			$old_value = libxml_disable_entity_loader( true );
		}

		$reader = new XMLReader();
		$status = $reader->open( $file_path );

		if ( ! is_null( $old_value ) && PHP_VERSION_ID < 80000 ) {
			libxml_disable_entity_loader( $old_value );
		}

		if ( ! $status ) {
			libxml_use_internal_errors( false );
			return new WP_Error(
				'better_importer.parse.cannot_open',
				__( 'Could not open the export file for parsing.', 'better-wordpress-importer' )
			);
		}

		return $reader;
	}
}
