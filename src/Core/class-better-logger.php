<?php
/**
 * Structured import log writer.
 *
 * @package Better_WordPress_Importer
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persists import log lines to the better_import_log table.
 *
 * @since 1.1.0
 */
class Better_Logger {

	/**
	 * Active job ID.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	protected $job_id = 0;

	/**
	 * Log table name including prefix.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	protected $table;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param int $job_id Import job ID.
	 */
	public function __construct( $job_id = 0 ) {
		global $wpdb;
		$this->job_id = absint( $job_id );
		$this->table  = $wpdb->prefix . 'better_import_log';
	}

	/**
	 * Write a log entry.
	 *
	 * @since 1.1.0
	 *
	 * @param string               $level        Log level.
	 * @param string               $message      Log message.
	 * @param int|null             $entity_index Related entity index.
	 * @param array<string, mixed> $context      Optional context payload.
	 *
	 * @return void
	 */
	public function log( $level, $message, $entity_index = null, array $context = array() ) {
		global $wpdb;

		if ( $this->job_id <= 0 ) {
			return;
		}

		$wpdb->insert(
			$this->table,
			array(
				'job_id'       => $this->job_id,
				'level'        => sanitize_key( $level ),
				'message'      => wp_strip_all_tags( (string) $message ),
				'entity_index' => null === $entity_index ? null : absint( $entity_index ),
				'context'      => empty( $context ) ? null : wp_json_encode( $context ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Log an info message.
	 *
	 * @since 1.1.0
	 *
	 * @param string $message Log message.
	 * @param int    $entity_index Optional entity index.
	 *
	 * @return void
	 */
	public function info( $message, $entity_index = null ) {
		$this->log( 'info', $message, $entity_index );
	}

	/**
	 * Log a warning message.
	 *
	 * @since 1.1.0
	 *
	 * @param string $message Log message.
	 * @param int    $entity_index Optional entity index.
	 *
	 * @return void
	 */
	public function warning( $message, $entity_index = null ) {
		$this->log( 'warning', $message, $entity_index );
	}

	/**
	 * Log an error message.
	 *
	 * @since 1.1.0
	 *
	 * @param string $message Log message.
	 * @param int    $entity_index Optional entity index.
	 *
	 * @return void
	 */
	public function error( $message, $entity_index = null ) {
		$this->log( 'error', $message, $entity_index );
	}

	/**
	 * Fetch recent log lines for a job.
	 *
	 * @since 1.2.0
	 *
	 * @param int $limit Maximum rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent( $limit = 20 ) {
		global $wpdb;

		if ( $this->job_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT level, message, entity_index, created_at
				FROM {$this->table}
				WHERE job_id = %d
				ORDER BY id DESC
				LIMIT %d",
				$this->job_id,
				absint( $limit )
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_reverse( $rows );
	}
}
