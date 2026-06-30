<?php
/**
 * Plugin Name: Better WordPress Importer
 * Plugin URI: https://github.com/Krafty-Sprouts-Media-LLC/KSM-WordPress-Importer
 * Description: Import posts, pages, comments, custom fields, categories, tags and more from a WordPress export file. Resumable, batch-based, large-file safe. Fork of humanmade/WordPress-Importer.
 * Version: 3.0.8
 * Author: Krafty Sprouts Media, LLC
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wordpress-importer
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Importer' ) ) {
	defined( 'WP_LOAD_IMPORTERS' ) || define( 'WP_LOAD_IMPORTERS', true );
	require ABSPATH . '/wp-admin/includes/class-wp-importer.php';
}

if ( ! defined( 'WXR_IMPORTER_URL' ) ) {
	define( 'WXR_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'WXR_IMPORTER_VERSION' ) ) {
	define( 'WXR_IMPORTER_VERSION', '3.0.8' );
}

require dirname( __FILE__ ) . '/class-logger.php';
require dirname( __FILE__ ) . '/class-logger-cli.php';
require dirname( __FILE__ ) . '/class-logger-html.php';
require dirname( __FILE__ ) . '/class-logger-job.php';
require dirname( __FILE__ ) . '/class-wxr-importer.php';
require dirname( __FILE__ ) . '/class-wxr-import-info.php';
require dirname( __FILE__ ) . '/class-wxr-import-job-repository.php';
require dirname( __FILE__ ) . '/class-wxr-import-job.php';
require dirname( __FILE__ ) . '/class-wxr-import-item.php';
require dirname( __FILE__ ) . '/class-wxr-import-remapper.php';
require dirname( __FILE__ ) . '/class-wxr-import-processor.php';
require dirname( __FILE__ ) . '/class-wxr-import-ui.php';

require dirname( __FILE__ ) . '/install.php';

register_activation_hook( __FILE__, 'wxr_importer_activate' );

if ( defined( 'WP_CLI' ) ) {
	require __DIR__ . '/class-command.php';

	WP_CLI::add_command( 'wxr-importer', 'WXR_Import_Command' );
}

/**
 * Register importer and hooks.
 *
 * @since 3.0.0
 *
 * @return void
 */
function wpimportv2_init() {
	$GLOBALS['wxr_importer'] = new WXR_Import_UI();
	register_importer(
		'wordpress',
		__( 'WordPress (v2)', 'wordpress-importer' ),
		__( 'Import <strong>posts, pages, comments, custom fields, categories, and tags</strong> from a WordPress export (WXR) file.', 'wordpress-importer' ),
		array( $GLOBALS['wxr_importer'], 'dispatch' )
	);

	add_action( 'load-importer-wordpress', array( $GLOBALS['wxr_importer'], 'on_load' ) );

	// Deprecated legacy SSE endpoint — returns 410 with job resume URL when possible.
	add_action( 'wp_ajax_wxr-import', array( $GLOBALS['wxr_importer'], 'stream_import' ) );

	// Job-based batch import endpoints.
	add_action( 'wp_ajax_wxr-import-batch', array( $GLOBALS['wxr_importer'], 'handle_import_batch' ) );
	add_action( 'wp_ajax_wxr-import-status', array( $GLOBALS['wxr_importer'], 'handle_import_status' ) );
	add_action( 'wp_ajax_wxr-import-pause', array( $GLOBALS['wxr_importer'], 'handle_import_pause' ) );
	add_action( 'wp_ajax_wxr-import-resume', array( $GLOBALS['wxr_importer'], 'handle_import_resume' ) );
}
add_action( 'admin_init', 'wpimportv2_init' );

/**
 * Register custom cron schedules and handlers.
 *
 * @since 3.0.0
 *
 * @param array $schedules Existing schedules.
 *
 * @return array
 */
function wxr_importer_cron_schedules( $schedules ) {
	$schedules['wxr_importer_batch_interval'] = array(
		'interval' => 30,
		'display'  => __( 'Every 30 seconds (WXR Importer)', 'wordpress-importer' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'wxr_importer_cron_schedules' );

add_action( 'wxr_importer_process_batch', array( 'WXR_Import_Processor', 'cron_process_next' ) );
add_action( 'wxr_importer_cleanup_chunks', array( 'WXR_Import_UI', 'cleanup_abandoned_chunks' ) );

/**
 * Ensure database tables exist after plugin updates.
 *
 * @since 3.0.0
 *
 * @return void
 */
function wxr_importer_maybe_upgrade() {
	$installed = get_option( 'wxr_importer_db_version', '' );
	if ( version_compare( $installed, '3.0.3', '<' ) ) {
		wxr_importer_install_tables();
	}
	wxr_importer_migrate_legacy_sessions();
}
add_action( 'plugins_loaded', 'wxr_importer_maybe_upgrade' );

/**
 * Show a one-time notice after upgrading to the job-based importer.
 *
 * @since 3.0.0
 *
 * @return void
 */
function wxr_importer_upgrade_admin_notice() {
	if ( ! get_transient( 'wxr_importer_upgrade_notice' ) ) {
		return;
	}

	if ( ! current_user_can( 'import' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
		esc_html__( 'Better WordPress Importer now uses resumable background jobs. Any in-progress imports from the previous version were cleared — please start a new import.', 'wordpress-importer' )
	);

	delete_transient( 'wxr_importer_upgrade_notice' );
}
add_action( 'admin_notices', 'wxr_importer_upgrade_admin_notice' );
