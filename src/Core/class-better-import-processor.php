<?php
/**
 * Time-based import batch processor.
 *
 * @package Better_WordPress_Importer
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Processes queue items until a time budget is exhausted.
 *
 * @since 1.1.0
 */
class Better_Import_Processor {

	/**
	 * Default batch wall-clock budget in seconds.
	 *
	 * @since 1.1.0
	 */
	const DEFAULT_BATCH_SECONDS = 25;

	/**
	 * Default per-entity timeout in seconds.
	 *
	 * @since 1.1.0
	 */
	const DEFAULT_ENTITY_TIMEOUT = 10;

	/**
	 * Lock TTL in seconds.
	 *
	 * @since 1.1.0
	 */
	const LOCK_TTL = 60;

	/**
	 * Deferred relationships resolved per remap chunk.
	 *
	 * @since 1.6.0
	 */
	const REMAP_CHUNK_SIZE = 100;

	/**
	 * Job repository.
	 *
	 * @since 1.1.0
	 * @var Better_Import_Job_Repository
	 */
	protected $job_repo;

	/**
	 * Queue repository.
	 *
	 * @since 1.1.0
	 * @var Better_Import_Queue_Repository
	 */
	protected $queue_repo;

	/**
	 * Entity importer.
	 *
	 * @since 1.1.0
	 * @var Better_Importer
	 */
	protected $importer;

