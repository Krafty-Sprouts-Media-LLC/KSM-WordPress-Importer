<?php
/**
 * WXR entity parser — reads one entity from XML and returns normalized payload data.
 *
 * @package Better_WordPress_Importer
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses a single WXR entity into a normalized PHP array for queue storage.
 *
 * @since 1.1.0
 */
class Better_WXR_Parser {

	/**
	 * Importable top-level XML elements in document order.
	 *
	 * @since 1.1.0
	 */
	const ENTITY_ELEMENTS = array(
		'wp:author',
		'wp:category',
		'wp:tag',
		'wp:term',
		'item',
	);

	/**
	 * Parse one entity at the given manifest index.
	 *
	 * @since 1.1.0
	 *
	 * @param string $file_path    Absolute WXR path.
	 * @param int    $entity_index Zero-based manifest index.
	 *
	 * @return array<string, mixed>|WP_Error Normalized payload.
	 */
	public function parse_entity_at_index( $file_path, $entity_index ) {
		$reader = $this->open_reader( $file_path );
		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$current = 0;
		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}

			if ( ! in_array( $reader->name, self::ENTITY_ELEMENTS, true ) ) {
				continue;
			}

			if ( $current < $entity_index ) {
				++$current;
				$reader->next( $reader->name );
				continue;
			}

			$node = $reader->expand();
			if ( false === $node ) {
				$reader->close();
				return new WP_Error(
					'better_importer.parse.expand_failed',
					__( 'Could not read an entity from the export file.', 'better-wordpress-importer' )
				);
			}

