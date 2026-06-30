<?php
/**
 * Settings and maintenance screen for legacy cleanup actions.
 *
 * @package Better_WordPress_Importer
 * @since 1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings and maintenance controller.
 *
 * @since 1.4.0
 */
class Better_Admin_Settings {

	/**
	 * Register hooks.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post_actions' ) );
	}

	/**
	 * Register the settings submenu.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			'tools.php',
			__( 'Better Importer Settings', 'better-wordpress-importer' ),
			__( 'Importer Settings', 'better-wordpress-importer' ),
			'manage_options',
			'better-importer-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle maintenance form submissions.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function handle_post_actions() {
		if ( empty( $_POST['better_importer_settings_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'better-importer-settings', 'better_importer_settings_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['better_importer_settings_action'] ) );
		$result = array();
		$notice = '';

		switch ( $action ) {
			case 'cleanup_chunks':
				$result = Better_Chunked_Upload::cleanup_abandoned();
				$legacy = Better_Legacy_Cleanup::cleanup_chunk_directories();
				$result['directories'] += isset( $legacy['directories'] ) ? (int) $legacy['directories'] : 0;
				$result['files']       += isset( $legacy['files'] ) ? (int) $legacy['files'] : 0;
				$notice = sprintf(
					/* translators: 1: directories removed, 2: files removed */
					__( 'Removed %1$d chunk directories and %2$d files.', 'better-wordpress-importer' ),
					isset( $result['directories'] ) ? (int) $result['directories'] : 0,
					isset( $result['files'] ) ? (int) $result['files'] : 0
				);
				break;

			case 'cleanup_legacy_meta':
				$result = Better_Legacy_Cleanup::cleanup_legacy_import_meta();
				$notice = sprintf(
					/* translators: 1: post meta rows, 2: comment meta rows */
					__( 'Removed %1$d legacy post meta rows and %2$d comment meta rows.', 'better-wordpress-importer' ),
					isset( $result['postmeta'] ) ? (int) $result['postmeta'] : 0,
					isset( $result['commentmeta'] ) ? (int) $result['commentmeta'] : 0
				);
				break;

			case 'cleanup_temp_meta':
				$result = array(
					'deleted' => Better_Legacy_Cleanup::cleanup_temp_import_meta(),
				);
				$notice = sprintf(
					/* translators: %d: number of rows deleted */
					__( 'Removed %d temporary import meta rows.', 'better-wordpress-importer' ),
					(int) $result['deleted']
				);
				break;

			case 'remove_diagnostic_logs':
				$result = array(
					'removed' => Better_Legacy_Cleanup::remove_diagnostic_logs(),
				);
				$notice = empty( $result['removed'] )
					? __( 'No diagnostic log files were found.', 'better-wordpress-importer' )
					: sprintf(
						/* translators: %s: comma-separated filenames */
						__( 'Removed diagnostic logs: %s', 'better-wordpress-importer' ),
						implode( ', ', $result['removed'] )
					);
				break;

			case 'unschedule_legacy_cron':
				Better_Legacy_Cleanup::unschedule_legacy_cron();
				$result = array( 'unscheduled' => true );
				$notice = __( 'Legacy cron events were cleared.', 'better-wordpress-importer' );
				break;

			case 'drop_legacy_tables':
				if ( empty( $_POST['better_importer_confirm_drop'] ) ) {
					add_settings_error(
						'better_importer_settings',
						'better_importer_confirm_drop',
						__( 'You must confirm before dropping legacy experimental tables.', 'better-wordpress-importer' ),
						'error'
					);
					return;
				}

				$result = Better_Legacy_Cleanup::drop_legacy_tables();
				$notice = __( 'Legacy experimental tables were dropped.', 'better-wordpress-importer' );
				break;

			default:
				add_settings_error(
					'better_importer_settings',
					'better_importer_invalid_action',
					__( 'Unknown maintenance action.', 'better-wordpress-importer' ),
					'error'
				);
				return;
		}

		Better_Legacy_Cleanup::record_maintenance_action( $action, $result );

		add_settings_error(
			'better_importer_settings',
			'better_importer_maintenance_success',
			$notice,
			'success'
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage importer settings.', 'better-wordpress-importer' ) );
		}

		$status          = Better_Legacy_Cleanup::get_status();
		$last_maintenance = get_option( 'better_importer_last_maintenance', array() );
		$ui              = isset( $GLOBALS['better_importer_ui'] ) ? $GLOBALS['better_importer_ui'] : null;

		if ( ! $ui instanceof Better_Admin_UI ) {
			$ui = Better_Admin_UI::instance();
		}

		$ui->render_header();
		include BETTER_IMPORTER_PATH . 'templates/settings.php';
		$ui->render_footer();
	}
}
