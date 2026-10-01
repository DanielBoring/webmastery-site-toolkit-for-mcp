<?php

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WSTM108_PROBE_OWNER' ) || ! defined( 'WSTM108_PROBE_SECRET' ) ) {
	return;
}
require_once __DIR__ . '/untrusted-content-boot.php';

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'wstm108', '/boot', array(
		'methods' => 'GET',
		'permission_callback' => static function ( $request ) {
			$secret = $request->get_header( 'X-WSTM108-Stage' );
			return 'GET' === $request->get_method() && is_string( $secret ) && hash_equals( WSTM108_PROBE_SECRET, $secret )
				? true : new WP_Error( 'wstm108_probe_denied', 'Owned read-only probe authorization required.', array( 'status' => 403 ) );
		},
		'callback' => static fn() => array(
			'identity' => array(
				'owner' => WSTM108_PROBE_OWNER, 'root' => realpath( ABSPATH ), 'plugin_root' => realpath( dirname( __DIR__, 2 ) ),
				'config_sha256' => hash_file( 'sha256', ABSPATH . 'wp-config.php' ),
				'uid' => function_exists( 'posix_geteuid' ) ? posix_geteuid() : null,
			),
			'runtime' => wstm108_runtime_snapshot(), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
		),
	) );
} );
