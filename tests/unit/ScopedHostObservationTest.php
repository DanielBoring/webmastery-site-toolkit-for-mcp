<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-observation.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-authority.php';

final class ScopedHostObservationTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_optin_is_exact_and_default_has_no_privileged_transport(): void {
		self::assertFalse( Wstm108_HostObservation::enabled( array() ) );
		self::assertTrue( Wstm108_HostObservation::enabled( array( 'WSTM108_HOST_INSPECTION' => 'system-readonly-v1' ) ) );
		foreach ( array( '', '1', 'SYSTEM-READONLY-V1', 'system-readonly-v1 ', null, false, array() ) as $value ) {
			try {
				Wstm108_HostObservation::enabled( array( 'WSTM108_HOST_INSPECTION' => $value ) );
				self::fail( 'Invalid mode accepted.' );
			} catch ( Wstm108_TopologyRefusal $error ) {
				self::assertSame( array( 'phase' => 'topology', 'reason' => 'invalid-host-inspection-mode' ),
					Wstm108_HostTopology::failure_witness( $error ) );
				self::assertSame( 78, $error->getCode() );
			}
		}
	}

	public function test_privileged_argv_has_only_fixed_tools_and_fixed_proc_targets(): void {
		$prefix = array( '/usr/bin/timeout', '--signal=TERM', '--kill-after=1s', '8s',
			'/usr/bin/sudo', '-n', '--user=root', '--', '/usr/bin/timeout', '--signal=TERM', '--kill-after=1s', '5s',
			'/usr/bin/env', '-i', 'PATH=/usr/bin:/bin', 'LC_ALL=C' );
		self::assertSame( array_merge( $prefix, array( '/usr/bin/find', '-P', '/proc/123/fd', '-mindepth', '1',
			'-maxdepth', '1', '-printf', '%f\\0%l\\0' ) ), Wstm108_HostObservation::command( 'descriptors', 123 ) );
		self::assertSame( array_merge( $prefix, array( '/usr/bin/stat', '-L', '--printf=%d:%i:%u:%g:%f:%h\n',
			'--', '/proc/123/ns/mnt' ) ), Wstm108_HostObservation::command( 'namespace', 123 ) );
		self::assertSame( array_merge( $prefix, array( '/usr/bin/head', '-c', '4194305', '--',
			'/proc/2147483647/mountinfo' ) ), Wstm108_HostObservation::command( 'mountinfo', 2147483647 ) );
		foreach ( array( 0, -1, 2147483648, PHP_INT_MAX ) as $pid ) {
			try { Wstm108_HostObservation::command( 'descriptors', $pid ); self::fail( 'Foreign PID accepted.' ); }
			catch ( Wstm108_TopologyRefusal $error ) { self::assertSame( 'untrusted-pid-discovery-hint', $error->reason() ); }
		}
		foreach ( array( '../fd', 'descriptors;id', "/ns/mnt\0", '/proc/1/environ', 'exec', '' ) as $operation ) {
			try { Wstm108_HostObservation::command( $operation, 123 ); self::fail( 'Foreign target accepted.' ); }
			catch ( Wstm108_TopologyRefusal $error ) { self::assertSame( 'unreadable-kernel-evidence', $error->reason() ); }
		}
		self::assertSame( array( 'PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C' ), Wstm108_HostObservation::environment() );
		foreach ( array( '123;id', '../1', '1 /etc/shadow', '01', '2147483648' ) as $pid ) {
			try { Wstm108_HostObservation::command( 'descriptors', $pid ); self::fail( 'String PID accepted.' ); }
			catch ( TypeError $error ) { self::assertStringContainsString( 'int', $error->getMessage() ); }
		}
	}

	public function test_descriptor_pairs_preserve_order_and_selected_listener_matching(): void {
		$links = Wstm108_HostObservation::descriptors( "2\0socket:[101]\0" . "10\0/path with\nprivate bytes\0" . "0\0/dev/null\0" );
		self::assertSame( array( 0 => '/dev/null', 10 => "/path with\nprivate bytes", 2 => 'socket:[101]' ), $links );
		self::assertSame( array( '2' ), array_map( 'strval', array_keys( array_filter( $links,
			static fn( $link ) => 'socket:[101]' === $link ) ) ) );
		self::assertSame( array(), array_keys( array_filter( $links, static fn( $link ) => 'socket:[999]' === $link ) ) );
		$bytes = '';
		for ( $i = 0; $i < 8190; ++$i ) { $bytes .= $i . "\0/dev/null\0"; }
		self::assertCount( 8190, Wstm108_HostObservation::descriptors( $bytes ) );
		try { Wstm108_HostObservation::descriptors( $bytes . "8190\0/dev/null\0" ); self::fail( 'Native scandir +2 fd count bound relaxed.' ); }
		catch ( Wstm108_TopologyRefusal $error ) { self::assertSame( 'daemon-descriptors-inaccessible', $error->reason() ); }
	}

	public function test_missing_partial_doubled_malformed_and_overflow_pairs_refuse(): void {
		foreach ( array( '', "1\0", "1\0link", "1\0link\0partial\0", "1\0link\0" . "1\0other\0",
			"01\0link\0", "-1\0link\0", "2147483648\0link\0", "1\0\0", ".\0link\0",
			"1\0" . str_repeat( 'x', 4097 ) . "\0", str_repeat( "1\0x\0", 1048577 ) ) as $bytes ) {
			try { Wstm108_HostObservation::descriptors( $bytes ); self::fail( 'Foreign stream accepted.' ); }
			catch ( Wstm108_TopologyRefusal $error ) {
				self::assertSame( 78, $error->getCode() );
				self::assertStringNotContainsString( $bytes . 'PRIVATE_SENTINEL', $error->getMessage() );
			}
		}
	}

	public function test_namespace_parser_preserves_full_native_stat_shape_and_rejects_partial_bytes(): void {
		self::assertSame( array( 'dev' => 4, 'ino' => 4026531840, 'mode' => 0100444, 'nlink' => 1, 'uid' => 0, 'gid' => 0 ),
			Wstm108_HostObservation::namespace_identity( "4:4026531840:0:0:8124:1\n" ) );
		foreach ( array( '', "4:1:0:0:8124:1", "4:1:0:0:8124:1\n\n", "4:1:0:0:8124:1\nPRIVATE_SENTINEL",
			"4:0:0:0:8124:1\n", "4:1:0:0:xyz:1\n", "4:1:0:0:fffffffff:1\n",
			"9223372036854775808:1:0:0:8124:1\n", "4:18446744073709551615:0:0:8124:1\n" ) as $bytes ) {
			try { Wstm108_HostObservation::namespace_identity( $bytes ); self::fail( 'Foreign namespace accepted.' ); }
			catch ( Wstm108_TopologyRefusal $error ) { self::assertSame( 'unreadable-kernel-identity', $error->reason() ); }
		}
	}

	public function test_only_controller_and_authority_helpers_receive_bound_observation_custody(): void {
		$environment = array( 'WSTM108_HOST_INSPECTION' => Wstm108_HostObservation::MODE,
			'WSTM108_HOST_OBSERVATION_CUSTODY' => 'private-bound-handle', 'BASH_ENV' => '/bad',
			'ENV' => '/bad', 'GITHUB_TOKEN' => 'secret', 'WSTM108_EXPORT_CHANNEL' => 'secret' );
		$helper = Wstm108_HostController::child_environment( $environment );
		self::assertSame( array_slice( $environment, 0, 2 ), $helper );
		$candidate = Wstm108_HostAuthority::child_environment( $environment );
		self::assertSame( array(), $candidate );
		$bootstrap = $this->read( 'scripts/untrusted-host-bootstrap.sh' );
		self::assertStringContainsString( 'if [[ -v WSTM108_HOST_INSPECTION ]]; then', $bootstrap );
		self::assertStringContainsString( 'inspection_args=( WSTM108_HOST_INSPECTION="$WSTM108_HOST_INSPECTION" )', $bootstrap );
		self::assertStringContainsString( '"${inspection_args[@]}"', $bootstrap );
		foreach ( array( 'e2e-qa.yml' => 2, 'compatibility-qa.yml' => 4, 'release-package-qa.yml' => 1, 'release.yml' => 1 ) as $workflow => $count ) {
			$bytes = $this->read( '.github/workflows/' . $workflow );
			self::assertSame( $count, substr_count( $bytes, 'WSTM108_HOST_AUTHORITY_ROOT: ${{ runner.temp }}' ) );
			self::assertSame( $count, substr_count( $bytes, "WSTM108_HOST_AUTHORITY_ROOT: \${{ runner.temp }}\n          WSTM108_HOST_INSPECTION: system-readonly-v1" ) );
			self::assertStringNotContainsString( 'self-hosted', $bytes );
		}
		self::assertStringContainsString( "'scripts/untrusted-host-observation.php'", $this->read( 'scripts/untrusted-provenance.php' ) );
	}

	public function test_native_tool_header_rejects_scripts_partial_and_foreign_elf(): void {
		$header = "\x7fELF\x02\x01\x01\x00" . str_repeat( "\0", 8 )
			. pack( 'vvV', 3, 62, 1 ) . str_repeat( "\0", 28 ) . pack( 'vvv', 64, 56, 9 ) . str_repeat( "\0", 6 );
		self::assertSame( hash( 'sha256', $header ), Wstm108_HostObservation::elf_header( $header ) );
		$arm = substr_replace( $header, pack( 'v', 183 ), 18, 2 );
		self::assertSame( hash( 'sha256', $arm ), Wstm108_HostObservation::elf_header( $arm ) );
		foreach ( array( '', "#!/bin/sh\nPRIVATE_SENTINEL", substr( $header, 0, 63 ), $header . "\0",
			substr_replace( $header, "\x01", 4, 1 ), substr_replace( $header, "\x02", 5, 1 ),
			substr_replace( $header, "\x09", 7, 1 ), substr_replace( $header, pack( 'v', 1 ), 16, 2 ),
			substr_replace( $header, pack( 'v', 3 ), 18, 2 ), substr_replace( $header, pack( 'V', 0 ), 20, 4 ),
			substr_replace( $header, pack( 'v', 0 ), 52, 2 ), substr_replace( $header, pack( 'v', 0 ), 54, 2 ),
			substr_replace( $header, pack( 'v', 0 ), 56, 2 ) ) as $bytes ) {
			try { Wstm108_HostObservation::elf_header( $bytes ); self::fail( 'Script or malformed tool header accepted.' ); }
			catch ( Wstm108_TopologyRefusal $error ) { self::assertSame( 'unreadable-kernel-evidence', $error->reason() ); }
		}
	}

	public function test_system_checks_capture_and_peer_predicates_remain_explicit(): void {
		$observer = $this->read( 'scripts/untrusted-host-observation.php' );
		foreach ( array( 'posix_geteuid() > 0', "'/run/docker.pid'", "'/run/docker.sock'",
			"'start_ticks' => \$fields[19]", '$scope === self::scope( $pid )', '$pid === (int) trim( $hint[\'bytes\'] )',
			'0 === $identity[\'uid\']', '0 === ( $identity[\'mode\'] & 0022 )',
			"'/usr/bin/sudo' === \$path || 0 === ( \$identity['mode'] & 06000 )",
			'realpath( $path ) === $path', "'elf_header_sha256'",
			'self::tools( $command ) === $tools', 'self::persist( $streams[ $fd ], $bytes )',
			'$eof[1] && $eof[2]', '0 === $exit && 0 === $lengths[2]', 'hrtime( true ) < $deadline',
			'Wstm108_Files::read_bound', 'Wstm108_Files::assert_file', 'fsync( $record )', '++self::$count <= 64' ) as $predicate ) {
			self::assertStringContainsString( $predicate, $observer );
		}
		foreach ( array( 'shell_exec(', 'sudo -E', 'nsenter', 'chmod(', 'chown(', 'setcap', 'catch ( Throwable',
			'docker exec', 'proc_close(', 'proc_terminate(' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $observer );
		}
		$peer = $this->read( 'scripts/untrusted-host-topology.php' );
		foreach ( array( "0 === \$identity['uid']", "ctype_digit( \$fields[19] )",
			'count( $descriptors ) <= 8192', "is_string( \$link )", "'socket:[' . \$listener[0] . ']'",
			'array() !== $matched', "\$namespace['dev'] === \$self_namespace['dev']",
			"\$namespace['ino'] === \$self_namespace['ino']", '$identity === self::stat( $process )',
			'$before === self::peer( $socket, $pid )', '$socket_before === self::stat( $socket )',
			'$mountinfo === $daemon_mountinfo', "Wstm108_Files::assert_file( '/run/docker.pid', \$hint )" ) as $predicate ) {
			self::assertStringContainsString( $predicate, $peer );
		}
	}

	public function test_transport_adapter_compiles_without_invoking_any_process_or_linux_read(): void {
		require_once __DIR__ . '/ScopedHostObservationTransportTest.php';
		$method = new ReflectionMethod( ScopedHostObservationTransportTest::class, 'load_adapter' );
		$method->setAccessible( true ); $method->invoke( null );
		self::assertTrue( class_exists( 'Wstm108_HostObservationTransportAdapter', false ) );
	}
}
