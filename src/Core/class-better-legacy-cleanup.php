<?php
/**
 * Legacy v3 experimental data detection and optional cleanup.
 *
 * @package Better_WordPress_Importer
 * @since 1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maintenance helpers for legacy wxr_import_* artifacts.
 *
 * @since 1.4.0
 */
class Better_Legacy_Cleanup {

	/**
	 * Legacy post meta keys from the v3 experimental engine.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	const LEGACY_POST_META_KEYS = array(
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

	/**
	 * Temporary post meta keys used by the 1.0 engine during imports.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	const TEMP_POST_META_KEYS = array(
		'_better_import_parent',
		'_better_import_user_slug',
		'_better_import_job_id',
		'_better_import_original_id',
		'_better_import_meta_offset',
		'_better_import_meta_total',
		'_better_import_meta_cursor',
	);

	/**
	 * Legacy comment meta keys.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	const LEGACY_COMMENT_META_KEYS = array(
		'_wxr_import_parent',
		'_wxr_import_user',
	);

	/**
	 * Diagnostic log filenames that must never ship in the plugin root.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	const DIAGNOSTIC_LOG_FILES = array(
		'wxr-upload-debug.log',
	);

	/**
	 * Summarize detectable legacy artifacts.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status() {
		global $wpdb;

		$jobs_table  = $wpdb->prefix . 'wxr_import_jobs';
		$items_table = $wpdb->prefix . 'wxr_import_items';
		$jobs_exist  = self::table_exists( $jobs_table );
		$items_exist = self::table_exists( $items_table );

		$status = array(
			'legacy_db_version'     => get_option( 'wxr_importer_db_version', '' ),
			'legacy_flagged'        => (bool) get_option( 'better_importer_legacy_flagged', false ),
			'legacy_detected_at'    => get_option( 'better_importer_legacy_detected_at', '' ),
			'legacy_migrated'       => (bool) get_option( 'better_importer_legacy_migrated', false ),
			'legacy_tables'         => array(
				'jobs'  => array(
					'exists' => $jobs_exist,
					'rows'   => $jobs_exist ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table}" ) : 0,
				),
				'items' => array(
					'exists' => $items_exist,
					'rows'   => $items_exist ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_table}" ) : 0,
				),
			),
			'legacy_meta_rows'      => self::count_legacy_meta_rows(),
			'temp_meta_rows'        => self::count_temp_meta_rows(),
			'chunk_directories'     => self::find_chunk_directories(),
			'diagnostic_log_files'  => self::find_diagnostic_logs(),
			'legacy_cron_scheduled' => self::has_legacy_cron_events(),
		);

		$status['has_legacy_data'] = (
			! empty( $status['legacy_db_version'] )
			|| $jobs_exist
			|| $items_exist
			|| $status['legacy_meta_rows'] > 0
			|| ! empty( $status['chunk_directories'] )
			|| ! empty( $status['diagnostic_log_files'] )
			|| $status['legacy_cron_scheduled']
		);

		return $status;
	}

	/**
	 * Remove legacy _wxr_import_* post and comment meta.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, int>
	 */
	public static function cleanup_legacy_import_meta() {
		global $wpdb;

		$deleted = array(
			'postmeta'    => 0,
			'commentmeta' => 0,
		);

		foreach ( self::LEGACY_POST_META_KEYS as $meta_key ) {
			$deleted['postmeta'] += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$meta_key
				)
			);
		}

		foreach ( self::LEGACY_COMMENT_META_KEYS as $meta_key ) {
			$deleted['commentmeta'] += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->commentmeta} WHERE meta_key = %s",
					$meta_key
				)
			);
		}

		update_option( 'wxr_importer_legacy_cleaned', true );

		return $deleted;
	}

	/**
	 * Remove temporary _better_import_* post meta left after imports.
	 *
	 * @since 1.4.0
	 *
	 * @return int Number of rows deleted.
	 */
	public static function cleanup_temp_import_meta() {
		global $wpdb;

		$deleted = 0;

		foreach ( self::TEMP_POST_META_KEYS as $meta_key ) {
			$deleted += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$meta_key
				)
			);
		}

		return $deleted;
	}

	/**
	 * Drop legacy experimental custom tables.
	 *
	 * Does not touch better_import_* tables.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, bool>
	 */
	public static function drop_legacy_tables() {
		global $wpdb;

		$jobs_table  = $wpdb->prefix . 'wxr_import_jobs';
		$items_table = $wpdb->prefix . 'wxr_import_items';

		$result = array(
			'jobs_dropped'  => false,
			'items_dropped' => false,
		);

		if ( self::table_exists( $items_table ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$items_table}" );
			$result['items_dropped'] = ! self::table_exists( $items_table );
		}

		if ( self::table_exists( $jobs_table ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$jobs_table}" );
			$result['jobs_dropped'] = ! self::table_exists( $jobs_table );
		}

		delete_option( 'wxr_importer_db_version' );

		return $result;
	}

	/**
	 * Remove abandoned chunk upload directories from uploads.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, int>
	 */
	public static function cleanup_chunk_directories() {
		$removed = array(
			'directories' => 0,
			'files'       => 0,
		);

		foreach ( self::find_chunk_directories() as $directory ) {
			$count = self::delete_directory_recursive( $directory );
			if ( $count['directories'] > 0 || $count['files'] > 0 ) {
				$removed['directories'] += $count['directories'];
				$removed['files']       += $count['files'];
			}
		}

		return $removed;
	}

	/**
	 * Delete diagnostic log files from the plugin root.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, string>
	 */
	public static function remove_diagnostic_logs() {
		$removed = array();

		foreach ( self::find_diagnostic_logs() as $path ) {
			if ( is_writable( $path ) && unlink( $path ) ) {
				$removed[] = basename( $path );
			}
		}

		return $removed;
	}

	/**
	 * Unschedule legacy v3 cron hooks.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function unschedule_legacy_cron() {
		wp_clear_scheduled_hook( 'wxr_importer_process_batch' );
		wp_clear_scheduled_hook( 'wxr_importer_cleanup_chunks' );
	}

	/**
	 * Record a maintenance action for the settings screen.
	 *
	 * @since 1.4.0
	 *
	 * @param string               $action Action key.
	 * @param array<string, mixed> $result Result payload.
	 *
	 * @return void
	 */
	public static function record_maintenance_action( $action, array $result ) {
		update_option(
			'better_importer_last_maintenance',
			array(
				'action'  => sanitize_key( $action ),
				'user_id' => get_current_user_id(),
				'at'      => current_time( 'mysql', true ),
				'result'  => $result,
			)
		);

		/**
		 * Fires after a Better Importer maintenance action completes.
		 *
		 * @since 1.4.0
		 *
		 * @param string               $action Action key.
		 * @param array<string, mixed> $result Result payload.
		 */
		do_action( 'better_importer.maintenance.completed', $action, $result );
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @since 1.4.0
	 *
	 * @param string $table Full table name including prefix.
	 *
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		return $found === $table;
	}

	/**
	 * Count legacy import meta rows.
	 *
	 * @since 1.4.0
	 *
	 * @return int
	 */
	protected static function count_legacy_meta_rows() {
		global $wpdb;

		$total   = 0;
		$pm_keys = array_map( 'esc_sql', self::LEGACY_POST_META_KEYS );
		$cm_keys = array_map( 'esc_sql', self::LEGACY_COMMENT_META_KEYS );

		if ( ! empty( $pm_keys ) ) {
			$total += (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('" . implode( "','", $pm_keys ) . "')"
			);
		}

		if ( ! empty( $cm_keys ) ) {
			$total += (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key IN ('" . implode( "','", $cm_keys ) . "')"
			);
		}

		return $total;
	}

	/**
	 * Count temporary 1.0 import meta rows.
	 *
	 * @since 1.4.0
	 *
	 * @return int
	 */
	protected static function count_temp_meta_rows() {
		global $wpdb;

		$keys = array_map( 'esc_sql', self::TEMP_POST_META_KEYS );
		if ( empty( $keys ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('" . implode( "','", $keys ) . "')"
		);
	}

	/**
	 * Find chunk upload directories under the uploads folder.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, string>
	 */
	protected static function find_chunk_directories() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return array();
		}

		$candidates = array(
			trailingslashit( $upload_dir['basedir'] ) . 'better-importer-chunks',
			trailingslashit( $upload_dir['basedir'] ) . 'wxr-importer-chunks',
		);

		$found = array();
		foreach ( $candidates as $path ) {
			if ( is_dir( $path ) ) {
				$found[] = wp_normalize_path( $path );
			}
		}

		return $found;
	}

	/**
	 * Find diagnostic log files in the plugin root.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, string>
	 */
	protected static function find_diagnostic_logs() {
		$found = array();

		foreach ( self::DIAGNOSTIC_LOG_FILES as $filename ) {
			$path = wp_normalize_path( BETTER_IMPORTER_PATH . $filename );
			if ( is_file( $path ) ) {
				$found[] = $path;
			}
		}

		return $found;
	}

	/**
	 * Whether legacy cron events are still scheduled.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	protected static function has_legacy_cron_events() {
		return (bool) wp_next_scheduled( 'wxr_importer_process_batch' )
			|| (bool) wp_next_scheduled( 'wxr_importer_cleanup_chunks' );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @since 1.4.0
	 *
	 * @param string $directory Absolute directory path.
	 *
	 * @return array<string, int>
	 */
	protected static function delete_directory_recursive( $directory ) {
		$counts = array(
			'directories' => 0,
			'files'       => 0,
		);

		if ( ! is_dir( $directory ) ) {
			return $counts;
		}

		$items = scandir( $directory );
		if ( false === $items ) {
			return $counts;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$nested = self::delete_directory_recursive( $path );
				$counts['directories'] += $nested['directories'];
				$counts['files']       += $nested['files'];
				if ( rmdir( $path ) ) {
					$counts['directories']++;
				}
			} elseif ( is_file( $path ) && unlink( $path ) ) {
				$counts['files']++;
			}
		}

		if ( rmdir( $directory ) ) {
			$counts['directories']++;
		}

		return $counts;
	}
}
