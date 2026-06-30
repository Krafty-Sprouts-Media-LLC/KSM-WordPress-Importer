<?php
/**
 * Plupload chunked WXR assembly for large browser uploads.
 *
 * @package Better_WordPress_Importer
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assembles multi-part Plupload uploads into a private XML attachment.
 *
 * @since 1.5.0
 */
class Better_Chunked_Upload {

	/**
	 * Chunk storage directory name under uploads.
	 *
	 * @since 1.5.0
	 */
	const CHUNK_DIR_NAME = 'better-importer-chunks';

	/**
	 * Default chunk size passed to Plupload.
	 *
	 * @since 1.5.0
	 */
	const DEFAULT_CHUNK_SIZE = '8mb';

	/**
	 * Whether the current request is a Plupload chunk upload.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public static function is_chunk_request() {
		return isset( $_REQUEST['chunks'], $_REQUEST['chunk'] ) && (int) $_REQUEST['chunks'] > 1;
	}

	/**
	 * Handle one chunk of a browser upload.
	 *
	 * @since 1.5.0
	 *
	 * @param string $filename Sanitized original filename.
	 *
	 * @return array<string, mixed>|WP_Error Partial progress or final attachment payload.
	 */
	public static function handle_chunk( $filename ) {
		$chunk  = isset( $_REQUEST['chunk'] ) ? (int) $_REQUEST['chunk'] : 0;
		$chunks = isset( $_REQUEST['chunks'] ) ? (int) $_REQUEST['chunks'] : 0;

		if ( $chunk < 0 || $chunks < 2 || $chunk >= $chunks ) {
			return new WP_Error(
				'better_importer.upload.chunk_invalid',
				__( 'Invalid upload chunk received.', 'better-wordpress-importer' )
			);
		}

		if ( 'xml' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error(
				'better_importer.upload.invalid_extension',
				__( 'Invalid file type. Please upload a valid WordPress XML export file.', 'better-wordpress-importer' )
			);
		}

		$upload_file = self::get_uploaded_file_array();
		if ( is_wp_error( $upload_file ) ) {
			return $upload_file;
		}

		$upload_session = isset( $_REQUEST['upload_session'] ) ? sanitize_key( wp_unslash( $_REQUEST['upload_session'] ) ) : '';
		if ( '' === $upload_session ) {
			$upload_session = md5( get_current_user_id() . '|' . $filename . '|' . $chunks );
		}

		$chunk_dir = self::get_session_directory( $upload_session );
		if ( is_wp_error( $chunk_dir ) ) {
			return $chunk_dir;
		}

		$chunk_file = trailingslashit( $chunk_dir ) . $chunk . '.part';
		if ( ! move_uploaded_file( $upload_file['tmp_name'], $chunk_file ) ) {
			return new WP_Error(
				'better_importer.upload.chunk_save_failed',
				__( 'Could not save upload chunk.', 'better-wordpress-importer' )
			);
		}

		if ( $chunk + 1 < $chunks || ! self::all_chunks_present( $chunk_dir, $chunks ) ) {
			return array(
				'partial'  => true,
				'message'  => __( 'Upload chunk received.', 'better-wordpress-importer' ),
				'filename' => $filename,
			);
		}

		return self::assemble_chunks( $chunk_dir, $chunks, $filename );
	}

