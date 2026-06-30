<?php
/**
 * Per-entity import item model and repository.
 *
 * Tracks individual entity outcomes (imported, skipped, failed) per job
 * in the wp_wxr_import_items table.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single import item record.
 *
 * @since 3.0.0
 */
class WXR_Import_Item {

	/**
	 * Row ID.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $id = 0;

	/**
	 * Parent job ID.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $job_id = 0;

	/**
	 * Entity type (posts, media, users, comments, terms).
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $entity_type = '';

	/**
	 * Original ID from the export file.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $old_id = '';

	/**
	 * New local ID after import.
	 *
	 * @since 3.0.0
	 * @var int|null
	 */
	public $new_id = null;

	/**
	 * Human-readable title or label.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $title = '';

	/**
	 * Item status (pending, imported, skipped, failed).
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $status = 'pending';

	/**
	 * Import attempt count.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	public $attempts = 0;

	/**
	 * Error message when status is failed.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $error_message = '';

	/**
	 * Created timestamp (MySQL datetime).
	 *
	 * @since 3.0.0
	 * @var string
	 */
	public $created_at = '';
}

/**
 * Database access for import items.
 *
 * @since 3.0.0
 */
class WXR_Import_Item_Repository {

	/**
	 * Items table name.
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
		$this->table = $wpdb->prefix . 'wxr_import_items';
	}

	/**
	 * Record a batch of item results from import_batch().
	 *
	 * @since 3.0.0
	 *
	 * @param int                  $job_id  Job ID.
	 * @param array<int, array>    $results Result rows from the importer.
	 *
	 * @return void
	 */
	public function record_batch( $job_id, array $results ) {
		if ( empty( $results ) ) {
			return;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		foreach ( $results as $row ) {
			if ( empty( $row['entity_type'] ) ) {
				continue;
			}

			$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : 'imported';
			if ( ! in_array( $status, array( 'imported', 'skipped', 'failed' ), true ) ) {
				$status = 'imported';
			}

			$wpdb->insert(
				$this->table,
				array(
					'job_id'        => absint( $job_id ),
					'entity_type'   => sanitize_key( $row['entity_type'] ),
					'old_id'        => isset( $row['old_id'] ) ? (string) $row['old_id'] : '',
					'new_id'        => isset( $row['new_id'] ) ? absint( $row['new_id'] ) : null,
					'title'         => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
					'status'        => $status,
					'attempts'      => 1,
					'error_message' => isset( $row['error_message'] ) ? sanitize_text_field( $row['error_message'] ) : '',
					'created_at'    => $now,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Count items by status for a job.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Optional status filter.
	 *
	 * @return int
	 */
	public function count_by_status( $job_id, $status = '' ) {
		global $wpdb;

		if ( $status ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE job_id = %d AND status = %s",
					absint( $job_id ),
					sanitize_key( $status )
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE job_id = %d",
				absint( $job_id )
			)
		);
	}

	/**
	 * Count items by entity type for a status.
	 *
	 * @since 3.0.8
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Item status.
	 *
	 * @return array<string, int>
	 */
	public function count_by_type_for_status( $job_id, $status ) {
		global $wpdb;

		$counts = array(
			'posts'    => 0,
			'comments' => 0,
			'terms'    => 0,
			'users'    => 0,
			'media'    => 0,
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entity_type, COUNT(*) AS total
				FROM {$this->table}
				WHERE job_id = %d AND status = %s
				GROUP BY entity_type",
				absint( $job_id ),
				sanitize_key( $status )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row ) {
			$type = isset( $row['entity_type'] ) ? sanitize_key( $row['entity_type'] ) : '';
			if ( isset( $counts[ $type ] ) ) {
				$counts[ $type ] = absint( $row['total'] );
			}
		}

		return $counts;
	}

	/**
	 * Get recent failed items for a job.
	 *
	 * @since 3.0.0
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Maximum rows.
	 *
	 * @return array<int, WXR_Import_Item>
	 */
	public function get_recent_failures( $job_id, $limit = 10 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE job_id = %d AND status = 'failed'
				ORDER BY id DESC
				LIMIT %d",
				absint( $job_id ),
				absint( $limit )
			)
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->hydrate( $row );
		}

		return $items;
	}

	/**
	 * Hydrate a database row.
	 *
	 * @since 3.0.0
	 *
	 * @param object $row Database row.
	 *
	 * @return WXR_Import_Item
	 */
	protected function hydrate( $row ) {
		$item = new WXR_Import_Item();

		$item->id            = (int) $row->id;
		$item->job_id        = (int) $row->job_id;
		$item->entity_type    = $row->entity_type;
		$item->old_id        = $row->old_id;
		$item->new_id        = null !== $row->new_id ? (int) $row->new_id : null;
		$item->title         = $row->title;
		$item->status        = $row->status;
		$item->attempts      = (int) $row->attempts;
		$item->error_message = $row->error_message;
		$item->created_at    = $row->created_at;

		return $item;
	}
}
