<?php

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/e2e/untrusted-content-files.php';

/** Finite diagnostics for the explicitly copied synthetic package fixture only. */
final class Wstm108_SyntheticDiagnostic {
	public const REASONS = array(
		1 => 'noncanonical-path',
		2 => 'unknown-mount-escape',
		3 => 'partial-mount-table',
		4 => 'mount-table-limit',
		5 => 'malformed-mount-record',
		6 => 'ambiguous-stacked-mount',
		7 => 'unsupported-mount-mapping',
		8 => 'missing-namespace-root',
		9 => 'unsupported-filesystem-coordinate',
		10 => 'nonstring-bind-source',
		11 => 'physical-bind-exposes-authority',
		12 => 'native-coordinate-prerequisite',
		13 => 'coordinate-path-changed',
		14 => 'unsupported-native-device',
		15 => 'kernel-coordinate-or-identity-disagrees',
		16 => 'incomplete-directory-identity',
		17 => 'unowned-child-creation-transition',
		18 => 'unreadable-kernel-evidence',
		19 => 'unreadable-kernel-identity',
		20 => 'unsupported-daemon-owner',
		21 => 'ambiguous-process-identity',
		22 => 'missing-process-start-identity',
		23 => 'ambiguous-selected-listener',
		24 => 'selected-listener-not-unique',
		25 => 'daemon-descriptors-inaccessible',
		26 => 'daemon-descriptor-race-or-denial',
		27 => 'pid-hint-does-not-own-selected-listener',
		28 => 'daemon-mount-namespace-differs',
		29 => 'daemon-identity-changed-during-read',
		30 => 'native-linux-prerequisite',
		31 => 'nonlocal-endpoint',
		32 => 'unrecognized-socket-alias',
		33 => 'nonroot-or-nonsocket-endpoint',
		34 => 'untrusted-pid-discovery-hint',
		35 => 'daemon-mount-table-differs',
		36 => 'peer-or-topology-changed-during-admission',
	);

	private static function require( bool $condition ): void {
		if ( ! $condition ) { throw new RuntimeException( 'Synthetic diagnostic refused.' ); }
	}

	public static function project( Throwable $error ): array {
		$exit = $error->getCode() > 0 && $error->getCode() <= 255 ? (int) $error->getCode() : 1;
		$code = 0;
		for ( $depth = 0; null !== $error && $depth < 8; ++$depth, $error = $error->getPrevious() ) {
			foreach ( self::REASONS as $candidate => $reason ) {
				if ( 'WSTM108 BLOCKED topology: ' . $reason === $error->getMessage() ) {
					$code = $candidate;
					break 2;
				}
			}
		}
		return array( 'version' => 1, 'scope' => 'synthetic-only', 'reason_code' => $code, 'exception_exit' => $exit );
	}

	private static function directory( string $path ): array {
		self::require( 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' )
			&& '1' === getenv( 'WSTM108_MOCK_ONLY' ) );
		$root = getenv( 'WSTM108_MOCK_ROOT' );
		self::require( is_string( $root ) && realpath( $root ) === $root
			&& $path === $root . '/first-package-diagnostic' );
		$identity = Wstm108_Files::directory( $path );
		self::require( 0040700 === $identity['mode'] && posix_geteuid() === $identity['uid'] );
		return $identity;
	}

	private static function file_identity( array $stat ): array {
		$result = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size' ) ) );
		self::require( count( $result ) === 7 && 0100600 === $result['mode'] && 1 === $result['nlink']
			&& posix_geteuid() === $result['uid'] && $result['size'] <= 4096 );
		return $result;
	}

	public static function record( Throwable $error, string $directory ): void {
		$parent = self::directory( $directory );
		self::require( function_exists( 'fsync' ) && ( new ReflectionFunction( 'fsync' ) )->isInternal() );
		$bytes = json_encode( self::project( $error ), JSON_THROW_ON_ERROR ) . "\n";
		self::require( strlen( $bytes ) <= 1024 );
		$path = $directory . '/terminal.json';
		$mask = umask( 0077 );
		try { $handle = @fopen( $path, 'x+b' ); } finally { umask( $mask ); }
		self::require( is_resource( $handle ) );
		try {
			$stat = fstat( $handle );
			self::require( is_array( $stat ) && 0 === self::file_identity( $stat )['size']
				&& $parent === self::directory( $directory ) );
			$identity = self::file_identity( $stat );
			for ( $offset = 0; $offset < strlen( $bytes ); $offset += $written ) {
				$written = fwrite( $handle, substr( $bytes, $offset ) );
				self::require( is_int( $written ) && $written > 0 );
			}
			self::require( fflush( $handle ) && fsync( $handle ) );
			clearstatcache( true, $path );
			$current = lstat( $path );
			$opened = fstat( $handle );
			self::require( is_array( $current ) && is_array( $opened )
				&& self::file_identity( $current ) === self::file_identity( $opened )
				&& array_diff_key( $identity, array( 'size' => true ) ) === array_diff_key( self::file_identity( $opened ), array( 'size' => true ) )
				&& strlen( $bytes ) === $opened['size'] && $parent === self::directory( $directory ) );
			self::require( rewind( $handle ) && $bytes === stream_get_contents( $handle, 4097 ) );
		} finally { self::require( fclose( $handle ) ); }
	}

	public static function read( string $directory, int $observed_exit ): ?int {
		$parent = self::directory( $directory );
		self::require( $observed_exit > 0 && $observed_exit <= 255 );
		$path = $directory . '/terminal.json';
		clearstatcache( true, $path );
		if ( ! file_exists( $path ) && ! is_link( $path ) ) { return null; }
		$before = lstat( $path );
		self::require( is_array( $before ) );
		$identity = self::file_identity( $before );
		$handle = @fopen( $path, 'rb' );
		self::require( is_resource( $handle ) );
		try {
			self::require( flock( $handle, LOCK_SH | LOCK_NB ) );
			$opened = fstat( $handle );
			self::require( is_array( $opened ) && $identity === self::file_identity( $opened ) );
			$bytes = stream_get_contents( $handle, 4097 );
			$after = fstat( $handle );
			clearstatcache( true, $path );
			$current = lstat( $path );
			self::require( is_string( $bytes ) && strlen( $bytes ) === $identity['size']
				&& is_array( $after ) && is_array( $current )
				&& $identity === self::file_identity( $after ) && $identity === self::file_identity( $current )
				&& $parent === self::directory( $directory ) );
		} finally { self::require( fclose( $handle ) ); }
		$value = json_decode( $bytes, true, 8, JSON_THROW_ON_ERROR );
		self::require( is_array( $value ) && array_keys( $value ) === array( 'version', 'scope', 'reason_code', 'exception_exit' )
			&& 1 === $value['version'] && 'synthetic-only' === $value['scope']
			&& is_int( $value['reason_code'] ) && $value['reason_code'] >= 0 && $value['reason_code'] <= 36
			&& $observed_exit === $value['exception_exit']
			&& $bytes === json_encode( $value, JSON_THROW_ON_ERROR ) . "\n" );
		return $value['reason_code'];
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		if ( 4 !== count( $argv ) || 'read' !== $argv[1] || 1 !== preg_match( '/^[1-9][0-9]{0,2}$/D', $argv[3] ) ) {
			throw new RuntimeException( 'Explicit synthetic readback required.' );
		}
		$code = Wstm108_SyntheticDiagnostic::read( $argv[2], (int) $argv[3] );
		if ( null === $code ) { exit( 2 ); }
		echo $code . "\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, "Synthetic diagnostic readback refused.\n" );
		exit( 3 );
	}
}
