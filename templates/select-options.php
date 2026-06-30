<?php
/**
 * Import settings and author mapping (step 1).
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

$this->render_header();

$generator = $data->generator;
if ( preg_match( '#^http://wordpress\.org/\?v=(\d+\.\d+\.\d+)$#', $generator, $matches ) ) {
	$generator = sprintf( __( 'WordPress %s', 'wordpress-importer' ), $matches[1] );
}
?>

<div class="wxr-header">
	<span class="dashicons dashicons-migrate"></span>
	<h1><?php esc_html_e( 'Import Settings', 'wordpress-importer' ); ?></h1>
</div>

<nav class="wxr-step-nav" aria-label="<?php esc_attr_e( 'Import steps', 'wordpress-importer' ); ?>">
	<ol>
		<li class="is-done"><span><?php esc_html_e( 'Choose file', 'wordpress-importer' ); ?></span></li>
		<li class="is-current"><span><?php esc_html_e( 'Settings', 'wordpress-importer' ); ?></span></li>
		<li><span><?php esc_html_e( 'Import', 'wordpress-importer' ); ?></span></li>
	</ol>
</nav>

<div class="wxr-page">

<div class="wxr-settings-grid">

	<div class="wxr-card">
		<h2><?php esc_html_e( 'What will be imported', 'wordpress-importer' ); ?></h2>
		<div class="wxr-summary-grid">
			<div class="wxr-summary-item">
				<span class="dashicons dashicons-admin-post"></span>
				<span><strong><?php echo esc_html( number_format_i18n( $data->post_count ) ); ?></strong><br><?php esc_html_e( 'Posts', 'wordpress-importer' ); ?></span>
			</div>
			<div class="wxr-summary-item">
				<span class="dashicons dashicons-admin-media"></span>
				<span><strong><?php echo esc_html( number_format_i18n( $data->media_count ) ); ?></strong><br><?php esc_html_e( 'Media', 'wordpress-importer' ); ?></span>
			</div>
			<div class="wxr-summary-item">
				<span class="dashicons dashicons-admin-users"></span>
				<span><strong><?php echo esc_html( number_format_i18n( count( $data->users ) ) ); ?></strong><br><?php esc_html_e( 'Users', 'wordpress-importer' ); ?></span>
			</div>
			<div class="wxr-summary-item">
				<span class="dashicons dashicons-admin-comments"></span>
				<span><strong><?php echo esc_html( number_format_i18n( $data->comment_count ) ); ?></strong><br><?php esc_html_e( 'Comments', 'wordpress-importer' ); ?></span>
			</div>
			<div class="wxr-summary-item">
				<span class="dashicons dashicons-category"></span>
				<span><strong><?php echo esc_html( number_format_i18n( $data->term_count ) ); ?></strong><br><?php esc_html_e( 'Terms', 'wordpress-importer' ); ?></span>
			</div>
		</div>
	</div>

	<div class="wxr-card">
		<h2><?php esc_html_e( 'Source', 'wordpress-importer' ); ?></h2>
		<ul class="wxr-meta-list">
			<li>
				<strong><?php esc_html_e( 'Site:', 'wordpress-importer' ); ?></strong>
				<?php echo wp_kses( sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( $data->home ),
					esc_html( $data->title )
				), array( 'a' => array( 'href' => array() ) ) ); ?>
			</li>
			<li><strong><?php esc_html_e( 'Generator:', 'wordpress-importer' ); ?></strong> <?php echo esc_html( $generator ); ?></li>
			<li><strong><?php esc_html_e( 'Format:', 'wordpress-importer' ); ?></strong> WXR v<?php echo esc_html( $data->version ); ?></li>
		</ul>
	</div>

</div>

<form action="<?php echo esc_url( $this->get_url( 2 ) ); ?>" method="post" class="wxr-settings-form">

	<?php if ( ! empty( $data->users ) ) : ?>

		<div class="wxr-card">
			<h2><?php esc_html_e( 'Assign Authors', 'wordpress-importer' ); ?></h2>
			<p><?php echo wp_kses(
				__( 'Map authors from the import file to existing users on this site, or create new users. Posts without a match will be assigned to the current user.', 'wordpress-importer' ),
				array( 'code' => array() )
			); ?></p>

			<?php if ( $this->allow_create_users() ) : ?>
				<p class="wxr-field-help"><?php printf(
					esc_html__( 'New users will be created with a random password and the %s role.', 'wordpress-importer' ),
					'<strong>' . esc_html( get_option( 'default_role' ) ) . '</strong>'
				); ?></p>
			<?php endif; ?>

			<ol id="authors">
				<?php foreach ( $data->users as $index => $users ) : ?>
					<li><?php $this->author_select( $index, $users['data'] ); ?></li>
				<?php endforeach; ?>
			</ol>
		</div>

	<?php endif; ?>

	<?php if ( $this->allow_fetch_attachments() ) : ?>

		<div class="wxr-card">
			<h2><?php esc_html_e( 'Attachments', 'wordpress-importer' ); ?></h2>
			<p>
				<label>
					<input type="checkbox" value="1" name="fetch_attachments" id="import-attachments" />
					<?php esc_html_e( 'Download and import file attachments from the source site', 'wordpress-importer' ); ?>
				</label>
			</p>
			<p class="wxr-field-help"><?php esc_html_e( 'Leave unchecked for local/offline imports — attachments can be imported separately.', 'wordpress-importer' ); ?></p>
		</div>

	<?php endif; ?>

	<input type="hidden" name="import_id" value="<?php echo esc_attr( $this->id ); ?>" />
	<?php wp_nonce_field( sprintf( 'wxr.import:%d', $this->id ) ); ?>

	<p class="wxr-form-actions">
		<?php submit_button( __( 'Run Import', 'wordpress-importer' ), 'primary large', 'submit', false ); ?>
	</p>

</form>

</div>

<?php $this->render_footer();
