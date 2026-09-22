<?php

declare(strict_types=1);

function wstm116_file_diagnostics( string $path ): array {
	clearstatcache( true, $path );
	clearstatcache( true, dirname( $path ) );
	$exists = file_exists( $path );
	$result = array(
		'path' => $path, 'exists' => $exists, 'realpath' => realpath( $path ),
		'effective_uid' => function_exists( 'posix_geteuid' ) ? posix_geteuid() : null,
		'file_uid' => $exists ? fileowner( $path ) : null,
		'file_gid' => $exists ? filegroup( $path ) : null,
		'file_mode' => $exists ? fileperms( $path ) & 0777 : null,
		'directory' => dirname( $path ), 'directory_uid' => fileowner( dirname( $path ) ),
		'directory_gid' => filegroup( dirname( $path ) ), 'directory_mode' => fileperms( dirname( $path ) ) & 0777,
		'directory_writable' => is_writable( dirname( $path ) ),
		'sha256' => is_file( $path ) ? hash_file( 'sha256', $path ) : null,
	);
	if ( false === $result['sha256'] ) {
		throw new RuntimeException( 'Cannot read owned file for deletion diagnostics.' );
	}
	return $result;
}

function wstm116_boot_diagnostics( int $status, string $body ): array {
	$decoded = json_decode( $body, true );
	$result = array( 'status' => $status, 'body_sha256' => hash( 'sha256', $body ), 'body_bytes' => strlen( $body ) );
	// Retain the public REST error, not arbitrary HTML/debug output or headers.
	if ( is_array( $decoded ) && is_string( $decoded['code'] ?? null ) && is_string( $decoded['message'] ?? null ) ) {
		$result['rest_error'] = array( 'code' => $decoded['code'], 'message' => $decoded['message'] );
	}
	return $result;
}

function wstm116_file_observer( string $owner, array &$operations ): Closure {
	return static function ( $file ) use ( $owner, &$operations ) {
		if ( is_string( $file ) && 0 === strpos( basename( $file ), $owner . '-' ) ) {
			$operations[] = wstm116_file_diagnostics( $file );
		}
		return $file;
	};
}
