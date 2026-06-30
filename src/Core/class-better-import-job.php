<?php
/**
 * Import job model — represents one resumable import session.
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import job model.
 *
 * @since 1.0.0
 */
class Better_Import_Job {

	/**
	 * Job ID.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $id = 0;

	/**
	 * Job status.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $status = 'created';

	/**
	 * Current lifecycle phase.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $phase = '';

	/**
	 * Cursor within the current phase.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $phase_cursor = 0;

	/**
	 * Absolute path to the WXR file.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $file_path = '';

	/**
	 * WordPress attachment ID for the WXR file.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $attachment_id = 0;

	/**
	 * Expected post count from preflight.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $total_posts = 0;

	/**
	 * Expected comment count from preflight.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $total_comments = 0;

	/**
	 * Expected term count from preflight.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $total_terms = 0;

	/**
	 * Expected user count from preflight.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $total_users = 0;

	/**
	 * Expected media count from preflight.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $total_media = 0;

	/**
	 * Scanned post count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $scanned_posts = 0;

	/**
	 * Scanned comment count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $scanned_comments = 0;

	/**
	 * Scanned term count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $scanned_terms = 0;

	/**
	 * Scanned user count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $scanned_users = 0;

	/**
	 * Scanned media count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $scanned_media = 0;

	/**
	 * Imported post count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $imported_posts = 0;

	/**
	 * Imported comment count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $imported_comments = 0;

	/**
	 * Imported term count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $imported_terms = 0;

	/**
	 * Imported user count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $imported_users = 0;

	/**
	 * Imported media count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $imported_media = 0;

	/**
	 * Skipped post count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $skipped_posts = 0;

	/**
	 * Skipped comment count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $skipped_comments = 0;

	/**
	 * Skipped term count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $skipped_terms = 0;

	/**
	 * Skipped user count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $skipped_users = 0;

	/**
	 * Skipped media count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $skipped_media = 0;

	/**
	 * Failed entity count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $failed_items = 0;

	/**
	 * Import options.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	public $options = array();

	/**
	 * Preflight summary metadata.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	public $preflight_data = array();

	/**
	 * Compact entity manifest — always stored in full, no cap.
	 *
	 * @since 1.0.0
	 * @var array<int, array<string, string|int>>
	 */
	public $item_manifest = array();

	/**
	 * Serialized ID mapping state.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	public $mapping_state = array();

	/**
	 * User who started the import.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $user_id = 0;

	/**
	 * Import start timestamp (UTC).
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public $started_at = null;

	/**
	 * Import completion timestamp (UTC).
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public $completed_at = null;

	/**
	 * Created timestamp (UTC).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $created_at = '';

	/**
	 * Updated timestamp (UTC).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $updated_at = '';

	/**
	 * Create a new import job from a WXR file.
	 *
	 * Runs preflight, stores the full compact manifest, and seeds queue rows.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $attachment_id WordPress attachment ID of the WXR file.
	 * @param string $file_path     Absolute filesystem path to the WXR file.
	 * @param array  $options       Import options.
	 *
	 * @return Better_Import_Job|WP_Error
	 */
	public static function create( $attachment_id, $file_path, array $options = array() ) {
		$file_path = wp_normalize_path( $file_path );

		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return new WP_Error(
				'better_importer.job.invalid_file',
				__( 'The export file could not be read.', 'better-wordpress-importer' )
			);
		}

		$preflight = new Better_Preflight();
		$scan      = $preflight->scan( $file_path );
		if ( is_wp_error( $scan ) ) {
			return $scan;
		}

		$manifest       = $scan['manifest'];
		$preflight_data = $scan['preflight'];
		$counts         = isset( $preflight_data['counts'] ) ? $preflight_data['counts'] : array();

		$job                  = new self();
		$job->status          = 'scanning';
		$job->phase           = 'queueing';
		$job->file_path       = $file_path;
		$job->attachment_id   = absint( $attachment_id );
		$job->options         = $options;
		$job->preflight_data  = $preflight_data;
		$job->item_manifest   = $manifest;
		$job->user_id         = get_current_user_id();
		$job->total_users     = isset( $counts['users'] ) ? (int) $counts['users'] : 0;
		$job->total_terms     = isset( $counts['terms'] ) ? (int) $counts['terms'] : 0;
		$job->total_posts     = isset( $counts['posts'] ) ? (int) $counts['posts'] : 0;
		$job->total_media     = isset( $counts['attachments'] ) ? (int) $counts['attachments'] : 0;
		$job->scanned_users   = $job->total_users;
		$job->scanned_terms   = $job->total_terms;
		$job->scanned_posts   = $job->total_posts;
		$job->scanned_media   = $job->total_media;

