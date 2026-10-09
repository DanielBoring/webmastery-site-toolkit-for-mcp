<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php';

final class NativePrerequisiteDiagnosticTest extends TestCase {
	private string $ini;

	protected function setUp(): void {
		$this->ini = ini_get( 'zend.exception_ignore_args' );
		ini_set( 'zend.exception_ignore_args', '1' );
		foreach ( array( 'enabled' => true, 'births' => null ) as $key => $value ) {
			$property = new ReflectionProperty( Wstm108_AdmissionCallsite::class, $key );
			$property->setAccessible( true ); $property->setValue( null, $value );
		}
	}

	protected function tearDown(): void {
		foreach ( array( 'enabled' => false, 'births' => null ) as $key => $value ) {
			$property = new ReflectionProperty( Wstm108_AdmissionCallsite::class, $key );
			$property->setAccessible( true ); $property->setValue( null, $value );
		}
		ini_set( 'zend.exception_ignore_args', $this->ini );
	}

	private function error(): Wstm108_TopologyRefusal {
		try { Wstm108_KernelMounts::visibility( "1 0 0:1 / / rw - tmpfs tmpfs rw\n" ); }
		catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			self::assertSame( 'native-coordinate-prerequisite', $error->reason() );
			return $error;
		}
		self::fail( 'Missing scope did not refuse before native work.' );
	}

	public function test_real_source_call_chain_attributes_missing_scope_without_actor_or_io(): void {
		$error = $this->error();
		self::assertSame( 'pr-active-scope', Wstm108_AdmissionCallsite::identify( $error ) );
		foreach ( $error->getTrace() as $frame ) {
			self::assertArrayNotHasKey( 'args', $frame );
			self::assertArrayNotHasKey( 'object', $frame );
		}
		$diagnostic = fopen( 'php://memory', 'w+b' ); $output = fopen( 'php://memory', 'w+b' );
		Wstm108_HostController::report_terminal_failure( $error, $diagnostic, $output );
		rewind( $output ); $bytes = stream_get_contents( $output );
		self::assertSame( "untrusted_admission_failure={\"phase\":\"topology\",\"reason\":\"native-coordinate-prerequisite\"}\n"
			. "untrusted_admission_callsite_v1=pr-active-scope\n", $bytes );
		rewind( $diagnostic ); self::assertLessThanOrEqual( 256, strlen( stream_get_contents( $diagnostic ) ) );
		fclose( $diagnostic ); fclose( $output );
	}

	public function test_all_named_and_default_guards_are_bound_to_exact_unmodified_source(): void {
		$table = ( new ReflectionClass( Wstm108_AdmissionCallsite::class ) )->getConstant( 'PREREQUISITE_GUARDS' );
		$seen = array();
		foreach ( $table as $class => $data ) {
			$source = file( dirname( __DIR__, 2 ) . '/scripts/' . $data['file'] );
			foreach ( $data['guards'] as $line => $binding ) {
				self::assertStringContainsString( 'self::require(', $source[ $line - 1 ], $class . ':' . $line );
				self::assertTrue( Wstm108_AdmissionCallsite::allows( 'native-coordinate-prerequisite', $binding[0] ) );
				$seen[] = $binding[0];
			}
			foreach ( $data['direct'] ?? array() as $line => $binding ) {
				self::assertStringContainsString( 'throw new Wstm108_TopologyRefusal', $source[ $line - 1 ] );
				self::assertStringContainsString( $binding[1], $source[ $line - 1 ] );
				$seen[] = $binding[0];
			}
			if ( 'Wstm108_KernelAuthorityOwner' === $class ) {
				$actual = array();
				foreach ( $source as $offset => $bytes ) {
					if ( false !== strpos( $bytes, 'self::require(' ) ) { $actual[] = $offset + 1; }
				}
				self::assertSame( $actual, array_keys( $data['guards'] ), 'All constant-default owner guards, not just reason literals.' );
			}
		}
		sort( $seen ); $expected = Wstm108_AdmissionCallsite::PREREQUISITE_IDS; sort( $expected );
		self::assertSame( $expected, $seen );
		self::assertCount( 48, $seen );
		self::assertCount( count( $seen ), array_unique( $seen ) );
	}

	public function test_all_explicit_prerequisite_calls_are_mapped_including_multiline_guards(): void {
		$table = ( new ReflectionClass( Wstm108_AdmissionCallsite::class ) )->getConstant( 'PREREQUISITE_GUARDS' );
		foreach ( $table as $class => $data ) {
			$tokens = token_get_all( file_get_contents( dirname( __DIR__, 2 ) . '/scripts/' . $data['file'] ) );
			$explicit = array();
			foreach ( $tokens as $index => $token ) {
				if ( ! is_array( $token ) || T_STRING !== $token[0] || 'self' !== $token[1] ) { continue; }
				if ( ! is_array( $tokens[ $index + 1 ] ) || T_DOUBLE_COLON !== $tokens[ $index + 1 ][0]
					|| 'require' !== ( $tokens[ $index + 2 ][1] ?? null ) ) { continue; }
				$depth = 0; $native = false;
				for ( $offset = $index + 3; $offset < count( $tokens ); ++$offset ) {
					$part = $tokens[ $offset ];
					if ( '(' === $part ) { ++$depth; }
					if ( is_array( $part ) && T_CONSTANT_ENCAPSED_STRING === $part[0]
						&& "'native-coordinate-prerequisite'" === $part[1] ) { $native = true; }
					if ( ')' === $part && 0 === --$depth ) { break; }
				}
				if ( $native ) { $explicit[] = $token[2]; }
			}
			if ( 'Wstm108_KernelAuthorityOwner' !== $class ) {
				self::assertSame( array_keys( $data['guards'] ), $explicit );
			}
		}
	}

	public function test_real_unreviewed_foreign_calls_and_direct_serialization_cannot_gain_attribution(): void {
		try { Wstm108_KernelMountModel::safe_exec( array(), 1000, 1000, array() ); }
		catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 'native-coordinate-prerequisite', $error->reason() );
			self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		}
		require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-authority-owner.php';
		foreach ( array( Wstm108_KernelMounts::class, Wstm108_KernelAuthorityOwner::class ) as $class ) {
			$instance = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
			foreach ( array( static fn() => $instance->__serialize(), static fn() => $instance->__unserialize( array() ),
				static fn() => serialize( $instance ) ) as $call ) {
				try { $call(); self::fail( 'Serialization must remain refused.' ); }
				catch ( Wstm108_TopologyRefusal $error ) {
					self::assertSame( 'native-coordinate-prerequisite', $error->reason() );
					self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
				}
			}
		}
	}

	public function test_every_guard_private_lookup_and_forgery_is_finite_not_live_provenance(): void {
		$reflection = new ReflectionClass( Wstm108_AdmissionCallsite::class );
		$table = $reflection->getConstant( 'PREREQUISITE_GUARDS' );
		$callers = $reflection->getConstant( 'PREREQUISITE_CALLERS' );
		$closing = $reflection->getConstant( 'PHP80_GUARD_LINES' );
		$lookup = $reflection->getMethod( 'prerequisite_site' ); $lookup->setAccessible( true );
		$trace_property = new ReflectionProperty( Exception::class, 'trace' ); $trace_property->setAccessible( true );
		$file_property = new ReflectionProperty( Exception::class, 'file' ); $file_property->setAccessible( true );
		$line_property = new ReflectionProperty( Exception::class, 'line' ); $line_property->setAccessible( true );
		$root = dirname( __DIR__, 2 ) . '/scripts/';
		foreach ( $table as $class => $data ) {
			foreach ( array( 'guards', 'direct' ) as $kind ) {
				foreach ( $data[ $kind ] ?? array() as $line => $binding ) {
					$error = $this->error();
					$sites = $callers[ $class ][ $binding[1] ] ?? array();
					$file = array_key_first( $sites );
					$caller = array( 'class' => $class, 'function' => $binding[1],
						'file' => $root . ( $file ?? 'untrusted-host-controller.php' ), 'line' => null === $file ? 100 : $sites[ $file ][0] );
					$trace = 'direct' === $kind ? array( $caller ) : array(
						array( 'class' => $class, 'function' => 'require', 'file' => $root . $data['file'],
							'line' => PHP_VERSION_ID < 80100 ? $closing[ $class ][ $line ] : $line ), $caller );
					$trace_property->setValue( $error, $trace );
					$file_property->setValue( $error, $root . $data['file'] ); $line_property->setValue( $error, $line );
					self::assertSame( null === $file ? 'unknown' : $binding[0], $lookup->invoke( null, $error ) );
					self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ), 'Fabricated metadata never replaces actual birth.' );
					$trace[ count( $trace ) - 1 ]['file'] = '/PRIVATE_SENTINEL';
					$trace_property->setValue( $error, $trace );
					self::assertSame( 'unknown', $lookup->invoke( null, $error ) );
					$trace[ count( $trace ) - 1 ]['file'] = $root . ( $file ?? 'untrusted-host-controller.php' );
					$trace[ count( $trace ) - 1 ]['line'] = 2147483647;
					$trace_property->setValue( $error, $trace );
					self::assertSame( 'unknown', $lookup->invoke( null, $error ), 'Same-source unfamiliar caller line is not ranked.' );
					$trace[0]['args'] = array( 'PRIVATE_SENTINEL' ); $trace_property->setValue( $error, $trace );
					self::assertSame( 'unknown', $lookup->invoke( null, $error ) );
				}
			}

		}
	}

	public function test_every_reviewed_caller_line_is_an_actual_named_source_invocation(): void {
		$table = ( new ReflectionClass( Wstm108_AdmissionCallsite::class ) )->getConstant( 'PREREQUISITE_CALLERS' );
		foreach ( $table as $methods ) {
			foreach ( $methods as $method => $files ) {
				foreach ( $files as $file => $lines ) {
					$source = file( dirname( __DIR__, 2 ) . '/scripts/' . $file );
					foreach ( $lines as $line ) {
						self::assertStringContainsString( $method . '(', $source[ $line - 1 ], $file . ':' . $line );
					}
				}
			}
		}
	}

	public function test_compiler_coordinates_are_exact_source_tokens_and_version_selected_not_aliases(): void {
		$class = new ReflectionClass( Wstm108_AdmissionCallsite::class );
		$guards = $class->getConstant( 'PREREQUISITE_GUARDS' );
		$closing = $class->getConstant( 'PHP80_GUARD_LINES' );
		$path_closing = $class->getConstant( 'PHP80_PATH_GUARD_LINES' );
		foreach ( $path_closing as $owner => $paths ) {
			$closing[ $owner ] += $paths;
			foreach ( $paths as $start => $end ) { $guards[ $owner ]['guards'][ $start ] = array(); }
			ksort( $closing[ $owner ] ); ksort( $guards[ $owner ]['guards'] );
		}
		$select = $class->getMethod( 'compiled_guard_line' ); $select->setAccessible( true );
		foreach ( $guards as $owner => $data ) {
			self::assertSame( array_keys( $data['guards'] ), array_keys( $closing[ $owner ] ) );
			self::assertCount( count( $closing[ $owner ] ), array_unique( $closing[ $owner ] ) );
			$tokens = token_get_all( file_get_contents( dirname( __DIR__, 2 ) . '/scripts/' . $data['file'] ) );
			$lines = array(); $current = 1;
			foreach ( $tokens as $index => $token ) {
				$lines[ $index ] = $current;
				$current += substr_count( is_array( $token ) ? $token[1] : $token, "\n" );
			}
			foreach ( $tokens as $index => $token ) {
				if ( ! is_array( $token ) || T_STRING !== $token[0] || 'self' !== $token[1]
					|| ! isset( $data['guards'][ $lines[ $index ] ] )
					|| 'require' !== ( $tokens[ $index + 2 ][1] ?? null ) ) { continue; }
				$start = $lines[ $index ]; $depth = 0; $end = null;
				for ( $offset = $index + 3; $offset < count( $tokens ); ++$offset ) {
					if ( '(' === $tokens[ $offset ] ) { ++$depth; }
					if ( ')' === $tokens[ $offset ] && 0 === --$depth ) { $end = $lines[ $offset ]; break; }
				}
				self::assertSame( $end, $closing[ $owner ][ $start ] );
				foreach ( array( 80000, 80030 ) as $version ) {
					self::assertSame( $start, $select->invoke( null, $owner, $end, $version ) );
					if ( $start !== $end ) { self::assertNull( $select->invoke( null, $owner, $start, $version ) ); }
				}
				foreach ( array( 80200, 80425 ) as $version ) {
					self::assertSame( $start, $select->invoke( null, $owner, $start, $version ) );
					if ( $start !== $end ) {
						$other = $select->invoke( null, $owner, $end, $version );
						self::assertArrayNotHasKey( $other, $data['guards'] );
					}
				}
				self::assertNull( $select->invoke( null, $owner, $end, 70400 ) );
			}
			self::assertNull( $select->invoke( null, $owner, 2147483647, 80030 ) );
		}
		self::assertNull( $select->invoke( null, 'unfamiliar', 150, 80030 ) );
	}

	public function test_unknown_provenance_repeated_reports_and_all_pairs_keep_original_bound(): void {
		$error = $this->error();
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( unserialize( serialize( $error ) ) ) );
		ini_set( 'zend.exception_ignore_args', '0' );
		self::assertSame( 'unknown', Wstm108_AdmissionCallsite::identify( $error ) );
		ini_set( 'zend.exception_ignore_args', '1' );
		$diagnostic = fopen( 'php://memory', 'w+b' ); $output = fopen( 'php://memory', 'w+b' );
		Wstm108_HostController::report_terminal_failure( $error, $diagnostic, $output );
		Wstm108_HostController::report_terminal_failure( new Wstm108_TopologyRefusal( 'native-coordinate-prerequisite' ), $diagnostic, $output );
		rewind( $output ); self::assertStringEndsWith( "untrusted_admission_callsite_v1=unknown\n", stream_get_contents( $output ) );
		fclose( $diagnostic ); fclose( $output );
		$witness = json_encode( array( 'phase' => 'topology', 'reason' => 'native-coordinate-prerequisite' ), JSON_THROW_ON_ERROR );
		foreach ( Wstm108_AdmissionCallsite::PREREQUISITE_IDS as $site ) {
			self::assertLessThanOrEqual( 32, strlen( $site ) );
			self::assertLessThanOrEqual( 256, strlen(
				"WSTM108 controller refused; private diagnostic channel retained; terminal release outcome must not be inferred.\n"
				. 'WSTM108_ADMISSION_REFUSAL_V1 ' . $witness . "\nWSTM108_ADMISSION_CALLSITE_V1 " . $site . "\n" ) );
			self::assertFalse( Wstm108_AdmissionCallsite::allows( 'noncanonical-path', $site ) );
		}
		self::assertFalse( Wstm108_AdmissionCallsite::allows( 'native-coordinate-prerequisite', 'mount-root' ) );
	}
}
