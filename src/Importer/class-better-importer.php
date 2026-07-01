<?php
/**
 * Entity import operations — create users, terms, and posts from parsed payloads.
 *
 * @package Better_WordPress_Importer
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Performs WordPress writes for normalized import payloads.
 *
 * @since 1.1.0
 */
class Better_Importer {

	/**
	 * Logger instance.
	 *
	 * @since 1.1.0
	 * @var Better_Logger
	 */
	protected $logger;

	/**
	 * Cached source-URL to local-URL map for content rewriting.
	 *
	 * @since 1.6.0
	 * @var array<string, string>|null
	 */
	protected $url_map = null;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Logger $logger Logger instance.
	 */
	public function __construct( Better_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Import a user from parsed payload data.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job      $job      Import job.
	 * @param array<string, mixed>   $payload  Parsed payload.
	 * @param Better_Import_Remapper $remapper ID remapper.
	 *
	 * @return array<string, mixed>
	 */
	public function import_user( Better_Import_Job $job, array $payload, Better_Import_Remapper $remapper ) {
		$data         = isset( $payload['data'] ) ? $payload['data'] : array();
		$original_id  = isset( $data['ID'] ) ? (int) $data['ID'] : 0;
		$original_slug = isset( $data['user_login'] ) ? $data['user_login'] : '';

		$mapped_user_id = $this->get_configured_user_mapping( $job, $original_id, $original_slug );
		if ( $mapped_user_id > 0 ) {
			$remapper->set( 'user', $original_id, $mapped_user_id );
			$remapper->set( 'user_slug', $original_slug, $mapped_user_id );

			return array(
				'status'        => 'skipped',
				'new_entity_id' => $mapped_user_id,
				'message'       => sprintf(
					/* translators: 1: source user login, 2: local user ID */
					__( 'Mapped source user "%1$s" to selected local user #%2$d.', 'better-wordpress-importer' ),
					$original_slug,
					$mapped_user_id
				),
			);
		}

		if ( $remapper->has( 'user', $original_id ) || $remapper->has( 'user_slug', $original_slug ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'User was already mapped earlier in this import.', 'better-wordpress-importer' ),
			);
		}

		$login = $original_slug;
		if ( isset( $job->options['user_slug_override'][ $login ] ) ) {
			$login = $job->options['user_slug_override'][ $login ];
		}

		$login = sanitize_user( $login, true );
		if ( '' === $login ) {
			return array(
				'status' => 'failed',
				'error'  => __( 'User is missing a valid login.', 'better-wordpress-importer' ),
			);
		}

		$existing_user = get_user_by( 'login', $login );
		if ( ! $existing_user && ! empty( $data['user_email'] ) ) {
			$existing_user = get_user_by( 'email', $data['user_email'] );
		}

		if ( $existing_user ) {
			$remapper->set( 'user', $original_id, $existing_user->ID );
			$remapper->set( 'user_slug', $original_slug, $existing_user->ID );

			return array(
				'status'        => 'skipped',
				'new_entity_id' => (int) $existing_user->ID,
				'message'       => sprintf(
					/* translators: 1: source user login, 2: local user ID */
					__( 'Mapped existing user "%1$s" to local user #%2$d.', 'better-wordpress-importer' ),
					$login,
					(int) $existing_user->ID
				),
			);
		}

		$userdata = array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password(),
		);

