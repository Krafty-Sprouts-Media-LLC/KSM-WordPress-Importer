<?php
/**
 * Import job persistence.
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Job repository.
 *
 * @since 1.0.0
 */
class Better_Import_Job_Repository {

	/**
	 * Jobs table name including prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $table;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'better_import_jobs';
	}

	/**
	 * Load a job by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return Better_Import_Job|null
	 */
	public function get( $job_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				absint( $job_id )
			)
		);

		if ( ! $row ) {
			return null;
		}

		return Better_Import_Job::from_row( $row );
	}

	/**
	 * Persist a job row.
	 *
	 * @since 1.0.0
	 *
	 * @param Better_Import_Job $job Job instance.
	 *
	 * @return true|WP_Error
	 */
	public function save( Better_Import_Job $job ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$data = array(
			'status'            => $job->status,
			'phase'             => $job->phase,
			'phase_cursor'      => $job->phase_cursor,
			'file_path'         => $job->file_path,
			'attachment_id'     => $job->attachment_id,
			'total_posts'       => $job->total_posts,
			'total_comments'    => $job->total_comments,
			'total_terms'       => $job->total_terms,
			'total_users'       => $job->total_users,
			'total_media'       => $job->total_media,
			'scanned_posts'     => $job->scanned_posts,
			'scanned_comments'  => $job->scanned_comments,
			'scanned_terms'     => $job->scanned_terms,
			'scanned_users'     => $job->scanned_users,
			'scanned_media'     => $job->scanned_media,
			'imported_posts'    => $job->imported_posts,
			'imported_comments' => $job->imported_comments,
			'imported_terms'    => $job->imported_terms,
			'imported_users'    => $job->imported_users,
			'imported_media'    => $job->imported_media,
			'skipped_posts'     => $job->skipped_posts,
			'skipped_comments'  => $job->skipped_comments,
			'skipped_terms'     => $job->skipped_terms,
			'skipped_users'     => $job->skipped_users,
			'skipped_media'     => $job->skipped_media,
			'failed_items'      => $job->failed_items,
			'options'           => wp_json_encode( $job->options ),
			'preflight_data'    => wp_json_encode( $job->preflight_data ),
			'item_manifest'     => wp_json_encode( $job->item_manifest ),
			'mapping_state'     => wp_json_encode( $job->mapping_state ),
			'user_id'           => $job->user_id,
			'started_at'        => $job->started_at,
			'completed_at'      => $job->completed_at,
			'updated_at'        => $now,
		);

		$format = array(
			'%s', '%s', '%d', '%s', '%d',
			'%d', '%d', '%d', '%d', '%d',
			'%d', '%d', '%d', '%d', '%d',
			'%d', '%d', '%d', '%d', '%d',
			'%d', '%d', '%d', '%d', '%d',
			'%d', '%s', '%s', '%s', '%s',
			'%d', '%s', '%s', '%s',
		);

		if ( $job->id > 0 ) {
			$result = $wpdb->update(
				$this->table,
				$data,
				array( 'id' => $job->id ),
				$format,
				array( '%d' )
			);
		} else {
			$data['created_at'] = $now;
			$result             = $wpdb->insert(
				$this->table,
				$data,
				array_merge( $format, array( '%s' ) )
			);
			if ( false !== $result ) {
				$job->id         = (int) $wpdb->insert_id;
				$job->created_at = $now;
			}
		}

		if ( false === $result ) {
			return new WP_Error(
				'better_importer.job.save_failed',
				__( 'Could not save the import job.', 'better-wordpress-importer' )
			);
		}

		$job->updated_at = $now;

		return true;
	}

	/**
	 * Sync imported/skipped/failed counters from queue rows.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job $job Import job.
	 *
	 * @return void
	 */
	public function sync_counters_from_queue( Better_Import_Job $job ) {
		global $wpdb;

		$table = $wpdb->prefix . 'better_import_queue';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entity_type, status, COUNT(*) AS total
				FROM {$table}
				WHERE job_id = %d
				GROUP BY entity_type, status",
				$job->id
			),
			ARRAY_A
		);

		$job->imported_posts    = 0;
		$job->imported_users    = 0;
		$job->imported_terms    = 0;
		$job->imported_media    = 0;
		$job->skipped_posts     = 0;
		$job->skipped_users     = 0;
		$job->skipped_terms     = 0;
		$job->skipped_media     = 0;
		$job->failed_items      = 0;

		foreach ( $rows as $row ) {
			$type   = isset( $row['entity_type'] ) ? $row['entity_type'] : '';
			$status = isset( $row['status'] ) ? $row['status'] : '';
			$total  = isset( $row['total'] ) ? (int) $row['total'] : 0;

			if ( 'complete' === $status ) {
				if ( 'user' === $type ) {
					$job->imported_users += $total;
				} elseif ( 'term' === $type ) {
					$job->imported_terms += $total;
				} elseif ( 'attachment' === $type ) {
					$job->imported_media += $total;
				} else {
					$job->imported_posts += $total;
				}
			} elseif ( 'skipped' === $status ) {
				if ( 'user' === $type ) {
					$job->skipped_users += $total;
				} elseif ( 'term' === $type ) {
					$job->skipped_terms += $total;
				} elseif ( 'attachment' === $type ) {
					$job->skipped_media += $total;
				} else {
					$job->skipped_posts += $total;
				}
			} elseif ( 'failed' === $status ) {
				$job->failed_items += $total;
			}
		}
	}

	/**
	 * List recent import jobs.
	 *
	 * @since 1.2.0
	 *
	 * @param int $limit Maximum rows.
	 *
	 * @return array<int, Better_Import_Job>
	 */
	public function list_recent( $limit = 20 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d",
				absint( $limit )
			)
		);

		$jobs = array();
		foreach ( $rows as $row ) {
			$jobs[] = Better_Import_Job::from_row( $row );
		}

		return $jobs;
	}

	/**
	 * Get the active job for a user, if any.
	 *
	 * @since 1.2.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return Better_Import_Job|null
	 */
	public function get_active_job_for_user( $user_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE user_id = %d AND status IN ('queued', 'processing', 'paused')
				ORDER BY id DESC
				LIMIT 1",
				absint( $user_id )
			)
		);

		if ( ! $row ) {
			return null;
		}

		return Better_Import_Job::from_row( $row );
	}

	/**
	 * Verify the current user may access a job.
	 *
	 * @since 1.2.0
	 *
	 * @param Better_Import_Job $job Import job.
	 *
	 * @return bool
	 */
	public function user_can_access( Better_Import_Job $job ) {
		if ( current_user_can( 'import' ) && ( 0 === $job->user_id || (int) get_current_user_id() === (int) $job->user_id ) ) {
			return true;
		}

		return current_user_can( 'manage_options' );
	}
}
