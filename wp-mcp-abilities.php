<?php
/**
 * Plugin Name: WP MCP Abilities
 * Description: Registers core WordPress management abilities for the MCP Adapter plugin.
 * Version:     1.0.2-debug
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

// Diagnostic: one inline ability with no dependencies to verify the hook fires.
add_action( 'wp_abilities_api_init', function () {
	wp_register_ability( 'core/ping', [
		'label'               => 'Ping',
		'description'         => 'Diagnostic ability — confirms WP MCP Abilities plugin is active.',
		'category'            => 'core',
		'execute_callback'    => function () {
			return [ 'success' => true, 'data' => [ 'status' => 'ok', 'version' => '1.0.2-debug' ] ];
		},
		'permission_callback' => function () { return true; },
		'meta'                => [ 'mcp' => [ 'public' => true ] ],
	] );
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
