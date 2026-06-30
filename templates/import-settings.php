<?php
/**
 * Import settings step template.
 *
 * @package Better_WordPress_Importer
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

/** @var Better_Admin_UI $ui */
/** @var string $context */
/** @var int $attachment_id */
/** @var string $local_file */
/** @var string $file_path */
/** @var array $preflight */
/** @var int $entity_total */
/** @var array $users */
$counts = isset( $preflight['counts'] ) ? $preflight['counts'] : array();
?>
<div class="better-importer-card">
	<h2><?php esc_html_e( 'Import Summary', 'better-wordpress-importer' ); ?></h2>
	<ul class="better-importer-summary">
		<li><strong><?php esc_html_e( 'File', 'better-wordpress-importer' ); ?>:</strong> <?php echo esc_html( basename( $file_path ) ); ?></li>
		<li><strong><?php esc_html_e( 'Site title', 'better-wordpress-importer' ); ?>:</strong> <?php echo esc_html( isset( $preflight['title'] ) ? $preflight['title'] : '' ); ?></li>
		<li><strong><?php esc_html_e( 'WXR version', 'better-wordpress-importer' ); ?>:</strong> <?php echo esc_html( isset( $preflight['wxr_version'] ) ? $preflight['wxr_version'] : '' ); ?></li>
		<li><strong><?php esc_html_e( 'Entities', 'better-wordpress-importer' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $entity_total ) ); ?></li>
		<li>
			<?php
			printf(
				/* translators: 1: posts, 2: media, 3: terms, 4: users */
				esc_html__( '%1$s posts · %2$s media · %3$s terms · %4$s users', 'better-wordpress-importer' ),
				esc_html( number_format_i18n( isset( $counts['posts'] ) ? $counts['posts'] : 0 ) ),
				esc_html( number_format_i18n( isset( $counts['attachments'] ) ? $counts['attachments'] : 0 ) ),
				esc_html( number_format_i18n( isset( $counts['terms'] ) ? $counts['terms'] : 0 ) ),
				esc_html( number_format_i18n( isset( $counts['users'] ) ? $counts['users'] : 0 ) )
			);
			?>
		</li>
	</ul>
</div>

<form id="better-importer-start-form" class="better-importer-card">
	<h2><?php esc_html_e( 'Import Options', 'better-wordpress-importer' ); ?></h2>
	<input type="hidden" name="attachment_id" value="<?php echo esc_attr( $attachment_id ); ?>" />
	<input type="hidden" name="local_file" value="<?php echo esc_attr( $local_file ); ?>" />

	<p>
		<label for="better-importer-default-author"><?php esc_html_e( 'Default author for unmatched content', 'better-wordpress-importer' ); ?></label><br />
		<select id="better-importer-default-author" name="default_author">
			<?php foreach ( $users as $user ) : ?>
				<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( get_current_user_id(), $user->ID ); ?>>
					<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

	<p>
		<label>
			<input type="checkbox" name="fetch_attachments" value="1" />
			<?php esc_html_e( 'Download and import file attachments', 'better-wordpress-importer' ); ?>
		</label>
	</p>

	<p>
		<label for="better-importer-job-label"><?php esc_html_e( 'Label (optional)', 'better-wordpress-importer' ); ?></label><br />
		<input type="text" class="regular-text" id="better-importer-job-label" name="job_label" value="<?php echo esc_attr( basename( $file_path, '.xml' ) ); ?>" />
	</p>

	<p class="submit">
		<a class="button" href="<?php echo esc_url( $ui->get_step_url( 0, $context ) ); ?>"><?php esc_html_e( 'Back', 'better-wordpress-importer' ); ?></a>
		<button type="submit" class="button button-primary button-hero" id="better-importer-start-btn">
			<?php esc_html_e( 'Start Import', 'better-wordpress-importer' ); ?>
		</button>
	</p>
</form>

<script>
window.betterImporterStart = {
	ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
	progressUrl: <?php echo wp_json_encode( $ui->get_step_url( 2, $context ) ); ?>,
	nonce: <?php echo wp_json_encode( wp_create_nonce( 'better-import-start' ) ); ?>
};
</script>