	/**
	 * Absolute path to the chunk base directory.
	 *
	 * @since 1.5.0
	 *
	 * @return string|WP_Error
	 */
	public static function get_chunk_base_directory() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error(
				'better_importer.upload.dir_unavailable',
				$upload_dir['error']
			);
		}

		$chunk_base = trailingslashit( $upload_dir['basedir'] ) . self::CHUNK_DIR_NAME;
		if ( ! wp_mkdir_p( $chunk_base ) ) {
			return new WP_Error(
				'better_importer.upload.chunk_base_failed',
				__( 'Could not create a temporary upload directory.', 'better-wordpress-importer' )
			);
		}

		self::protect_directory( $chunk_base );

		return wp_normalize_path( $chunk_base );
	}

	/**
	 * Remove abandoned chunk session directories.
	 *
	 * @since 1.5.0
	 *
	 * @param int $max_age_seconds Delete sessions older than this many seconds.
	 *
	 * @return array<string, int>
	 */
	public static function cleanup_abandoned( $max_age_seconds = DAY_IN_SECONDS ) {
		$removed = array(
			'directories' => 0,
			'files'       => 0,
		);

		$chunk_base = self::get_chunk_base_directory();
		if ( is_wp_error( $chunk_base ) || ! is_dir( $chunk_base ) ) {
			return $removed;
		}

		$cutoff = time() - max( 3600, (int) $max_age_seconds );
		$items  = scandir( $chunk_base );
		if ( false === $items ) {
			return $removed;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || in_array( $item, array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
				continue;
			}

			$path = trailingslashit( $chunk_base ) . $item;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			if ( filemtime( $path ) > $cutoff ) {
				continue;
			}

			$count = self::delete_directory( $path );
			$removed['directories'] += $count['directories'];
			$removed['files']       += $count['files'];
		}

		return $removed;
	}

	/**
	 * Get the uploaded chunk file from the request.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	protected static function get_uploaded_file_array() {
		foreach ( array( 'async-upload', 'import' ) as $field ) {
			if ( empty( $_FILES[ $field ]['tmp_name'] ) || ! is_uploaded_file( $_FILES[ $field ]['tmp_name'] ) ) {
				continue;
			}

			return $_FILES[ $field ];
		}

		return new WP_Error(
			'better_importer.upload.chunk_missing',
			__( 'Upload chunk was missing from the request.', 'better-wordpress-importer' )
		);
	}

	/**
	 * Prepare a per-session chunk directory.
	 *
	 * @since 1.5.0
	 *
	 * @param string $upload_session Session key.
	 *
	 * @return string|WP_Error
	 */
	protected static function get_session_directory( $upload_session ) {
		$chunk_base = self::get_chunk_base_directory();
		if ( is_wp_error( $chunk_base ) ) {
			return $chunk_base;
		}

		$chunk_dir = trailingslashit( $chunk_base ) . sanitize_file_name( $upload_session );
		if ( ! wp_mkdir_p( $chunk_dir ) ) {
			return new WP_Error(
				'better_importer.upload.chunk_dir_failed',
				__( 'Could not create a temporary upload directory.', 'better-wordpress-importer' )
			);
		}

		return wp_normalize_path( $chunk_dir );
	}

	/**
	 * Whether every chunk part exists on disk.
	 *
	 * @since 1.5.0
	 *
	 * @param string $chunk_dir Session directory.
	 * @param int    $chunks    Total chunk count.
	 *
	 * @return bool
	 */
	protected static function all_chunks_present( $chunk_dir, $chunks ) {
		for ( $i = 0; $i < $chunks; $i++ ) {
			if ( ! file_exists( trailingslashit( $chunk_dir ) . $i . '.part' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Merge chunk parts into a private XML attachment.
	 *
	 * @since 1.5.0
	 *
	 * @param string $chunk_dir Session directory.
	 * @param int    $chunks    Total chunk count.
	 * @param string $filename  Original filename.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	protected static function assemble_chunks( $chunk_dir, $chunks, $filename ) {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error(
				'better_importer.upload.dir_unavailable',
				$upload_dir['error']
			);
		}

		$unique_filename = wp_unique_filename( $upload_dir['path'], $filename );
		$final_file      = trailingslashit( $upload_dir['path'] ) . $unique_filename;
		$out             = fopen( $final_file, 'wb' );

		if ( false === $out ) {
			return new WP_Error(
				'better_importer.upload.assemble_open_failed',
				__( 'Could not assemble uploaded file.', 'better-wordpress-importer' )
			);
		}

		for ( $i = 0; $i < $chunks; $i++ ) {
			$part = trailingslashit( $chunk_dir ) . $i . '.part';
			$in   = fopen( $part, 'rb' );

			if ( false === $in ) {
				fclose( $out );
				@unlink( $final_file );
				return new WP_Error(
					'better_importer.upload.chunk_read_failed',
					__( 'Could not read upload chunk.', 'better-wordpress-importer' )
				);
			}

			stream_copy_to_stream( $in, $out );
			fclose( $in );
		}

		fclose( $out );
		self::delete_directory( $chunk_dir );

		$format = Better_Format_Detector::validate_for_import( $final_file, $filename );
		if ( is_wp_error( $format ) ) {
			@unlink( $final_file );
			return $format;
		}

		$attachment_id = self::register_attachment( $final_file, $unique_filename, $upload_dir );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $final_file );
			return $attachment_id;
		}

		return array(
			'attachment_id' => $attachment_id,
			'filename'        => $unique_filename,
			'format'          => $format,
		);
	}

	/**
	 * Register an assembled XML file as a private attachment.
	 *
	 * @since 1.5.0
	 *
	 * @param string               $final_file      Absolute assembled file path.
	 * @param string               $unique_filename Stored filename.
	 * @param array<string, mixed> $upload_dir      Upload directory data.
	 *
	 * @return int|WP_Error
	 */
	protected static function register_attachment( $final_file, $unique_filename, array $upload_dir ) {
		$attachment = array(
			'post_title'     => wp_basename( $unique_filename ),
			'post_content'   => trailingslashit( $upload_dir['url'] ) . $unique_filename,
			'post_mime_type' => 'application/xml',
			'guid'           => trailingslashit( $upload_dir['url'] ) . $unique_filename,
			'context'        => 'import',
			'post_status'    => 'private',
		);

		$attachment_id = wp_insert_attachment( $attachment, $final_file );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return (int) $attachment_id;
	}

	/**
	 * Add basic web protection files to a chunk directory.
	 *
	 * @since 1.5.0
	 *
	 * @param string $directory Directory path.
	 *
	 * @return void
	 */
	protected static function protect_directory( $directory ) {
		$index = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$webconfig = trailingslashit( $directory ) . 'web.config';
		if ( ! file_exists( $webconfig ) ) {
			file_put_contents(
				$webconfig,
				'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'
			);
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @since 1.5.0
	 *
	 * @param string $directory Directory path.
	 *
	 * @return array<string, int>
	 */
	protected static function delete_directory( $directory ) {
		$counts = array(
			'directories' => 0,
			'files'       => 0,
		);

		if ( ! is_dir( $directory ) ) {
			return $counts;
		}

		$items = scandir( $directory );
		if ( false === $items ) {
			return $counts;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$nested = self::delete_directory( $path );
				$counts['directories'] += $nested['directories'];
				$counts['files']       += $nested['files'];
				if ( rmdir( $path ) ) {
					$counts['directories']++;
				}
			} elseif ( is_file( $path ) && unlink( $path ) ) {
				$counts['files']++;
			}
		}

		if ( rmdir( $directory ) ) {
			$counts['directories']++;
		}

		return $counts;
	}
}
