<?php
/** Independent, read-only, owner-authenticated HTTP runtime sampler. */
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WSTM126_PROBE_OWNER' ) || ! defined( 'WSTM126_PROBE_SOURCE' ) || ! defined( 'WSTM126_PROBE_PROJECT' ) || ! defined( 'WSTM126_PROBE_KEY_HASH' ) ) {
	return;
}
require_once __DIR__ . '/input-schema-boot.php';
add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'wstm126-stage', '/boot', array(
		'methods' => 'GET',
		'permission_callback' => static function ( $request ) {
			return Wstm126_Boot::authorized( $request->get_method(), $request->get_header( 'X-WSTM126-Stage' ), WSTM126_PROBE_KEY_HASH )
				? true : new WP_Error( 'wstm126_probe_denied', 'Owned GET probe required.', array( 'status' => 403 ) );
		},
		'callback' => static function () {
			$response = new WP_REST_Response( Wstm126_Boot::body( rtrim( ABSPATH, '/\\' ), dirname( __DIR__, 2 ), WSTM126_PROBE_OWNER, WSTM126_PROBE_SOURCE, WSTM126_PROBE_PROJECT ) );
			$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
			return $response;
		},
	) );
} );
