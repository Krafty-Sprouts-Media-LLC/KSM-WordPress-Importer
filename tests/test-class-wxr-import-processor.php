<?php
/**
 * Tests for batch import processing.
 *
 * @package WordPress_Importer_v2
 */

/**
 * Batch processor tests.
 */
class Test_WXR_Import_Processor extends WP_UnitTestCase {

	/**
	 * Fixture path.
	 *
	 * @var string
	 */
	protected $fixture;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->fixture = dirname( __DIR__ ) . '/fixtures/basic-export.xml';
		wxr_importer_install_tables();
	}

	/**
	 * import_batch processes entities without error.
	 */
	public function test_import_batch_processes_fixture() {
		$importer = new WXR_Importer( array( 'default_author' => get_current_user_id() ) );
		$manifest = $importer->build_import_manifest( $this->fixture );
		$this->assertIsArray( $manifest );
		$this->assertNotEmpty( $manifest );

		$result = $importer->import_batch( $this->fixture, 0, 3, $manifest );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'next_item_index', $result );
		$this->assertGreaterThan( 0, $result['processed'] );
	}

	/**
	 * Full job completes via batch processor.
	 */
	public function test_job_completes_via_processor() {
		$job = WXR_Import_Job::create(
			0,
			$this->fixture,
			array(
				'default_author' => get_current_user_id(),
				'batch_size'     => 2,
			)
		);
		$this->assertNotWPError( $job );
		$job->set_status( 'processing' );

		$processor = new WXR_Import_Processor();
		$guard     = 0;

		while ( ! $job->is_terminal() && $guard < 50 ) {
			$result = $processor->process_job( $job->id, 2 );
			$this->assertNotWPError( $result );
			$job = WXR_Import_Job::get( $job->id );
			$this->assertNotWPError( $job );
			$guard++;
		}

		$this->assertSame( 'complete', $job->status );
		$this->assertGreaterThan( 0, $job->processed_posts );
	}

	/**
	 * Resume after partial import does not duplicate posts.
	 */
	public function test_resume_after_partial_import() {
		$job = WXR_Import_Job::create(
			0,
			$this->fixture,
			array( 'default_author' => get_current_user_id() )
		);
		$this->assertNotWPError( $job );
		$job->set_status( 'processing' );

		$processor = new WXR_Import_Processor();
		$processor->process_job( $job->id, 2 );

		$partial = WXR_Import_Job::get( $job->id );
		$this->assertNotWPError( $partial );
		$this->assertGreaterThan( 0, $partial->xml_cursor_item );

		$posts_after_partial = $this->get_posts_count();

		while ( ! $partial->is_terminal() ) {
			$processor->process_job( $partial->id, 5 );
			$partial = WXR_Import_Job::get( $partial->id );
		}

		$this->assertSame( 'complete', $partial->status );
		$this->assertSame( 2, $this->get_posts_count() );
	}

	/**
	 * Count imported posts (post + page).
	 *
	 * @return int
	 */
	protected function get_posts_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status != 'auto-draft'"
		);
	}
}
