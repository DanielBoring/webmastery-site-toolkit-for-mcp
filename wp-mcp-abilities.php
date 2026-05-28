<?php
/**
 * Plugin Name: WP MCP Abilities
 * Description: Registers core WordPress management abilities for the MCP Adapter plugin.
 * Version:     1.0.8-diag
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author:      Daniel Boring
 * License:     MIT
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		echo '<div class="notice notice-error"><p><strong>WP MCP Abilities</strong> requires the <a href="https://wordpress.org/plugins/mcp-adapter/">MCP Adapter</a> plugin to be installed and active.</p></div>';
	}
} );

// Register abilities — wp_register_ability() only works inside wp_abilities_api_init.
add_action( 'wp_abilities_api_init', function () {
	require_once __DIR__ . '/includes/class-posts.php';
	require_once __DIR__ . '/includes/class-taxonomy.php';
	require_once __DIR__ . '/includes/class-comments.php';
	require_once __DIR__ . '/includes/class-health.php';
	require_once __DIR__ . '/includes/class-security.php';
	require_once __DIR__ . '/includes/class-seo.php';

	WP_MCP_Posts::register();
	WP_MCP_Taxonomy::register();
	WP_MCP_Comments::register();
	WP_MCP_Health::register();
	WP_MCP_Security::register();
	WP_MCP_SEO::register();
} );

// Diagnostic REST endpoint — DELETE BEFORE PRODUCTION.
// GET /wp-json/wp-mcp/v1/diag  (requires authentication)
add_action( 'rest_api_init', function () {
	register_rest_route( 'wp-mcp/v1', '/diag', array(
		'methods'             => 'GET',
		'callback'            => function () {
			$abilities    = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
			$ability_list = array();
			foreach ( $abilities as $a ) {
				$meta           = $a->get_meta();
				$ability_list[] = array(
					'name'       => $a->get_name(),
					'mcp_public' => $meta['mcp']['public'] ?? false,
					'mcp_type'   => $meta['mcp']['type'] ?? 'tool',
				);
			}
			return array(
				'wp_abilities_api_init_fired' => (bool) did_action( 'wp_abilities_api_init' ),
				'wp_register_ability_exists'  => function_exists( 'wp_register_ability' ),
				'wp_get_abilities_exists'     => function_exists( 'wp_get_abilities' ),
				'ability_count'               => count( $ability_list ),
				'abilities'                   => $ability_list,
			);
		},
		'permission_callback' => function () {
			return is_user_logged_in();
		},
	) );
} );
