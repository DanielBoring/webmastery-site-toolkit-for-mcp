<?php
/**
 * Plugin Name: Webmastery Site Toolkit for MCP
 * Plugin URI:  https://www.virtuallyboring.com/webmastery-site-toolkit-for-mcp/
 * Description: Adds site management abilities for MCP-powered WordPress workflows: posts, pages, taxonomy, comments, media, content hygiene, plugins, user lookup, site info, health, performance, security, and SEO analysis.
 * Version:     2.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author:      Daniel Boring
 * Author URI:  https://www.virtuallyboring.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: webmastery-site-toolkit-for-mcp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		echo '<div class="notice notice-error"><p>' . wp_kses(
				sprintf(
					/* translators: %s: URL to MCP Adapter plugin page */
					__( '<strong>Webmastery Site Toolkit for MCP</strong> requires the <a href="%s">MCP Adapter</a> plugin to be installed and active.', 'webmastery-site-toolkit-for-mcp' ),
					'https://wordpress.org/plugins/mcp-adapter/'
				),
				[ 'strong' => [], 'a' => [ 'href' => [] ] ]
			) . '</p></div>';
	}
} );

// Register the webmastery-site-toolkit-for-mcp category before abilities are registered.
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'webmastery-site-toolkit-for-mcp', array(
		'label'       => 'Webmastery Site Toolkit for MCP',
		'description' => 'WordPress site management abilities registered by Webmastery Site Toolkit for MCP.',
	) );
} );

// Register abilities — wp_register_ability() only works inside wp_abilities_api_init.
add_action( 'wp_abilities_api_init', function () {
	require_once __DIR__ . '/includes/class-posts.php';
	require_once __DIR__ . '/includes/class-custom-post-types.php';
	require_once __DIR__ . '/includes/class-taxonomy.php';
	require_once __DIR__ . '/includes/class-comments.php';
	require_once __DIR__ . '/includes/class-media.php';
	require_once __DIR__ . '/includes/class-users.php';
	require_once __DIR__ . '/includes/class-site-info.php';
	require_once __DIR__ . '/includes/class-health.php';
	require_once __DIR__ . '/includes/class-database-health.php';
	require_once __DIR__ . '/includes/class-performance-status.php';
	require_once __DIR__ . '/includes/class-content-hygiene.php';
	require_once __DIR__ . '/includes/class-security.php';
	require_once __DIR__ . '/includes/class-seo.php';
	require_once __DIR__ . '/includes/class-plugins.php';

	Webmastery_MCP_Posts::register();
	Webmastery_MCP_Custom_Post_Types::register();
	Webmastery_MCP_Taxonomy::register();
	Webmastery_MCP_Comments::register();
	Webmastery_MCP_Media::register();
	Webmastery_MCP_Users::register();
	Webmastery_MCP_Site_Info::register();
	Webmastery_MCP_Health::register();
	Webmastery_MCP_Database_Health::register();
	Webmastery_MCP_Performance_Status::register();
	Webmastery_MCP_Content_Hygiene::register();
	Webmastery_MCP_Security::register();
	Webmastery_MCP_SEO::register();
	Webmastery_MCP_Plugins::register();
} );
