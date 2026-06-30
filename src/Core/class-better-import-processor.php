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
	 * WXR parser.
	 *
	 * @since 1.1.0
	 * @var Better_WXR_Parser
	 */
	protected $parser;

	/**
	 * Entity importer.
	 *
	 * @since 1.1.0
	 * @var Better_Importer
	 */
	protected $importer;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->job_repo   = new Better_Import_Job_Repository();
		$this->queue_repo = new Better_Import_Queue_Repository();
		$this->parser     = new Better_WXR_Parser();
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

		$logger           = new Better_Logger( $job->id );
		$this->importer   = new Better_Importer( $logger );
		$remapper         = Better_Import_Remapper::from_job( $job );
		$batch_start      = microtime( true );
		$processed        = 0;
		$skipped          = 0;
		$failed           = 0;

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		wp_raise_memory_limit( 'admin' );
		set_time_limit( max( 30, $max_seconds + 5 ) );

		if ( 'queued' === $job->status ) {
			$job->status    = 'processing';
			$job->phase     = 'importing';
			$job->started_at = current_time( 'mysql', true );
			$this->job_repo->save( $job );
			$logger->info( __( 'Import processing started.', 'better-wordpress-importer' ) );
		}

		while ( ( microtime( true ) - $batch_start ) < $max_seconds ) {
			$job = $this->job_repo->get( $job->id );
			if ( ! $job || 'paused' === $job->status ) {
				$this->release_lock( $job ? $job->id : $job_id );
				return array(
					'job_id'  => $job_id,
					'status'  => $job ? $job->status : 'paused',
					'message' => __( 'Import job is paused.', 'better-wordpress-importer' ),
				);
			}

			$item = $this->queue_repo->get_next_work_item( $job->id );
			if ( ! $item ) {
		$job->status       = 'complete';
		$job->phase        = 'complete';
		$job->completed_at = current_time( 'mysql', true );
		$job->mapping_state = $remapper->to_array();
		$this->job_repo->sync_counters_from_queue( $job );
		$this->job_repo->save( $job );
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

		return array(
					'job_id'      => $job->id,
					'status'      => $job->status,
					'processed'   => $processed,
					'skipped'     => $skipped,
					'failed'      => $failed,
					'is_complete' => true,
				);
			}

			if ( 'attachment' === $item->entity_type && ! $job->get_option( 'fetch_attachments', false ) ) {
				$item->status = 'skipped';
				$this->queue_repo->save( $item );
				++$skipped;
				continue;
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
			$this->checkpoint( $job, $item, $remapper );

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
		$this->job_repo->save( $job );
		$this->release_lock( $job->id );

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
	 * Parse XML into the queue payload when needed.
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

		$parsed = $this->parser->parse_entity_at_index( $job->file_path, $item->entity_index );
		if ( is_wp_error( $parsed ) ) {
			$logger->error( $parsed->get_error_message(), $item->entity_index );
			return $parsed;
		}

		$item->set_encoded_payload( $parsed );

		$meta_count     = count( $parsed['meta'] ?? array() );
		$comment_count  = count( $parsed['comments'] ?? array() );
		$item->step     = 'create';
		$item->step_cursor = 0;
		$item->step_total  = $meta_count + $comment_count;

		$this->queue_repo->save( $item );

		return true;
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
				return $this->step_import_meta_chunk( $job, $item, $payload );
			case 'import_comments':
				return $this->step_import_comments_chunk( $job, $item, $payload, $remapper );
			case 'assign_terms':
				return $this->step_assign_terms( $job, $item, $payload );
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
			$this->mark_item_failed( $item, 'better_importer.create.failed', $result['error'] );
			$logger->error( $result['error'], $item->entity_index );
			return array( 'status' => 'failed' );
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
	 * @param Better_Import_Job        $job     Import job.
	 * @param Better_Import_Queue_Item $item    Queue item.
	 * @param array<string, mixed>     $payload Parsed payload.
	 *
	 * @return array<string, string>
	 */
	protected function step_import_meta_chunk( Better_Import_Job $job, Better_Import_Queue_Item $item, array $payload ) {
		$chunk_size = (int) $job->get_option( 'meta_chunk_size', 25 );
		$meta       = isset( $payload['meta'] ) ? $payload['meta'] : array();
		$slice      = array_slice( $meta, $item->step_cursor, $chunk_size );
		$post_data  = isset( $payload['data'] ) ? $payload['data'] : array();

		$this->importer->import_meta_chunk( $item->new_entity_id, $slice, $post_data );

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
		$chunk_size = (int) $job->get_option( 'comment_chunk_size', 25 );
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
	 *
	 * @return array<string, string>
	 */
	protected function step_assign_terms( Better_Import_Job $job, Better_Import_Queue_Item $item, array $payload ) {
		$this->importer->assign_terms( $item->new_entity_id, isset( $payload['terms'] ) ? $payload['terms'] : array() );
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
	 * Persist queue item and job mapping state after a sub-step.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job        $job      Import job.
	 * @param Better_Import_Queue_Item $item     Queue item.
	 * @param Better_Import_Remapper   $remapper ID remapper.
	 *
	 * @return void
	 */
	protected function checkpoint( Better_Import_Job $job, Better_Import_Queue_Item $item, Better_Import_Remapper $remapper ) {
		$this->queue_repo->save( $item );
		$job->mapping_state = $remapper->to_array();
		$this->job_repo->save( $job );
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
		$item->error_code    = sanitize_key( $code );
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
