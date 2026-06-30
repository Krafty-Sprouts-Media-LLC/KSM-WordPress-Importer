<div id="plupload-upload-ui" class="hide-if-no-js">
	<?php do_action( 'pre-plupload-upload-ui' ); ?>

	<div id="drag-drop-area">
		<div class="drag-drop-inside drag-drop-selector">
			<p class="drag-drop-info"><?php esc_html_e( 'Drop your .xml file here', 'wordpress-importer' ) ?></p>
			<p><?php echo esc_html_x( 'or', 'Uploader: Drop files here - or - Select Files', 'wordpress-importer' ) ?></p>
			<p class="drag-drop-buttons">
				<input id="plupload-browse-button" type="button" value="<?php esc_attr_e( 'Choose File', 'wordpress-importer' ) ?>" class="button button-primary" />
			</p>
		</div>
		<div class="drag-drop-inside drag-drop-status"></div>
	</div>

	<?php do_action( 'post-plupload-upload-ui' ); ?>
</div>

<div id="html-upload-ui" class="hide-if-js">
	<?php do_action( 'pre-html-upload-ui' ); ?>
	<p id="async-upload-wrap">
		<label class="screen-reader-text" for="async-upload"><?php esc_html_e( 'Upload', 'wordpress-importer' ) ?></label>
		<input type="file" name="import" id="async-upload" />
		<?php submit_button( __( 'Upload File', 'wordpress-importer' ), 'primary', 'html-upload', false ); ?>
	</p>
	<div class="clear"></div>
	<?php do_action( 'post-html-upload-ui' ); ?>
</div>

<p class="max-upload-size description"><?php printf(
	esc_html__( 'Maximum upload file size: %s.', 'wordpress-importer' ),
	esc_html( size_format( $max_upload_size ) )
) ?></p>

<script type="text/html" id="tmpl-import-upload-status">
	<# if ( data.uploading ) { #>

		<div class="wxr-upload-progress">
			<p class="wxr-upload-filename">
				<?php echo wp_kses(
					sprintf( __( 'Uploading <code>{{ data.filename }}</code>&hellip;', 'wordpress-importer' ), '' ),
					array( 'code' => array() )
				); ?>
			</p>
			<div class="media-item">
				<div class="wxr-upload-bar-header">
					<span class="wxr-upload-label"><?php esc_html_e( 'Uploading&hellip;', 'wordpress-importer' ); ?></span>
					<span class="percent wxr-upload-percent">0%</span>
				</div>
				<div class="progress">
					<div class="bar"></div>
				</div>
			</div>
		</div>

	<# } else { #>

		<div class="wxr-upload-success">
			<span class="dashicons dashicons-yes-alt" style="color:#00a32a;font-size:32px;width:32px;height:32px;display:block;margin:0 auto 8px;"></span>
			<p><strong><?php esc_html_e( 'File uploaded successfully.', 'wordpress-importer' ) ?></strong></p>
			<button type="submit" class="button button-primary button-large">
				<?php esc_html_e( 'Continue to Import Settings', 'wordpress-importer' ) ?>
			</button>
		</div>

	<# } #>
</script>

<script type="text/html" id="tmpl-import-upload-error">
	<div class="wxr-upload-error notice notice-error inline">
		<p><?php printf(
			esc_html__( 'Upload failed: %s', 'wordpress-importer' ),
			'{{ data.message }}'
		) ?></p>
		<p><button type="button" class="button"><?php esc_html_e( 'Try Again', 'wordpress-importer' ) ?></button></p>
	</div>
</script>
