<?php
/**
 * Plugin uninstall — removes custom tables, options, and import meta.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wxr_import_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wxr_import_jobs" );

delete_option( 'wxr_importer_db_version' );
delete_option( 'wxr_importer_legacy_cleaned' );
delete_transient( 'wxr_importer_upgrade_notice' );

require_once dirname( __FILE__ ) . '/install.php';
wxr_importer_cleanup_import_meta();

$upload_dir = wp_upload_dir();
if ( empty( $upload_dir['error'] ) ) {
	$chunk_base = trailingslashit( $upload_dir['basedir'] ) . 'wxr-importer-chunks';
	if ( is_dir( $chunk_base ) ) {
		$dirs = glob( trailingslashit( $chunk_base ) . '*', GLOB_ONLYDIR );
		if ( is_array( $dirs ) ) {
			foreach ( $dirs as $dir ) {
				$files = glob( trailingslashit( $dir ) . '*' );
				if ( is_array( $files ) ) {
					foreach ( $files as $file ) {
						wp_delete_file( $file );
					}
				}
				@rmdir( $dir );
			}
		}
		@rmdir( $chunk_base );
	}
}

wp_clear_scheduled_hook( 'wxr_importer_process_batch' );
wp_clear_scheduled_hook( 'wxr_importer_cleanup_chunks' );
