<?php
/**
 * Page for the actual import step.
 */

$args = array(
	'action' => 'wxr-import',
	'id'     => $this->id,
);
$url = add_query_arg( urlencode_deep( $args ), admin_url( 'admin-ajax.php' ) );

$total_items = $data->post_count + $data->media_count + $data->comment_count + $data->term_count + count( $data->users );

$script_data = array(
	'count' => array(
		'posts'    => $data->post_count,
		'media'    => $data->media_count,
		'users'    => count( $data->users ),
		'comments' => $data->comment_count,
		'terms'    => $data->term_count,
	),
	'url'      => $url,
	'importId' => $this->id,
	'cancelUrl'   => admin_url( 'admin-ajax.php' ),
	'cancelNonce' => wp_create_nonce( 'wxr-cancel-import' ),
	'strings' => array(
		'complete'    => __( 'Import complete!', 'wordpress-importer' ),
		'error'       => __( 'Import failed. Check the log below for details.', 'wordpress-importer' ),
		'interrupted' => __( 'The import stream was interrupted. Check the server logs.', 'wordpress-importer' ),
		'importing'   => __( 'Importing&hellip;', 'wordpress-importer' ),
		'showLog'     => __( 'Show log', 'wordpress-importer' ),
		'hideLog'     => __( 'Hide log', 'wordpress-importer' ),
	),
);

wp_enqueue_script( 'wxr-importer-import', WXR_IMPORTER_URL . 'assets/import.js', array( 'jquery' ), '2.0.1', true );
wp_localize_script( 'wxr-importer-import', 'wxrImportData', $script_data );
wp_enqueue_style( 'wxr-importer-import', WXR_IMPORTER_URL . 'assets/import.css', array(), '2.0.1' );

$this->render_header();
?>

<div class="wxr-header">
	<span class="dashicons dashicons-migrate" id="wxr-header-icon"></span>
	<h1><?php esc_html_e( 'Importing', 'wordpress-importer' ) ?></h1>
	<button type="button" id="wxr-cancel-btn" class="button" style="margin-left:auto;">
		<?php esc_html_e( 'Cancel Import', 'wordpress-importer' ) ?>
	</button>
</div>

<div id="import-status-message" class="notice notice-info">
	<?php esc_html_e( 'Starting import&hellip;', 'wordpress-importer' ) ?>
</div>

<div class="wxr-overall wxr-card" style="padding:16px 24px;">
	<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
		<span style="font-size:13px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'Overall Progress', 'wordpress-importer' ) ?></span>
		<span id="progress-total" style="font-size:13px;color:#646970;">0%</span>
	</div>
	<div class="wxr-overall-bar">
		<div class="wxr-overall-fill" id="wxr-overall-fill"></div>
	</div>
</div>

<div class="wxr-stats">

	<div class="wxr-stat" id="stat-posts">
		<span class="dashicons dashicons-admin-post wxr-stat-icon"></span>
		<span class="wxr-stat-count"><?php echo esc_html( number_format_i18n( $data->post_count ) ); ?></span>
		<span class="wxr-stat-label"><?php esc_html_e( 'Posts', 'wordpress-importer' ) ?></span>
		<span class="wxr-stat-done" id="completed-posts">0 done</span>
		<progress id="progressbar-posts" max="100" value="0" style="display:none"></progress>
		<span id="progress-posts" style="display:none">0%</span>
	</div>

	<div class="wxr-stat" id="stat-media">
		<span class="dashicons dashicons-admin-media wxr-stat-icon"></span>
		<span class="wxr-stat-count"><?php echo esc_html( number_format_i18n( $data->media_count ) ); ?></span>
		<span class="wxr-stat-label"><?php esc_html_e( 'Media', 'wordpress-importer' ) ?></span>
		<span class="wxr-stat-done" id="completed-media">0 done</span>
		<progress id="progressbar-media" max="100" value="0" style="display:none"></progress>
		<span id="progress-media" style="display:none">0%</span>
	</div>

	<div class="wxr-stat" id="stat-users">
		<span class="dashicons dashicons-admin-users wxr-stat-icon"></span>
		<span class="wxr-stat-count"><?php echo esc_html( number_format_i18n( count( $data->users ) ) ); ?></span>
		<span class="wxr-stat-label"><?php esc_html_e( 'Users', 'wordpress-importer' ) ?></span>
		<span class="wxr-stat-done" id="completed-users">0 done</span>
		<progress id="progressbar-users" max="100" value="0" style="display:none"></progress>
		<span id="progress-users" style="display:none">0%</span>
	</div>

	<div class="wxr-stat" id="stat-comments">
		<span class="dashicons dashicons-admin-comments wxr-stat-icon"></span>
		<span class="wxr-stat-count"><?php echo esc_html( number_format_i18n( $data->comment_count ) ); ?></span>
		<span class="wxr-stat-label"><?php esc_html_e( 'Comments', 'wordpress-importer' ) ?></span>
		<span class="wxr-stat-done" id="completed-comments">0 done</span>
		<progress id="progressbar-comments" max="100" value="0" style="display:none"></progress>
		<span id="progress-comments" style="display:none">0%</span>
	</div>

	<div class="wxr-stat" id="stat-terms">
		<span class="dashicons dashicons-category wxr-stat-icon"></span>
		<span class="wxr-stat-count"><?php echo esc_html( number_format_i18n( $data->term_count ) ); ?></span>
		<span class="wxr-stat-label"><?php esc_html_e( 'Terms', 'wordpress-importer' ) ?></span>
		<span class="wxr-stat-done" id="completed-terms">0 done</span>
		<progress id="progressbar-terms" max="100" value="0" style="display:none"></progress>
		<span id="progress-terms" style="display:none">0%</span>
	</div>

</div>

<span id="completed-total" style="display:none">0/0</span>

<div style="margin-bottom:8px;">
	<button type="button" class="wxr-log-toggle" id="wxr-log-toggle" style="display:none;">
		<?php esc_html_e( 'Show log', 'wordpress-importer' ) ?>
	</button>
</div>

<table id="import-log" class="widefat" style="display:none;">
	<thead>
		<tr>
			<th style="width:80px;"><?php esc_html_e( 'Level', 'wordpress-importer' ) ?></th>
			<th><?php esc_html_e( 'Message', 'wordpress-importer' ) ?></th>
		</tr>
	</thead>
	<tbody></tbody>
</table>

<?php $this->render_footer();
