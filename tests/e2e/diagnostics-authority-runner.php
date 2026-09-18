<?php

$args = array( 'all' );
ob_start();
require __DIR__ . '/diagnostics-runner.php';
$regressions = json_decode( ob_get_clean(), true, 512, JSON_THROW_ON_ERROR );
$GLOBALS['wstm111_results'] = array();
wp_set_current_user( (int) get_user_by( 'login', 'admin' )->ID );
$cases_file = dirname( __DIR__ ) . '/fixtures/diagnostics-authority.json';
$cases = json_decode( file_get_contents( $cases_file ), true, 512, JSON_THROW_ON_ERROR );

foreach ( array( 'direct', 'wrapper' ) as $mode ) {
	foreach ( $cases as $case ) {
		$scenario = array( 'home' => $case['home'], 'https' => 'pass' !== $case['bucket'], 'admin_ssl' => true );
		$call = wstm111_invoke( 'security-audit', $mode, $scenario );
		$actual = wstm111_finding( $call['result'], 'ssl' );
		$response = $call['result'];
		$shape = true === $response['success'] && array( 'summary', 'fail', 'warn', 'pass' ) === array_keys( $response['data'] );
		foreach ( array( 'pass', 'warn', 'fail' ) as $bucket ) {
			$shape = $shape && count( $response['data'][ $bucket ] ) === $response['data']['summary'][ $bucket ];
		}
		wstm111_record( "authority/{$mode}/{$case['id']}", array(
			'expected_bucket' => $case['bucket'] === $actual['bucket'],
			'configured_label' => str_contains( $actual['finding']['label'], 'Public home URL' ),
			'response_shape' => $shape,
			'no_configured_url' => ! str_contains( wp_json_encode( $actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $case['home'] ),
		) + $call['invariants'], $actual, $call['evidence'] );
	}
}

$report = array(
	'wordpress' => get_bloginfo( 'version' ),
	'php' => PHP_VERSION,
	'runner_sha256' => hash_file( 'sha256', __FILE__ ),
	'cases_sha256' => hash_file( 'sha256', $cases_file ),
	'fixture_sha256' => $regressions['fixture_sha256'],
	'source_sha256' => $regressions['source_sha256'],
	'transport_note' => $regressions['transport_note'],
	'original_direct_regressions' => $regressions,
	'results' => $GLOBALS['wstm111_results'],
);
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
if ( in_array( false, array_column( $report['results'], 'passed' ), true ) ) {
	exit( 1 );
}
