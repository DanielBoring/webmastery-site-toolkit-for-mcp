<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-runtime-diagnostic.php';
require_once __DIR__ . '/fixtures/untrusted-host-boundaries.php';

final class UntrustedRuntimeDiagnosticTest extends TestCase {
	public static function mapped_reasons(): array {
		$cases = array();
		foreach ( Wstm108_SyntheticDiagnostic::REASONS as $code => $reason ) { $cases[ $reason ] = array( $code, $reason ); }
		return $cases;
	}

	/** @dataProvider mapped_reasons */
	public function test_each_exact_reason_has_only_the_closed_projection( int $code, string $reason ): void {
		self::assertSame( array( 'version' => 1, 'scope' => 'synthetic-only', 'reason_code' => $code, 'exception_exit' => 78 ),
			Wstm108_SyntheticDiagnostic::project( new RuntimeException( 'WSTM108 BLOCKED topology: ' . $reason, 78 ) ) );
	}

	public static function unknown_messages(): array {
		return array(
			'private controls' => array( "\x1b[31m/private/sentinel\0\"\\\u{96ea}\n" ),
			'prefix' => array( 'private WSTM108 BLOCKED topology: partial-mount-table' ),
			'suffix' => array( 'WSTM108 BLOCKED topology: partial-mount-table private' ),
			'newline' => array( "WSTM108 BLOCKED topology: partial-mount-table\n" ),
			'new reason' => array( 'WSTM108 BLOCKED topology: newly-added-reason' ),
		);
	}

	/** @dataProvider unknown_messages */
	public function test_unknown_private_text_is_not_projected( string $message ): void {
		self::assertSame( array( 'version' => 1, 'scope' => 'synthetic-only', 'reason_code' => 0, 'exception_exit' => 78 ),
			Wstm108_SyntheticDiagnostic::project( new RuntimeException( $message, 78 ) ) );
	}

	public static function chain_depths(): array {
		return array( 'outer' => array( 0, 3 ), 'eighth' => array( 7, 3 ), 'ninth' => array( 8, 0 ), 'tenth' => array( 9, 0 ) );
	}

	/** @dataProvider chain_depths */
	public function test_chain_depth_is_bounded_without_replacing_outer_exit( int $wrappers, int $expected ): void {
		$error = new RuntimeException( 'WSTM108 BLOCKED topology: partial-mount-table', 43 );
		for ( $depth = 0; $depth < $wrappers; ++$depth ) { $error = new RuntimeException( 'private wrapper', 43, $error ); }
		self::assertSame( array( 'version' => 1, 'scope' => 'synthetic-only', 'reason_code' => $expected, 'exception_exit' => 43 ),
			Wstm108_SyntheticDiagnostic::project( $error ) );
	}

	public static function exit_codes(): array {
		return array( array( 0, 1 ), array( -1, 1 ), array( 1, 1 ), array( 78, 78 ), array( 255, 255 ), array( 256, 1 ) );
	}

	/** @dataProvider exit_codes */
	public function test_original_exit_expression_is_retained( int $exit, int $expected ): void {
		$previous = new RuntimeException( 'WSTM108 BLOCKED topology: partial-mount-table', 78 );
		$projected = Wstm108_SyntheticDiagnostic::project( new RuntimeException( 'outer private', $exit, $previous ) );
		self::assertSame( 3, $projected['reason_code'] );
		self::assertSame( $expected, $projected['exception_exit'] );
	}

	private static function topology_reasons( string $source ): array {
		$tokens = array_values( array_filter( token_get_all( $source ), static function ( $token ): bool {
			return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
		} ) );
		$reasons = array();
		for ( $index = 0; $index < count( $tokens ) - 3; ++$index ) {
			if ( ! is_array( $tokens[ $index ] ) || T_STRING !== $tokens[ $index ][0] || 'self' !== $tokens[ $index ][1]
				|| ! is_array( $tokens[ $index + 1 ] ) || T_DOUBLE_COLON !== $tokens[ $index + 1 ][0]
				|| ! is_array( $tokens[ $index + 2 ] ) || 'require' !== $tokens[ $index + 2 ][1] || '(' !== $tokens[ $index + 3 ] ) { continue; }
			$depth = 1;
			$arguments = array( array() );
			for ( $cursor = $index + 4; $cursor < count( $tokens ); ++$cursor ) {
				$token = $tokens[ $cursor ];
				if ( in_array( $token, array( '(', '[', '{' ), true ) ) { ++$depth; }
				if ( in_array( $token, array( ')', ']', '}' ), true ) && 0 === --$depth ) { break; }
				if ( ',' === $token && 1 === $depth ) { $arguments[] = array(); }
				else { $arguments[ count( $arguments ) - 1 ][] = $token; }
			}
			$last = $arguments[1] ?? array();
			if ( 0 !== $depth || 2 !== count( $arguments ) || 1 !== count( $last ) || ! is_array( $last[0] )
				|| T_CONSTANT_ENCAPSED_STRING !== $last[0][0] || 1 !== preg_match( "/^'([a-z]+(?:-[a-z]+)*)'$/D", $last[0][1], $match ) ) {
				throw new RuntimeException( 'Topology diagnostic coverage requires every guard reason to remain an explicit static literal.' );
			}
			$reasons[] = $match[1];
		}
		return $reasons;
	}

