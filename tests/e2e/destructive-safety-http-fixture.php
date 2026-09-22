<?php
/**
 * Install as an MU loader only in an explicitly disposable WordPress runtime.
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WSTM116_DISPOSABLE_RUNTIME' ) || true !== WSTM116_DISPOSABLE_RUNTIME ) {
	return;
}
require_once WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/tests/e2e/destructive-safety-fixture.php';
add_filter( 'rest_pre_dispatch', 'wstm116_http_observer', PHP_INT_MIN, 3 );
add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'wstm116', '/runtime', array(
		'methods' => 'GET',
		'permission_callback' => static fn( $request ) => defined( 'WSTM116_STAGE_TOKEN' ) && is_string( $request->get_header( 'X-WSTM116-Stage' ) ) && hash_equals( WSTM116_STAGE_TOKEN, $request->get_header( 'X-WSTM116-Stage' ) ),
		'callback' => static function () {
			$response = new WP_REST_Response( array( 'owner' => WSTM116_STAGE_TOKEN, 'trash_days' => EMPTY_TRASH_DAYS, 'disposable' => WSTM116_DISPOSABLE_RUNTIME ) );
			$response->header( 'X-WSTM116-Boot', base64_encode( wp_json_encode( array(
				'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
				'effective_uid' => function_exists( 'posix_geteuid' ) ? posix_geteuid() : null,
				'config_sha256' => hash_file( 'sha256', ABSPATH . 'wp-config.php' ),
				'opcache_validate_timestamps' => ini_get( 'opcache.validate_timestamps' ),
				'opcache_revalidate_freq' => ini_get( 'opcache.revalidate_freq' ),
			) ) ) );
			return $response;
		},
	) );
} );
