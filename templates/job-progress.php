<?php
/**
 * Job-based import progress page template.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

if ( ! isset( $job_id, $data ) ) {
	return;
}

$total_items = $data->post_count + $data->media_count + $data->comment_count + $data->term_count + count( $data->users );
$batch_size  = isset( $job ) && $job instanceof WXR_Import_Job && isset( $job->options['batch_size'] )
	? (int) $job->options['batch_size']
	: 100;

$script_data = array(
	'jobId'        => (int) $job_id,
	'importId'     => $this->id,
	'batchUrl'     => admin_url( 'admin-ajax.php' ),
	'statusUrl'    => admin_url( 'admin-ajax.php' ),
	'nonce'        => wp_create_nonce( 'wxr-import-job:' . $job_id ),
	'cancelUrl'    => admin_url( 'admin-ajax.php' ),
	'cancelNonce'  => wp_create_nonce( 'wxr-cancel-import' ),
	'introUrl'     => $this->get_url( 0 ),
	'pollInterval' => 2000,
	'batchSize'    => $batch_size,
	'count'        => array(
		'posts'    => $data->post_count,
		'media'    => $data->media_count,
		'users'    => count( $data->users ),
		'comments' => $data->comment_count,
		'terms'    => $data->term_count,
	),
	'strings'      => array(
		'complete'       => __( 'Import complete!', 'wordpress-importer' ),
		'reportTitle'    => __( 'Import Report', 'wordpress-importer' ),
		'runAnother'     => __( 'Run another import', 'wordpress-importer' ),
		'error'          => __( 'Import failed. Check the log below for details.', 'wordpress-importer' ),
		'importing'      => __( 'Importing…', 'wordpress-importer' ),
		'remapping'      => __( 'Remapping relationships…', 'wordpress-importer' ),
		'downloadingAttachments' => __( 'Downloading attachments…', 'wordpress-importer' ),
		'paused'         => __( 'Import paused.', 'wordpress-importer' ),
		'pauseRequested' => __( 'Pause requested. The current batch will finish first.', 'wordpress-importer' ),
		'background'     => __( 'Processing continues on the server. You can close this page and return later.', 'wordpress-importer' ),
		'connectionLost' => __( 'Connection lost — processing continues on the server.', 'wordpress-importer' ),
		'sessionExpired' => __( 'Session expired. Refresh this page to continue the import.', 'wordpress-importer' ),
		'showLog'        => __( 'Show log', 'wordpress-importer' ),
		'hideLog'        => __( 'Hide log', 'wordpress-importer' ),
		'entities'       => __( '%1$s of %2$s XML entities scanned', 'wordpress-importer' ),
		'batchNote'      => __( 'Processing %d items per batch', 'wordpress-importer' ),
		'batchRetry'     => __( 'Connection interrupted. Retrying in %d seconds...', 'wordpress-importer' ),
		'batchRunning'   => __( 'Current batch running for %d seconds...', 'wordpress-importer' ),
	),
);

if ( isset( $job ) && $job instanceof WXR_Import_Job ) {
	$script_data['initialStatus'] = $job->to_status_array();
}

$script_path = dirname( __DIR__ ) . '/assets/job-status.js';
wp_enqueue_script( 'wxr-importer-job-status', WXR_IMPORTER_URL . 'assets/job-status.js', array( 'jquery' ), filemtime( $script_path ), true );
wp_localize_script( 'wxr-importer-job-status', 'wxrJobData', $script_data );

$this->render_header();
?>

<div class="wxr-header wxr-header-actions">
	<span class="dashicons dashicons-migrate" id="wxr-header-icon"></span>
	<h1><?php esc_html_e( 'Importing', 'wordpress-importer' ); ?></h1>
	<div class="wxr-header-buttons">
		<button type="button" id="wxr-pause-btn" class="button"><?php esc_html_e( 'Pause', 'wordpress-importer' ); ?></button>
		<button type="button" id="wxr-cancel-btn" class="button"><?php esc_html_e( 'Cancel Import', 'wordpress-importer' ); ?></button>
	</div>
</div>

<nav class="wxr-step-nav" aria-label="<?php esc_attr_e( 'Import steps', 'wordpress-importer' ); ?>">
	<ol>
		<li class="is-done"><span><?php esc_html_e( 'Choose file', 'wordpress-importer' ); ?></span></li>
		<li class="is-done"><span><?php esc_html_e( 'Settings', 'wordpress-importer' ); ?></span></li>
		<li class="is-current"><span><?php esc_html_e( 'Import', 'wordpress-importer' ); ?></span></li>
	</ol>
</nav>

<div class="wxr-page">

<?php if ( $total_items > 1000 ) : ?>
<div class="wxr-banner wxr-banner-info">
	<span class="dashicons dashicons-info"></span>
	<p><?php esc_html_e( 'Large import — processing continues in the background. You can close this page and return later.', 'wordpress-importer' ); ?></p>
</div>
<?php endif; ?>

<div id="import-status-message" class="wxr-status-message">
	<?php esc_html_e( 'Starting import&hellip;', 'wordpress-importer' ); ?>
</div>

<div class="wxr-card wxr-overall">
	<div class="wxr-overall-header">
		<span class="wxr-overall-title"><?php esc_html_e( 'Overall Progress', 'wordpress-importer' ); ?></span>
		<span id="progress-total" class="wxr-overall-percent">0%</span>
	</div>
	<div class="wxr-overall-bar">
		<div class="wxr-overall-fill" id="wxr-overall-fill"></div>
	</div>
	<p id="wxr-phase-label" class="wxr-phase-label"></p>
	<p id="wxr-entity-progress" class="wxr-entity-progress"></p>
	<p class="wxr-batch-note" id="wxr-batch-note">
		<?php
		printf(
			/* translators: %d: batch size */
			esc_html__( 'Processing %d items per batch', 'wordpress-importer' ),
			(int) $batch_size
		);
		?>
	</p>
