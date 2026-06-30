<?php
/**
 * Batch remapping after content import completes.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles post-import remapping in resumable batches.
 *
 * @since 3.0.0
 */
class WXR_Import_Remapper {

	/**
	 * Process one remapping batch for a job.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job      Import job.
	 * @param WXR_Importer   $importer Importer with restored mapping state.
	 * @param int            $batch_size Items per batch.
	 *
	 * @return array<string, mixed> Batch result.
	 */
	public function process_batch( WXR_Import_Job $job, WXR_Importer $importer, $batch_size = 10 ) {
		$cursor = isset( $job->options['remap_cursor'] ) ? $job->options['remap_cursor'] : array(
			'posts'    => 0,
			'comments' => 0,
			'urls'     => 0,
			'featured' => 0,
		);

		$phase = isset( $cursor['phase'] ) ? $cursor['phase'] : 'posts';

		if ( 'posts' === $phase ) {
			return $this->remap_posts_batch( $job, $importer, $batch_size, $cursor );
		}

		if ( 'comments' === $phase ) {
			return $this->remap_comments_batch( $job, $importer, $batch_size, $cursor );
		}

		if ( 'urls' === $phase ) {
			return $this->remap_urls_batch( $job, $importer, $cursor );
		}

		if ( 'featured' === $phase ) {
			return $this->remap_featured_batch( $job, $importer, $batch_size, $cursor );
		}

		return array(
			'is_complete' => true,
			'phase'       => 'done',
		);
	}

	/**
	 * Remap posts requiring post-processing.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job        Job.
	 * @param WXR_Importer   $importer   Importer.
	 * @param int            $batch_size Batch size.
	 * @param array          $cursor     Remap cursor.
	 *
	 * @return array<string, mixed>
	 */
	protected function remap_posts_batch( WXR_Import_Job $job, WXR_Importer $importer, $batch_size, array $cursor ) {
		$requires = $importer->get_requires_remapping_posts();
		$ids      = array_keys( $requires );
		$offset   = isset( $cursor['posts'] ) ? (int) $cursor['posts'] : 0;
		$slice    = array_slice( $ids, $offset, $batch_size );

		if ( empty( $slice ) ) {
			$cursor['phase'] = 'comments';
			$cursor['posts'] = 0;
			$job->options['remap_cursor'] = $cursor;
			return array(
				'is_complete' => false,
				'phase'       => 'comments',
				'processed'   => 0,
			);
		}

		$batch = array();
		foreach ( $slice as $post_id ) {
			$batch[ $post_id ] = true;
		}

		$importer->post_process_posts_public( $batch );

		$cursor['posts'] = $offset + count( $slice );
		$job->options['remap_cursor'] = $cursor;

		return array(
			'is_complete' => false,
			'phase'       => 'posts',
			'processed'   => count( $slice ),
		);
	}

	/**
	 * Remap comments requiring post-processing.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job        Job.
	 * @param WXR_Importer   $importer   Importer.
	 * @param int            $batch_size Batch size.
	 * @param array          $cursor     Remap cursor.
	 *
	 * @return array<string, mixed>
	 */
	protected function remap_comments_batch( WXR_Import_Job $job, WXR_Importer $importer, $batch_size, array $cursor ) {
		$requires = $importer->get_requires_remapping_comments();
		$ids      = array_keys( $requires );
		$offset   = isset( $cursor['comments'] ) ? (int) $cursor['comments'] : 0;
		$slice    = array_slice( $ids, $offset, $batch_size );

		if ( empty( $slice ) ) {
			$cursor['phase'] = 'urls';
			$cursor['comments'] = 0;
			$job->options['remap_cursor'] = $cursor;
			return array(
				'is_complete' => false,
				'phase'       => 'urls',
				'processed'   => 0,
			);
		}

		$batch = array();
		foreach ( $slice as $comment_id ) {
			$batch[ $comment_id ] = true;
		}

		$importer->post_process_comments_public( $batch );

		$cursor['comments'] = $offset + count( $slice );
		$job->options['remap_cursor'] = $cursor;

		return array(
			'is_complete' => false,
			'phase'       => 'comments',
			'processed'   => count( $slice ),
		);
	}

	/**
	 * Run URL remapping (single batch — processes all URLs).
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job      Job.
	 * @param WXR_Importer   $importer Importer.
	 * @param array          $cursor   Remap cursor.
	 *
	 * @return array<string, mixed>
	 */
	protected function remap_urls_batch( WXR_Import_Job $job, WXR_Importer $importer, array $cursor ) {
		if ( ! empty( $job->options['aggressive_url_search'] ) ) {
			$importer->replace_attachment_urls_in_content_public();
		}

		$cursor['phase'] = 'featured';
		$cursor['urls']  = 1;
		$job->options['remap_cursor'] = $cursor;

		return array(
			'is_complete' => false,
			'phase'       => 'featured',
			'processed'   => 1,
		);
	}

	/**
	 * Remap featured images in batches.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job        Job.
	 * @param WXR_Importer   $importer   Importer.
	 * @param int            $batch_size Batch size.
	 * @param array          $cursor     Remap cursor.
	 *
	 * @return array<string, mixed>
	 */
	protected function remap_featured_batch( WXR_Import_Job $job, WXR_Importer $importer, $batch_size, array $cursor ) {
		$featured = $importer->get_featured_images();
		$ids      = array_keys( $featured );
		$offset   = isset( $cursor['featured'] ) ? (int) $cursor['featured'] : 0;
		$slice    = array_slice( $ids, $offset, $batch_size );

		if ( empty( $slice ) ) {
			$cursor['phase'] = 'done';
			$job->options['remap_cursor'] = $cursor;
			return array(
				'is_complete' => true,
				'phase'       => 'done',
				'processed'   => 0,
			);
		}

		$batch_featured = array();
		foreach ( $slice as $post_id ) {
			$batch_featured[ $post_id ] = $featured[ $post_id ];
		}

		$importer->remap_featured_images_batch( $batch_featured );

		$cursor['featured'] = $offset + count( $slice );
		$job->options['remap_cursor'] = $cursor;

		$is_complete = $cursor['featured'] >= count( $ids );

		return array(
			'is_complete' => $is_complete,
			'phase'       => $is_complete ? 'done' : 'featured',
			'processed'   => count( $slice ),
		);
	}
}
