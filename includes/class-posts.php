<?php

defined( 'ABSPATH' ) || exit;

class Unlock_MCP_Posts {

	public static function register() {
		self::register_post_type( 'post' );
		self::register_post_type( 'page' );
	}

	private static function normalize( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		$data = [
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'content'       => $post->post_content,
			'excerpt'       => $post->post_excerpt,
			'status'        => $post->post_status,
			'slug'          => $post->post_name,
			'url'           => get_permalink( $post->ID ),
			'author'        => (int) $post->post_author,
			'date_created'  => $post->post_date,
			'date_modified' => $post->post_modified,
			'type'          => $post->post_type,
		];

		if ( 'post' === $post->post_type ) {
			$data['categories'] = wp_get_post_categories( $post->ID, [ 'fields' => 'ids' ] );
			$data['tags']       = wp_get_post_tags( $post->ID, [ 'fields' => 'ids' ] );
		}

		return $data;
	}

	private static function permission( $cap ) {
		return function () use ( $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability." );
			}
			return true;
		};
	}

	private static function object_permission( $type, $input_key, $cap ) {
		return function ( $input = [] ) use ( $type, $input_key, $cap ) {
			$id   = absint( $input[ $input_key ] ?? 0 );
			$post = get_post( $id );

			if ( ! $post || $post->post_type !== $type ) {
				return new WP_Error( 'not_found', ucfirst( $type ) . ' not found.' );
			}
			if ( ! current_user_can( $cap, $id ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability for this {$type}." );
			}
			return true;
		};
	}

	private static function create_permission( $type ) {
		return function ( $input = [] ) use ( $type ) {
			$edit_cap    = 'post' === $type ? 'edit_posts' : 'edit_pages';
			$publish_cap = 'post' === $type ? 'publish_posts' : 'publish_pages';
			$status      = $input['status'] ?? 'draft';

			if ( ! current_user_can( $edit_cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$edit_cap} capability." );
			}
			if ( in_array( $status, [ 'publish', 'future' ], true ) && ! current_user_can( $publish_cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$publish_cap} capability." );
			}
			if ( 'page' === $type && ! empty( $input['parent'] ) && ! current_user_can( 'edit_post', absint( $input['parent'] ) ) ) {
				return new WP_Error( 'forbidden', 'Requires edit_post capability for the parent page.' );
			}
			return true;
		};
	}

	private static function register_post_type( $type ) {
		$slug  = 'post' === $type ? 'posts' : 'pages';
		$label = 'post' === $type ? 'Post' : 'Page';

		// --- list ---
		$list_input = [
			'type'       => 'object',
			'properties' => [
				'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash', 'any' ], 'default' => 'any' ],
				'search'   => [ 'type' => 'string' ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
				'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'author'   => [ 'type' => 'integer' ],
				'orderby'  => [ 'type' => 'string', 'enum' => [ 'date', 'title', 'modified', 'id' ], 'default' => 'date' ],
				'order'    => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
			],
		];

		if ( 'post' === $type ) {
			$list_input['properties']['category_id'] = [ 'type' => 'integer' ];
		}

		wp_register_ability( "wp-mcp/list-{$slug}", [
			'label'               => "List {$label}s",
			'description'         => "List WordPress {$slug} with optional filters.",
			'category'            => 'wp-mcp',
			'input_schema'        => $list_input,
			'execute_callback'    => function ( $input ) use ( $type, $slug ) {
				$args = [
					'post_type'      => $type,
					'post_status'    => $input['status'] ?? 'any',
					'posts_per_page' => min( (int) ( $input['per_page'] ?? 20 ), 100 ),
					'paged'          => max( 1, (int) ( $input['page'] ?? 1 ) ),
					'orderby'        => $input['orderby'] ?? 'date',
					'order'          => strtoupper( $input['order'] ?? 'DESC' ),
				];

				if ( ! empty( $input['search'] ) ) {
					$args['s'] = sanitize_text_field( $input['search'] );
				}
				if ( ! empty( $input['author'] ) ) {
					$args['author'] = absint( $input['author'] );
				}
				if ( ! current_user_can( 'edit_others_' . $slug ) ) {
					$args['author'] = get_current_user_id();
				}
				if ( 'post' === $type && ! empty( $input['category_id'] ) ) {
					$args['cat'] = absint( $input['category_id'] );
				}

				$query = new WP_Query( $args );

				return [
					'success' => true,
					'data'    => [
						'items'       => array_values( array_filter( array_map( [ self::class, 'normalize' ], $query->posts ) ) ),
						'total'       => (int) $query->found_posts,
						'total_pages' => (int) $query->max_num_pages,
					],
				];
			},
			'permission_callback' => self::permission( 'post' === $type ? 'edit_posts' : 'edit_pages' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- get ---
		wp_register_ability( "wp-mcp/get-{$type}", [
			'label'               => "Get {$label}",
			'description'         => "Get a single WordPress {$type} by ID.",
			'category'            => 'wp-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					"{$type}_id" => [ 'type' => 'integer', 'description' => ucfirst( $type ) . ' ID' ],
				],
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return [ 'success' => false, 'error' => ucfirst( $type ) . ' not found.' ];
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to view this ' . $type . '.' ];
				}

				return [ 'success' => true, 'data' => self::normalize( $post ) ];
			},
			'permission_callback' => self::object_permission( $type, "{$type}_id", 'edit_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- create ---
		$create_props = [
			'title'   => [ 'type' => 'string', 'description' => "{$label} title" ],
			'content' => [ 'type' => 'string', 'description' => "{$label} content (HTML)" ],
			'status'         => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'future' ], 'default' => 'draft' ],
			'scheduled_date' => [ 'type' => 'string', 'description' => 'ISO 8601 datetime to publish (required when status is future, e.g. 2025-12-01T09:00:00)' ],
			'excerpt'        => [ 'type' => 'string' ],
			'slug'           => [ 'type' => 'string' ],
		];

		if ( 'post' === $type ) {
			$create_props['category_ids'] = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
			$create_props['tag_ids']      = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
		}
		if ( 'page' === $type ) {
			$create_props['parent'] = [ 'type' => 'integer', 'description' => 'Parent page ID (0 for top-level)' ];
		}

		wp_register_ability( "wp-mcp/create-{$type}", [
			'label'               => "Create {$label}",
			'description'         => "Create a new WordPress {$type}.",
			'category'            => 'wp-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $create_props,
				'required'   => [ 'title', 'content' ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$permission = self::create_permission( $type );
				$allowed    = $permission( $input );
				if ( is_wp_error( $allowed ) ) {
					return [ 'success' => false, 'error' => $allowed->get_error_message() ];
				}

				$args = [
					'post_type'    => $type,
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => wp_kses_post( $input['content'] ),
					'post_status'  => in_array( $input['status'] ?? 'draft', [ 'draft', 'publish', 'pending', 'future' ], true )
										? $input['status']
										: 'draft',
				];

				if ( ! empty( $input['scheduled_date'] ) ) {
					$args['post_date']     = wp_date( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $input['scheduled_date'] ) ) );
					$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
				}
				if ( ! empty( $input['excerpt'] ) ) {
					$args['post_excerpt'] = sanitize_text_field( $input['excerpt'] );
				}
				if ( ! empty( $input['slug'] ) ) {
					$args['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( 'page' === $type && isset( $input['parent'] ) ) {
					$args['post_parent'] = absint( $input['parent'] );
				}

				$id = wp_insert_post( $args, true );

				if ( is_wp_error( $id ) ) {
					return [ 'success' => false, 'error' => $id->get_error_message() ];
				}

				if ( 'post' === $type ) {
					if ( ! empty( $input['category_ids'] ) ) {
						wp_set_post_categories( $id, array_map( 'absint', (array) $input['category_ids'] ) );
					}
					if ( ! empty( $input['tag_ids'] ) ) {
						wp_set_post_tags( $id, array_map( 'absint', (array) $input['tag_ids'] ) );
					}
				}

				return [ 'success' => true, 'data' => self::normalize( $id ) ];
			},
			'permission_callback' => self::create_permission( $type ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- update ---
		$update_props = [
			"{$type}_id" => [ 'type' => 'integer', 'description' => ucfirst( $type ) . ' ID to update' ],
			'title'      => [ 'type' => 'string' ],
			'content'    => [ 'type' => 'string' ],
			'status'         => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'private', 'future' ] ],
			'scheduled_date' => [ 'type' => 'string', 'description' => 'ISO 8601 datetime to publish (required when status is future)' ],
			'excerpt'        => [ 'type' => 'string' ],
			'slug'           => [ 'type' => 'string' ],
		];

		if ( 'post' === $type ) {
			$update_props['category_ids'] = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
			$update_props['tag_ids']      = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
		}
		if ( 'page' === $type ) {
			$update_props['parent'] = [ 'type' => 'integer', 'description' => 'Parent page ID (0 for top-level)' ];
		}

		$update_props['yoast_meta_description'] = [ 'type' => 'string', 'description' => 'Yoast SEO meta description (stored as _yoast_wpseo_metadesc)' ];
		$update_props['yoast_focus_keyword']    = [ 'type' => 'string', 'description' => 'Yoast SEO focus keyword (stored as _yoast_wpseo_focuskw)' ];

		wp_register_ability( "wp-mcp/update-{$type}", [
			'label'               => "Update {$label}",
			'description'         => "Update an existing WordPress {$type}.",
			'category'            => 'wp-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $update_props,
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type, $slug ) {
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return [ 'success' => false, 'error' => ucfirst( $type ) . ' not found.' ];
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to update this ' . $type . '.' ];
				}
				if ( isset( $input['status'] ) && in_array( $input['status'], [ 'publish', 'future' ], true ) && ! current_user_can( 'publish_' . $slug ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to publish this ' . $type . '.' ];
				}

				$args = [ 'ID' => $id ];

				if ( isset( $input['title'] ) ) {
					$args['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['content'] ) ) {
					$args['post_content'] = wp_kses_post( $input['content'] );
				}
				if ( isset( $input['excerpt'] ) ) {
					$args['post_excerpt'] = sanitize_text_field( $input['excerpt'] );
				}
				if ( isset( $input['slug'] ) ) {
					$args['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( isset( $input['status'] ) && in_array( $input['status'], [ 'draft', 'publish', 'pending', 'private', 'future' ], true ) ) {
					$args['post_status'] = $input['status'];
				}
				if ( ! empty( $input['scheduled_date'] ) ) {
					$args['post_date']     = wp_date( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $input['scheduled_date'] ) ) );
					$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
				}
				if ( 'page' === $type && isset( $input['parent'] ) ) {
					$args['post_parent'] = absint( $input['parent'] );
				}

				$result = wp_update_post( $args, true );

				if ( is_wp_error( $result ) ) {
					return [ 'success' => false, 'error' => $result->get_error_message() ];
				}

				if ( 'post' === $type ) {
					if ( isset( $input['category_ids'] ) ) {
						wp_set_post_categories( $id, array_map( 'absint', (array) $input['category_ids'] ) );
					}
					if ( isset( $input['tag_ids'] ) ) {
						wp_set_post_tags( $id, array_map( 'absint', (array) $input['tag_ids'] ) );
					}
				}

				if ( isset( $input['yoast_meta_description'] ) ) {
					update_post_meta( $id, '_yoast_wpseo_metadesc', sanitize_text_field( $input['yoast_meta_description'] ) );
				}
				if ( isset( $input['yoast_focus_keyword'] ) ) {
					update_post_meta( $id, '_yoast_wpseo_focuskw', sanitize_text_field( $input['yoast_focus_keyword'] ) );
				}

				return [ 'success' => true, 'data' => self::normalize( $id ) ];
			},
			'permission_callback' => self::object_permission( $type, "{$type}_id", 'edit_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- delete (trash) ---
		wp_register_ability( "wp-mcp/delete-{$type}", [
			'label'               => "Delete {$label}",
			'description'         => "Move a WordPress {$type} to trash.",
			'category'            => 'wp-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					"{$type}_id" => [ 'type' => 'integer', 'description' => ucfirst( $type ) . ' ID to trash' ],
				],
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return [ 'success' => false, 'error' => ucfirst( $type ) . ' not found.' ];
				}
				if ( ! current_user_can( 'delete_post', $id ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to delete this ' . $type . '.' ];
				}

				$result = wp_trash_post( $id );

				if ( ! $result ) {
					return [ 'success' => false, 'error' => 'Failed to trash ' . $type . '.' ];
				}

				return [ 'success' => true, 'data' => [ 'id' => $id, 'status' => 'trash' ] ];
			},
			'permission_callback' => self::object_permission( $type, "{$type}_id", 'delete_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
