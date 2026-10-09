<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-observation.php';

/**
 * A nonprivileged transport adapter only: production tools/scope/custody checks
 * have separate tests. No sudo, root process, Docker or live host is invoked.
 */
final class ScopedHostObservationTransportTest extends TestCase {
	private string $directory;
	private array $handle;

	private static function load_adapter(): void {
		if ( ! class_exists( 'Wstm108_HostObservationTransportAdapter', false ) ) {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-observation.php' );
			$source = str_replace( 'final class Wstm108_HostObservation {', 'final class Wstm108_HostObservationTransportAdapter {
				public static array $test_command = array();
				public static array $test_processes = array();
				public static int $test_tools = 0;
				public static bool $test_race = false;
				public static bool $test_delay = false;
				public static bool $test_hold_eof = false;
				private static function test_open(array $descriptors, array &$pipes, string $cwd, array $environment) {
					$pairs = array();
					if (self::$test_hold_eof) {
						foreach (array(1, 2) as $fd) {
							$pairs[$fd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
							if (false === $pairs[$fd]) { throw new RuntimeException("Adapter EOF pair unavailable."); }
							$descriptors[$fd] = $pairs[$fd][1];
						}
						$holder = proc_open(array(PHP_BINARY, "-r", "usleep(8000000);"),
							array(0 => array("file", "/dev/null", "r"), 1 => $pairs[1][1], 2 => $pairs[2][1]),
							$unused, $cwd, $environment);
						if (!is_resource($holder)) { throw new RuntimeException("Adapter EOF holder unavailable."); }
						self::$test_processes[] = $holder;
					}
					$process = @proc_open(self::$test_command, $descriptors, $pipes, $cwd, $environment);
					if (is_resource($process)) { self::$test_processes[] = $process; }
					foreach ($pairs as $fd => $pair) { $pipes[$fd] = $pair[0]; fclose($pair[1]); }
					return $process;
				}', $source, $count );
			self::assertSame( 1, $count );
			$start = strpos( $source, 'private static function tools(' );
			$end = strpos( $source, 'private static function custody(', $start );
			self::assertNotFalse( $start ); self::assertNotFalse( $end );
			$source = substr_replace( $source, 'private static function tools( array $command ): array {
				++self::$test_tools;
				if (self::$test_delay && self::$test_tools >= 3) { usleep(250000); }
				return array( "generation" => self::$test_race && self::$test_tools >= 3 ? 2 : 1 );
			}
			', $start, $end - $start );
			$source = str_replace( '@proc_open( $command,', 'self::test_open(', $source, $count );
			self::assertSame( 1, $count );
			eval( substr( $source, strlen( "<?php\n" ) ) );
		}
	}

	protected function setUp(): void {
		if ( 'Linux' !== PHP_OS_FAMILY || ! function_exists( 'posix_geteuid' ) || 0 === posix_geteuid()
			|| ! function_exists( 'fsync' ) ) {
			$this->markTestSkipped( 'Transport adapter requires native nonroot Linux PHP/fsync; not native privileged-tool evidence.' );
		}
		self::load_adapter();
		$temporary = realpath( sys_get_temp_dir() );
		self::assertNotFalse( $temporary, 'Transport adapter requires an existing native temporary directory.' );
		self::assertDirectoryIsWritable( $temporary, 'Transport adapter requires a writable native temporary directory.' );
		$this->directory = $temporary . '/wstm108-host-observation-unit-' . bin2hex( random_bytes( 16 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->handle = array( 'directory' => $this->directory,
			'identity' => Wstm108_HostTopology::stable_identity( Wstm108_Files::directory( $this->directory ) ) );
		Wstm108_HostObservationTransportAdapter::$test_tools = 0;
		Wstm108_HostObservationTransportAdapter::$test_race = false;
		Wstm108_HostObservationTransportAdapter::$test_delay = false;
		Wstm108_HostObservationTransportAdapter::$test_hold_eof = false;
		$deadline = new ReflectionProperty( Wstm108_HostObservationTransportAdapter::class, 'deadline' );
		$deadline->setAccessible( true ); $deadline->setValue( null, hrtime( true ) + 20000000000 );
	}

	protected function tearDown(): void {
		if ( ! isset( $this->directory ) ) { return; }
		foreach ( Wstm108_HostObservationTransportAdapter::$test_processes as $process ) {
			$status = proc_get_status( $process );
			if ( $status['running'] ) {
				self::assertTrue( proc_terminate( $process, 9 ) );
				$deadline = hrtime( true ) + 2000000000;
				do {
					usleep( 1000 );
					$status = proc_get_status( $process );
				} while ( $status['running'] && hrtime( true ) < $deadline );
			}
			self::assertFalse( $status['running'], 'Owned nonroot adapter child did not terminate.' );
		}
		Wstm108_HostObservationTransportAdapter::$test_processes = array();
		foreach ( glob( $this->directory . '/*' ) as $path ) {
			self::assertFalse( is_link( $path ) );
			self::assertTrue( unlink( $path ) );
		}
		self::assertTrue( rmdir( $this->directory ) );
	}

	private function capture( string $code ): string {
		Wstm108_HostObservationTransportAdapter::$test_command = array( PHP_BINARY, '-r', $code );
		$method = new ReflectionMethod( Wstm108_HostObservationTransportAdapter::class, 'capture' );
		$method->setAccessible( true );
		return $method->invoke( null, Wstm108_HostObservation::command( 'descriptors', 123 ), $this->handle );
	}

	private function intent(): array {
		$files = glob( $this->directory . '/*.intent.private.json' );
		self::assertCount( 1, $files );
		return json_decode( Wstm108_Files::file( $files[0] )['bytes'], true, 512, JSON_THROW_ON_ERROR );
	}

	public function test_complete_exit_eof_and_originals_are_required_before_success(): void {
		self::assertSame( "1\0socket:[101]\0", $this->capture( 'fwrite(STDOUT, "1\\0socket:[101]\\0");' ) );
		$intent = $this->intent();
		self::assertSame( 'complete', $intent['state'] );
		self::assertSame( 0, $intent['exit'] );
		self::assertSame( array( 1 => true, 2 => true ), $intent['eof'] );
		self::assertSame( hash( 'sha256', "1\0socket:[101]\0" ), $intent['streams']['stdout']['sha256'] );
		self::assertSame( 0, $intent['streams']['stderr']['length'] );
		$environment = array( 'WSTM108_HOST_INSPECTION' => Wstm108_HostObservation::MODE,
			'WSTM108_HOST_OBSERVATION_CUSTODY' => base64_encode( json_encode( $this->handle, JSON_THROW_ON_ERROR ) ) );
		$retained = Wstm108_HostObservationTransportAdapter::retained( $environment, array() );
		self::assertCount( 3, $retained );
		self::assertSame( $retained, Wstm108_HostObservationTransportAdapter::retained( $environment, $retained ) );
		foreach ( glob( $this->directory . '/*' ) as $path ) {
			$file = Wstm108_Files::file( $path );
			self::assertSame( 0100600, $file['identity']['mode'] );
			self::assertSame( posix_geteuid(), $file['identity']['uid'] );
		}
		$stdout = glob( $this->directory . '/*.stdout.private' )[0];
		file_put_contents( $stdout, 'foreign' );
		try { Wstm108_HostObservationTransportAdapter::retained( $environment, $retained ); self::fail( 'Retained original drift accepted.' ); }
		catch ( Wstm108_TopologyRefusal $error ) { self::assertSame( 78, $error->getCode() ); }
	}

	public function test_transport_clears_ambient_environment_and_closes_stdin(): void {
		putenv( 'BASH_ENV=PRIVATE_SENTINEL' ); putenv( 'LD_PRELOAD=PRIVATE_SENTINEL' );
		try {
			self::assertSame( 'ok', $this->capture( '$env = getenv(); ksort($env);
				if ($env !== array("LC_ALL"=>"C", "PATH"=>"/usr/bin:/bin") || stream_get_contents(STDIN) !== "") { exit(12); }
				echo "ok";' ) );
		} finally { putenv( 'BASH_ENV' ); putenv( 'LD_PRELOAD' ); }
	}

	public static function incomplete(): array {
		return array(
			'nonzero-or-sudo-denial-shape' => array( 'fwrite(STDOUT,"private-prefix"); exit(1);' ),
			'root-system-timeout-exit-shape' => array( 'fwrite(STDOUT,"private-prefix"); exit(124);' ),
			'root-system-kill-exit-shape' => array( 'fwrite(STDOUT,"private-prefix"); exit(137);' ),
			'stderr' => array( 'fwrite(STDOUT,"private-prefix"); fwrite(STDERR,"PRIVATE_SENTINEL");' ),
			'stdout-overflow' => array( 'echo str_repeat("x", 4194306);' ),
			'stderr-overflow' => array( 'fwrite(STDERR,str_repeat("x", 65538));' ),
			'missing-executable' => array( null ),
		);
	}

	/** @dataProvider incomplete */
	public function test_failed_transport_keeps_reserved_private_originals_without_complete_intent( ?string $code ): void {
		try {
			if ( null === $code ) {
				Wstm108_HostObservationTransportAdapter::$test_command = array( $this->directory . '/nonexistent' );
				$method = new ReflectionMethod( Wstm108_HostObservationTransportAdapter::class, 'capture' );
				$method->setAccessible( true );
				$method->invoke( null, Wstm108_HostObservation::command( 'mountinfo', 123 ), $this->handle );
			} else { $this->capture( $code ); }
			self::fail( 'Incomplete transport accepted.' );
		} catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			self::assertSame( 'WSTM108 BLOCKED topology: unreadable-kernel-evidence', $error->getMessage() );
			$intent = $this->intent();
			self::assertSame( 'failed', $intent['state'] );
			self::assertArrayHasKey( 'exit', $intent );
			self::assertArrayHasKey( 'running', $intent );
			self::assertIsBool( $intent['deadline_expired'] );
			if ( null !== $code && false !== strpos( $code, 'exit(' ) ) {
				preg_match( '/exit\(([0-9]+)\)/', $code, $match );
				self::assertSame( (int) $match[1], $intent['exit'] );
				self::assertFalse( $intent['running'] );
				self::assertSame( array( 1 => true, 2 => true ), $intent['eof'] );
			}
			$originals = glob( $this->directory . '/*.private' );
			self::assertCount( 2, $originals );
			foreach ( $originals as $path ) {
				self::assertSame( 0100600, Wstm108_Files::file( $path )['identity']['mode'] );
				$stream = false !== strpos( $path, '.stdout.' ) ? 'stdout' : 'stderr';
				self::assertLessThanOrEqual( 'stdout' === $stream ? 4194305 : 65537, filesize( $path ) );
				$file = Wstm108_Files::file( $path );
				self::assertSame( array( 'identity' => $file['identity'], 'sha256' => $file['sha256'],
					'length' => strlen( $file['bytes'] ) ), $intent['streams'][ $stream ] );
			}
			$environment = array( 'WSTM108_HOST_INSPECTION' => Wstm108_HostObservation::MODE,
				'WSTM108_HOST_OBSERVATION_CUSTODY' => base64_encode( json_encode( $this->handle, JSON_THROW_ON_ERROR ) ) );
			try { Wstm108_HostObservationTransportAdapter::retained( $environment, array() ); self::fail( 'Failed observation released.' ); }
			catch ( Wstm108_TopologyRefusal $refusal ) { self::assertSame( 78, $refusal->getCode() ); }
		}
	}

	public function test_deadline_and_after_execution_tool_identity_race_refuse(): void {
		$deadline = new ReflectionProperty( Wstm108_HostObservationTransportAdapter::class, 'deadline' );
		$deadline->setAccessible( true ); $deadline->setValue( null, hrtime( true ) + 100000000 );
		$before = hrtime( true );
		try { $this->capture( 'fwrite(STDOUT,"private-prefix"); usleep(2000000);' ); self::fail( 'Timeout accepted.' ); }
		catch ( Wstm108_TopologyRefusal $error ) { self::assertSame( 'failed', $this->intent()['state'] ); }
		self::assertLessThan( 3000000000, hrtime( true ) - $before );
		foreach ( glob( $this->directory . '/*' ) as $path ) { self::assertTrue( unlink( $path ) ); }
		$deadline->setValue( null, hrtime( true ) + 20000000000 );
		Wstm108_HostObservationTransportAdapter::$test_tools = 0;
		Wstm108_HostObservationTransportAdapter::$test_race = true;
		try { $this->capture( 'echo "private-prefix";' ); self::fail( 'Tool race accepted.' ); }
		catch ( Wstm108_TopologyRefusal $error ) { self::assertSame( 'failed', $this->intent()['state'] ); }
	}

	public static function hung(): array {
		return array(
			'stdout-remains-open' => array( 'fclose(STDERR); fwrite(STDOUT,"private-prefix"); usleep(8000000);' ),
			'stderr-remains-open' => array( 'fclose(STDOUT); fwrite(STDERR,"PRIVATE_SENTINEL"); usleep(8000000);' ),
			'both-eof-but-process-running' => array( 'fclose(STDOUT); fclose(STDERR); usleep(8000000);' ),
		);
	}

	/** @dataProvider hung */
	public function test_hung_streams_and_eof_do_not_enter_a_blocking_reap( string $code ): void {
		$deadline = new ReflectionProperty( Wstm108_HostObservationTransportAdapter::class, 'deadline' );
		$deadline->setAccessible( true ); $deadline->setValue( null, hrtime( true ) + 200000000 );
		$before = hrtime( true );
		try { $this->capture( $code ); self::fail( 'Hung transport accepted.' ); }
		catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			$intent = $this->intent();
			self::assertSame( 'failed', $intent['state'] );
			self::assertNull( $intent['exit'] );
			self::assertTrue( $intent['running'] );
			self::assertTrue( $intent['deadline_expired'] );
			if ( false !== strpos( $code, 'fclose(STDOUT); fclose(STDERR)' ) ) {
				self::assertSame( array( 1 => true, 2 => true ), $intent['eof'] );
			}
		}
		self::assertLessThan( 2000000000, hrtime( true ) - $before );
		$process = Wstm108_HostObservationTransportAdapter::$test_processes[0];
		self::assertTrue( proc_get_status( $process )['running'], 'Production cleanup must not signal an unobserved child.' );
	}

	public function test_deadline_includes_after_execution_identity_and_private_persistence(): void {
		$deadline = new ReflectionProperty( Wstm108_HostObservationTransportAdapter::class, 'deadline' );
		$deadline->setAccessible( true ); $deadline->setValue( null, hrtime( true ) + 200000000 );
		Wstm108_HostObservationTransportAdapter::$test_delay = true;
		try { $this->capture( 'echo "private-prefix";' ); self::fail( 'Late completion accepted.' ); }
		catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			$intent = $this->intent();
			self::assertSame( 'failed', $intent['state'] );
			self::assertSame( 0, $intent['exit'] );
			self::assertFalse( $intent['running'] );
			self::assertSame( array( 1 => true, 2 => true ), $intent['eof'] );
			self::assertTrue( $intent['deadline_expired'] );
		}
	}

	public function test_observed_exit_zero_does_not_complete_a_stream_with_another_open_writer(): void {
		$deadline = new ReflectionProperty( Wstm108_HostObservationTransportAdapter::class, 'deadline' );
		$deadline->setAccessible( true ); $deadline->setValue( null, hrtime( true ) + 200000000 );
		Wstm108_HostObservationTransportAdapter::$test_hold_eof = true;
		$before = hrtime( true );
		try { $this->capture( 'echo "private-prefix";' ); self::fail( 'Missing EOF accepted after exit zero.' ); }
		catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			$intent = $this->intent();
			self::assertSame( 'failed', $intent['state'] );
			self::assertSame( 0, $intent['exit'] );
			self::assertFalse( $intent['running'] );
			self::assertSame( array( 1 => false, 2 => false ), $intent['eof'] );
			self::assertTrue( $intent['deadline_expired'] );
		}
		self::assertLessThan( 2000000000, hrtime( true ) - $before );
		self::assertTrue( proc_get_status( Wstm108_HostObservationTransportAdapter::$test_processes[0] )['running'] );
		self::assertFalse( proc_get_status( Wstm108_HostObservationTransportAdapter::$test_processes[1] )['running'] );
	}
}
