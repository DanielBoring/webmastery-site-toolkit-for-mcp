<?php
/**
 * Plugin Name: Unlock MCP Potential
 * Plugin URI:  https://github.com/DanielBoring/unlock-mcp-potential
 * Description: Adds content management abilities for MCP-powered WordPress workflows: posts, pages, taxonomy, comments, media, user lookup, health checks, security auditing, and SEO analysis.
 * Version:     1.6.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author:      Daniel Boring
 * Author URI:  https://www.virtuallyboring.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: unlock-mcp-potential
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		echo '<div class="notice notice-error"><p>' . wp_kses(
				sprintf(
					/* translators: %s: URL to MCP Adapter plugin page */
					__( '<strong>Unlock MCP Potential</strong> requires the <a href="%s">MCP Adapter</a> plugin to be installed and active.', 'unlock-mcp-potential' ),
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
		'description' => 'WordPress content management abilities registered by Unlock MCP Potential.',
	) );
} );

// Register abilities — wp_register_ability() only works inside wp_abilities_api_init.
add_action( 'wp_abilities_api_init', function () {
	require_once __DIR__ . '/includes/class-posts.php';
	require_once __DIR__ . '/includes/class-taxonomy.php';
	require_once __DIR__ . '/includes/class-comments.php';
	require_once __DIR__ . '/includes/class-media.php';
	require_once __DIR__ . '/includes/class-users.php';
	require_once __DIR__ . '/includes/class-health.php';
	require_once __DIR__ . '/includes/class-security.php';
	require_once __DIR__ . '/includes/class-seo.php';
	require_once __DIR__ . '/includes/class-plugins.php';

	Unlock_MCP_Posts::register();
	Unlock_MCP_Taxonomy::register();
	Unlock_MCP_Comments::register();
	Unlock_MCP_Media::register();
	Unlock_MCP_Users::register();
	Unlock_MCP_Health::register();
	Unlock_MCP_Security::register();
	Unlock_MCP_SEO::register();
	Unlock_MCP_Plugins::register();
} );
