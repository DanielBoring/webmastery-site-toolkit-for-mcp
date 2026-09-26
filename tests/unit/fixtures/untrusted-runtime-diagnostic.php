<?php

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/e2e/untrusted-content-files.php';

/** Finite diagnostics for the explicitly copied synthetic package fixture only. */
final class Wstm108_SyntheticDiagnostic {
	public const AUTHORITY_SOURCES = array(
		1 => 'tests/unit/fixtures/untrusted-authority-controls.php',
		2 => 'tests/unit/fixtures/untrusted-release-controls.php',
		3 => 'scripts/untrusted-authority.php',
		4 => 'scripts/untrusted-host-topology.php',
		5 => 'tests/e2e/untrusted-content-files.php',
		6 => 'scripts/untrusted-release.php',
		7 => 'tests/e2e/untrusted-content-provenance.php',
		8 => 'tests/unit/fixtures/untrusted-host-boundaries.php',
	);
	public const AUTHORITY_CONTROLS = array(
		1 => 'bootstrap', 2 => 'directories', 3 => 'authority', 4 => 'successful-capture',
		5 => 'failed-capture', 6 => 'partial-write-setup', 7 => 'partial-write-capture',
		8 => 'anchor-replacement', 9 => 'release-setup', 10 => 'release-case-setup',
		11 => 'primary-arm', 12 => 'companion-arm', 13 => 'process-chain',
		14 => 'clear-primary', 15 => 'authorize', 16 => 'mutation',
		17 => 'commit', 18 => 'authorization-readback',
	);
	public const AUTHORITY_CASES = array(
		1 => 'partial-companion', 2 => 'partial-clear-capture', 3 => 'replacement',
		4 => 'mode', 5 => 'receipt-replacement', 6 => 'guard-false',
		7 => 'guard-throws', 8 => 'prerequisite-noise', 9 => 'success', 10 => 'postcommit-fault',
	);
	private static int $authority_control = 1;
	private static int $authority_case = 0;

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
		self::write_value( self::project( $error ), $directory, 'terminal.json' );
	}

	public static function authority_checkpoint( int $control, int $case = 0 ): void {
		self::require( isset( self::AUTHORITY_CONTROLS[ $control ] )
			&& ( $control <= 9 ? 0 === $case : isset( self::AUTHORITY_CASES[ $case ] ) ) );
		self::$authority_control = $control;
		self::$authority_case = $case;
	}

	public static function authority_location( string $file, int $line, string $class ): array {
		// Zero source/line/class means unavailable; case zero means no release case.
		$source = 0;
		foreach ( self::AUTHORITY_SOURCES as $id => $relative ) {
			if ( dirname( __DIR__, 3 ) . '/' . $relative === $file && $line >= 1 && $line <= 9999 ) { $source = $id; break; }
		}
		$classes = array( RuntimeException::class => 1, LogicException::class => 2, Error::class => 3, TypeError::class => 4, ParseError::class => 5 );
		return array( 'version' => 1, 'scope' => 'synthetic-authority-only', 'source_id' => $source,
			'control_id' => self::$authority_control, 'case_id' => self::$authority_case,
			'line' => 0 === $source ? 0 : $line, 'class_id' => $classes[ $class ] ?? 0 );
	}

	public static function record_authority( Throwable $error, string $directory ): void {
		$root = getenv( 'WSTM108_MOCK_ROOT' );
		self::require( 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' )
			&& '1' === getenv( 'WSTM108_MOCK_ONLY' ) && is_string( $root )
			&& realpath( $root ) === $root && $directory === $root . '/first-package-diagnostic' );
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) { self::require( mkdir( $directory, 0700 ) ); }
		self::write_value( self::authority_location( $error->getFile(), $error->getLine(), get_class( $error ) ), $directory, 'authority.json' );
	}

	public static function validate_authority( array $value ): array {
		self::require( array_keys( $value ) === array( 'version', 'scope', 'source_id', 'control_id', 'case_id', 'line', 'class_id' )
			&& 1 === $value['version'] && 'synthetic-authority-only' === $value['scope'] );
		foreach ( array( 'source_id', 'control_id', 'case_id', 'line', 'class_id' ) as $key ) { self::require( is_int( $value[ $key ] ) ); }
		self::require( $value['source_id'] >= 0 && $value['source_id'] <= 8
			&& isset( self::AUTHORITY_CONTROLS[ $value['control_id'] ] )
			&& ( $value['control_id'] <= 9 ? 0 === $value['case_id'] : isset( self::AUTHORITY_CASES[ $value['case_id'] ] ) )
			&& ( 0 === $value['source_id'] ? 0 === $value['line'] : ( $value['line'] >= 1 && $value['line'] <= 9999 ) )
			&& $value['class_id'] >= 0 && $value['class_id'] <= 5 );
		return $value;
	}

	public static function read_authority( string $directory, int $observed_exit ): ?array {
		$root = getenv( 'WSTM108_MOCK_ROOT' );
		self::require( 'Linux' === PHP_OS_FAMILY && '1' === getenv( 'WSTM108_MOCK_ONLY' )
			&& is_string( $root ) && realpath( $root ) === $root && $directory === $root . '/first-package-diagnostic'
			&& $observed_exit >= 1 && $observed_exit <= 255 );
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) { return null; }
		$value = self::read_value( $directory, 'authority.json' );
		if ( null === $value ) { return null; }
		// The observer rethrows the original uncaught Throwable; its code is not the native exit.
		self::require( 255 === $observed_exit );
		return self::validate_authority( $value );
	}

	private static function write_value( array $value, string $directory, string $name ): void {
		$parent = self::directory( $directory );
		self::require( function_exists( 'fsync' ) && ( new ReflectionFunction( 'fsync' ) )->isInternal() );
		$bytes = json_encode( $value, JSON_THROW_ON_ERROR ) . "\n";
		self::require( strlen( $bytes ) <= 1024 );
		$path = $directory . '/' . $name;
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
		self::require( $observed_exit > 0 && $observed_exit <= 255 );
		$value = self::read_value( $directory, 'terminal.json' );
		if ( null === $value ) { return null; }
		self::require( array_keys( $value ) === array( 'version', 'scope', 'reason_code', 'exception_exit' )
			&& 1 === $value['version'] && 'synthetic-only' === $value['scope']
			&& is_int( $value['reason_code'] ) && $value['reason_code'] >= 0 && $value['reason_code'] <= 36
			&& $observed_exit === $value['exception_exit'] );
		return $value['reason_code'];
	}

	private static function read_value( string $directory, string $name ): ?array {
		$parent = self::directory( $directory );
		$path = $directory . '/' . $name;
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
		self::require( is_array( $value ) && $bytes === json_encode( $value, JSON_THROW_ON_ERROR ) . "\n" );
		return $value;
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		if ( 4 !== count( $argv ) || ! in_array( $argv[1], array( 'read', 'read-authority' ), true ) || 1 !== preg_match( '/^[1-9][0-9]{0,2}$/D', $argv[3] ) ) {
			throw new RuntimeException( 'Explicit synthetic readback required.' );
		}
		if ( 'read-authority' === $argv[1] ) {
			$value = Wstm108_SyntheticDiagnostic::read_authority( $argv[2], (int) $argv[3] );
			if ( null === $value ) { exit( 2 ); }
			echo implode( ' ', array( $value['source_id'], $value['control_id'], $value['case_id'], $value['line'], $value['class_id'] ) ) . "\n";
			exit( 0 );
		}
		$code = Wstm108_SyntheticDiagnostic::read( $argv[2], (int) $argv[3] );
		if ( null === $code ) { exit( 2 ); }
		echo $code . "\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, "Synthetic diagnostic readback refused.\n" );
		exit( 3 );
	}
}
