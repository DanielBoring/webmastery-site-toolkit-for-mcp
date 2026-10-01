<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Posts {

	public static function register() {
		self::register_post_type( 'post' );
		self::register_post_type( 'page' );
		Webmastery_MCP_Content_Patch::register();
		Webmastery_MCP_Featured_Image::register();
		Webmastery_MCP_Post_Revisions::register();
		Webmastery_MCP_Post_Meta::register();
		Webmastery_MCP_Bulk_Posts::register();
	}

	public static function reject_combined_metadata( array $input ): ?array {
		return Webmastery_MCP_Post_Meta::reject_combined_metadata( $input );
	}

	public static function can_read_post_meta_key( int $post_id, string $key ): bool {
		return Webmastery_MCP_Post_Meta::can_read_post_meta_key( $post_id, $key );
	}

	public static function bulk_input_error( $input ) {
		return Webmastery_MCP_Bulk_Posts::bulk_input_error( $input );
	}

	public static function normalize( $post, $fields = 'full' ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		$data = Webmastery_MCP_Post_Content::normalize( $post, $fields );

		if ( 'post' === $post->post_type ) {
			$data['categories'] = wp_get_post_categories( $post->ID, [ 'fields' => 'ids' ] );
			$data['tags']       = wp_get_post_tags( $post->ID, [ 'fields' => 'ids' ] );
		}

		return $data;
	}

	private static function can_read_full_post( $post ) {
		return Webmastery_MCP_Post_Access::can_read( $post );
	}

	private static function query_readable_posts( $args, $page, $per_page, $fields = 'summary' ) {
		return Webmastery_MCP_Post_Access::query_readable( $args, $page, $per_page, static fn( $id ) => self::can_read_full_post( $id ), static fn( $post ) => self::normalize( $post, $fields ) );
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
				'fields'   => Webmastery_MCP_List_Query::fields_schema(),
			],
		];

		if ( 'post' === $type ) {
			$list_input['properties']['category_id'] = [ 'type' => 'integer' ];
		}

		wp_register_ability( "webmastery-site-toolkit-for-mcp/list-{$slug}", [
			'label'               => "List {$label}s",
			'description'         => "List WordPress {$slug} in bounded candidate windows. Follow next_page even for empty items; no exact totals. Summary omits content; fields full includes it.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => $list_input,
			'execute_callback'    => function ( $input ) use ( $type, $slug ) {
				$args = Webmastery_MCP_Post_Access::list_args( $input, $type, 'edit_others_' . $slug );
				if ( 'post' === $type && ! empty( $input['category_id'] ) ) {
					$args['cat'] = absint( $input['category_id'] );
				}

				[ 'per_page' => $per_page, 'page' => $page ] = Webmastery_MCP_Input::pagination( $input );
				$data                                        = self::query_readable_posts( $args, $page, $per_page, $input['fields'] ?? 'summary' );
				if ( is_wp_error( $data ) ) {
					return Webmastery_MCP_Response::from_wp_error( $data );
				}

				return [
					'success' => true,
					'data'    => $data,
				];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::permission( 'post' === $type ? 'edit_posts' : 'edit_pages' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- get ---
		wp_register_ability( "webmastery-site-toolkit-for-mcp/get-{$type}", [
			'label'               => "Get {$label}",
			'description'         => "Get a single WordPress {$type} by ID.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', ucfirst( $type ) . ' not found.' );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to view this ' . $type . '.' );
				}

				return [ 'success' => true, 'data' => self::normalize( $post ) ];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::object_permission( $type, "{$type}_id", 'edit_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- create ---
		$create_props = [
			'title'   => [ 'type' => 'string', 'description' => "{$label} title" ],
			'content' => [ 'type' => 'string', 'description' => "{$label} content (HTML)" ],
			'status'         => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'private', 'future' ], 'default' => 'draft' ],
			'scheduled_date' => [ 'type' => 'string', 'description' => 'Date to set post_date. New future schedules require a valid date at least 60 seconds ahead at validation. Prefer ISO 8601 with Z or an explicit offset; legacy relative and offset-less parsing is retained (normally UTC).' ],
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

		wp_register_ability( "webmastery-site-toolkit-for-mcp/create-{$type}", [
			'label'               => "Create {$label}",
			'description'         => "Create a new WordPress {$type} without metadata or SEO aliases. Create a draft, write metadata with update-post-meta, then publish; the steps are not atomic.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $create_props,
				'required'   => [ 'title', 'content' ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$metadata_error = Webmastery_MCP_Post_Meta::reject_combined_metadata( $input );
				if ( null !== $metadata_error ) {
					return $metadata_error;
				}
				$permission = Webmastery_MCP_Post_Access::create_permission( $type );
				$allowed    = $permission( $input );
				if ( is_wp_error( $allowed ) ) {
					return Webmastery_MCP_Response::from_wp_error( $allowed );
				}

				$args = [
					'post_type'    => $type,
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => wp_kses_post( $input['content'] ),
					'post_status'  => in_array( $input['status'] ?? 'draft', [ 'draft', 'publish', 'pending', 'private', 'future' ], true )
										? ( $input['status'] ?? 'draft' )
										: 'draft',
				];

				if ( ! empty( $input['excerpt'] ) ) {
					$args['post_excerpt'] = sanitize_text_field( $input['excerpt'] );
				}
				if ( ! empty( $input['slug'] ) ) {
					$args['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( 'page' === $type && isset( $input['parent'] ) ) {
					$args['post_parent'] = absint( $input['parent'] );
				}

				$schedule = Webmastery_MCP_Post_Scheduling::prepare( $input );
				if ( is_wp_error( $schedule ) ) {
					return Webmastery_MCP_Response::from_wp_error( $schedule );
				}
				$args = array_merge( $args, $schedule );

				if ( isset( $args['post_parent'] ) ) {
					$parent_valid = Webmastery_MCP_Post_Parent::validate( $type, $args['post_parent'] );
					if ( is_wp_error( $parent_valid ) ) {
						return Webmastery_MCP_Response::from_wp_error( $parent_valid );
					}
				}

				$id = Webmastery_MCP_Post_Writes::insert( $args );

				if ( is_wp_error( $id ) ) {
					return Webmastery_MCP_Response::from_wp_error( $id );
				}

				if ( 'post' === $type ) {
					if ( ! empty( $input['category_ids'] ) ) {
						wp_set_post_categories( $id, array_map( 'absint', (array) $input['category_ids'] ) );
					}
					if ( ! empty( $input['tag_ids'] ) ) {
						wp_set_post_tags( $id, array_map( 'absint', (array) $input['tag_ids'] ) );
					}
				}

				$data = self::normalize( $id );
				return [ 'success' => true, 'data' => $data ];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::create_permission( $type ),
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
			'scheduled_date' => [ 'type' => 'string', 'description' => 'Date to set post_date. New future schedules require a valid date at least 60 seconds ahead at validation; omit to retain a valid existing future schedule. Prefer ISO 8601 with an explicit offset; legacy parsing is retained.' ],
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

		wp_register_ability( "webmastery-site-toolkit-for-mcp/update-{$type}", [
			'label'               => "Update {$label}",
			'description'         => "Update an existing WordPress {$type} without metadata or SEO aliases. Use update-post-meta separately for each metadata key.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $update_props,
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type, $slug ) {
				$metadata_error = Webmastery_MCP_Post_Meta::reject_combined_metadata( $input );
				if ( null !== $metadata_error ) {
					return $metadata_error;
				}
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return Webmastery_MCP_Response::legacy_error( 'not_found', ucfirst( $type ) . ' not found.' );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to update this ' . $type . '.' );
				}
				if ( isset( $input['status'] ) && in_array( $input['status'], [ 'publish', 'private', 'future' ], true ) && ! current_user_can( 'publish_' . $slug ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to publish this ' . $type . '.' );
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
				if ( 'page' === $type && isset( $input['parent'] ) ) {
					$args['post_parent'] = absint( $input['parent'] );
				}

				$schedule = Webmastery_MCP_Post_Scheduling::prepare( $input, $post );
				if ( is_wp_error( $schedule ) ) {
					return Webmastery_MCP_Response::from_wp_error( $schedule );
				}
				$args = array_merge( $args, $schedule );

				if ( isset( $args['post_parent'] ) ) {
					$parent_valid = Webmastery_MCP_Post_Parent::validate( $type, $args['post_parent'], $id );
					if ( is_wp_error( $parent_valid ) ) {
						return Webmastery_MCP_Response::from_wp_error( $parent_valid );
					}
				}

				$result = Webmastery_MCP_Post_Writes::update( $args );

				if ( is_wp_error( $result ) ) {
					return Webmastery_MCP_Response::from_wp_error( $result );
				}

				if ( 'post' === $type ) {
					if ( isset( $input['category_ids'] ) ) {
						wp_set_post_categories( $id, array_map( 'absint', (array) $input['category_ids'] ) );
					}
					if ( isset( $input['tag_ids'] ) ) {
						wp_set_post_tags( $id, array_map( 'absint', (array) $input['tag_ids'] ) );
					}
				}

				$data = self::normalize( $id );
				return [ 'success' => true, 'data' => $data ];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::object_permission( $type, "{$type}_id", 'edit_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- delete (trash) ---
		wp_register_ability( "webmastery-site-toolkit-for-mcp/delete-{$type}", [
			'label'               => "Delete {$label}",
			'description'         => "Move a WordPress {$type} to trash. Refuses to delete when site trash is disabled.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', ucfirst( $type ) . ' not found.' );
				}
				if ( ! current_user_can( 'delete_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to delete this ' . $type . '.' );
				}

				if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
					return Webmastery_MCP_Response::legacy_error( 'trash_disabled', 'Trash is disabled on this site; the ' . $type . ' was not deleted.' );
				}

				$result = wp_trash_post( $id );

				if ( ! $result ) {
					return Webmastery_MCP_Response::legacy_error( 'trash_failed', 'Failed to trash ' . $type . '.' );
				}

				return [ 'success' => true, 'data' => [ 'id' => $id, 'status' => 'trash' ] ];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::object_permission( $type, "{$type}_id", 'delete_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- restore (untrash) ---
		wp_register_ability( "webmastery-site-toolkit-for-mcp/restore-{$type}", [
			'label'               => "Restore {$label}",
			'description'         => "Restore a WordPress {$type} from trash.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					"{$type}_id" => [ 'type' => 'integer', 'description' => ucfirst( $type ) . ' ID to restore from trash' ],
				],
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return Webmastery_MCP_Response::legacy_error( 'not_found', ucfirst( $type ) . ' not found.' );
				}
				if ( ! current_user_can( 'delete_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', "You do not have permission to restore this {$type}." );
				}

				$result = wp_untrash_post( $id );

				if ( ! $result ) {
					return Webmastery_MCP_Response::legacy_error( 'restore_failed', 'Failed to restore ' . $type . ' from trash.' );
				}

				return [ 'success' => true, 'data' => self::normalize( $id ) ];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::restore_permission( $type, "{$type}_id" ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
