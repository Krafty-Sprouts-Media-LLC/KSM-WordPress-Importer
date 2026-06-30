<?php
/**
 * Import queue row model — one entity in the work plan.
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queue item model.
 *
 * @since 1.0.0
 */
class Better_Import_Queue_Item {

	/**
	 * Row ID.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $id = 0;

	/**
	 * Parent job ID.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $job_id = 0;

	/**
	 * Zero-based entity index in the manifest.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $entity_index = 0;

	/**
	 * Entity type: user, term, post, attachment.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $entity_type = '';

	/**
	 * Source entity ID from the export file.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $old_entity_id = '';

	/**
	 * Local entity ID after import.
	 *
	 * @since 1.0.0
	 * @var int|null
	 */
	public $new_entity_id = null;

	/**
	 * Queue status.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $status = 'pending';

	/**
	 * Current processing step.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $step = 'create';

	/**
	 * Sub-step cursor for chunked work.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $step_cursor = 0;

	/**
	 * Total sub-steps for the current phase.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $step_total = 0;

	/**
	 * Gzipped serialized entity payload.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public $parsed_payload = null;

	/**
	 * Hash of the parsed payload for integrity checks.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $payload_hash = '';

	/**
	 * Human-readable entity title.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public $title = null;

	/**
	 * Processing attempt count.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $attempts = 0;

	/**
	 * Last error message.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public $error_message = null;

	/**
	 * Last error code.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public $error_code = null;

	/**
	 * Timestamp of the last error.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	public $last_error_at = null;

	/**
	 * Created timestamp (UTC).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $created_at = '';

	/**
	 * Updated timestamp (UTC).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $updated_at = '';

	/**
	 * Hydrate a queue item from a database row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Database row object.
	 *
	 * @return Better_Import_Queue_Item
	 */
	public static function from_row( $row ) {
		$item = new self();

		$item->id             = (int) $row->id;
		$item->job_id         = (int) $row->job_id;
		$item->entity_index   = (int) $row->entity_index;
		$item->entity_type    = (string) $row->entity_type;
		$item->old_entity_id  = (string) $row->old_entity_id;
		$item->new_entity_id  = isset( $row->new_entity_id ) ? (int) $row->new_entity_id : null;
		$item->status         = (string) $row->status;
		$item->step           = (string) $row->step;
		$item->step_cursor    = (int) $row->step_cursor;
		$item->step_total     = (int) $row->step_total;
		$item->parsed_payload = $row->parsed_payload;
		$item->payload_hash   = (string) $row->payload_hash;
		$item->title          = isset( $row->title ) ? (string) $row->title : null;
		$item->attempts       = (int) $row->attempts;
		$item->error_message  = isset( $row->error_message ) ? (string) $row->error_message : null;
		$item->error_code     = isset( $row->error_code ) ? (string) $row->error_code : null;
		$item->last_error_at  = isset( $row->last_error_at ) ? (string) $row->last_error_at : null;
		$item->created_at     = (string) $row->created_at;
		$item->updated_at     = (string) $row->updated_at;

		return $item;
	}

	/**
	 * Decode gzipped serialized payload from storage.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_decoded_payload() {
		if ( empty( $this->parsed_payload ) ) {
			return null;
		}

		$raw = @gzuncompress( $this->parsed_payload );
		if ( false === $raw ) {
			return null;
		}

		$data = @unserialize( $raw, array( 'allowed_classes' => false ) );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Store a parsed payload as gzipped serialized data.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $payload Parsed entity payload.
	 *
	 * @return void
	 */
	public function set_encoded_payload( array $payload ) {
		$serialized            = serialize( $payload );
		$this->parsed_payload  = gzcompress( $serialized, 5 );
		$this->payload_hash    = hash( 'sha256', $serialized );
	}
}
