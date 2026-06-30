<?php
/**
 * Import job model — represents one resumable import session.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import job model.
 *
 * @since 3.0.0
 */
class WXR_Import_Job {

	/**
	 * Job ID.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $id = 0;

	/**
	 * Job status.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $status = 'pending';

	/**
	 * Absolute path to the WXR file.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $file_path = '';

	/**
	 * WordPress attachment ID for the WXR file.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $attachment_id = 0;

	/**
	 * Current position in the item manifest (zero-based).
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $xml_cursor_item = 0;

	/**
	 * Total posts expected (non-attachment items).
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $total_posts = 0;

	/**
	 * Total comments expected.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $total_comments = 0;

	/**
	 * Total terms expected.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $total_terms = 0;

	/**
	 * Total users expected.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $total_users = 0;

	/**
	 * Total media/attachment items expected.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $total_media = 0;

	/**
	 * Processed post count.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $processed_posts = 0;

	/**
	 * Processed comment count.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $processed_comments = 0;

	/**
	 * Processed term count.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $processed_terms = 0;

	/**
	 * Processed user count.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $processed_users = 0;

	/**
	 * Processed media count.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $processed_media = 0;

	/**
	 * Failed entity count.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $failed_items = 0;

	/**
	 * Skipped entity count.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $skipped_items = 0;

	/**
	 * Import options and persisted mapping state.
	 *
	 * @since 3.0.0
	 * @var array<string, mixed>
	 */
	public $options = array();

	/**
	 * Preflight scan data.
	 *
	 * @since 3.0.0
	 * @var array<string, mixed>
	 */
	public $preflight_data = array();

	/**
	 * Entity manifest for batch processing.
	 *
	 * @since 3.0.0
	 * @var array<int, array<string, mixed>>
	 */
	public $item_manifest = array();

