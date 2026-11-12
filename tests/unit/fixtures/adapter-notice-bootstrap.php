<?php

declare(strict_types=1);

// Usage: adapter-notice-bootstrap.php <none|autoload> <cap,cap,...>
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
class WP_Error {}

$adapter = $argv[1] ?? 'none';
$caps = array_filter( explode( ',', $argv[2] ?? '' ) );
$calls = array();
$hooks = array();
function current_user_can( $cap ) {
	$GLOBALS['calls'][] = $cap;
	return in_array( $cap, $GLOBALS['caps'], true );
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $hook ][] = $callback;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	add_filter( $hook, $callback, $priority, $args );
}
function __( $text, $domain ) {
	return $text;
}
function wp_kses( $html, $allowed ) {
	return $html;
}

// A Composer or plugin autoloader, as used by a standalone or bundled MCP Adapter.
if ( 'autoload' === $adapter ) {
	spl_autoload_register( static function ( $name ) {
		if ( 'WP\MCP\Core\McpAdapter' === $name ) {
			eval( 'namespace WP\MCP\Core; class McpAdapter {}' );
		}
	} );
}

// The Abilities API is in core on every supported WordPress version, so it cannot signal the adapter.
function wp_register_ability() {}

require dirname( __DIR__, 3 ) . '/webmastery-site-toolkit-for-mcp.php';

ob_start();
foreach ( $hooks['admin_notices'] ?? array() as $callback ) {
	$callback();
}
echo json_encode( array(
	'notice' => ob_get_clean(),
	'calls' => $calls,
), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
