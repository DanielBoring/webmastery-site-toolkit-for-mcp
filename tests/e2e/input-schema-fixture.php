<?php
/**
 * Explicitly disposable MU fixture. Counts only work inside registered callbacks.
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WSTM126_DISPOSABLE_RUNTIME' ) || true !== WSTM126_DISPOSABLE_RUNTIME ) {
	return;
}
require_once __DIR__ . '/metadata-batch-fixture.php';

function wstm126_require( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

function wstm126_assert_no_work( array $before, array $after, array $evidence ): void {
	wstm126_require( isset( $evidence['callbacks'], $evidence['mutations'] ) && is_array( $evidence['callbacks'] ) && is_array( $evidence['mutations'] ), 'Missing or malformed observer evidence.' );
	wstm126_require( $before === $after, 'Rejected input changed persistent state.' );
	wstm126_require( array() === $evidence['mutations'], 'Rejected input reached mutation hooks.' );
	foreach ( $evidence['callbacks'] as $callback ) {
		wstm126_require( is_array( $callback ) && isset( $callback['queries'], $callback['capabilities'] ), 'Missing callback counters.' );
		wstm126_require( 0 === $callback['queries'] && 0 === $callback['capabilities'], 'Malformed input reached queries or capability hooks.' );
	}
}

add_filter( 'wp_register_ability_args', static function ( $args, $name ) {
	if ( ! Webmastery_MCP_Response::owns( $name ) ) { return $args; }
	foreach ( array( 'permission_callback', 'execute_callback' ) as $key ) {
		if ( ! is_callable( $args[ $key ] ?? null ) ) { continue; }
		$callback = $args[ $key ];
		$args[ $key ] = static function ( ...$input ) use ( $callback, $key, $name ) {
			if ( ! isset( $GLOBALS['wstm126_evidence'] ) ) { return $callback( ...$input ); }
			$counts = array( 'ability' => $name, 'stage' => $key, 'queries' => 0, 'capabilities' => 0 );
			$query = static function ( $sql ) use ( &$counts ) { $counts['queries']++; return $sql; };
			$capability = static function ( $caps ) use ( &$counts ) { $counts['capabilities']++; return $caps; };
			add_filter( 'query', $query, PHP_INT_MIN );
			add_filter( 'map_meta_cap', $capability, PHP_INT_MIN );
			try {
				return $callback( ...$input );
			} finally {
				remove_filter( 'query', $query, PHP_INT_MIN );
				remove_filter( 'map_meta_cap', $capability, PHP_INT_MIN );
				$GLOBALS['wstm126_evidence']['callbacks'][] = $counts;
			}
		};
	}
	return $args;
}, PHP_INT_MAX, 2 );

function wstm126_begin(): Closure {
	$GLOBALS['wstm126_evidence'] = array( 'callbacks' => array(), 'mutations' => array() );
	return wstm110_batch_observe( static function ( $hook ) { $GLOBALS['wstm126_evidence']['mutations'][] = $hook; } );
}

function wstm126_end( Closure $observer ): array {
	wstm110_batch_unobserve( $observer );
	$evidence = $GLOBALS['wstm126_evidence'];
	unset( $GLOBALS['wstm126_evidence'] );
	return $evidence;
}

add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) {
	$config = get_option( 'wstm126_http', array() );
	$token = $request->get_header( 'X-WSTM110-Observation' );
	if ( ! is_string( $token ) || '' === $token || empty( $config['token'] )
		|| ! hash_equals( $config['token'], $token ) || get_current_user_id() !== (int) ( $config['user_id'] ?? 0 ) ) {
		return $result;
	}
	$observer = wstm126_begin();
	$finish = null;
	$finish = static function ( $response, $response_server, $response_request ) use ( $observer, $request, &$finish ) {
		if ( $request === $response_request ) {
			$evidence = wstm126_end( $observer );
			remove_filter( 'rest_post_dispatch', $finish, PHP_INT_MAX );
			// Reuse the existing transport's authenticated observation channel.
			$response->header( 'X-WSTM110-Mutation-Events', base64_encode( wp_json_encode( $evidence ) ) );
		}
		return $response;
	};
	add_filter( 'rest_post_dispatch', $finish, PHP_INT_MAX, 3 );
	return $result;
}, PHP_INT_MIN, 3 );
