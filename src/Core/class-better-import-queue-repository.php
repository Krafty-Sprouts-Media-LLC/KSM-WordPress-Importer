<?php
/**
 * Import queue persistence — bulk seed and row access.
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queue repository.
 *
 * @since 1.0.0
 */
class Better_Import_Queue_Repository {

	/**
	 * Rows inserted per SQL statement during manifest seeding.
	 *
	 * @since 1.0.0
	 */
	const INSERT_BATCH_SIZE = 250;

	/**
	 * Queue table name including prefix.
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
		$this->table = $wpdb->prefix . 'better_import_queue';
	}

	/**
	 * Insert queue rows for every manifest entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $job_id   Import job ID.
	 * @param array $manifest Compact manifest entries.
	 * @param array $payloads Parsed payloads keyed by manifest index.
	 *
	 * @return true|WP_Error
	 */
	public function seed_from_manifest( $job_id, array $manifest, array $payloads = array() ) {
		global $wpdb;

		$job_id = absint( $job_id );
		if ( $job_id <= 0 ) {
			return new WP_Error( 'better_importer.queue.invalid_job', __( 'Invalid import job ID.', 'better-wordpress-importer' ) );
		}

		if ( empty( $manifest ) ) {
			return new WP_Error( 'better_importer.queue.empty_manifest', __( 'The import manifest is empty.', 'better-wordpress-importer' ) );
		}

		$now   = current_time( 'mysql', true );
		$batch = array();

		foreach ( $manifest as $entry ) {
			$entity_index = isset( $entry['i'] ) ? (int) $entry['i'] : 0;
			$payload_data = isset( $payloads[ $entity_index ] ) && is_array( $payloads[ $entity_index ] ) ? $payloads[ $entity_index ] : array();
			$payload      = $this->encode_payload( $payload_data );
			$step_total   = 0;

			if ( ! empty( $payload_data ) ) {
				$step_total = count( $payload_data['meta'] ?? array() ) + count( $payload_data['comments'] ?? array() );
			}

			$batch[] = array(
				'job_id'        => $job_id,
				'entity_index'  => $entity_index,
				'entity_type'   => isset( $entry['t'] ) ? sanitize_key( $entry['t'] ) : '',
				'old_entity_id' => isset( $entry['id'] ) ? sanitize_text_field( (string) $entry['id'] ) : '',
				'title'         => isset( $entry['title'] ) ? sanitize_text_field( (string) $entry['title'] ) : '',
				'status'        => 'pending',
				'step'          => 'create',
				'step_total'    => $step_total,
				'parsed_payload' => $payload['parsed_payload'],
				'payload_hash'  => $payload['payload_hash'],
				'created_at'    => $now,
				'updated_at'    => $now,
			);

			if ( count( $batch ) >= self::INSERT_BATCH_SIZE ) {
				$result = $this->insert_batch( $batch );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$batch = array();
			}
		}

		if ( ! empty( $batch ) ) {
			$result = $this->insert_batch( $batch );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Encode a parsed payload for queue storage.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $payload Parsed entity payload.
	 *
	 * @return array<string, string|null>
	 */
	protected function encode_payload( array $payload ) {
		if ( empty( $payload ) ) {
			return array(
				'parsed_payload' => null,
				'payload_hash'   => '',
			);
		}

		$serialized = serialize( $payload );

		return array(
			'parsed_payload' => gzcompress( $serialized, 5 ),
			'payload_hash'   => hash( 'sha256', $serialized ),
		);
	}

	/**
	 * Count queue rows for a job.
	 *
	 * @since 1.0.0
	 *
	 * @param int $job_id Import job ID.
	 *
	 * @return int
	 */
	public function count_for_job( $job_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE job_id = %d",
				absint( $job_id )
			)
		);
	}

	/**
	 * Insert a batch of queue rows with one query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to insert.
	 *
	 * @return true|WP_Error
	 */
	protected function insert_batch( array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return true;
		}

		$placeholders = array();
		$values       = array();

		foreach ( $rows as $row ) {
			$placeholders[] = '(%d, %d, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)';
			$values[]       = $row['job_id'];
			$values[]       = $row['entity_index'];
			$values[]       = $row['entity_type'];
			$values[]       = $row['old_entity_id'];
			$values[]       = $row['title'];
			$values[]       = $row['status'];
			$values[]       = $row['step'];
			$values[]       = $row['step_total'];
			$values[]       = $row['parsed_payload'];
			$values[]       = $row['payload_hash'];
			$values[]       = $row['created_at'];
			$values[]       = $row['updated_at'];
		}

		$sql = "INSERT INTO {$this->table}
			(job_id, entity_index, entity_type, old_entity_id, title, status, step, step_total, parsed_payload, payload_hash, created_at, updated_at)
			VALUES " . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$prepared = $wpdb->prepare( $sql, $values );
		$result   = $wpdb->query( $prepared );

