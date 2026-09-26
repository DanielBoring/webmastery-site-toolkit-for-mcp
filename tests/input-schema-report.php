<?php

declare(strict_types=1);

require_once __DIR__ . '/unit/fixtures/input-schema-report.php';

if ( 7 !== $argc ) { throw new RuntimeException( 'Expected isolated mock arguments.' ); }
$project = getenv( 'COMPOSE_PROJECT_NAME' );
if ( ! is_string( $project ) || '' === $project ) { throw new RuntimeException( 'Missing mock project identity.' ); }
$report = wstm126_fake_report( $argv[2], $argv[3], $project, $argv[4] );
switch ( $argv[5] ) {
	case 'case':
		$report['cases'][0]['passed'] = false;
		$report['cases'][0]['error'] = 'Mock case failure';
		$report['passed']--;
		$report['failed']++;
		break;
	case 'missing': exit( 0 );
	case 'partial': array_pop( $report['cases'] ); break;
	case 'foreign': $report['project'] = 'foreign-project'; break;
	case 'cleanup': $report['cleanup_proof']['actors_absent'] = false; break;
	case 'hash': $report['source_hashes']['production']['includes/class-input.php'] = str_repeat( '0', 64 ); break;
	case 'malformed': file_put_contents( $argv[1], '{' ); exit( 0 );
}
if ( 'package' === $argv[6] ) {
	$report['source_hashes'] = wstm126_source_hashes( getenv( 'E2E_PACKAGE_ROOT' ), dirname( __DIR__ ) );
	foreach ( $report['source_hashes']['production'] as $path => $hash ) {
		if ( isset( $report['hashes'][ basename( $path ) ] ) ) { $report['hashes'][ basename( $path ) ] = $hash; }
	}
}
file_put_contents( $argv[1], json_encode( $report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
