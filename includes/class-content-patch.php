<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_Content_Patch {

	public static function register() {
		self::register_list_content_blocks();
		self::register_patch_content_block();
		self::register_patch_post_content();
	}

	private static function content_hash( $content ) {
		return hash( 'sha256', (string) $content );
	}

	private static function block_hash( $block ) {
		return self::content_hash( serialize_block( $block ) );
	}

	private static function is_empty_freeform_block( $block ) {
		return null === ( $block['blockName'] ?? null )
			&& '' === trim( $block['innerHTML'] ?? '' )
			&& empty( $block['innerBlocks'] );
	}

	private static function filter_empty_freeform_blocks( $blocks ) {
		$filtered = [];

		foreach ( $blocks as $block ) {
			if ( self::is_empty_freeform_block( $block ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::filter_empty_freeform_blocks( $block['innerBlocks'] );
			}

			$filtered[] = $block;
		}

		return $filtered;
	}

	private static function parse_editable_blocks( $content ) {
		return self::filter_empty_freeform_blocks( parse_blocks( $content ) );
	}

	private static function block_text( $block ) {
		$html = $block['innerHTML'] ?? '';
		if ( '' === $html && ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$html = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
		}

		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) );
	}

	private static function heading_text( $block ) {
		if ( 'core/heading' !== ( $block['blockName'] ?? null ) ) {
			return '';
		}

		return self::block_text( $block );
	}

	private static function heading_level( $block ) {
		if ( isset( $block['attrs']['level'] ) ) {
			return max( 1, min( 6, absint( $block['attrs']['level'] ) ) );
		}

		if ( ! empty( $block['innerHTML'] ) && preg_match( '/<h([1-6])\b/i', $block['innerHTML'], $matches ) ) {
			return absint( $matches[1] );
		}

		return 2;
	}

	private static function normalize_block( $block, $path ) {
		return Webmastery_MCP_Untrusted::mark( [
			'path'              => $path,
			'block_name'        => $block['blockName'] ?? null,
			'text'              => self::block_text( $block ),
			'html'              => $block['innerHTML'] ?? '',
			'attrs'             => $block['attrs'] ?? [],
			'inner_block_count' => count( $block['innerBlocks'] ?? [] ),
			'hash'              => self::block_hash( $block ),
		], [ 'block_name', 'text', 'html', 'attrs' ] );
	}

	private static function flatten_blocks( $blocks, $prefix = '' ) {
		$flat = [];

		foreach ( $blocks as $index => $block ) {
			$path   = '' === $prefix ? (string) $index : "{$prefix}.{$index}";
			$flat[] = self::normalize_block( $block, $path );

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, self::flatten_blocks( $block['innerBlocks'], $path ) );
			}
		}

		return $flat;
	}

	private static function parse_block_path( $path ) {
		$path = trim( (string) $path );

		if ( '' === $path || ! preg_match( '/^\d+(?:\.\d+)*$/', $path ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_block_path', 'block_path must be a dotted numeric path like 0 or 2.1.' );
		}

		return array_map( 'absint', explode( '.', $path ) );
	}

	private static function get_block_by_segments( $blocks, $segments ) {
		$current_blocks = $blocks;
		$current_block  = null;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $current_blocks ) || ! array_key_exists( $segment, $current_blocks ) ) {
				return Webmastery_MCP_Response::local_error( 'target_not_found', 'Block path not found.' );
			}

			$current_block  = $current_blocks[ $segment ];
			$current_blocks = $current_block['innerBlocks'] ?? [];
		}

		return $current_block;
	}

	private static function replace_block_by_segments( &$blocks, $segments, $replacement_block ) {
		$segment = array_shift( $segments );

		if ( ! array_key_exists( $segment, $blocks ) ) {
			return Webmastery_MCP_Response::local_error( 'target_not_found', 'Block path not found.' );
		}

		if ( empty( $segments ) ) {
			$blocks[ $segment ] = $replacement_block;
			return true;
		}

		if ( empty( $blocks[ $segment ]['innerBlocks'] ) || ! is_array( $blocks[ $segment ]['innerBlocks'] ) ) {
			return Webmastery_MCP_Response::local_error( 'target_not_found', 'Block path not found.' );
		}

		return self::replace_block_by_segments( $blocks[ $segment ]['innerBlocks'], $segments, $replacement_block );
	}

	private static function find_block_paths_by_hash( $blocks, $hash, $prefix = '' ) {
		$matches = [];

		foreach ( $blocks as $index => $block ) {
			$path = '' === $prefix ? (string) $index : "{$prefix}.{$index}";

			if ( self::block_hash( $block ) === $hash ) {
				$matches[] = $path;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$matches = array_merge( $matches, self::find_block_paths_by_hash( $block['innerBlocks'], $hash, $path ) );
			}
		}

		return $matches;
	}

	private static function register_list_content_blocks() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-content-blocks', [
			'label'               => 'List Content Blocks',
			'description'         => 'List Gutenberg block paths and hashes for a post or page.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'content_id'   => [
						'type'        => 'integer',
						'description' => 'Post or page ID to inspect',
					],
					'content_type' => [
						'type'        => 'string',
						'enum'        => [ 'post', 'page' ],
						'description' => 'Content type to inspect.',
					],
				],
				'required'   => [ 'content_id', 'content_type' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = Webmastery_MCP_Post_Access::get_content_target( $input['content_id'], $input['content_type'] );

				if ( is_wp_error( $post ) ) {
					return Webmastery_MCP_Response::from_wp_error( $post );
				}

				$blocks = self::parse_editable_blocks( $post->post_content );

				return [
					'success' => true,
					'data'    => [
						'id'           => $post->ID,
						'type'         => $post->post_type,
						'content_hash' => self::content_hash( $post->post_content ),
						'blocks'       => self::flatten_blocks( $blocks ),
					],
				];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::content_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_patch_content_block() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/patch-content-block', [
			'label'               => 'Patch Content Block',
			'description'         => 'Replace one exact Gutenberg block in a post or page by path or unique hash.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'content_id'            => [
						'type'        => 'integer',
						'description' => 'Post or page ID to patch',
					],
					'content_type'          => [
						'type'        => 'string',
						'enum'        => [ 'post', 'page' ],
						'description' => 'Content type to patch.',
					],
					'target_type'           => [
						'type'        => 'string',
						'enum'        => [ 'block_path', 'block_hash' ],
						'description' => 'Use block_path from list-content-blocks, or a unique block_hash.',
					],
					'block_path'            => [
						'type'        => 'string',
						'description' => 'Dotted block path, such as 0 or 2.1.',
					],
					'block_hash'            => [
						'type'        => 'string',
						'description' => 'Current block hash from list-content-blocks.',
					],
					'replacement_content'   => [
						'type'        => 'string',
						'description' => 'Replacement content that parses to exactly one block.',
					],
					'expected_block_hash'   => [
						'type'        => 'string',
						'description' => 'Optional expected hash for the block at the target path.',
					],
					'expected_content_hash' => [
						'type'        => 'string',
						'description' => 'Optional sha256 hash of the current post_content.',
					],
				],
				'required'   => [ 'content_id', 'content_type', 'target_type', 'replacement_content' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = Webmastery_MCP_Post_Access::get_content_target( $input['content_id'], $input['content_type'] );

				if ( is_wp_error( $post ) ) {
					return Webmastery_MCP_Response::from_wp_error( $post );
				}

				$current_content = $post->post_content;
				$before_hash     = self::content_hash( $current_content );
				$expected_hash   = sanitize_text_field( $input['expected_content_hash'] ?? '' );

				if ( '' !== $expected_hash && ! hash_equals( $before_hash, $expected_hash ) ) {
					return Webmastery_MCP_Response::legacy_error( 'content_hash_mismatch', 'Content hash did not match; reload before patching.' );
				}

				$blocks      = self::parse_editable_blocks( $current_content );
				$target_type = sanitize_key( $input['target_type'] );

				if ( 'block_path' === $target_type ) {
					$segments = self::parse_block_path( $input['block_path'] ?? '' );
				} elseif ( 'block_hash' === $target_type ) {
					$hash = sanitize_text_field( $input['block_hash'] ?? '' );
					if ( '' === $hash ) {
						return Webmastery_MCP_Response::legacy_error( 'missing_target', 'block_hash is required when target_type is block_hash.' );
					}

					$matches = self::find_block_paths_by_hash( $blocks, $hash );
					if ( 0 === count( $matches ) ) {
						return Webmastery_MCP_Response::legacy_error( 'target_not_found', 'Block hash target not found.' );
					}
					if ( count( $matches ) > 1 ) {
						return Webmastery_MCP_Response::legacy_error( 'ambiguous_target', 'Block hash target matched more than once.' );
					}

					$segments = self::parse_block_path( $matches[0] );
				} else {
					return Webmastery_MCP_Response::legacy_error( 'invalid_target_type', 'target_type must be block_path or block_hash.' );
				}

				if ( is_wp_error( $segments ) ) {
					return Webmastery_MCP_Response::from_wp_error( $segments );
				}

				$target_block = self::get_block_by_segments( $blocks, $segments );
				if ( is_wp_error( $target_block ) ) {
					return Webmastery_MCP_Response::from_wp_error( $target_block );
				}

				$before_block_hash = self::block_hash( $target_block );
				$expected_block    = sanitize_text_field( $input['expected_block_hash'] ?? '' );
				if ( '' !== $expected_block && ! hash_equals( $before_block_hash, $expected_block ) ) {
					return Webmastery_MCP_Response::legacy_error( 'block_hash_mismatch', 'Block hash did not match; reload before patching.' );
				}

				$replacement_blocks = self::parse_editable_blocks( wp_kses_post( $input['replacement_content'] ) );
				if ( 1 !== count( $replacement_blocks ) ) {
					return Webmastery_MCP_Response::legacy_error( 'invalid_replacement', 'replacement_content must parse to exactly one block.' );
				}

				$replacement_result = self::replace_block_by_segments( $blocks, $segments, $replacement_blocks[0] );
				if ( is_wp_error( $replacement_result ) ) {
					return Webmastery_MCP_Response::from_wp_error( $replacement_result );
				}

				$result = Webmastery_MCP_Post_Writes::update( [
					'ID'           => $post->ID,
					'post_content' => serialize_blocks( $blocks ),
				] );

				if ( is_wp_error( $result ) ) {
					return Webmastery_MCP_Response::legacy_error( 'update_failed', 'Failed to update content.' );
				}

				$updated_post = get_post( $post->ID );
				$path         = implode( '.', $segments );

				return [
					'success' => true,
					'data'    => Webmastery_MCP_Untrusted::mark( [
						'id'                  => $post->ID,
						'type'                => $post->post_type,
						'target'              => [
							'type'       => $target_type,
							'block_path' => $path,
						],
						'block_hash_before'   => $before_block_hash,
						'block_hash_after'    => self::block_hash( $replacement_blocks[0] ),
						'content_hash_before' => $before_hash,
						'content_hash_after'  => self::content_hash( $updated_post->post_content ),
						'content'             => $updated_post->post_content,
					], [ 'content' ] ),
				];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::content_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function patch_content_by_heading( $content, $heading_text, $replacement_content ) {
		$blocks  = self::parse_editable_blocks( $content );
		$matches = [];
		$count   = count( $blocks );

		foreach ( $blocks as $index => $block ) {
			if ( self::heading_text( $block ) === $heading_text ) {
				$matches[] = $index;
			}
		}

		if ( 0 === count( $matches ) ) {
			return Webmastery_MCP_Response::local_error( 'target_not_found', 'Heading target not found.' );
		}
		if ( count( $matches ) > 1 ) {
			return Webmastery_MCP_Response::local_error( 'ambiguous_target', 'Heading target matched more than once.' );
		}

		$heading_index      = $matches[0];
		$heading_level      = self::heading_level( $blocks[ $heading_index ] );
		$section_start      = $heading_index + 1;
		$section_end        = count( $blocks );
		$replacement_blocks = self::parse_editable_blocks( $replacement_content );

		for ( $index = $section_start; $index < $count; $index++ ) {
			$is_next_section = 'core/heading' === ( $blocks[ $index ]['blockName'] ?? null )
				&& self::heading_level( $blocks[ $index ] ) <= $heading_level;

			if ( $is_next_section ) {
				$section_end = $index;
				break;
			}
		}

		array_splice( $blocks, $section_start, $section_end - $section_start, $replacement_blocks );

		return [
			'content'         => serialize_blocks( $blocks ),
			'replaced_blocks' => $section_end - $section_start,
			'target'          => [
				'type'          => 'heading',
				'heading_text'  => $heading_text,
				'heading_level' => $heading_level,
			],
		];
	}

	private static function patch_content_by_exact_match( $content, $old_content, $replacement_content ) {
		$count = substr_count( $content, $old_content );

		if ( 0 === $count ) {
			return Webmastery_MCP_Response::local_error( 'target_not_found', 'Exact content target not found.' );
		}
		if ( $count > 1 ) {
			return Webmastery_MCP_Response::local_error( 'ambiguous_target', 'Exact content target matched more than once.' );
		}

		return [
			'content'         => str_replace( $old_content, $replacement_content, $content ),
			'replaced_blocks' => null,
			'target'          => [
				'type' => 'exact',
			],
		];
	}

	private static function register_patch_post_content() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/patch-post-content', [
			'label'               => 'Patch Post Content',
			'description'         => 'Safely update one targeted part of a post, page, or eligible custom post type body without replacing the full content.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'               => [
						'type'        => 'integer',
						'description' => 'Post, page, or custom post type ID to patch.',
					],
					'content_type'          => [
						'type'        => 'string',
						'description' => 'Optional expected content type slug, such as post, page, or an eligible custom post type.',
					],
					'target_type'           => [
						'type'        => 'string',
						'enum'        => [ 'heading', 'exact' ],
						'description' => 'Use heading for block-aware section replacement, or exact for strict raw-content replacement.',
					],
					'heading_text'          => [
						'type'        => 'string',
						'description' => 'Exact heading text to target when target_type is heading.',
					],
					'old_content'           => [
						'type'        => 'string',
						'description' => 'Exact current content to replace when target_type is exact.',
					],
					'replacement_content'   => [
						'type'        => 'string',
						'description' => 'Replacement HTML or block markup for the targeted content.',
					],
					'expected_content_hash' => [
						'type'        => 'string',
						'description' => 'Optional sha256 hash of the current post_content; fails if the content changed before patching.',
					],
				],
				'required'   => [ 'post_id', 'target_type', 'replacement_content' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = Webmastery_MCP_Post_Access::get_patch_content_target( $input['post_id'], $input['content_type'] ?? '' );

				if ( is_wp_error( $post ) ) {
					return Webmastery_MCP_Response::from_wp_error( $post );
				}

				$id              = $post->ID;
				$current_content = $post->post_content;
				$before_hash     = self::content_hash( $current_content );

				$expected_hash = sanitize_text_field( $input['expected_content_hash'] ?? '' );

				if ( '' !== $expected_hash && ! hash_equals( $before_hash, $expected_hash ) ) {
					return Webmastery_MCP_Response::legacy_error( 'content_hash_mismatch', 'Post content hash did not match; reload the post before patching.' );
				}

				$target_type         = sanitize_key( $input['target_type'] );
				$replacement_content = wp_kses_post( $input['replacement_content'] );

				if ( 'heading' === $target_type ) {
					if ( empty( $input['heading_text'] ) ) {
						return Webmastery_MCP_Response::legacy_error( 'missing_target', 'heading_text is required when target_type is heading.' );
					}

					$patch = self::patch_content_by_heading(
						$current_content,
						sanitize_text_field( $input['heading_text'] ),
						$replacement_content
					);
				} elseif ( 'exact' === $target_type ) {
					if ( ! isset( $input['old_content'] ) || '' === $input['old_content'] ) {
						return Webmastery_MCP_Response::legacy_error( 'missing_target', 'old_content is required when target_type is exact.' );
					}

					$patch = self::patch_content_by_exact_match(
						$current_content,
						$input['old_content'],
						$replacement_content
					);
				} else {
					return Webmastery_MCP_Response::legacy_error( 'invalid_target_type', 'target_type must be heading or exact.' );
				}

				if ( is_wp_error( $patch ) ) {
					return Webmastery_MCP_Response::from_wp_error( $patch );
				}

				$args = [
					'ID'           => $id,
					'post_content' => $patch['content'],
				];

				$result = Webmastery_MCP_Post_Writes::update( $args );

				if ( is_wp_error( $result ) ) {
					return Webmastery_MCP_Response::legacy_error( 'update_failed', 'Failed to update content.' );
				}

				$updated_post = get_post( $id );
				$after_hash   = self::content_hash( $updated_post->post_content );

				return [
					'success' => true,
					'data'    => [
						'id'                  => $id,
						'type'                => $post->post_type,
						'target'              => Webmastery_MCP_Untrusted::mark( $patch['target'], [ 'heading_text' ] ),
						'replaced_blocks'     => $patch['replaced_blocks'],
						'content_hash_before' => $before_hash,
						'content_hash_after'  => $after_hash,
						'post'                => Webmastery_MCP_Posts::normalize( $id ),
					],
				];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::patch_post_content_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
