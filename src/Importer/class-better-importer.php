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

		if ( $remapper->has( 'user', $original_id ) || $remapper->has( 'user_slug', $original_slug ) ) {
			return array( 'status' => 'skipped' );
		}

		$login = $original_slug;
		if ( isset( $job->options['user_slug_override'][ $login ] ) ) {
			$login = $job->options['user_slug_override'][ $login ];
		}

		$userdata = array(
			'user_login' => sanitize_user( $login, true ),
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

		$mapping_key = sha1( $taxonomy . ':' . $slug );
		$existing    = $this->term_exists( $data );

		if ( $existing ) {
			$remapper->set( 'term', $mapping_key, $existing );
			$remapper->set( 'term_id', $original_id, $existing );
			return array(
				'status'        => 'skipped',
				'new_entity_id' => $existing,
			);
		}

		if ( $remapper->has( 'term', $mapping_key ) ) {
			return array(
				'status'        => 'skipped',
				'new_entity_id' => $remapper->get( 'term', $mapping_key ),
			);
		}

		$termdata = array();
		if ( ! empty( $data['slug'] ) ) {
			$termdata['slug'] = $data['slug'];
		}
		if ( isset( $data['description'] ) ) {
			$termdata['description'] = $data['description'];
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

		if ( ! get_post_type_object( $data['post_type'] ) ) {
			return array(
				'status' => 'failed',
				'error'  => sprintf(
					/* translators: %s: post type slug */
					__( 'Invalid post type: %s', 'better-wordpress-importer' ),
					$data['post_type']
				),
			);
		}

		$existing = $this->post_exists( $data );
		if ( $existing && ! $this->is_resumable_post( $existing, $job->id, count( $meta ) ) ) {
			$remapper->set( 'post', $original_id, $existing );
			return array(
				'status'        => 'skipped',
				'new_entity_id' => $existing,
			);
		}

		if ( $existing ) {
			$post_id = $existing;
		} else {
			$postarr = $this->map_post_authors_and_parents( $data, $meta, $job, $remapper );
			$post_id = wp_insert_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $post_id ) ) {
				return array(
					'status' => 'failed',
					'error'  => $post_id->get_error_message(),
				);
			}

			update_post_meta( $post_id, '_better_import_job_id', $job->id );
			update_post_meta( $post_id, '_better_import_original_id', $original_id );
		}

		$remapper->set( 'post', $original_id, $post_id );

		return array(
			'status'        => 'created',
			'new_entity_id' => (int) $post_id,
		);
	}

	/**
	 * Import a chunk of post meta rows.
	 *
	 * @since 1.1.0
	 *
	 * @param int                    $post_id Post ID.
	 * @param array<int, array>      $meta_rows Meta rows.
	 * @param array<string, mixed>   $post_data Post data for filters.
	 *
	 * @return true|WP_Error
	 */
	public function import_meta_chunk( $post_id, array $meta_rows, array $post_data ) {
		foreach ( $meta_rows as $meta_item ) {
			if ( empty( $meta_item['key'] ) ) {
				continue;
			}

			$key   = $meta_item['key'];
			$value = isset( $meta_item['value'] ) ? $meta_item['value'] : '';

			if ( ! apply_filters( 'better_importer.pre_process.post_meta', true, $key, $value, $post_id, $post_data ) ) {
				continue;
			}

			update_post_meta( $post_id, wp_slash( $key ), wp_slash( $value ) );
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
				'comment_parent'       => 0,
				'user_id'              => isset( $comment_data['comment_user_id'] ) ? (int) $comment_data['comment_user_id'] : 0,
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
	 * @return true
	 */
	public function assign_terms( $post_id, array $terms ) {
		$grouped = array();

		foreach ( $terms as $term ) {
			$taxonomy = isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'category';
			if ( ! isset( $grouped[ $taxonomy ] ) ) {
				$grouped[ $taxonomy ] = array();
			}
			$grouped[ $taxonomy ][] = isset( $term['slug'] ) ? $term['slug'] : $term['name'];
		}

		foreach ( $grouped as $taxonomy => $names ) {
			wp_set_post_terms( $post_id, $names, $taxonomy );
		}

		return true;
	}

	/**
	 * Map post author and parent fields using remapper state.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed>   $data     Post data.
	 * @param array<int, array>      $meta     Meta rows (by reference).
	 * @param Better_Import_Job      $job      Import job.
	 * @param Better_Import_Remapper $remapper ID remapper.
	 *
	 * @return array<string, mixed>
	 */
	protected function map_post_authors_and_parents( array $data, array &$meta, Better_Import_Job $job, Better_Import_Remapper $remapper ) {
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
			$meta[] = array(
				'key'   => '_better_import_parent',
				'value' => $parent_id,
			);
			$postarr['post_parent'] = 0;
		}

		$author_slug = isset( $data['post_author'] ) ? sanitize_user( $data['post_author'], true ) : '';
		if ( $author_slug && $remapper->has( 'user_slug', $author_slug ) ) {
			$postarr['post_author'] = $remapper->get( 'user_slug', $author_slug );
		} elseif ( $author_slug ) {
			$meta[] = array(
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
