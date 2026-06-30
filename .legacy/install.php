<?php
/**
 * Plugin activation — creates custom tables for import jobs.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create or upgrade import job database tables.
 *
 * @since 3.0.0
 *
 * @return void
 */
function wxr_importer_install_tables() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$jobs_table      = $wpdb->prefix . 'wxr_import_jobs';
	$items_table     = $wpdb->prefix . 'wxr_import_items';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql_jobs = "CREATE TABLE {$jobs_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		status varchar(20) NOT NULL DEFAULT 'pending',
		file_path varchar(500) NOT NULL,
		attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
		xml_cursor_item int(10) unsigned NOT NULL DEFAULT 0,
		total_posts int(10) unsigned NOT NULL DEFAULT 0,
		total_comments int(10) unsigned NOT NULL DEFAULT 0,
		total_terms int(10) unsigned NOT NULL DEFAULT 0,
		total_users int(10) unsigned NOT NULL DEFAULT 0,
		total_media int(10) unsigned NOT NULL DEFAULT 0,
		processed_posts int(10) unsigned NOT NULL DEFAULT 0,
		processed_comments int(10) unsigned NOT NULL DEFAULT 0,
		processed_terms int(10) unsigned NOT NULL DEFAULT 0,
		processed_users int(10) unsigned NOT NULL DEFAULT 0,
		processed_media int(10) unsigned NOT NULL DEFAULT 0,
		failed_items int(10) unsigned NOT NULL DEFAULT 0,
		skipped_items int(10) unsigned NOT NULL DEFAULT 0,
		options longtext DEFAULT NULL,
		preflight_data longtext DEFAULT NULL,
		item_manifest longtext DEFAULT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY status (status),
		KEY user_id (user_id)
	) {$charset_collate};";

	$sql_items = "CREATE TABLE {$items_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		job_id bigint(20) unsigned NOT NULL,
		entity_type varchar(20) NOT NULL,
		old_id varchar(100) NOT NULL DEFAULT '',
		new_id bigint(20) unsigned DEFAULT NULL,
		title varchar(500) DEFAULT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
		error_message text DEFAULT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY job_status (job_id, status),
		KEY job_type (job_id, entity_type)
	) {$charset_collate};";

	dbDelta( $sql_jobs );
	dbDelta( $sql_items );

	update_option( 'wxr_importer_db_version', '3.0.3' );
}

/**
 * Remove temporary _wxr_import_* post and comment meta left after imports.
 *
 * @since 3.0.0
 *
 * @return void
 */
function wxr_importer_cleanup_import_meta() {
	global $wpdb;

	$meta_keys = array(
		'_wxr_import_parent',
		'_wxr_import_user_slug',
		'_wxr_import_has_attachment_refs',
		'_wxr_import_menu_item',
		'_wxr_import_term',
		'_wxr_import_pending_attachment_url',
		'_wxr_import_user',
		'_wxr_import_settings',
		'_wxr_import_info',
		'_wxr_import_job_id',
		'_wxr_import_original_id',
		'_wxr_import_meta_offset',
		'_wxr_import_meta_total',
	);

	foreach ( $meta_keys as $meta_key ) {
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
	}

	$wpdb->delete( $wpdb->commentmeta, array( 'meta_key' => '_wxr_import_parent' ), array( '%s' ) );
	$wpdb->delete( $wpdb->commentmeta, array( 'meta_key' => '_wxr_import_user' ), array( '%s' ) );
}

/**
 * Clean up legacy SSE import session meta from pre-3.0 imports.
 *
 * @since 3.0.0
 *
 * @return void
 */
function wxr_importer_migrate_legacy_sessions() {
	if ( get_option( 'wxr_importer_legacy_cleaned', false ) ) {
		return;
	}

	wxr_importer_cleanup_import_meta();

	update_option( 'wxr_importer_legacy_cleaned', true );
	set_transient( 'wxr_importer_upgrade_notice', 1, DAY_IN_SECONDS );
}

/**
 * Run install on plugin activation.
 *
 * @since 3.0.0
 *
 * @return void
 */
function wxr_importer_activate() {
	wxr_importer_install_tables();

	if ( ! wp_next_scheduled( 'wxr_importer_process_batch' ) ) {
		wp_schedule_event( time(), 'wxr_importer_batch_interval', 'wxr_importer_process_batch' );
	}

	if ( ! wp_next_scheduled( 'wxr_importer_cleanup_chunks' ) ) {
		wp_schedule_event( time(), 'daily', 'wxr_importer_cleanup_chunks' );
	}
}
