<?php

declare(strict_types=1);

function wstm_php80_floor_route( string $uri, string $root ): string {
	$path = parse_url( $uri, PHP_URL_PATH );
	if ( ! is_string( $path ) || ! str_starts_with( $path, '/' ) ) {
		return 'refuse';
	}
	$path = rawurldecode( $path );
	foreach ( explode( '/', $path ) as $segment ) {
		if ( str_starts_with( $segment, '.' ) || str_contains( $segment, '\\' ) || str_contains( $segment, "\0" )
			|| in_array( $segment, array( 'wp-config.php', 'wp-config-sample.php' ), true ) ) {
			return 'refuse';
		}
	}
	if ( '/_wstm_php80_floor' === $path ) {
		return 'probe';
	}
	$file = realpath( $root . $path );
	if ( false !== $file && is_file( $file ) ) {
		$canonical_root = realpath( $root );
		if ( false === $canonical_root || ! str_starts_with( $file, $canonical_root . DIRECTORY_SEPARATOR ) ) {
			return 'refuse';
		}
		foreach ( explode( DIRECTORY_SEPARATOR, substr( $file, strlen( $canonical_root ) + 1 ) ) as $segment ) {
			if ( str_starts_with( $segment, '.' ) || in_array( $segment, array( 'wp-config.php', 'wp-config-sample.php' ), true ) ) {
				return 'refuse';
			}
		}
		return 'file';
	}
	return 'index';
}

if ( 'cli-server' === PHP_SAPI ) {
	$root = '/var/www/html';
	$route = wstm_php80_floor_route( $_SERVER['REQUEST_URI'] ?? '', $root );
	if ( 'refuse' === $route ) {
		http_response_code( 403 );
		echo "Forbidden.\n";
		return true;
	}
	if ( 'probe' === $route ) {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			http_response_code( 405 );
			header( 'Allow: GET' );
			return true;
		}
		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-store' );
		$hash = hash_file( 'sha256', PHP_BINARY );
		if ( 'Linux' !== PHP_OS_FAMILY || '8.0.30' !== PHP_VERSION || ! extension_loaded( 'mysqli' ) || false === $hash ) {
			http_response_code( 500 );
			echo "{\"error\":\"floor_runtime_mismatch\"}\n";
			return true;
		}
		echo json_encode( array( 'profile' => 'php80-floor', 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'php_binary_sha256' => $hash ), JSON_THROW_ON_ERROR ) . "\n";
		return true;
	}
	if ( 'file' === $route ) {
		return false;
	}
	$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
	$_SERVER['SCRIPT_NAME'] = '/index.php';
	$_SERVER['PHP_SELF'] = '/index.php';
	$index = $root . '/index.php';
	if ( ! is_file( $index ) ) {
		http_response_code( 500 );
		echo "WordPress entrypoint missing.\n";
		return true;
	}
	require $index;
	return true;
}
