<?php
/**
 * Admin UI controller for the Better WordPress Importer.
 *
 * @package Better_WordPress_Importer
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers importer screens, menus, and assets.
 *
 * @since 1.2.0
 */
class Better_Admin_UI {

	/**
	 * Selected attachment ID for the current import flow.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	protected $attachment_id = 0;

	/**
	 * Singleton UI instance.
	 *
	 * @since 1.4.1
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Get or create the admin UI instance.
	 *
	 * @since 1.4.1
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			$GLOBALS['better_importer_ui'] = self::$instance;
		}

		return self::$instance;
	}

	/**
	 * Register admin hooks. Must run on plugins_loaded, before admin_menu fires.
	 *
	 * @since 1.4.1
	 *
	 * @return void
	 */
	public static function register() {
		$ui = self::instance();

		add_action( 'admin_menu', array( $ui, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $ui, 'enqueue_assets' ) );
		add_filter( 'upload_mimes', array( $ui, 'allow_xml_uploads' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $ui, 'fix_xml_filetype' ), 10, 5 );
	}

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	protected function __construct() {
	}

	/**
	 * Register the Tools importer and standalone menu.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register_importer() {
		if ( ! class_exists( 'WP_Importer' ) ) {
			defined( 'WP_LOAD_IMPORTERS' ) || define( 'WP_LOAD_IMPORTERS', true );
			require ABSPATH . 'wp-admin/includes/class-wp-importer.php';
		}

		$ui = self::instance();

		register_importer(
			'wordpress',
			__( 'WordPress (v2)', 'better-wordpress-importer' ),
			__( 'Import posts, pages, comments, custom fields, categories, and tags from a WordPress export file.', 'better-wordpress-importer' ),
			array( $ui, 'dispatch_importer' )
		);

		add_action( 'load-importer-wordpress', array( $ui, 'hide_admin_header' ) );
	}

	/**
	 * Register submenu pages under Tools.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'tools.php',
			__( 'Better Importer', 'better-wordpress-importer' ),
			__( 'Better Importer', 'better-wordpress-importer' ),
			'import',
			'better-importer',
			array( $this, 'render_import_page' )
		);

		add_submenu_page(
			'tools.php',
			__( 'Import History', 'better-wordpress-importer' ),
			__( 'Import History', 'better-wordpress-importer' ),
			'import',
			'better-importer-history',
			array( $this, 'render_history_page' )
		);
	}

	/**
	 * Hide the default admin header on importer screens.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function hide_admin_header() {
		$_GET['noheader'] = true;
	}

	/**
	 * Dispatch the core Tools → Import screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function dispatch_importer() {
		$this->dispatch( 'import' );
	}

	/**
	 * Render the standalone import page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_import_page() {
		$this->dispatch( 'page' );
	}

	/**
	 * Render import history.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_history_page() {
		if ( ! current_user_can( 'import' ) ) {
			wp_die( esc_html__( 'You do not have permission to import content.', 'better-wordpress-importer' ) );
		}

		$jobs = ( new Better_Import_Job_Repository() )->list_recent( 50 );
		$this->render_template( 'history', compact( 'jobs' ) );
	}

	/**
	 * Route import steps.
	 *
	 * @since 1.2.0
	 *
	 * @param string $context importer or page.
	 *
	 * @return void
	 */
	protected function dispatch( $context ) {
		if ( ! current_user_can( 'import' ) ) {
			wp_die( esc_html__( 'You do not have permission to import content.', 'better-wordpress-importer' ) );
		}

		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 0;

		if ( isset( $_GET['job_id'] ) ) {
			$this->render_progress_page( absint( $_GET['job_id'] ), $context );
			return;
		}

		$active_job = ( new Better_Import_Job_Repository() )->get_active_job_for_user( get_current_user_id() );
		if ( $active_job && 0 === $step ) {
			$this->render_progress_page( $active_job->id, $context );
			return;
		}

		switch ( $step ) {
			case 1:
				$this->render_settings_step( $context );
				break;
			case 2:
				$this->render_progress_page( isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0, $context );
				break;
			default:
				$this->render_upload_step( $context );
		}
	}

