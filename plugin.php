<?php
/**
 * Plugin Name: Better WordPress Importer
 * Plugin URI: https://github.com/Krafty-Sprouts-Media-LLC/KSM-WordPress-Importer
 * Description: Import posts, pages, comments, custom fields, categories, tags and more from a WordPress export file. Resumable, batch-based, large-file safe.
 * Version: 1.6.0
 * Author: Krafty Sprouts Media, LLC
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: better-wordpress-importer
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BETTER_IMPORTER_VERSION' ) ) {
	define( 'BETTER_IMPORTER_VERSION', '1.6.0' );
}

if ( ! defined( 'BETTER_IMPORTER_PATH' ) ) {
	define( 'BETTER_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'BETTER_IMPORTER_URL' ) ) {
	define( 'BETTER_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Load a plugin class file.
 *
 * @since 1.0.0
 *
 * @param string $relative_path Path relative to the plugin root.
 *
 * @return void
 */
function better_importer_require( $relative_path ) {
	require_once BETTER_IMPORTER_PATH . ltrim( $relative_path, '/' );
}

better_importer_require( 'src/Format/class-better-format-detector.php' );
better_importer_require( 'src/Upload/class-better-chunked-upload.php' );
better_importer_require( 'src/Core/class-better-legacy-cleanup.php' );
better_importer_require( 'src/Core/class-better-install.php' );
better_importer_require( 'src/Core/class-better-logger.php' );
better_importer_require( 'src/Core/class-better-import-remapper.php' );
better_importer_require( 'src/Importer/class-better-preflight.php' );
better_importer_require( 'src/Importer/class-better-wxr-parser.php' );
better_importer_require( 'src/Importer/class-better-importer.php' );
better_importer_require( 'src/Core/class-better-import-queue-item.php' );
better_importer_require( 'src/Core/class-better-import-queue-repository.php' );
better_importer_require( 'src/Core/class-better-import-job.php' );
better_importer_require( 'src/Core/class-better-import-job-repository.php' );
better_importer_require( 'src/Core/class-better-import-processor.php' );
better_importer_require( 'admin/class-better-import-ajax.php' );
better_importer_require( 'admin/class-better-admin-ui.php' );
better_importer_require( 'admin/class-better-admin-settings.php' );

register_activation_hook( __FILE__, array( 'Better_Install', 'activate' ) );

/**
 * Bootstrap the plugin after WordPress loads.
 *
 * @since 1.0.0
 *
 * @return void
 */
function better_importer_init() {
	if ( is_admin() ) {
		Better_Install::maybe_install();
		Better_Import_Ajax::register();
		Better_Admin_UI::register();
		Better_Admin_Settings::register();
		add_action( 'admin_init', array( 'Better_Admin_UI', 'register_importer' ) );
	}

	Better_Install::schedule_cron();
}
add_action( 'plugins_loaded', 'better_importer_init' );

/**
 * Process abandoned import jobs from WP-Cron.
 *
 * Skips paused jobs; only queued, processing, and remapping jobs are picked up.
 *
 * @since 1.1.0
 *
 * @return void
 */
function better_importer_cron_process_batch() {
	global $wpdb;

	$table = $wpdb->prefix . 'better_import_jobs';
	$jobs  = $wpdb->get_col(
		"SELECT id FROM {$table} WHERE status IN ('queued', 'processing', 'remapping') ORDER BY updated_at ASC LIMIT 5"
	);

	if ( empty( $jobs ) ) {
		return;
	}

	$processor = new Better_Import_Processor();
	foreach ( $jobs as $job_id ) {
		$processor->process_batch( (int) $job_id );
	}
}
add_action( 'better_importer_process_batch', 'better_importer_cron_process_batch' );

/**
 * Remove abandoned chunked upload session directories.
 *
 * @since 1.5.0
 *
 * @return void
 */
function better_importer_cron_cleanup_chunks() {
	Better_Chunked_Upload::cleanup_abandoned();
}
add_action( 'better_importer_cleanup_chunks', 'better_importer_cron_cleanup_chunks' );

/**
 * Register WP-CLI commands when available.
 *
 * @since 1.3.0
 *
 * @return void
 */
function better_importer_register_cli() {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}

	better_importer_require( 'src/CLI/class-better-cli-command.php' );
	WP_CLI::add_command( 'better-importer', 'Better_CLI_Command' );
	WP_CLI::add_command( 'wxr-importer', 'Better_CLI_Command' );
}
add_action( 'plugins_loaded', 'better_importer_register_cli', 20 );
