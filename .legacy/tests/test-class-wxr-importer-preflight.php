<?php
/**
 * Tests for WXR_Importer preflight and manifest.
 *
 * @package WordPress_Importer_v2
 */

/**
 * Importer preflight tests.
 */
class Test_WXR_Importer_Preflight extends WP_UnitTestCase {

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
	}

	/**
	 * get_preliminary_information returns counts and item_positions.
	 */
	public function test_get_preliminary_information_item_positions() {
		$importer = new WXR_Importer();
		$info     = $importer->get_preliminary_information( $this->fixture );

		$this->assertNotWPError( $info );
		$this->assertInstanceOf( 'WXR_Import_Info', $info );
		$this->assertIsArray( $info->item_positions );
		$this->assertCount( 2, $info->item_positions );
		$this->assertCount( 1, $info->users );
		$this->assertGreaterThan( 0, $info->post_count );
	}

	/**
	 * build_import_manifest lists entities in document order.
	 */
	public function test_build_import_manifest() {
		$importer = new WXR_Importer();
		$manifest = $importer->build_import_manifest( $this->fixture );

		$this->assertIsArray( $manifest );
		$this->assertGreaterThanOrEqual( 4, count( $manifest ) );
		$this->assertSame( 'author', $manifest[0]['type'] );
	}
}