	/**
	 * Missing taxonomy assignment counts for the active batch.
	 *
	 * @since 1.5.0
	 * @var array<string, int>
	 */
	protected $skipped_taxonomies = array();

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->job_repo   = new Better_Import_Job_Repository();
		$this->queue_repo = new Better_Import_Queue_Repository();
	}

	/**
	 * Process one batch for a job.
	 *
	 * @since 1.1.0
	 *
	 * @param int $job_id        Job ID.
	 * @param int $max_seconds   Wall-clock budget.
	 * @param int $entity_timeout Per-entity timeout.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_batch( $job_id, $max_seconds = 0, $entity_timeout = 0 ) {
		$job = $this->job_repo->get( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'better_importer.job.not_found', __( 'Import job not found.', 'better-wordpress-importer' ) );
		}

		if ( $job->is_terminal() ) {
			return array(
				'job_id'      => $job->id,
				'status'      => $job->status,
				'is_complete' => true,
			);
		}

		if ( 'paused' === $job->status ) {
			$this->release_lock( $job->id );
			return array(
				'job_id'  => $job->id,
				'status'  => $job->status,
				'message' => __( 'Import job is paused.', 'better-wordpress-importer' ),
			);
		}

		if ( ! $job->can_process() ) {
			return new WP_Error(
				'better_importer.job.not_processable',
				__( 'Import job is not ready for processing.', 'better-wordpress-importer' )
			);
		}

		if ( ! $this->acquire_lock( $job->id ) ) {
			return array(
				'job_id'  => $job->id,
				'status'  => $job->status,
				'message' => __( 'Another batch is already running for this job.', 'better-wordpress-importer' ),
			);
		}

		$max_seconds    = $max_seconds > 0 ? $max_seconds : (int) apply_filters( 'better_importer.batch.seconds', self::DEFAULT_BATCH_SECONDS, $job );
		$entity_timeout = $entity_timeout > 0 ? $entity_timeout : (int) apply_filters( 'better_importer.entity.timeout', self::DEFAULT_ENTITY_TIMEOUT, $job );

		// Fetching attachments performs remote downloads, so give each entity —
		// and the PHP process — more headroom to avoid failing created media.
		if ( $job->get_option( 'fetch_attachments', false ) && $entity_timeout < 60 ) {
			$entity_timeout = 60;
		}

		$logger           = new Better_Logger( $job->id );
		$this->importer   = new Better_Importer( $logger );
		$remapper         = Better_Import_Remapper::from_job( $job );
		$batch_start      = microtime( true );
		$processed        = 0;
		$skipped          = 0;
		$failed           = 0;
		$this->skipped_taxonomies = array();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		wp_raise_memory_limit( 'admin' );
		set_time_limit( max( 30, $max_seconds + $entity_timeout + 5 ) );

		$previous_cache_invalidation = wp_suspend_cache_invalidation( true );
		$previous_term_counting      = wp_defer_term_counting();
		$previous_comment_counting   = wp_defer_comment_counting();
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		$restore_runtime = function() use ( $previous_cache_invalidation, $previous_term_counting, $previous_comment_counting ) {
			wp_suspend_cache_invalidation( $previous_cache_invalidation );
			wp_defer_term_counting( $previous_term_counting );
			wp_defer_comment_counting( $previous_comment_counting );
		};

		if ( 'queued' === $job->status ) {
			$job->status    = 'processing';
			$job->phase     = 'importing';
			$job->started_at = current_time( 'mysql', true );
			$this->job_repo->save_state( $job );
			$logger->info( __( 'Import processing started.', 'better-wordpress-importer' ) );
		}

		if ( ! $job->get_option( 'fetch_attachments', false ) ) {
			$bulk_skipped = $this->queue_repo->skip_pending_attachments( $job->id );
			if ( $bulk_skipped > 0 ) {
				$skipped += $bulk_skipped;
				$logger->info(
					sprintf(
						/* translators: %d: attachment count */
						__( 'Skipped %d media items because attachment import is disabled.', 'better-wordpress-importer' ),
						$bulk_skipped
					)
				);
			}
		}

		while ( ( microtime( true ) - $batch_start ) < $max_seconds ) {
			$current_status = $this->job_repo->get_status( $job->id );
			if ( null === $current_status || 'paused' === $current_status ) {
				$restore_runtime();
				$this->release_lock( $job->id );
				return array(
					'job_id'  => $job->id,
					'status'  => null === $current_status ? 'paused' : $current_status,
					'message' => __( 'Import job is paused.', 'better-wordpress-importer' ),
				);
			}

			$item = $this->queue_repo->get_next_work_item( $job->id );
			if ( ! $item ) {
				// Queue drained: resolve deferred parent/author relationships
				// before declaring the job complete.
				$remapped = $this->importer->process_remap_chunk( $job, $remapper, self::REMAP_CHUNK_SIZE );
				if ( $remapped > 0 ) {
					if ( 'remapping' !== $job->phase ) {
						$job->phase = 'remapping';
						$logger->info( __( 'Resolving deferred relationships.', 'better-wordpress-importer' ) );
					}
					continue;
				}

				$job->status        = 'complete';
				$job->phase         = 'complete';
				$job->completed_at  = current_time( 'mysql', true );
				$job->mapping_state = $remapper->to_array();
				$this->job_repo->sync_counters_from_queue( $job );
				$this->job_repo->save_state( $job );
				$restore_runtime();
				$this->release_lock( $job->id );

				/**
				 * Fires when an import job finishes processing all queue items.
				 *
				 * @since 1.1.0
				 *
				 * @param Better_Import_Job $job Import job.
				 */
				do_action( 'better_importer.job.completed', $job );
				do_action( 'import_end' );

				$logger->info( __( 'Import processing completed.', 'better-wordpress-importer' ) );

				return array(
					'job_id'      => $job->id,
					'status'      => $job->status,
					'processed'   => $processed,
					'skipped'     => $skipped,
					'failed'      => $failed,
					'is_complete' => true,
				);
			}

			$entity_start = microtime( true );

			if ( 'pending' === $item->status ) {
				$item->status = 'in_progress';
				$this->queue_repo->save( $item );
			}

			$payload_result = $this->ensure_payload( $job, $item, $logger );
			if ( is_wp_error( $payload_result ) ) {
				$this->mark_item_failed( $item, $payload_result->get_error_code(), $payload_result->get_error_message() );
				++$failed;
				continue;
			}

			$payload = $item->get_decoded_payload();
			if ( ! is_array( $payload ) ) {
				$this->mark_item_failed( $item, 'better_importer.payload.missing', __( 'Parsed payload is missing.', 'better-wordpress-importer' ) );
				++$failed;
				continue;
			}

			$step_result = $this->process_next_step( $job, $item, $payload, $remapper, $logger );
			if ( 'failed' !== $step_result['status'] ) {
				$this->checkpoint( $item );
			}

			if ( ( microtime( true ) - $entity_start ) > $entity_timeout && 'complete' !== $step_result['status'] && 'skipped' !== $step_result['status'] ) {
				$this->mark_item_failed(
					$item,
					'better_importer.entity.timeout',
					sprintf(
						/* translators: %s: seconds */
						__( 'Entity timed out after %s seconds.', 'better-wordpress-importer' ),
						round( microtime( true ) - $entity_start, 1 )
					)
				);
				++$failed;
				continue;
			}

			if ( 'complete' === $step_result['status'] ) {
				++$processed;
			} elseif ( 'skipped' === $step_result['status'] ) {
				++$skipped;
			} elseif ( 'failed' === $step_result['status'] ) {
				++$failed;
			}
		}

		$job->mapping_state = $remapper->to_array();
		$this->job_repo->sync_counters_from_queue( $job );
		$this->job_repo->save_state( $job );

		if ( $processed > 0 || $skipped > 0 || $failed > 0 ) {
			$logger->info(
				sprintf(
					/* translators: 1: completed items, 2: skipped items, 3: failed items */
					__( 'Batch finished: %1$d completed, %2$d skipped, %3$d failed.', 'better-wordpress-importer' ),
					$processed,
					$skipped,
					$failed
				)
			);
		}

		if ( ! empty( $this->skipped_taxonomies ) ) {
			foreach ( $this->skipped_taxonomies as $taxonomy => $count ) {
				$logger->warning(
					sprintf(
						/* translators: 1: assignment count, 2: taxonomy slug */
						__( 'Skipped %1$d term assignments because taxonomy "%2$s" is not registered on this site.', 'better-wordpress-importer' ),
						$count,
						$taxonomy
					)
				);
			}
		}

		$this->release_lock( $job->id );
		$restore_runtime();

		return array(
			'job_id'      => $job->id,
			'status'      => $job->status,
			'processed'   => $processed,
			'skipped'     => $skipped,
			'failed'      => $failed,
			'is_complete' => false,
		);
	}

	/**
	 * Verify the queue item has its persisted parsed payload.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job        $job    Import job.
	 * @param Better_Import_Queue_Item $item   Queue item.
	 * @param Better_Logger            $logger Logger.
	 *
	 * @return true|WP_Error
	 */
	protected function ensure_payload( Better_Import_Job $job, Better_Import_Queue_Item $item, Better_Logger $logger ) {
		if ( ! empty( $item->parsed_payload ) ) {
			return true;
		}

		$error = new WP_Error(
			'better_importer.payload.missing',
			__( 'Parsed entity payload is missing from the queue row. Recreate the import job so the file is parsed once into persistent queue payloads.', 'better-wordpress-importer' )
		);

		$logger->error( $error->get_error_message(), $item->entity_index );

		return $error;
	}

	/**
	 * Advance one sub-step for a queue item.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job        $job      Import job.
	 * @param Better_Import_Queue_Item $item     Queue item.
	 * @param array<string, mixed>     $payload  Parsed payload.
	 * @param Better_Import_Remapper   $remapper ID remapper.
	 * @param Better_Logger            $logger   Logger.
	 *
	 * @return array<string, string>
	 */
	protected function process_next_step( Better_Import_Job $job, Better_Import_Queue_Item $item, array $payload, Better_Import_Remapper $remapper, Better_Logger $logger ) {
		switch ( $item->step ) {
			case 'create':
				return $this->step_create_entity( $job, $item, $payload, $remapper, $logger );
			case 'import_meta':
				return $this->step_import_meta_chunk( $job, $item, $payload, $remapper );
			case 'import_comments':
				return $this->step_import_comments_chunk( $job, $item, $payload, $remapper );
			case 'assign_terms':
				return $this->step_assign_terms( $job, $item, $payload, $logger );
			case 'complete':
				return $this->finalize_item( $item );
		}

		return array( 'status' => 'failed' );
	}

	/**
	 * Create the local entity for a queue item.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job        $job      Import job.
	 * @param Better_Import_Queue_Item $item     Queue item.
	 * @param array<string, mixed>     $payload  Parsed payload.
	 * @param Better_Import_Remapper   $remapper ID remapper.
	 * @param Better_Logger            $logger   Logger.
	 *
	 * @return array<string, string>
	 */
	protected function step_create_entity( Better_Import_Job $job, Better_Import_Queue_Item $item, array $payload, Better_Import_Remapper $remapper, Better_Logger $logger ) {
		if ( 'user' === $item->entity_type ) {
			$result = $this->importer->import_user( $job, $payload, $remapper );
		} elseif ( 'term' === $item->entity_type ) {
			$result = $this->importer->import_term( $job, $payload, $remapper );
		} else {
			$result = $this->importer->create_post( $job, $item, $payload, $remapper );
		}

		if ( 'failed' === $result['status'] ) {
			$code = isset( $result['code'] ) ? $result['code'] : 'better_importer.create.failed';
			$this->mark_item_failed( $item, $code, $result['error'] );
			$logger->error( $result['error'], $item->entity_index );
			return array( 'status' => 'failed' );
		}

		if ( ! empty( $result['message'] ) && 'post' !== $item->entity_type ) {
			$logger->info( $result['message'], $item->entity_index );
		}

		if ( ! empty( $result['new_entity_id'] ) ) {
			$item->new_entity_id = (int) $result['new_entity_id'];
		}

		if ( in_array( $result['status'], array( 'skipped', 'complete' ), true ) && in_array( $item->entity_type, array( 'user', 'term' ), true ) ) {
			$item->status = 'skipped' === $result['status'] ? 'skipped' : 'complete';
			$item->step   = 'complete';
			return $this->finalize_item( $item );
		}

		if ( 'skipped' === $result['status'] ) {
			$item->status = 'skipped';
			$item->step   = 'complete';
			return $this->finalize_item( $item );
		}

		$meta_count    = count( $payload['meta'] ?? array() );
		$comment_count = count( $payload['comments'] ?? array() );
		$term_count    = count( $payload['terms'] ?? array() );

		if ( $meta_count > 0 ) {
			$item->step        = 'import_meta';
			$item->step_cursor = 0;
			$item->step_total  = $meta_count;
			update_post_meta( $item->new_entity_id, '_better_import_meta_total', $meta_count );
		} elseif ( $comment_count > 0 ) {
			$item->step        = 'import_comments';
			$item->step_cursor = 0;
			$item->step_total  = $comment_count;
		} elseif ( $term_count > 0 ) {
			$item->step = 'assign_terms';
		} else {
			$item->step = 'complete';
			return $this->finalize_item( $item );
		}

		return array( 'status' => 'more_work' );
	}

	/**
	 * Import the next chunk of post meta.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job        $job      Import job.
	 * @param Better_Import_Queue_Item $item     Queue item.
	 * @param array<string, mixed>     $payload  Parsed payload.
	 * @param Better_Import_Remapper   $remapper ID remapper.
	 *
	 * @return array<string, string>
	 */
	protected function step_import_meta_chunk( Better_Import_Job $job, Better_Import_Queue_Item $item, array $payload, Better_Import_Remapper $remapper ) {
		$chunk_size = (int) $job->get_option( 'meta_chunk_size', 200 );
		$write_mode = (string) $job->get_option( 'meta_write_mode', 'bulk' );
		$meta       = isset( $payload['meta'] ) ? $payload['meta'] : array();
		$slice      = array_slice( $meta, $item->step_cursor, $chunk_size );
		$post_data  = isset( $payload['data'] ) ? $payload['data'] : array();

		$result = $this->importer->import_meta_chunk( $item->new_entity_id, $slice, $post_data, $write_mode, $remapper );
		if ( is_wp_error( $result ) ) {
			$this->mark_item_failed( $item, $result->get_error_code(), $result->get_error_message() );
			return array( 'status' => 'failed' );
		}

		$item->step_cursor += count( $slice );

		if ( $item->step_cursor >= $item->step_total ) {
			$comment_count = count( $payload['comments'] ?? array() );
			if ( $comment_count > 0 ) {
				$item->step        = 'import_comments';
				$item->step_cursor = 0;
				$item->step_total  = $comment_count;
			} elseif ( ! empty( $payload['terms'] ) ) {
				$item->step = 'assign_terms';
			} else {
				$item->step = 'complete';
				return $this->finalize_item( $item );
			}
		}

		return array( 'status' => 'more_work' );
	}

	/**
	 * Import the next chunk of comments.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job        $job      Import job.
	 * @param Better_Import_Queue_Item $item     Queue item.
	 * @param array<string, mixed>     $payload  Parsed payload.
	 * @param Better_Import_Remapper   $remapper ID remapper.
	 *
	 * @return array<string, string>
	 */
	protected function step_import_comments_chunk( Better_Import_Job $job, Better_Import_Queue_Item $item, array $payload, Better_Import_Remapper $remapper ) {
		$chunk_size = (int) $job->get_option( 'comment_chunk_size', 100 );
		$comments   = isset( $payload['comments'] ) ? $payload['comments'] : array();
		$slice      = array_slice( $comments, $item->step_cursor, $chunk_size );

		$this->importer->import_comments_chunk( $item->new_entity_id, $slice, $remapper );

		$item->step_cursor += count( $slice );

		if ( $item->step_cursor >= $item->step_total ) {
			if ( ! empty( $payload['terms'] ) ) {
				$item->step = 'assign_terms';
			} else {
				$item->step = 'complete';
				return $this->finalize_item( $item );
			}
		}

		return array( 'status' => 'more_work' );
	}

	/**
	 * Assign taxonomy terms to a post.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job        $job     Import job.
	 * @param Better_Import_Queue_Item $item    Queue item.
	 * @param array<string, mixed>     $payload Parsed payload.
	 * @param Better_Logger            $logger  Logger.
	 *
	 * @return array<string, string>
	 */
	protected function step_assign_terms( Better_Import_Job $job, Better_Import_Queue_Item $item, array $payload, Better_Logger $logger ) {
		$missing_taxonomies = $this->importer->get_missing_term_taxonomies( isset( $payload['terms'] ) ? $payload['terms'] : array() );
		foreach ( $missing_taxonomies as $taxonomy ) {
			if ( ! isset( $this->skipped_taxonomies[ $taxonomy ] ) ) {
				$this->skipped_taxonomies[ $taxonomy ] = 0;
			}
			++$this->skipped_taxonomies[ $taxonomy ];
		}

		$result = $this->importer->assign_terms( $item->new_entity_id, isset( $payload['terms'] ) ? $payload['terms'] : array() );

		if ( is_wp_error( $result ) ) {
			$this->mark_item_failed( $item, $result->get_error_code(), $result->get_error_message() );
			$logger->error( $result->get_error_message(), $item->entity_index );
			return array( 'status' => 'failed' );
		}

		$item->step = 'complete';
		return $this->finalize_item( $item );
	}

	/**
	 * Mark a queue item complete and clear its payload.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Queue_Item $item Queue item.
	 *
	 * @return array<string, string>
	 */
	protected function finalize_item( Better_Import_Queue_Item $item ) {
		$item->status         = ( 'skipped' === $item->status ) ? 'skipped' : 'complete';
		$item->step           = 'complete';
		$item->parsed_payload = null;
		$item->payload_hash   = '';

		return array( 'status' => $item->status );
	}

	/**
	 * Persist a queue item after a sub-step.
	 *
	 * Only the queue row is written here. The job row (mapping state and
	 * counters) is persisted once per batch, not per sub-step, so the
	 * immutable entity manifest is never rewritten inside the hot loop.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Queue_Item $item Queue item.
	 *
	 * @return void
	 */
	protected function checkpoint( Better_Import_Queue_Item $item ) {
		$this->queue_repo->save( $item );
	}

	/**
	 * Mark a queue item as failed.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Queue_Item $item Queue item.
	 * @param string                 $code Error code.
	 * @param string                 $message Error message.
	 *
	 * @return void
	 */
	protected function mark_item_failed( Better_Import_Queue_Item $item, $code, $message ) {
		$item->status        = 'failed';
		$item->error_code    = sanitize_key( str_replace( '.', '_', $code ) );
		$item->error_message = $message;
		$item->last_error_at = current_time( 'mysql', true );
		$item->attempts     += 1;
		$this->queue_repo->save( $item );
	}

	/**
	 * Acquire a short-lived batch lock for a job.
	 *
	 * @since 1.1.0
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return bool
	 */
	protected function acquire_lock( $job_id ) {
		$key = 'better_import_job_lock_' . absint( $job_id );
		return (bool) set_transient( $key, time(), self::LOCK_TTL );
	}

	/**
	 * Release a batch lock for a job.
	 *
	 * @since 1.1.0
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return void
	 */
	protected function release_lock( $job_id ) {
		delete_transient( 'better_import_job_lock_' . absint( $job_id ) );
	}
}
