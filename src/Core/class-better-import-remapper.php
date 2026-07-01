<?php
/**
 * ID mapping state for import remapping.
 *
 * @package Better_WordPress_Importer
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracks old-to-new ID mappings during an import job.
 *
 * Large buckets that correspond to a queue row (`post`, `user`, `term_id`)
 * are resolved on demand from the queue table instead of being carried in the
 * job row. Only the small derived buckets (`user_slug`, `term`, `comment`) are
 * persisted in `mapping_state`. This keeps the persisted mapping bounded and
 * avoids re-serializing a map that grows with every imported post.
 *
 * @since 1.1.0
 */
class Better_Import_Remapper {

	/**
	 * Buckets resolvable from queue rows, mapped to their queue entity types.
	 *
	 * @since 1.6.0
	 * @var array<string, array<int, string>>
	 */
	const QUEUE_BACKED = array(
		'post'    => array( 'post', 'attachment' ),
		'user'    => array( 'user' ),
		'term_id' => array( 'term' ),
	);

	/**
	 * Buckets persisted in the job row (not derivable from queue rows).
	 *
	 * @since 1.6.0
	 * @var array<int, string>
	 */
	const PERSISTED = array( 'user_slug', 'term', 'comment' );

	/**
	 * In-memory mapping cache keyed by entity type.
	 *
	 * Acts as a write-through cache: every set() lands here, and queue-backed
	 * lookups populate it from the database on a miss.
	 *
	 * @since 1.1.0
	 * @var array<string, array<string|int, int>>
	 */
	protected $mapping = array(
		'post'      => array(),
		'comment'   => array(),
		'term'      => array(),
		'term_id'   => array(),
		'user'      => array(),
		'user_slug' => array(),
	);

	/**
	 * Import job ID used for queue-backed lookups.
	 *
	 * @since 1.6.0
	 * @var int
	 */
	protected $job_id = 0;

	/**
	 * Lazily-created queue repository for ID resolution.
	 *
	 * @since 1.6.0
	 * @var Better_Import_Queue_Repository|null
	 */
	protected $queue_repo = null;

	/**
	 * Hydrate mapping state from a job row.
	 *
	 * Only the persisted (non-queue-backed) buckets are loaded; the large
	 * buckets resolve lazily from the queue table.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job $job Import job.
	 *
	 * @return Better_Import_Remapper
	 */
	public static function from_job( Better_Import_Job $job ) {
		$remapper         = new self();
		$remapper->job_id = (int) $job->id;
		$state            = is_array( $job->mapping_state ) ? $job->mapping_state : array();

		foreach ( self::PERSISTED as $bucket ) {
			if ( ! empty( $state[ $bucket ] ) && is_array( $state[ $bucket ] ) ) {
				$remapper->mapping[ $bucket ] = $state[ $bucket ];
			}
		}

		return $remapper;
	}

	/**
	 * Export mapping state for persistence on the job.
	 *
	 * Queue-backed buckets are intentionally omitted; they live on the queue
	 * rows and are resolved on demand.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array<string|int, int>>
	 */
	public function to_array() {
		$out = array();

		foreach ( self::PERSISTED as $bucket ) {
			$out[ $bucket ] = isset( $this->mapping[ $bucket ] ) ? $this->mapping[ $bucket ] : array();
		}

		return $out;
	}

	/**
	 * Store a mapping entry.
	 *
	 * @since 1.1.0
	 *
	 * @param string     $type   Mapping bucket.
	 * @param string|int $old_id Source ID or key.
	 * @param int        $new_id Local ID.
	 *
	 * @return void
	 */
	public function set( $type, $old_id, $new_id ) {
		if ( ! isset( $this->mapping[ $type ] ) ) {
			$this->mapping[ $type ] = array();
		}

		$this->mapping[ $type ][ $old_id ] = (int) $new_id;
	}

	/**
	 * Read a mapping entry.
	 *
	 * @since 1.1.0
	 *
	 * @param string     $type   Mapping bucket.
	 * @param string|int $old_id Source ID or key.
	 *
	 * @return int|null
	 */
	public function get( $type, $old_id ) {
		if ( isset( $this->mapping[ $type ][ $old_id ] ) ) {
			return (int) $this->mapping[ $type ][ $old_id ];
		}

		if ( isset( self::QUEUE_BACKED[ $type ] ) && $this->job_id > 0 ) {
			$resolved = $this->queue_repo()->get_new_entity_id( $this->job_id, self::QUEUE_BACKED[ $type ], $old_id );
			if ( null !== $resolved ) {
				// Cache the resolved ID so repeat lookups stay in memory.
				$this->mapping[ $type ][ $old_id ] = $resolved;
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * Check whether a mapping entry exists.
	 *
	 * @since 1.1.0
	 *
	 * @param string     $type   Mapping bucket.
	 * @param string|int $old_id Source ID or key.
	 *
	 * @return bool
	 */
	public function has( $type, $old_id ) {
		if ( isset( $this->mapping[ $type ] ) && array_key_exists( $old_id, $this->mapping[ $type ] ) ) {
			return true;
		}

		return null !== $this->get( $type, $old_id );
	}

	/**
	 * Get the queue repository, creating it on first use.
	 *
	 * @since 1.6.0
	 *
	 * @return Better_Import_Queue_Repository
	 */
	protected function queue_repo() {
		if ( null === $this->queue_repo ) {
			$this->queue_repo = new Better_Import_Queue_Repository();
		}

		return $this->queue_repo;
	}
}
