<?php
/**
 * AJAX endpoints for import upload, batches, and job control.
 *
 * @package Better_WordPress_Importer
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import AJAX controller.
 *
 * @since 1.2.0
 */
class Better_Import_Ajax {

	/**
	 * Register AJAX actions.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register() {
		$actions = array(
			'better-import-upload',
			'better-import-preflight',
			'better-import-start',
			'better-import-batch',
			'better-import-status',
			'better-import-pause',
			'better-import-resume',
			'better-import-cancel',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, str_replace( '-', '_', $action ) ) );
		}
	}

	/**
	 * Handle async WXR upload via Plupload.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function better_import_upload() {
		self::authorize();
		check_ajax_referer( 'better-import-upload', 'nonce' );

		if ( ! function_exists( 'wp_import_handle_upload' ) ) {
			require ABSPATH . 'wp-admin/includes/import.php';
		}

		$filename = '';
		if ( isset( $_REQUEST['name'] ) ) {
			$filename = sanitize_file_name( wp_unslash( $_REQUEST['name'] ) );
		} elseif ( ! empty( $_FILES['async-upload']['name'] ) ) {
			$filename = sanitize_file_name( wp_unslash( $_FILES['async-upload']['name'] ) );
		} elseif ( ! empty( $_FILES['import']['name'] ) ) {
			$filename = sanitize_file_name( wp_unslash( $_FILES['import']['name'] ) );
		}

		if ( Better_Chunked_Upload::is_chunk_request() ) {
			$result = Better_Chunked_Upload::handle_chunk( $filename );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			if ( ! empty( $result['partial'] ) ) {
				wp_send_json_success( $result );
			}

			wp_send_json_success(
				array(
					'attachment_id' => (int) $result['attachment_id'],
					'filename'      => isset( $result['filename'] ) ? $result['filename'] : $filename,
				)
			);
		}

		if ( empty( $_FILES['async-upload'] ) && empty( $_FILES['import'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded.', 'better-wordpress-importer' ) ) );
		}

		if ( ! empty( $_FILES['async-upload'] ) ) {
			$_FILES['import'] = $_FILES['async-upload'];
		}

		$file = wp_import_handle_upload();
		if ( isset( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => $file['error'] ) );
		}

		$uploaded_path = isset( $file['file'] ) ? $file['file'] : '';
		$format        = Better_Format_Detector::validate_for_import( $uploaded_path, $filename );
		if ( is_wp_error( $format ) ) {
			if ( $uploaded_path && file_exists( $uploaded_path ) ) {
				wp_delete_file( $uploaded_path );
			}
			if ( ! empty( $file['id'] ) ) {
				wp_delete_attachment( (int) $file['id'], true );
			}
			wp_send_json_error( array( 'message' => $format->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'attachment_id' => isset( $file['id'] ) ? (int) $file['id'] : 0,
				'filename'      => isset( $file['file'] ) ? basename( $file['file'] ) : $filename,
			)
		);
	}

	/**
	 * Run a preflight scan without creating a job.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function better_import_preflight() {
		self::authorize();

		$file = self::resolve_uploaded_file();
		if ( is_wp_error( $file ) ) {
			wp_send_json_error( array( 'message' => $file->get_error_message() ) );
		}

		$preflight = new Better_Preflight();
		$result    = $preflight->scan( $file['path'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'attachment_id' => $file['attachment_id'],
				'file_path'     => $file['path'],
				'filename'      => basename( $file['path'] ),
				'preflight'     => $result['preflight'],
				'entity_total'  => count( $result['manifest'] ),
			)
		);
	}

	/**
	 * Create a job and seed the queue.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function better_import_start() {
		self::authorize();
		check_ajax_referer( 'better-import-start', 'nonce' );

		$file = self::resolve_uploaded_file();
		if ( is_wp_error( $file ) ) {
			wp_send_json_error( array( 'message' => $file->get_error_message() ) );
		}

		$unknown_post_type = isset( $_POST['unknown_post_type_strategy'] ) ? sanitize_key( wp_unslash( $_POST['unknown_post_type_strategy'] ) ) : 'import_as_draft';
		if ( ! in_array( $unknown_post_type, array( 'import_as_draft', 'skip', 'fail' ), true ) ) {
			$unknown_post_type = 'import_as_draft';
		}

		$meta_write_mode = isset( $_POST['meta_write_mode'] ) ? sanitize_key( wp_unslash( $_POST['meta_write_mode'] ) ) : 'bulk';
		if ( ! in_array( $meta_write_mode, array( 'bulk', 'hooked' ), true ) ) {
			$meta_write_mode = 'bulk';
		}

		$options = array(
			'fetch_attachments'          => ! empty( $_POST['fetch_attachments'] ),
			'default_author'             => isset( $_POST['default_author'] ) ? absint( $_POST['default_author'] ) : get_current_user_id(),
			'job_label'                  => isset( $_POST['job_label'] ) ? sanitize_text_field( wp_unslash( $_POST['job_label'] ) ) : '',
			'user_id_map'                => self::sanitize_user_mapping(),
			'unknown_post_type_strategy' => $unknown_post_type,
			'meta_write_mode'            => $meta_write_mode,
		);

		$job = Better_Import_Job::create( $file['attachment_id'], $file['path'], $options );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'job_id' => $job->id,
				'nonce'  => self::job_nonce( $job->id ),
				'status' => $job->to_status_array(),
			)
		);
	}

	/**
	 * Process one import batch.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function better_import_batch() {
		self::authorize();

		$job = self::get_authorized_job_from_request( 'POST' );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 403 );
		}

		set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$processor = new Better_Import_Processor();
		$result    = $processor->process_batch( $job->id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$job = ( new Better_Import_Job_Repository() )->get( $job->id );
		$payload = is_array( $result ) ? $result : array();
		$payload['status'] = $job ? $job->to_status_array( self::get_log_after_id() ) : array();

		wp_send_json_success( $payload );
	}

	/**
	 * Return persisted job status.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function better_import_status() {
		self::authorize();

		$job = self::get_authorized_job_from_request( 'GET' );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 403 );
		}

		wp_send_json_success( $job->to_status_array( self::get_log_after_id() ) );
	}

	/**
	 * Pause a running job.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function better_import_pause() {
		self::authorize();

		$job = self::get_authorized_job_from_request( 'POST' );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 403 );
		}

		$job->pause();
		( new Better_Import_Job_Repository() )->save( $job );
		delete_transient( 'better_import_job_lock_' . $job->id );

		$logger = new Better_Logger( $job->id );
		$logger->info( __( 'Import paused.', 'better-wordpress-importer' ) );

		wp_send_json_success( $job->to_status_array( self::get_log_after_id() ) );
	}

	/**
	 * Resume a paused job.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function better_import_resume() {
		self::authorize();

		$job = self::get_authorized_job_from_request( 'POST' );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 403 );
		}

		$job->resume();
		( new Better_Import_Job_Repository() )->save( $job );

		$logger = new Better_Logger( $job->id );
		$logger->info( __( 'Import resumed.', 'better-wordpress-importer' ) );

		wp_send_json_success( $job->to_status_array( self::get_log_after_id() ) );
	}

	/**
	 * Cancel a job.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function better_import_cancel() {
		self::authorize();

		$job = self::get_authorized_job_from_request( 'POST' );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 403 );
		}

		$job->cancel();
		( new Better_Import_Job_Repository() )->save( $job );
		delete_transient( 'better_import_job_lock_' . $job->id );

		$logger = new Better_Logger( $job->id );
		$logger->info( __( 'Import cancelled.', 'better-wordpress-importer' ) );

		wp_send_json_success( $job->to_status_array( self::get_log_after_id() ) );
	}

	/**
	 * Read the log cursor from an AJAX request.
	 *
	 * @since 1.5.0
	 *
	 * @return int
	 */
	protected static function get_log_after_id() {
		return isset( $_REQUEST['log_after_id'] ) ? absint( $_REQUEST['log_after_id'] ) : 0;
	}

