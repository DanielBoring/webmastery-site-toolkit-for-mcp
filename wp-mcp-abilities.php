<?php
/**
 * Plugin Name: WP MCP Abilities
 * Description: Registers core WordPress management abilities for the MCP Adapter plugin.
 * Version:     1.0.1
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
