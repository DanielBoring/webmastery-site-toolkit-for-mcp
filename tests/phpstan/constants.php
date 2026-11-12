<?php
/**
 * PHPStan-only declarations for WordPress constants that wordpress-stubs.php does not define.
 *
 * Loaded through `bootstrapFiles` in phpstan.neon.dist. Never loaded at runtime and not shipped:
 * the release ZIP is an allowlist of the main file, readme.txt, LICENSE and includes/.
 * WordPress defines these constants itself (wp-includes/default-constants.php and wp-config.php).
 */

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/var/www/html/wp-content' );
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/var/www/html/wp-content/plugins' );
}