			$parsed = $this->parse_entity_node( $reader->name, $node );
			$reader->close();

			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			return $parsed;
		}

		$reader->close();

		return new WP_Error(
			'better_importer.parse.entity_not_found',
			__( 'The requested entity could not be found in the export file.', 'better-wordpress-importer' )
		);
	}

	/**
	 * Parse a DOM node for the given WXR element name.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $element_name WXR element name.
	 * @param DOMNode   $node         Expanded DOM node.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	protected function parse_entity_node( $element_name, $node ) {
		switch ( $element_name ) {
			case 'wp:author':
				return $this->parse_author( $node );
			case 'wp:category':
				return $this->parse_term( $node, 'category' );
			case 'wp:tag':
				return $this->parse_term( $node, 'tag' );
			case 'wp:term':
				return $this->parse_term( $node, 'term' );
			case 'item':
				return $this->parse_post( $node );
		}

		return new WP_Error(
			'better_importer.parse.unsupported_entity',
			__( 'Unsupported entity type in export file.', 'better-wordpress-importer' )
		);
	}

	/**
	 * Parse a wp:author node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Author node.
	 *
	 * @return array<string, mixed>
	 */
	protected function parse_author( $node ) {
		$data = array();
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			switch ( $child->tagName ) {
				case 'wp:author_login':
					$data['user_login'] = $child->textContent;
					break;
				case 'wp:author_id':
					$data['ID'] = $child->textContent;
					break;
				case 'wp:author_email':
					$data['user_email'] = $child->textContent;
					break;
				case 'wp:author_display_name':
					$data['display_name'] = $child->textContent;
					break;
				case 'wp:author_first_name':
					$data['first_name'] = $child->textContent;
					break;
				case 'wp:author_last_name':
					$data['last_name'] = $child->textContent;
					break;
			}
		}

		return array(
			'entity_kind' => 'user',
			'data'        => $data,
			'meta'        => array(),
			'comments'    => array(),
			'terms'       => array(),
		);
	}

	/**
	 * Parse a taxonomy node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Term node.
	 * @param string  $kind category, tag, or term.
	 *
	 * @return array<string, mixed>
	 */
	protected function parse_term( $node, $kind ) {
		$tag_map = array(
			'category' => array(
				'id'          => 'wp:term_id',
				'slug'        => 'wp:category_nicename',
				'parent'      => 'wp:category_parent',
				'name'        => 'wp:cat_name',
				'description' => 'wp:category_description',
				'taxonomy'    => 'category',
			),
			'tag'      => array(
				'id'          => 'wp:term_id',
				'slug'        => 'wp:tag_slug',
				'parent'      => null,
				'name'        => 'wp:tag_name',
				'description' => 'wp:tag_description',
				'taxonomy'    => 'post_tag',
			),
			'term'     => array(
				'id'          => 'wp:term_id',
				'slug'        => 'wp:term_slug',
				'parent'      => 'wp:term_parent',
				'name'        => 'wp:term_name',
				'description' => 'wp:term_description',
				'taxonomy'    => null,
			),
		);

		$map  = $tag_map[ $kind ];
		$data = array( 'taxonomy' => $map['taxonomy'] );

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			if ( $map['id'] === $child->tagName ) {
				$data['id'] = $child->textContent;
			} elseif ( $map['slug'] === $child->tagName ) {
				$data['slug'] = $child->textContent;
			} elseif ( $map['parent'] && $map['parent'] === $child->tagName ) {
				$data['parent'] = $child->textContent;
			} elseif ( $map['name'] === $child->tagName ) {
				$data['name'] = $child->textContent;
			} elseif ( $map['description'] === $child->tagName ) {
				$data['description'] = $child->textContent;
			} elseif ( 'term' === $kind && 'wp:term_taxonomy' === $child->tagName ) {
				$data['taxonomy'] = $child->textContent;
			}
		}

		return array(
			'entity_kind' => 'term',
			'term_kind'   => $kind,
			'data'        => $data,
			'meta'        => array(),
			'comments'    => array(),
			'terms'       => array(),
		);
	}

	/**
	 * Parse an item node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Item node.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	protected function parse_post( $node ) {
		$data     = array();
		$meta     = array();
		$comments = array();
		$terms    = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			switch ( $child->tagName ) {
				case 'wp:post_type':
					$data['post_type'] = $child->textContent;
					break;
				case 'title':
					$data['post_title'] = $child->textContent;
					break;
				case 'guid':
					$data['guid'] = $child->textContent;
					break;
				case 'dc:creator':
					$data['post_author'] = $child->textContent;
					break;
				case 'content:encoded':
					$data['post_content'] = $child->textContent;
					break;
				case 'excerpt:encoded':
					$data['post_excerpt'] = $child->textContent;
					break;
				case 'wp:post_id':
					$data['post_id'] = $child->textContent;
					break;
				case 'wp:post_date':
					$data['post_date'] = $child->textContent;
					break;
				case 'wp:post_date_gmt':
					$data['post_date_gmt'] = $child->textContent;
					break;
				case 'wp:comment_status':
					$data['comment_status'] = $child->textContent;
					break;
				case 'wp:ping_status':
					$data['ping_status'] = $child->textContent;
					break;
				case 'wp:post_name':
					$data['post_name'] = $child->textContent;
					break;
				case 'wp:status':
					$data['post_status'] = $child->textContent;
					if ( 'auto-draft' === $data['post_status'] ) {
						return new WP_Error(
							'better_importer.parse.auto_draft',
							__( 'Cannot import auto-draft posts.', 'better-wordpress-importer' )
						);
					}
					break;
				case 'wp:post_parent':
					$data['post_parent'] = $child->textContent;
					break;
				case 'wp:menu_order':
					$data['menu_order'] = $child->textContent;
					break;
				case 'wp:post_password':
					$data['post_password'] = $child->textContent;
					break;
				case 'wp:is_sticky':
					$data['is_sticky'] = $child->textContent;
					break;
				case 'wp:attachment_url':
					$data['attachment_url'] = $child->textContent;
					break;
				case 'wp:postmeta':
					$meta_item = $this->parse_meta_node( $child );
					if ( ! empty( $meta_item ) ) {
						$meta[] = $meta_item;
					}
					break;
				case 'wp:comment':
					$comment_item = $this->parse_comment_node( $child );
					if ( ! empty( $comment_item ) ) {
						$comments[] = $comment_item;
					}
					break;
				case 'category':
					$term_item = $this->parse_category_node( $child );
					if ( ! empty( $term_item ) ) {
						$terms[] = $term_item;
					}
					break;
			}
		}

		return array(
			'entity_kind' => ( isset( $data['post_type'] ) && 'attachment' === $data['post_type'] ) ? 'attachment' : 'post',
			'data'        => $data,
			'meta'        => $meta,
			'comments'    => $comments,
			'terms'       => $terms,
		);
	}

	/**
	 * Parse a wp:postmeta or wp:commentmeta node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Meta node.
	 *
	 * @return array<string, string>|null
	 */
	protected function parse_meta_node( $node ) {
		$key   = '';
		$value = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			if ( 'wp:meta_key' === $child->tagName ) {
				$key = $child->textContent;
			} elseif ( 'wp:meta_value' === $child->tagName ) {
				$value = $child->textContent;
			}
		}

		if ( '' === $key ) {
			return null;
		}

		return array(
			'key'   => $key,
			'value' => $value,
		);
	}

	/**
	 * Parse a wp:comment node.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Comment node.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function parse_comment_node( $node ) {
		$data = array();
		$meta = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			switch ( $child->tagName ) {
				case 'wp:comment_id':
					$data['comment_id'] = $child->textContent;
					break;
				case 'wp:comment_author':
					$data['comment_author'] = $child->textContent;
					break;
				case 'wp:comment_author_email':
					$data['comment_author_email'] = $child->textContent;
					break;
				case 'wp:comment_author_url':
					$data['comment_author_url'] = $child->textContent;
					break;
				case 'wp:comment_author_IP':
					$data['comment_author_IP'] = $child->textContent;
					break;
				case 'wp:comment_date':
					$data['comment_date'] = $child->textContent;
					break;
				case 'wp:comment_date_gmt':
					$data['comment_date_gmt'] = $child->textContent;
					break;
				case 'wp:comment_content':
					$data['comment_content'] = $child->textContent;
					break;
				case 'wp:comment_approved':
					$data['comment_approved'] = $child->textContent;
					break;
				case 'wp:comment_type':
					$data['comment_type'] = $child->textContent;
					break;
				case 'wp:comment_parent':
					$data['comment_parent'] = $child->textContent;
					break;
				case 'wp:comment_user_id':
					$data['comment_user_id'] = $child->textContent;
					break;
				case 'wp:commentmeta':
					$meta_item = $this->parse_meta_node( $child );
					if ( ! empty( $meta_item ) ) {
						$meta[] = $meta_item;
					}
					break;
			}
		}

		if ( empty( $data ) ) {
			return null;
		}

		$data['meta'] = $meta;

		return $data;
	}

	/**
	 * Parse a category node attached to a post item.
	 *
	 * @since 1.1.0
	 *
	 * @param DOMNode $node Category node.
	 *
	 * @return array<string, string>|null
	 */
	protected function parse_category_node( $node ) {
		$term = array(
			'name'     => $node->getAttribute( 'nicename' ),
			'slug'     => $node->getAttribute( 'nicename' ),
			'taxonomy' => 'category',
		);

		if ( $node->hasAttribute( 'domain' ) ) {
			$term['taxonomy'] = $node->getAttribute( 'domain' );
		}

		$term['name'] = $node->textContent;

		return $term;
	}

	/**
	 * Open an XMLReader for the WXR file.
	 *
	 * @since 1.1.0
	 *
	 * @param string $file_path Absolute WXR path.
	 *
	 * @return XMLReader|WP_Error
	 */
	protected function open_reader( $file_path ) {
		libxml_use_internal_errors( true );
		libxml_clear_errors();

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
			libxml_use_internal_errors( false );
			return new WP_Error(
				'better_importer.parse.cannot_open',
				__( 'Could not open the export file for parsing.', 'better-wordpress-importer' )
			);
		}

		return $reader;
	}
}
