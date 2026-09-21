<?php

declare(strict_types=1);

namespace Wstm119;

const WP_CONTENT_DIR = 'wstm119-unused-content';

function get_option( $name, $default = false ) {
	$GLOBALS['wstm119']['calls'][] = array( 'get_option', $name, $default );
	$value = array_key_exists( $name, $GLOBALS['wstm119']['options'] ) ? $GLOBALS['wstm119']['options'][ $name ] : $default;
	return isset( $GLOBALS['wstm119']['filter'] ) ? ( $GLOBALS['wstm119']['filter'] )( $name, $value ) : $value;
}

function get_site_option( $name, $default = false ) {
	$GLOBALS['wstm119']['calls'][] = array( 'get_site_option', $name, $default );
	$value = array_key_exists( $name, $GLOBALS['wstm119']['network'] ) ? $GLOBALS['wstm119']['network'][ $name ] : $default;
	return isset( $GLOBALS['wstm119']['filter'] ) ? ( $GLOBALS['wstm119']['filter'] )( $name, $value ) : $value;
}

function is_multisite() {
	$GLOBALS['wstm119']['calls'][] = array( 'is_multisite' );
	return $GLOBALS['wstm119']['multisite'];
}

function current_user_can( $capability ) {
	$GLOBALS['wstm119']['calls'][] = array( 'current_user_can', $capability );
	return in_array( $capability, $GLOBALS['wstm119']['caps'], true );
}

function update_option( ...$args ) {
	throw new \LogicException( 'Inventory must not update options.' );
}

function update_site_option( ...$args ) {
	throw new \LogicException( 'Inventory must not update network options.' );
}

function activate_plugin( ...$args ) {
	throw new \LogicException( 'Inventory must not activate plugins.' );
}

function deactivate_plugins( ...$args ) {
	throw new \LogicException( 'Inventory must not deactivate plugins.' );
}

function get_plugins() {
	throw new \LogicException( 'Inventory must not inspect installed plugin files.' );
}

function wp_using_ext_object_cache() {
	return false;
}

function file_exists( $path ) {
	return false;
}

// Evaluate unchanged production class bodies with isolated WordPress boundaries.
class_alias( \Webmastery_MCP_Response::class, __NAMESPACE__ . '\Webmastery_MCP_Response' );
foreach ( array( 'plugins', 'backup-status', 'performance-status' ) as $file ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-' . $file . '.php' );
	eval( 'namespace ' . __NAMESPACE__ . ';' . substr( $source, strlen( '<?php' ) ) );
}