	/**
	 * Ensure the current user may import.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected static function authorize() {
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'better-wordpress-importer' ) ), 403 );
		}
	}

	/**
	 * Sanitize source-author to destination-user mappings.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string, int>
	 */
	protected static function sanitize_user_mapping() {
		if ( empty( $_POST['user_mapping'] ) || ! is_array( $_POST['user_mapping'] ) ) {
			return array();
		}

		$mapping = array();
		$raw     = wp_unslash( $_POST['user_mapping'] );

		foreach ( $raw as $source_key => $user_id ) {
			$source_key = sanitize_text_field( (string) $source_key );
			$user_id    = absint( $user_id );

			if ( '' === $source_key || $user_id <= 0 || ! get_user_by( 'ID', $user_id ) ) {
				continue;
			}

			$mapping[ $source_key ] = $user_id;
		}

		return $mapping;
	}

	/**
	 * Load and authorize a job from the request.
	 *
	 * @since 1.2.0
	 *
	 * @param string $method HTTP method for reading job_id.
	 *
	 * @return Better_Import_Job|WP_Error
	 */
	protected static function get_authorized_job_from_request( $method ) {
		$source = 'GET' === strtoupper( $method ) ? $_GET : $_POST;
		$job_id = isset( $source['job_id'] ) ? absint( $source['job_id'] ) : 0;

		if ( $job_id <= 0 ) {
			return new WP_Error( 'better_importer.job.missing', __( 'Missing import job ID.', 'better-wordpress-importer' ) );
		}

		check_ajax_referer( self::job_nonce_action( $job_id ), 'nonce' );

		$job = ( new Better_Import_Job_Repository() )->get( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'better_importer.job.not_found', __( 'Import job not found.', 'better-wordpress-importer' ) );
		}

