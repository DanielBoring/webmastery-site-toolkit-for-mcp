<?php

namespace Wstm114;

// Load the unchanged class body in a test namespace so PHP's DNS boundary can
// be counted deterministically without adding a production bypass/filter.
$wstm114_source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-webmaster-verification.php' );
eval( 'namespace Wstm114; use \WP_Error; use \Webmastery_MCP_Response; ' . substr( $wstm114_source, 5 ) );

function function_exists( $name ) {
	return \function_exists( __NAMESPACE__ . '\\' . $name ) || \function_exists( $name );
}

function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['wstm114']['caps'], true );
}

function home_url( $path = '' ) {
	++$GLOBALS['wstm114']['counts']['home'];
	return $GLOBALS['wstm114']['home'] . $path;
}

function get_current_blog_id() {
	return $GLOBALS['wstm114']['site'];
}

function get_transient( $key ) {
	++$GLOBALS['wstm114']['counts']['cache'];
	$entry = $GLOBALS['wstm114']['cache'][ $key ] ?? null;
	return $entry && $entry['expires'] > $GLOBALS['wstm114']['now'] ? $entry['value'] : false;
}

function set_transient( $key, $value, $ttl ) {
	$GLOBALS['wstm114']['cache'][ $key ] = array(
		'value' => $value,
		'expires' => $GLOBALS['wstm114']['now'] + $ttl,
		'ttl' => $ttl,
	);
	return true;
}

function get_plugins() {
	++$GLOBALS['wstm114']['counts']['plugins'];
	return $GLOBALS['wstm114']['installed'] ? array( 'google-site-kit/google-site-kit.php' => array() ) : array();
}

function is_plugin_active( $plugin ) {
	++$GLOBALS['wstm114']['counts']['active'];
	return $GLOBALS['wstm114']['active'];
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function dns_get_record( $host, $type ) {
	++$GLOBALS['wstm114']['counts']['dns'];
	return $GLOBALS['wstm114']['failure'] ? false : array( array( 'txt' => 'google-site-verification=wstm114' ) );
}

function wp_remote_request( $url, $args ) {
	++$GLOBALS['wstm114']['counts']['http'];
	$GLOBALS['wstm114']['requests'][] = array( $url, $args['method'] );
	if ( $GLOBALS['wstm114']['failure'] ) {
		return new \WP_Error( 'wstm114_http', 'wstm114 public request failed' );
	}
	$path = parse_url( $url, PHP_URL_PATH );
	$body = str_ends_with( $path, '/robots.txt' )
		? 'Sitemap: ' . $GLOBALS['wstm114']['home'] . '/sitemap.xml'
		: '<meta name="google-site-verification" content="wstm114"><meta name="msvalidate.01" content="wstm114">';
	return array( 'response' => array( 'code' => 200 ), 'body' => $body );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
