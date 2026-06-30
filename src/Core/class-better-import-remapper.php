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
 * @since 1.1.0
 */
class Better_Import_Remapper {

	/**
	 * Mapping buckets keyed by entity type.
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
	 * Hydrate mapping state from a job row.
	 *
	 * @since 1.1.0
	 *
	 * @param Better_Import_Job $job Import job.
	 *
	 * @return Better_Import_Remapper
	 */
	public static function from_job( Better_Import_Job $job ) {
		$remapper = new self();
		$state    = is_array( $job->mapping_state ) ? $job->mapping_state : array();

		foreach ( array_keys( $remapper->mapping ) as $bucket ) {
			if ( ! empty( $state[ $bucket ] ) && is_array( $state[ $bucket ] ) ) {
				$remapper->mapping[ $bucket ] = $state[ $bucket ];
			}
		}

		return $remapper;
	}

	/**
	 * Export mapping state for persistence on the job.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array<string|int, int>>
	 */
	public function to_array() {
		return $this->mapping;
	}

	/**
	 * Store a mapping entry.
	 *
	 * @since 1.1.0
	 *
	 * @param string       $type   Mapping bucket.
	 * @param string|int   $old_id Source ID or key.
	 * @param int          $new_id Local ID.
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
		if ( ! isset( $this->mapping[ $type ][ $old_id ] ) ) {
			return null;
		}

		return (int) $this->mapping[ $type ][ $old_id ];
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
		return array_key_exists( $old_id, $this->mapping[ $type ] );
	}
}