	/**
	 * User who started the job.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $user_id = 0;

	/**
	 * Created timestamp (MySQL datetime).
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $created_at = '';

	/**
	 * Last updated timestamp (MySQL datetime).
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $updated_at = '';

	/**
	 * Create a new import job from a WXR file.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $attachment_id WordPress attachment ID of the WXR file.
	 * @param string $file_path     Absolute filesystem path to the WXR file.
	 * @param array  $options       Import options (author mapping, fetch_attachments, etc.).
	 *
	 * @return WXR_Import_Job|WP_Error Job instance on success, error otherwise.
	 */
	public static function create( $attachment_id, $file_path, array $options = array() ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'wxr_importer.job.file_not_readable',
				__( 'The import file is not readable.', 'wordpress-importer' )
			);
		}

		$importer = new WXR_Importer( $options );
		$info     = $importer->get_preliminary_information( $file_path );

		if ( is_wp_error( $info ) ) {
			return $info;
		}

		$manifest = $importer->build_import_manifest( $file_path );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$now = current_time( 'mysql', true );

		$job = new self();
		$job->status           = 'pending';
		$job->file_path        = $file_path;
		$job->attachment_id    = absint( $attachment_id );
		$job->xml_cursor_item  = 0;
		$job->total_posts      = (int) $info->post_count;
		$job->total_comments   = (int) $info->comment_count;
		$job->total_terms      = (int) $info->term_count;
		$job->total_users      = count( $info->users );
		$job->total_media      = (int) $info->media_count;
		$job->options          = wp_parse_args(
			$options,
			array(
				'fetch_attachments' => false,
				'is_local_file'     => false,
				'batch_size'        => 10,
				'mapping_state'     => array(),
				'log'               => array(),
				'remap_cursor'      => array(
					'posts'    => 0,
					'comments' => 0,
					'urls'     => 0,
					'featured' => 0,
				),
			)
		);
		$job->preflight_data   = array(
			'title'                 => $info->title,
			'version'               => $info->version,
			'generator'             => $info->generator,
			'home'                  => $info->home,
			'siteurl'               => $info->siteurl,
			'manifest_entity_total' => count( $manifest ),
		);

		// Byte offsets for items are useful for seeks but bloat DB rows on large exports.
		if ( count( $manifest ) <= 500 ) {
			$job->preflight_data['item_positions'] = $info->item_positions;
		}
		$job->item_manifest    = $manifest;
		$job->user_id          = get_current_user_id();
		$job->created_at       = $now;
		$job->updated_at       = $now;

		$repository = new WXR_Import_Job_Repository();
		$saved      = $repository->save( $job );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$job->id = (int) $saved;

		/**
		 * Fires after a new import job is created.
		 *
		 * @since 3.0.0
		 *
		 * @param WXR_Import_Job $job Import job instance.
		 */
		do_action( 'wxr_importer.job.created', $job );

		return $job;
	}

	/**
	 * Load a job by ID.
	 *
	 * @since 3.0.0
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return WXR_Import_Job|WP_Error
	 */
	public static function get( $job_id ) {
		$repository = new WXR_Import_Job_Repository();
		return $repository->get( $job_id );
	}

	/**
	 * Total entities to import.
	 *
	 * @since 3.0.0
	 *
	 * @return int
	 */
	public function total_items() {
		return $this->total_posts + $this->total_comments + $this->total_terms + $this->total_users + $this->total_media;
	}

	/**
	 * Total importable XML entities in the WXR file (authors, terms, items).
	 *
	 * Persisted separately because the full manifest array may not round-trip
	 * through the database on large exports.
	 *
	 * @since 3.0.6
	 *
	 * @return int
	 */
	public function manifest_entity_total() {
		if ( ! empty( $this->preflight_data['manifest_entity_total'] ) ) {
			return (int) $this->preflight_data['manifest_entity_total'];
		}

		$manifest_count = count( $this->item_manifest );
		if ( $manifest_count > 0 ) {
			return $manifest_count;
		}

		return $this->total_items();
	}

	/**
	 * Whether all XML entities have been processed.
	 *
	 * @since 3.0.6
	 *
	 * @return bool
	 */
	public function is_content_import_complete() {
		$total = $this->manifest_entity_total();
		if ( $total <= 0 ) {
			return false;
		}

		return $this->xml_cursor_item >= $total;
	}

	/**
	 * Total entities successfully imported so far.
	 *
	 * @since 3.0.0
	 *
	 * @return int
	 */
	public function processed_items() {
		return $this->processed_posts + $this->processed_comments + $this->processed_terms + $this->processed_users + $this->processed_media;
	}

	/**
	 * Percent complete (0–100).
	 *
	 * @since 3.0.0
	 *
	 * @return int
	 */
	public function percent_complete() {
		$manifest_total = $this->manifest_entity_total();

		if ( $manifest_total > 0 && in_array( $this->status, array( 'processing', 'pending', 'scanning' ), true ) ) {
			$pct = ( $this->xml_cursor_item / $manifest_total ) * 90;
			if ( $this->xml_cursor_item > 0 && $pct < 1 ) {
				return 1;
			}
			return min( 90, (int) round( $pct ) );
		}

		if ( 'downloading_attachments' === $this->status ) {
			$pending = isset( $this->options['mapping_state']['pending_attachments'] )
				? count( $this->options['mapping_state']['pending_attachments'] )
				: 0;
			$total_media = max( 1, $this->total_media );
			$done        = max( 0, $total_media - $pending );
			return min( 98, 90 + (int) round( ( $done / $total_media ) * 8 ) );
		}

		if ( 'remapping' === $this->status ) {
			$cursor = isset( $this->options['remap_cursor'] ) ? $this->options['remap_cursor'] : array();
			$phase  = isset( $cursor['phase'] ) ? $cursor['phase'] : 'posts';
			$base   = 92;

			if ( 'featured' === $phase && ! empty( $cursor['featured'] ) ) {
				$featured_total = isset( $this->options['mapping_state']['featured_images'] )
					? count( $this->options['mapping_state']['featured_images'] )
					: 0;
				if ( $featured_total > 0 ) {
					return min( 99, $base + (int) round( ( (int) $cursor['featured'] / $featured_total ) * 7 ) );
				}
			}

			return $base;
		}

		if ( 'complete' === $this->status ) {
			return 100;
		}

		$total = $this->total_items();
		if ( $total <= 0 ) {
			return 0;
		}

		return min( 100, (int) round( ( $this->processed_items() / $total ) * 100 ) );
	}

	/**
	 * Whether the job is actively running.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public function is_running() {
		return in_array( $this->status, array( 'scanning', 'processing', 'remapping', 'downloading_attachments' ), true );
	}

	/**
	 * Whether the job has reached a terminal state.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public function is_terminal() {
		return in_array( $this->status, array( 'complete', 'failed', 'cancelled' ), true );
	}

	/**
	 * Transition job to a new status.
	 *
	 * @since 3.0.0
	 *
	 * @param string $status New status.
	 *
	 * @return bool|WP_Error
	 */
	public function set_status( $status ) {
		$allowed = array(
			'pending',
			'scanning',
			'processing',
			'remapping',
			'downloading_attachments',
			'complete',
			'failed',
			'cancelled',
			'paused',
		);

		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'wxr_importer.job.invalid_status', __( 'Invalid job status.', 'wordpress-importer' ) );
		}

		$this->status     = $status;
		$this->updated_at = current_time( 'mysql', true );

		$repository = new WXR_Import_Job_Repository();
		$result     = $repository->save( $this );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Append a log entry to the job options.
	 *
	 * @since 3.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 *
	 * @return void
	 */
	public function add_log( $level, $message ) {
		if ( ! isset( $this->options['log'] ) || ! is_array( $this->options['log'] ) ) {
			$this->options['log'] = array();
		}

		$this->options['log'][] = array(
			'time'    => gmdate( 'c' ),
			'level'   => sanitize_key( $level ),
			'message' => $message,
		);

		// Keep only the last 200 entries.
		if ( count( $this->options['log'] ) > 200 ) {
			$this->options['log'] = array_slice( $this->options['log'], -200 );
		}
	}

	/**
	 * Get recent log entries.
	 *
	 * @since 3.0.0
	 *
	 * @param int $limit Maximum entries to return.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_recent_log( $limit = 20 ) {
		$log = isset( $this->options['log'] ) && is_array( $this->options['log'] ) ? $this->options['log'] : array();
		return array_slice( $log, -absint( $limit ) );
	}

	/**
	 * Build a status array for API responses.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_status_array() {
		$item_repository = new WXR_Import_Item_Repository();

		$status = array(
			'id'                => $this->id,
			'status'            => $this->status,
			'percent'           => $this->percent_complete(),
			'xml_cursor_item'   => $this->xml_cursor_item,
			'manifest_total'    => $this->manifest_entity_total(),
			'batch_size'        => isset( $this->options['batch_size'] ) ? absint( $this->options['batch_size'] ) : 0,
			'totals'            => array(
				'posts'    => $this->total_posts,
				'comments' => $this->total_comments,
				'terms'    => $this->total_terms,
				'users'    => $this->total_users,
				'media'    => $this->total_media,
			),
			'processed'         => array(
				'posts'    => $this->processed_posts,
				'comments' => $this->processed_comments,
				'terms'    => $this->processed_terms,
				'users'    => $this->processed_users,
				'media'    => $this->processed_media,
			),
			'skipped'           => $item_repository->count_by_type_for_status( $this->id, 'skipped' ),
			'failed'            => $item_repository->count_by_type_for_status( $this->id, 'failed' ),
			'failed_items'      => $this->failed_items,
			'skipped_items'     => $this->skipped_items,
			'is_terminal'       => $this->is_terminal(),
			'recent_log'        => $this->get_recent_log( 10 ),
			'created_at'        => $this->created_at,
			'updated_at'        => $this->updated_at,
		);

		if ( $this->is_terminal() ) {
			$status['report'] = $this->get_final_report();
		}

		return $status;
	}

	/**
	 * Final import report summary.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_final_report() {
		return array(
			'title'   => isset( $this->preflight_data['title'] ) ? $this->preflight_data['title'] : '',
			'imported' => array(
				'posts'    => $this->processed_posts,
				'comments' => $this->processed_comments,
				'terms'    => $this->processed_terms,
				'users'    => $this->processed_users,
				'media'    => $this->processed_media,
			),
			'totals'  => array(
				'posts'    => $this->total_posts,
				'comments' => $this->total_comments,
				'terms'    => $this->total_terms,
				'users'    => $this->total_users,
				'media'    => $this->total_media,
			),
			'skipped' => $this->skipped_items,
			'failed'  => $this->failed_items,
		);
	}
}
