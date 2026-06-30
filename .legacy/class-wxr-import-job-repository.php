<?php
/**
 * Database access layer for import jobs.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import job repository.
 *
 * @since 3.0.0
 */
class WXR_Import_Job_Repository {

	/**
	 * Jobs table name.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	protected $table;

	/**
	 * Constructor.
	 *
	 * @since 3.0.0
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wxr_import_jobs';
	}

	/**
	 * Get a job by ID.
	 *
	 * @since 3.0.0
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return WXR_Import_Job|WP_Error
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
			return new WP_Error(
				'wxr_importer.job.not_found',
				__( 'Import job not found.', 'wordpress-importer' )
			);
		}

		return $this->hydrate( $row );
	}

	/**
	 * Save a job (insert or update).
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job Job instance.
	 *
	 * @return int|WP_Error Job ID on success.
	 */
	public function save( WXR_Import_Job $job ) {
		global $wpdb;

		$job->updated_at = current_time( 'mysql', true );

		if ( $job->id > 0 ) {
			$progress_floor = $this->get_progress_floor( $job->id );
			$manifest_total = $job->manifest_entity_total();

			$job->xml_cursor_item = max(
				absint( $job->xml_cursor_item ),
				absint( $progress_floor['xml_cursor_item'] ),
				absint( $progress_floor['recorded_items'] )
			);

			if ( $manifest_total > 0 ) {
				$job->xml_cursor_item = min( $job->xml_cursor_item, $manifest_total );
			}

			$job->processed_posts    = max( absint( $job->processed_posts ), absint( $progress_floor['processed_posts'] ) );
			$job->processed_comments = max( absint( $job->processed_comments ), absint( $progress_floor['processed_comments'] ) );
			$job->processed_terms    = max( absint( $job->processed_terms ), absint( $progress_floor['processed_terms'] ) );
			$job->processed_users    = max( absint( $job->processed_users ), absint( $progress_floor['processed_users'] ) );
			$job->processed_media    = max( absint( $job->processed_media ), absint( $progress_floor['processed_media'] ) );
			$job->failed_items       = max( absint( $job->failed_items ), absint( $progress_floor['failed_items'] ) );
			$job->skipped_items      = max( absint( $job->skipped_items ), absint( $progress_floor['skipped_items'] ) );
		}

		$manifest_to_store = $job->item_manifest;
		if ( $job->manifest_entity_total() > 500 ) {
			$manifest_to_store = array();
		}

		$data = array(
			'status'             => $job->status,
			'file_path'          => $job->file_path,
			'attachment_id'      => absint( $job->attachment_id ),
			'xml_cursor_item'    => absint( $job->xml_cursor_item ),
			'total_posts'        => absint( $job->total_posts ),
			'total_comments'     => absint( $job->total_comments ),
			'total_terms'        => absint( $job->total_terms ),
			'total_users'        => absint( $job->total_users ),
			'total_media'        => absint( $job->total_media ),
			'processed_posts'    => absint( $job->processed_posts ),
			'processed_comments' => absint( $job->processed_comments ),
			'processed_terms'    => absint( $job->processed_terms ),
			'processed_users'    => absint( $job->processed_users ),
			'processed_media'    => absint( $job->processed_media ),
			'failed_items'       => absint( $job->failed_items ),
			'skipped_items'      => absint( $job->skipped_items ),
			'options'            => wp_json_encode( $job->options ),
			'preflight_data'     => wp_json_encode( $job->preflight_data ),
			'item_manifest'      => wp_json_encode( $manifest_to_store ),
			'user_id'            => absint( $job->user_id ),
			'updated_at'         => $job->updated_at,
		);

		$formats = array(
			'%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d',
			'%d', '%d', '%d', '%d', '%d', '%d', '%d',
			'%s', '%s', '%s', '%d', '%s',
		);

		if ( $job->id > 0 ) {
			$result = $wpdb->update(
				$this->table,
				$data,
				array( 'id' => $job->id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				return new WP_Error(
					'wxr_importer.job.save_failed',
					__( 'Could not update import job.', 'wordpress-importer' )
				);
			}

			return $job->id;
		}

		$data['created_at'] = $job->created_at ? $job->created_at : $job->updated_at;
		$formats[]          = '%s';

		$result = $wpdb->insert( $this->table, $data, $formats );

		if ( ! $result ) {
			return new WP_Error(
				'wxr_importer.job.save_failed',
				__( 'Could not create import job.', 'wordpress-importer' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * List recent jobs.
	 *
	 * @since 3.0.0
	 *
	 * @param int $limit Maximum jobs to return.
	 *
	 * @return array<int, WXR_Import_Job>
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
			$jobs[] = $this->hydrate( $row );
		}

		return $jobs;
	}

	/**
	 * Get the oldest running job.
	 *
	 * @since 3.0.0
	 *
	 * @return WXR_Import_Job|null
	 */
	public function get_next_runnable_job() {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT * FROM {$this->table}
			WHERE status IN ('pending', 'processing', 'remapping', 'downloading_attachments')
			ORDER BY updated_at ASC
			LIMIT 1"
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Get the active (non-terminal) job for a user.
	 *
	 * @since 3.0.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return WXR_Import_Job|null
	 */
	public function get_active_job_for_user( $user_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE user_id = %d
				AND status IN ('pending', 'processing', 'remapping', 'paused', 'downloading_attachments')
				ORDER BY id DESC
				LIMIT 1",
				absint( $user_id )
			)
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Get the most recent completed job for a user.
	 *
	 * @since 3.0.0
	 *
	 * @param int $user_id User ID.
	 *
	 * @return WXR_Import_Job|null
	 */
	public function get_last_completed_for_user( $user_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE user_id = %d AND status = 'complete'
				ORDER BY id DESC
				LIMIT 1",
				absint( $user_id )
			)
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Hydrate a database row into a job object.
	 *
	 * @since 3.0.0
	 *
	 * @param object $row Database row.
	 *
	 * @return WXR_Import_Job
	 */
	protected function hydrate( $row ) {
		$job = new WXR_Import_Job();

		$job->id                 = (int) $row->id;
		$job->status             = $row->status;
		$job->file_path          = $row->file_path;
		$job->attachment_id      = (int) $row->attachment_id;
		$job->xml_cursor_item    = (int) $row->xml_cursor_item;
		$job->total_posts        = (int) $row->total_posts;
		$job->total_comments     = (int) $row->total_comments;
		$job->total_terms        = (int) $row->total_terms;
		$job->total_users        = (int) $row->total_users;
		$job->total_media        = (int) $row->total_media;
		$job->processed_posts    = (int) $row->processed_posts;
		$job->processed_comments = (int) $row->processed_comments;
		$job->processed_terms    = (int) $row->processed_terms;
		$job->processed_users    = (int) $row->processed_users;
		$job->processed_media    = (int) $row->processed_media;
		$job->failed_items       = (int) $row->failed_items;
		$job->skipped_items      = (int) $row->skipped_items;
		$job->user_id            = (int) $row->user_id;
		$job->created_at         = $row->created_at;
		$job->updated_at         = $row->updated_at;

		$options = json_decode( $row->options, true );
		$job->options = is_array( $options ) ? $options : array();

		$preflight = json_decode( $row->preflight_data, true );
		$job->preflight_data = is_array( $preflight ) ? $preflight : array();

		$manifest = json_decode( $row->item_manifest, true );
		$job->item_manifest = is_array( $manifest ) ? $manifest : array();

		// Repair corrupted manifest totals from older jobs / failed JSON round-trips.
		$expected_total = $job->total_posts + $job->total_comments + $job->total_terms + $job->total_users + $job->total_media;
		$stored_total   = isset( $job->preflight_data['manifest_entity_total'] ) ? (int) $job->preflight_data['manifest_entity_total'] : 0;
		if ( $expected_total > 0 && ( $stored_total <= 0 || $stored_total < ( $expected_total * 0.5 ) ) ) {
			$job->preflight_data['manifest_entity_total'] = $expected_total;
		}

		$recorded_items = $this->count_recorded_items( $job->id );
		if ( $recorded_items > $job->xml_cursor_item ) {
			$job->xml_cursor_item = $recorded_items;

			$total = $job->manifest_entity_total();
			if ( $total > 0 ) {
				$job->xml_cursor_item = min( $job->xml_cursor_item, $total );
			}
		}

		return $job;
	}

	/**
	 * Get the minimum safe progress values for a persisted job.
	 *
	 * This prevents stale concurrent requests from writing an older cursor or
	 * lower counters over progress already recorded by another batch.
	 *
	 * @since 3.0.8
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, int>
	 */
	protected function get_progress_floor( $job_id ) {
		global $wpdb;

		$defaults = array(
			'xml_cursor_item'    => 0,
			'processed_posts'    => 0,
			'processed_comments' => 0,
			'processed_terms'    => 0,
			'processed_users'    => 0,
			'processed_media'    => 0,
			'failed_items'       => 0,
			'skipped_items'      => 0,
			'recorded_items'     => 0,
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT xml_cursor_item, processed_posts, processed_comments, processed_terms, processed_users, processed_media, failed_items, skipped_items
				FROM {$this->table}
				WHERE id = %d",
				absint( $job_id )
			),
			ARRAY_A
		);

		if ( is_array( $row ) ) {
			foreach ( $defaults as $key => $value ) {
				if ( isset( $row[ $key ] ) ) {
					$defaults[ $key ] = absint( $row[ $key ] );
				}
			}
		}

		$defaults['recorded_items'] = $this->count_recorded_items( $job_id );

		return $defaults;
	}

	/**
	 * Count item records already persisted for a job.
	 *
	 * @since 3.0.8
	 *
	 * @param int $job_id Job ID.
	 * @return int
	 */
	protected function count_recorded_items( $job_id ) {
		global $wpdb;

		$items_table = $wpdb->prefix . 'wxr_import_items';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$items_table} WHERE job_id = %d",
				absint( $job_id )
			)
		);
	}
}
