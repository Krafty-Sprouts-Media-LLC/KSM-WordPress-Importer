<?php
/**
 * Tests for per-entity import item tracking.
 *
 * @package WordPress_Importer_v2
 */

/**
 * Import item repository tests.
 */
class Test_WXR_Import_Item extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		wxr_importer_install_tables();
	}

	/**
	 * record_batch inserts rows for a job.
	 */
	public function test_record_batch_inserts_items() {
		$fixture = dirname( __DIR__ ) . '/fixtures/basic-export.xml';
		$job     = WXR_Import_Job::create(
			0,
			$fixture,
			array( 'default_author' => get_current_user_id(), 'batch_size' => 5 )
		);
		$this->assertNotWPError( $job );

		$repository = new WXR_Import_Item_Repository();
		$repository->record_batch(
			$job->id,
			array(
				array(
					'entity_type' => 'posts',
					'old_id'      => '1',
					'new_id'      => 42,
					'title'       => 'Test Post',
					'status'      => 'imported',
				),
				array(
					'entity_type'   => 'terms',
					'old_id'        => '2',
					'title'         => 'Category',
					'status'        => 'failed',
					'error_message' => 'Example failure',
				),
			)
		);

		$this->assertSame( 2, $repository->count_by_status( $job->id ) );
		$this->assertSame( 1, $repository->count_by_status( $job->id, 'failed' ) );

		$failures = $repository->get_recent_failures( $job->id );
		$this->assertCount( 1, $failures );
		$this->assertSame( 'failed', $failures[0]->status );
	}
}
