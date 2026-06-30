<?php
/**
 * Import file format detection (WXR XML vs Better Package).
 *
 * @package Better_WordPress_Importer
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects whether an upload is WXR XML or a Better Package archive.
 *
 * @since 1.5.0
 */
class Better_Format_Detector {

	const FORMAT_WXR            = 'wxr';
	const FORMAT_BETTER_PACKAGE = 'better_package';
	const FORMAT_UNKNOWN        = 'unknown';

	/**
	 * ZIP local file header magic bytes.
	 *
	 * @since 1.5.0
	 */
	const ZIP_MAGIC = "PK\x03\x04";

	/**
	 * Detect format from a filesystem path.
	 *
	 * @since 1.5.0
	 *
	 * @param string $file_path Absolute readable file path.
	 *
	 * @return string One of the FORMAT_* constants.
	 */
	public static function detect_file( $file_path ) {
		$file_path = wp_normalize_path( $file_path );

		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return self::FORMAT_UNKNOWN;
		}

		$head = file_get_contents( $file_path, false, null, 0, 512 );
		if ( false === $head || '' === $head ) {
			return self::FORMAT_UNKNOWN;
		}

		return self::detect_from_header( $head, $file_path );
	}

	/**
	 * Detect format using filename hint plus file header bytes.
	 *
	 * @since 1.5.0
	 *
	 * @param string $filename  Original filename.
	 * @param string $file_path Absolute readable file path.
	 *
	 * @return string One of the FORMAT_* constants.
	 */
	public static function detect_upload( $filename, $file_path ) {
		$detected = self::detect_file( $file_path );
		if ( self::FORMAT_UNKNOWN !== $detected ) {
			return $detected;
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, array( 'bwxr', 'zip' ), true ) ) {
			return self::FORMAT_BETTER_PACKAGE;
		}

		if ( 'xml' === $extension ) {
			return self::FORMAT_WXR;
		}

		return self::FORMAT_UNKNOWN;
	}

	/**
	 * Whether the MVP importer can process this format today.
	 *
	 * @since 1.5.0
	 *
	 * @param string $format Detected format constant.
	 *
	 * @return bool
	 */
	public static function is_importable( $format ) {
		return self::FORMAT_WXR === $format;
	}

	/**
	 * User-facing message for unsupported formats.
	 *
	 * @since 1.5.0
	 *
	 * @param string $format Detected format constant.
	 *
	 * @return string
	 */
	public static function unsupported_message( $format ) {
		if ( self::FORMAT_BETTER_PACKAGE === $format ) {
			return __( 'Better Package (.bwxr) import is planned for a future release. Please upload a WordPress XML export (.xml) file.', 'better-wordpress-importer' );
		}

		return __( 'Unrecognized import file format. Please upload a valid WordPress XML export (.xml) file.', 'better-wordpress-importer' );
	}

	/**
	 * Validate a file for import and return its format or an error.
	 *
	 * @since 1.5.0
	 *
	 * @param string $file_path Absolute readable file path.
	 * @param string $filename  Optional original filename.
	 *
	 * @return string|WP_Error Format constant on success.
	 */
	public static function validate_for_import( $file_path, $filename = '' ) {
		if ( '' === $filename ) {
			$filename = wp_basename( $file_path );
		}

		$format = self::detect_upload( $filename, $file_path );

		if ( self::FORMAT_UNKNOWN === $format ) {
			return new WP_Error(
				'better_importer.format.unknown',
				self::unsupported_message( $format )
			);
		}

		if ( ! self::is_importable( $format ) ) {
			return new WP_Error(
				'better_importer.format.unsupported',
				self::unsupported_message( $format )
			);
		}

		return $format;
	}

	/**
	 * Inspect leading bytes to determine format.
	 *
	 * @since 1.5.0
	 *
	 * @param string $head      First bytes of the file.
	 * @param string $file_path File path for extension fallback.
	 *
	 * @return string
	 */
	protected static function detect_from_header( $head, $file_path ) {
		if ( 0 === strpos( $head, self::ZIP_MAGIC ) ) {
			return self::FORMAT_BETTER_PACKAGE;
		}

		if ( preg_match( '/<\?xml/i', $head ) || preg_match( '/<rss|<wxr/i', $head ) ) {
			return self::FORMAT_WXR;
		}

		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( 'xml' === $extension ) {
			return self::FORMAT_WXR;
		}

		if ( in_array( $extension, array( 'bwxr', 'zip' ), true ) ) {
			return self::FORMAT_BETTER_PACKAGE;
		}

		return self::FORMAT_UNKNOWN;
	}
}
