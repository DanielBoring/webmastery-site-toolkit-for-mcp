<?php
/**
 * Disposable MU fixture: opt-in request-scoped metadata mutation evidence.
 */

defined( 'ABSPATH' ) || exit;
require_once WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/tests/e2e/metadata-batch-fixture.php';

add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) {
	$config = get_option( 'wstm110_batch_http', array() );
	$token = $request->get_header( 'X-WSTM110-Observation' );
	if ( ! is_string( $token ) || '' === $token || empty( $config['token'] )
		|| ! hash_equals( $config['token'], $token ) || get_current_user_id() !== (int) ( $config['user_id'] ?? 0 ) ) {
		return $result;
	}
	$events = array();
	$observer = wstm110_batch_observe( static function ( $hook ) use ( &$events ): void { $events[] = $hook; } );
	$finish = null;
	$finish = static function ( $response, $response_server, $response_request ) use ( &$events, $observer, $request, &$finish ) {
		if ( $request === $response_request ) {
			wstm110_batch_unobserve( $observer );
			remove_filter( 'rest_post_dispatch', $finish, PHP_INT_MAX );
			$response->header( 'X-WSTM110-Mutation-Events', base64_encode( wp_json_encode( $events ) ) );
		}
		return $response;
	};
	add_filter( 'rest_post_dispatch', $finish, PHP_INT_MAX, 3 );
	return $result;
}, PHP_INT_MIN, 3 );