		$allowed = array( 'user_email', 'display_name', 'first_name', 'last_name' );
		foreach ( $allowed as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$userdata[ $key ] = $data[ $key ];
			}
		}

		$user_id = wp_insert_user( wp_slash( $userdata ) );
		if ( is_wp_error( $user_id ) ) {
			return array(
				'status' => 'failed',
				'error'  => $user_id->get_error_message(),
			);
		}

		$remapper->set( 'user', $original_id, $user_id );
		$remapper->set( 'user_slug', $original_slug, $user_id );

		return array(
			'status'        => 'complete',
			'new_entity_id' => $user_id,
		);
	}

	/**
	 * Import a term from parsed payload data.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job      $job      Import job.
	 * @param array<string, mixed>   $payload  Parsed payload.
	 * @param Better_Import_Remapper $remapper ID remapper.
	 *
	 * @return array<string, mixed>
	 */
	public function import_term( Better_Import_Job $job, array $payload, Better_Import_Remapper $remapper ) {
		$data        = isset( $payload['data'] ) ? $payload['data'] : array();
		$original_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$taxonomy    = isset( $data['taxonomy'] ) ? $data['taxonomy'] : '';
		$slug        = isset( $data['slug'] ) ? $data['slug'] : '';

		if ( empty( $taxonomy ) || empty( $data['name'] ) ) {
			return array(
				'status' => 'failed',
				'error'  => __( 'Term is missing taxonomy or name.', 'better-wordpress-importer' ),
			);
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'status'  => 'skipped',
				'message' => sprintf(
					/* translators: %s: taxonomy slug */
					__( 'Skipped source term because taxonomy "%s" is not registered on this site.', 'better-wordpress-importer' ),
					$taxonomy
				),
			);
		}

		$mapping_key = sha1( $taxonomy . ':' . $slug );
		$existing    = $this->term_exists( $data );

		if ( $existing ) {
			$remapper->set( 'term', $mapping_key, $existing );
			$remapper->set( 'term_id', $original_id, $existing );
			return array(
				'status'        => 'skipped',
				'new_entity_id' => $existing,
				'message'       => sprintf(
					/* translators: 1: term name, 2: taxonomy slug */
					__( 'Mapped existing term "%1$s" in taxonomy "%2$s".', 'better-wordpress-importer' ),
					$data['name'],
					$taxonomy
				),
			);
		}

		if ( $remapper->has( 'term', $mapping_key ) ) {
			return array(
				'status'        => 'skipped',
				'new_entity_id' => $remapper->get( 'term', $mapping_key ),
				'message'       => sprintf(
					/* translators: 1: term name, 2: taxonomy slug */
					__( 'Term "%1$s" in taxonomy "%2$s" was already mapped earlier in this import.', 'better-wordpress-importer' ),
					$data['name'],
					$taxonomy
				),
			);
		}

		$termdata = array();
		if ( ! empty( $data['slug'] ) ) {
			$termdata['slug'] = $data['slug'];
		}
		if ( isset( $data['description'] ) ) {
			$termdata['description'] = $data['description'];
		}

		// WXR stores the parent as its slug. Resolve it to a local term when
		// the parent has already been imported.
		$parent_slug = isset( $data['parent'] ) ? (string) $data['parent'] : '';
		if ( '' !== $parent_slug ) {
			$mapped_parent = $remapper->get( 'term', sha1( $taxonomy . ':' . $parent_slug ) );
			if ( $mapped_parent ) {
				$termdata['parent'] = (int) $mapped_parent;
			}
		}

		$result = wp_insert_term( $data['name'], $taxonomy, $termdata );
		if ( is_wp_error( $result ) ) {
			return array(
				'status' => 'failed',
				'error'  => $result->get_error_message(),
			);
		}

		$term_id = (int) $result['term_id'];
		$remapper->set( 'term', $mapping_key, $term_id );
		$remapper->set( 'term_id', $original_id, $term_id );

		// Defer the parent link when the parent has not been imported yet; the
		// remapping phase resolves it once every term exists.
		if ( '' !== $parent_slug && empty( $termdata['parent'] ) ) {
			add_term_meta( $term_id, '_better_import_term_parent', $parent_slug );
		}

		return array(
			'status'        => 'complete',
			'new_entity_id' => $term_id,
		);
	}

	/**
	 * Create a post or attachment from parsed payload data.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job      $job      Import job.
	 * @param Better_Import_Queue_Item $item   Queue item.
	 * @param array<string, mixed>   $payload  Parsed payload.
	 * @param Better_Import_Remapper $remapper ID remapper.
	 *
	 * @return array<string, mixed>
	 */
	public function create_post( Better_Import_Job $job, Better_Import_Queue_Item $item, array $payload, Better_Import_Remapper $remapper ) {
		$data        = isset( $payload['data'] ) ? $payload['data'] : array();
		$meta        = isset( $payload['meta'] ) ? $payload['meta'] : array();
		$original_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;

		if ( empty( $data['post_type'] ) ) {
			return array(
				'status' => 'failed',
				'error'  => __( 'Post is missing a post type.', 'better-wordpress-importer' ),
			);
		}

		if ( 'attachment' === $data['post_type'] ) {
			if ( ! $job->get_option( 'fetch_attachments', false ) ) {
				return array(
					'status'  => 'skipped',
					'message' => __( 'Attachment skipped because media import is disabled.', 'better-wordpress-importer' ),
				);
			}
			return $this->import_attachment( $job, $data, $remapper );
		}

		$original_post_type = '';
		if ( ! get_post_type_object( $data['post_type'] ) ) {
			$strategy = $job->get_option( 'unknown_post_type_strategy', 'import_as_draft' );

			if ( 'fail' === $strategy ) {
				return array(
					'status' => 'failed',
					'code'   => 'better_importer.post.invalid_type',
					'error'  => sprintf(
						/* translators: %s: post type slug */
						__( 'Invalid post type: %s', 'better-wordpress-importer' ),
						$data['post_type']
					),
				);
			}

			if ( 'skip' === $strategy ) {
				return array(
					'status'  => 'skipped',
					'message' => sprintf(
						/* translators: 1: post title, 2: post type slug */
						__( 'Skipped "%1$s": post type "%2$s" is not registered on this site.', 'better-wordpress-importer' ),
						isset( $data['post_title'] ) ? $data['post_title'] : '',
						$data['post_type']
					),
				);
			}

			// import_as_draft (default): keep the content under a safe type and
			// record the source type so it can be migrated later.
			$original_post_type  = $data['post_type'];
			$data['post_type']   = 'post';
			$data['post_status'] = 'draft';
		}

		$existing = $this->post_exists( $data );
		if ( $existing && ! $this->is_resumable_post( $existing, $job->id, count( $meta ) ) ) {
			$remapper->set( 'post', $original_id, $existing );
			return array(
				'status'        => 'skipped',
				'new_entity_id' => $existing,
				'message'       => sprintf(
					/* translators: 1: post title, 2: local post ID */
					__( 'Mapped existing post "%1$s" to local post #%2$d.', 'better-wordpress-importer' ),
					isset( $data['post_title'] ) ? $data['post_title'] : '',
					$existing
				),
			);
		}

		if ( $existing ) {
			$post_id = $existing;
		} else {
			$deferred = array();
			$postarr  = $this->map_post_authors_and_parents( $data, $deferred, $job, $remapper );
			$post_id  = wp_insert_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $post_id ) ) {
				return array(
					'status' => 'failed',
					'error'  => $post_id->get_error_message(),
				);
			}

			update_post_meta( $post_id, '_better_import_job_id', $job->id );
			update_post_meta( $post_id, '_better_import_original_id', $original_id );

			if ( '' !== $original_post_type ) {
				update_post_meta( $post_id, '_better_import_original_post_type', $original_post_type );
			}

			// Persist deferred parent/author markers now so the remapping phase
			// can resolve them once the whole file is imported.
			foreach ( $deferred as $marker ) {
				add_post_meta( $post_id, $marker['key'], $marker['value'] );
			}

			if ( isset( $data['is_sticky'] ) && '1' === (string) $data['is_sticky'] ) {
				stick_post( $post_id );
			}

			// Menu items carry source object/parent IDs in meta that must be
			// remapped once every entity exists; flag them for the remap phase.
			if ( 'nav_menu_item' === $data['post_type'] ) {
				add_post_meta( $post_id, '_better_import_menu_item', 1 );
			}

			// Flag posts that reference uploads so the remap phase can rewrite
			// source media URLs to local ones once attachments are imported.
			if (
				$job->get_option( 'fetch_attachments', false )
				&& $job->get_option( 'remap_content_urls', true )
				&& ! empty( $data['post_content'] )
				&& false !== strpos( $data['post_content'], 'wp-content/uploads' )
			) {
				add_post_meta( $post_id, '_better_import_rewrite_pending', 1 );
			}
		}

		$remapper->set( 'post', $original_id, $post_id );

		return array(
			'status'        => 'created',
			'new_entity_id' => (int) $post_id,
		);
	}

	/**
	 * Import an attachment by fetching its source file into the media library.
	 *
	 * @since 1.6.0
	 *
	 * @param Better_Import_Job      $job      Import job.
	 * @param array<string, mixed>   $data     Attachment post data.
	 * @param Better_Import_Remapper $remapper ID remapper.
	 *
	 * @return array<string, mixed>
	 */
	protected function import_attachment( Better_Import_Job $job, array $data, Better_Import_Remapper $remapper ) {
		$original_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;

		$existing = $this->post_exists( $data );
		if ( $existing ) {
			$remapper->set( 'post', $original_id, $existing );
			return array(
				'status'        => 'skipped',
				'new_entity_id' => $existing,
				'message'       => sprintf(
					/* translators: 1: attachment title, 2: local post ID */
					__( 'Mapped existing attachment "%1$s" to #%2$d.', 'better-wordpress-importer' ),
					isset( $data['post_title'] ) ? $data['post_title'] : '',
					$existing
				),
			);
		}

		$remote_url = ! empty( $data['attachment_url'] ) ? $data['attachment_url'] : ( ! empty( $data['guid'] ) ? $data['guid'] : '' );
		if ( '' === $remote_url ) {
			return array(
				'status' => 'failed',
				'code'   => 'better_importer.attachment.no_url',
				'error'  => __( 'Attachment has no source URL to fetch.', 'better-wordpress-importer' ),
			);
		}

		// Resolve a root-relative URL against the source site base.
		if ( '/' === substr( $remote_url, 0, 1 ) ) {
			$base = $this->source_base_url( $job );
			if ( '' !== $base ) {
				$remote_url = rtrim( $base, '/' ) . '/' . ltrim( $remote_url, '/' );
			}
		}

		$upload = $this->fetch_remote_file( $remote_url );
		if ( is_wp_error( $upload ) ) {
			return array(
				'status' => 'failed',
				'code'   => 'better_importer.attachment.fetch_failed',
				'error'  => $upload->get_error_message(),
			);
		}

		$deferred                  = array();
		$postarr                   = $this->map_post_authors_and_parents( $data, $deferred, $job, $remapper );
		$postarr['post_status']    = 'inherit';
		$postarr['post_mime_type'] = $upload['type'];
		if ( $job->get_option( 'update_attachment_guids', false ) ) {
			$postarr['guid'] = $upload['url'];
		}

		$attach_id = wp_insert_attachment( wp_slash( $postarr ), $upload['file'], 0, true );
		if ( is_wp_error( $attach_id ) ) {
			return array(
				'status' => 'failed',
				'code'   => 'better_importer.attachment.insert_failed',
				'error'  => $attach_id->get_error_message(),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		if ( ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attach_id, $metadata );
		}

		update_post_meta( $attach_id, '_better_import_job_id', $job->id );
		update_post_meta( $attach_id, '_better_import_original_id', $original_id );
		add_post_meta( $attach_id, '_better_import_source_url', esc_url_raw( $remote_url ) );

		foreach ( $deferred as $marker ) {
			add_post_meta( $attach_id, $marker['key'], $marker['value'] );
		}

		$remapper->set( 'post', $original_id, $attach_id );

		return array(
			'status'        => 'created',
			'new_entity_id' => (int) $attach_id,
		);
	}

	/**
	 * Download a remote file into the uploads directory.
	 *
	 * @since 1.6.0
	 *
	 * @param string $url Remote file URL.
	 *
	 * @return array<string, string>|WP_Error File data (file, url, type) or error.
	 */
	protected function fetch_remote_file( $url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		/**
		 * Filter the remote attachment download timeout in seconds.
		 *
		 * @since 1.6.0
		 *
		 * @param int    $timeout Timeout in seconds.
		 * @param string $url     Remote file URL.
		 */
		$timeout = (int) apply_filters( 'better_importer.attachment.download_timeout', 30, $url );

		$tmp = download_url( $url, $timeout );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name ) {
			$name = 'import-' . md5( $url );
		}

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		$file = wp_handle_sideload( $file_array, array( 'test_form' => false ) );

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		if ( isset( $file['error'] ) ) {
			return new WP_Error( 'better_importer.attachment.sideload_failed', $file['error'] );
		}

		return array(
			'file' => $file['file'],
			'url'  => $file['url'],
			'type' => $file['type'],
		);
	}

	/**
	 * Source site base URL for resolving relative media paths.
	 *
	 * @since 1.6.0
	 *
	 * @param Better_Import_Job $job Import job.
	 *
	 * @return string
	 */
	protected function source_base_url( Better_Import_Job $job ) {
		$preflight = is_array( $job->preflight_data ) ? $job->preflight_data : array();

		if ( ! empty( $preflight['siteurl'] ) ) {
			return (string) $preflight['siteurl'];
		}
		if ( ! empty( $preflight['home'] ) ) {
			return (string) $preflight['home'];
		}

		return '';
	}

	/**
	 * Build (and cache for this request) the source→local media URL map.
	 *
	 * @since 1.6.0
	 *
	 * @param Better_Import_Job $job Import job.
	 *
	 * @return array<string, string>
	 */
	protected function content_url_map( Better_Import_Job $job ) {
		if ( null !== $this->url_map ) {
			return $this->url_map;
		}

		global $wpdb;
		$this->url_map = array();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS post_id, pm.meta_value AS source_url
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->postmeta} j
					ON j.post_id = pm.post_id AND j.meta_key = '_better_import_job_id' AND j.meta_value = %d
				WHERE pm.meta_key = '_better_import_source_url'",
				$job->id
			)
		);

		foreach ( $rows as $row ) {
			$new_url = wp_get_attachment_url( (int) $row->post_id );
			if ( ! $new_url ) {
				continue;
			}

			$src = (string) $row->source_url;
			$this->url_map[ $src ] = $new_url;

			// Map the opposite scheme too so http/https references both resolve.
			if ( 0 === strpos( $src, 'https://' ) ) {
				$this->url_map[ 'http://' . substr( $src, 8 ) ] = $new_url;
			} elseif ( 0 === strpos( $src, 'http://' ) ) {
				$this->url_map[ 'https://' . substr( $src, 7 ) ] = $new_url;
			}
		}

		return $this->url_map;
	}

	/**
	 * Import a chunk of post meta rows.
	 *
	 * In the default `bulk` mode the rows are written with a single INSERT for
	 * speed. In `hooked` mode each row goes through `add_post_meta()` so plugin
	 * hooks (`added_post_meta`, meta index builders) run — slower, but required
	 * for stacks that depend on those hooks.
	 *
	 * @since 1.1.0
	 *
	 * @param int                         $post_id    Post ID.
	 * @param array<int, array>           $meta_rows  Meta rows.
	 * @param array<string, mixed>        $post_data  Post data for filters.
	 * @param string                      $write_mode `bulk` (default) or `hooked`.
	 * @param Better_Import_Remapper|null $remapper   Remapper for value remapping.
	 *
	 * @return true|WP_Error
	 */
	public function import_meta_chunk( $post_id, array $meta_rows, array $post_data, $write_mode = 'bulk', Better_Import_Remapper $remapper = null ) {
		global $wpdb;

		if ( 'hooked' === $write_mode ) {
			return $this->import_meta_chunk_hooked( $post_id, $meta_rows, $post_data, $remapper );
		}

		$unique_keys   = $this->unique_meta_keys();
		$is_attachment = isset( $post_data['post_type'] ) && 'attachment' === $post_data['post_type'];

		$rows        = array();
		$unique_rows = array();

		foreach ( $meta_rows as $meta_item ) {
			if ( empty( $meta_item['key'] ) ) {
				continue;
			}

			$key   = $meta_item['key'];
			$value = isset( $meta_item['value'] ) ? $meta_item['value'] : '';

			// The correct file path and metadata are generated during attachment
			// import; never overwrite them with the source file's stale values.
			if ( $is_attachment && ( '_wp_attached_file' === $key || '_wp_attachment_metadata' === $key ) ) {
				continue;
			}

			if ( ! apply_filters( 'better_importer.pre_process.post_meta', true, $key, $value, $post_id, $post_data ) ) {
				continue;
			}

			if ( isset( $unique_keys[ $key ] ) ) {
				$unique_rows[ (string) $key ] = maybe_serialize( $value );
				continue;
			}

			$rows[] = array(
				'key'   => (string) $key,
				'value' => maybe_serialize( $value ),
			);
		}

		foreach ( $unique_rows as $unique_key => $serialized_value ) {
			delete_post_meta( $post_id, $unique_key );
			$rows[] = array(
				'key'   => $unique_key,
				'value' => $serialized_value,
			);
		}

		if ( ! empty( $rows ) ) {
			$placeholders = array();
			$values       = array();

			foreach ( $rows as $row ) {
				$placeholders[] = '(%d, %s, %s)';
				$values[]       = absint( $post_id );
				$values[]       = $row['key'];
				$values[]       = $row['value'];
			}

			$sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $placeholders );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are generated above.
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
			if ( false === $result ) {
				return new WP_Error(
					'better_importer.meta.insert_failed',
					__( 'Could not import post meta rows.', 'better-wordpress-importer' )
				);
			}
		}

		update_post_meta( $post_id, '_better_import_meta_cursor', time() );

		return true;
	}

	/**
	 * Meta keys that must hold a single value.
	 *
	 * These are replaced rather than appended so re-importing a post never
	 * produces duplicate rows (e.g. multiple `_thumbnail_id` values).
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, int> Keys flipped for O(1) lookup.
	 */
	protected function unique_meta_keys() {
		/**
		 * Filter the list of single-value meta keys.
		 *
		 * @since 1.6.0
		 *
		 * @param array<int, string> $keys Single-value meta keys.
		 */
		$keys = (array) apply_filters(
			'better_importer.meta.unique_keys',
			array( '_thumbnail_id', '_wp_page_template', '_edit_last', '_edit_lock', '_wp_attached_file', '_wp_attachment_metadata' )
		);

		return array_flip( $keys );
	}

	/**
	 * Import a chunk of post meta through WordPress hooks.
	 *
	 * Slower than the bulk path but fires `add_post_meta` hooks and remaps the
	 * `_edit_last` editor to the local user, matching core importer behaviour.
	 *
	 * @since 1.6.0
	 *
	 * @param int                         $post_id   Post ID.
	 * @param array<int, array>           $meta_rows Meta rows.
	 * @param array<string, mixed>        $post_data Post data for filters.
	 * @param Better_Import_Remapper|null $remapper  Remapper for value remapping.
	 *
	 * @return true
	 */
	protected function import_meta_chunk_hooked( $post_id, array $meta_rows, array $post_data, Better_Import_Remapper $remapper = null ) {
		$unique_keys   = $this->unique_meta_keys();
		$is_attachment = isset( $post_data['post_type'] ) && 'attachment' === $post_data['post_type'];

		foreach ( $meta_rows as $meta_item ) {
			if ( empty( $meta_item['key'] ) ) {
				continue;
			}

			$key   = $meta_item['key'];
			$value = isset( $meta_item['value'] ) ? $meta_item['value'] : '';

			// Never overwrite the attachment file path/metadata generated during
			// import with the source file's stale values.
			if ( $is_attachment && ( '_wp_attached_file' === $key || '_wp_attachment_metadata' === $key ) ) {
				continue;
			}

			if ( ! apply_filters( 'better_importer.pre_process.post_meta', true, $key, $value, $post_id, $post_data ) ) {
				continue;
			}

			$value = maybe_unserialize( $value );

			// Remap the last editor to the local user; skip when unknown.
			if ( '_edit_last' === $key && $remapper instanceof Better_Import_Remapper ) {
				$mapped = $remapper->get( 'user', (int) $value );
				if ( ! $mapped ) {
					continue;
				}
				$value = $mapped;
			}

			if ( isset( $unique_keys[ $key ] ) ) {
				update_post_meta( $post_id, wp_slash( $key ), wp_slash( $value ) );
			} else {
				add_post_meta( $post_id, wp_slash( $key ), wp_slash( $value ) );
			}
		}

		update_post_meta( $post_id, '_better_import_meta_cursor', time() );

		return true;
	}

	/**
	 * Import a chunk of comments for a post.
	 *
	 * @since 1.1.0
	 *
	 * @param int                    $post_id Post ID.
	 * @param array<int, array>      $comments Comment rows.
	 * @param Better_Import_Remapper $remapper ID remapper.
	 *
	 * @return true
	 */
	public function import_comments_chunk( $post_id, array $comments, Better_Import_Remapper $remapper ) {
		foreach ( $comments as $comment_data ) {
			$original_id = isset( $comment_data['comment_id'] ) ? (int) $comment_data['comment_id'] : 0;
			if ( $remapper->has( 'comment', $original_id ) ) {
				continue;
			}

			// Remap the parent comment and author to local IDs. Parents earlier
			// in the same post resolve immediately; forward references fall back
			// to a top-level comment rather than pointing at a stale source ID.
			$parent_original = isset( $comment_data['comment_parent'] ) ? (int) $comment_data['comment_parent'] : 0;
			$parent_new      = $parent_original ? (int) $remapper->get( 'comment', $parent_original ) : 0;

			$author_original = isset( $comment_data['comment_user_id'] ) ? (int) $comment_data['comment_user_id'] : 0;
			$author_new      = $author_original ? (int) $remapper->get( 'user', $author_original ) : 0;

			$commentarr = array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => isset( $comment_data['comment_author'] ) ? $comment_data['comment_author'] : '',
				'comment_author_email' => isset( $comment_data['comment_author_email'] ) ? $comment_data['comment_author_email'] : '',
				'comment_author_url'   => isset( $comment_data['comment_author_url'] ) ? $comment_data['comment_author_url'] : '',
				'comment_author_IP'    => isset( $comment_data['comment_author_IP'] ) ? $comment_data['comment_author_IP'] : '',
				'comment_date'         => isset( $comment_data['comment_date'] ) ? $comment_data['comment_date'] : current_time( 'mysql' ),
				'comment_date_gmt'     => isset( $comment_data['comment_date_gmt'] ) ? $comment_data['comment_date_gmt'] : current_time( 'mysql', 1 ),
				'comment_content'      => isset( $comment_data['comment_content'] ) ? $comment_data['comment_content'] : '',
				'comment_approved'     => isset( $comment_data['comment_approved'] ) ? $comment_data['comment_approved'] : 1,
				'comment_type'         => isset( $comment_data['comment_type'] ) ? $comment_data['comment_type'] : '',
				'comment_parent'       => $parent_new,
				'user_id'              => $author_new,
			);

			$comment_id = wp_insert_comment( wp_slash( $commentarr ) );
			if ( $comment_id ) {
				$remapper->set( 'comment', $original_id, $comment_id );
			}
		}

		return true;
	}

	/**
	 * Assign taxonomy terms to a post.
	 *
	 * @since 1.1.0
	 *
	 * @param int               $post_id Post ID.
	 * @param array<int, array> $terms   Term rows from payload.
	 *
	 * @return true|WP_Error
	 */
	public function assign_terms( $post_id, array $terms ) {
		$grouped = array();
		$missing = $this->get_missing_term_taxonomies( $terms );

		foreach ( $terms as $term ) {
			$taxonomy = isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'category';
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$name = isset( $term['slug'] ) ? $term['slug'] : ( isset( $term['name'] ) ? $term['name'] : '' );
			if ( '' === $name ) {
				continue;
			}

			if ( ! isset( $grouped[ $taxonomy ] ) ) {
				$grouped[ $taxonomy ] = array();
			}
			$grouped[ $taxonomy ][] = $name;
		}

		if ( ! empty( $missing ) ) {
			update_post_meta( $post_id, '_better_import_skipped_taxonomies', $missing );
		}

		foreach ( $grouped as $taxonomy => $names ) {
			wp_set_post_terms( $post_id, array_values( array_unique( $names ) ), $taxonomy );
		}

		return true;
	}

	/**
	 * Find term taxonomies that are missing on this site.
	 *
	 * @since 1.5.0
	 *
	 * @param array<int, array> $terms Term rows from payload.
	 *
	 * @return array<int, string> Missing taxonomy slugs.
	 */
	public function get_missing_term_taxonomies( array $terms ) {
		$missing = array();

		foreach ( $terms as $term ) {
			$taxonomy = isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'category';
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$missing[] = $taxonomy;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Resolve a chunk of deferred post relationships (parent, author).
	 *
	 * When a post is created before its parent or author exists, the source ID
	 * is stored as a `_better_import_parent` / `_better_import_user_slug` marker
	 * meta. Once the whole file is imported this pass resolves those markers to
	 * local IDs. Markers are always removed once handled — even when the target
	 * genuinely does not exist — so the remapping phase is guaranteed to finish.
	 *
	 * Updates are written directly to the posts table to avoid running the full
	 * `wp_update_post()` hook stack across thousands of rows; caches are cleared
	 * per affected post.
	 *
	 * @since 1.6.0
	 *
	 * @param Better_Import_Job      $job        Import job.
	 * @param Better_Import_Remapper $remapper   ID remapper.
	 * @param int                    $chunk_size Rows to resolve per marker type.
	 *
	 * @return int Number of markers handled in this call.
	 */
	public function process_remap_chunk( Better_Import_Job $job, Better_Import_Remapper $remapper, $chunk_size = 100 ) {
		global $wpdb;

		$chunk_size = max( 1, absint( $chunk_size ) );
		$handled    = 0;

		$parent_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS post_id, pm.meta_value AS old_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->postmeta} j
					ON j.post_id = pm.post_id AND j.meta_key = '_better_import_job_id' AND j.meta_value = %d
				WHERE pm.meta_key = '_better_import_parent'
				LIMIT %d",
				$job->id,
				$chunk_size
			)
		);

		foreach ( $parent_rows as $row ) {
			$post_id    = (int) $row->post_id;
			$new_parent = $remapper->get( 'post', $row->old_value );
			if ( $new_parent ) {
				$wpdb->update( $wpdb->posts, array( 'post_parent' => (int) $new_parent ), array( 'ID' => $post_id ), array( '%d' ), array( '%d' ) );
				clean_post_cache( $post_id );
			}
			delete_post_meta( $post_id, '_better_import_parent' );
			++$handled;
		}

		$author_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS post_id, pm.meta_value AS old_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->postmeta} j
					ON j.post_id = pm.post_id AND j.meta_key = '_better_import_job_id' AND j.meta_value = %d
				WHERE pm.meta_key = '_better_import_user_slug'
				LIMIT %d",
				$job->id,
				$chunk_size
			)
		);

		foreach ( $author_rows as $row ) {
			$post_id    = (int) $row->post_id;
			$new_author = $remapper->get( 'user_slug', $row->old_value );
			if ( $new_author ) {
				$wpdb->update( $wpdb->posts, array( 'post_author' => (int) $new_author ), array( 'ID' => $post_id ), array( '%d' ), array( '%d' ) );
				clean_post_cache( $post_id );
			}
			delete_post_meta( $post_id, '_better_import_user_slug' );
			++$handled;
		}

		$term_parent_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id AS term_id, meta_value AS parent_slug
				FROM {$wpdb->termmeta}
				WHERE meta_key = '_better_import_term_parent'
				LIMIT %d",
				$chunk_size
			)
		);

		foreach ( $term_parent_rows as $row ) {
			$term_id = (int) $row->term_id;
			$term    = get_term( $term_id );
			if ( $term instanceof WP_Term ) {
				$mapped_parent = $remapper->get( 'term', sha1( $term->taxonomy . ':' . $row->parent_slug ) );
				if ( $mapped_parent ) {
					wp_update_term( $term_id, $term->taxonomy, array( 'parent' => (int) $mapped_parent ) );
				}
			}
			delete_term_meta( $term_id, '_better_import_term_parent' );
			++$handled;
		}

		$menu_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->postmeta} j
					ON j.post_id = pm.post_id AND j.meta_key = '_better_import_job_id' AND j.meta_value = %d
				WHERE pm.meta_key = '_better_import_menu_item'
				LIMIT %d",
				$job->id,
				$chunk_size
			)
		);

		foreach ( $menu_rows as $row ) {
			$post_id   = (int) $row->post_id;
			$item_type = get_post_meta( $post_id, '_menu_item_type', true );
			$object_id = (int) get_post_meta( $post_id, '_menu_item_object_id', true );

			if ( $object_id ) {
				$mapped_object = null;
				if ( 'taxonomy' === $item_type ) {
					$mapped_object = $remapper->get( 'term_id', $object_id );
				} elseif ( 'post_type' === $item_type || 'post_type_archive' === $item_type ) {
					$mapped_object = $remapper->get( 'post', $object_id );
				}
				if ( $mapped_object ) {
					update_post_meta( $post_id, '_menu_item_object_id', (int) $mapped_object );
				}
			}

			$parent_source = (int) get_post_meta( $post_id, '_menu_item_menu_item_parent', true );
			if ( $parent_source ) {
				$mapped_parent = $remapper->get( 'post', $parent_source );
				if ( $mapped_parent ) {
					update_post_meta( $post_id, '_menu_item_menu_item_parent', (int) $mapped_parent );
				}
			}

			delete_post_meta( $post_id, '_better_import_menu_item' );
			++$handled;
		}

		$rewrite_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->postmeta} j
					ON j.post_id = pm.post_id AND j.meta_key = '_better_import_job_id' AND j.meta_value = %d
				WHERE pm.meta_key = '_better_import_rewrite_pending'
				LIMIT %d",
				$job->id,
				$chunk_size
			)
		);

		if ( ! empty( $rewrite_rows ) ) {
			$url_map = $this->content_url_map( $job );

			foreach ( $rewrite_rows as $row ) {
				$post_id = (int) $row->post_id;

				if ( ! empty( $url_map ) ) {
					$post = get_post( $post_id );
					if ( $post instanceof WP_Post ) {
						$new_content = strtr( $post->post_content, $url_map );
						if ( $new_content !== $post->post_content ) {
							$wpdb->update( $wpdb->posts, array( 'post_content' => $new_content ), array( 'ID' => $post_id ), array( '%s' ), array( '%d' ) );
							clean_post_cache( $post_id );
						}
					}
				}

				delete_post_meta( $post_id, '_better_import_rewrite_pending' );
				++$handled;
			}
		}

		return $handled;
	}

	/**
	 * Map post author and parent fields using remapper state.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed>   $data     Post data.
	 * @param array<int, array>      $deferred Deferred relationship markers (by reference).
	 * @param Better_Import_Job      $job      Import job.
	 * @param Better_Import_Remapper $remapper ID remapper.
	 *
	 * @return array<string, mixed>
	 */
	protected function map_post_authors_and_parents( array $data, array &$deferred, Better_Import_Job $job, Better_Import_Remapper $remapper ) {
		$postarr = array(
			'post_title'   => isset( $data['post_title'] ) ? $data['post_title'] : '',
			'post_content' => isset( $data['post_content'] ) ? $data['post_content'] : '',
			'post_excerpt' => isset( $data['post_excerpt'] ) ? $data['post_excerpt'] : '',
			'post_status'  => isset( $data['post_status'] ) ? $data['post_status'] : 'publish',
			'post_type'    => $data['post_type'],
			'post_name'    => isset( $data['post_name'] ) ? $data['post_name'] : '',
			'menu_order'   => isset( $data['menu_order'] ) ? (int) $data['menu_order'] : 0,
			'post_password'=> isset( $data['post_password'] ) ? $data['post_password'] : '',
			'comment_status' => isset( $data['comment_status'] ) ? $data['comment_status'] : 'open',
			'ping_status'    => isset( $data['ping_status'] ) ? $data['ping_status'] : 'open',
		);

		if ( ! empty( $data['post_date'] ) ) {
			$postarr['post_date'] = $data['post_date'];
		}
		if ( ! empty( $data['post_date_gmt'] ) ) {
			$postarr['post_date_gmt'] = $data['post_date_gmt'];
		}
		if ( ! empty( $data['guid'] ) ) {
			$postarr['guid'] = $data['guid'];
		}

		$parent_id = isset( $data['post_parent'] ) ? (int) $data['post_parent'] : 0;
		if ( $parent_id && $remapper->has( 'post', $parent_id ) ) {
			$postarr['post_parent'] = $remapper->get( 'post', $parent_id );
		} elseif ( $parent_id ) {
			$deferred[] = array(
				'key'   => '_better_import_parent',
				'value' => $parent_id,
			);
			$postarr['post_parent'] = 0;
		}

		$author_slug = isset( $data['post_author'] ) ? sanitize_user( $data['post_author'], true ) : '';
		if ( $author_slug && $remapper->has( 'user_slug', $author_slug ) ) {
			$postarr['post_author'] = $remapper->get( 'user_slug', $author_slug );
		} elseif ( $author_slug ) {
			$deferred[] = array(
				'key'   => '_better_import_user_slug',
				'value' => $author_slug,
			);
			$postarr['post_author'] = isset( $job->options['default_author'] ) ? (int) $job->options['default_author'] : get_current_user_id();
		} else {
			$postarr['post_author'] = isset( $job->options['default_author'] ) ? (int) $job->options['default_author'] : get_current_user_id();
		}

		return $postarr;
	}

	/**
	 * Get a manually configured destination user mapping.
	 *
	 * @since 1.5.0
	 *
	 * @param Better_Import_Job $job           Import job.
	 * @param int               $original_id   Source user ID.
	 * @param string            $original_slug Source user login.
	 *
	 * @return int Destination user ID, or zero when unmapped.
	 */
	protected function get_configured_user_mapping( Better_Import_Job $job, $original_id, $original_slug ) {
		$mapping = isset( $job->options['user_id_map'] ) && is_array( $job->options['user_id_map'] ) ? $job->options['user_id_map'] : array();
		$keys    = array(
			(string) $original_id,
			(string) $original_slug,
			sanitize_user( (string) $original_slug, true ),
		);

		foreach ( $keys as $key ) {
			if ( '' !== $key && ! empty( $mapping[ $key ] ) ) {
				$user_id = absint( $mapping[ $key ] );
				if ( get_user_by( 'ID', $user_id ) ) {
					return $user_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Find an existing post by GUID or title/date.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $data Post data.
	 *
	 * @return int Post ID or 0.
	 */
	protected function post_exists( array $data ) {
		global $wpdb;

		if ( ! empty( $data['guid'] ) ) {
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE guid = %s LIMIT 1",
					$data['guid']
				)
			);
			if ( $post_id ) {
				return (int) $post_id;
			}
		}

		if ( ! empty( $data['post_title'] ) && ! empty( $data['post_date'] ) ) {
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_date = %s LIMIT 1",
					$data['post_title'],
					$data['post_date']
				)
			);
			if ( $post_id ) {
				return (int) $post_id;
			}
		}

		return 0;
	}

	/**
	 * Determine whether an existing post should be resumed.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Existing post ID.
	 * @param int $job_id  Current job ID.
	 * @param int $expected_meta_count Expected meta count.
	 *
	 * @return bool
	 */
	protected function is_resumable_post( $post_id, $job_id, $expected_meta_count ) {
		$marker_job = (int) get_post_meta( $post_id, '_better_import_job_id', true );
		if ( $marker_job === $job_id ) {
			return true;
		}

		$actual_meta = (int) get_post_meta( $post_id, '_better_import_meta_cursor', true );
		if ( $actual_meta > 0 ) {
			return true;
		}

		if ( $expected_meta_count > 0 ) {
			$count = (int) get_post_meta( $post_id, '_better_import_meta_total', true );
			if ( $count > 0 && $count < $expected_meta_count ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find an existing term.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $data Term data.
	 *
	 * @return int Term ID or 0.
	 */
	protected function term_exists( array $data ) {
		$taxonomy = isset( $data['taxonomy'] ) ? $data['taxonomy'] : '';
		$slug     = isset( $data['slug'] ) ? $data['slug'] : '';

		if ( empty( $taxonomy ) || empty( $slug ) ) {
			return 0;
		}

		$term = term_exists( $slug, $taxonomy );
		if ( is_array( $term ) ) {
			return (int) $term['term_id'];
		}

		return 0;
	}
}
