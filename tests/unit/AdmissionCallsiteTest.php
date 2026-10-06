<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mount-model.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php';

final class AdmissionCallsiteTest extends TestCase {
	private string $original_ini;

	protected function setUp(): void {
		$this->original_ini = ini_get( 'zend.exception_ignore_args' );
		ini_set( 'zend.exception_ignore_args', '1' );
		// Model tests isolate diagnostic state, never native admission or a live-proof receipt.
		$this->state( 'enabled', true );
		$this->state( 'attempted', false );
		$this->state( 'births', null );
	}

	protected function tearDown(): void {
		$this->state( 'enabled', false );
		$this->state( 'attempted', false );
		$this->state( 'births', null );
		ini_set( 'zend.exception_ignore_args', $this->original_ini );
	}

	private function state( string $name, $value ): void {
		$property = new ReflectionProperty( Wstm108_AdmissionCallsite::class, $name );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	private function refusal( callable $call ): Wstm108_TopologyRefusal {
		try { $call(); } catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			self::assertSame( 'noncanonical-path', $error->reason() );
			return $error;
		}
		self::fail( 'Path guard did not refuse.' );
	}

	private function row( string $root = '/' ): array {
		return array( 'id' => 1, 'parent' => 0, 'device' => '0:1', 'root' => $root, 'point' => '/bound', 'type' => 'tmpfs' );
	}

	public function test_real_host_guard_frames_cover_each_reviewed_caller(): void {
		$cases = array(
			'mount-root-rx-d' => static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 /../PRIVATE_SENTINEL /bound rw - tmpfs tmpfs rw\n" ),
			'mount-point' => static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 / /../PRIVATE_SENTINEL rw - tmpfs tmpfs rw\n" ),
			'legacy-input' => fn() => Wstm108_HostTopology::coordinate( '/../PRIVATE_SENTINEL', array( $this->row() ) ),
			'legacy-physical' => fn() => Wstm108_HostTopology::coordinate( '/bound/file', array( $this->row( '/../PRIVATE_SENTINEL' ) ) ),
			'legacy-source' => fn() => Wstm108_HostTopology::outside( '/bound/owned', array( '/../PRIVATE_SENTINEL' ), array( $this->row() ) ),
		);
		foreach ( $cases as $site => $call ) {
			$error = $this->refusal( $call );
			self::assertSame( $site, Wstm108_AdmissionCallsite::identify( $error ) );
			foreach ( array_slice( $error->getTrace(), 0, 3 ) as $frame ) {
				self::assertArrayNotHasKey( 'args', $frame );
				self::assertArrayNotHasKey( 'object', $frame );
			}
		}
	}

	public function test_real_root_frames_cover_all_prefixes_and_exact_regex_combinations(): void {
		$cases = array(
			'mount-root-empty' => '',
			'mount-root-net-false' => "PRIVATE_SENTINEL\0//../",
			'mount-root-nul' => "/PRIVATE_SENTINEL\0//../",
			'mount-root-rx-c' => '/PRIVATE_SENTINEL\\011',
			'mount-root-rx-s' => '/PRIVATE_SENTINEL//end',
			'mount-root-rx-d' => '/PRIVATE_SENTINEL/../end',
			'mount-root-rx-cs' => '/PRIVATE_SENTINEL\\012//end',
			'mount-root-rx-cd' => '/PRIVATE_SENTINEL\\011/./end',
			'mount-root-rx-sd' => '/PRIVATE_SENTINEL//../end',
			'mount-root-rx-csd' => '/PRIVATE_SENTINEL\\012//../end',
		);
		$reflection = new ReflectionClass( Wstm108_AdmissionCallsite::class );
		$guards = $reflection->getConstant( 'HOST_FAILURE_GUARDS' );
		$expected = array_values( array_filter( $guards, static fn( $id ) => 'unknown' !== $id ) );
		$expected[] = 'mount-root-net-false';
		self::assertEqualsCanonicalizing( $expected, array_keys( $cases ) );
		foreach ( $cases as $site => $root ) {
			foreach ( array( 'mounts', 'structural_mounts' ) as $parser ) {
				$error = $this->refusal( static fn() => Wstm108_HostTopology::$parser( '1 0 0:1 ' . $root . " / rw - tmpfs tmpfs rw\n" ) );
				self::assertSame( $site, Wstm108_AdmissionCallsite::identify( $error ) );
				self::assertSame( array( 'phase' => 'topology', 'reason' => 'noncanonical-path' ), Wstm108_HostTopology::failure_witness( $error ) );
				foreach ( $error->getTrace() as $frame ) {
					self::assertArrayNotHasKey( 'args', $frame );
					self::assertArrayNotHasKey( 'object', $frame );
				}
				$diagnostic = fopen( 'php://memory', 'w+b' ); $output = fopen( 'php://memory', 'w+b' );
				Wstm108_HostController::report_terminal_failure( $error, $diagnostic, $output );
				rewind( $diagnostic ); rewind( $output );
				$private = stream_get_contents( $diagnostic ); $public = stream_get_contents( $output );
				self::assertStringContainsString( 'untrusted_admission_callsite_v1=' . $site . "\n", $public );
				self::assertLessThanOrEqual( 256, strlen( $private ) );
				self::assertLessThanOrEqual( 256, strlen( $public ) );
				self::assertStringNotContainsString( 'PRIVATE_SENTINEL', $private . $public );
				Wstm108_HostController::report_terminal_failure( new RuntimeException( 'PRIVATE_SENTINEL' ), $diagnostic, $output );
				rewind( $output );
				self::assertStringEndsWith( "untrusted_admission_callsite_v1=unknown\n", stream_get_contents( $output ) );
				fclose( $diagnostic ); fclose( $output );
			}
		}
	}