		if ( false === $result ) {
			return new WP_Error(
				'better_importer.queue.insert_failed',
				__( 'Could not create import queue rows.', 'better-wordpress-importer' )
			);
		}

		return true;
	}

	/**
	 * Fetch the next queue item that still needs work.
	 *
	 * @since 1.1.0
	 *
	 * @param int $job_id Import job ID.
	 *
	 * @return Better_Import_Queue_Item|null
	 */
	public function get_next_work_item( $job_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE job_id = %d AND status IN ('pending', 'in_progress')
				ORDER BY entity_index ASC
				LIMIT 1",
				absint( $job_id )
			)
		);

		if ( ! $row ) {
			return null;
		}

		return Better_Import_Queue_Item::from_row( $row );
	}

	/**
	 * Persist a queue item row.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Queue_Item $item Queue item.
	 *
	 * @return true|WP_Error
	 */
	public function save( Better_Import_Queue_Item $item ) {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = array(
			'entity_type'    => $item->entity_type,
			'old_entity_id'  => $item->old_entity_id,
			'new_entity_id'  => $item->new_entity_id,
			'status'         => $item->status,
			'step'           => $item->step,
			'step_cursor'    => $item->step_cursor,
			'step_total'     => $item->step_total,
			'parsed_payload' => $item->parsed_payload,
			'payload_hash'   => $item->payload_hash,
			'title'          => $item->title,
			'attempts'       => $item->attempts,
			'error_message'  => $item->error_message,
			'error_code'     => $item->error_code,
			'last_error_at'  => $item->last_error_at,
			'updated_at'     => $now,
		);

		$format = array(
			'%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
		);

		if ( $item->id > 0 ) {
			$result = $wpdb->update(
				$this->table,
				$data,
				array( 'id' => $item->id ),
				$format,
				array( '%d' )
			);
		} else {
			$data['job_id']        = $item->job_id;
			$data['entity_index']  = $item->entity_index;
			$data['created_at']    = $now;
			$result                = $wpdb->insert(
				$this->table,
				$data,
				array_merge( array( '%d', '%d', '%s' ), $format )
			);
			if ( false !== $result ) {
				$item->id = (int) $wpdb->insert_id;
			}
		}

		if ( false === $result ) {
			return new WP_Error(
				'better_importer.queue.save_failed',
				__( 'Could not save the queue item.', 'better-wordpress-importer' )
			);
		}

		$item->updated_at = $now;

		return true;
	}

	/**
	 * Count queue rows by status for a job.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $job_id Import job ID.
	 * @param string $status Queue status.
	 *
	 * @return int
	 */
	public function count_by_status( $job_id, $status ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE job_id = %d AND status = %s",
				absint( $job_id ),
				sanitize_key( $status )
			)
		);
	}

	/**
	 * Bulk-skip queued attachment rows for jobs that do not fetch media.
	 *
	 * @since 1.5.0
	 *
	 * @param int $job_id Import job ID.
	 *
	 * @return int Number of rows skipped.
	 */
	public function skip_pending_attachments( $job_id ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET status = 'skipped',
					step = 'complete',
					parsed_payload = NULL,
					payload_hash = '',
					updated_at = %s
				WHERE job_id = %d
					AND entity_type = 'attachment'
					AND status IN ('pending', 'in_progress')",
				$now,
				absint( $job_id )
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Fetch aggregate queue status counts for a job.
	 *
	 * @since 1.2.0
	 *
	 * @param int $job_id Import job ID.
	 *
	 * @return array<string, int>
	 */
	public function get_status_summary( $job_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS total FROM {$this->table} WHERE job_id = %d GROUP BY status",
				absint( $job_id )
			),
			ARRAY_A
		);

		$summary = array(
			'pending'     => 0,
			'in_progress' => 0,
			'complete'    => 0,
			'skipped'     => 0,
			'failed'      => 0,
		);

		foreach ( $rows as $row ) {
			$key = isset( $row['status'] ) ? $row['status'] : '';
			if ( isset( $summary[ $key ] ) ) {
				$summary[ $key ] = (int) $row['total'];
			}
		}

		return $summary;
	}

	/**
	 * Get the current in-progress queue item for display.
	 *
	 * @since 1.2.0
	 *
	 * @param int $job_id Import job ID.
	 *
	 * @return Better_Import_Queue_Item|null
	 */
	public function get_active_item( $job_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE job_id = %d AND status = 'in_progress'
				ORDER BY entity_index ASC
				LIMIT 1",
				absint( $job_id )
			)
		);

		if ( ! $row ) {
			return null;
		}

		return Better_Import_Queue_Item::from_row( $row );
	}
}
