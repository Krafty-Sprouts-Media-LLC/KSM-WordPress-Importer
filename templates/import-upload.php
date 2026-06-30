<?php
/**
 * Import upload step template.
 *
 * @package Better_WordPress_Importer
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

/** @var Better_Admin_UI $ui */
/** @var string $context */
/** @var int $max_upload_size */
/** @var array $upload_dir */
/** @var array<int, Better_Import_Job> $recent_jobs */
?>
<div class="better-importer-card">
	<h2><?php esc_html_e( 'Upload WXR File', 'better-wordpress-importer' ); ?></h2>
	<div id="better-importer-plupload" class="better-importer-dropzone">
		<p class="description"><?php esc_html_e( 'Drop your .xml export file here or choose a file to upload.', 'better-wordpress-importer' ); ?></p>
		<button type="button" class="button button-primary" id="better-importer-browse"><?php esc_html_e( 'Choose File', 'better-wordpress-importer' ); ?></button>
		<input type="file" id="better-importer-file-fallback" accept=".xml,application/xml,text/xml" hidden />
		<div id="better-importer-upload-status" hidden></div>
	</div>
	<p class="description">
		<?php
		printf(
			/* translators: %s: maximum upload size */
			esc_html__( 'Maximum upload file size: %s.', 'better-wordpress-importer' ),
			esc_html( size_format( $max_upload_size ) )
		);
		?>
	</p>
</div>

<div class="better-importer-card">
	<h2><?php esc_html_e( 'Import from Server', 'better-wordpress-importer' ); ?></h2>
	<form method="get" action="<?php echo esc_url( $ui->get_step_url( 1, $context ) ); ?>">
		<?php if ( 'import' === $context ) : ?>
			<input type="hidden" name="import" value="wordpress" />
		<?php else : ?>
			<input type="hidden" name="page" value="better-importer" />
		<?php endif; ?>
		<input type="hidden" name="step" value="1" />
		<p>
			<label for="better-importer-local-file"><?php esc_html_e( 'Absolute path to WXR file', 'better-wordpress-importer' ); ?></label><br />
			<input type="text" class="large-text code" id="better-importer-local-file" name="local_file" value="" />
		</p>
		<p class="description">
			<?php
			if ( empty( $upload_dir['error'] ) ) {
				printf(
					/* translators: %s: uploads directory */
					esc_html__( 'Uploads directory: %s', 'better-wordpress-importer' ),
					esc_html( $upload_dir['basedir'] )
				);
			}
			?>
		</p>
		<?php submit_button( __( 'Continue to Import Settings', 'better-wordpress-importer' ), 'secondary', 'submit', false ); ?>
	</form>
</div>

<?php if ( ! empty( $recent_jobs ) ) : ?>
<div class="better-importer-card">
	<h2><?php esc_html_e( 'Recent Imports', 'better-wordpress-importer' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'better-wordpress-importer' ); ?></th>
				<th><?php esc_html_e( 'Status', 'better-wordpress-importer' ); ?></th>
				<th><?php esc_html_e( 'Entities', 'better-wordpress-importer' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $recent_jobs as $recent_job ) : ?>
				<tr>
					<td><?php echo esc_html( $recent_job->created_at ); ?></td>
					<td><?php echo esc_html( ucfirst( $recent_job->status ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $recent_job->manifest_entity_total() ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p><a href="<?php echo esc_url( admin_url( 'tools.php?page=better-importer-history' ) ); ?>"><?php esc_html_e( 'View all import history', 'better-wordpress-importer' ); ?></a></p>
</div>
<?php endif; ?>