		if ( ! ( new Better_Import_Job_Repository() )->user_can_access( $job ) ) {
			return new WP_Error( 'better_importer.job.forbidden', __( 'You cannot access this import job.', 'better-wordpress-importer' ) );
		}

		return $job;
	}

	/**
	 * Resolve the WXR file path from the request.
	 *
	 * @since 1.2.0
	 *
	 * @return array{path:string,attachment_id:int}|WP_Error
	 */
	protected static function resolve_uploaded_file() {
		$attachment_id = isset( $_REQUEST['attachment_id'] ) ? absint( $_REQUEST['attachment_id'] ) : 0;
		$local_file    = isset( $_REQUEST['local_file'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['local_file'] ) ) : '';

		if ( $attachment_id > 0 ) {
			$path = get_attached_file( $attachment_id );
			if ( ! $path || ! is_readable( $path ) ) {
				return new WP_Error( 'better_importer.file.unreadable', __( 'The selected file could not be read.', 'better-wordpress-importer' ) );
			}

			return array(
				'path'          => wp_normalize_path( $path ),
				'attachment_id' => $attachment_id,
			);
		}

		if ( '' !== $local_file ) {
			$path = Better_Admin_UI::validate_local_file_path( $local_file );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			return array(
				'path'          => $path,
				'attachment_id' => 0,
			);
		}

		return new WP_Error( 'better_importer.file.missing', __( 'No import file was provided.', 'better-wordpress-importer' ) );
	}

	/**
	 * Nonce action for a job.
	 *
	 * @since 1.2.0
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return string
	 */
	public static function job_nonce_action( $job_id ) {
		return 'better-import.job:' . absint( $job_id );
	}

	/**
	 * Create a nonce for a job.
	 *
	 * @since 1.2.0
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return string
	 */
	public static function job_nonce( $job_id ) {
		return wp_create_nonce( self::job_nonce_action( $job_id ) );
	}
}
