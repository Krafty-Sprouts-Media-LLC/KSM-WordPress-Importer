<?php
/**
 * Plugin uninstall — removes custom tables, options, and import meta.
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}better_import_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}better_import_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}better_import_jobs" );

delete_option( 'better_importer_db_version' );
delete_option( 'better_importer_legacy_flagged' );
delete_option( 'better_importer_legacy_detected_at' );
delete_option( 'better_importer_legacy_migrated' );
delete_option( 'better_importer_last_maintenance' );

// Legacy experimental tables are not dropped automatically.
delete_option( 'wxr_importer_db_version' );
delete_option( 'wxr_importer_legacy_cleaned' );
delete_transient( 'wxr_importer_upgrade_notice' );

wp_clear_scheduled_hook( 'better_importer_process_batch' );
wp_clear_scheduled_hook( 'better_importer_cleanup_chunks' );
wp_clear_scheduled_hook( 'wxr_importer_process_batch' );
wp_clear_scheduled_hook( 'wxr_importer_cleanup_chunks' );
