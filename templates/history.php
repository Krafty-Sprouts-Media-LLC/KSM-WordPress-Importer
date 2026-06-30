<?php
/**
 * Import history template.
 *
 * @package Better_WordPress_Importer
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

/** @var Better_Admin_UI $ui */
/** @var array<int, Better_Import_Job> $jobs */
?>
<div class="better-importer-card">
	<h2><?php esc_html_e( 'Import History', 'better-wordpress-importer' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'better-wordpress-importer' ); ?></th>
				<th><?php esc_html_e( 'File', 'better-wordpress-importer' ); ?></th>
				<th><?php esc_html_e( 'Status', 'better-wordpress-importer' ); ?></th>
				<th><?php esc_html_e( 'Entities', 'better-wordpress-importer' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'better-wordpress-importer' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $jobs ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No imports yet.', 'better-wordpress-importer' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $jobs as $job ) : ?>
					<tr>
						<td><?php echo esc_html( $job->created_at ); ?></td>
						<td><?php echo esc_html( basename( $job->file_path ) ); ?></td>
						<td><?php echo esc_html( ucfirst( $job->status ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $job->manifest_entity_total() ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'tools.php?page=better-importer&job_id=' . $job->id ) ); ?>">
								<?php esc_html_e( 'View', 'better-wordpress-importer' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
