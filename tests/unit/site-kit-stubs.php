<?php

// Unit-only provider/REST boundary doubles; real WordPress dispatch is covered by E2E.
function get_plugins() {
	++$GLOBALS['wstm125']['calls']['plugin'];
	return array( 'google-site-kit/google-site-kit.php' => array( 'Version' => $GLOBALS['wstm125']['version'] ) );
}

function is_plugin_active( $plugin ) {
	return $GLOBALS['wstm125']['active'];
}

function rest_get_server() {
	return new Wstm125RestServer();
}

function home_url( $path = '/' ) {
	return 'http://localhost' . $path;
}

final class WP_REST_Request {
	public string $path;
	public array $params = array();
	public function __construct( $method, $path ) {
		$this->path = $path;
	}
	public function set_query_params( $params ) {
		$this->params = $params;
	}
	public function set_url_params( $params ) {}
}

final class Wstm125RestServer {
	public function get_routes() {
		++$GLOBALS['wstm125']['calls']['routes'];
		if ( 'missing-route' === $GLOBALS['wstm125']['mode'] ) {
			return array();
		}
		$endpoint = array( 'methods' => array( 'GET' => true ) );
		if ( 'missing-callback' !== $GLOBALS['wstm125']['mode'] ) {
			$endpoint['permission_callback'] = 'uncallable-callback' === $GLOBALS['wstm125']['mode']
				? 'wstm125_nonexistent_callback' : 'wstm125_permission';
		}
		$routes = array();
		foreach ( array( '/core/modules/data/list', '/core/user/data/permissions', '/modules/pagespeed-insights/data/pagespeed', '/core/site/data/connection', '/core/user/data/authentication' ) as $path ) {
			$routes[ '/google-site-kit/v1' . $path ] = array( $endpoint );
		}
		return $routes;
	}
}

function wstm125_permission( $request ) {
	++$GLOBALS['wstm125']['calls']['permission'];
	switch ( $GLOBALS['wstm125']['mode'] ) {
		case 'false':
			return false;
		case 'null':
			return null;
		case 'error':
			return new WP_Error( 'upstream_denied', 'Fixture denial.' );
		default:
			return true;
	}
}

function rest_do_request( $request ) {
	// WordPress skips absent permission callbacks; the adapter must fail closed first.
	$allowed = 'missing-callback' === $GLOBALS['wstm125']['mode'] ? true : wstm125_permission( $request );
	$error = is_wp_error( $allowed ) || false === $allowed || null === $allowed;
	if ( ! $error ) {
		++$GLOBALS['wstm125']['calls']['data'];
	}
	return new Wstm125RestResponse( $error, $request->path );
}

final class Wstm125RestResponse {
	private bool $error;
	private string $path;
	public function __construct( $error, $path ) {
		$this->error = $error;
		$this->path = $path;
	}
	public function is_error() {
		return $this->error;
	}
	public function get_status() {
		return $this->error ? 403 : 200;
	}
	public function as_error() {
		return new WP_Error( 'upstream_denied', 'Fixture denial.' );
	}
	public function get_data() {
		return str_ends_with( $this->path, '/core/modules/data/list' )
			? array( array( 'slug' => 'pagespeed-insights', 'active' => true, 'connected' => true ) )
			: array();
	}
}
