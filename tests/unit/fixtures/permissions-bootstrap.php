<?php

declare(strict_types=1);

// A fresh process deliberately does not use the unit bootstrap's helper requires.
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
class WP_Error {}

$calls = array();
$hooks = array();
function current_user_can( $cap ) {
	$GLOBALS['calls'][] = $cap;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $hook ][] = $callback;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	add_filter( $hook, $callback, $priority, $args );
}

require dirname( __DIR__, 3 ) . '/webmastery-site-toolkit-for-mcp.php';
$loaded = class_exists( 'Webmastery_MCP_Permissions', false );
$before = $calls;
$callback = Webmastery_MCP_Permissions::admin();
$factory_calls = $calls;
$result = $callback();
echo json_encode( array(
	'loaded' => $loaded,
	'before' => $before,
	'factory_calls' => $factory_calls,
	'result' => $result,
	'calls' => $calls,
	'ability_hook' => isset( $hooks['wp_abilities_api_init'] ),
), JSON_THROW_ON_ERROR );
