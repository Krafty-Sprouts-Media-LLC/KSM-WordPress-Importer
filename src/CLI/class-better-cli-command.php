<?php
/**
 * WP-CLI commands for the Better WordPress Importer queue engine.
 *
 * @package Better_WordPress_Importer
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI command handler.
 *
 * @since 1.3.0
 */
class Better_CLI_Command extends WP_CLI_Command {

	/**
	 * Import content from a WXR file using the resumable queue engine.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a valid WXR file for importing.
	 *
	 * [--default-author=<id>]
	 * : Default author ID for unmapped authors.
	 *
	 * [--batch-seconds=<n>]
	 * : Wall-clock seconds per batch. Default: 25.
	 *
	 * [--attachments]
	 * : Fetch attachment files. Use --no-attachments to skip attachment downloads.
	 *
	 * [--dry-run]
	 * : Validate XML and report counts without importing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp better-importer import /path/to/export.xml
	 *     wp better-importer import export.xml --dry-run
	 *     wp better-importer import export.xml --no-attachments
	 *
	 * @since 1.3.0
	 *
	 * @param array<int, string>           $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$path = realpath( $args[0] );
		if ( ! $path || ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( 'Specified file %s does not exist or is not readable.', $args[0] ) );
		}

		$preflight = new Better_Preflight();
		$scan      = $preflight->scan( $path );
		if ( is_wp_error( $scan ) ) {
			WP_CLI::error( $scan->get_error_message() );
		}

		$counts = isset( $scan['preflight']['counts'] ) ? $scan['preflight']['counts'] : array();

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::success(
				sprintf(
					'WXR valid. Posts: %d, Media: %d, Terms: %d, Users: %d',
					isset( $counts['posts'] ) ? (int) $counts['posts'] : 0,
					isset( $counts['attachments'] ) ? (int) $counts['attachments'] : 0,
					isset( $counts['terms'] ) ? (int) $counts['terms'] : 0,
					isset( $counts['users'] ) ? (int) $counts['users'] : 0
				)
			);
			return;
		}

		$options = array(
			'fetch_attachments' => ! array_key_exists( 'attachments', $assoc_args ) || false !== $assoc_args['attachments'],
		);

		if ( isset( $assoc_args['default-author'] ) ) {
			$options['default_author'] = absint( $assoc_args['default-author'] );
			if ( ! get_user_by( 'ID', $options['default_author'] ) ) {
				WP_CLI::error( 'Invalid default author ID specified.' );
			}
		} else {
			$options['default_author'] = get_current_user_id();
		}

		$job = Better_Import_Job::create( 0, $path, $options );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$batch_seconds = isset( $assoc_args['batch-seconds'] ) ? absint( $assoc_args['batch-seconds'] ) : 0;
		$processor     = new Better_Import_Processor();
		$total         = max( 1, $job->manifest_entity_total() );
		$progress      = \WP_CLI\Utils\make_progress_bar( 'Importing', $total );
		$last_processed = 0;

		WP_CLI::log( sprintf( 'Started import job #%d (%d entities).', $job->id, $total ) );

		while ( true ) {
			$job = ( new Better_Import_Job_Repository() )->get( $job->id );
			if ( ! $job ) {
				WP_CLI::error( 'Import job disappeared during processing.' );
			}

			if ( $job->is_terminal() ) {
				break;
			}

			$result = $processor->process_batch( $job->id, $batch_seconds );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$job    = ( new Better_Import_Job_Repository() )->get( $job->id );
			$status = $job ? $job->to_status_array() : array();
			$done   = isset( $status['processed'] ) ? (int) $status['processed'] : 0;
			$delta  = max( 0, $done - $last_processed );

			if ( $delta > 0 ) {
				$progress->tick( $delta );
				$last_processed = $done;
			}
		}

		$progress->finish();

		$job    = ( new Better_Import_Job_Repository() )->get( $job->id );
		$status = $job ? $job->to_status_array() : array();

		if ( $job && 'complete' === $job->status ) {
			WP_CLI::success(
				sprintf(
					'Import complete (job #%d). Imported: %d, Skipped: %d, Failed: %d',
					$job->id,
					isset( $status['imported'] ) ? (int) $status['imported'] : 0,
					isset( $status['skipped'] ) ? (int) $status['skipped'] : 0,
					isset( $status['failed'] ) ? (int) $status['failed'] : 0
				)
			);
			return;
		}

		WP_CLI::warning(
			sprintf(
				'Import ended with status: %s (job #%d).',
				$job ? $job->status : 'unknown',
				$job ? $job->id : 0
			)
		);
	}

	/**
	 * Show import job progress.
	 *
	 * ## OPTIONS
	 *
	 * [<job_id>]
	 * : Job ID. Defaults to the most recent job.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int, string>           $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$job = $this->resolve_job( $args );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		WP_CLI::log( wp_json_encode( $job->to_status_array(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Cancel a running import job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job ID to cancel.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int, string>           $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cancel( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Job ID is required.' );
		}

		$job = ( new Better_Import_Job_Repository() )->get( absint( $args[0] ) );
		if ( ! $job ) {
			WP_CLI::error( 'Import job not found.' );
		}

		if ( $job->is_terminal() ) {
			WP_CLI::warning( sprintf( 'Job #%d is already %s.', $job->id, $job->status ) );
			return;
		}

		$job->cancel();
		( new Better_Import_Job_Repository() )->save( $job );

		WP_CLI::success( sprintf( 'Job #%d cancelled.', $job->id ) );
	}

	/**
	 * List recent import jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Number of jobs to list. Default: 10.
	 *
	 * @subcommand list
	 *
	 * @since 1.3.0
	 *
	 * @param array<int, string>           $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function list_jobs( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10;
		$jobs  = ( new Better_Import_Job_Repository() )->list_recent( $limit );

		if ( empty( $jobs ) ) {
			WP_CLI::log( 'No jobs found.' );
			return;
		}

		foreach ( $jobs as $job ) {
			$status = $job->to_status_array();
			WP_CLI::log(
				sprintf(
					'#%d [%s] %d%% — %d/%d entities',
					$job->id,
					$job->status,
					isset( $status['percent'] ) ? (int) $status['percent'] : 0,
					isset( $status['processed'] ) ? (int) $status['processed'] : 0,
					isset( $status['total_entities'] ) ? (int) $status['total_entities'] : 0
				)
			);
		}
	}

	/**
	 * Show final report for a completed job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job ID.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int, string>           $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function report( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Job ID is required.' );
		}

		$job = ( new Better_Import_Job_Repository() )->get( absint( $args[0] ) );
		if ( ! $job ) {
			WP_CLI::error( 'Import job not found.' );
		}

		WP_CLI::log( wp_json_encode( $job->to_status_array(), JSON_PRETTY_PRINT ) );

		$logger = new Better_Logger( $job->id );
		foreach ( $logger->get_recent( 50 ) as $entry ) {
			WP_CLI::log( sprintf( '[%s] %s', $entry['level'], $entry['message'] ) );
		}
	}

	/**
	 * Resolve a job from CLI arguments.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int, string> $args Positional arguments.
	 *
	 * @return Better_Import_Job|WP_Error
	 */
	protected function resolve_job( $args ) {
		if ( ! empty( $args[0] ) ) {
			$job = ( new Better_Import_Job_Repository() )->get( absint( $args[0] ) );
			if ( ! $job ) {
				return new WP_Error( 'better_importer.job.not_found', 'Import job not found.' );
			}
			return $job;
		}

		$jobs = ( new Better_Import_Job_Repository() )->list_recent( 1 );
		if ( empty( $jobs ) ) {
			return new WP_Error( 'better_importer.job.not_found', 'No import jobs found.' );
		}

		return $jobs[0];
	}
}
