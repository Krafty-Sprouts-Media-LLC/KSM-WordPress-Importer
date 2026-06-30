<?php
/**
 * Tests for WXR_Import_Job model and repository.
 *
 * @package WordPress_Importer_v2
 */

/**
 * Job model tests.
 */
class Test_WXR_Import_Job extends WP_UnitTestCase {

	/**
	 * Fixture path.
	 *
	 * @var string
	 */
	protected $fixture;

	/**
	 * Set up test fixture path and tables.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->fixture = dirname( __DIR__ ) . '/fixtures/basic-export.xml';
		wxr_importer_install_tables();
	}

	/**
	 * Job tables should be created on install.
	 */
	public function test_tables_exist_after_install() {
		global $wpdb;
		$table = $wpdb->prefix . 'wxr_import_jobs';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( $table, $found );
	}

	/**
	 * Job can be created from a valid WXR file.
	 */
	public function test_job_create_from_fixture() {
		$job = WXR_Import_Job::create( 0, $this->fixture, array() );
		$this->assertNotWPError( $job );
		$this->assertGreaterThan( 0, $job->id );
		$this->assertSame( 1, $job->total_users );
		$this->assertGreaterThan( 0, $job->total_posts );
		$this->assertNotEmpty( $job->item_manifest );
	}

	/**
	 * Job persists and reloads from the database.
	 */
	public function test_job_persistence() {
		$job = WXR_Import_Job::create( 0, $this->fixture, array( 'batch_size' => 5 ) );
		$this->assertNotWPError( $job );

		$loaded = WXR_Import_Job::get( $job->id );
		$this->assertNotWPError( $loaded );
		$this->assertSame( $job->total_posts, $loaded->total_posts );
		$this->assertSame( 5, $loaded->options['batch_size'] );
	}

	/**
	 * Percent complete reflects manifest cursor.
	 */
	public function test_percent_complete() {
		$job = WXR_Import_Job::create( 0, $this->fixture, array() );
		$this->assertNotWPError( $job );

		$total = count( $job->item_manifest );
		$job->xml_cursor_item = (int) floor( $total / 2 );
		$job->status          = 'processing';

		$this->assertGreaterThan( 0, $job->percent_complete() );
		$this->assertLessThan( 100, $job->percent_complete() );
	}

	/**
	 * Final report includes imported counts.
	 */
	public function test_get_final_report() {
		$job = WXR_Import_Job::create( 0, $this->fixture, array() );
		$this->assertNotWPError( $job );

		$job->processed_posts = 2;
		$job->skipped_items   = 1;
		$job->status          = 'complete';

		$report = $job->get_final_report();
		$this->assertSame( 2, $report['imported']['posts'] );
		$this->assertSame( 1, $report['skipped'] );
	}
}
