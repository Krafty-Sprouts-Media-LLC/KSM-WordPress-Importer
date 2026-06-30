<?php
/**
 * Orchestrates resumable batch import processing.
 *
 * @package WordPress_Importer_v2
 * @since 3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batch import processor.
 *
 * @since 3.0.0
 */
class WXR_Import_Processor {

	/**
	 * Default batch size.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Attachments downloaded per batch in the deferred phase.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	const ATTACHMENT_BATCH_SIZE = 3;

	/**
	 * Lock transient TTL in seconds.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	const LOCK_TTL = 60;

	/**
	 * Minimum seconds between batches for the same job.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	const RATE_LIMIT = 2;

	/**
	 * Process one batch for a job.
	 *
	 * @since 3.0.0
	 *
	 * @param int $job_id     Job ID.
	 * @param int $batch_size Optional batch size override.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_job( $job_id, $batch_size = 0 ) {
		$job = WXR_Import_Job::get( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( $job->is_terminal() ) {
			return array(
				'job_id'      => $job->id,
				'status'      => $job->status,
				'is_terminal' => true,
				'message'     => __( 'Import job is already finished.', 'wordpress-importer' ),
			);
		}

		if ( 'paused' === $job->status ) {
			return array(
				'job_id'  => $job->id,
				'status'  => $job->status,
				'message' => __( 'Import job is paused.', 'wordpress-importer' ),
			);
		}

		if ( ! $this->acquire_lock( $job->id ) ) {
			return array(
				'job_id'  => $job->id,
				'status'  => $job->status,
				'message' => __( 'Another batch is already running for this job.', 'wordpress-importer' ),
			);
		}

		try {
			$fresh_job = WXR_Import_Job::get( $job->id );
			if ( ! is_wp_error( $fresh_job ) ) {
				$job = $fresh_job;
			}

			$this->recover_premature_phase( $job );

			set_time_limit( 0 );
			wp_raise_memory_limit( 'admin' );

			if ( $batch_size <= 0 ) {
				$batch_size = isset( $job->options['batch_size'] ) ? (int) $job->options['batch_size'] : self::DEFAULT_BATCH_SIZE;
			}

			$batch_size = max( 1, (int) $batch_size );

			$logger   = new WP_Importer_Logger_Job( $job );
			$importer = $this->create_importer( $job, $logger );

			if ( $this->skip_stale_single_entity( $job, $importer ) ) {
				$repository = new WXR_Import_Job_Repository();
				$repository->save( $job );

				return array_merge(
					array(
						'job_id' => $job->id,
						'status' => $job->status,
					),
					$job->to_status_array()
				);
			}

			$batch_size = $this->adjust_batch_size_after_stale_batch( $job, $batch_size );
			$job->options['batch_size'] = $batch_size;
			$result   = array();

			if ( in_array( $job->status, array( 'pending', 'processing' ), true ) ) {
				if ( 'pending' === $job->status ) {
					$job->status = 'processing';
				}

				$batch_size = min( $batch_size, (int) $job->options['batch_size'] );
				$this->mark_active_batch( $job, $batch_size );
				$job->add_log(
					'info',
					sprintf(
						/* translators: 1: batch start index, 2: batch size */
						__( 'Scanning XML batch from entity %1$d (%2$d items)...', 'wordpress-importer' ),
						$job->xml_cursor_item,
						$batch_size
					)
				);

				( new WXR_Import_Job_Repository() )->save( $job );

				$result = $importer->import_batch(
					$job->file_path,
					$job->xml_cursor_item,
					$batch_size,
					$job->item_manifest,
					$job->manifest_entity_total()
				);

				if ( is_wp_error( $result ) ) {
					$job->set_status( 'failed' );
					$job->add_log( 'error', $result->get_error_message() );
					$this->save_job( $job, $importer );
					return $result;
				}

				$cursor_before = (int) $job->xml_cursor_item;

				$this->apply_batch_counts( $job, $result );
				$this->record_item_results( $job, $result );
				$next_item_index = isset( $result['next_item_index'] ) ? (int) $result['next_item_index'] : $cursor_before;
				$processed_count = isset( $result['processed'] ) ? (int) $result['processed'] : 0;
				$entity_incomplete = ! empty( $result['incomplete'] );

				if ( ! $entity_incomplete && $processed_count > 0 && $next_item_index <= $cursor_before ) {
					$next_item_index = $cursor_before + $processed_count;
					$job->add_log(
						'warning',
						sprintf(
							/* translators: 1: previous cursor, 2: repaired cursor */
							__( 'Batch cursor did not advance; repaired progress from entity %1$d to %2$d.', 'wordpress-importer' ),
							$cursor_before,
							$next_item_index
						)
					);
				}

				$job->xml_cursor_item = $next_item_index;
				$job->options['import_environment_ready'] = true;
				unset( $job->options['active_batch'] );

				if ( $entity_incomplete ) {
					$job->add_log(
						'info',
						sprintf(
							/* translators: %d: entity index */
							__( 'XML entity %1$d is still being imported in smaller resumable steps.', 'wordpress-importer' ),
							$job->xml_cursor_item
						)
					);
				} else {
					$job->add_log(
						'info',
						sprintf(
							/* translators: 1: entity index, 2: manifest total */
							__( 'Batch scanned through XML entity %1$d of %2$d.', 'wordpress-importer' ),
							$job->xml_cursor_item,
							$job->manifest_entity_total()
						)
					);
				}

				if ( ! empty( $result['is_complete'] ) && $job->is_content_import_complete() ) {
					if ( $this->has_pending_attachments( $importer ) ) {
						$job->set_status( 'downloading_attachments' );
						$job->add_log( 'info', __( 'Downloading attachments…', 'wordpress-importer' ) );
					} else {
						$job->set_status( 'remapping' );
						$job->options['remap_cursor'] = array(
							'phase'    => 'posts',
							'posts'    => 0,
							'comments' => 0,
							'urls'     => 0,
							'featured' => 0,
						);
					}
				}

				$this->honor_pause_request( $job );
				$this->save_job( $job, $importer );
			}

			if ( 'downloading_attachments' === $job->status ) {
				$attachment_result = $importer->download_pending_attachments_batch( self::ATTACHMENT_BATCH_SIZE );
				$job->failed_items += isset( $attachment_result['failed'] ) ? (int) $attachment_result['failed'] : 0;

				if ( ! empty( $attachment_result['is_complete'] ) ) {
					$job->set_status( 'remapping' );
					$job->options['remap_cursor'] = array(
						'phase'    => 'posts',
						'posts'    => 0,
						'comments' => 0,
						'urls'     => 0,
						'featured' => 0,
					);
					$job->add_log( 'info', __( 'Remapping relationships…', 'wordpress-importer' ) );
				}

				$this->save_job( $job, $importer );
				$result['attachments'] = $attachment_result;
			}

			if ( 'remapping' === $job->status ) {
				$remap_result = $this->process_remapping_batch( $job, $importer, $batch_size );
				$this->save_job( $job, $importer );

				if ( ! empty( $remap_result['is_complete'] ) ) {
					$this->finalize_job( $job, $importer );
					$this->save_job( $job, $importer );
				}

				$result['remap'] = $remap_result;
			}

			return array_merge(
				array(
					'job_id' => $job->id,
					'status' => $job->status,
				),
				$job->to_status_array(),
				is_array( $result ) ? $result : array()
			);
		} finally {
			$this->release_lock( $job->id );
		}
	}

	/**
	 * Roll back to content processing when a job entered a later phase too early.
	 *
	 * @since 3.0.6
	 *
	 * @param WXR_Import_Job $job Job.
	 *
	 * @return void
	 */
	protected function recover_premature_phase( WXR_Import_Job $job ) {
		if ( $job->is_content_import_complete() ) {
			return;
		}

		if ( ! in_array( $job->status, array( 'remapping', 'downloading_attachments' ), true ) ) {
			return;
		}

		$job->status = 'processing';
		$job->add_log(
			'warning',
			__( 'Resuming content import — the job had not finished processing the export file.', 'wordpress-importer' )
		);
	}

	/**
	 * Whether the importer has deferred attachments left to download.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Importer $importer Importer instance.
	 *
	 * @return bool
	 */
	protected function has_pending_attachments( WXR_Importer $importer ) {
		return ! empty( $importer->get_pending_attachments() );
	}

	/**
	 * Reduce batch size when the previous request died at the same cursor.
	 *
	 * @since 3.0.8
	 *
	 * @param WXR_Import_Job $job        Job.
	 * @param int            $batch_size Requested batch size.
	 *
	 * @return int
	 */
	protected function adjust_batch_size_after_stale_batch( WXR_Import_Job $job, $batch_size ) {
		if ( empty( $job->options['active_batch'] ) || ! is_array( $job->options['active_batch'] ) ) {
			return $batch_size;
		}

		$active = $job->options['active_batch'];
		$cursor = isset( $active['cursor'] ) ? (int) $active['cursor'] : -1;
		if ( $cursor !== (int) $job->xml_cursor_item ) {
			unset( $job->options['active_batch'] );
			return $batch_size;
		}

		$started_at = isset( $active['started_at'] ) ? (int) $active['started_at'] : 0;
		if ( $started_at > 0 && ( time() - $started_at ) < self::LOCK_TTL ) {
			return $batch_size;
		}

		$previous_size = isset( $active['batch_size'] ) ? max( 1, (int) $active['batch_size'] ) : $batch_size;
		$reduced_size  = max( 1, (int) floor( $previous_size / 2 ) );

		if ( $reduced_size < $batch_size ) {
			$job->add_log(
				'warning',
				sprintf(
					/* translators: 1: cursor, 2: previous batch size, 3: reduced batch size */
					__( 'Previous batch at entity %1$d did not complete; reducing batch size from %2$d to %3$d and retrying.', 'wordpress-importer' ),
					$job->xml_cursor_item,
					$previous_size,
					$reduced_size
				)
			);
		}

		return min( $batch_size, $reduced_size );
	}

	/**
	 * Skip one entity when even a single-item batch repeatedly times out.
	 *
	 * @since 3.0.8
	 *
	 * @param WXR_Import_Job $job Job.
	 *
	 * @return bool Whether an entity was skipped.
	 */
	protected function skip_stale_single_entity( WXR_Import_Job $job, WXR_Importer $importer ) {
		if ( empty( $job->options['active_batch'] ) || ! is_array( $job->options['active_batch'] ) ) {
			return false;
		}

		$active = $job->options['active_batch'];
		$cursor = isset( $active['cursor'] ) ? (int) $active['cursor'] : -1;
		$batch_size = isset( $active['batch_size'] ) ? (int) $active['batch_size'] : 0;
		$started_at = isset( $active['started_at'] ) ? (int) $active['started_at'] : 0;

		if ( $cursor !== (int) $job->xml_cursor_item || $batch_size > 1 ) {
			return false;
		}

		if ( $started_at > 0 && ( time() - $started_at ) < self::LOCK_TTL ) {
			return false;
		}

		$inspection = $importer->inspect_manifest_entity( $job->file_path, (int) $job->xml_cursor_item );
		$item       = array(
			'entity_type'   => 'posts',
			'old_id'        => '',
			'title'         => sprintf( 'XML entity %d', $job->xml_cursor_item ),
			'new_id'        => null,
			'status'        => 'failed',
			'error_message' => __( 'Timed out repeatedly during import.', 'wordpress-importer' ),
		);

		if ( is_array( $inspection ) ) {
			$item = array_merge( $item, $inspection );
		}

		if ( ! empty( $item['new_id'] ) ) {
			$job->add_log(
				'error',
				sprintf(
					/* translators: 1: entity cursor, 2: post ID */
					__( 'Entity %1$d timed out after creating post %2$d; recording the partial result and continuing.', 'wordpress-importer' ),
					$job->xml_cursor_item,
					(int) $item['new_id']
				)
			);
			$item['error_message'] = __( 'Timed out after creating the post. Review this post because meta, terms, comments, or remapping may be incomplete.', 'wordpress-importer' );
		} else {
			$job->add_log(
				'error',
				sprintf(
					/* translators: %d: entity cursor */
					__( 'Entity %d failed repeatedly as a single-item batch; marking it failed and continuing.', 'wordpress-importer' ),
					$job->xml_cursor_item
				)
			);
		}

		$repository = new WXR_Import_Item_Repository();
		$repository->record_batch(
			$job->id,
			array(
				$item,
			)
		);

		$job->failed_items++;
		$job->xml_cursor_item++;
		unset( $job->options['active_batch'] );

		return true;
	}

	/**
	 * Record the currently running batch before expensive work begins.
	 *
	 * @since 3.0.8
	 *
	 * @param WXR_Import_Job $job        Job.
	 * @param int            $batch_size Batch size.
	 *
	 * @return void
	 */
	protected function mark_active_batch( WXR_Import_Job $job, $batch_size ) {
		$job->options['active_batch'] = array(
			'cursor'     => (int) $job->xml_cursor_item,
			'batch_size' => max( 1, (int) $batch_size ),
			'started_at' => time(),
		);
	}

	/**
	 * Preserve a pause request made while the current batch was running.
	 *
	 * @since 3.0.8
	 *
	 * @param WXR_Import_Job $job Job.
	 *
	 * @return void
	 */
	protected function honor_pause_request( WXR_Import_Job $job ) {
		$fresh_job = WXR_Import_Job::get( $job->id );
		if ( is_wp_error( $fresh_job ) ) {
			return;
		}

		$pause_requested = ! empty( $fresh_job->options['pause_requested'] ) || 'paused' === $fresh_job->status;
		if ( ! $pause_requested || $job->is_terminal() ) {
			return;
		}

		unset( $job->options['pause_requested'] );
		$job->set_status( 'paused' );
		$job->add_log( 'info', __( 'Import paused after the current batch finished.', 'wordpress-importer' ) );
	}

	/**
	 * Persist per-entity item results from a batch.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job       $job    Job.
	 * @param array<string, mixed> $result Batch result.
	 *
	 * @return void
	 */
	protected function record_item_results( WXR_Import_Job $job, array $result ) {
		if ( empty( $result['item_results'] ) || ! is_array( $result['item_results'] ) ) {
			return;
		}

		$repository = new WXR_Import_Item_Repository();
		$repository->record_batch( $job->id, $result['item_results'] );
	}

	/**
	 * Process a remapping batch.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job        Job.
	 * @param WXR_Importer   $importer   Importer.
	 * @param int            $batch_size Batch size.
	 *
	 * @return array<string, mixed>
	 */
	protected function process_remapping_batch( WXR_Import_Job $job, WXR_Importer $importer, $batch_size ) {
		$remapper = new WXR_Import_Remapper();
		return $remapper->process_batch( $job, $importer, $batch_size );
	}

	/**
	 * Finalize a completed job.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job      Job.
	 * @param WXR_Importer   $importer Importer.
	 *
	 * @return void
	 */
	protected function finalize_job( WXR_Import_Job $job, WXR_Importer $importer ) {
		$importer->import_end_public();
		$job->set_status( 'complete' );
		$job->add_log( 'info', __( 'Import complete.', 'wordpress-importer' ) );

		/**
		 * Fires when an import job completes successfully.
		 *
		 * @since 3.0.0
		 *
		 * @param WXR_Import_Job $job Import job.
		 */
		do_action( 'wxr_importer.job.completed', $job );

		$this->cleanup_job_files( $job );
		wxr_importer_cleanup_import_meta();
	}

	/**
	 * Clean up temporary import files after successful completion.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job Job.
	 *
	 * @return void
	 */
	protected function cleanup_job_files( WXR_Import_Job $job ) {
		$is_local = ! empty( $job->options['is_local_file'] );

		if ( $job->attachment_id && ! $is_local ) {
			wp_delete_attachment( $job->attachment_id, true );
		}

		delete_post_meta( $job->attachment_id, '_wxr_import_settings' );
		delete_post_meta( $job->attachment_id, '_wxr_import_info' );
		delete_post_meta( $job->attachment_id, '_wxr_import_job_id' );
	}

	/**
	 * Create an importer configured for the job.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job         $job    Job.
	 * @param WP_Importer_Logger_Job $logger Logger.
	 *
	 * @return WXR_Importer
	 */
	protected function create_importer( WXR_Import_Job $job, WP_Importer_Logger_Job $logger ) {
		$fetch_attachments = ! empty( $job->options['fetch_attachments'] );

		$options = array(
			'fetch_attachments'           => $fetch_attachments,
			'defer_attachment_download'   => $fetch_attachments,
			'aggressive_url_search'       => ! empty( $job->options['aggressive_url_search'] ),
			'default_author'              => isset( $job->options['default_author'] ) ? $job->options['default_author'] : null,
			'prefill_existing_posts'      => empty( $job->options['import_started'] ),
			'prefill_existing_comments'   => empty( $job->options['import_started'] ),
			'prefill_existing_terms'      => empty( $job->options['import_started'] ),
			'job_id'                      => $job->id,
			'post_meta_chunk_size'        => 25,
		);

		$importer = new WXR_Importer( $options );
		$importer->set_logger( $logger );

		if ( ! empty( $job->options['mapping'] ) ) {
			$author_map = $job->options['mapping'];
			if ( isset( $author_map['mapping'] ) ) {
				$importer->set_user_mapping( $author_map['mapping'] );
			}
			if ( ! empty( $author_map['slug_overrides'] ) ) {
				$importer->set_user_slug_overrides( $author_map['slug_overrides'] );
			}
		}

		if ( ! empty( $job->options['mapping_state'] ) ) {
			$importer->set_mapping_state( $job->options['mapping_state'] );
		}

		if ( ! empty( $job->options['import_environment_ready'] ) ) {
			$importer->set_import_started( true );
		}

		return $importer;
	}

	/**
	 * Persist job state and mapping from importer.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job $job      Job.
	 * @param WXR_Importer   $importer Importer.
	 *
	 * @return void
	 */
	protected function save_job( WXR_Import_Job $job, WXR_Importer $importer ) {
		$mapping_state = $importer->get_mapping_state();
		// Exists caches are rebuilt on demand; omitting them keeps job rows small on large imports.
		unset( $mapping_state['exists'] );

		$job->options['mapping_state']          = $mapping_state;
		$job->options['import_started']         = true;
		$job->options['import_environment_ready'] = true;

		$repository = new WXR_Import_Job_Repository();
		$repository->save( $job );
	}

	/**
	 * Apply batch counter results to the job.
	 *
	 * @since 3.0.0
	 *
	 * @param WXR_Import_Job         $job    Job.
	 * @param array<string, mixed>   $result Batch result.
	 *
	 * @return void
	 */
	protected function apply_batch_counts( WXR_Import_Job $job, array $result ) {
		if ( isset( $result['counts'] ) && is_array( $result['counts'] ) ) {
			$counts = $result['counts'];
			$job->processed_posts    += isset( $counts['posts'] ) ? (int) $counts['posts'] : 0;
			$job->processed_comments += isset( $counts['comments'] ) ? (int) $counts['comments'] : 0;
			$job->processed_terms    += isset( $counts['terms'] ) ? (int) $counts['terms'] : 0;
			$job->processed_users    += isset( $counts['users'] ) ? (int) $counts['users'] : 0;
			$job->processed_media    += isset( $counts['media'] ) ? (int) $counts['media'] : 0;
		}

		$job->failed_items  += isset( $result['failed'] ) ? (int) $result['failed'] : 0;
		$job->skipped_items += isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
	}

	/**
	 * Acquire a processing lock for a job.
	 *
	 * @since 3.0.0
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return bool
	 */
	protected function acquire_lock( $job_id ) {
		$key = 'wxr_import_job_lock_' . absint( $job_id );

		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, time(), self::LOCK_TTL );
		return true;
	}

	/**
	 * Release a processing lock.
	 *
	 * @since 3.0.0
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return void
	 */
	protected function release_lock( $job_id ) {
		delete_transient( 'wxr_import_job_lock_' . absint( $job_id ) );
	}

	/**
	 * Cron handler — process the next runnable job.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public static function cron_process_next() {
		$repository = new WXR_Import_Job_Repository();
		$job        = $repository->get_next_runnable_job();

		if ( ! $job ) {
			return;
		}

		$processor = new self();
		$processor->process_job( $job->id );
	}
}
