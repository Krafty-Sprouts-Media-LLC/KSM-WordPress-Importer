<?php
/**
 * Intro screen and uploader (step 0).
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

wp_enqueue_media();

$upload_dir = wp_upload_dir();
$this->render_header();
?>

<div class="wxr-header">
	<span class="dashicons dashicons-upload"></span>
	<h1><?php esc_html_e( 'Better WordPress Importer', 'wordpress-importer' ); ?></h1>
</div>

<nav class="wxr-step-nav" aria-label="<?php esc_attr_e( 'Import steps', 'wordpress-importer' ); ?>">
	<ol>
		<li class="is-current"><span><?php esc_html_e( 'Choose file', 'wordpress-importer' ); ?></span></li>
		<li><span><?php esc_html_e( 'Settings', 'wordpress-importer' ); ?></span></li>
		<li><span><?php esc_html_e( 'Import', 'wordpress-importer' ); ?></span></li>
	</ol>
</nav>

<?php if ( ! empty( $resume_job ) ) : ?>
<div class="wxr-banner wxr-banner-info">
	<span class="dashicons dashicons-update"></span>
	<p>
		<?php
		printf(
			/* translators: %d: percent complete */
			esc_html__( 'Import in progress — %d%% complete.', 'wordpress-importer' ),
			(int) $resume_job->percent_complete()
		);
		?>
		<a href="<?php echo esc_url( add_query_arg( array( 'import' => 'wordpress', 'step' => 3, 'job_id' => $resume_job->id ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( 'Resume import', 'wordpress-importer' ); ?>
		</a>
	</p>
</div>
<?php endif; ?>

<?php if ( ! empty( $last_job ) && empty( $resume_job ) ) : ?>
<?php $report = $last_job->get_final_report(); ?>
<div class="wxr-banner wxr-banner-success">
	<span class="dashicons dashicons-yes-alt"></span>
	<p>
		<?php
		printf(
			/* translators: %d: number of posts imported */
			esc_html__( 'Last import finished — %d posts imported.', 'wordpress-importer' ),
			(int) $report['imported']['posts']
		);
		?>
		<a href="<?php echo esc_url( add_query_arg( array( 'import' => 'wordpress', 'step' => 3, 'job_id' => $last_job->id ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( 'View report', 'wordpress-importer' ); ?>
		</a>
	</p>
</div>
<?php endif; ?>

<div class="wxr-page">

	<div class="wxr-card wxr-intro-lead">
		<p><?php esc_html_e( 'Import posts, pages, comments, custom fields, categories, and tags from a WordPress export (.xml) file.', 'wordpress-importer' ); ?></p>
	</div>

	<div class="wxr-source-picker">
		<div class="wxr-tab-nav" role="tablist" aria-label="<?php esc_attr_e( 'File source', 'wordpress-importer' ); ?>">
			<button type="button" class="wxr-tab is-active" role="tab" id="wxr-tab-upload" aria-selected="true" aria-controls="wxr-panel-upload" data-wxr-tab="upload">
				<span class="dashicons dashicons-upload"></span>
				<?php esc_html_e( 'Upload file', 'wordpress-importer' ); ?>
			</button>
			<button type="button" class="wxr-tab" role="tab" id="wxr-tab-media" aria-selected="false" aria-controls="wxr-panel-media" data-wxr-tab="media">
				<span class="dashicons dashicons-admin-media"></span>
				<?php esc_html_e( 'Media library', 'wordpress-importer' ); ?>
			</button>
			<button type="button" class="wxr-tab" role="tab" id="wxr-tab-server" aria-selected="false" aria-controls="wxr-panel-server" data-wxr-tab="server">
				<span class="dashicons dashicons-admin-site-alt3"></span>
				<?php esc_html_e( 'Server path', 'wordpress-importer' ); ?>
			</button>
		</div>

		<div class="wxr-tab-panels">
			<div class="wxr-tab-panel is-active" role="tabpanel" id="wxr-panel-upload" aria-labelledby="wxr-tab-upload">
				<div class="wxr-card">
					<h2><?php esc_html_e( 'Upload from your computer', 'wordpress-importer' ); ?></h2>
					<p class="wxr-panel-desc"><?php esc_html_e( 'Best for small and medium exports. Large files are uploaded in chunks automatically.', 'wordpress-importer' ); ?></p>
					<form action="<?php echo esc_attr( $this->get_url( 1 ) ); ?>" method="POST">
						<?php $this->render_upload_form(); ?>
						<?php wp_nonce_field( 'import-upload' ); ?>
						<input type="hidden" id="import-selected-id" name="id" value="" />
					</form>
				</div>
			</div>

			<div class="wxr-tab-panel" role="tabpanel" id="wxr-panel-media" aria-labelledby="wxr-tab-media" hidden>
				<div class="wxr-card">
					<h2><?php esc_html_e( 'Select from Media Library', 'wordpress-importer' ); ?></h2>
					<p class="wxr-panel-desc"><?php esc_html_e( 'Choose an XML file you have already uploaded to this site.', 'wordpress-importer' ); ?></p>
					<form action="<?php echo esc_attr( $this->get_url( 1 ) ); ?>" method="POST" id="wxr-media-form">
						<button type="button" class="button button-primary button-hero upload-select">
							<span class="dashicons dashicons-admin-media"></span>
							<?php esc_html_e( 'Browse Media Library', 'wordpress-importer' ); ?>
						</button>
						<?php wp_nonce_field( 'import-upload' ); ?>
						<input type="hidden" id="import-selected-id-media" name="id" value="" />
					</form>
				</div>
			</div>

			<div class="wxr-tab-panel" role="tabpanel" id="wxr-panel-server" aria-labelledby="wxr-tab-server" hidden>
				<div class="wxr-card">
					<h2><?php esc_html_e( 'Use a file on the server', 'wordpress-importer' ); ?></h2>
					<p class="wxr-panel-desc"><?php esc_html_e( 'Copy your .xml into the uploads folder, then paste the full path below. Bypasses browser upload limits.', 'wordpress-importer' ); ?></p>
					<p class="wxr-path-hint">
						<strong><?php esc_html_e( 'Uploads folder:', 'wordpress-importer' ); ?></strong>
						<code><?php echo esc_html( $upload_dir['basedir'] ); ?></code>
					</p>
					<form action="<?php echo esc_url( $this->get_url( 1 ) ); ?>" method="POST">
						<label class="wxr-field-label" for="wxr-local-path"><?php esc_html_e( 'Full path to .xml file', 'wordpress-importer' ); ?></label>
						<input
							type="text"
							id="wxr-local-path"
							name="local_file"
							class="large-text"
							placeholder="<?php echo esc_attr( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'export.xml' ); ?>"
						/>
						<p class="wxr-field-help"><?php esc_html_e( 'Paste the path as-is — no surrounding quotes.', 'wordpress-importer' ); ?></p>
						<?php wp_nonce_field( 'import-upload' ); ?>
						<?php submit_button( __( 'Use This File', 'wordpress-importer' ), 'primary', 'submit-local', false ); ?>
					</form>
				</div>
			</div>
		</div>
	</div>

</div>

<?php $this->render_footer();
