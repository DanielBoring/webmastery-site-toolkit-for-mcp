<?php

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/e2e/untrusted-content-files.php';

$require = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( 'Synthetic outer retention assertion: ' . $message ); }
};
$fault = $argv[1] ?? '';
$checkout = (string) getenv( 'WSTM108_MOCK_CHECKOUT' );
$live = (string) getenv( 'WSTM108_MOCK_LIVE' );
$project = (string) getenv( 'COMPOSE_PROJECT_NAME' );
$guard = Wstm108_Files::file( $checkout . '/build/wstm116-retention-' . $project );
$require( 1 === preg_match( '/^owner=([a-f0-9]{32})$/m', $guard['bytes'], $owner ), 'original owned guard retained' );
$directory = (string) getenv( 'WSTM108_HOST_AUTHORITY_ROOT' ) . '/wstm108-authority-' . $project . '-' . $owner[1];
Wstm108_Files::directory( $directory );
$action = in_array( $fault, array( 'partial-spool', 'partial-journal', 'pending-parser' ), true ) ? 'runner-proof' : 'runner';
if ( in_array( $fault, array( 'bad-anchor', 'truncated-anchor', 'acquire-stderr', 'failed-acquire' ), true ) ) { $action = 'acquire'; }
if ( 'bad-prepare' === $fault ) { $action = 'finalize'; }
$receipt = json_decode( Wstm108_Files::file( $directory . '/' . $action . '.process.receipt.json' )['bytes'], true, 512, JSON_THROW_ON_ERROR );
$require( false === $receipt['validated'] && true === $receipt['capture_complete'], 'failed verdict with complete original process capture' );
$streams = array();
foreach ( array( 'stdout', 'stderr' ) as $stream ) {
	$file = Wstm108_Files::read_bound( $directory . '/' . $action . '.' . $stream . '.private', $receipt[ $stream ]['identity'] );
	$require( $file['sha256'] === $receipt[ $stream ]['sha256'] && strlen( $file['bytes'] ) === $receipt[ $stream ]['length'], 'private original stream digest/length' );
	$streams[ $stream ] = $file['bytes'];
}
foreach ( array( 'pre-handler-stdout' => array( 'stdout', 'unknown-bootstrap-secret' ), 'provider-stderr' => array( 'stderr', 'unknown-provider-secret' ),
	'extra-line' => array( 'stdout', 'unknown-extra-secret' ), 'acquire-stderr' => array( 'stderr', 'unknown-acquire-secret' ) ) as $kind => $expected ) {
	if ( $fault === $kind ) { $require( false !== strpos( $streams[ $expected[0] ], $expected[1] ), 'unregistered diagnostic retained privately' ); }
}
if ( 'both-streams' === $fault ) {
	$require( false !== strpos( $streams['stdout'], 'unknown-bootstrap-secret' ) && false !== strpos( $streams['stderr'], 'unknown-provider-secret' ), 'both original diagnostic streams' );
}
if ( in_array( $fault, array( 'http500', 'malformed-json', 'failed-valid-json' ), true ) ) {
	$require( 44 === $receipt['child_exit'], 'original nonzero child status' );
	$expected = 'malformed-json' === $fault ? "{\"private\":\"unknown-original-secret\",\xff" : '{"private":"unknown-original-secret","valid":"json","wrong":"semantic"}';
	$require( $expected === Wstm108_Files::file( $live . '/original-wire.private' )['bytes'], 'exact failed original body, including malformed UTF-8' );
	$metadata = json_decode( Wstm108_Files::file( $live . '/original-wire-metadata.private.json' )['bytes'], true, 512, JSON_THROW_ON_ERROR );
	$require( ( 'http500' === $fault ? 500 : 200 ) === $metadata['status'] && hash( 'sha256', $expected ) === $metadata['body_sha256'], 'original HTTP status/body binding' );
	$proof = json_decode( Wstm108_Files::file( $checkout . '/e2e-artifacts/untrusted-' . $owner[1] . '/runner.json' )['bytes'], true, 512, JSON_THROW_ON_ERROR );
	$require( 285 === $proof['passed'] && 1 === $proof['failed'] && 'failed' === $proof['status']
		&& true === $proof['cleanup_complete'] && false === $proof['cases'][100]['passed'], 'failed case/count survives successful resource cleanup' );
}
foreach ( glob( $checkout . '/e2e-artifacts/untrusted-' . $owner[1] . '/*' ) as $path ) {
	$bytes = Wstm108_Files::file( $path )['bytes'];
	$require( 0 === preg_match( '/unknown-(?:original|bootstrap|provider|extra|acquire)-secret|unknown-private-cookie/', $bytes ), 'public evidence excludes unvalidated originals' );
}
$require( ! file_exists( $directory . '/retirement.receipt.json' ), 'failed run cannot issue a retirement receipt' );
echo "Verified synthetic outer retention, exact private failed evidence and public exclusion.\n";
