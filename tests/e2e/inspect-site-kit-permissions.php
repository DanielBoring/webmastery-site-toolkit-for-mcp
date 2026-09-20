<?php

// Read-only upstream inspection, separate from the controlled fixture and all Google data calls.
if ( function_exists( 'webmastery_mcp_e2e_site_kit_dashboard_permission' ) || ! defined( 'GOOGLESITEKIT_VERSION' ) || ! class_exists( 'Google\\Site_Kit\\Plugin' ) ) {
	throw new RuntimeException( 'Activate the official Site Kit plugin without the Site Kit MU-plugin fixture on a disposable installation.' );
}

$wstm125_http_calls = 0;
$wstm125_capabilities = array();
$wstm125_http_guard = static function () use ( &$wstm125_http_calls ) {
	++$wstm125_http_calls;
	return new WP_Error( 'wstm125_inspection_http_blocked', 'External HTTP is disabled during route permission inspection.' );
};
$wstm125_cap_observer = static function ( $allcaps, $caps, $args ) use ( &$wstm125_capabilities ) {
	if ( 0 === strpos( $args[0], 'googlesitekit_' ) ) {
		$wstm125_capabilities[] = array( 'requested' => $args[0], 'mapped' => $caps );
	}
	return $allcaps;
};
$wstm125_inspection = array(
	'site_kit_version' => GOOGLESITEKIT_VERSION,
	'wordpress_version' => get_bloginfo( 'version' ),
	'roles' => wp_get_current_user()->roles,
	'local_read' => current_user_can( 'read' ),
	'data_callbacks_executed' => 0,
	'routes' => array(),
);
add_filter( 'pre_http_request', $wstm125_http_guard );
add_filter( 'user_has_cap', $wstm125_cap_observer, PHP_INT_MAX, 3 );
try {
	$routes = rest_get_server()->get_routes();
	foreach ( array( '/core/modules/data/list', '/core/user/data/permissions', '/modules/pagespeed-insights/data/pagespeed' ) as $suffix ) {
		$path = '/google-site-kit/v1' . $suffix;
		$found = false;
		foreach ( $routes as $pattern => $endpoints ) {
			if ( 1 !== preg_match( '@^' . $pattern . '$@i', $path, $matches ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				if ( empty( $endpoint['methods']['GET'] ) || ! is_callable( $endpoint['permission_callback'] ?? null ) ) {
					continue;
				}
				$found = true;
				$request = new WP_REST_Request( 'GET', $path );
				$request->set_url_params( array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY ) );
				$request->set_query_params( array( 'url' => home_url( '/' ), 'strategy' => 'mobile' ) );
				$wstm125_capabilities = array();
				$callback = $endpoint['permission_callback'];
				$reflection = new ReflectionFunction( Closure::fromCallable( $callback ) );
				$permission = call_user_func( $callback, $request );
				$wstm125_inspection['routes'][] = array(
					'path' => $path,
					'pattern' => $pattern,
					'callback_file' => str_replace( WP_PLUGIN_DIR . '/google-site-kit/', '', $reflection->getFileName() ),
					'callback_lines' => array( $reflection->getStartLine(), $reflection->getEndLine() ),
					'allowed' => ! is_wp_error( $permission ) && false !== $permission && null !== $permission,
					'capability_checks' => $wstm125_capabilities,
				);
				break 2;
			}
		}
		if ( ! $found ) {
			throw new RuntimeException( "Required upstream GET route or callable permission missing: {$path}" );
		}
	}
} finally {
	remove_filter( 'pre_http_request', $wstm125_http_guard );
	remove_filter( 'user_has_cap', $wstm125_cap_observer, PHP_INT_MAX );
}
$wstm125_inspection['http_attempts'] = $wstm125_http_calls;
echo wp_json_encode( $wstm125_inspection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( $wstm125_http_calls ) {
	throw new RuntimeException( 'Upstream permission inspection attempted HTTP; inspect the changed provider before supporting it.' );
}
