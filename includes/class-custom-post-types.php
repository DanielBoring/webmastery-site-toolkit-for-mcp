<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Custom_Post_Types {

	private const NAMESPACE = 'webmastery-site-toolkit-for-mcp';

	public static function register() {
		self::register_list_post_types();
		self::register_dynamic_post_type_abilities();
	}

	public static function register_dynamic_post_type_abilities() {
		foreach ( self::ability_base_map() as $post_type => $ability_base ) {
			$post_type_object = get_post_type_object( $post_type );

			if ( ! $post_type_object ) {
				continue;
			}

			self::register_custom_post_type( $post_type_object, $ability_base );
		}
	}

	private static function eligible_post_types() {
		$post_types = get_post_types(
			[
				'public'   => true,
				'_builtin' => false,
			],
			'objects'
		);

		$eligible = [];

		foreach ( $post_types as $post_type => $post_type_object ) {
			if ( empty( $post_type_object->show_ui ) ) {
				continue;
			}

			$eligible[ $post_type ] = $post_type_object;
		}

		ksort( $eligible );

		return $eligible;
	}

	private static function ability_base_map() {
		$base_counts = [];
		$bases       = [];

		foreach ( self::eligible_post_types() as $post_type => $post_type_object ) {
			$base = sanitize_title( str_replace( '_', '-', $post_type ) );

			if ( '' === $base ) {
				continue;
			}

			$bases[ $post_type ]  = $base;
			$base_counts[ $base ] = ( $base_counts[ $base ] ?? 0 ) + 1;
		}

		foreach ( $bases as $post_type => $base ) {
			if ( $base_counts[ $base ] > 1 ) {
				$bases[ $post_type ] = $base . '-' . substr( md5( $post_type ), 0, 8 );
			}
		}

		return $bases;
	}

	private static function ability_registered( $ability_name ) {
		return function_exists( 'wp_get_abilities' ) && array_key_exists( $ability_name, wp_get_abilities() );
	}

	private static function ability_name( $action, $ability_base ) {
		return self::NAMESPACE . "/{$action}-cpt-{$ability_base}";
	}

	private static function cap( $post_type_object, $capability ) {
		return $post_type_object->cap->{$capability} ?? $capability;
	}

	private static function normalize_taxonomy( $taxonomy ) {
		return [
			'name'        => $taxonomy->name,
			'label'       => $taxonomy->label,
			'hierarchical' => (bool) $taxonomy->hierarchical,
			'rest_base'   => $taxonomy->rest_base,
			'capabilities' => (array) $taxonomy->cap,
		];
	}

	private static function normalize_post_type( $post_type_object, $ability_base ) {
		$taxonomies = get_object_taxonomies( $post_type_object->name, 'objects' );

		return [
			'name'            => $post_type_object->name,
			'label'           => $post_type_object->label,
			'singular_label'  => $post_type_object->labels->singular_name ?? $post_type_object->label,
			'description'     => $post_type_object->description,
			'ability_base'    => $ability_base,
			'abilities'       => [
				'list'   => self::ability_name( 'list', $ability_base ),
				'get'    => self::ability_name( 'get', $ability_base ),
				'create' => self::ability_name( 'create', $ability_base ),
				'update' => self::ability_name( 'update', $ability_base ),
				'delete' => self::ability_name( 'delete', $ability_base ),
			],
			'public'          => (bool) $post_type_object->public,
			'show_ui'         => (bool) $post_type_object->show_ui,
			'show_in_rest'    => (bool) $post_type_object->show_in_rest,
			'rest_base'       => $post_type_object->rest_base,
			'hierarchical'    => (bool) $post_type_object->hierarchical,
			'supports'        => array_keys( get_all_post_type_supports( $post_type_object->name ) ),
			'taxonomies'      => array_values( array_map( [ self::class, 'normalize_taxonomy' ], $taxonomies ) ),
			'capability_type' => $post_type_object->capability_type,
			'capabilities'    => (array) $post_type_object->cap,
		];
	}

	private static function normalize_post( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return null;
		}

		$taxonomy_terms = [];

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$taxonomy_terms[ $taxonomy ] = wp_get_object_terms(
				$post->ID,
				$taxonomy,
				[
					'fields' => 'ids',
				]
			);

			if ( is_wp_error( $taxonomy_terms[ $taxonomy ] ) ) {
				$taxonomy_terms[ $taxonomy ] = [];
			}
		}

		return [
			'id'                => $post->ID,
			'title'             => $post->post_title,
			'content'           => $post->post_content,
			'excerpt'           => $post->post_excerpt,
			'status'            => $post->post_status,
			'slug'              => $post->post_name,
			'url'               => get_permalink( $post->ID ),
			'author'            => (int) $post->post_author,
			'author_name'       => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'author_login'      => get_the_author_meta( 'user_login', (int) $post->post_author ),
			'date_created'      => $post->post_date,
			'date_modified'     => $post->post_modified,
			'type'              => $post->post_type,
			'featured_image_id' => (int) get_post_thumbnail_id( $post->ID ),
			'taxonomy_terms'    => $taxonomy_terms,
		];
	}

	private static function error_response( $code, $message, $data = [] ) {
		$response = [
			'success' => false,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];

		if ( ! empty( $data ) ) {
			$response['data'] = $data;
		}

		return $response;
	}

	private static function permission( $post_type_object, $capability ) {
		$cap = self::cap( $post_type_object, $capability );

		return function () use ( $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability." );
			}

			return true;
		};
	}

	private static function object_permission( $post_type_object, $capability ) {
		$type = $post_type_object->name;
		$cap  = self::cap( $post_type_object, $capability );

		return function ( $input = [] ) use ( $type, $cap ) {
			$id   = absint( $input['id'] ?? 0 );
			$post = get_post( $id );

			if ( ! $post || $post->post_type !== $type ) {
				return new WP_Error( 'not_found', 'Custom post type item not found.' );
			}
			if ( ! current_user_can( $cap, $id ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability for this custom post type item." );
			}

			return true;
		};
	}

	private static function create_permission( $post_type_object ) {
		$create_cap  = self::cap( $post_type_object, 'create_posts' );
		$publish_cap = self::cap( $post_type_object, 'publish_posts' );
		$edit_cap    = self::cap( $post_type_object, 'edit_post' );
		$type        = $post_type_object->name;

		return function ( $input = [] ) use ( $create_cap, $publish_cap, $edit_cap, $type ) {
			$status = $input['status'] ?? 'draft';

			if ( ! current_user_can( $create_cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$create_cap} capability." );
			}
			if ( in_array( $status, [ 'publish', 'future' ], true ) && ! current_user_can( $publish_cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$publish_cap} capability." );
			}
			if ( ! empty( $input['parent'] ) && ! current_user_can( $edit_cap, absint( $input['parent'] ) ) ) {
				return new WP_Error( 'forbidden', "Requires {$edit_cap} capability for the parent {$type} item." );
			}

			return true;
		};
	}

	private static function taxonomy_terms_schema() {
		return [
			'type'                 => 'object',
			'description'          => 'Map taxonomy names to term ID arrays. Only taxonomies registered to this custom post type are accepted.',
			'additionalProperties' => [
				'type'  => 'array',
				'items' => [
					'type' => 'integer',
				],
			],
		];
	}

	private static function validate_taxonomy_terms( $post_type, $taxonomy_terms ) {
		if ( empty( $taxonomy_terms ) ) {
			return true;
		}

		if ( ! is_array( $taxonomy_terms ) ) {
			return new WP_Error( 'invalid_taxonomy_terms', 'taxonomy_terms must be an object keyed by taxonomy name.' );
		}

		foreach ( $taxonomy_terms as $taxonomy => $terms ) {
			$taxonomy        = sanitize_key( $taxonomy );
			$taxonomy_object = get_taxonomy( $taxonomy );

			if ( ! $taxonomy_object || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				return new WP_Error( 'invalid_taxonomy', "Taxonomy {$taxonomy} is not registered for this custom post type." );
			}

			$assign_cap = $taxonomy_object->cap->assign_terms ?? 'assign_terms';
			if ( ! current_user_can( $assign_cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$assign_cap} capability to assign {$taxonomy} terms." );
			}
		}

		return true;
	}

	private static function assign_taxonomy_terms( $post_id, $post_type, $taxonomy_terms ) {
		$validated = self::validate_taxonomy_terms( $post_type, $taxonomy_terms );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( empty( $taxonomy_terms ) ) {
			return [];
		}

		$assigned = [];

		foreach ( $taxonomy_terms as $taxonomy => $terms ) {
			$taxonomy = sanitize_key( $taxonomy );

			$result = wp_set_object_terms( $post_id, array_map( 'absint', (array) $terms ), $taxonomy, false );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$assigned[ $taxonomy ] = array_map( 'absint', $result );
		}

		return $assigned;
	}

	private static function post_input_properties( $post_type_object ) {
		$properties = [
			'title'          => [ 'type' => 'string', 'description' => 'Custom post type item title.' ],
			'content'        => [ 'type' => 'string', 'description' => 'Custom post type item content (HTML).' ],
			'status'         => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'future' ], 'default' => 'draft' ],
			'scheduled_date' => [ 'type' => 'string', 'description' => 'ISO 8601 datetime to publish when status is future.' ],
			'excerpt'        => [ 'type' => 'string' ],
			'slug'           => [ 'type' => 'string' ],
			'taxonomy_terms' => self::taxonomy_terms_schema(),
		];

		if ( $post_type_object->hierarchical ) {
			$properties['parent'] = [ 'type' => 'integer', 'description' => 'Parent item ID (0 for top-level).' ];
		}

		return $properties;
	}

	private static function sanitized_post_args( $input, $allowed_statuses ) {
		$args = [];

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
		if ( isset( $input['status'] ) && in_array( $input['status'], $allowed_statuses, true ) ) {
			$args['post_status'] = $input['status'];
		}
		if ( ! empty( $input['scheduled_date'] ) ) {
			$args['post_date']     = wp_date( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $input['scheduled_date'] ) ) );
			$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = absint( $input['parent'] );
		}

		return $args;
	}

	private static function register_list_post_types() {
		wp_register_ability(
			self::NAMESPACE . '/list-post-types',
			[
				'label'               => 'List Custom Post Types',
				'description'         => 'List eligible public custom post types and their generated ability names.',
				'category'            => self::NAMESPACE,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [],
				],
				'execute_callback'    => function () {
					$items = [];
					$bases = self::ability_base_map();

					foreach ( self::eligible_post_types() as $post_type => $post_type_object ) {
						if ( ! isset( $bases[ $post_type ] ) ) {
							continue;
						}

						$items[] = self::normalize_post_type( $post_type_object, $bases[ $post_type ] );
					}

					return [
						'success' => true,
						'data'    => [
							'items' => $items,
							'total' => count( $items ),
						],
					];
				},
				'permission_callback' => function () {
					if ( ! current_user_can( 'read' ) ) {
						return new WP_Error( 'forbidden', 'Requires read capability.' );
					}

					return true;
				},
				'meta'                => [
					'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
					'mcp'         => [ 'public' => true, 'type' => 'tool' ],
				],
			]
		);
	}

	private static function register_custom_post_type( $post_type_object, $ability_base ) {
		$label          = $post_type_object->labels->singular_name ?? $post_type_object->label;
		$post_type_name = $post_type_object->name;

		if ( self::ability_registered( self::ability_name( 'list', $ability_base ) ) ) {
			return;
		}

		wp_register_ability(
			self::ability_name( 'list', $ability_base ),
			[
				'label'               => "List {$post_type_object->label}",
				'description'         => "List {$post_type_object->label} custom post type items with optional filters.",
				'category'            => self::NAMESPACE,
				'input_schema'        => [
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
				],
				'execute_callback'    => function ( $input ) use ( $post_type_object, $post_type_name ) {
					$args = [
						'post_type'      => $post_type_name,
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
					if ( ! current_user_can( self::cap( $post_type_object, 'edit_others_posts' ) ) ) {
						$args['author'] = get_current_user_id();
					}

					$query = new WP_Query( $args );

					return [
						'success' => true,
						'data'    => [
							'items'       => array_values( array_filter( array_map( [ self::class, 'normalize_post' ], $query->posts ) ) ),
							'total'       => (int) $query->found_posts,
							'total_pages' => (int) $query->max_num_pages,
						],
					];
				},
				'permission_callback' => self::permission( $post_type_object, 'edit_posts' ),
				'meta'                => [
					'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
					'mcp'         => [ 'public' => true, 'type' => 'tool' ],
				],
			]
		);

		wp_register_ability(
			self::ability_name( 'get', $ability_base ),
			[
				'label'               => "Get {$label}",
				'description'         => "Get one {$label} custom post type item by ID.",
				'category'            => self::NAMESPACE,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'id' => [ 'type' => 'integer', 'description' => "{$label} item ID." ],
					],
					'required'   => [ 'id' ],
				],
				'execute_callback'    => function ( $input ) use ( $post_type_object, $post_type_name ) {
					$id   = absint( $input['id'] ?? 0 );
					$post = get_post( $id );

					if ( ! $post || $post->post_type !== $post_type_name ) {
						return self::error_response( 'not_found', 'Custom post type item not found.' );
					}
					if ( ! current_user_can( self::cap( $post_type_object, 'read_post' ), $id ) ) {
						return self::error_response( 'forbidden', 'You do not have permission to view this custom post type item.' );
					}

					return [ 'success' => true, 'data' => self::normalize_post( $post ) ];
				},
				'permission_callback' => self::object_permission( $post_type_object, 'read_post' ),
				'meta'                => [
					'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
					'mcp'         => [ 'public' => true, 'type' => 'tool' ],
				],
			]
		);

		wp_register_ability(
			self::ability_name( 'create', $ability_base ),
			[
				'label'               => "Create {$label}",
				'description'         => "Create a {$label} custom post type item.",
				'category'            => self::NAMESPACE,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => self::post_input_properties( $post_type_object ),
					'required'   => [ 'title', 'content' ],
				],
				'execute_callback'    => function ( $input ) use ( $post_type_object, $post_type_name ) {
					$permission = self::create_permission( $post_type_object );
					$allowed    = $permission( $input );

					if ( is_wp_error( $allowed ) ) {
						return self::error_response( $allowed->get_error_code(), $allowed->get_error_message() );
					}

					$validated_terms = self::validate_taxonomy_terms( $post_type_name, $input['taxonomy_terms'] ?? [] );
					if ( is_wp_error( $validated_terms ) ) {
						return self::error_response( $validated_terms->get_error_code(), $validated_terms->get_error_message() );
					}

					$args                = self::sanitized_post_args( $input, [ 'draft', 'publish', 'pending', 'future' ] );
					$args['post_type']   = $post_type_name;
					$args['post_status'] = $args['post_status'] ?? 'draft';

					$id = wp_insert_post( wp_slash( $args ), true );

					if ( is_wp_error( $id ) ) {
						return self::error_response( 'create_failed', $id->get_error_message() );
					}

					$assigned_terms = self::assign_taxonomy_terms( $id, $post_type_name, $input['taxonomy_terms'] ?? [] );
					if ( is_wp_error( $assigned_terms ) ) {
						return self::error_response( $assigned_terms->get_error_code(), $assigned_terms->get_error_message() );
					}

					$data = self::normalize_post( $id );

					if ( ! empty( $assigned_terms ) ) {
						$data['assigned_terms'] = $assigned_terms;
					}

					return [ 'success' => true, 'data' => $data ];
				},
				'permission_callback' => self::create_permission( $post_type_object ),
				'meta'                => [
					'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
					'mcp'         => [ 'public' => true, 'type' => 'tool' ],
				],
			]
		);

		wp_register_ability(
			self::ability_name( 'update', $ability_base ),
			[
				'label'               => "Update {$label}",
				'description'         => "Update a {$label} custom post type item.",
				'category'            => self::NAMESPACE,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => array_merge(
						[
							'id' => [ 'type' => 'integer', 'description' => "{$label} item ID to update." ],
						],
						self::post_input_properties( $post_type_object )
					),
					'required'   => [ 'id' ],
				],
				'execute_callback'    => function ( $input ) use ( $post_type_object, $post_type_name ) {
					$id   = absint( $input['id'] ?? 0 );
					$post = get_post( $id );

					if ( ! $post || $post->post_type !== $post_type_name ) {
						return self::error_response( 'not_found', 'Custom post type item not found.' );
					}
					if ( ! current_user_can( self::cap( $post_type_object, 'edit_post' ), $id ) ) {
						return self::error_response( 'forbidden', 'You do not have permission to update this custom post type item.' );
					}
					if ( isset( $input['status'] ) && in_array( $input['status'], [ 'publish', 'future' ], true ) && ! current_user_can( self::cap( $post_type_object, 'publish_posts' ) ) ) {
						return self::error_response( 'forbidden', 'You do not have permission to publish this custom post type item.' );
					}

					$args       = self::sanitized_post_args( $input, [ 'draft', 'publish', 'pending', 'private', 'future' ] );
					$args['ID'] = $id;

					$result = wp_update_post( wp_slash( $args ), true );

					if ( is_wp_error( $result ) ) {
						return self::error_response( 'update_failed', $result->get_error_message() );
					}

					$assigned_terms = self::assign_taxonomy_terms( $id, $post_type_name, $input['taxonomy_terms'] ?? [] );
					if ( is_wp_error( $assigned_terms ) ) {
						return self::error_response( $assigned_terms->get_error_code(), $assigned_terms->get_error_message() );
					}

					$data = self::normalize_post( $id );

					if ( ! empty( $assigned_terms ) ) {
						$data['assigned_terms'] = $assigned_terms;
					}

					return [ 'success' => true, 'data' => $data ];
				},
				'permission_callback' => self::object_permission( $post_type_object, 'edit_post' ),
				'meta'                => [
					'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
					'mcp'         => [ 'public' => true, 'type' => 'tool' ],
				],
			]
		);

		wp_register_ability(
			self::ability_name( 'delete', $ability_base ),
			[
				'label'               => "Delete {$label}",
				'description'         => "Move a {$label} custom post type item to trash.",
				'category'            => self::NAMESPACE,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'id' => [ 'type' => 'integer', 'description' => "{$label} item ID to trash." ],
					],
					'required'   => [ 'id' ],
				],
				'execute_callback'    => function ( $input ) use ( $post_type_object, $post_type_name ) {
					$id   = absint( $input['id'] ?? 0 );
					$post = get_post( $id );

					if ( ! $post || $post->post_type !== $post_type_name ) {
						return self::error_response( 'not_found', 'Custom post type item not found.' );
					}
					if ( ! current_user_can( self::cap( $post_type_object, 'delete_post' ), $id ) ) {
						return self::error_response( 'forbidden', 'You do not have permission to delete this custom post type item.' );
					}

					$result = wp_trash_post( $id );

					if ( ! $result ) {
						return self::error_response( 'delete_failed', 'Failed to trash custom post type item.' );
					}

					return [ 'success' => true, 'data' => [ 'id' => $id, 'status' => 'trash' ] ];
				},
				'permission_callback' => self::object_permission( $post_type_object, 'delete_post' ),
				'meta'                => [
					'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
					'mcp'         => [ 'public' => true, 'type' => 'tool' ],
				],
			]
		);
	}
}
