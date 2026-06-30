<?php
/**
 * Filename: class-wxr-import-info.php
 * Author: wordpressdotorg, rmccue
 * Created: 2015-01-01
 * Version: 2.0.1
 * Last Modified: 2025-12-30
 * Description: Data container for WordPress XML import information
 */

class WXR_Import_Info {
	public $home;
	public $siteurl;

	public $title;

	public $users = array();
	public $post_count = 0;
	public $media_count = 0;
	public $comment_count = 0;
	public $term_count = 0;

	public $generator = '';
	public $version;

	/**
	 * Byte offsets for each <item> element (when supported by XMLReader).
	 *
	 * @since 3.0.0
	 * @var array<int, int>
	 */
	public $item_positions = array();
}
