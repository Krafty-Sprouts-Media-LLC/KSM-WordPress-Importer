<?php
/**
 * Intro screen and uploader (step 0).
 */

wp_enqueue_media();

$this->render_header();
?>

<div class="wxr-header">
	<span class="dashicons dashicons-upload"></span>
	<h1><?php esc_html_e( 'WordPress Importer', 'wordpress-importer' ) ?></h1>
</div>

<div class="narrow">

	<div class="wxr-card">
		<p><?php esc_html_e( 'Upload a WordPress export file (.xml) to import posts, pages, comments, custom fields, categories, and tags into this site.', 'wordpress-importer' ) ?></p>
	</div>

	<!-- ── Option 1: Browser upload ── -->
	<form action="<?php echo esc_attr( $this->get_url( 1 ) ) ?>" method="POST">

		<?php $this->render_upload_form() ?>

		<div class="wxr-or-divider"><?php esc_html_e( 'or', 'wordpress-importer' ) ?></div>

		<button type="button" class="button button-secondary upload-select">
			<span class="dashicons dashicons-admin-media" style="vertical-align:middle;margin-right:4px;"></span>
			<?php esc_html_e( 'Select from Media Library', 'wordpress-importer' ) ?>
		</button>

		<?php wp_nonce_field( 'import-upload' ) ?>
		<input type="hidden" id="import-selected-id" name="id" value="" />
	</form>

	<!-- ── Option 2: Local file path (for large files) ── -->
	<div class="wxr-or-divider"><?php esc_html_e( 'or for large files', 'wordpress-importer' ) ?></div>

	<div class="wxr-card">
		<h2 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#646970;margin:0 0 12px;padding:0 0 10px;border-bottom:1px solid #f0f0f1;">
			<?php esc_html_e( 'Use a file already on the server', 'wordpress-importer' ) ?>
		</h2>
		<p style="color:#50575e;font-size:13px;margin:0 0 12px;">
			<?php esc_html_e( 'Copy your .xml file directly into the WordPress uploads folder, then enter the full server path below. This bypasses browser upload limits entirely.', 'wordpress-importer' ) ?>
		</p>
		<p style="font-family:monospace;font-size:12px;background:#f6f7f7;padding:8px 10px;border-radius:3px;color:#1d2327;margin:0 0 14px;word-break:break-all;">
			<?php
			$upload_dir = wp_upload_dir();
			echo esc_html( $upload_dir['basedir'] );
			?>
		</p>
		<form action="<?php echo esc_url( $this->get_url( 1 ) ) ?>" method="POST">
			<label for="wxr-local-path" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">
				<?php esc_html_e( 'Full path to .xml file (no quotes):', 'wordpress-importer' ) ?>
			</label>
			<input
				type="text"
				id="wxr-local-path"
				name="local_file"
				class="large-text"
				placeholder="<?php echo esc_attr( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'import.xml' ); ?>"
				style="margin-bottom:10px;"
			/>
			<p style="font-size:12px;color:#646970;margin:0 0 10px;">
				<?php esc_html_e( 'Paste the path as-is — no surrounding quotes needed.', 'wordpress-importer' ) ?>
			</p>
			<?php wp_nonce_field( 'import-upload' ) ?>
			<?php submit_button( __( 'Use This File', 'wordpress-importer' ), 'secondary', 'submit-local', false ); ?>
		</form>
	</div>

</div>

<?php $this->render_footer();
