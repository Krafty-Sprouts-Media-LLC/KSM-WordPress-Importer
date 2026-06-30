<?php
/**
 * Settings and maintenance template.
 *
 * @package Better_WordPress_Importer
 * @since 1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * @var array<string, mixed> $status
 * @var array<string, mixed> $last_maintenance
 * @var Better_Admin_UI      $ui
 */
?>
<div class="better-importer-settings">
	<?php settings_errors( 'better_importer_settings' ); ?>

	<div class="better-importer-card">
		<h2><?php esc_html_e( 'Legacy status', 'better-wordpress-importer' ); ?></h2>
		<?php if ( ! empty( $status['has_legacy_data'] ) ) : ?>
			<p><?php esc_html_e( 'Legacy experimental data from the v3 engine was detected on this site.', 'better-wordpress-importer' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'No legacy experimental artifacts were detected.', 'better-wordpress-importer' ); ?></p>
		<?php endif; ?>

		<table class="widefat striped better-importer-status-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Legacy DB version', 'better-wordpress-importer' ); ?></th>
					<td><?php echo esc_html( $status['legacy_db_version'] ? $status['legacy_db_version'] : '—' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Legacy jobs table', 'better-wordpress-importer' ); ?></th>
					<td>
						<?php
						if ( ! empty( $status['legacy_tables']['jobs']['exists'] ) ) {
							printf(
								/* translators: %d: row count */
								esc_html__( 'Present (%d rows)', 'better-wordpress-importer' ),
								(int) $status['legacy_tables']['jobs']['rows']
							);
						} else {
							esc_html_e( 'Not present', 'better-wordpress-importer' );
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Legacy items table', 'better-wordpress-importer' ); ?></th>
					<td>
						<?php
						if ( ! empty( $status['legacy_tables']['items']['exists'] ) ) {
							printf(
								/* translators: %d: row count */
								esc_html__( 'Present (%d rows)', 'better-wordpress-importer' ),
								(int) $status['legacy_tables']['items']['rows']
							);
						} else {
							esc_html_e( 'Not present', 'better-wordpress-importer' );
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Legacy import meta rows', 'better-wordpress-importer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $status['legacy_meta_rows'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Temporary import meta rows', 'better-wordpress-importer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $status['temp_meta_rows'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Chunk upload directories', 'better-wordpress-importer' ); ?></th>
					<td><?php echo esc_html( (string) count( $status['chunk_directories'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Diagnostic log files', 'better-wordpress-importer' ); ?></th>
					<td><?php echo esc_html( (string) count( $status['diagnostic_log_files'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Legacy cron events', 'better-wordpress-importer' ); ?></th>
					<td>
						<?php
						echo ! empty( $status['legacy_cron_scheduled'] )
							? esc_html__( 'Scheduled', 'better-wordpress-importer' )
							: esc_html__( 'None', 'better-wordpress-importer' );
						?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="better-importer-card">
		<h2><?php esc_html_e( 'Maintenance', 'better-wordpress-importer' ); ?></h2>
		<p><?php esc_html_e( 'These actions are irreversible except where noted. Imported posts and pages are never deleted.', 'better-wordpress-importer' ); ?></p>

		<form method="post" action="" class="better-importer-maintenance-form">
			<?php wp_nonce_field( 'better-importer-settings', 'better_importer_settings_nonce' ); ?>

			<p>
				<button type="submit" class="button" name="better_importer_settings_action" value="cleanup_chunks">
					<?php esc_html_e( 'Clean up temporary chunk upload directories', 'better-wordpress-importer' ); ?>
				</button>
			</p>

			<p>
				<button type="submit" class="button" name="better_importer_settings_action" value="cleanup_legacy_meta">
					<?php esc_html_e( 'Remove legacy import metadata (_wxr_import_*)', 'better-wordpress-importer' ); ?>
				</button>
			</p>

			<p>
				<button type="submit" class="button" name="better_importer_settings_action" value="cleanup_temp_meta">
					<?php esc_html_e( 'Remove temporary import metadata (_better_import_*)', 'better-wordpress-importer' ); ?>
				</button>
			</p>

			<p>
				<button type="submit" class="button" name="better_importer_settings_action" value="remove_diagnostic_logs">
					<?php esc_html_e( 'Remove diagnostic log files from plugin directory', 'better-wordpress-importer' ); ?>
				</button>
			</p>

			<p>
				<button type="submit" class="button" name="better_importer_settings_action" value="unschedule_legacy_cron">
					<?php esc_html_e( 'Clear legacy cron events', 'better-wordpress-importer' ); ?>
				</button>
			</p>

			<hr />

			<p><strong><?php esc_html_e( 'Drop legacy experimental tables', 'better-wordpress-importer' ); ?></strong></p>
			<p class="description">
				<?php esc_html_e( 'Permanently deletes wp_wxr_import_jobs and wp_wxr_import_items. The new better_import_* tables are not affected.', 'better-wordpress-importer' ); ?>
			</p>
			<p>
				<label>
					<input type="checkbox" name="better_importer_confirm_drop" value="1" />
					<?php esc_html_e( 'I understand this cannot be undone.', 'better-wordpress-importer' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-secondary" name="better_importer_settings_action" value="drop_legacy_tables">
					<?php esc_html_e( 'Drop legacy experimental tables', 'better-wordpress-importer' ); ?>
				</button>
			</p>
		</form>
	</div>

	<?php if ( ! empty( $last_maintenance ) && is_array( $last_maintenance ) ) : ?>
		<div class="better-importer-card">
			<h2><?php esc_html_e( 'Last maintenance action', 'better-wordpress-importer' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: action key, 2: timestamp */
					esc_html__( 'Action: %1$s at %2$s', 'better-wordpress-importer' ),
					esc_html( isset( $last_maintenance['action'] ) ? $last_maintenance['action'] : '' ),
					esc_html( isset( $last_maintenance['at'] ) ? $last_maintenance['at'] : '' )
				);
				?>
			</p>
		</div>
	<?php endif; ?>
</div>
