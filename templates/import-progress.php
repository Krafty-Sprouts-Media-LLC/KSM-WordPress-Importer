<?php
/**
 * Import progress step template.
 *
 * @package Better_WordPress_Importer
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

/** @var Better_Admin_UI $ui */
/** @var string $context */
/** @var Better_Import_Job $job */
?>
<div class="better-importer-card better-importer-progress-card">
	<div class="better-importer-progress-header">
		<h2 id="better-importer-progress-title"><?php esc_html_e( 'Importing', 'better-wordpress-importer' ); ?></h2>
		<div class="better-importer-progress-actions">
			<button type="button" class="button" id="better-importer-pause-btn"><?php esc_html_e( 'Pause', 'better-wordpress-importer' ); ?></button>
			<button type="button" class="button" id="better-importer-cancel-btn"><?php esc_html_e( 'Cancel Import', 'better-wordpress-importer' ); ?></button>
		</div>
	</div>

	<p id="better-importer-status-message" class="description"><?php echo esc_html__( 'Processing continues on the server. You can close this page and return later.', 'better-wordpress-importer' ); ?></p>

	<div class="better-importer-progress-bar-wrap">
		<div class="better-importer-progress-bar"><span id="better-importer-progress-fill"></span></div>
		<p id="better-importer-progress-label"></p>
	</div>

	<div class="better-importer-count-grid" id="better-importer-count-grid"></div>

	<div class="better-importer-current-entity" id="better-importer-current-entity" hidden>
		<h3><?php esc_html_e( 'Current Entity', 'better-wordpress-importer' ); ?></h3>
		<p id="better-importer-current-title"></p>
		<p id="better-importer-current-step"></p>
	</div>

	<div class="better-importer-log" id="better-importer-log">
		<h3><?php esc_html_e( 'Activity Log', 'better-wordpress-importer' ); ?></h3>
		<ul id="better-importer-log-list"></ul>
	</div>

	<div id="better-importer-complete-actions" hidden>
		<a class="button button-primary" href="<?php echo esc_url( $ui->get_step_url( 0, $context ) ); ?>"><?php esc_html_e( 'Import another file', 'better-wordpress-importer' ); ?></a>
	</div>
</div>