</div>

<div class="wxr-stats">
	<?php
	$stat_types = array(
		'posts'    => array( 'icon' => 'admin-post', 'label' => __( 'Posts', 'wordpress-importer' ), 'count' => $data->post_count ),
		'media'    => array( 'icon' => 'admin-media', 'label' => __( 'Media', 'wordpress-importer' ), 'count' => $data->media_count ),
		'users'    => array( 'icon' => 'admin-users', 'label' => __( 'Users', 'wordpress-importer' ), 'count' => count( $data->users ) ),
		'comments' => array( 'icon' => 'admin-comments', 'label' => __( 'Comments', 'wordpress-importer' ), 'count' => $data->comment_count ),
		'terms'    => array( 'icon' => 'category', 'label' => __( 'Terms', 'wordpress-importer' ), 'count' => $data->term_count ),
	);
	foreach ( $stat_types as $key => $stat ) :
		?>
	<div class="wxr-stat" id="stat-<?php echo esc_attr( $key ); ?>">
		<span class="dashicons dashicons-<?php echo esc_attr( $stat['icon'] ); ?> wxr-stat-icon"></span>
		<span class="wxr-stat-count"><?php echo esc_html( number_format_i18n( $stat['count'] ) ); ?></span>
		<span class="wxr-stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
		<span class="wxr-stat-done" id="completed-<?php echo esc_attr( $key ); ?>">0 / <?php echo esc_html( number_format_i18n( $stat['count'] ) ); ?></span>
		<span class="wxr-stat-note" id="skipped-<?php echo esc_attr( $key ); ?>" style="display:none;"></span>
		<span class="wxr-stat-note is-error" id="failed-<?php echo esc_attr( $key ); ?>" style="display:none;"></span>
		<div class="wxr-stat-bar"><div class="wxr-stat-bar-fill" id="bar-<?php echo esc_attr( $key ); ?>"></div></div>
	</div>
		<?php
	endforeach;
	?>
</div>

<button type="button" class="wxr-log-toggle" id="wxr-log-toggle" style="display:none;">
	<?php esc_html_e( 'Show log', 'wordpress-importer' ); ?>
</button>

<table id="import-log" class="widefat wxr-log-table" style="display:none;">
	<thead>
		<tr>
			<th style="width:80px;"><?php esc_html_e( 'Level', 'wordpress-importer' ); ?></th>
			<th><?php esc_html_e( 'Message', 'wordpress-importer' ); ?></th>
		</tr>
	</thead>
	<tbody></tbody>
</table>

<div id="wxr-final-report" class="wxr-card wxr-final-report" style="display:none;">
	<h2><?php esc_html_e( 'Import Report', 'wordpress-importer' ); ?></h2>
	<div id="wxr-final-report-body"></div>
	<p class="wxr-form-actions">
		<a class="button button-primary" href="<?php echo esc_url( $this->get_url( 0 ) ); ?>"><?php esc_html_e( 'Run another import', 'wordpress-importer' ); ?></a>
	</p>
</div>

</div>

<?php $this->render_footer();
