<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Taxonomy {

	public static function register() {
		self::register_list( 'category', 'Categories' );
		self::register_list( 'post_tag', 'Tags' );
		self::register_get( 'category' );
		self::register_get( 'post_tag' );
		self::register_create( 'category' );
		self::register_create( 'post_tag' );
		self::register_update( 'category' );
		self::register_update( 'post_tag' );
		self::register_delete( 'category' );
		self::register_delete( 'post_tag' );
	}

	private static function normalize_term( $term ) {
		return [
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => $term->count,
			'parent'      => $term->parent,
		];
	}

	private static function check_write_permission( $taxonomy, $action, $term_id = null ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return new WP_Error( 'invalid_taxonomy', 'Taxonomy is not registered.' );
		}

		$capability = 'delete' === $action ? $tax->cap->delete_terms : $tax->cap->edit_terms;
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error( 'forbidden', "Requires {$capability} capability." );
		}

		if ( null !== $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			// Leave missing/wrong-taxonomy errors to the existing execute response.
			if ( $term && ! is_wp_error( $term ) && ! current_user_can( "{$action}_term", $term_id ) ) {
				return new WP_Error( 'forbidden', "Cannot {$action} this term." );
			}
		}

		return true;
	}

	private static function register_list( $taxonomy, $label ) {
		$ability = 'category' === $taxonomy ? 'categories' : 'tags';

		wp_register_ability( "webmastery-site-toolkit-for-mcp/list-{$ability}", [
			'label'               => "List {$label}",
			'description'         => "List all WordPress {$label}.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'search'   => [ 'type' => 'string' ],
					'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ],
					'hide_empty' => [ 'type' => 'boolean', 'default' => false ],
				],
			],
			'execute_callback'    => function ( $input ) use ( $taxonomy ) {
				$args = [
					'taxonomy'   => $taxonomy,
					'number'     => min( (int) ( $input['per_page'] ?? 100 ), 200 ),
					'hide_empty' => ! empty( $input['hide_empty'] ),
				];

				if ( ! empty( $input['search'] ) ) {
					$args['search'] = sanitize_text_field( $input['search'] );
				}

				$terms = get_terms( $args );

				if ( is_wp_error( $terms ) ) {
					return [ 'success' => false, 'error' => $terms->get_error_message() ];
				}

				return [
					'success' => true,
					'data'    => array_map( [ self::class, 'normalize_term' ], $terms ),
				];
			},
			'permission_callback' => function () {
				if ( ! current_user_can( 'read' ) ) {
					return new WP_Error( 'forbidden', 'Requires read capability.' );
				}
				return true;
			},
			'meta' => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_get( $taxonomy ) {
		$is_category = 'category' === $taxonomy;
		$label       = $is_category ? 'Category' : 'Tag';
		$ability     = $is_category ? 'category' : 'tag';

		wp_register_ability( "webmastery-site-toolkit-for-mcp/get-{$ability}", [
			'label'               => "Get {$label}",
			'description'         => "Get a single WordPress {$label} by ID.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					"{$ability}_id" => [ 'type' => 'integer', 'description' => "{$label} term ID" ],
				],
				'required'   => [ "{$ability}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $taxonomy, $label, $ability ) {
				$id   = absint( $input[ "{$ability}_id" ] );
				$term = get_term( $id, $taxonomy );

				if ( ! $term || is_wp_error( $term ) ) {
					return [ 'success' => false, 'error' => "{$label} not found." ];
				}

				return [ 'success' => true, 'data' => self::normalize_term( $term ) ];
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
		] );
	}

	private static function register_create( $taxonomy ) {
		$is_category = 'category' === $taxonomy;
		$label       = $is_category ? 'Category' : 'Tag';
		$ability     = $is_category ? 'category' : 'tag';

		$props = [
			'name'        => [ 'type' => 'string', 'description' => "{$label} name" ],
			'slug'        => [ 'type' => 'string' ],
			'description' => [ 'type' => 'string' ],
		];

		if ( $is_category ) {
			$props['parent'] = [ 'type' => 'integer', 'description' => 'Parent category ID (0 for top-level)' ];
		}

		wp_register_ability( "webmastery-site-toolkit-for-mcp/create-{$ability}", [
			'label'               => "Create {$label}",
			'description'         => "Create a new WordPress {$label}.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $props,
				'required'   => [ 'name' ],
			],
			'execute_callback'    => function ( $input ) use ( $taxonomy ) {
				$permission = self::check_write_permission( $taxonomy, 'edit' );
				if ( is_wp_error( $permission ) ) {
					return [ 'success' => false, 'error' => $permission->get_error_message() ];
				}

				$args = [];

				if ( ! empty( $input['slug'] ) ) {
					$args['slug'] = sanitize_title( $input['slug'] );
				}
				if ( ! empty( $input['description'] ) ) {
					$args['description'] = sanitize_text_field( $input['description'] );
				}
				if ( isset( $input['parent'] ) ) {
					$args['parent'] = absint( $input['parent'] );
				}

				$result = wp_insert_term( sanitize_text_field( $input['name'] ), $taxonomy, $args );

				if ( is_wp_error( $result ) ) {
					return [ 'success' => false, 'error' => $result->get_error_message() ];
				}

				$term = get_term( $result['term_id'], $taxonomy );

				return [ 'success' => true, 'data' => self::normalize_term( $term ) ];
			},
			'permission_callback' => function () use ( $taxonomy ) {
				return self::check_write_permission( $taxonomy, 'edit' );
			},
			'meta' => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_update( $taxonomy ) {
		$is_category = 'category' === $taxonomy;
		$label       = $is_category ? 'Category' : 'Tag';
		$ability     = $is_category ? 'category' : 'tag';

		$props = [
			"{$ability}_id" => [ 'type' => 'integer', 'description' => "{$label} term ID to update" ],
			'name'          => [ 'type' => 'string', 'description' => "{$label} name" ],
			'slug'          => [ 'type' => 'string' ],
			'description'   => [ 'type' => 'string' ],
		];

		if ( $is_category ) {
			$props['parent'] = [ 'type' => 'integer', 'description' => 'Parent category ID (0 for top-level)' ];
		}

		wp_register_ability( "webmastery-site-toolkit-for-mcp/update-{$ability}", [
			'label'               => "Update {$label}",
			'description'         => "Update an existing WordPress {$label}.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $props,
				'required'   => [ "{$ability}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $taxonomy, $label, $ability ) {
				$id   = absint( $input[ "{$ability}_id" ] );
				$term = get_term( $id, $taxonomy );

				if ( ! $term || is_wp_error( $term ) ) {
					return [ 'success' => false, 'error' => "{$label} not found." ];
				}

				$permission = self::check_write_permission( $taxonomy, 'edit', $id );
				if ( is_wp_error( $permission ) ) {
					return [ 'success' => false, 'error' => $permission->get_error_message() ];
				}

				$args = [];

				if ( isset( $input['name'] ) ) {
					$args['name'] = sanitize_text_field( $input['name'] );
				}
				if ( isset( $input['slug'] ) ) {
					$args['slug'] = sanitize_title( $input['slug'] );
				}
				if ( isset( $input['description'] ) ) {
					$args['description'] = sanitize_text_field( $input['description'] );
				}
				if ( 'category' === $taxonomy && isset( $input['parent'] ) ) {
					$args['parent'] = absint( $input['parent'] );
				}

				$result = wp_update_term( $id, $taxonomy, $args );

				if ( is_wp_error( $result ) ) {
					return [ 'success' => false, 'error' => $result->get_error_message() ];
				}

				$updated = get_term( $result['term_id'], $taxonomy );

				if ( ! $updated || is_wp_error( $updated ) ) {
					return [ 'success' => false, 'error' => "{$label} not found." ];
				}

				return [ 'success' => true, 'data' => self::normalize_term( $updated ) ];
			},
			'permission_callback' => function ( $input ) use ( $taxonomy, $ability ) {
				return self::check_write_permission( $taxonomy, 'edit', absint( $input[ "{$ability}_id" ] ?? 0 ) );
			},
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_delete( $taxonomy ) {
		$is_category = 'category' === $taxonomy;
		$label       = $is_category ? 'Category' : 'Tag';
		$ability     = $is_category ? 'category' : 'tag';

		wp_register_ability( "webmastery-site-toolkit-for-mcp/delete-{$ability}", [
			'label'               => "Delete {$label}",
			'description'         => "Permanently delete a WordPress {$label} by ID.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					"{$ability}_id" => [ 'type' => 'integer', 'description' => "{$label} term ID to delete" ],
				],
				'required'   => [ "{$ability}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $taxonomy, $label, $ability ) {
				$id   = absint( $input[ "{$ability}_id" ] );
				$term = get_term( $id, $taxonomy );

				if ( ! $term || is_wp_error( $term ) ) {
					return [ 'success' => false, 'error' => "{$label} not found." ];
				}

				$permission = self::check_write_permission( $taxonomy, 'delete', $id );
				if ( is_wp_error( $permission ) ) {
					return [ 'success' => false, 'error' => $permission->get_error_message() ];
				}

				$result = wp_delete_term( $id, $taxonomy );

				if ( is_wp_error( $result ) ) {
					return [ 'success' => false, 'error' => $result->get_error_message() ];
				}

				if ( ! $result ) {
					return [ 'success' => false, 'error' => "{$label} was not deleted." ];
				}

				return [ 'success' => true, 'data' => [ 'id' => $id, 'deleted' => true ] ];
			},
			'permission_callback' => function ( $input ) use ( $taxonomy, $ability ) {
				return self::check_write_permission( $taxonomy, 'delete', absint( $input[ "{$ability}_id" ] ?? 0 ) );
			},
			'meta' => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
