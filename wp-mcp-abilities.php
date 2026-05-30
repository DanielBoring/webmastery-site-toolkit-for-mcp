<?php
/**
 * Plugin Name: WP MCP Abilities
 * Plugin URI:  https://github.com/DanielBoring/wordpress-mcp-abilities
 * Description: Adds core content management abilities to the official WordPress MCP Adapter plugin, giving AI agents full editorial access: posts, pages, taxonomy, comments, health checks, security auditing, and SEO analysis.
 * Version:     1.3.2
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author:      Daniel Boring
 * Author URI:  https://www.virtuallyboring.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-mcp-abilities
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		echo '<div class="notice notice-error"><p>' . wp_kses(
				sprintf(
					/* translators: %s: URL to MCP Adapter plugin page */
					__( '<strong>WP MCP Abilities</strong> requires the <a href="%s">MCP Adapter</a> plugin to be installed and active.', 'wp-mcp-abilities' ),
					'https://wordpress.org/plugins/mcp-adapter/'
				),
				[ 'strong' => [], 'a' => [ 'href' => [] ] ]
			) . '</p></div>';
	}
} );

// Register the wp-mcp category before abilities are registered.
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'wp-mcp', array(
		'label'       => 'WP MCP',
		'description' => 'WordPress content management abilities registered by WP MCP Abilities.',
	) );
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
