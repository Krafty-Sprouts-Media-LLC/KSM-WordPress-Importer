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
$authors = isset( $preflight['authors'] ) && is_array( $preflight['authors'] ) ? $preflight['authors'] : array();
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

	<?php if ( ! empty( $authors ) ) : ?>
		<div class="better-importer-author-map">
			<h3><?php esc_html_e( 'Author Mapping', 'better-wordpress-importer' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Source author', 'better-wordpress-importer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Destination user', 'better-wordpress-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $authors as $author ) : ?>
						<?php
						$source_id = isset( $author['id'] ) ? (string) $author['id'] : '';
						$login     = isset( $author['login'] ) ? (string) $author['login'] : ( isset( $author['title'] ) ? (string) $author['title'] : '' );
						$email     = isset( $author['email'] ) ? (string) $author['email'] : '';
						$name      = isset( $author['display_name'] ) ? (string) $author['display_name'] : '';
						$map_key   = '' !== $source_id ? $source_id : $login;
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( '' !== $name ? $name : $login ); ?></strong>
								<?php if ( '' !== $login ) : ?>
									<br /><code><?php echo esc_html( $login ); ?></code>
								<?php endif; ?>
								<?php if ( '' !== $email ) : ?>
									<br /><span class="description"><?php echo esc_html( $email ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<select name="user_mapping[<?php echo esc_attr( $map_key ); ?>]">
									<option value=""><?php esc_html_e( 'Create if missing / map matching user', 'better-wordpress-importer' ); ?></option>
									<?php foreach ( $users as $user ) : ?>
										<option value="<?php echo esc_attr( $user->ID ); ?>">
											<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<p>
		<label>
			<input type="checkbox" name="fetch_attachments" value="1" />
			<?php esc_html_e( 'Download and import file attachments', 'better-wordpress-importer' ); ?>
		</label>
	</p>

	<p>
		<label for="better-importer-unknown-pt"><?php esc_html_e( 'Unknown post types', 'better-wordpress-importer' ); ?></label><br />
		<select id="better-importer-unknown-pt" name="unknown_post_type_strategy">
			<option value="import_as_draft"><?php esc_html_e( 'Import as draft (preserve original type)', 'better-wordpress-importer' ); ?></option>
			<option value="skip"><?php esc_html_e( 'Skip', 'better-wordpress-importer' ); ?></option>
			<option value="fail"><?php esc_html_e( 'Fail the item', 'better-wordpress-importer' ); ?></option>
		</select>
		<br /><span class="description"><?php esc_html_e( 'What to do with content whose post type is not registered on this site (e.g. from a source theme or plugin).', 'better-wordpress-importer' ); ?></span>
	</p>

	<p>
		<label for="better-importer-meta-mode"><?php esc_html_e( 'Post meta import', 'better-wordpress-importer' ); ?></label><br />
		<select id="better-importer-meta-mode" name="meta_write_mode">
			<option value="bulk"><?php esc_html_e( 'Fast (bulk insert)', 'better-wordpress-importer' ); ?></option>
			<option value="hooked"><?php esc_html_e( 'Compatible (run meta hooks)', 'better-wordpress-importer' ); ?></option>
		</select>
		<br /><span class="description"><?php esc_html_e( 'Choose Compatible only if plugins rely on post-meta hooks (some SEO/ACF setups). Slower.', 'better-wordpress-importer' ); ?></span>
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