	/**
	 * Render the upload step.
	 *
	 * @since 1.2.0
	 *
	 * @param string $context Screen context.
	 *
	 * @return void
	 */
	protected function render_upload_step( $context ) {
		$max_upload_size = wp_max_upload_size();
		$upload_dir      = wp_upload_dir();
		$recent_jobs     = ( new Better_Import_Job_Repository() )->list_recent( 5 );

		$this->render_template(
			'import-upload',
			compact( 'context', 'max_upload_size', 'upload_dir', 'recent_jobs' )
		);
	}

	/**
	 * Render the settings step.
	 *
	 * @since 1.2.0
	 *
	 * @param string $context Screen context.
	 *
	 * @return void
	 */
	protected function render_settings_step( $context ) {
		$this->attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		$local_file          = isset( $_GET['local_file'] ) ? sanitize_text_field( wp_unslash( $_GET['local_file'] ) ) : '';

		$file_path = '';
		if ( $this->attachment_id > 0 ) {
			$file_path = get_attached_file( $this->attachment_id );
		} elseif ( '' !== $local_file ) {
			$validated = self::validate_local_file_path( $local_file );
			if ( ! is_wp_error( $validated ) ) {
				$file_path = $validated;
			}
		}

		if ( ! $file_path || ! is_readable( $file_path ) ) {
			$this->render_error( __( 'The selected import file could not be read.', 'better-wordpress-importer' ), $context );
			return;
		}

		$preflight = new Better_Preflight();
		$scan      = $preflight->scan( $file_path );
		if ( is_wp_error( $scan ) ) {
			$this->render_error( $scan->get_error_message(), $context );
			return;
		}

		$users = get_users(
			array(
				'fields'  => array( 'ID', 'display_name', 'user_login' ),
				'orderby' => 'display_name',
			)
		);

		$this->render_template(
			'import-settings',
			array(
				'context'       => $context,
				'attachment_id' => $this->attachment_id,
				'local_file'    => $local_file,
				'file_path'     => $file_path,
				'preflight'     => $scan['preflight'],
				'entity_total'  => count( $scan['manifest'] ),
				'users'         => $users,
			)
		);
	}

	/**
	 * Render the progress step.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $job_id  Job ID.
	 * @param string $context Screen context.
	 *
	 * @return void
	 */
	protected function render_progress_page( $job_id, $context ) {
		$job = ( new Better_Import_Job_Repository() )->get( $job_id );
		if ( ! $job || ! ( new Better_Import_Job_Repository() )->user_can_access( $job ) ) {
			$this->render_error( __( 'Import job not found.', 'better-wordpress-importer' ), $context );
			return;
		}

		$this->render_template(
			'import-progress',
			compact( 'job', 'context' )
		);
	}

	/**
	 * Render a template with header/footer wrappers.
	 *
	 * @since 1.2.0
	 *
	 * @param string               $template Template slug.
	 * @param array<string, mixed> $vars     Template variables.
	 *
	 * @return void
	 */
	protected function render_template( $template, array $vars = array() ) {
		$vars['ui'] = $this;
		extract( $vars, EXTR_SKIP );

		$this->render_header();
		include BETTER_IMPORTER_PATH . 'templates/' . $template . '.php';
		$this->render_footer();
	}

	/**
	 * Render a simple error screen.
	 *
	 * @since 1.2.0
	 *
	 * @param string $message Error message.
	 * @param string $context Screen context.
	 *
	 * @return void
	 */
	protected function render_error( $message, $context ) {
		$this->render_header();
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		echo '<p><a class="button" href="' . esc_url( $this->get_step_url( 0, $context ) ) . '">' . esc_html__( 'Back', 'better-wordpress-importer' ) . '</a></p>';
		$this->render_footer();
	}

