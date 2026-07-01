<?php
/**
 * Plugin activation — creates custom tables for the 1.0 import engine.
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database installation and migration helpers.
 *
 * @since 1.0.0
 */
class Better_Install {

	/**
	 * Current schema version.
	 *
	 * @since 1.0.0
	 */
	const DB_VERSION = '1.2.0';

	/**
	 * Create or upgrade import database tables.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function install_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$jobs_table      = $wpdb->prefix . 'better_import_jobs';
		$queue_table     = $wpdb->prefix . 'better_import_queue';
		$log_table       = $wpdb->prefix . 'better_import_log';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_jobs = "CREATE TABLE {$jobs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'created',
			phase varchar(30) NOT NULL DEFAULT '',
			phase_cursor int(10) unsigned NOT NULL DEFAULT 0,
			file_path varchar(500) NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			total_posts int(10) unsigned NOT NULL DEFAULT 0,
			total_comments int(10) unsigned NOT NULL DEFAULT 0,
			total_terms int(10) unsigned NOT NULL DEFAULT 0,
			total_users int(10) unsigned NOT NULL DEFAULT 0,
			total_media int(10) unsigned NOT NULL DEFAULT 0,
			scanned_posts int(10) unsigned NOT NULL DEFAULT 0,
			scanned_comments int(10) unsigned NOT NULL DEFAULT 0,
			scanned_terms int(10) unsigned NOT NULL DEFAULT 0,
			scanned_users int(10) unsigned NOT NULL DEFAULT 0,
			scanned_media int(10) unsigned NOT NULL DEFAULT 0,
			imported_posts int(10) unsigned NOT NULL DEFAULT 0,
			imported_comments int(10) unsigned NOT NULL DEFAULT 0,
			imported_terms int(10) unsigned NOT NULL DEFAULT 0,
			imported_users int(10) unsigned NOT NULL DEFAULT 0,
			imported_media int(10) unsigned NOT NULL DEFAULT 0,
			skipped_posts int(10) unsigned NOT NULL DEFAULT 0,
			skipped_comments int(10) unsigned NOT NULL DEFAULT 0,
			skipped_terms int(10) unsigned NOT NULL DEFAULT 0,
			skipped_users int(10) unsigned NOT NULL DEFAULT 0,
			skipped_media int(10) unsigned NOT NULL DEFAULT 0,
			failed_items int(10) unsigned NOT NULL DEFAULT 0,
			options longtext DEFAULT NULL,
			preflight_data longtext DEFAULT NULL,
			item_manifest longtext DEFAULT NULL,
			mapping_state longtext DEFAULT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY user_id (user_id)
		) {$charset_collate};";

		$sql_queue = "CREATE TABLE {$queue_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			entity_index int(10) unsigned NOT NULL,
			entity_type varchar(20) NOT NULL,
			old_entity_id varchar(100) NOT NULL DEFAULT '',
			new_entity_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			step varchar(30) NOT NULL DEFAULT 'create',
			step_cursor int(10) unsigned NOT NULL DEFAULT 0,
			step_total int(10) unsigned NOT NULL DEFAULT 0,
			parsed_payload longblob DEFAULT NULL,
			payload_hash varchar(64) NOT NULL DEFAULT '',
			title varchar(500) DEFAULT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			error_message text DEFAULT NULL,
			error_code varchar(50) DEFAULT NULL,
			last_error_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_entity (job_id, entity_index),
			KEY job_status (job_id, status),
			KEY job_status_step (job_id, status, step),
			KEY job_status_entity (job_id, status, entity_index),
			KEY job_type_old (job_id, entity_type, old_entity_id)
		) {$charset_collate};";

		$sql_log = "CREATE TABLE {$log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			level varchar(10) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			entity_index int(10) unsigned DEFAULT NULL,
			context longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY job_level (job_id, level)
		) {$charset_collate};";

		dbDelta( $sql_jobs );
		dbDelta( $sql_queue );
		dbDelta( $sql_log );

		update_option( 'better_importer_db_version', self::DB_VERSION );

		self::maybe_upgrade_from_legacy();
		self::maybe_flag_legacy_data();
	}

	/**
	 * Migrate sites that still carry v3 experimental options/tables.
	 *
	 * Installs better_import_* tables and flags legacy data without dropping anything.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade_from_legacy() {
		if ( ! get_option( 'wxr_importer_db_version' ) ) {
			return;
		}

		if ( get_option( 'better_importer_legacy_migrated' ) ) {
			return;
		}

		self::maybe_flag_legacy_data();
		Better_Legacy_Cleanup::unschedule_legacy_cron();

		update_option( 'better_importer_legacy_migrated', current_time( 'mysql', true ) );

		/**
		 * Fires after the 1.0 engine detects and flags legacy v3 experimental data.
		 *
		 * @since 1.4.0
		 */
		do_action( 'better_importer.legacy.migrated' );
	}

	/**
	 * Flag legacy experimental tables without dropping them.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_flag_legacy_data() {
		if ( get_option( 'wxr_importer_db_version' ) && ! get_option( 'better_importer_legacy_flagged' ) ) {
			update_option( 'better_importer_legacy_flagged', true );
			update_option( 'better_importer_legacy_detected_at', current_time( 'mysql', true ) );
		}
	}

	/**
	 * Install or upgrade tables only when the schema version changed.
	 *
	 * Runs on every admin request, so it must be cheap. `dbDelta` is only
	 * invoked when the stored schema version differs from {@see DB_VERSION}.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( 'better_importer_db_version' ) === self::DB_VERSION ) {
			return;
		}

		self::install_tables();
	}

	/**
	 * Run install on plugin activation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_tables();
		self::schedule_cron();
	}

	/**
	 * Register the background batch cron event.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_interval' ) );

		if ( ! wp_next_scheduled( 'better_importer_process_batch' ) ) {
			wp_schedule_event( time(), 'better_importer_batch_interval', 'better_importer_process_batch' );
		}

		if ( ! wp_next_scheduled( 'better_importer_cleanup_chunks' ) ) {
			wp_schedule_event( time(), 'daily', 'better_importer_cleanup_chunks' );
		}
	}

	/**
	 * Add a frequent cron interval for abandoned imports.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, array<string, int|string>> $schedules Existing schedules.
	 *
	 * @return array<string, array<string, int|string>>
	 */
	public static function register_cron_interval( $schedules ) {
		if ( ! isset( $schedules['better_importer_batch_interval'] ) ) {
			$schedules['better_importer_batch_interval'] = array(
				'interval' => 60,
				'display'  => __( 'Every minute (Better Importer)', 'better-wordpress-importer' ),
			);
		}

		return $schedules;
	}
}
