<?php
/**
 * Read-only stage probe. Its loader owns the token independently of wp-config.
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WSTM116_PROBE_OWNER' ) ) {
	return;
}
require_once __DIR__ . '/destructive-safety-boot.php';
add_action( 'rest_api_init', static function (): void {
	$permission = static function ( $request ) {
		return 'GET' === $request->get_method() && is_string( $request->get_header( 'X-WSTM116-Stage' ) )
			&& hash_equals( WSTM116_PROBE_OWNER, $request->get_header( 'X-WSTM116-Stage' ) )
			? true : new WP_Error( 'wstm116_probe_denied', 'Probe token required.', array( 'status' => 403 ) );
	};
	$boot = static fn() => array(
			'identity' => array( 'owner' => WSTM116_PROBE_OWNER, 'root' => realpath( ABSPATH ),
				'plugin_root' => realpath( dirname( __DIR__, 2 ) ),
				'config_sha256' => hash_file( 'sha256', ABSPATH . 'wp-config.php' ),
				'uid' => function_exists( 'posix_geteuid' ) ? posix_geteuid() : null ),
			'runtime' => wstm116_runtime_configuration(), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
		);
	register_rest_route( 'wstm116', '/boot', array(
		'methods' => 'GET', 'permission_callback' => $permission, 'callback' => $boot,
	) );
	register_rest_route( 'wstm116', '/upload/(?P<owner>[a-f0-9]{32})', array(
		'methods' => 'GET', 'permission_callback' => $permission,
		'callback' => static function ( $request ) use ( $boot ) {
			$owner = $request['owner'];
			$uploads = wp_upload_dir( null, false );
			$root = realpath( $uploads['basedir'] );
			$directory = $root . '/wstm116-' . $owner;
			$marker = $directory . '/.wstm116-owner';
			if ( ! is_string( $owner ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $owner )
				|| false !== $uploads['error'] || false === $root || is_link( $directory ) || realpath( $directory ) !== $directory
				|| is_link( $marker ) || ! is_file( $marker ) ) {
				return new WP_Error( 'wstm116_upload_denied', 'Owned upload identity unavailable.', array( 'status' => 409 ) );
			}
			$proof = file_get_contents( $marker );
			if ( ! is_string( $proof ) || 1 !== preg_match( '/^' . $owner . ':[a-f0-9]{64}\n$/D', $proof ) ) {
				return new WP_Error( 'wstm116_upload_denied', 'Owned upload marker differs.', array( 'status' => 409 ) );
			}
			clearstatcache( true, $directory );
			return array( 'boot' => $boot(), 'upload' => array( 'owner' => $owner, 'directory' => $directory,
				'uid' => fileowner( $directory ), 'mode' => fileperms( $directory ) & 0777, 'writable' => is_writable( $directory ) ) );
		},
	) );
} );
