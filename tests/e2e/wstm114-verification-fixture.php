<?php

function wstm114_verification_prepare( array $case ): array {
	$key = 'webmastery_mcp_verification_v1_' . hash( 'sha256', get_current_blog_id() . ':' . home_url( '/' ) );
	if ( ! empty( $case['setup']['wstm114_reset_cache'] ) ) {
		delete_transient( $key );
	}
	$state = (object) array( 'http_calls' => 0 );
	$count = static function ( $preempt ) use ( $state ) {
		++$state->http_calls;
		return $preempt;
	};
	add_filter( 'pre_http_request', $count, 1 );
	return array( 'key' => $key, 'state' => $state, 'callback' => $count );
}

function wstm114_verification_assert( array $case, $result, array $fixture ): bool {
	remove_filter( 'pre_http_request', $fixture['callback'], 1 );
	if ( $fixture['state']->http_calls !== $case['assert_wstm114_http_calls'] ) {
		echo "FAIL wstm114 HTTP count: expected {$case['assert_wstm114_http_calls']}, got {$fixture['state']->http_calls}\n";
		return false;
	}
	if ( is_wp_error( $result ) ) {
		return 'failure' === $case['expect'];
	}
	$data = $result['data'];
	$summary = array( 'pass' => 0, 'warn' => 0, 'unknown' => 0 );
	foreach ( $data['checks'] as $check ) {
		++$summary[ $check['status'] ];
	}
	if ( $summary !== $data['summary'] ) {
		echo "FAIL wstm114 caller-visible summary\n";
		return false;
	}
	if ( ! current_user_can( 'activate_plugins' ) && ( 7 !== count( $data['checks'] ) || false !== strpos( wp_json_encode( $data ), 'site_kit' ) ) ) {
		echo "FAIL wstm114 private projection\n";
		return false;
	}
	$public = get_transient( $fixture['key'] );
	if ( ! is_array( $public ) || array( 'home_reached', 'checks' ) !== array_keys( $public ) || 7 !== count( $public['checks'] ) || false !== strpos( wp_json_encode( $public ), 'site_kit' ) ) {
		echo "FAIL wstm114 shared cache must contain only public checks\n";
		return false;
	}
	return true;
}