		$repo = new Better_Import_Job_Repository();
		$saved = $repo->save( $job );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$queue_repo = new Better_Import_Queue_Repository();
		$seeded     = $queue_repo->seed_from_manifest( $job->id, $manifest );
		if ( is_wp_error( $seeded ) ) {
			return $seeded;
		}

		$queued_count = $queue_repo->count_for_job( $job->id );
		$expected     = count( $manifest );

		if ( $queued_count !== $expected ) {
			return new WP_Error(
				'better_importer.job.queue_mismatch',
				sprintf(
					/* translators: 1: expected queue rows, 2: actual queue rows */
					__( 'Queue seeding mismatch: expected %1$d rows, created %2$d.', 'better-wordpress-importer' ),
					$expected,
					$queued_count
				)
			);
		}

		$job->status = 'queued';
		$job->phase  = 'importing';

		$saved = $repo->save( $job );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		/**
		 * Fires after a new import job is created and queue rows are seeded.
		 *
		 * @since 1.0.0
		 *
		 * @param Better_Import_Job $job Import job instance.
		 */
		do_action( 'better_importer.job.created', $job );

		return $job;
	}

	/**
	 * Total entities in the manifest.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function manifest_entity_total() {
		if ( ! empty( $this->preflight_data['manifest_entity_total'] ) ) {
			return (int) $this->preflight_data['manifest_entity_total'];
		}

		return count( $this->item_manifest );
	}

	/**
	 * Hydrate a job from a database row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Database row object.
	 *
	 * @return Better_Import_Job
	 */
	public static function from_row( $row ) {
		$job = new self();

		$job->id                 = (int) $row->id;
		$job->status             = (string) $row->status;
		$job->phase              = (string) $row->phase;
		$job->phase_cursor       = (int) $row->phase_cursor;
		$job->file_path          = (string) $row->file_path;
		$job->attachment_id      = (int) $row->attachment_id;
		$job->total_posts        = (int) $row->total_posts;
		$job->total_comments     = (int) $row->total_comments;
		$job->total_terms        = (int) $row->total_terms;
		$job->total_users        = (int) $row->total_users;
		$job->total_media        = (int) $row->total_media;
		$job->scanned_posts      = (int) $row->scanned_posts;
		$job->scanned_comments   = (int) $row->scanned_comments;
		$job->scanned_terms      = (int) $row->scanned_terms;
		$job->scanned_users      = (int) $row->scanned_users;
		$job->scanned_media      = (int) $row->scanned_media;
		$job->imported_posts     = (int) $row->imported_posts;
		$job->imported_comments  = (int) $row->imported_comments;
		$job->imported_terms     = (int) $row->imported_terms;
		$job->imported_users     = (int) $row->imported_users;
		$job->imported_media     = (int) $row->imported_media;
		$job->skipped_posts      = (int) $row->skipped_posts;
		$job->skipped_comments   = (int) $row->skipped_comments;
		$job->skipped_terms      = (int) $row->skipped_terms;
		$job->skipped_users      = (int) $row->skipped_users;
		$job->skipped_media      = (int) $row->skipped_media;
		$job->failed_items       = (int) $row->failed_items;
		$job->options            = self::decode_json_field( $row->options );
		$job->preflight_data     = self::decode_json_field( $row->preflight_data );
		$job->item_manifest      = self::decode_json_field( $row->item_manifest );
		$job->mapping_state      = self::decode_json_field( $row->mapping_state );
		$job->user_id            = (int) $row->user_id;
		$job->started_at         = $row->started_at;
		$job->completed_at       = $row->completed_at;
		$job->created_at         = (string) $row->created_at;
		$job->updated_at         = (string) $row->updated_at;

		return $job;
	}

	/**
	 * Decode a JSON database column into an array.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $value Raw JSON value.
	 *
	 * @return array<string, mixed>|array<int, mixed>
	 */
	protected static function decode_json_field( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Read an import option with default.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public function get_option( $key, $default = null ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
	}

	/**
	 * Whether the job is in a terminal state.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_terminal() {
		return in_array( $this->status, array( 'complete', 'failed', 'cancelled' ), true );
	}

	/**
	 * Whether the job can accept processing batches.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function can_process() {
		return in_array( $this->status, array( 'queued', 'processing', 'remapping' ), true );
	}

	/**
	 * Build a status payload for AJAX polling.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_status_array() {
		$queue_repo   = new Better_Import_Queue_Repository();
		$queue_counts = $queue_repo->get_status_summary( $this->id );
		$active_item  = $queue_repo->get_active_item( $this->id );
		$total        = $this->manifest_entity_total();
		$finished     = isset( $queue_counts['complete'] ) ? (int) $queue_counts['complete'] : 0;
		$skipped      = isset( $queue_counts['skipped'] ) ? (int) $queue_counts['skipped'] : 0;
		$failed       = isset( $queue_counts['failed'] ) ? (int) $queue_counts['failed'] : 0;
		$partial      = isset( $queue_counts['in_progress'] ) ? (int) $queue_counts['in_progress'] : 0;
		$pending      = isset( $queue_counts['pending'] ) ? (int) $queue_counts['pending'] : 0;
		$processed    = $finished + $skipped + $failed;

		$percent = 0;
		if ( $total > 0 ) {
			$percent = min( 100, max( 0, (int) floor( ( $processed / $total ) * 100 ) ) );
			if ( $processed > 0 && 0 === $percent ) {
				$percent = 1;
			}
		}

		$logger = new Better_Logger( $this->id );

		$status = array(
			'job_id'            => $this->id,
			'status'            => $this->status,
			'phase'             => $this->phase,
			'phase_label'       => $this->phase_label(),
			'percent'           => $percent,
			'total_entities'    => $total,
			'scanned'           => $total,
			'queued'            => $total,
			'processed'         => $processed,
			'pending'           => $pending,
			'partial'           => $partial,
			'imported'          => $finished,
			'skipped'           => $skipped,
			'failed'            => $failed,
			'counts'            => array(
				'total'    => array(
					'posts'    => $this->total_posts,
					'media'    => $this->total_media,
					'terms'    => $this->total_terms,
					'users'    => $this->total_users,
					'comments' => $this->total_comments,
				),
				'imported' => array(
					'posts'    => $this->imported_posts,
					'media'    => $this->imported_media,
					'terms'    => $this->imported_terms,
					'users'    => $this->imported_users,
					'comments' => $this->imported_comments,
				),
				'skipped'  => array(
					'posts'    => $this->skipped_posts,
					'media'    => $this->skipped_media,
					'terms'    => $this->skipped_terms,
					'users'    => $this->skipped_users,
					'comments' => $this->skipped_comments,
				),
			),
			'current_entity'    => null,
			'started_at'        => $this->started_at,
			'completed_at'      => $this->completed_at,
			'file_title'        => isset( $this->preflight_data['title'] ) ? $this->preflight_data['title'] : '',
			'logs'              => $logger->get_recent( 15 ),
			'can_pause'         => in_array( $this->status, array( 'queued', 'processing' ), true ),
			'can_resume'        => 'paused' === $this->status,
			'can_cancel'        => ! $this->is_terminal(),
			'is_complete'       => 'complete' === $this->status,
			'is_terminal'       => $this->is_terminal(),
		);

		if ( $active_item ) {
			$status['current_entity'] = array(
				'index'       => $active_item->entity_index,
				'type'        => $active_item->entity_type,
				'title'       => $active_item->title,
				'step'        => $active_item->step,
				'step_cursor' => $active_item->step_cursor,
				'step_total'  => $active_item->step_total,
			);
		}

		return $status;
	}

	/**
	 * Human-readable phase label for the UI.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function phase_label() {
		$labels = array(
			''          => __( 'Preparing', 'better-wordpress-importer' ),
			'queueing'  => __( 'Queueing', 'better-wordpress-importer' ),
			'importing' => __( 'Importing', 'better-wordpress-importer' ),
			'remapping' => __( 'Remapping', 'better-wordpress-importer' ),
			'complete'  => __( 'Complete', 'better-wordpress-importer' ),
		);

		return isset( $labels[ $this->phase ] ) ? $labels[ $this->phase ] : $this->phase;
	}

	/**
	 * Pause the import job.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function pause() {
		$this->status = 'paused';
		$this->options['pause_requested'] = true;
	}

	/**
	 * Resume a paused import job.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function resume() {
		$this->status = 'processing';
		$this->phase  = 'importing';
		unset( $this->options['pause_requested'] );
	}

	/**
	 * Cancel the import job.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function cancel() {
		$this->status       = 'cancelled';
		$this->completed_at = current_time( 'mysql', true );
	}
}
