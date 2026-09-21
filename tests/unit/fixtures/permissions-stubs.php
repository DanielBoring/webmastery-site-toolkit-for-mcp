<?php

namespace Wstm119;

// Evaluate the actual source against isolated WordPress boundaries, not copied checks.
foreach ( array( 'permissions', 'health', 'security', 'site-info', 'webmaster-verification' ) as $file ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-' . $file . '.php' );
	eval( 'namespace Wstm119; use \Closure; use \WP_Error; use \Webmastery_MCP_Response; ' . substr( $source, 5 ) );
}

function current_user_can( $capability ) {
	$GLOBALS['wstm119']['calls'][] = array( $GLOBALS['wstm119']['user'], $capability );
	return in_array( $capability, $GLOBALS['wstm119']['caps'], true );
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['wstm119']['abilities'][ $name ] = $args;
}

function unexpected_boundary( $name ) {
	$GLOBALS['wstm119']['boundaries'][] = $name;
	throw new \RuntimeException( 'Denied verification reached ' . $name );
}

function home_url( $path = '' ) {
	return unexpected_boundary( __FUNCTION__ );
}

function get_current_blog_id() {
	return unexpected_boundary( __FUNCTION__ );
}

function get_transient( $key ) {
	return unexpected_boundary( __FUNCTION__ );
}

function set_transient( $key, $value, $ttl ) {
	return unexpected_boundary( __FUNCTION__ );
}

function get_plugins() {
	return unexpected_boundary( __FUNCTION__ );
}

function is_plugin_active( $plugin ) {
	return unexpected_boundary( __FUNCTION__ );
}

function dns_get_record( $host, $type ) {
	return unexpected_boundary( __FUNCTION__ );
}

function wp_remote_request( $url, $args ) {
	return unexpected_boundary( __FUNCTION__ );
}
