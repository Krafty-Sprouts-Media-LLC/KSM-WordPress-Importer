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
	 * Whether the current import file was registered via handle_local_file().
	 * Local-path files are NOT deleted after import — the user placed them there.
	 * Browser-uploaded files ARE deleted after import — they're temporary.
	 *
	 * @var bool
	 */
	protected $is_local_file = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wxr_importer.ui.header', array( $this, 'show_updates_in_header' ) );
		add_action( 'wp_ajax_wxr-import-upload', array( $this, 'handle_async_upload' ) );
		add_action( 'wp_ajax_nopriv_wxr-import-upload', array( $this, 'handle_async_upload' ) );
		add_action( 'wp_ajax_wxr-cancel-import', array( $this, 'handle_cancel_import' ) );
		add_filter( 'upload_mimes', array( $this, 'add_mime_type_xml' ) );
		// Prevent WordPress from renaming .xml files to .xml.txt during upload
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'fix_xml_filetype' ), 10, 5 );
	}

	/**
	 * Add .xml files as supported format in the uploader.
	 *
	 * @param array $mimes Already supported mime types.
	 */
	public function add_mime_type_xml( $mimes ) {
		$mimes['xml'] = 'application/xml';
		$mimes['xml|xsl'] = 'text/xml';
		return $mimes;
	}

	/**
	 * Fix WordPress incorrectly flagging .xml files as unsafe and renaming them to .txt.
	 * WordPress's MIME sniffing can misidentify XML files — this corrects it for our uploader.
	 *
	 * @param array       $data     Values for the extension, mime type, and corrected filename.
	 * @param string      $file     Full path to the file.
	 * @param string      $filename The name of the file.
	 * @param array|null  $mimes    Array of mime types keyed by their file extension regex.
	 * @param string|bool $real_mime The actual mime type or false if the type cannot be determined.
	 * @return array
	 */
	public function fix_xml_filetype( $data, $file, $filename, $mimes, $real_mime = false ) {
		if ( empty( $data['ext'] ) && empty( $data['type'] ) ) {
			$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			if ( $ext === 'xml' ) {
				$data['ext']             = 'xml';
				$data['type']            = 'application/xml';
				$data['proper_filename'] = $filename;
			}
		}
		return $data;
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
				if ( ! empty( $_POST['import_id'] ) ) {
					$this->display_import_step();
					break;
				}
				if ( ! empty( $_GET['job_id'] ) ) {
					$this->display_job_progress_page();
					break;
				}
				$active_job = ( new WXR_Import_Job_Repository() )->get_active_job_for_user( get_current_user_id() );
				if ( $active_job ) {
					wp_safe_redirect(
						add_query_arg(
							array(
								'import' => 'wordpress',
								'step'   => 3,
								'job_id' => $active_job->id,
							),
							admin_url( 'admin.php' )
						)
					);
					exit;
				}
				$this->display_error(
					new WP_Error(
						'wxr_importer.import.missing_id',
						__( 'Missing import file ID from request. Start a new import or resume from the upload screen.', 'wordpress-importer' )
					)
				);
				break;
			case 3:
				$this->display_job_progress_page();
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
	 * Display introductory text and file upload form.
	 */
	protected function display_intro_step() {
		$this->enqueue_admin_styles( 'intro' );
		$this->enqueue_intro_scripts();

		$repository = new WXR_Import_Job_Repository();
		$resume_job = $repository->get_active_job_for_user( get_current_user_id() );
		$last_job   = $repository->get_last_completed_for_user( get_current_user_id() );

		require __DIR__ . '/templates/intro.php';
	}

	/**
	 * Enqueue shared admin styles for importer screens.
	 *
	 * @since 3.0.0
	 *
	 * @param string $screen Screen slug: intro, settings, progress.
	 *
	 * @return void
	 */
	protected function enqueue_admin_styles( $screen = 'intro' ) {
		$intro_css = dirname( __FILE__ ) . '/assets/intro.css';
		wp_enqueue_style(
			'wxr-importer-admin',
			WXR_IMPORTER_URL . 'assets/intro.css',
			array(),
			file_exists( $intro_css ) ? filemtime( $intro_css ) : WXR_IMPORTER_VERSION
		);

		if ( in_array( $screen, array( 'settings', 'progress' ), true ) ) {
			$import_css = dirname( __FILE__ ) . '/assets/import.css';
			wp_enqueue_style(
				'wxr-importer-progress',
				WXR_IMPORTER_URL . 'assets/import.css',
				array( 'wxr-importer-admin' ),
				file_exists( $import_css ) ? filemtime( $import_css ) : WXR_IMPORTER_VERSION
			);
		}
	}

	/**
	 * Enqueue intro page scripts (tabs + uploader).
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function enqueue_intro_scripts() {
		$tabs_js = dirname( __FILE__ ) . '/assets/intro-tabs.js';
		wp_enqueue_script(
			'wxr-importer-intro-tabs',
			WXR_IMPORTER_URL . 'assets/intro-tabs.js',
			array( 'jquery' ),
			file_exists( $tabs_js ) ? filemtime( $tabs_js ) : WXR_IMPORTER_VERSION,
			true
		);
	}

	/**
	 * Default web UI batch size.
	 *
	 * @since 3.0.0
	 *
	 * @return int
	 */
	protected function get_web_batch_size( $entity_total = 0 ) {
		/**
		 * Filter the batch size for web UI imports.
		 *
		 * @since 3.0.0
		 *
		 * @param int $batch_size Items per AJAX batch. Default 100.
		 * @param int $entity_total Total entities in the export, if known.
		 */
		$batch_size = max( 1, (int) apply_filters( 'wxr_importer.web_batch_size', 100, $entity_total ) );

		return $batch_size;
	}

	/**
	 * Get options for the importer.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, mixed> Options to pass to WXR_Importer::__construct.
	 */
	protected function get_import_options() {
		$options = array(
			'fetch_attachments' => $this->fetch_attachments,
			'default_author'    => get_current_user_id(),
		);

		/**
		 * Filter the importer options used in the admin UI.
		 *
		 * @since 3.0.0
		 *
		 * @param array<string, mixed> $options Options to pass to WXR_Importer::__construct.
		 */
		return apply_filters( 'wxr_importer.admin.import_options', $options );
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
		$script_path = dirname( __FILE__ ) . '/assets/intro.js';
		$url = plugins_url( 'assets/intro.js', $plugin_file );
		$deps = array(
			'jquery',
			'underscore',
			'wp-util',
			'wp-backbone',
			'wp-plupload',
			'media-upload',
		);
		wp_enqueue_script( 'import-upload', $url, $deps, filemtime( $script_path ), true );

		// Set uploader settings
		wp_plupload_default_settings();
		$settings = array(
			'l10n' => array(
				'frameTitle' => esc_html__( 'Select', 'wordpress-importer' ),
				'buttonText' => esc_html__( 'Import', 'wordpress-importer' ),
			),
			'next_url' => wp_nonce_url( $this->get_url( 1 ), 'import-upload' ) . '&id={id}',
			'params'   => array(
				'action'   => 'wxr-import-upload',
				'_wpnonce' => wp_create_nonce( 'wxr-import-upload' ),
				'upload_session' => wp_generate_uuid4(),
			),
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
				'chunk_size' => '8mb',
			),
		);
		wp_localize_script( 'import-upload', 'importUploadSettings', $settings );
		$this->log_upload_debug(
			'render_upload_form',
			array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'upload_action'  => $settings['params']['action'],
				'file_data_name' => $settings['plupload']['file_data_name'],
				'max_file_size'  => $settings['plupload']['filters']['max_file_size'],
				'chunk_size'     => $settings['plupload']['chunk_size'],
				'script_version' => filemtime( $script_path ),
			)
		);

		// Use WXR_IMPORTER_URL constant for consistent asset loading.
		$this->enqueue_admin_styles( 'intro' );

		// Load the template.
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

		$this->enqueue_admin_styles( 'settings' );

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
		// Flag this as a local file so stream_import knows not to delete it after import
		update_post_meta( $id, '_wxr_is_local_file', '1' );

		$this->id            = $id;
		$this->is_local_file = true; // don't delete after import — user placed it there
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
		$this->log_upload_debug(
			'async_entry',
			array(
				'method'         => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				'content_length' => isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
				'action'         => isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '',
				'request_keys'   => array_keys( $_REQUEST ),
				'file_keys'      => array_keys( $_FILES ),
				'files'          => $this->summarize_upload_files(),
				'user_id'        => get_current_user_id(),
				'can_upload'     => current_user_can( 'upload_files' ),
			)
		);

		register_shutdown_function(
			function () {
				$error = error_get_last();
				if ( ! empty( $error ) ) {
					$this->log_upload_debug( 'async_shutdown_error', $error );
				}
			}
		);

		$this->discard_async_upload_output();

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
			send_nosniff_header();
			nocache_headers();
		}

		ob_start();

		// Verify nonce
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wxr-import-upload' ) ) {
			$this->log_upload_debug(
				'nonce_failed',
				array(
					'has_nonce' => isset( $_REQUEST['_wpnonce'] ),
					'action'    => isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '',
				)
			);
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Security check failed. Please refresh the page and try again.', 'wordpress-importer' ),
					'filename' => isset( $_FILES['import']['name'] ) ? sanitize_file_name( $_FILES['import']['name'] ) : '',
				)
			);
		}

		/*
		 * This function does not use wp_send_json_success() / wp_send_json_error()
		 * as the html4 Plupload handler requires a text/html content-type for older IE.
		 * See https://core.trac.wordpress.org/ticket/31037
		 */

		// Check if file was uploaded
		if ( ! isset( $_FILES['import'] ) || ! isset( $_FILES['import']['name'] ) ) {
			$this->log_upload_debug( 'missing_import_file', array( 'files' => $this->summarize_upload_files() ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'No file was uploaded.', 'wordpress-importer' ),
					'filename' => '',
				)
			);
		}

		$filename = wp_unslash( $_FILES['import']['name'] );
		if ( $this->is_chunked_upload() && isset( $_REQUEST['name'] ) ) {
			$filename = wp_unslash( $_REQUEST['name'] );
		}
		$filename = sanitize_file_name( $filename );

		if ( ! current_user_can( 'upload_files' ) ) {
			$this->log_upload_debug( 'permission_failed', array( 'filename' => $filename ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'You do not have permission to upload files.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		if ( $this->is_chunked_upload() ) {
			$this->handle_async_chunked_upload( $filename );
		}

		// Ensure wp_import_handle_upload function exists
		if ( ! function_exists( 'wp_import_handle_upload' ) ) {
			require_once ABSPATH . '/wp-admin/includes/import.php';
		}

		if ( ! function_exists( 'wp_import_handle_upload' ) ) {
			$this->log_upload_debug( 'missing_wp_import_handle_upload', array( 'filename' => $filename ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'WordPress import functions are not available.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		$file = wp_import_handle_upload();
		if ( is_wp_error( $file ) ) {
			$this->log_upload_debug(
				'wp_error',
				array(
					'filename' => $filename,
					'code'     => $file->get_error_code(),
					'message'  => $file->get_error_message(),
				)
			);
			$this->send_async_upload_response(
				false,
				array(
					'message'  => $file->get_error_message(),
					'filename' => $filename,
				)
			);
		}

		if ( isset( $file['error'] ) ) {
			$this->log_upload_debug(
				'upload_array_error',
				array(
					'filename' => $filename,
					'error'    => $file['error'],
					'file'     => $file,
				)
			);
			$this->send_async_upload_response(
				false,
				array(
					'message'  => $file['error'],
					'filename' => $filename,
				)
			);
		}

		if ( ! isset( $file['id'] ) || ! isset( $file['file'] ) ) {
			$this->log_upload_debug(
				'invalid_upload_result',
				array(
					'filename' => $filename,
					'file'     => $file,
				)
			);
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Upload failed: Invalid file data returned.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		$attachment = wp_prepare_attachment_for_js( $file['id'] );
		if ( ! $attachment ) {
			$this->log_upload_debug(
				'prepare_attachment_failed',
				array(
					'filename'      => $filename,
					'attachment_id' => $file['id'],
					'file'          => $file,
				)
			);
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Upload failed: Could not prepare attachment data.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		$this->log_upload_debug(
			'async_success',
			array(
				'filename'      => $filename,
				'attachment_id' => $attachment['id'],
				'uploaded_file' => $file['file'],
			)
		);
		$this->send_async_upload_response( true, $attachment );
	}

	/**
	 * Check whether the current upload is a Plupload chunk.
	 *
	 * @return bool
	 */
	protected function is_chunked_upload() {
		return isset( $_REQUEST['chunks'], $_REQUEST['chunk'] ) && (int) $_REQUEST['chunks'] > 1;
	}

	/**
	 * Handle a chunked browser upload and register the assembled XML file.
	 *
	 * @param string $filename Sanitized original filename.
	 */
	protected function handle_async_chunked_upload( $filename ) {
		$chunk  = isset( $_REQUEST['chunk'] ) ? (int) $_REQUEST['chunk'] : 0;
		$chunks = isset( $_REQUEST['chunks'] ) ? (int) $_REQUEST['chunks'] : 0;

		if ( $chunk < 0 || $chunks < 2 || $chunk >= $chunks ) {
			$this->log_upload_debug(
				'chunk_invalid_index',
				array(
					'filename' => $filename,
					'chunk'    => $chunk,
					'chunks'   => $chunks,
				)
			);
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Invalid upload chunk received.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'xml' !== $ext ) {
			$this->log_upload_debug( 'chunk_invalid_extension', array( 'filename' => $filename ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Invalid file type. Please upload a valid WordPress XML export file.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		$upload_session = isset( $_REQUEST['upload_session'] ) ? sanitize_key( wp_unslash( $_REQUEST['upload_session'] ) ) : '';
		if ( empty( $upload_session ) ) {
			$upload_session = md5( get_current_user_id() . '|' . $filename . '|' . $chunks );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			$this->log_upload_debug( 'chunk_upload_dir_error', array( 'error' => $upload_dir['error'] ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => $upload_dir['error'],
					'filename' => $filename,
				)
			);
		}

		$chunk_base = trailingslashit( $upload_dir['basedir'] ) . 'wxr-importer-chunks';
		if ( ! wp_mkdir_p( $chunk_base ) ) {
			$this->log_upload_debug( 'chunk_base_dir_failed', array( 'chunk_base' => $chunk_base ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Could not create a temporary upload directory.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		if ( ! file_exists( trailingslashit( $chunk_base ) . 'index.php' ) ) {
			@file_put_contents( trailingslashit( $chunk_base ) . 'index.php', "<?php\n// Silence is golden.\n" );
		}

		if ( ! file_exists( trailingslashit( $chunk_base ) . '.htaccess' ) ) {
			@file_put_contents( trailingslashit( $chunk_base ) . '.htaccess', "Deny from all\n" );
		}

		if ( ! file_exists( trailingslashit( $chunk_base ) . 'web.config' ) ) {
			@file_put_contents(
				trailingslashit( $chunk_base ) . 'web.config',
				'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'
			);
		}

		$chunk_dir = trailingslashit( $chunk_base ) . $upload_session;
		if ( ! wp_mkdir_p( $chunk_dir ) ) {
			$this->log_upload_debug( 'chunk_dir_failed', array( 'chunk_dir' => $chunk_dir ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Could not create a temporary upload directory.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		$chunk_file = trailingslashit( $chunk_dir ) . $chunk . '.part';
		if ( ! isset( $_FILES['import']['tmp_name'] ) || ! is_uploaded_file( $_FILES['import']['tmp_name'] ) ) {
			$this->log_upload_debug( 'chunk_missing_tmp_file', array( 'files' => $this->summarize_upload_files() ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Upload chunk was missing from the request.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		if ( ! move_uploaded_file( $_FILES['import']['tmp_name'], $chunk_file ) ) {
			$this->log_upload_debug(
				'chunk_move_failed',
				array(
					'chunk_file' => $chunk_file,
					'files'      => $this->summarize_upload_files(),
				)
			);
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Could not save upload chunk.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		$this->log_upload_debug(
			'chunk_saved',
			array(
				'filename' => $filename,
				'chunk'    => $chunk,
				'chunks'   => $chunks,
				'size'     => filesize( $chunk_file ),
			)
		);

		if ( $chunk + 1 < $chunks ) {
			$this->send_async_upload_response(
				true,
				array(
					'message'  => __( 'Upload chunk received.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		for ( $i = 0; $i < $chunks; $i++ ) {
			if ( ! file_exists( trailingslashit( $chunk_dir ) . $i . '.part' ) ) {
				$this->log_upload_debug(
					'chunk_waiting_for_parts',
					array(
						'filename' => $filename,
						'missing'  => $i,
						'chunks'   => $chunks,
					)
				);
				$this->send_async_upload_response(
					true,
					array(
						'message'  => __( 'Upload chunk received.', 'wordpress-importer' ),
						'filename' => $filename,
					)
				);
			}
		}

		$unique_filename = wp_unique_filename( $upload_dir['path'], $filename );
		$final_file      = trailingslashit( $upload_dir['path'] ) . $unique_filename;
		$out             = fopen( $final_file, 'wb' );

		if ( false === $out ) {
			$this->log_upload_debug( 'chunk_final_open_failed', array( 'final_file' => $final_file ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Could not assemble uploaded file.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		for ( $i = 0; $i < $chunks; $i++ ) {
			$part = trailingslashit( $chunk_dir ) . $i . '.part';
			$in   = fopen( $part, 'rb' );

			if ( false === $in ) {
				fclose( $out );
				$this->log_upload_debug( 'chunk_part_open_failed', array( 'part' => $part ) );
				$this->send_async_upload_response(
					false,
					array(
						'message'  => __( 'Could not read upload chunk.', 'wordpress-importer' ),
						'filename' => $filename,
					)
				);
			}

			stream_copy_to_stream( $in, $out );
			fclose( $in );
		}

		fclose( $out );

		for ( $i = 0; $i < $chunks; $i++ ) {
			@unlink( trailingslashit( $chunk_dir ) . $i . '.part' );
		}
		@rmdir( $chunk_dir );

		$head = file_get_contents( $final_file, false, null, 0, 500 );
		if ( false === $head || ( ! preg_match( '/<\?xml/i', $head ) && ! preg_match( '/<rss|<wxr/i', $head ) ) ) {
			@unlink( $final_file );
			$this->log_upload_debug( 'chunk_final_invalid_xml', array( 'filename' => $filename ) );
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Invalid file type. Please upload a valid WordPress XML export file.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		$attachment = array(
			'post_title'     => wp_basename( $unique_filename ),
			'post_content'   => trailingslashit( $upload_dir['url'] ) . $unique_filename,
			'post_mime_type' => 'application/xml',
			'guid'           => trailingslashit( $upload_dir['url'] ) . $unique_filename,
			'context'        => 'import',
			'post_status'    => 'private',
		);

		$id = wp_insert_attachment( $attachment, $final_file );
		if ( is_wp_error( $id ) ) {
			@unlink( $final_file );
			$this->log_upload_debug(
				'chunk_insert_attachment_error',
				array(
					'filename' => $filename,
					'code'     => $id->get_error_code(),
					'message'  => $id->get_error_message(),
				)
			);
			$this->send_async_upload_response(
				false,
				array(
					'message'  => $id->get_error_message(),
					'filename' => $filename,
				)
			);
		}

		update_attached_file( $id, $final_file );
		wp_schedule_single_event( time() + DAY_IN_SECONDS, 'importer_scheduled_cleanup', array( $id ) );

		$attachment_data = wp_prepare_attachment_for_js( $id );
		if ( ! $attachment_data ) {
			$this->log_upload_debug(
				'chunk_prepare_attachment_failed',
				array(
					'filename'      => $filename,
					'attachment_id' => $id,
				)
			);
			$this->send_async_upload_response(
				false,
				array(
					'message'  => __( 'Upload failed: Could not prepare attachment data.', 'wordpress-importer' ),
					'filename' => $filename,
				)
			);
		}

		$this->log_upload_debug(
			'chunk_upload_success',
			array(
				'filename'      => $filename,
				'attachment_id' => $id,
				'final_file'    => $final_file,
			)
		);

		$this->send_async_upload_response( true, $attachment_data );
	}

	/**
	 * Send a Plupload-compatible JSON response.
	 *
	 * @param bool  $success Whether the upload succeeded.
	 * @param array $data    Response payload.
	 */
	protected function send_async_upload_response( $success, array $data ) {
		$this->log_upload_debug(
			'async_response',
			array(
				'success' => (bool) $success,
				'data'    => $this->summarize_upload_response_data( $data ),
			)
		);
		$this->discard_async_upload_output();

		echo wp_json_encode(
			array(
				'success' => (bool) $success,
				'data'    => $data,
			)
		);

		exit;
	}

	/**
	 * Discard output that would corrupt the Plupload JSON response.
	 */
	protected function discard_async_upload_output() {
		while ( ob_get_level() ) {
			$buffer = ob_get_status();
			if ( empty( $buffer['del'] ) ) {
				break;
			}

			ob_end_clean();
		}
	}

	/**
	 * Write importer upload diagnostics outside WP_DEBUG_LOG.
	 *
	 * @param string $event   Event name.
	 * @param array  $context Debug context.
	 */
	protected function log_upload_debug( $event, array $context = array() ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WXR_IMPORTER_DIAGNOSTICS' ) && WXR_IMPORTER_DIAGNOSTICS ) ) {
			return;
		}

		$entry = sprintf(
			'[wxr-importer] %s %s',
			$event,
			wp_json_encode( $context, JSON_UNESCAPED_SLASHES )
		);

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( $entry );
		}
	}

	/**
	 * Summarize $_FILES without dumping full temp-file contents.
	 *
	 * @return array
	 */
	protected function summarize_upload_files() {
		$files = array();

		foreach ( $_FILES as $key => $file ) {
			$files[ $key ] = array(
				'name'     => isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '',
				'type'     => isset( $file['type'] ) ? sanitize_text_field( wp_unslash( $file['type'] ) ) : '',
				'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
				'error'    => isset( $file['error'] ) ? (int) $file['error'] : 0,
				'tmp_name' => isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '',
			);
		}

		return $files;
	}

	/**
	 * Keep response log entries readable.
	 *
	 * @param array $data Response data.
	 * @return array
	 */
	protected function summarize_upload_response_data( array $data ) {
		return array(
			'id'       => isset( $data['id'] ) ? $data['id'] : null,
			'filename' => isset( $data['filename'] ) ? $data['filename'] : null,
			'message'  => isset( $data['message'] ) ? $data['message'] : null,
			'type'     => isset( $data['type'] ) ? $data['type'] : null,
			'subtype'  => isset( $data['subtype'] ) ? $data['subtype'] : null,
			'url'      => isset( $data['url'] ) ? $data['url'] : null,
		);
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

		// WordPress sometimes renames .xml to .xml.txt during upload due to MIME sniffing.
		// If the stored path doesn't exist, try stripping the .txt suffix.
		if ( ( ! $file || ! file_exists( $file ) ) && $file ) {
			$candidate = preg_replace( '/\.txt$/i', '', $file );
			if ( $candidate !== $file && file_exists( $candidate ) ) {
				// Fix the stored path so future calls work correctly
				update_attached_file( $id, $candidate );
				$file = $candidate;
			}
		}

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
			$error = new WP_Error( 'wxr_importer.import.missing_id', __( 'Missing import file ID from request.', 'wordpress-importer' ) );
			$this->display_error( $error );
			return;
		}

		check_admin_referer( sprintf( 'wxr.import:%d', (int) $args['import_id'] ) );

		$this->id   = (int) $args['import_id'];
		$file       = get_attached_file( $this->id );
		$mapping    = $this->get_author_mapping( $args );
		$fetch_attachments = ( ! empty( $args['fetch_attachments'] ) && $this->allow_fetch_attachments() );

		$job_options = array(
			'mapping'           => $mapping,
			'fetch_attachments' => $fetch_attachments,
			'is_local_file'     => (bool) get_post_meta( $this->id, '_wxr_is_local_file', true ),
			'batch_size'        => $this->get_web_batch_size( 0 ),
		);

		$job = WXR_Import_Job::create( $this->id, $file, $job_options );
		if ( is_wp_error( $job ) ) {
			$this->display_error( $job );
			return;
		}

		$job->options['batch_size'] = $this->get_web_batch_size( $job->manifest_entity_total() );

		$job->set_status( 'processing' );
		$repository = new WXR_Import_Job_Repository();
		$repository->save( $job );

		update_post_meta( $this->id, '_wxr_import_job_id', $job->id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'import' => 'wordpress',
					'step'   => 3,
					'job_id' => $job->id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Display job progress page (resume or view report).
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	protected function display_job_progress_page() {
		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		if ( $job_id < 1 ) {
			$active_job = ( new WXR_Import_Job_Repository() )->get_active_job_for_user( get_current_user_id() );
			if ( $active_job ) {
				$job_id = $active_job->id;
			}
		}
		if ( $job_id < 1 ) {
			$this->display_error( new WP_Error( 'wxr_importer.job.missing_id', __( 'Missing import job ID.', 'wordpress-importer' ) ) );
			return;
		}

		$job = WXR_Import_Job::get( $job_id );
		if ( is_wp_error( $job ) ) {
			$this->display_error( $job );
			return;
		}

		if ( $job->user_id && (int) $job->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this import job.', 'wordpress-importer' ),
				esc_html__( 'Permission Denied', 'wordpress-importer' ),
				array( 'response' => 403 )
			);
		}

		$this->id = (int) $job->attachment_id;
		$data     = get_post_meta( $this->id, '_wxr_import_info', true );

		if ( ! $data instanceof WXR_Import_Info ) {
			$data = new WXR_Import_Info();
			$data->post_count    = $job->total_posts;
			$data->media_count   = $job->total_media;
			$data->comment_count = $job->total_comments;
			$data->term_count    = $job->total_terms;
			$data->users         = array_fill( 0, $job->total_users, array() );
			if ( ! empty( $job->preflight_data['title'] ) ) {
				$data->title = $job->preflight_data['title'];
			}
		}

		$this->enqueue_admin_styles( 'progress' );
		$job_id = $job->id;
		require __DIR__ . '/templates/job-progress.php';
	}

	/**
	 * Deprecated SSE import endpoint.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function stream_import() {
		if ( ! current_user_can( 'import' ) ) {
			status_header( 403 );
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to import content.', 'wordpress-importer' ) ),
				403
			);
		}

		$attachment_id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
		$job_id        = $attachment_id ? (int) get_post_meta( $attachment_id, '_wxr_import_job_id', true ) : 0;

		if ( $job_id > 0 ) {
			$resume_url = add_query_arg(
				array(
					'import' => 'wordpress',
					'step'   => 3,
					'job_id' => $job_id,
				),
				admin_url( 'admin.php' )
			);

			wp_send_json_error(
				array(
					'message'    => __( 'The legacy import stream has been retired. Use the job-based import UI instead.', 'wordpress-importer' ),
					'resume_url' => $resume_url,
					'job_id'     => $job_id,
				),
				410
			);
		}

		wp_send_json_error(
			array(
				'message' => __( 'The legacy import stream has been retired. Please start a new import from Tools → Import.', 'wordpress-importer' ),
			),
			410
		);
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

		// Cancel any associated import job.
		$job_id = (int) get_post_meta( $id, '_wxr_import_job_id', true );
		if ( $job_id > 0 ) {
			$job = WXR_Import_Job::get( $job_id );
			if ( ! is_wp_error( $job ) && ! $job->is_terminal() ) {
				$job->set_status( 'cancelled' );
				$job->add_log( 'info', __( 'Import cancelled by user.', 'wordpress-importer' ) );
				$repository = new WXR_Import_Job_Repository();
				$repository->save( $job );
			}
		}

		delete_post_meta( $id, '_wxr_import_settings' );

		wp_send_json_success( array( 'message' => __( 'Import cancelled.', 'wordpress-importer' ) ) );
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
	 * Process one import batch via AJAX.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function handle_import_batch() {
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wordpress-importer' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		check_ajax_referer( 'wxr-import-job:' . $job_id, 'nonce' );

		set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		try {
			$processor = new WXR_Import_Processor();
			$result    = $processor->process_job( $job_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			if ( isset( $result['item_results'] ) ) {
				unset( $result['item_results'] );
			}

			wp_send_json_success( $result );
		} catch ( Throwable $e ) {
			$job = WXR_Import_Job::get( $job_id );
			if ( ! is_wp_error( $job ) ) {
				$job->add_log( 'error', $e->getMessage() );
				( new WXR_Import_Job_Repository() )->save( $job );
			}
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Batch failed: %s', 'wordpress-importer' ),
						$e->getMessage()
					),
				),
				500
			);
		}
	}

	/**
	 * Return persisted job status for polling UI.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function handle_import_status() {
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wordpress-importer' ) ), 403 );
		}

		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		check_ajax_referer( 'wxr-import-job:' . $job_id, 'nonce' );

		$job = WXR_Import_Job::get( $job_id );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		wp_send_json_success( $job->to_status_array() );
	}

	/**
	 * Pause a running import job.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function handle_import_pause() {
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wordpress-importer' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		check_ajax_referer( 'wxr-import-job:' . $job_id, 'nonce' );

		$job = WXR_Import_Job::get( $job_id );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		$job->options['pause_requested'] = true;
		$job->set_status( 'paused' );
		$job->add_log( 'info', __( 'Import paused.', 'wordpress-importer' ) );
		$repository = new WXR_Import_Job_Repository();
		$repository->save( $job );

		wp_send_json_success( $job->to_status_array() );
	}

	/**
	 * Resume a paused import job.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function handle_import_resume() {
		if ( ! current_user_can( 'import' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wordpress-importer' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		check_ajax_referer( 'wxr-import-job:' . $job_id, 'nonce' );

		$job = WXR_Import_Job::get( $job_id );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		unset( $job->options['pause_requested'] );
		$job->set_status( 'processing' );
		$job->add_log( 'info', __( 'Import resumed.', 'wordpress-importer' ) );
		$repository = new WXR_Import_Job_Repository();
		$repository->save( $job );

		wp_send_json_success( $job->to_status_array() );
	}

	/**
	 * Remove abandoned chunk upload directories older than one day.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public static function cleanup_abandoned_chunks() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return;
		}

		$chunk_base = trailingslashit( $upload_dir['basedir'] ) . 'wxr-importer-chunks';
		if ( ! is_dir( $chunk_base ) ) {
			return;
		}

		$cutoff = time() - DAY_IN_SECONDS;
		$dirs   = glob( trailingslashit( $chunk_base ) . '*', GLOB_ONLYDIR );
		if ( ! is_array( $dirs ) ) {
			return;
		}

		foreach ( $dirs as $dir ) {
			if ( filemtime( $dir ) < $cutoff ) {
				self::delete_directory( $dir );
			}
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @since 3.0.0
	 *
	 * @param string $dir Directory path.
	 *
	 * @return void
	 */
	protected static function delete_directory( $dir ) {
		$items = glob( trailingslashit( $dir ) . '*' );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_dir( $item ) ) {
					self::delete_directory( $item );
				} else {
					wp_delete_file( $item );
				}
			}
		}
		rmdir( $dir );
	}
}
