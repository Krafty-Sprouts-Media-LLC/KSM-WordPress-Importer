<?php
/**
 * WXR preflight scanner — builds a compact entity manifest without byte offsets.
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Streaming preflight scan for WXR import jobs.
 *
 * @since 1.0.0
 */
class Better_Preflight {

	/**
	 * Highest WXR version this importer supports.
	 *
	 * @since 1.0.0
	 */
	const MAX_WXR_VERSION = '1.2';

	/**
	 * Scan a WXR file and return manifest plus summary metadata.
	 *
	 * Manifest entries use compact keys: i (index), t (type), id, title.
	 * No byte offsets or raw XML are stored.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Absolute path to the WXR file.
	 *
	 * @return array<string, mixed>|WP_Error {
	 *     @type array<int, array<string, string|int>> $manifest
	 *     @type array<string, mixed>                   $preflight
	 * }
	 */
	public function scan( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'better_importer.preflight.unreadable',
				__( 'The export file could not be read.', 'better-wordpress-importer' )
			);
		}

		$format = Better_Format_Detector::validate_for_import( $file_path );
		if ( is_wp_error( $format ) ) {
			return $format;
		}

		libxml_use_internal_errors( true );
		libxml_clear_errors();

		$reader = $this->open_reader( $file_path );
		if ( is_wp_error( $reader ) ) {
			libxml_use_internal_errors( false );
			return $reader;
		}

		wp_raise_memory_limit( 'admin' );

		$manifest   = array();
		$index      = 0;
		$wxr_version = '1.0';
		$preflight  = array(
			'title'       => '',
			'generator'   => '',
			'home'        => '',
			'siteurl'     => '',
			'wxr_version' => $wxr_version,
			'authors'     => array(),
			'counts'      => array(
				'users'       => 0,
				'terms'       => 0,
				'posts'       => 0,
				'attachments' => 0,
				'comments'    => 0,
			),
		);

		$in_item      = false;
		$item_id      = '';
		$item_title   = '';
		$item_type    = '';

		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT === $reader->nodeType ) {
				if ( $in_item ) {
					switch ( $reader->name ) {
						case 'title':
							$item_title = $reader->readString();
							break;
						case 'wp:post_id':
							$item_id = $reader->readString();
							break;
						case 'wp:post_type':
							$item_type = $reader->readString();
							break;
						case 'wp:comment':
							++$preflight['counts']['comments'];
							break;
					}
					continue;
				}

				switch ( $reader->name ) {
					case 'wp:wxr_version':
						$wxr_version = $reader->readString();
						$preflight['wxr_version'] = $wxr_version;
						break;

					case 'generator':
						$preflight['generator'] = $reader->readString();
						break;

					case 'title':
						$preflight['title'] = $reader->readString();
						break;

					case 'wp:base_site_url':
						$preflight['siteurl'] = $reader->readString();
						break;

					case 'wp:base_blog_url':
						$preflight['home'] = $reader->readString();
						break;

					case 'wp:author':
						$author = $this->read_author_summary( $reader );
						if ( ! empty( $author ) ) {
							$preflight['authors'][] = $author;
							$manifest[] = array(
								'i'     => $index,
								't'     => 'user',
								'id'    => (string) $author['id'],
								'title' => (string) $author['title'],
							);
							++$index;
							++$preflight['counts']['users'];
						}
						break;

					case 'wp:category':
						$term = $this->read_term_summary( $reader, 'category' );
						if ( ! empty( $term ) ) {
							$manifest[] = array(
								'i'     => $index,
								't'     => 'term',
								'id'    => (string) $term['id'],
								'title' => (string) $term['title'],
							);
							++$index;
							++$preflight['counts']['terms'];
						}
						break;

					case 'wp:tag':
						$term = $this->read_term_summary( $reader, 'tag' );
						if ( ! empty( $term ) ) {
							$manifest[] = array(
								'i'     => $index,
								't'     => 'term',
								'id'    => (string) $term['id'],
								'title' => (string) $term['title'],
							);
							++$index;
							++$preflight['counts']['terms'];
						}
						break;

					case 'wp:term':
						$term = $this->read_term_summary( $reader, 'term' );
						if ( ! empty( $term ) ) {
							$manifest[] = array(
								'i'     => $index,
								't'     => 'term',
								'id'    => (string) $term['id'],
								'title' => (string) $term['title'],
							);
							++$index;
							++$preflight['counts']['terms'];
						}
						break;

					case 'item':
						$in_item    = true;
						$item_id    = '';
						$item_title = '';
						$item_type  = '';
						break;
				}
			} elseif ( XMLReader::END_ELEMENT === $reader->nodeType && 'item' === $reader->name && $in_item ) {
				$entity_type = ( 'attachment' === $item_type ) ? 'attachment' : 'post';

				$manifest[] = array(
					'i'     => $index,
					't'     => $entity_type,
					'id'    => $item_id,
					'title' => $item_title,
				);
				++$index;

				if ( 'attachment' === $entity_type ) {
					++$preflight['counts']['attachments'];
				} else {
					++$preflight['counts']['posts'];
				}

				$in_item    = false;
				$item_id    = '';
				$item_title = '';
				$item_type  = '';
			}
		}

		$reader->close();
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		if ( version_compare( $wxr_version, self::MAX_WXR_VERSION, '>' ) ) {
			return new WP_Error(
				'better_importer.preflight.unsupported_version',
				sprintf(
					/* translators: 1: WXR version, 2: supported version */
					__( 'This WXR file (version %1$s) is newer than the importer supports (version %2$s).', 'better-wordpress-importer' ),
					$wxr_version,
					self::MAX_WXR_VERSION
				)
			);
		}

		$preflight['manifest_entity_total'] = count( $manifest );

		return array(
			'manifest'  => $manifest,
			'preflight' => $preflight,
		);
	}

	/**
	 * Open an XMLReader for the given WXR file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Absolute path to the WXR file.
	 *
	 * @return XMLReader|WP_Error
	 */
	protected function open_reader( $file_path ) {
		$old_value = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			$old_value = libxml_disable_entity_loader( true );
		}

		$reader = new XMLReader();
		$status = $reader->open( $file_path );

		if ( ! is_null( $old_value ) && PHP_VERSION_ID < 80000 ) {
			libxml_disable_entity_loader( $old_value );
		}

		if ( ! $status ) {
			return new WP_Error(
				'better_importer.preflight.cannot_parse',
				__( 'Could not open the export file for parsing.', 'better-wordpress-importer' )
			);
		}

		return $reader;
	}

	/**
	 * Read author id and login from the current wp:author element.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader $reader Active reader positioned on wp:author.
	 *
	 * @return array<string, string>
	 */
	protected function read_author_summary( XMLReader $reader ) {
		$depth = $reader->depth;
		$id    = '';
		$title = '';
		$email = '';
		$name  = '';

		while ( $reader->read() ) {
			if ( XMLReader::END_ELEMENT === $reader->nodeType && 'wp:author' === $reader->name && $reader->depth === $depth ) {
				break;
			}

			if ( XMLReader::ELEMENT !== $reader->nodeType || $reader->depth !== $depth + 1 ) {
				continue;
			}

			switch ( $reader->name ) {
				case 'wp:author_id':
					$id = $reader->readString();
					break;
				case 'wp:author_login':
					$title = $reader->readString();
					break;
				case 'wp:author_email':
					$email = $reader->readString();
					break;
				case 'wp:author_display_name':
					$name = $reader->readString();
					break;
			}
		}

		if ( '' === $id && '' === $title ) {
			return array();
		}

		return array(
			'id'           => $id,
			'title'        => $title,
			'login'        => $title,
			'email'        => $email,
			'display_name' => $name,
		);
	}

	/**
	 * Read term id and name from the current term element.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader $reader Active reader positioned on the term element.
	 * @param string    $kind   Term element kind: category, tag, or term.
	 *
	 * @return array<string, string>
	 */
	protected function read_term_summary( XMLReader $reader, $kind ) {
		$depth   = $reader->depth;
		$id      = '';
		$title   = '';
		$id_tags = array(
			'category' => 'wp:term_id',
			'tag'      => 'wp:term_id',
			'term'     => 'wp:term_id',
		);
		$name_tags = array(
			'category' => 'wp:cat_name',
			'tag'      => 'wp:tag_name',
			'term'     => 'wp:term_name',
		);

		$id_tag   = isset( $id_tags[ $kind ] ) ? $id_tags[ $kind ] : 'wp:term_id';
		$name_tag = isset( $name_tags[ $kind ] ) ? $name_tags[ $kind ] : 'wp:term_name';

		while ( $reader->read() ) {
			if ( XMLReader::END_ELEMENT === $reader->nodeType && $reader->depth === $depth ) {
				break;
			}

			if ( XMLReader::ELEMENT !== $reader->nodeType || $reader->depth !== $depth + 1 ) {
				continue;
			}

			if ( $id_tag === $reader->name ) {
				$id = $reader->readString();
			} elseif ( $name_tag === $reader->name ) {
				$title = $reader->readString();
			}
		}

		if ( '' === $id && '' === $title ) {
			return array();
		}

		return array(
			'id'    => $id,
			'title' => $title,
		);
	}
}