	public function test_allowlist_characterizes_every_current_topology_guard_in_stable_code_order(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php' );
		self::assertSame( range( 1, 36 ), array_keys( Wstm108_SyntheticDiagnostic::REASONS ) );
		self::assertSame( array_values( Wstm108_SyntheticDiagnostic::REASONS ), self::topology_reasons( $source ),
			'New, removed or reordered reasons require explicit diagnostic-code review; this is not a claim of future coverage.' );
		$extra = self::topology_reasons( $source . "\nself::require( true, 'newly-added-reason' );\n" );
		self::assertSame( array( 'newly-added-reason' ), array_values( array_diff( $extra, Wstm108_SyntheticDiagnostic::REASONS ) ) );
	}

	public function test_dynamic_guard_reason_cannot_silently_evade_source_characterization(): void {
		$this->expectException( RuntimeException::class );
		self::topology_reasons( '<?php self::require( true, $reason );' );
	}

	public static function endings(): array {
		return array( 'LF' => array( "\n" ), 'CRLF' => array( "\r\n" ) );
	}

	/** @dataProvider endings */
	public function test_real_terminal_installation_is_exact_reversible_and_preserves_other_bytes( string $ending ): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php' ) );
		$source = str_replace( "\n", $ending, $source );
		$method = new ReflectionMethod( Wstm108_HostBoundaryFixture::class, 'diagnostic_terminal' );
		$method->setAccessible( true );
		$copy = $method->invoke( null, $source, "/synthetic/quote' and space" );
		$start = strpos( $copy, "\t\tif ( '1' === getenv( 'WSTM108_MOCK_DIAGNOSTIC' ) ) {" );
		$end = strpos( $copy, "\t\tfwrite( STDERR, \"WSTM108 controller refused;", $start );
		self::assertNotFalse( $start ); self::assertNotFalse( $end );
		self::assertSame( $source, substr( $copy, 0, $start ) . substr( $copy, $end ) );
		self::assertSame( 1, substr_count( $copy, 'Wstm108_SyntheticDiagnostic::record(' ) );
		self::assertStringContainsString( var_export( "/synthetic/quote' and space/first-package-diagnostic", true ), $copy );
		self::assertStringContainsString( "self::diagnostic_terminal( \$updates['untrusted-host-controller.php'], \$root )",
			file_get_contents( __DIR__ . '/fixtures/untrusted-host-boundaries.php' ) );
	}

	public static function bad_terminal_sites(): array {
		return array( array( 'absent' ), array( 'duplicate' ), array( 'already-installed' ), array( 'mixed-eol' ) );
	}

	/** @dataProvider bad_terminal_sites */
	public function test_terminal_installer_refuses_ambiguous_or_changed_sources( string $case ): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php' ) );
		$method = new ReflectionMethod( Wstm108_HostBoundaryFixture::class, 'diagnostic_terminal' );
		$method->setAccessible( true );
		if ( 'absent' === $case ) { $source = str_replace( 'controller refused;', 'controller changed;', $source ); }
		if ( 'duplicate' === $case ) { $source .= $source; }
		if ( 'already-installed' === $case ) { $source = $method->invoke( null, $source, '/synthetic' ); }
		if ( 'mixed-eol' === $case ) { $source = "<?php\r\n" . $source; }
		$this->expectException( RuntimeException::class );
		$method->invoke( null, $source, '/synthetic' );
	}

	public function test_noop_installer_replacement_refuses(): void {
		$method = new ReflectionMethod( Wstm108_HostBoundaryFixture::class, 'replace' );
		$method->setAccessible( true );
		$this->expectException( RuntimeException::class );
		$method->invoke( null, "site\n", "site\n", "site\n", 'diagnostic-noop-control' );
	}

	public function test_observation_never_enters_production_or_publication_channels(): void {
		foreach ( array( 'untrusted-host-controller.php', 'untrusted-host-bootstrap.sh', 'untrusted-host-topology.php',
			'untrusted-authority.php', 'untrusted-export.php', 'untrusted-release.php' ) as $name ) {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/' . $name );
			self::assertStringNotContainsString( 'WSTM108_MOCK_DIAGNOSTIC', $source );
			self::assertStringNotContainsString( 'Wstm108_SyntheticDiagnostic', $source );
		}
		$helper = file_get_contents( __DIR__ . '/fixtures/untrusted-runtime-diagnostic.php' );
		self::assertSame( 2, substr_count( $helper, 'finally { self::require( fclose( $handle ) ); }' ), 'Both reader and writer must reject close failure.' );
		self::assertStringNotContainsString( 'GITHUB_OUTPUT', $helper );
	}

	public function test_real_linux_filesystem_io_fault_and_outer_exit_controls(): void {
		if ( 'Linux' !== PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Real Linux filesystem/process controls required; a non-Linux skip is not positive coverage.' );
		}
		$php = PHP_VERSION_ID >= 80100 ? PHP_BINARY : '/usr/bin/php8.4';
		self::assertFileExists( $php, 'PHP 8.0 requires the fixed Linux companion already bound by the Unit workflow; no PATH fallback or skip.' );
		self::assertSame( $php, realpath( $php ) );
		self::assertTrue( is_executable( $php ) );
		$identity = lstat( $php );
		self::assertSame( 0, $identity['uid'] );
		self::assertSame( 0, $identity['mode'] & 0022 );
		$root = sys_get_temp_dir() . '/wstm108-runtime-diagnostic-' . bin2hex( random_bytes( 16 ) );
		self::assertTrue( mkdir( $root, 0700 ) );
		$environment = getenv();
		$environment['WSTM108_DIAGNOSTIC_CONTROLS'] = '1';
		$mask = umask( 0077 );
		try {
			$stdout = fopen( $root . '/controls.stdout.private', 'x+b' );
			$stderr = fopen( $root . '/controls.stderr.private', 'x+b' );
		} finally { umask( $mask ); }
		self::assertIsResource( $stdout ); self::assertIsResource( $stderr );
		$process = proc_open( array( $php, __DIR__ . '/fixtures/untrusted-runtime-diagnostic-controls.php', $root ),
			array( 0 => array( 'file', '/dev/null', 'r' ), 1 => $stdout, 2 => $stderr ), $pipes, dirname( __DIR__, 2 ), $environment );
		self::assertIsResource( $process );
		$status = proc_close( $process );
		self::assertTrue( fclose( $stdout ) && fclose( $stderr ) );
		self::assertSame( 0, $status, 'Synthetic native controls failed; originals remain in the owned temporary directory.' );
		self::assertTrue( '' === file_get_contents( $root . '/controls.stderr.private' ), 'Unexpected private control stderr is retained, not rendered.' );
		$result = json_decode( file_get_contents( $root . '/controls.stdout.private' ), true, 16, JSON_THROW_ON_ERROR );
		self::assertSame( array( 'scope', 'passed' ), array_keys( $result ) );
		self::assertSame( 'real-linux-filesystem-processes-with-synthetic-faults-no-daemon-or-wordpress', $result['scope'] );
		self::assertSame( array(
			'record-read', 'unknown-record', 'missing-record', 'write-collision', 'write-symlink-collision',
			'read-symlink', 'read-hardlink', 'read-mode', 'read-oversize', 'read-partial', 'read-extra-key',
			'read-duplicate-key', 'read-code-type', 'read-code-range', 'read-exit-mismatch', 'read-noncanonical',
			'synthetic-missing-fsync', 'synthetic-write-zero', 'synthetic-flush', 'synthetic-sync', 'synthetic-write-close', 'synthetic-write-readback',
			'synthetic-read-close', 'synthetic-read-short', 'actual-read-replacement', 'synthetic-read-owner',
			'outer-mapped', 'outer-unmapped', 'outer-not-observed', 'outer-write-collision', 'outer-reader-failed',
			'outer-reader-stdout', 'outer-reader-stderr', 'outer-reader-range', 'outer-mapped-exit43', 'outer-success',
			'outer-diagnostic-stdout-full',
			'native-authority-exit-0', 'native-authority-exit-23', 'native-authority-exit-47', 'native-authority-exit-255',
		), $result['passed'] );
	}
}