	public function test_relative_original_row_establishes_only_the_exact_joint_fact(): void {
		$cases = array(
			array( 'net:[1]', 'nsfs', 'true' ), array( 'net:[4294967295]', 'nsfs', 'true' ),
			array( 'net:[0]', 'nsfs', 'false' ), array( 'net:[01]', 'nsfs', 'false' ),
			array( 'net:[4294967296]', 'nsfs', 'false' ), array( 'net:[10000000000]', 'nsfs', 'false' ),
			array( 'net:[+1]', 'nsfs', 'false' ), array( 'net:[-1]', 'nsfs', 'false' ),
			array( 'mnt:[1]', 'nsfs', 'false' ), array( 'NET:[1]', 'nsfs', 'false' ),
			array( 'net:[1]\\011', 'nsfs', 'false' ), array( 'net:[1]\\012', 'nsfs', 'false' ),
			array( "net:[1]\0", 'nsfs', 'false' ), array( 'net:[1]/PRIVATE_SENTINEL', 'nsfs', 'false' ),
			array( 'net:[1]', 'tmpfs', 'false' ), array( 'net:[1]', 'ext4', 'false' ),
			array( 'net:[1]', 'unknownfs', 'false' ), array( 'PRIVATE_SENTINEL', 'nsfs', 'false' ),
			array( 'net:[1]', '', 'unknown' ), array( 'net:[1]', "nsfs\0", 'unknown' ),
			array( 'net:[1]', "nsfs\t", 'unknown' ),
		);
		foreach ( $cases as $case ) {
			foreach ( array( 'mounts', 'structural_mounts' ) as $parser ) {
				if ( 'true' === $case[2] ) {
					$mounts = Wstm108_HostTopology::$parser( '1 0 0:1 ' . $case[0] . ' / rw - ' . $case[1] . " none rw\n" );
					self::assertSame( $case[0], $mounts[0]['root'] );
					self::assertSame( $case[1], $mounts[0]['type'] );
					continue;
				}
				$error = $this->refusal( static fn() => Wstm108_HostTopology::$parser( '1 0 0:1 ' . $case[0] . ' / rw - ' . $case[1] . " none rw\n" ) );
				$site = 'mount-root-net-' . $case[2];
				self::assertSame( $site, Wstm108_AdmissionCallsite::identify( $error ) );
				foreach ( $error->getTrace() as $frame ) {
					self::assertArrayNotHasKey( 'args', $frame ); self::assertArrayNotHasKey( 'object', $frame );
				}
				self::assertSame( array( 'phase' => 'topology', 'reason' => 'noncanonical-path' ), Wstm108_HostTopology::failure_witness( $error ) );
				$diagnostic = fopen( 'php://memory', 'w+b' ); $output = fopen( 'php://memory', 'w+b' );
				Wstm108_HostController::report_terminal_failure( $error, $diagnostic, $output );
				rewind( $diagnostic ); rewind( $output );
				$private = stream_get_contents( $diagnostic ); $public = stream_get_contents( $output );
				self::assertStringContainsString( 'untrusted_admission_callsite_v1=' . $site . "\n", $public );
				self::assertLessThanOrEqual( 256, strlen( $private ) ); self::assertLessThanOrEqual( 256, strlen( $public ) );
				foreach ( array( 'PRIVATE_SENTINEL', 'net:[', 'nsfs', '4294967295', 'unknownfs' ) as $sentinel ) {
					self::assertStringNotContainsString( $sentinel, $private . $public );
				}
				Wstm108_HostController::report_terminal_failure( new RuntimeException( 'PRIVATE_SENTINEL' ), $diagnostic, $output );
				rewind( $output ); self::assertStringEndsWith( "untrusted_admission_callsite_v1=unknown\n", stream_get_contents( $output ) );
				fclose( $diagnostic ); fclose( $output );
				self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( unserialize( serialize( $error ) ) ) );
			}
		}
		foreach ( array(
			'legacy-input' => static fn() => Wstm108_HostTopology::coordinate( 'net:[1]', array() ),
			'mount-point' => static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 / net:[1] rw - nsfs none ro\n" ),
		) as $site => $call ) {
			self::assertSame( $site, Wstm108_AdmissionCallsite::identify( $this->refusal( $call ) ) );
		}
	}

	public function test_relative_context_and_all_five_frames_cannot_be_supplied_or_forged(): void {
		$relative = new ReflectionMethod( Wstm108_HostTopology::class, 'relative_root_failure' ); $relative->setAccessible( true );
		$row = array( '1', '0', '0:1', 'net:[1]', '/', 'rw', '-', 'nsfs', 'none', 'ro' );
		foreach ( array( null, array(), $row, array_replace( $row, array( 3 => 'net:[2]' ) ), array_replace( $row, array( 7 => true ) ) ) as $context ) {
			$error = $this->refusal( static fn() => $relative->invoke( null, 'net:[1]', $context ) );
			self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		}
		$call = static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 net:[0] / rw - nsfs none ro\n" );
		$trace_property = new ReflectionProperty( Exception::class, 'trace' ); $trace_property->setAccessible( true );
		foreach ( range( 0, 4 ) as $index ) {
			foreach ( array( 'file', 'line', 'class', 'function', 'args', 'object' ) as $field ) {
				$error = $this->refusal( $call ); $trace = $error->getTrace();
				self::assertSame( 'mount-root-net-false', Wstm108_AdmissionCallsite::identify( $error ) );
				$trace[ $index ][ $field ] = 'line' === $field ? 999999 : 'PRIVATE_SENTINEL';
				$trace_property->setValue( $error, $trace );
				self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
				Wstm108_AdmissionCallsite::record_creation( $error );
				self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
			}
		}
		$error = $this->refusal( $call ); $trace = $error->getTrace();
		$trace[0]['line'] = 326; $trace_property->setValue( $error, $trace );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ), 'A genuine birth cannot be changed to the true guard.' );
		$this->state( 'enabled', false );
		$error = $this->refusal( $call ); $this->state( 'enabled', true );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		ini_set( 'zend.exception_ignore_args', '0' );
		$error = $this->refusal( $call ); ini_set( 'zend.exception_ignore_args', '1' );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
	}

	public function test_real_owned_entry_classifies_relative_rows_with_argument_free_birth(): void {
		$hook = tempnam( sys_get_temp_dir(), 'wstm-relative-entry-' ); self::assertIsString( $hook );
		$code = <<<'PHP'
<?php
register_shutdown_function( static function (): void {
	$sites = array(); $arguments = false;
	foreach ( array( 'nsfs', 'tmpfs', '' ) as $type ) {
		try {
			Wstm108_HostTopology::structural_mounts( '1 0 0:1 net:[1] / rw - ' . $type . " none ro\n" );
			$sites[] = 'metadata-accepted';
		}
		catch ( Wstm108_TopologyRefusal $error ) {
			$sites[] = Wstm108_AdmissionCallsite::identify( $error );
			foreach ( $error->getTrace() as $frame ) { $arguments = $arguments || isset( $frame['args'] ) || isset( $frame['object'] ); }
		}
	}
	echo json_encode( array( ini_get( 'zend.exception_ignore_args' ), $sites, $arguments ), JSON_THROW_ON_ERROR ) . "\n";
} );
PHP;
		try {
			self::assertSame( strlen( $code ), file_put_contents( $hook, $code ) );
			$command = array( PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-d', 'auto_prepend_file=' . $hook,
				dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php' );
			$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process ); fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); fclose( $pipes[2] );
			self::assertSame( 1, proc_close( $process ), 'No admission was requested.' );
			self::assertSame( array( '1', array( 'metadata-accepted', 'mount-root-net-false', 'mount-root-net-unknown' ), false ),
				json_decode( $output, true, 4, JSON_THROW_ON_ERROR ) );
			self::assertStringNotContainsString( 'net:[', $output . $errors );
		} finally { unlink( $hook ); }
	}

	public function test_failure_only_fallback_and_direct_or_drifted_calls_are_unknown(): void {
		$failure = new ReflectionMethod( Wstm108_HostTopology::class, 'path_failure' ); $failure->setAccessible( true );
		foreach ( array( '/', '', '/PRIVATE_SENTINEL//../end' ) as $path ) {
			$error = $this->refusal( static fn() => $failure->invoke( null, $path ) );
			self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		}
		$call = static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 /PRIVATE_SENTINEL//../ / rw - tmpfs tmpfs rw\n" );
		$trace_property = new ReflectionProperty( Exception::class, 'trace' ); $trace_property->setAccessible( true );
		foreach ( array( 'guard-swap', 'failure-call', 'path-class', 'path-file', 'path-line', 'path-function', 'owner', 'owner-args', 'owner-object' ) as $change ) {
			$error = $this->refusal( $call ); $trace = $error->getTrace();
			self::assertSame( 'mount-root-rx-sd', Wstm108_AdmissionCallsite::identify( $error ) );
			if ( 'guard-swap' === $change ) { $trace[0]['line'] = 302; }
			elseif ( 'failure-call' === $change ) { ++$trace[1]['line']; }
			elseif ( 'owner' === $change ) { $trace[3]['function'] = 'foreign'; }
			elseif ( 'owner-args' === $change ) { $trace[3]['args'] = array( 'PRIVATE_SENTINEL' ); }
			elseif ( 'owner-object' === $change ) { $trace[3]['object'] = new stdClass(); }
			else { $trace[2][ substr( $change, 5 ) ] = 'path-line' === $change ? 88 : 'PRIVATE_SENTINEL'; }
			$trace_property->setValue( $error, $trace );
			self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ), $change );
			Wstm108_AdmissionCallsite::record_creation( $error );
			self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		}
	}

	public function test_real_model_and_held_frames_distinguish_length_canonical_and_physical(): void {
		$held = new ReflectionMethod( Wstm108_KernelMounts::class, 'held' );
		$held->setAccessible( true );
		foreach ( array( 'length' => '/' . str_repeat( 'a', 4096 ), 'canonical' => '/../PRIVATE_SENTINEL' ) as $kind => $path ) {
			$cases = array(
				'kernel-selected' => fn() => Wstm108_KernelMountModel::selected_row( $path, array( $this->row() ), 1, '0:1' ),
				'kernel-input' => fn() => Wstm108_KernelMountModel::coordinate( $path, array( $this->row() ), 1, '0:1' ),
				'kernel-physical' => fn() => Wstm108_KernelMountModel::coordinate( '/bound/file', array( $this->row( $path ) ), 1, '0:1' ),
				'kernel-held' => static fn() => $held->invoke( null, 1, 3, $path, static function (): void {} ),
			);
			foreach ( $cases as $prefix => $call ) {
				self::assertSame( $prefix . '-' . $kind, Wstm108_AdmissionCallsite::identify( $this->refusal( $call ) ) );
			}
		}
		// A valid root below 4096 can also produce an over-limit physical coordinate.
		$error = $this->refusal( fn() => Wstm108_KernelMountModel::coordinate(
			'/bound/' . str_repeat( 'b', 30 ), array( $this->row( '/' . str_repeat( 'a', 4080 ) ) ), 1, '0:1' ) );
		self::assertSame( 'kernel-physical-length', Wstm108_AdmissionCallsite::identify( $error ) );
	}

	public function test_path_predicates_and_normalization_remain_exact(): void {
		$paths = array( '', '/', '/a/', '/a/b', '/a//b', '//', '/.', '/..', '/a/../b', '/a/./b',
			"/a\0b", "/a\nb", "/a\x7fb", 'relative', '/a..b', '/é', str_repeat( '/a', 2050 ),
			'/' . str_repeat( 'a', 4095 ), '/' . str_repeat( 'a', 4096 ), '/' . str_repeat( 'a', 4096 ) . '/../b' );
		foreach ( range( 0, 127 ) as $byte ) { $paths[] = '/a' . chr( $byte ) . 'b'; }
		foreach ( $paths as $path ) {
			$canonical = '' !== $path && '/' === $path[0] && false === strpos( $path, "\0" )
				&& ! preg_match( '/[\x00-\x1f\x7f]|\/\/|(?:^|\/)\.\.?(?:\/|$)/', $path );
			foreach ( array( Wstm108_HostTopology::class, Wstm108_KernelMountModel::class ) as $class ) {
				$expected = $canonical && ( Wstm108_HostTopology::class === $class || strlen( $path ) <= 4096 );
				$method = new ReflectionMethod( $class, 'path' );
				$method->setAccessible( true );
				try {
					$result = $method->invoke( null, $path );
					self::assertTrue( $expected );
					self::assertSame( '/' === $path ? '/' : rtrim( $path, '/' ), $result );
				} catch ( Wstm108_TopologyRefusal $error ) {
					self::assertFalse( $expected );
					self::assertSame( 'noncanonical-path', $error->reason() );
					self::assertSame( 78, $error->getCode() );
				}
			}
		}
	}

	public function test_every_bound_source_line_and_exact_guard_is_current(): void {
		$reflection = new ReflectionClass( Wstm108_AdmissionCallsite::class );
		$root = dirname( __DIR__, 2 ) . '/scripts/';
		foreach ( array( 'HOST_CALLERS' => 'untrusted-host-topology.php', 'MODEL_CALLERS' => 'untrusted-kernel-mount-model.php',
			'KERNEL_CALLERS' => 'untrusted-kernel-mounts.php' ) as $key => $file ) {
			$lines = file( $root . $file );
			$sites = $reflection->getConstant( $key );
			$actual = array();
			foreach ( $lines as $offset => $line ) {
				if ( false !== strpos( $line, '::path(' ) ) { $actual[] = $offset + 1; }
			}
			self::assertSame( $actual, array_keys( $sites ), $file );
			foreach ( $sites as $line => $binding ) {
				self::assertStringContainsString( '::path(', $lines[ $line - 1 ] );
				self::assertIsArray( $binding );
			}
		}
		$host = file_get_contents( $root . 'untrusted-host-topology.php' );
		$predicate = "'' !== \$path && '/' === \$path[0] && false === strpos( \$path, \"\\0\" )\n"
			. "\t\t\t&& ! preg_match( '/[\\x00-\\x1f\\x7f]|\\/\\/|(?:^|\\/)\\.\\.?(?:\\/|\$)/', \$path )";
		self::assertSame( 1, substr_count( str_replace( "\r\n", "\n", $host ), '$canonical = ' . $predicate . ';' ) );
		self::assertStringContainsString( "if ( ! \$canonical ) { self::path_failure( \$path, \$mount_row ); } return '/' === \$path ? '/' : rtrim( \$path, '/' );", $host );
		$lines = file( $root . 'untrusted-host-topology.php' );
		foreach ( $reflection->getConstant( 'HOST_FAILURE_GUARDS' ) + $reflection->getConstant( 'RELATIVE_FAILURE_GUARDS' ) as $line => $site ) {
			self::assertStringContainsString( "self::require( false, 'noncanonical-path' );", $lines[ $line - 1 ] );
			self::assertTrue( Wstm108_AdmissionCallsite::allows( 'noncanonical-path', $site ) );
		}
		$model = file( $root . 'untrusted-kernel-mount-model.php' );
		self::assertStringContainsString( "strlen( \$path ) <= 4096, 'noncanonical-path'", $model[ $reflection->getConstant( 'MODEL_LENGTH_LINE' ) - 1 ] );
		self::assertStringContainsString( "self::require( '' !== \$path", $model[ $reflection->getConstant( 'MODEL_CANONICAL_LINE' ) - 1 ] );
		$controller = file( $root . 'untrusted-host-controller.php' );
		self::assertStringContainsString( 'AdmissionCallsite::initialize();', $controller[ $reflection->getConstant( 'ENTRY_LINE' ) - 1 ] );
		self::assertStringContainsString( 'AdmissionCallsite::record_creation( $this );', file( $root . 'untrusted-host-topology.php' )[ $reflection->getConstant( 'BIRTH_LINE' ) - 1 ] );
		foreach ( Wstm108_AdmissionCallsite::IDS as $site ) { self::assertLessThanOrEqual( 32, strlen( $site ) ); }
	}

	public function test_all_eight_current_steps_pass_only_scalar_output_through_environment(): void {
		$step = "      - name: Show closed admission refusal diagnostic (not acceptance)\n"
			. "        if: \${{ always() }}\n        continue-on-error: true\n        env:\n"
			. "          WSTM108_ADMISSION_FAILURE: \${{ steps.qa.outputs.untrusted_admission_failure }}\n"
			. "          WSTM108_ADMISSION_CALLSITE_V1: \${{ steps.qa.outputs.untrusted_admission_callsite_v1 }}\n"
			. "        run: php -d display_errors=0 -d log_errors=0 scripts/untrusted-admission-diagnostic.php\n";
		foreach ( array( 'e2e-qa.yml' => 2, 'release-package-qa.yml' => 1, 'release.yml' => 1, 'compatibility-qa.yml' => 4 ) as $file => $count ) {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/' . $file );
			$floor_step = str_replace( "diagnostic (not acceptance)\n", "diagnostic (not acceptance)\n"
				. "        working-directory: \${{ github.workspace }}/candidate-floor-tools\n", $step );
			$floor_count = 'compatibility-qa.yml' === $file ? 1 : 0;
			self::assertSame( $count - $floor_count, substr_count( $source, $step ), $file );
			self::assertSame( $floor_count, substr_count( $source, $floor_step ), $file );
			self::assertSame( $count, substr_count( $source, 'untrusted_admission_callsite_v1' ), $file );
			self::assertSame( $count, substr_count( $source, 'WSTM108_ADMISSION_CALLSITE_V1' ), $file );
		}
	}

	public function test_absent_birth_unverified_ini_foreign_initializer_and_foreign_calls_are_unknown(): void {
		$this->state( 'enabled', false );
		ini_set( 'zend.exception_ignore_args', '0' );
		Wstm108_AdmissionCallsite::initialize();
		self::assertSame( '0', ini_get( 'zend.exception_ignore_args' ), 'A foreign entry cannot change INI.' );
		$error = $this->refusal( static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 /../private / rw - tmpfs tmpfs rw\n" ) );
		$this->state( 'enabled', true );
		ini_set( 'zend.exception_ignore_args', '1' );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		Wstm108_AdmissionCallsite::record_creation( $error );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ), 'A retained object cannot acquire birth provenance later.' );
		foreach ( array( new RuntimeException( 'PRIVATE_SENTINEL', 78 ), new Wstm108_TopologyRefusal( 'noncanonical-path' ),
			$this->refusal( static fn() => Wstm108_KernelMountModel::path( '/../private' ) ) ) as $foreign ) {
			self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $foreign ) );
		}
	}

	public function test_non_source_and_unknown_births_never_gain_classification_from_forged_frames(): void {
		$error = $this->refusal( static fn() => Wstm108_KernelMountModel::path( '/../PRIVATE_SENTINEL' ) );
		$known = $this->refusal( static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 /../private / rw - tmpfs tmpfs rw\n" ) );
		$property = new ReflectionProperty( Exception::class, 'trace' ); $property->setAccessible( true );
		$property->setValue( $error, $known->getTrace() );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		Wstm108_AdmissionCallsite::record_creation( $error );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		$this->state( 'births', null );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $known ) );
	}

	public function test_real_birth_rejects_changed_ini_serialization_and_native_trace_forgery(): void {
		$call = static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 /../private / rw - tmpfs tmpfs rw\n" );
		$error = $this->refusal( $call );
		self::assertSame( 'mount-root-rx-d', Wstm108_AdmissionCallsite::identify( $error ) );
		ini_set( 'zend.exception_ignore_args', '0' );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		ini_set( 'zend.exception_ignore_args', '1' );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( unserialize( serialize( $error ) ) ) );
		$property = new ReflectionProperty( Exception::class, 'trace' );
		$property->setAccessible( true );
		foreach ( array( 'line', 'file', 'class', 'function', 'args', 'object', 'owner', 'different-site', 'tail', 'missing' ) as $change ) {
			$error = $this->refusal( $call ); $trace = $error->getTrace();
			if ( 'owner' === $change ) { $trace[3]['function'] = 'foreign'; }
			elseif ( 'different-site' === $change ) { $trace[2]['line'] = 88; }
			elseif ( 'tail' === $change ) { $trace[] = array( 'function' => 'PRIVATE_SENTINEL' ); }
			elseif ( 'missing' === $change ) { $trace = array(); }
			else { $trace[0][ $change ] = 'line' === $change ? 999999 : 'PRIVATE_SENTINEL'; }
			$property->setValue( $error, $trace );
			self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ), $change );
			Wstm108_AdmissionCallsite::record_creation( $error );
			self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ), 'No re-registration: ' . $change );
		}
	}

	public function test_actual_owned_entry_sets_ini_before_creation_or_refuses_classification_without_it(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array( '', 'ini_set', 'ini_get', 'debug_backtrace' ) as $disabled ) {
			$command = array( PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
				'-d', 'auto_prepend_file=' . $root . '/tests/unit/fixtures/callsite-entry-shutdown.php' );
			if ( '' !== $disabled ) { $command = array_merge( $command, array( '-d', 'disable_functions=' . $disabled ) ); }
			$command[] = $root . '/scripts/untrusted-host-controller.php';
			$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process ); fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] ); $diagnostic = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); fclose( $pipes[2] );
			self::assertSame( 1, proc_close( $process ), 'No admission or runtime was requested.' );
			$unverified = 'ini_get' === $disabled ? array( false, 'unavailable', 'unknown', false, array( 'unknown', 'unknown', 'unknown' ) )
				: array( false, '0', 'unknown', true, array( 'unknown', 'unknown', 'unknown' ) );
			self::assertSame( '' !== $disabled ? $unverified
				: array( true, '1', 'mount-root-rx-d', false, array( 'kernel-physical-length', 'kernel-held-canonical', 'pr-active-scope' ) ),
				json_decode( $output, true, 4, JSON_THROW_ON_ERROR ) );
			self::assertStringNotContainsString( 'PRIVATE_SENTINEL', $diagnostic . $output );
		}
	}

	public function test_all_reviewed_metadata_sites_are_finite_but_forgery_cannot_change_birth(): void {
		$reflection = new ReflectionClass( Wstm108_AdmissionCallsite::class );
		$native = $reflection->getMethod( 'native_site' ); $native->setAccessible( true );
		$trace_property = new ReflectionProperty( Exception::class, 'trace' ); $trace_property->setAccessible( true );
		$root = dirname( __DIR__, 2 ) . '/scripts/';
		foreach ( array( 'HOST_CALLERS' => 'untrusted-host-topology.php', 'MODEL_CALLERS' => 'untrusted-kernel-mount-model.php',
			'KERNEL_CALLERS' => 'untrusted-kernel-mounts.php' ) as $key => $file ) {
			foreach ( $reflection->getConstant( $key ) as $line => $binding ) {
				$host = 'HOST_CALLERS' === $key;
				foreach ( $host ? array( 'canonical' ) : array( 'length', 'canonical' ) as $kind ) {
					foreach ( (array) $binding[1] as $owner ) {
						$error = $this->refusal( static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 /../private / rw - tmpfs tmpfs rw\n" ) );
						$class = $host ? 'Wstm108_HostTopology' : 'Wstm108_KernelMountModel';
						$guard = $host ? 314
							: $reflection->getConstant( 'MODEL_' . strtoupper( $kind ) . '_LINE' );
						if ( PHP_VERSION_ID < 80100 ) {
							$guard = $reflection->getConstant( 'PHP80_PATH_GUARD_LINES' )[ $class ][ $guard ];
						}
						$trace = array(
							array( 'class' => $class, 'function' => 'require', 'file' => $root . ( $host ? $file : 'untrusted-kernel-mount-model.php' ), 'line' => $guard ),
							array( 'class' => $class, 'function' => $host ? 'path_failure' : 'path', 'file' => $root . $file,
								'line' => $host ? $reflection->getConstant( 'HOST_FAILURE_CALL_LINE' ) : $line ),
							array( 'class' => 'KERNEL_CALLERS' === $key ? 'Wstm108_KernelMounts' : $class, 'function' => $owner ),
						);
						if ( $host ) { array_splice( $trace, 2, 0, array( array( 'class' => $class, 'function' => 'path', 'file' => $root . $file, 'line' => $line ) ) ); }
						$trace_property->setValue( $error, $trace );
						$expected = $host && 'mount-root' === $binding[0] ? 'mount-root-rx-d' : $binding[0] . ( $host ? '' : '-' . $kind );
						// Private lookup grammar only: these fabricated frames are not native provenance.
						self::assertSame( $expected, $native->invoke( null, $error ) );
						self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
						$trace[ $host ? 2 : 1 ]['line'] += 10000; $trace_property->setValue( $error, $trace );
						self::assertSame( 'unknown', $native->invoke( null, $error ) );
						self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
					}
				}
			}
		}
	}

	public function test_same_bounded_terminal_payload_preserves_witness_and_clears_repeated_scalar(): void {
		$error = $this->refusal( static fn() => Wstm108_HostTopology::structural_mounts( "1 0 0:1 /../private / rw - tmpfs tmpfs rw\n" ) );
		$diagnostic = fopen( 'php://memory', 'w+b' ); $output = fopen( 'php://memory', 'w+b' );
		Wstm108_HostController::report_terminal_failure( $error, $diagnostic, $output );
		Wstm108_HostController::report_terminal_failure( new RuntimeException( 'PRIVATE_SENTINEL' ), $diagnostic, $output );
		rewind( $output ); $bytes = stream_get_contents( $output );
		self::assertStringContainsString( "untrusted_admission_failure={\"phase\":\"topology\",\"reason\":\"noncanonical-path\"}\n", $bytes );
		self::assertStringContainsString( "untrusted_admission_callsite_v1=mount-root-rx-d\n", $bytes );
		self::assertStringEndsWith( "untrusted_admission_callsite_v1=unknown\n", $bytes );
		rewind( $diagnostic ); self::assertStringNotContainsString( 'PRIVATE_SENTINEL', stream_get_contents( $diagnostic ) );
		fclose( $diagnostic ); fclose( $output );
		foreach ( Wstm108_HostTopology::REFUSAL_REASONS as $reason ) {
			$diagnostic = fopen( 'php://memory', 'w+b' ); $output = fopen( 'php://memory', 'w+b' );
			Wstm108_HostController::report_terminal_failure( new Wstm108_TopologyRefusal( $reason ), $diagnostic, $output );
			rewind( $diagnostic ); rewind( $output );
			self::assertLessThanOrEqual( 256, strlen( stream_get_contents( $diagnostic ) ) );
			self::assertLessThanOrEqual( 256, strlen( stream_get_contents( $output ) ) );
			fclose( $diagnostic ); fclose( $output );
		}
		$witness = json_encode( array( 'phase' => 'topology', 'reason' => 'noncanonical-path' ), JSON_THROW_ON_ERROR );
		foreach ( Wstm108_AdmissionCallsite::IDS as $site ) {
			self::assertLessThanOrEqual( Wstm108_AdmissionCallsite::MAX_TERMINAL_BYTES, strlen(
				"WSTM108 controller refused; private diagnostic channel retained; terminal release outcome must not be inferred.\n"
				. 'WSTM108_ADMISSION_REFUSAL_V1 ' . $witness . "\nWSTM108_ADMISSION_CALLSITE_V1 " . $site . "\n" ) );
			self::assertLessThanOrEqual( Wstm108_AdmissionCallsite::MAX_TERMINAL_BYTES, strlen(
				'untrusted_admission_failure=' . $witness . "\nuntrusted_admission_callsite_v1=" . $site . "\n" ) );
		}
	}
}
