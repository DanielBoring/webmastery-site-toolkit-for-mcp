<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-authority.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php';

/** Portable controls only: synthetic rows and parsed status never grant native proof. */
final class UntrustedKernelMountTest extends TestCase {
	private static function status_bytes(): string {
		return "Name:\tphp\nPid:\t41\nTgid:\t41\nThreads:\t1\nNoNewPrivs:\t1\n"
			. "Uid:\t1000\t1000\t1000\t1000\nGid:\t1001\t1001\t1001\t1001\nGroups:\t1002 1001 \n"
			. "CapEff:\t0000000000000000\nCapPrm:\t0000000000000000\n"
			. "CapInh:\t0000000000000000\nCapAmb:\t0000000000000000\nCapBnd:\tffffffffffffffff\n";
	}

	private static function allowance(): array {
		return array( 'ns' => 8000000000, 'launches' => 8, 'bytes' => 16777216 );
	}

	private function refuses( callable $call, string $reason ): void {
		try { $call(); self::fail( 'This input must refuse.' ); }
		catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			self::assertSame( array( 'phase' => 'topology', 'reason' => $reason ), Wstm108_HostTopology::failure_witness( $error ) );
		}
	}

	public function test_both_safe_preexec_lanes_keep_full_width_bounding_set(): void {
		$status = Wstm108_KernelMountModel::status( self::status_bytes() );
		self::assertSame( 'ffffffffffffffff', $status['CapBnd'] );
		self::assertSame( array( 1001, 1002 ), $status['Groups'] );
		Wstm108_KernelMountModel::safe_exec( $status, 1000, 1001, array( 1002, 1001 ) );
		$status['NoNewPrivs'] = 0;
		$status['CapBnd'] = '0000000000000000';
		Wstm108_KernelMountModel::safe_exec( $status, 1000, 1001, array( 1001, 1002 ) );
		self::assertSame( 0, $status['NoNewPrivs'] );
	}

	public static function malformed_status(): array {
		$base = self::status_bytes();
		$cases = array( array( '' ), array( substr( $base, 0, -1 ) ), array( $base . "Pid:\t41\n" ),
			array( $base . "bad line\n" ), array( str_repeat( 'x', 65537 ) . "\n" ) );
		foreach ( array( 'Pid', 'Tgid', 'Threads', 'NoNewPrivs', 'Uid', 'Gid', 'Groups', 'CapEff', 'CapPrm', 'CapInh', 'CapAmb', 'CapBnd' ) as $field ) {
			$cases[] = array( preg_replace( '/^' . $field . ':.*\n/m', '', $base ) );
		}
		foreach ( array(
			array( 'Pid', '0' ), array( 'Pid', '9223372036854775808' ), array( 'Tgid', '42' ),
			array( 'Threads', '2' ), array( 'NoNewPrivs', '2' ), array( 'NoNewPrivs', '01' ),
			array( 'Uid', '1000 1000 1000' ), array( 'Gid', '-1 -1 -1 -1' ),
			array( 'Groups', '1001 1001' ), array( 'CapEff', '0' ), array( 'CapBnd', '10000000000000000' ),
			array( 'CapAmb', '000000000000000g' ),
		) as $mutation ) {
			$cases[] = array( preg_replace( '/^' . $mutation[0] . ':.*$/m', $mutation[0] . ":\t" . $mutation[1], $base ) );
		}
		return $cases;
	}

	/** @dataProvider malformed_status */
	public function test_missing_duplicate_malformed_overflow_status_refuses( string $bytes ): void {
		$this->refuses( static fn() => Wstm108_KernelMountModel::status( $bytes ), 'unreadable-kernel-evidence' );
	}

	public static function unsafe_credentials(): array {
		$cases = array();
		foreach ( array( 'Uid', 'Gid' ) as $field ) {
			foreach ( range( 0, 3 ) as $index ) { $cases[] = array( $field, $index ); }
		}
		foreach ( array( 'CapEff', 'CapPrm', 'CapInh', 'CapAmb', 'CapBnd', 'Groups', 'NoNewPrivs', 'Threads' ) as $field ) {
			$cases[] = array( $field, null );
		}
		return $cases;
	}

	/** @dataProvider unsafe_credentials */
	public function test_no_saved_fs_cap_group_thread_or_gate_shortcut( string $field, ?int $index ): void {
		$status = Wstm108_KernelMountModel::status( self::status_bytes() );
		if ( null !== $index ) { $status[ $field ][ $index ] = 0; }
		elseif ( 'Groups' === $field ) { $status['Groups'][] = 0; }
		elseif ( 'Threads' === $field ) { $status['Threads'] = 2; }
		elseif ( 'NoNewPrivs' === $field ) { $status['NoNewPrivs'] = 2; }
		else { $status[ $field ] = '8000000000000000'; $status['NoNewPrivs'] = 0; }
		$this->refuses( static fn() => Wstm108_KernelMountModel::safe_exec( $status, 1000, 1001, array( 1001, 1002 ) ),
			'native-coordinate-prerequisite' );
	}

	public static function malformed_fdinfo(): array {
		return array_map( static fn( $value ) => array( $value ), array(
			'', "pos:\t0\nflags:\t012400002\nmnt_id:\t2\nino:\t3\n",
			"pos:\t0\nflags:\t012000000\nmnt_id:\t2\nino:\t3\n",
			"pos:\t0\nflags:\t012400001\nmnt_id:\t2\nino:\t3\n",
			"pos:\t1\nflags:\t012400000\nmnt_id:\t2\nino:\t3\n",
			"pos:\t0\nflags:\t012400000\nmnt_id:\t0\nino:\t3\n",
			"pos:\t0\nflags:\t012400000\nmnt_id:\t2\nino:\t3\nmnt_id:\t2\n",
			str_repeat( 'x', 65537 ),
		) );
	}

	/** @dataProvider malformed_fdinfo */
	public function test_fdinfo_cannot_omit_path_nofollow_cloexec_or_add_fields( string $bytes ): void {
		$this->refuses( static fn() => Wstm108_KernelMountModel::fdinfo( $bytes ), 'unreadable-kernel-evidence' );
	}

	public function test_fdinfo_positive_is_only_parsed_identity(): void {
		$flags = decoct( 0x2a0000 );
		self::assertSame( array( 'mount_id' => 2, 'ino' => 3 ),
			Wstm108_KernelMountModel::fdinfo( "pos:\t0\nflags:\t$flags\nmnt_id:\t2\nino:\t3\n" ) );
	}

	private static function table(): string {
		return "90 0 8:1 / / rw - ext4 /dev/root rw\n"
			. "71 90 0:5 / /stack rw - proc proc rw\n"
			. "13 90 0:6 / /stack rw - sysfs sysfs rw\n"
			. "99 90 0:7 / /stack rw - nsfs nsfs rw\n";
	}

	public function test_structural_rows_preserve_order_but_no_pure_stack_admission(): void {
		$mounts = Wstm108_HostTopology::structural_mounts( self::table() );
		self::assertSame( array( 90, 71, 13, 99 ), array_column( $mounts, 'id' ) );
		self::assertTrue( Wstm108_HostTopology::stacked( $mounts ) );
		$this->refuses( static fn() => Wstm108_HostTopology::mounts( self::table() ), 'ambiguous-stacked-mount' );
		$this->refuses( static fn() => Wstm108_HostTopology::coordinate( '/stack', $mounts ), 'ambiguous-stacked-mount' );
		$this->refuses( static fn() => Wstm108_HostTopology::outside( '/stack', array( '/workspace' ), $mounts ), 'ambiguous-stacked-mount' );
	}

	public function test_identity_only_selection_is_neither_order_maximum_nor_filesystem_grant(): void {
		$mounts = Wstm108_HostTopology::structural_mounts( self::table() );
		foreach ( array( $mounts, array_reverse( $mounts ), array( $mounts[2], $mounts[0], $mounts[3], $mounts[1] ) ) as $order ) {
			self::assertSame( 13, Wstm108_KernelMountModel::selected_row( '/stack/node', $order, 13, '0:6' )['id'] );
			$this->refuses( static fn() => Wstm108_KernelMountModel::coordinate( '/stack/node', $order, 13, '0:6' ),
				'unsupported-filesystem-coordinate' );
			self::assertSame( 90, Wstm108_KernelMountModel::selected_row( '/stack/hidden', $order, 90, '8:1' )['id'] );
		}
		$this->refuses( static fn() => Wstm108_KernelMountModel::selected_row( '/elsewhere', $mounts, 13, '0:6' ),
			'kernel-coordinate-or-identity-disagrees' );
		$this->refuses( static fn() => Wstm108_KernelMountModel::selected_row( '/stack', $mounts, 13, '0:7' ),
			'kernel-coordinate-or-identity-disagrees' );
		$this->refuses( static fn() => Wstm108_KernelMountModel::selected_row( '/stack', $mounts, 100, '0:6' ),
			'kernel-coordinate-or-identity-disagrees' );
	}

	public function test_syntax_does_not_adopt_duplicate_ids_overflow_partial_or_bad_escapes(): void {
		foreach ( array( self::table() . "13 90 0:8 / /other rw - tmpfs tmpfs rw\n",
			"9223372036854775808 0 8:1 / / rw - ext4 /dev/root rw\n" ) as $bytes ) {
			$this->refuses( static fn() => Wstm108_HostTopology::structural_mounts( $bytes ), 'ambiguous-stacked-mount' );
		}
		$this->refuses( static fn() => Wstm108_HostTopology::structural_mounts( substr( self::table(), 0, -1 ) ), 'partial-mount-table' );
		$this->refuses( static fn() => Wstm108_HostTopology::structural_mounts( "1 0 8:1 / /bad\\999 rw - ext4 /dev/root rw\n" ),
			'unknown-mount-escape' );
	}

	public function test_same_device_whole_root_nested_and_exact_file_aliases_are_exposures(): void {
		$mounts = Wstm108_HostTopology::structural_mounts(
			"1 0 8:1 / / rw - ext4 /dev/root rw\n2 1 0:42 / /private rw - tmpfs tmpfs rw\n"
			. "3 1 0:42 / /workspace/nested rw - tmpfs tmpfs rw\n"
			. "4 1 0:42 /owned /bind rw - tmpfs tmpfs rw\n" );
		$owned = Wstm108_KernelMountModel::coordinate( '/private/owned', $mounts, 2, '0:42' );
		foreach ( array( array( '/workspace/nested', 3 ), array( '/bind', 4 ) ) as $probe ) {
			$exposure = Wstm108_KernelMountModel::coordinate( $probe[0], $mounts, $probe[1], '0:42' );
			self::assertTrue( Wstm108_KernelMountModel::exposes( $owned, $exposure, true ) );
		}
		self::assertFalse( Wstm108_KernelMountModel::exposes( $owned, array( 'device' => '0:42', 'path' => '/' ), false ) );
		self::assertTrue( Wstm108_KernelMountModel::exposes( $owned, array( 'device' => '0:42', 'path' => '/owned' ), false ) );
		self::assertTrue( Wstm108_KernelMountModel::exposes( $owned, array( 'device' => '0:42', 'path' => '/owned/receipt' ), false ) );
		self::assertTrue( Wstm108_KernelMountModel::exposes( $owned, array( 'device' => '0:42', 'path' => '/owned/child' ), true ) );
		self::assertFalse( Wstm108_KernelMountModel::exposes( $owned, array( 'device' => '8:1', 'path' => '/' ), true ) );
		self::assertFalse( Wstm108_KernelMountModel::exposes( $owned, array( 'device' => '0:42', 'path' => '/own' ), true ) );
	}

	public function test_shared_allowance_debits_failed_work_and_parent_reservations_without_refund(): void {
		$parent = self::allowance();
		$child = array( 'ns' => 4000000000, 'launches' => 4, 'bytes' => 8388608 );
		$parent = Wstm108_KernelMountModel::spend( $parent, $child['ns'], $child['launches'], $child['bytes'] );
		$child = Wstm108_KernelMountModel::spend( $child, 1500000000, 1, 100 );
		$child = Wstm108_KernelMountModel::spend( $child, 2500000000, 1, 100 );
		self::assertSame( 0, $child['ns'] );
		self::assertSame( 4000000000, $parent['ns'] );
		$this->refuses( static fn() => Wstm108_KernelMountModel::spend( $child, 1, 0, 0 ), 'unreadable-kernel-evidence' );
		$this->refuses( static fn() => Wstm108_KernelMountModel::spend( $parent, -1, 0, 0 ), 'unreadable-kernel-evidence' );
		$this->refuses( static fn() => Wstm108_KernelMountModel::spend( $parent, 0, 5, 0 ), 'unreadable-kernel-evidence' );
		$this->refuses( static fn() => Wstm108_KernelMountModel::spend( $parent, 0, 0, 8388609 ), 'unreadable-kernel-evidence' );
	}

	public function test_existing_deadline_and_shutdown_reservation_never_expand(): void {
		$now = 1000000000;
		$outer = $now + 1500000000;
		self::assertSame( $outer, Wstm108_KernelMountModel::deadline( $outer, $now, self::allowance() ) );
		$this->refuses( static fn() => Wstm108_KernelMountModel::deadline( $outer, $outer, self::allowance() ), 'native-coordinate-prerequisite' );
		$remaining = Wstm108_KernelMountModel::spend( self::allowance(), 7000000000, 0, 0 );
		$this->refuses( static fn() => Wstm108_KernelMountModel::deadline( $now + 120000000000, $now, $remaining ),
			'native-coordinate-prerequisite' );
		$remaining = Wstm108_KernelMountModel::spend( self::allowance(), 0, 8, 0 );
		$this->refuses( static fn() => Wstm108_KernelMountModel::deadline( $outer, $now, $remaining ), 'native-coordinate-prerequisite' );
	}

	public function test_models_receipts_booleans_and_callbacks_cannot_create_live_sessions(): void {
		self::assertTrue( ( new ReflectionClass( Wstm108_KernelMounts::class ) )->isFinal() );
		self::assertTrue( ( new ReflectionMethod( Wstm108_KernelMounts::class, '__construct' ) )->isPrivate() );
		self::assertTrue( ( new ReflectionMethod( Wstm108_KernelMounts::class, '__clone' ) )->isPrivate() );
		$this->refuses( static fn() => Wstm108_KernelMounts::outside( '/private', array( '/workspace' ),
			Wstm108_HostTopology::structural_mounts( self::table() ) ), 'native-coordinate-prerequisite' );
		foreach ( array( 'from_json', 'from_receipt', 'adopt', 'set_collector', 'from_nonce' ) as $method ) {
			self::assertFalse( method_exists( Wstm108_KernelMounts::class, $method ) );
		}
	}

	public function test_current_source_binding_and_private_channel_scrubbing(): void {
		$root = dirname( __DIR__, 2 );
		$source = file_get_contents( $root . '/scripts/untrusted-kernel-mounts.php' );
		preg_match( "/HOLDER_SHA256 = '([a-f0-9]{64})'/", $source, $match );
		self::assertSame( hash_file( 'sha256', $root . '/scripts/untrusted-kernel-mount-holder.py' ), $match[1] );
		$environment = array( 'WSTM108_KERNEL_ALLOCATION' => 'forged', 'WSTM108_KERNEL_CUSTODY' => 'forged',
			'WSTM108_HOST_INSPECTION' => 'system-readonly-v1', 'WSTM108_HOST_OBSERVATION_CUSTODY' => 'private', 'PATH' => 'kept' );
		self::assertSame( array( 'PATH' => 'kept' ), Wstm108_HostAuthority::child_environment( $environment ) );
		self::assertCount( 37, Wstm108_HostTopology::REFUSAL_REASONS );
		self::assertStringContainsString( 'untrusted-kernel-mount-holder.py', file_get_contents( $root . '/scripts/untrusted-provenance.php' ) );
	}

	public function test_current_live_consumers_keep_independent_preexec_ready_and_finalization_order(): void {
		$root = dirname( __DIR__, 2 );
		$source = file_get_contents( $root . '/scripts/untrusted-kernel-mounts.php' );
		$markers = array(
			'Wstm108_KernelMountModel::safe_exec( $parent',
			'$process = proc_open( $argv',
			"self::require( array( 'ready' => 1 ) ===",
			'$child = self::actor( $pid',
			'Wstm108_KernelMountModel::safe_exec( $child',
			'$gather = static function',
			'$gather( $paths, $authority, $sources )',
			'$exit = $status[\'exitcode\'];',
			"'state' => 'complete'",
		);
		$offset = 0;
		foreach ( $markers as $marker ) {
			$position = strpos( $source, $marker, $offset );
			self::assertNotFalse( $position, $marker );
			$offset = $position + strlen( $marker );
		}
		self::assertStringContainsString( 'hash_final( hash_copy( $hashes[ $suffix ] ) )', $source );
		self::assertStringContainsString( 'self::$remaining = Wstm108_KernelMountModel::spend', $source );
		self::assertStringContainsString( 'self::$remaining[\'launches\'] - 3', $source );
		self::assertStringNotContainsString( 'register_shutdown_function', $source );
		self::assertStringNotContainsString( '__destruct', $source );
		$controller = file_get_contents( $root . '/scripts/untrusted-host-controller.php' );
		self::assertStringContainsString( 'reserve_helper()', $controller );
		self::assertStringContainsString( 'import_kernel_captures()', $controller );
		self::assertStringContainsString( "fopen( \$path, 'rb' )", $controller );
		self::assertStringContainsString( '$precommit = function (): bool {' . "\n\t\t\t" . '\Wstm108_KernelMounts::finish_scope();', $controller );
		$authority = file_get_contents( $root . '/scripts/untrusted-authority.php' );
		self::assertStringContainsString( 'pause_for_capture()', $authority );
		self::assertStringContainsString( "5 => array( 'file', '/dev/null', 'w' )", $authority );
		self::assertStringContainsString( 'Wstm108_HostTopology::stacked', $authority );
		self::assertStringContainsString( 'Wstm108_KernelMounts::visibility', file_get_contents( $root . '/scripts/untrusted-host-topology.php' ) );
		self::assertStringContainsString( 'Wstm108_KernelMounts::observation_deadline', file_get_contents( $root . '/scripts/untrusted-host-observation.php' ) );
		self::assertStringContainsString( 'Wstm108_HostAuthority::discover_mounts', file_get_contents( $root . '/scripts/qa-runtime.php' ) );
		$release = file_get_contents( $root . '/scripts/untrusted-release.php' );
		self::assertSame( 1, substr_count( $release, '@unlink( $path )' ) );
		self::assertMatchesRegularExpression( '~self::require\( @unlink\( \$path \), \'[^\']+\' \);\s*}\s*}\s*$~D', $release );
	}
}
