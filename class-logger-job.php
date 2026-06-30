<?php
/**
 * Job-scoped logger that persists entries on the import job.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logger that writes to a WXR_Import_Job instance.
 *
 * @since 3.0.0
 */
class WP_Importer_Logger_Job extends WP_Importer_Logger {

	/**
	 * Target import job.
	 *
	 * @since 3.0.0
	 * @var WXR_Import_Job
	 */
	protected $job;

	/**
	 * Constructor.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job Import job to log against.
	 */
	public function __construct( WXR_Import_Job $job ) {
		$this->job = $job;
	}

	/**
	 * Write a log entry.
	 *
	 * @since 3.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 *
	 * @return void
	 */
	public function write( $level, $message, array $context = array() ) {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		$this->job->add_log( $level, $message );
	}
}
