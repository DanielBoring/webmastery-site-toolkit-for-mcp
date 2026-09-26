<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/tests/e2e/input-schema-proof.php';

try {
	if ( 9 !== $argc || ! in_array( $argv[1], array( 'cleanup', 'outcome' ), true ) ) {
		throw new RuntimeException( 'Usage: input-schema-proof.php cleanup|outcome report owner source project boundary production-root harness-root' );
	}
	if ( ! is_file( $argv[2] ) || is_link( $argv[2] ) ) { throw new RuntimeException( 'Missing or linked schema artifact.' ); }
	$report = json_decode( file_get_contents( $argv[2] ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $report ) ) { throw new RuntimeException( 'Malformed schema artifact.' ); }
	wstm126_validate_invocation( $report, $argv[3], $argv[4], $argv[5], $argv[6] );
	if ( wstm126_source_hashes( $argv[7], $argv[8] ) !== $report['source_hashes'] ) {
		throw new RuntimeException( 'Schema production/package or read-only harness identity mismatch.' );
	}
	if ( 'outcome' === $argv[1] && 0 !== $report['failed'] ) { throw new RuntimeException( 'Schema cases failed despite independently verified cleanup.' ); }
	echo "PASS schema {$argv[1]} proof: {$argv[6]}, {$report['passed']}/152 cases, owner {$argv[3]}\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL schema proof: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
