<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-runtime-diagnostic.php';
require_once __DIR__ . '/untrusted-host-boundaries.php';

/** Real Linux files/processes, synthetic errors and copied test-only I/O seams. */
final class Wstm108_DiagnosticControls {
	private static function require( bool $condition ): void {
		if ( ! $condition ) { throw new RuntimeException( 'Synthetic diagnostic control failed.' ); }
	}

	private static function create( string $path, string $bytes ): void {
		Wstm108_Files::create( $path, $bytes );
	}

	private static function directory( string $root, string $name, bool $sidecar = true ): string {
		$case = $root . '/' . $name;
		self::require( mkdir( $case, 0700 ) );
		if ( $sidecar ) { self::require( mkdir( $case . '/first-package-diagnostic', 0700 ) ); }
		putenv( 'WSTM108_MOCK_ONLY=1' );
		putenv( 'WSTM108_MOCK_ROOT=' . $case );
		return $case;
	}

	private static function refused( callable $operation ): void {
		try { $operation(); } catch ( RuntimeException | JsonException $error ) { return; }
		throw new LogicException( 'Expected diagnostic refusal did not occur.' );
	}

	private static function run( array $arguments, string $cwd, array $environment, string $stem, bool $stdout_full = false ): array {
		$mask = umask( 0077 );
		try {
			$stdout = $stdout_full ? fopen( '/dev/full', 'wb' ) : fopen( $stem . '.stdout.private', 'x+b' );
			$stderr = fopen( $stem . '.stderr.private', 'x+b' );
		} finally { umask( $mask ); }
		self::require( is_resource( $stdout ) && is_resource( $stderr ) );
		$process = proc_open( $arguments, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => $stdout, 2 => $stderr ), $pipes, $cwd, $environment );
		self::require( is_resource( $process ) );
		$status = proc_close( $process );
		self::require( fclose( $stdout ) && fclose( $stderr ) );
		return array( 'exit' => $status, 'stdout' => $stdout_full ? null : file_get_contents( $stem . '.stdout.private' ), 'stderr' => file_get_contents( $stem . '.stderr.private' ) );
	}

	private static function fault_helper( string $root ): string {
		$source = str_replace( "\r\n", "\n", file_get_contents( __DIR__ . '/untrusted-runtime-diagnostic.php' ) );
		$namespace = <<<'PHP'
namespace Wstm108_DiagnosticFault;
use \Throwable;
use \RuntimeException;
use \ReflectionFunction;
use \Wstm108_Files;
function function_exists( $name ) {
	return 'missing-fsync' === getenv( 'WSTM108_DIAGNOSTIC_IO_FAULT' ) && 'fsync' === $name ? false : \function_exists( $name );
}
function fwrite( $handle, $bytes ) {
	return 'write-zero' === getenv( 'WSTM108_DIAGNOSTIC_IO_FAULT' ) ? 0 : \fwrite( $handle, $bytes );
}
function fflush( $handle ) {
	return 'flush' === getenv( 'WSTM108_DIAGNOSTIC_IO_FAULT' ) ? false : \fflush( $handle );
}
function fsync( $handle ) {
	return 'sync' === getenv( 'WSTM108_DIAGNOSTIC_IO_FAULT' ) ? false : \fsync( $handle );
}
function fclose( $handle ) {
	$result = \fclose( $handle );
	return in_array( getenv( 'WSTM108_DIAGNOSTIC_IO_FAULT' ), array( 'write-close', 'read-close' ), true ) ? false : $result;
}
function lstat( $path ) {
	$result = \lstat( $path );
	if ( 'read-owner' === getenv( 'WSTM108_DIAGNOSTIC_IO_FAULT' ) && basename( $path ) === 'terminal.json' && is_array( $result ) ) { ++$result['uid']; }
	return $result;
}
function stream_get_contents( $handle, $length ) {
	$bytes = \stream_get_contents( $handle, $length );
	$fault = getenv( 'WSTM108_DIAGNOSTIC_IO_FAULT' );
	if ( in_array( $fault, array( 'write-readback', 'read-short' ), true ) ) { return ''; }
	if ( 'read-replacement' === $fault ) {
		$root = getenv( 'WSTM108_MOCK_ROOT' );
		$path = $root . '/first-package-diagnostic/terminal.json';
		if ( ! rename( $path, $root . '/original-record.private' ) || ! rename( $root . '/replacement.private', $path ) ) {
			throw new RuntimeException( 'Synthetic replacement failed.' );
		}
	}
	return $bytes;
}
PHP;
		$needle = 'declare(strict_types=1);';
		self::require( 1 === substr_count( $source, $needle ) );
		$source = str_replace( $needle, $needle . "\n\n" . $namespace, $source );
		$needle = "require_once dirname( __DIR__, 2 ) . '/e2e/untrusted-content-files.php';";
		self::require( 1 === substr_count( $source, $needle ) );
		$source = str_replace( $needle, 'require_once ' . var_export( dirname( __DIR__, 2 ) . '/e2e/untrusted-content-files.php', true ) . ';', $source );
		$path = $root . '/fault-helper.php';
		self::create( $path, $source );
		return $path;
	}

	private static function outer( string $root, string $name ): void {
		$case = self::directory( $root, $name, false );
		self::require( mkdir( $case . '/scripts', 0700 ) && mkdir( $case . '/tests', 0700 )
			&& mkdir( $case . '/tests/unit', 0700 ) && mkdir( $case . '/tests/unit/fixtures', 0700 ) );
		self::create( $case . '/tests/unit/fixtures/untrusted-runtime-diagnostic.php',
			'<?php require_once ' . var_export( __DIR__ . '/untrusted-runtime-diagnostic.php', true ) . ';' );
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 3 ) . '/scripts/untrusted-host-controller.php' ) );
		$method = new ReflectionMethod( Wstm108_HostBoundaryFixture::class, 'diagnostic_terminal' );
		$method->setAccessible( true );
		$installed = $method->invoke( null, $source, $case );
		$marker = "\t} catch ( Throwable \$error ) {\n\t\tif ( '1' === getenv( 'WSTM108_MOCK_DIAGNOSTIC' ) ) {";
		self::require( 1 === substr_count( $installed, $marker ) );
		$catch = substr( $installed, strpos( $installed, $marker ) );
		$exit = 'outer-mapped-exit43' === $name ? 43 : 78;
		$message = 'outer-unmapped' === $name ? "synthetic-private-\x1b[31m\0\"\\\n" : 'WSTM108 BLOCKED topology: partial-mount-table';
		$throw = 'throw new RuntimeException( ' . var_export( $message, true ) . ', ' . $exit . ' );';
		self::create( $case . '/scripts/terminal.php', "<?php\nif (true) {\n\ttry {\n\t\t" . $throw . "\n" . $catch );
		$release = "#!/usr/bin/env bash\nset -Eeuo pipefail\nprintf 'synthetic-original-stream\\n'\n";
		if ( 'outer-success' === $name ) {
			$release .= "exit 0\n";
		} elseif ( 'outer-not-observed' === $name || 0 === strpos( $name, 'outer-reader-' ) ) {
			$release .= "exit 78\n";
		} else {
			if ( 'outer-write-collision' === $name ) {
				$release .= "(umask 077; set -o noclobber; printf 'collision-original' > \"\$WORK/first-package-diagnostic/terminal.json\")\n";
			}
			$release .= 'exec ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $case . '/scripts/terminal.php' ) . "\n";
		}
		self::create( $case . '/scripts/release-qa.sh', $release );
		$driver = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 3 ) . '/scripts/test-release-runtime.sh' ) );
		$start = 'mkdir -m 700 -- "$WORK/first-package-diagnostic"';
		$end = '[[ "$(sha256sum "$RELEASE_ZIP")" == "$IDENTITY" ]]';
		self::require( 1 === substr_count( $driver, $start ) );
		$offset = strpos( $driver, $start );
		$after = strpos( $driver, $end, $offset );
		self::require( false !== $after );
		$block = substr( $driver, $offset, $after - $offset );
		self::create( $case . '/outer.sh', "#!/usr/bin/env bash\nset -Eeuo pipefail\n" . $block . "printf 'positive-assertion-reached\\n'\n" );
		$environment = getenv();
		$environment['WORK'] = $case;
		$environment['WSTM108_MOCK_ROOT'] = $case;
		$environment['REPO_ROOT'] = dirname( __DIR__, 3 );
		$environment['WSTM108_MOCK_PHP'] = PHP_BINARY;
		unset( $environment['WSTM108_MOCK_DIAGNOSTIC'] );
		if ( 0 === strpos( $name, 'outer-reader-' ) ) {
			$body = array(
				'outer-reader-failed' => "printf 'synthetic-private-reader-error\\033[31m\\n' >&2\nexit 47\n",
				'outer-reader-stdout' => "printf '\\033\\n'\nexit 0\n",
				'outer-reader-stderr' => "printf '3\\n'\nprintf 'synthetic-private-reader-error\\033[31m\\n' >&2\nexit 0\n",
				'outer-reader-range' => "printf '37\\n'\nexit 0\n",
			);
			self::create( $case . '/reader', "#!/usr/bin/env bash\n" . $body[ $name ] );
			self::require( chmod( $case . '/reader', 0700 ) );
			$environment['WSTM108_MOCK_PHP'] = $case . '/reader';
		}
		$stdout_full = 'outer-diagnostic-stdout-full' === $name;
		$result = self::run( array( '/bin/bash', $case . '/outer.sh' ), $case, $environment, $case . '/outer', $stdout_full );
		$labels = array(
			'outer-mapped' => 'topology_code=3; original_exit=78',
			'outer-unmapped' => 'unmapped; original_exit=78',
			'outer-not-observed' => 'not-observed; original_exit=78',
			'outer-write-collision' => 'refused; reader_exit=3; original_exit=78',
			'outer-reader-failed' => 'refused; reader_exit=47; original_exit=78',
			'outer-reader-stdout' => 'refused; reader_exit=0; original_exit=78',
			'outer-reader-stderr' => 'refused; reader_exit=0; original_exit=78',
			'outer-reader-range' => 'refused; reader_exit=0; original_exit=78',
			'outer-mapped-exit43' => 'topology_code=3; original_exit=43',
		);
		if ( $stdout_full ) {
			self::require( 78 === $result['exit'] && null === $result['stdout']
				&& "WSTM108 synthetic diagnostic reporting failed; original exit retained.\n" === $result['stderr']
				&& "3\n" === file_get_contents( $case . '/first-package-diagnostic/readback.stdout.private' )
				&& '' === file_get_contents( $case . '/first-package-diagnostic/readback.stderr.private' ) );
		} else {
			$expected = 'outer-success' === $name ? "positive-assertion-reached\n" : 'WSTM108 synthetic diagnostic: ' . $labels[ $name ] . "\n";
			self::require( ( 'outer-success' === $name ? 0 : $exit ) === $result['exit']
				&& $expected === $result['stdout'] && '' === $result['stderr'] );
		}
		$original = file_get_contents( $case . '/success.log' );
		self::require( 0 === strpos( $original, "synthetic-original-stream\n" ) );
		if ( 'outer-write-collision' === $name ) {
			self::require( 'collision-original' === file_get_contents( $case . '/first-package-diagnostic/terminal.json' )
				&& false !== strpos( $original, 'synthetic diagnostic write failed; original exit retained.' ) );
		}
		if ( 'outer-success' === $name ) {
			self::require( array( '.', '..' ) === scandir( $case . '/first-package-diagnostic' ) );
		}
	}

	public static function execute( string $root ): array {
		self::require( 'Linux' === PHP_OS_FAMILY && PHP_VERSION_ID >= 80100
			&& function_exists( 'posix_geteuid' ) && function_exists( 'fsync' )
			&& ( new ReflectionFunction( 'fsync' ) )->isInternal()
			&& '1' === getenv( 'WSTM108_DIAGNOSTIC_CONTROLS' ) && realpath( $root ) === $root );
		$identity = Wstm108_Files::directory( $root );
		self::require( 0040700 === $identity['mode'] && posix_geteuid() === $identity['uid'] );
		$passed = array();
		$error = new RuntimeException( 'WSTM108 BLOCKED topology: partial-mount-table', 78 );
		$valid = json_encode( Wstm108_SyntheticDiagnostic::project( $error ), JSON_THROW_ON_ERROR ) . "\n";
		foreach ( array( 'record-read', 'unknown-record', 'missing-record', 'write-collision', 'write-symlink-collision' ) as $name ) {
			$case = self::directory( $root, $name );
			$directory = $case . '/first-package-diagnostic';
			$path = $directory . '/terminal.json';
			if ( 'record-read' === $name || 'unknown-record' === $name ) {
				self::require( mkdir( $case . '/authority', 0700 ) );
				self::create( $case . '/authority/publication.private', 'unchanged-publication' );
				$authority = Wstm108_Files::directory( $case . '/authority' );
				$selected = 'unknown-record' === $name ? new RuntimeException( "private\x1b[31m\"\\\0", 78 ) : $error;
				Wstm108_SyntheticDiagnostic::record( $selected, $directory );
				self::require( ( 'unknown-record' === $name ? 0 : 3 ) === Wstm108_SyntheticDiagnostic::read( $directory, 78 )
					&& json_encode( Wstm108_SyntheticDiagnostic::project( $selected ), JSON_THROW_ON_ERROR ) . "\n" === file_get_contents( $path )
					&& $authority === Wstm108_Files::directory( $case . '/authority' )
					&& 'unchanged-publication' === file_get_contents( $case . '/authority/publication.private' ) );
			} elseif ( 'missing-record' === $name ) {
				self::require( null === Wstm108_SyntheticDiagnostic::read( $directory, 78 ) && ! file_exists( $path ) );
			} else {
				$target = 'write-collision' === $name ? $path : $case . '/target.private';
				self::create( $target, 'original-collision-bytes' );
				if ( $target !== $path ) { self::require( symlink( $target, $path ) ); }
				self::refused( static function () use ( $error, $directory ): void { Wstm108_SyntheticDiagnostic::record( $error, $directory ); } );
				self::require( 'original-collision-bytes' === file_get_contents( $target ) );
				if ( $target !== $path ) { self::require( is_link( $path ) && readlink( $path ) === $target ); }
			}
			$passed[] = $name;
		}
		foreach ( array( 'read-symlink', 'read-hardlink', 'read-mode', 'read-oversize', 'read-partial', 'read-extra-key',
			'read-duplicate-key', 'read-code-type', 'read-code-range', 'read-exit-mismatch', 'read-noncanonical' ) as $name ) {
			$case = self::directory( $root, $name );
			$directory = $case . '/first-package-diagnostic';
			$path = $directory . '/terminal.json';
			$bytes = $valid;
			$value = json_decode( $valid, true, 8, JSON_THROW_ON_ERROR );
			if ( 'read-extra-key' === $name ) { $value['private'] = 'not-public'; }
			if ( 'read-code-type' === $name ) { $value['reason_code'] = '3'; }
			if ( 'read-code-range' === $name ) { $value['reason_code'] = 37; }
			if ( in_array( $name, array( 'read-extra-key', 'read-code-type', 'read-code-range' ), true ) ) { $bytes = json_encode( $value, JSON_THROW_ON_ERROR ) . "\n"; }
			if ( 'read-duplicate-key' === $name ) { $bytes = str_replace( '"version":1', '"version":1,"version":1', $bytes ); }
			if ( 'read-noncanonical' === $name ) { $bytes = ' ' . $bytes; }
			if ( 'read-oversize' === $name ) { $bytes = str_repeat( 'x', 4097 ); }
			if ( 'read-partial' === $name ) { $bytes = '{"version":'; }
			$target = in_array( $name, array( 'read-symlink', 'read-hardlink' ), true ) ? $case . '/target.private' : $path;
			self::create( $target, $bytes );
			if ( 'read-symlink' === $name ) { self::require( symlink( $target, $path ) ); }
			if ( 'read-hardlink' === $name ) { self::require( link( $target, $path ) ); }
			if ( 'read-mode' === $name ) { self::require( chmod( $path, 0644 ) ); }
			$observed = 'read-exit-mismatch' === $name ? 43 : 78;
			self::refused( static function () use ( $directory, $observed ): void { Wstm108_SyntheticDiagnostic::read( $directory, $observed ); } );
			self::require( $bytes === file_get_contents( $target ) );
			$passed[] = $name;
		}
		$fault_helper = self::fault_helper( $root );
		$writer = <<<'PHP'
<?php
require $argv[1];
try {
	\Wstm108_DiagnosticFault\Wstm108_SyntheticDiagnostic::record(
		new RuntimeException( 'WSTM108 BLOCKED topology: partial-mount-table', 78 ), $argv[2] );
	exit( 99 );
} catch ( Throwable $error ) {
	fwrite( STDERR, "Synthetic writer refused; original exit retained.\n" );
	exit( 78 );
}
PHP;
		self::create( $root . '/writer.php', $writer );
		foreach ( array( 'missing-fsync', 'write-zero', 'flush', 'sync', 'write-close', 'write-readback' ) as $fault ) {
			$name = 'synthetic-' . $fault;
			$case = self::directory( $root, $name );
			$environment = getenv();
			$environment['WSTM108_DIAGNOSTIC_IO_FAULT'] = $fault;
			$result = self::run( array( PHP_BINARY, $root . '/writer.php', $fault_helper, $case . '/first-package-diagnostic' ),
				$case, $environment, $case . '/writer' );
			self::require( 78 === $result['exit'] && '' === $result['stdout']
				&& "Synthetic writer refused; original exit retained.\n" === $result['stderr'] );
			$passed[] = $name;
		}
		foreach ( array( 'read-close', 'read-short', 'read-replacement', 'read-owner' ) as $fault ) {
			$name = 'read-replacement' === $fault ? 'actual-read-replacement' : 'synthetic-' . $fault;
			$case = self::directory( $root, $name );
			self::create( $case . '/first-package-diagnostic/terminal.json', $valid );
			if ( 'read-replacement' === $fault ) { self::create( $case . '/replacement.private', $valid ); }
			$environment = getenv();
			$environment['WSTM108_DIAGNOSTIC_IO_FAULT'] = $fault;
			$result = self::run( array( PHP_BINARY, $fault_helper, 'read', $case . '/first-package-diagnostic', '78' ),
				$case, $environment, $case . '/reader' );
			self::require( 3 === $result['exit'] && '' === $result['stdout'] && "Synthetic diagnostic readback refused.\n" === $result['stderr'] );
			self::require( $valid === file_get_contents( $case . '/first-package-diagnostic/terminal.json' ) );
			if ( 'read-replacement' === $fault ) {
				self::require( $valid === file_get_contents( $case . '/original-record.private' )
					&& lstat( $case . '/original-record.private' )['ino'] !== lstat( $case . '/first-package-diagnostic/terminal.json' )['ino'] );
			}
			$passed[] = $name;
		}
		foreach ( array( 'outer-mapped', 'outer-unmapped', 'outer-not-observed', 'outer-write-collision', 'outer-reader-failed',
			'outer-reader-stdout', 'outer-reader-stderr', 'outer-reader-range', 'outer-mapped-exit43', 'outer-success',
			'outer-diagnostic-stdout-full' ) as $name ) {
			self::outer( $root, $name );
			$passed[] = $name;
		}
		return array( 'scope' => 'real-linux-filesystem-processes-with-synthetic-faults-no-daemon-or-wordpress', 'passed' => $passed );
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		if ( 2 !== count( $argv ) ) { throw new RuntimeException( 'Explicit owned control root required.' ); }
		echo json_encode( Wstm108_DiagnosticControls::execute( $argv[1] ), JSON_THROW_ON_ERROR ) . "\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, "Synthetic diagnostic controls failed; originals retained; no native coverage inferred.\n" );
		exit( 1 );
	}
}
