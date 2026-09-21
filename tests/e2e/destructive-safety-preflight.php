<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM116_STAGE_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Preflight requires disposable-project opt-in.' );
}
$artifact = fopen( $argv[1] ?? '', 'x' );
if ( false === $artifact ) {
	throw new RuntimeException( 'Preflight evidence collision.' );
}
$report = array( 'passed' => false, 'source_sha' => getenv( 'WSTM116_SOURCE_SHA' ) );
try {
	$_SERVER['HTTP_HOST'] = 'localhost';
	require_once '/var/www/html/wp-load.php';
	require_once __DIR__ . '/destructive-safety-fixture.php';
	$registered = array_values( array_filter( array_keys( wp_get_abilities() ), static fn( $name ) => 0 === strpos( $name, 'webmastery-site-toolkit-for-mcp/' ) ) );
	$cases = json_decode( file_get_contents( __DIR__ . '/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
	$manifest = array_values( array_unique( array_column( $cases, 'ability' ) ) );
	sort( $registered );
	sort( $manifest );
	$report['registered'] = $registered;
	$report['manifest'] = $manifest;
	wstm116_require( 85 === count( $registered ) && $registered === $manifest, 'Native registration audit failed before test-only MU installation.' );
	wstm116_require( ! defined( 'WSTM116_DISPOSABLE_RUNTIME' ) && ! function_exists( 'wstm118_probe' ), 'Test-only runtime fixture already installed.' );
	$snapshot = static function (): array {
		global $wpdb;
		$result = array();
		foreach ( $wpdb->tables( 'all', true ) as $table ) {
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
			wstm116_require( '' === $wpdb->last_error && is_array( $rows ), 'Cannot snapshot bootstrap denial tables.' );
			$rows = array_map( 'serialize', $rows );
			sort( $rows );
			$result[ $table ] = array( 'count' => count( $rows ), 'sha256' => hash( 'sha256', serialize( $rows ) ) );
		}
		$uploads = wp_get_upload_dir();
		wstm116_require( ! $uploads['error'], 'Cannot inspect bootstrap denial upload directory.' );
		$result['uploads'] = array();
		if ( is_dir( $uploads['basedir'] ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads['basedir'], FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				wstm116_require( ! $file->isLink(), 'Refusing symlinked fixture uploads.' );
				if ( $file->isFile() ) {
					$result['uploads'][ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() );
				}
			}
			ksort( $result['uploads'] );
		}
		return $result;
	};
	$report['before'] = $snapshot();
	$environment = getenv();
	$environment['WSTM116_DISPOSABLE'] = '';
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/destructive-safety-runner.php' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, null, $environment );
	wstm116_require( is_resource( $process ), 'Cannot execute missing-opt-in probe.' );
	fclose( $pipes[0] );
	$report['cli_stdout'] = stream_get_contents( $pipes[1] );
	$report['cli_stderr'] = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$report['cli_status'] = proc_close( $process );
	wstm116_require( 0 !== $report['cli_status'] && false !== strpos( $report['cli_stdout'] . $report['cli_stderr'], 'Set WSTM116_DISPOSABLE=1' ), 'Missing-opt-in guard failed.' );
	$response = wp_remote_get( 'http://localhost/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/destructive-safety-runner.php' );
	wstm116_require( ! is_wp_error( $response ), 'Cannot execute non-CLI guard probe.' );
	$report['http_status'] = wp_remote_retrieve_response_code( $response );
	$report['http_body'] = wp_remote_retrieve_body( $response );
	wstm116_require( 403 === $report['http_status'] && 'CLI only.' === $report['http_body'], 'Runner must reject HTTP before bootstrap.' );
	wp_cache_flush();
	$report['after'] = $snapshot();
	wstm116_require( $report['before'] === $report['after'], 'Bootstrap denials mutated database or uploaded files.' );
	$report['passed'] = true;
} catch ( Throwable $error ) {
	$report['error'] = $error->getMessage();
	fwrite( STDERR, $error->getMessage() . "\n" );
} finally {
	$data = json_encode( $report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR );
	if ( strlen( $data ) !== fwrite( $artifact, $data ) || ! fflush( $artifact ) ) {
		throw new RuntimeException( 'Cannot retain preflight evidence.' );
	}
	fclose( $artifact );
}
exit( $report['passed'] ? 0 : 1 );
