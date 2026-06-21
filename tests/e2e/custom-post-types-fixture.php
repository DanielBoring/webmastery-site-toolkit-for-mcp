<?php
/**
 * E2E fixture custom post types.
 *
 * This file is copied to mu-plugins by scripts/e2e-test.sh so fixture CPTs are
 * registered before the MCP abilities API registration action runs.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function () {
		register_taxonomy(
			'mcp_genre',
			[ 'mcp_book' ],
			[
				'label'        => 'MCP Genres',
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'capabilities' => [
					'manage_terms' => 'manage_mcp_genres',
					'edit_terms'   => 'edit_mcp_genres',
					'delete_terms' => 'delete_mcp_genres',
					'assign_terms' => 'assign_mcp_genres',
				],
			]
		);

		register_post_type(
			'mcp_book',
			[
				'label'           => 'MCP Books',
				'labels'          => [
					'singular_name' => 'MCP Book',
				],
				'public'          => true,
				'show_ui'         => true,
				'show_in_rest'    => true,
				'supports'        => [ 'title', 'editor', 'excerpt' ],
				'taxonomies'      => [ 'mcp_genre' ],
				'capability_type' => [ 'mcp_book', 'mcp_books' ],
				'map_meta_cap'    => true,
			]
		);

		register_post_type(
			'mcp_case_study',
			[
				'label'        => 'MCP Case Studies',
				'labels'       => [
					'singular_name' => 'MCP Case Study',
				],
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'supports'     => [ 'title', 'editor' ],
				'capabilities' => [
					'edit_post'              => 'edit_mcp_case',
					'read_post'              => 'read_mcp_case',
					'delete_post'            => 'delete_mcp_case',
					'edit_posts'             => 'edit_mcp_cases',
					'edit_others_posts'      => 'edit_others_mcp_cases',
					'delete_posts'           => 'delete_mcp_cases',
					'delete_others_posts'    => 'delete_others_mcp_cases',
					'publish_posts'          => 'publish_mcp_cases',
					'read_private_posts'     => 'read_private_mcp_cases',
					'create_posts'           => 'create_mcp_cases',
					'edit_published_posts'   => 'edit_published_mcp_cases',
					'delete_published_posts' => 'delete_published_mcp_cases',
				],
				'map_meta_cap' => true,
			]
		);
	},
	0
);