	/**
	 * Render the admin page header.
	 *
	 * On Tools → Import → WordPress (v2), WordPress sets noheader so it does not
	 * load admin-header.php before the importer callback. Load it here so styles,
	 * scripts, and the admin chrome are available (same pattern as legacy header.php).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_header() {
		if ( ! did_action( 'admin_enqueue_scripts' ) ) {
			require_once ABSPATH . 'wp-admin/admin-header.php';
		}

		echo '<div class="wrap better-importer-wrap">';
		echo '<h1>' . esc_html__( 'Better WordPress Importer', 'better-wordpress-importer' ) . '</h1>';
		$this->render_tabs();
	}

	/**
	 * Render navigation tabs.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function render_tabs() {
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'wordpress';
		$tabs    = array(
			'better-importer'         => array(
				'label' => __( 'Import', 'better-wordpress-importer' ),
				'url'   => admin_url( 'tools.php?page=better-importer' ),
			),
			'better-importer-history' => array(
				'label' => __( 'History', 'better-wordpress-importer' ),
				'url'   => admin_url( 'tools.php?page=better-importer-history' ),
			),
			'better-importer-settings' => array(
				'label' => __( 'Settings', 'better-wordpress-importer' ),
				'url'   => admin_url( 'tools.php?page=better-importer-settings' ),
			),
		);

		echo '<nav class="nav-tab-wrapper better-importer-tabs">';
		foreach ( $tabs as $slug => $tab ) {
			$class = ( $current === $slug || ( 'wordpress' === $current && 'better-importer' === $slug ) ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf( '<a href="%s" class="%s">%s</a>', esc_url( $tab['url'] ), esc_attr( $class ), esc_html( $tab['label'] ) );
		}
		echo '</nav>';
	}

	/**
	 * Render the admin page footer.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_footer() {
		echo '</div>';
	}

	/**
	 * Build a step URL for the current context.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $step    Step number.
	 * @param string $context importer or page.
	 * @param array  $args    Extra query args.
	 *
	 * @return string
	 */
	public function get_step_url( $step, $context, array $args = array() ) {
		if ( 'import' === $context ) {
			$base = admin_url( 'import.php' );
			$args = array_merge(
				array(
					'import' => 'wordpress',
					'step'   => $step,
				),
				$args
			);
		} else {
			$base = admin_url( 'tools.php?page=better-importer' );
			$args = array_merge( array( 'step' => $step ), $args );
		}

		return add_query_arg( $args, $base );
	}

	/**
	 * Validate a server-side WXR path.
	 *
	 * @since 1.2.0
	 *
	 * @param string $path User-supplied path.
	 *
	 * @return string|WP_Error
	 */
	public static function validate_local_file_path( $path ) {
		$path = wp_normalize_path( trim( $path ) );
		if ( '' === $path ) {
			return new WP_Error( 'better_importer.path.empty', __( 'Please enter a file path.', 'better-wordpress-importer' ) );
		}

		$real = realpath( $path );
		if ( false === $real || ! is_readable( $real ) ) {
			return new WP_Error( 'better_importer.path.unreadable', __( 'That file path could not be read.', 'better-wordpress-importer' ) );
		}

		$real = wp_normalize_path( $real );
		$allowed_roots = array(
			wp_normalize_path( ABSPATH ),
			wp_normalize_path( WP_CONTENT_DIR ),
		);

		$allowed = false;
		foreach ( $allowed_roots as $root ) {
			if ( 0 === strpos( $real, $root ) ) {
				$allowed = true;
				break;
			}
		}

		if ( ! $allowed ) {
			return new WP_Error( 'better_importer.path.outside', __( 'Import files must stay inside the WordPress install.', 'better-wordpress-importer' ) );
		}

		if ( 'xml' !== strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'better_importer.path.extension', __( 'Import files must use the .xml extension.', 'better-wordpress-importer' ) );
		}

		$format = Better_Format_Detector::validate_for_import( $real );
		if ( is_wp_error( $format ) ) {
			return $format;
		}

		return $real;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 1.2.0
	 *
	 * @param string $hook Current admin hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$is_core_importer = (
			isset( $_GET['import'] )
			&& 'wordpress' === sanitize_key( wp_unslash( $_GET['import'] ) )
		);
		$is_importer      = (
			'tools_page_better-importer' === $hook
			|| 'tools_page_better-importer-history' === $hook
			|| 'tools_page_better-importer-settings' === $hook
			|| 'import' === $hook
			|| $is_core_importer
		);
		if ( ! $is_importer ) {
			return;
		}

		wp_enqueue_style(
			'better-importer-admin',
			BETTER_IMPORTER_URL . 'assets/css/admin.css',
			array(),
			$this->get_asset_version( 'assets/css/admin.css' )
		);

		if ( 'tools_page_better-importer-history' === $hook || 'tools_page_better-importer-settings' === $hook ) {
			return;
		}

		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 0;
		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;

