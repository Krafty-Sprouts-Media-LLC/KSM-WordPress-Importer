<?php
/**
 * Filename: class-wxr-import-ui.php
 * Author: wordpressdotorg, rmccue
 * Created: 2015-01-01
 * Version: 2.0.1
 * Last Modified: 2025-12-30
 * Description: UI handler for WordPress XML importer
 */

class WXR_Import_UI {
	/**
	 * Should we fetch attachments?
	 *
	 * Set in {@see display_import_step}.
	 *
	 * @var bool
	 */
	protected $fetch_attachments = false;

	/**
	 * Import file attachment ID.
	 *
	 * @var int
	 */
	protected $id = 0;

	/**
	 * Import version.
	 *
	 * @var string
	 */
	protected $version = '1.0';

	/**
	 * Import authors/users.
	 *
	 * @var array
	 */
	protected $authors = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wxr_importer.ui.header', array( $this, 'show_updates_in_header' ) );
		add_action( 'wp_ajax_wxr-import-upload', array( $this, 'handle_async_upload' ) );
		add_action( 'wp_ajax_nopriv_wxr-import-upload', array( $this, 'handle_async_upload' ) );
		add_action( 'wp_ajax_wxr-cancel-import', array( $this, 'handle_cancel_import' ) );
		add_filter( 'upload_mimes', array( $this, 'add_mime_type_xml' ) );
	}

	/**
	 * Add .xml files as supported format in the uploader.
	 *
	 * @param array $mimes Already supported mime types.
	 */
	public function add_mime_type_xml( $mimes ) {
		$mimes = array_merge( $mimes, array( 'xml' => 'application/xml' ) );

		return $mimes;
	}

	/**
	 * Show an update notice in the importer header.
	 */
	public function show_updates_in_header() {
		// Check for updates too.
		$updates = get_plugin_updates();
		$plugin_file = dirname( __FILE__ ) . '/plugin.php';
		$basename = plugin_basename( $plugin_file );
		if ( empty( $updates[ $basename ] ) ) {
			return;
		}

		$message = sprintf(
			esc_html__( 'A new version of this importer is available. Please update to version %s to ensure compatibility with newer export files.', 'wordpress-importer' ),
			$updates[ $basename ]->update->new_version
		);

		$args = array(
			'action' => 'upgrade-plugin',
			'plugin' => $basename,
		);
		$url = add_query_arg( $args, self_admin_url( 'update.php' ) );
		$url = wp_nonce_url( $url, 'upgrade-plugin_' . $basename );
		$link = sprintf( '<a href="%s" class="button">%s</a>', $url, esc_html__( 'Update Now', 'wordpress-importer' ) );

		printf( '<div class="error"><p>%s</p><p>%s</p></div>', $message, $link );
	}

	/**
	 * Get the URL for the importer.
	 *
	 * @param int $step Go to step rather than start.
	 */
	protected function get_url( $step = 0 ) {
		$path = 'admin.php?import=wordpress';
		if ( $step ) {
			$path = add_query_arg( 'step', (int) $step, $path );
		}
		return admin_url( $path );
	}

	protected function display_error( WP_Error $err, $step = 0 ) {
		$this->render_header();

		echo '<p><strong>' . esc_html__( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
		echo esc_html( $err->get_error_message() );
		echo '</p>';
		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( $this->get_url( $step ) ),
			esc_html__( 'Try Again', 'wordpress-importer' )
		);

		$this->render_footer();
	}

	/**
	 * Handle load event for the importer.
	 */
	public function on_load() {
		// Skip outputting the header on our import page, so we can handle it.
		$_GET['noheader'] = true;
	}

	/**
	 * Render the import page.
	 */
	public function dispatch() {
		// Ensure only users with import capability can access any step
		if ( ! current_user_can( 'import' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to import content.', 'wordpress-importer' ),
				esc_html__( 'Permission Denied', 'wordpress-importer' ),
				array( 'response' => 403 )
			);
		}

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
		switch ( $step ) {
			case 0:
				$this->display_intro_step();
				break;
			case 1:
				check_admin_referer( 'import-upload' );
				$this->display_author_step();
				break;
			case 2:
				$this->display_import_step();
				break;
		}
	}

	/**
	 * Render the importer header.
	 */
	protected function render_header() {
		require __DIR__ . '/templates/header.php';
	}

	/**
	 * Render the importer footer.
	 */
	protected function render_footer() {
		require __DIR__ . '/templates/footer.php';
	}

	/**
	 * Display introductory text and file upload form
	 */
	protected function display_intro_step() {
		require __DIR__ . '/templates/intro.php';
	}

	protected function render_upload_form() {
		/**
		 * Filter the maximum allowed upload size for import files.
		 *
		 * @since 2.3.0
		 *
		 * @see wp_max_upload_size()
		 *
		 * @param int $max_upload_size Allowed upload size. Default 1 MB.
		 */
		$max_upload_size = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			$error = '<div class="error inline"><p>';
			$error .= esc_html__(
				'Before you can upload your import file, you will need to fix the following error:',
				'wordpress-importer'
			);
			$error .= sprintf( '<p><strong>%s</strong></p></div>', $upload_dir['error'] );
			echo $error;
			return;
		}

		// Queue the JS needed for the page
		// Ensure media scripts are loaded (needed for wp.Uploader and wp.media)
		wp_enqueue_media();
		
		// Get plugin file path and use plugins_url() - the WordPress way
		// class-wxr-import-ui.php is in the plugin root, so dirname(__FILE__) gives us the plugin directory
		$plugin_file = dirname( __FILE__ ) . '/plugin.php';
		$url = plugins_url( 'assets/intro.js', $plugin_file );
		$deps = array(
			'jquery',
			'underscore',
			'wp-util',
			'wp-backbone',
			'wp-plupload',
			'media-upload',
		);
		wp_enqueue_script( 'import-upload', $url, $deps, false, true );

		// Set uploader settings
		wp_plupload_default_settings();
		$settings = array(
			'l10n' => array(
				'frameTitle' => esc_html__( 'Select', 'wordpress-importer' ),
				'buttonText' => esc_html__( 'Import', 'wordpress-importer' ),
			),
			'next_url' => wp_nonce_url( $this->get_url( 1 ), 'import-upload' ) . '&id={id}',
			'plupload' => array(
				'filters' => array(
					'max_file_size' => $max_upload_size . 'b',
					'mime_types'    => array(
						array(
							'title'      => esc_html__( 'XML files', 'wordpress-importer' ),
							'extensions' => 'xml',
						),
					),
				),
				'url' => admin_url( 'admin-ajax.php' ),
				'file_data_name' => 'import',
				'multipart_params' => array(
					'action'   => 'wxr-import-upload',
					'_wpnonce' => wp_create_nonce( 'wxr-import-upload' ),
				),
			),
		);
		wp_localize_script( 'import-upload', 'importUploadSettings', $settings );

		// Use WXR_IMPORTER_URL constant for consistent asset loading
		wp_enqueue_style( 'wxr-import-upload', WXR_IMPORTER_URL . 'assets/intro.css', array(), '2.0.1' );

		// Load the template
		remove_action( 'post-plupload-upload-ui', 'media_upload_flash_bypass' );
		require __DIR__ . '/templates/upload.php';
		add_action( 'post-plupload-upload-ui', 'media_upload_flash_bypass' );
	}

	/**
	 * Display the author picker (or upload errors).
	 */
	protected function display_author_step() {
		// Preliminary XML parse can be slow on large files
		set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		if ( isset( $_REQUEST['id'] ) ) {
			$err = $this->handle_select( wp_unslash( $_REQUEST['id'] ) );
		} elseif ( isset( $_POST['local_file'] ) ) {
			$err = $this->handle_local_file( wp_unslash( $_POST['local_file'] ) );
		} else {
			$err = $this->handle_upload();
		}

		if ( is_wp_error( $err ) ) {
			$this->display_error( $err );
			return;
		}

		$data = $this->get_data_for_attachment( $this->id );
		if ( is_wp_error( $data ) ) {
			$this->display_error( $data );
			return;
		}

		require __DIR__ . '/templates/select-options.php';
	}

	/**
	 * Handle a large XML file that already exists on the server filesystem.
	 *
	 * The file is registered as a WordPress attachment so the rest of the
	 * import flow (which works from attachment IDs) works unchanged.
	 *
	 * @param string $path Absolute path to the XML file supplied by the user.
	 * @return true|WP_Error
	 */
	protected function handle_local_file( $path ) {
		// Sanitise and validate the path
		$path = trim( $path );

		// Strip surrounding quotes — users often paste paths with quote marks
		$path = trim( $path, '"\'` ' );

		if ( empty( $path ) ) {
			return new WP_Error(
				'wxr_importer.local.empty_path',
				__( 'Please enter a file path.', 'wordpress-importer' )
			);
		}

		// Resolve to a real path (resolves ../ traversal attempts)
		$real = realpath( $path );
		if ( false === $real ) {
			return new WP_Error(
				'wxr_importer.local.not_found',
				sprintf(
					__( 'File not found: %s', 'wordpress-importer' ),
					'<code>' . esc_html( $path ) . '</code>'
				)
			);
		}

		// Restrict to inside the uploads directory only — tighter than ABSPATH,
		// prevents pointing at wp-config.php, plugin files, or anything sensitive
		// inside the WordPress root.
		$upload_dir = wp_upload_dir();
		$uploads_base = realpath( $upload_dir['basedir'] );

		if ( ! $uploads_base || strpos( $real, $uploads_base ) !== 0 ) {
			return new WP_Error(
				'wxr_importer.local.outside_uploads',
				sprintf(
					__( 'The file must be inside the uploads directory: %s', 'wordpress-importer' ),
					'<code>' . esc_html( $upload_dir['basedir'] ) . '</code>'
				)
			);
		}

		if ( ! is_readable( $real ) ) {
			return new WP_Error(
				'wxr_importer.local.not_readable',
				__( 'The file exists but is not readable. Check file permissions.', 'wordpress-importer' )
			);
		}

		// Quick sanity check — must look like XML
		$head = file_get_contents( $real, false, null, 0, 100 );
		if ( false === $head || ( strpos( $head, '<?xml' ) === false && strpos( $head, '<rss' ) === false ) ) {
			return new WP_Error(
				'wxr_importer.local.not_xml',
				__( 'The file does not appear to be a valid WordPress XML export.', 'wordpress-importer' )
			);
		}

		// Register as a WP attachment so the rest of the flow works unchanged.
		// We use the file in-place — no copy needed.
		$url = '';

		// Build a URL if the file is inside the uploads directory
		if ( strpos( $real, $upload_dir['basedir'] ) === 0 ) {
			$url = $upload_dir['baseurl'] . str_replace(
				$upload_dir['basedir'],
				'',
				$real
			);
		}

		$attachment = array(
			'post_title'     => basename( $real ),
			'post_content'   => $real,   // store path in post_content for reference
			'post_mime_type' => 'application/xml',
			'post_status'    => 'private',
			'guid'           => $url ?: $real,
		);

		$id = wp_insert_attachment( $attachment, $real );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// Store the actual file path as attached file meta so get_attached_file() works
		update_attached_file( $id, $real );

		$this->id = $id;
		return true;
	}

	/**
	 * Handles the WXR upload and initial parsing of the file to prepare for
	 * displaying author import options
	 *
	 * @return bool|WP_Error True on success, error object otherwise.
	 */
	protected function handle_upload() {
		// Ensure wp_import_handle_upload function exists (from wp-admin/includes/import.php)
		if ( ! function_exists( 'wp_import_handle_upload' ) ) {
			require_once ABSPATH . '/wp-admin/includes/import.php';
		}

		if ( ! function_exists( 'wp_import_handle_upload' ) ) {
			return new WP_Error(
				'wxr_importer.upload.missing_function',
				__( 'WordPress import functions are not available. Please ensure WordPress is properly installed.', 'wordpress-importer' )
			);
		}

		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) ) {
			return new WP_Error( 'wxr_importer.upload.error', esc_html( $file['error'] ), $file );
		} elseif ( ! file_exists( $file['file'] ) ) {
			$message = sprintf(
				esc_html__( 'The export file could not be found at %s. It is likely that this was caused by a permissions problem.', 'wordpress-importer' ),
				'<code>' . esc_html( $file['file'] ) . '</code>'
			);
			return new WP_Error( 'wxr_importer.upload.no_file', $message, $file );
		}

		$this->id = (int) $file['id'];
		return true;
	}

	/**
	 * Handle an async upload.
	 *
	 * Triggers on `async-upload.php?action=wxr-import-upload` to handle
	 * Plupload requests from the importer.
	 */
	public function handle_async_upload() {
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		send_nosniff_header();
		nocache_headers();

		// Verify nonce
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wxr-import-upload' ) ) {
			echo wp_json_encode( array(
				'success' => false,
				'data'    => array(
					'message'  => __( 'Security check failed. Please refresh the page and try again.', 'wordpress-importer' ),
					'filename' => isset( $_FILES['import']['name'] ) ? sanitize_file_name( $_FILES['import']['name'] ) : '',
				),
			) );
			exit;
		}

		/*
		 * This function does not use wp_send_json_success() / wp_send_json_error()
		 * as the html4 Plupload handler requires a text/html content-type for older IE.
		 * See https://core.trac.wordpress.org/ticket/31037
		 */

		// Check if file was uploaded
		if ( ! isset( $_FILES['import'] ) || ! isset( $_FILES['import']['name'] ) ) {
			echo wp_json_encode( array(
				'success' => false,
				'data'    => array(
					'message'  => __( 'No file was uploaded.', 'wordpress-importer' ),
					'filename' => '',
				),
			) );
			exit;
		}

		$filename = wp_unslash( $_FILES['import']['name'] );
		$filename = sanitize_file_name( $filename );

		if ( ! current_user_can( 'upload_files' ) ) {
			echo wp_json_encode( array(
				'success' => false,
				'data'    => array(
					'message'  => __( 'You do not have permission to upload files.', 'wordpress-importer' ),
					'filename' => $filename,
				),
			) );

			exit;
		}

		// Ensure wp_import_handle_upload function exists
		if ( ! function_exists( 'wp_import_handle_upload' ) ) {
			require_once ABSPATH . '/wp-admin/includes/import.php';
		}

		if ( ! function_exists( 'wp_import_handle_upload' ) ) {
			echo wp_json_encode( array(
				'success' => false,
				'data'    => array(
					'message'  => __( 'WordPress import functions are not available.', 'wordpress-importer' ),
					'filename' => $filename,
				),
			) );
			exit;
		}

		$file = wp_import_handle_upload();
		if ( is_wp_error( $file ) ) {
			echo wp_json_encode( array(
				'success' => false,
				'data'    => array(
					'message'  => $file->get_error_message(),
					'filename' => $filename,
				),
			) );

			exit;
		}

		if ( ! isset( $file['id'] ) || ! isset( $file['file'] ) ) {
			echo wp_json_encode( array(
				'success' => false,
				'data'    => array(
					'message'  => __( 'Upload failed: Invalid file data returned.', 'wordpress-importer' ),
					'filename' => $filename,
				),
			) );
			exit;
		}

		$attachment = wp_prepare_attachment_for_js( $file['id'] );
		if ( ! $attachment ) {
			echo wp_json_encode( array(
				'success' => false,
				'data'    => array(
					'message'  => __( 'Upload failed: Could not prepare attachment data.', 'wordpress-importer' ),
					'filename' => $filename,
				),
			) );
			exit;
		}

		echo wp_json_encode( array(
			'success' => true,
			'data'    => $attachment,
		) );

		exit;
	}

	/**
	 * Handle a WXR file selected from the media browser.
	 *
	 * @param int|string $id Media item to import from.
	 * @return bool|WP_Error True on success, error object otherwise.
	 */
	protected function handle_select( $id ) {
		if ( ! is_numeric( $id ) || intval( $id ) < 1 ) {
			return new WP_Error(
				'wxr_importer.upload.invalid_id',
				__( 'Invalid media item ID.', 'wordpress-importer' ),
				compact( 'id' )
			);
		}

		$id = (int) $id;

		$attachment = get_post( $id );
		if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
			return new WP_Error(
				'wxr_importer.upload.invalid_id',
				__( 'Invalid media item ID.', 'wordpress-importer' ),
				compact( 'id', 'attachment' )
			);
		}

		if ( ! current_user_can( 'read_post', $attachment->ID ) ) {
			return new WP_Error(
				'wxr_importer.upload.sorry_dave',
				__( 'You cannot access the selected media item.', 'wordpress-importer' ),
				compact( 'id', 'attachment' )
			);
		}

		$this->id = $id;
		return true;
	}

	/**
	 * Get preliminary data for an import file.
	 *
	 * This is a quick pre-parse to verify the file and grab authors from it.
	 *
	 * @param int $id Media item ID.
	 * @return WXR_Import_Info|WP_Error Import info instance on success, error otherwise.
	 */
	protected function get_data_for_attachment( $id ) {
		$existing = get_post_meta( $id, '_wxr_import_info' );
		if ( ! empty( $existing ) ) {
			$data = $existing[0];
			$this->authors = $data->users;
			$this->version = $data->version;
			return $data;
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error(
				'wxr_importer.upload.file_not_found',
				__( 'The import file could not be found.', 'wordpress-importer' ),
				compact( 'id' )
			);
		}

		// Validate file is readable
		if ( ! is_readable( $file ) ) {
			return new WP_Error(
				'wxr_importer.upload.file_not_readable',
				__( 'The import file is not readable. Please check file permissions.', 'wordpress-importer' ),
				compact( 'id' )
			);
		}

		// Basic validation: check if file starts with XML declaration or contains WXR elements
		// Don't rely on extension as WordPress may change it during upload
		$file_content = file_get_contents( $file, false, null, 0, 500 );
		if ( false === $file_content ) {
			return new WP_Error(
				'wxr_importer.upload.file_read_error',
				__( 'Could not read the import file.', 'wordpress-importer' ),
				compact( 'id' )
			);
		}

		// Check if it looks like an XML/WXR file
		// WordPress exports start with <?xml and contain <rss> or <wxr> elements
		$is_xml = preg_match( '/<\?xml/i', $file_content );
		$has_wxr = preg_match( '/<rss|<wxr/i', $file_content );
		
		if ( ! $is_xml && ! $has_wxr ) {
			return new WP_Error(
				'wxr_importer.upload.invalid_file_type',
				__( 'Invalid file type. Please upload a valid WordPress XML export file.', 'wordpress-importer' ),
				compact( 'id' )
			);
		}

		// Use HTML logger for preliminary information (not streaming yet)
		$importer = new WXR_Importer( $this->get_import_options() );
		$logger = new WP_Importer_Logger_HTML();
		$importer->set_logger( $logger );
		
		$data = $importer->get_preliminary_information( $file );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Cache the information on the upload
		// Note: update_post_meta returns false if the value is unchanged (not just on failure),
		// so we check get_post_meta to confirm a genuine write failure.
		update_post_meta( $id, '_wxr_import_info', $data );
		$check = get_post_meta( $id, '_wxr_import_info', true );
		if ( empty( $check ) ) {
			return new WP_Error(
				'wxr_importer.upload.failed_save_meta',
				__( 'Could not cache information on the import.', 'wordpress-importer' ),
				compact( 'id' )
			);
		}

		$this->authors = $data->users;
		$this->version = $data->version;

		return $data;
	}

	/**
	 * Display the actual import step.
	 */
	protected function display_import_step() {
		$args = wp_unslash( $_POST );
		if ( ! isset( $args['import_id'] ) ) {
			// Missing import ID.
			$error = new WP_Error( 'wxr_importer.import.missing_id', __( 'Missing import file ID from request.', 'wordpress-importer' ) );
			$this->display_error( $error );
			return;
		}

		// Check the nonce.
		check_admin_referer( sprintf( 'wxr.import:%d', (int) $args['import_id'] ) );

		$this->id = (int) $args['import_id'];
		$file = get_attached_file( $this->id );

		$mapping = $this->get_author_mapping( $args );
		$fetch_attachments = ( ! empty( $args['fetch_attachments'] ) && $this->allow_fetch_attachments() );

		// Set our settings
		$settings = compact( 'mapping', 'fetch_attachments' );
		update_post_meta( $this->id, '_wxr_import_settings', $settings );

		// Time to run the import!
		set_time_limit( 0 );

		// Ensure we're not buffered.
		wp_ob_end_flush_all();
		flush();

		$data = get_post_meta( $this->id, '_wxr_import_info', true );
		require __DIR__ . '/templates/import.php';
	}

	/**
	 * Run an import, and send an event-stream response.
	 *
	 * Streams logs and success messages to the browser to allow live status
	 * and updates.
	 */
	public function stream_import() {
		// Turn off PHP output compression
		$previous = error_reporting( error_reporting() ^ E_WARNING );
		ini_set( 'output_buffering', 'off' );
		ini_set( 'zlib.output_compression', false );
		error_reporting( $previous );

		// Prevent caching for SSE
		nocache_headers();
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		
		if ( isset( $GLOBALS['is_nginx'] ) && $GLOBALS['is_nginx'] ) {
			// Setting this header instructs Nginx to disable fastcgi_buffering
			// and disable gzip for this request.
			header( 'Content-Encoding: none' );
		}

		// Start the event stream.
		header( 'Content-Type: text/event-stream; charset=utf-8' );

		$this->id = (int) wp_unslash( $_REQUEST['id'] );
		$settings = get_post_meta( $this->id, '_wxr_import_settings', true );
		if ( empty( $settings ) ) {
			// Tell the browser to stop reconnecting.
			status_header( 204 );
			exit;
		}

		// Verify the user has permission to run this import
		if ( ! current_user_can( 'import' ) ) {
			$this->emit_sse_message( array(
				'action' => 'error',
				'error'  => __( 'You do not have permission to import content.', 'wordpress-importer' ),
			) );
			exit;
		}

		// 2KB padding for IE
		echo ':' . str_repeat( ' ', 2048 ) . "\n\n";

		// Time to run the import!
		set_time_limit( 0 );

		// Ensure we're not buffered.
		wp_ob_end_flush_all();
		flush();

		// Send initial connection message to confirm stream is working
		$this->emit_sse_message( array(
			'action' => 'connected',
			'message' => __( 'Import stream connected. Starting import...', 'wordpress-importer' ),
		) );

		$mapping = $settings['mapping'];
		$this->fetch_attachments = (bool) $settings['fetch_attachments'];

		$importer = $this->get_importer();
		if ( ! empty( $mapping['mapping'] ) ) {
			$importer->set_user_mapping( $mapping['mapping'] );
		}
		if ( ! empty( $mapping['slug_overrides'] ) ) {
			$importer->set_user_slug_overrides( $mapping['slug_overrides'] );
		}

		// Are we allowed to create users?
		if ( ! $this->allow_create_users() ) {
			add_filter( 'wxr_importer.pre_process.user', '__return_null' );
		}

		// Keep track of our progress
		add_action( 'wxr_importer.processed.post', array( $this, 'imported_post' ), 10, 2 );
		add_action( 'wxr_importer.process_failed.post', array( $this, 'imported_post' ), 10, 2 );
		add_action( 'wxr_importer.process_already_imported.post', array( $this, 'already_imported_post' ), 10, 2 );
		add_action( 'wxr_importer.process_skipped.post', array( $this, 'already_imported_post' ), 10, 2 );
		add_action( 'wxr_importer.processed.comment', array( $this, 'imported_comment' ) );
		add_action( 'wxr_importer.process_already_imported.comment', array( $this, 'imported_comment' ) );
		add_action( 'wxr_importer.processed.term', array( $this, 'imported_term' ) );
		add_action( 'wxr_importer.process_failed.term', array( $this, 'imported_term' ) );
		add_action( 'wxr_importer.process_already_imported.term', array( $this, 'imported_term' ) );
		add_action( 'wxr_importer.processed.user', array( $this, 'imported_user' ) );
		add_action( 'wxr_importer.process_failed.user', array( $this, 'imported_user' ) );

		// Clean up some memory
		unset( $settings );

		// Flush once more.
		flush();

		$file = get_attached_file( $this->id );
		if ( ! $file || ! file_exists( $file ) ) {
			$this->emit_sse_message( array(
				'action' => 'error',
				'error' => __( 'Import file not found.', 'wordpress-importer' ),
			) );
			exit;
		}

		// Send start message
		$this->emit_sse_message( array(
			'action' => 'start',
			'message' => __( 'Beginning import...', 'wordpress-importer' ),
		) );

		$err = $importer->import( $file );

		// Remove the settings to stop future reconnects.
		delete_post_meta( $this->id, '_wxr_import_settings' );

		// Let the browser know we're done.
		$complete = array(
			'action' => 'complete',
			'error' => false,
		);
		if ( is_wp_error( $err ) ) {
			$complete['error'] = $err->get_error_message();
		}

		$this->emit_sse_message( $complete );
		exit;
	}

	/**
	 * Handle a cancel import request.
	 *
	 * Clears the import settings meta so the SSE stream won't auto-resume
	 * if the browser reconnects. Posts already imported are kept.
	 */
	public function handle_cancel_import() {
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wordpress-importer' ) ), 403 );
		}

		check_ajax_referer( 'wxr-cancel-import' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import ID.', 'wordpress-importer' ) ) );
		}

		// Remove the settings — this stops the SSE endpoint from processing
		// if the browser reconnects, and marks the import as no longer active.
		delete_post_meta( $id, '_wxr_import_settings' );

		wp_send_json_success( array( 'message' => __( 'Import cancelled.', 'wordpress-importer' ) ) );
	}

	/**
	 * Get the importer instance.
	 *
	 * @return WXR_Importer
	 */
	protected function get_importer() {
		$importer = new WXR_Importer( $this->get_import_options() );
		$logger = new WP_Importer_Logger_ServerSentEvents();
		$importer->set_logger( $logger );

		return $importer;
	}

	/**
	 * Get options for the importer.
	 *
	 * @return array Options to pass to WXR_Importer::__construct
	 */
	protected function get_import_options() {
		$options = array(
			'fetch_attachments' => $this->fetch_attachments,
			'default_author'    => get_current_user_id(),
		);

		/**
		 * Filter the importer options used in the admin UI.
		 *
		 * @param array $options Options to pass to WXR_Importer::__construct
		 */
		return apply_filters( 'wxr_importer.admin.import_options', $options );
	}

	/**
	 * Display import options for an individual author. That is, either create
	 * a new user based on import info or map to an existing user
	 *
	 * @param int $index Index for each author in the form
	 * @param array $author Author information, e.g. login, display name, email
	 */
	protected function author_select( $index, $author ) {
		esc_html_e( 'Import author:', 'wordpress-importer' );
		$supports_extras = version_compare( $this->version, '1.0', '>' );

		if ( $supports_extras ) {
			$name = sprintf( '%s (%s)', $author['display_name'], $author['user_login'] );
		} else {
			$name = $author['display_name'];
		}
		echo ' <strong>' . esc_html( $name ) . '</strong><br />';

		if ( $supports_extras ) {
			echo '<div style="margin-left:18px">';
		}

		$create_users = $this->allow_create_users();
		if ( $create_users ) {
			if ( ! $supports_extras ) {
				esc_html_e( 'or create new user with login name:', 'wordpress-importer' );
				$value = '';
			} else {
				esc_html_e( 'as a new user:', 'wordpress-importer' );
				$value = sanitize_user( $author['user_login'], true );
			}

			printf(
				' <input type="text" name="user_new[%d]" value="%s" /><br />',
				$index,
				esc_attr( $value )
			);
		}

		if ( ! $create_users && $supports_extras ) {
			esc_html_e( 'assign posts to an existing user:', 'wordpress-importer' );
		} else {
			esc_html_e( 'or assign posts to an existing user:', 'wordpress-importer' );
		}

		wp_dropdown_users( array(
			'name' => sprintf( 'user_map[%d]', $index ),
			'multi' => true,
			'show_option_all' => __( '- Select -', 'wordpress-importer' ),
		));

		printf(
			'<input type="hidden" name="imported_authors[%d]" value="%s" />',
			(int) $index,
			esc_attr( $author['user_login'] )
		);

		// Keep the old ID for when we want to remap
		if ( isset( $author['ID'] ) ) {
			printf(
				'<input type="hidden" name="imported_author_ids[%d]" value="%d" />',
				(int) $index,
				esc_attr( $author['ID'] )
			);
		}

		if ( $supports_extras ) {
			echo '</div>';
		}
	}

	/**
	 * Decide whether or not the importer should attempt to download attachment files.
	 * Default is true, can be filtered via import_allow_fetch_attachments. The choice
	 * made at the import options screen must also be true, false here hides that checkbox.
	 *
	 * @return bool True if downloading attachments is allowed
	 */
	protected function allow_fetch_attachments() {
		return apply_filters( 'import_allow_fetch_attachments', true );
	}

	/**
	 * Decide whether or not the importer is allowed to create users.
	 * Default is true, can be filtered via import_allow_create_users
	 *
	 * @return bool True if creating users is allowed
	 */
	protected function allow_create_users() {
		return apply_filters( 'import_allow_create_users', true );
	}

	/**
	 * Get mapping data from request data.
	 *
	 * Parses form request data into an internally usable mapping format.
	 *
	 * @param array $args Raw (UNSLASHED) POST data to parse.
	 * @return array Map containing `mapping` and `slug_overrides` keys.
	 */
	protected function get_author_mapping( $args ) {
		if ( ! isset( $args['imported_authors'] ) ) {
			return array(
				'mapping'        => array(),
				'slug_overrides' => array(),
			);
		}

		$map        = isset( $args['user_map'] ) ? (array) $args['user_map'] : array();
		$new_users  = isset( $args['user_new'] ) ? $args['user_new'] : array();
		$old_ids    = isset( $args['imported_author_ids'] ) ? (array) $args['imported_author_ids'] : array();

		// Store the actual map.
		$mapping = array();
		$slug_overrides = array();

		foreach ( (array) $args['imported_authors'] as $i => $old_login ) {
			$old_id = isset( $old_ids[ $i ] ) ? (int) $old_ids[ $i ] : false;

			if ( ! empty( $map[ $i ] ) ) {
				$user = get_user_by( 'id', (int) $map[ $i ] );

				if ( isset( $user->ID ) ) {
					$mapping[] = array(
						'old_slug' => $old_login,
						'old_id'   => $old_id,
						'new_id'   => $user->ID,
					);
				}
			} elseif ( ! empty( $new_users[ $i ] ) ) {
				if ( $new_users[ $i ] !== $old_login ) {
					$slug_overrides[ $old_login ] = $new_users[ $i ];
				}
			}
		}

		return compact( 'mapping', 'slug_overrides' );
	}

	/**
	 * Emit a Server-Sent Events message.
	 *
	 * @param mixed $data Data to be JSON-encoded and sent in the message.
	 */
	protected function emit_sse_message( $data ) {
		echo "event: message\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";

		// Extra padding.
		echo ':' . str_repeat( ' ', 2048 ) . "\n\n";

		flush();
	}

	/**
	 * Send message when a post has been imported.
	 *
	 * @param int $id Post ID.
	 * @param array $data Post data saved to the DB.
	 */
	public function imported_post( $id, $data ) {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => ( $data['post_type'] === 'attachment' ) ? 'media' : 'posts',
			'delta'  => 1,
		));
	}

	/**
	 * Send message when a post is marked as already imported.
	 *
	 * @param array $data Post data saved to the DB.
	 */
	public function already_imported_post( $data ) {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => ( $data['post_type'] === 'attachment' ) ? 'media' : 'posts',
			'delta'  => 1,
		));
	}

	/**
	 * Send message when a comment has been imported.
	 */
	public function imported_comment() {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => 'comments',
			'delta'  => 1,
		));
	}

	/**
	 * Send message when a term has been imported.
	 */
	public function imported_term() {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => 'terms',
			'delta'  => 1,
		));
	}

	/**
	 * Send message when a user has been imported.
	 */
	public function imported_user() {
		$this->emit_sse_message( array(
			'action' => 'updateDelta',
			'type'   => 'users',
			'delta'  => 1,
		));
	}
}
