<?php
/**
 * WP-CLI command handler for batch-based WordPress importer.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands for the WXR importer.
 *
 * @since 3.0.0
 */
class WXR_Import_Command extends WP_CLI_Command {

	/**
	 * Import content from a WXR file using resumable batches.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a valid WXR file for importing.
	 *
	 * [--verbose[=<level>]]
	 * : Verbose log level.
	 *
	 * [--default-author=<id>]
	 * : Default author ID for unmapped authors.
	 *
	 * [--batch-size=<n>]
	 * : Items per batch. Default: 10.
	 *
	 * [--no-attachments]
	 * : Skip attachment download.
	 *
	 * [--dry-run]
	 * : Validate XML and report counts without importing.
	 */
	public function import( $args, $assoc_args ) {
		$path = realpath( $args[0] );
		if ( ! $path || ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( 'Specified file %s does not exist or is not readable.', $args[0] ) );
		}

		$importer = new WXR_Importer();
		$info     = $importer->get_preliminary_information( $path );

		if ( is_wp_error( $info ) ) {
			WP_CLI::error( $info->get_error_message() );
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::success(
				sprintf(
					'WXR valid. Posts: %d, Media: %d, Terms: %d, Users: %d',
					$info->post_count,
					$info->media_count,
					$info->term_count,
					count( $info->users )
				)
			);
			return;
		}

		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 10;
		$options    = array(
			'fetch_attachments' => empty( $assoc_args['no-attachments'] ),
			'batch_size'        => max( 1, $batch_size ),
		);

		if ( isset( $assoc_args['default-author'] ) ) {
			$options['default_author'] = absint( $assoc_args['default-author'] );
			if ( ! get_user_by( 'ID', $options['default_author'] ) ) {
				WP_CLI::error( 'Invalid default author ID specified.' );
			}
		}

		$job = WXR_Import_Job::create( 0, $path, $options );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$job->set_status( 'processing' );
		$repository = new WXR_Import_Job_Repository();
		$repository->save( $job );

		$processor  = new WXR_Import_Processor();
		$manifest   = count( $job->item_manifest );
		$progress   = \WP_CLI\Utils\make_progress_bar( 'Importing', max( 1, $manifest ) );

		while ( true ) {
			$job = WXR_Import_Job::get( $job->id );
			if ( is_wp_error( $job ) ) {
				WP_CLI::error( $job->get_error_message() );
			}

			if ( $job->is_terminal() ) {
				break;
			}

			$cursor_before = $job->xml_cursor_item;
			$result        = $processor->process_job( $job->id, $batch_size );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$job = WXR_Import_Job::get( $job->id );
			$delta = max( 0, $job->xml_cursor_item - $cursor_before );
			if ( $delta > 0 ) {
				$progress->tick( $delta );
			} elseif ( 'remapping' === $job->status || 'downloading_attachments' === $job->status ) {
				$progress->tick( 0 );
			}
		}

		$progress->finish();

		if ( 'complete' === $job->status ) {
			WP_CLI::success(
				sprintf(
					'Import complete. Posts: %d, Skipped: %d, Failed: %d',
					$job->processed_posts,
					$job->skipped_items,
					$job->failed_items
				)
			);
		} else {
			WP_CLI::warning( sprintf( 'Import ended with status: %s', $job->status ) );
		}
	}

	/**
	 * Show import job progress.
	 *
	 * ## OPTIONS
	 *
	 * [<job_id>]
	 * : Job ID. Defaults to the most recent job.
	 */
	public function status( $args, $assoc_args ) {
		if ( ! empty( $args[0] ) ) {
			$job = WXR_Import_Job::get( absint( $args[0] ) );
		} else {
			$jobs = ( new WXR_Import_Job_Repository() )->list_recent( 1 );
			if ( empty( $jobs ) ) {
				WP_CLI::error( 'No import jobs found.' );
			}
			$job = $jobs[0];
		}

		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$status = $job->to_status_array();
		WP_CLI::log( wp_json_encode( $status, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Cancel a running import job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job ID to cancel.
	 */
	public function cancel( $args, $assoc_args ) {
		$job = WXR_Import_Job::get( absint( $args[0] ) );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$job->set_status( 'cancelled' );
		$repository = new WXR_Import_Job_Repository();
		$repository->save( $job );

		WP_CLI::success( sprintf( 'Job %d cancelled.', $job->id ) );
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
	 */
	public function list_jobs( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10;
		$jobs  = ( new WXR_Import_Job_Repository() )->list_recent( $limit );

		if ( empty( $jobs ) ) {
			WP_CLI::log( 'No jobs found.' );
			return;
		}

		foreach ( $jobs as $job ) {
			WP_CLI::log(
				sprintf(
					'#%d [%s] %d%% — posts %d/%d',
					$job->id,
					$job->status,
					$job->percent_complete(),
					$job->processed_posts,
					$job->total_posts
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
	 */
	public function report( $args, $assoc_args ) {
		$job = WXR_Import_Job::get( absint( $args[0] ) );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		WP_CLI::log( wp_json_encode( $job->to_status_array(), JSON_PRETTY_PRINT ) );

		foreach ( $job->get_recent_log( 50 ) as $entry ) {
			WP_CLI::log( sprintf( '[%s] %s', $entry['level'], $entry['message'] ) );
		}
	}

	/**
	 * Clean up abandoned chunk upload directories.
	 */
	public function clean( $args, $assoc_args ) {
		WXR_Import_UI::cleanup_abandoned_chunks();
		WP_CLI::success( 'Temporary upload directories cleaned.' );
	}
}