		if ( $job_id > 0 || 2 === $step ) {
			$job = $job_id > 0 ? ( new Better_Import_Job_Repository() )->get( $job_id ) : null;
			if ( $job ) {
				$context = ( $is_core_importer || 'import' === $hook ) ? 'import' : 'page';
				wp_enqueue_script(
					'better-importer-progress',
					BETTER_IMPORTER_URL . 'assets/js/import-progress.js',
					array( 'jquery' ),
					$this->get_asset_version( 'assets/js/import-progress.js' ),
					true
				);
				wp_localize_script(
					'better-importer-progress',
					'betterImporterProgress',
					array(
						'jobId'        => $job->id,
						'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
						'nonce'        => Better_Import_Ajax::job_nonce( $job->id ),
						'introUrl'     => $this->get_step_url( 0, $context ),
						'pollInterval' => 2000,
						'initial'      => $job->to_status_array(),
						'strings'      => array(
							'importing'      => __( 'Importing…', 'better-wordpress-importer' ),
							'paused'         => __( 'Import paused.', 'better-wordpress-importer' ),
							'complete'       => __( 'Import complete!', 'better-wordpress-importer' ),
							'failed'         => __( 'Import failed.', 'better-wordpress-importer' ),
							'cancelled'      => __( 'Import cancelled.', 'better-wordpress-importer' ),
							'background'     => __( 'Processing continues on the server. You can close this page and return later.', 'better-wordpress-importer' ),
							'connectionLost' => __( 'Connection lost. Retrying…', 'better-wordpress-importer' ),
							'pause'          => __( 'Pause', 'better-wordpress-importer' ),
							'resume'         => __( 'Resume', 'better-wordpress-importer' ),
							'cancel'         => __( 'Cancel Import', 'better-wordpress-importer' ),
							'runAnother'     => __( 'Import another file', 'better-wordpress-importer' ),
						),
					)
				);
			}
			return;
		}

		wp_enqueue_media();
		wp_plupload_default_settings();

		wp_enqueue_script(
			'better-importer-upload',
			BETTER_IMPORTER_URL . 'assets/js/import-upload.js',
			array( 'jquery', 'underscore', 'wp-util', 'wp-backbone', 'wp-plupload' ),
			$this->get_asset_version( 'assets/js/import-upload.js' ),
			true
		);

		$context = ( $is_core_importer || 'import' === $hook ) ? 'import' : 'page';

		wp_localize_script(
			'better-importer-upload',
			'betterImporterUpload',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'better-import-upload' ),
				'maxSize'       => wp_max_upload_size(),
				'chunkSize'     => Better_Chunked_Upload::DEFAULT_CHUNK_SIZE,
				'uploadSession' => wp_generate_uuid4(),
				'settingsUrl'   => $this->get_step_url( 1, $context ),
				'strings'     => array(
					'uploading' => __( 'Uploading…', 'better-wordpress-importer' ),
					'success'   => __( 'File uploaded successfully.', 'better-wordpress-importer' ),
					'error'     => __( 'Upload failed.', 'better-wordpress-importer' ),
				),
			)
		);

		if ( 1 === $step ) {
			return;
		}
	}

	/**
	 * Get a cache-busting asset version.
	 *
	 * @since 1.5.0
	 *
	 * @param string $relative_path Asset path relative to the plugin root.
	 *
	 * @return string Asset version.
	 */
	protected function get_asset_version( $relative_path ) {
		$path = BETTER_IMPORTER_PATH . ltrim( $relative_path, '/\\' );

		if ( file_exists( $path ) ) {
			return (string) filemtime( $path );
		}

		return BETTER_IMPORTER_VERSION;
	}

	/**
	 * Allow XML uploads in the media library.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, string> $mimes Mime types.
	 *
	 * @return array<string, string>
	 */
	public function allow_xml_uploads( $mimes ) {
		$mimes['xml'] = 'application/xml';
		return $mimes;
	}

	/**
	 * Prevent WordPress from misidentifying XML uploads.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, string> $data     File data.
	 * @param string              $file     File path.
	 * @param string              $filename Filename.
	 * @param array|null          $mimes    Mime map.
	 * @param string|bool         $real_mime Real mime.
	 *
	 * @return array<string, string>
	 */
	public function fix_xml_filetype( $data, $file, $filename, $mimes, $real_mime = false ) {
		if ( empty( $data['ext'] ) && empty( $data['type'] ) ) {
			$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			if ( 'xml' === $ext ) {
				$data['ext']             = 'xml';
				$data['type']            = 'application/xml';
				$data['proper_filename'] = $filename;
			}
		}

		return $data;
	}
}
