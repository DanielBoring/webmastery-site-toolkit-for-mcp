<?php

defined( 'ABSPATH' ) || exit;

class Unlock_MCP_Taxonomy {

	public static function register() {
		self::register_list( 'category', 'Categories' );
		self::register_list( 'post_tag', 'Tags' );
		self::register_create( 'category' );
		self::register_create( 'post_tag' );
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

	private static function register_list( $taxonomy, $label ) {
		$ability = 'category' === $taxonomy ? 'categories' : 'tags';

		wp_register_ability( "unlock-mcp-potential/list-{$ability}", [
			'label'               => "List {$label}",
			'description'         => "List all WordPress {$label}.",
			'category'            => 'unlock-mcp-potential',
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

		wp_register_ability( "unlock-mcp-potential/create-{$ability}", [
			'label'               => "Create {$label}",
			'description'         => "Create a new WordPress {$label}.",
			'category'            => 'unlock-mcp-potential',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $props,
				'required'   => [ 'name' ],
			],
			'execute_callback'    => function ( $input ) use ( $taxonomy ) {
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
			'permission_callback' => function () {
				if ( ! current_user_can( 'manage_categories' ) ) {
					return new WP_Error( 'forbidden', 'Requires manage_categories capability.' );
				}
				return true;
			},
			'meta' => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_delete( $taxonomy ) {
		$is_category = 'category' === $taxonomy;
		$label       = $is_category ? 'Category' : 'Tag';
		$ability     = $is_category ? 'category' : 'tag';

		wp_register_ability( "unlock-mcp-potential/delete-{$ability}", [
			'label'               => "Delete {$label}",
			'description'         => "Permanently delete a WordPress {$label} by ID.",
			'category'            => 'unlock-mcp-potential',
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

				$result = wp_delete_term( $id, $taxonomy );

				if ( is_wp_error( $result ) ) {
					return [ 'success' => false, 'error' => $result->get_error_message() ];
				}

				return [ 'success' => true, 'data' => [ 'id' => $id, 'deleted' => true ] ];
			},
			'permission_callback' => function () {
				if ( ! current_user_can( 'manage_categories' ) ) {
					return new WP_Error( 'forbidden', 'Requires manage_categories capability.' );
				}
				return true;
			},
			'meta' => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
