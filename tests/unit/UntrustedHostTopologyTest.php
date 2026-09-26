<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php';

final class UntrustedHostTopologyTest extends TestCase {
	private static function table(): string {
		return "1 0 8:1 / / rw - ext4 /dev/root rw\n"
			. "2 1 0:42 / /private rw - tmpfs tmpfs rw\n";
	}

	public function test_distinct_filesystem_coordinates_are_not_textual_aliases(): void {
		$mounts = Wstm108_HostTopology::mounts( self::table() );
		self::assertSame( array( 'device' => '0:42', 'path' => '/owned', 'mount_id' => 2, 'type' => 'tmpfs' ),
			Wstm108_HostTopology::coordinate( '/private/owned', $mounts ) );
		Wstm108_HostTopology::outside( '/private/owned', array( '/workspace', '/var/lib/docker' ), $mounts );
		self::assertCount( 2, $mounts );
	}

	public static function aliases(): array {
		return array(
			'whole filesystem alias' => array( "3 1 0:42 / /visible rw - tmpfs tmpfs rw\n", '/visible' ),
			'root-relative child alias' => array( "3 1 0:42 /owned /visible rw - tmpfs tmpfs rw\n", '/visible' ),
			'nested bind exposes private filesystem' => array( "3 1 0:42 / /workspace/nested rw - tmpfs tmpfs rw\n", '/workspace' ),
			'nested bind exposes exact private root' => array( "3 1 0:42 /owned /workspace/nested rw - tmpfs tmpfs rw\n", '/workspace' ),
		);
	}

	/** @dataProvider aliases */
	public function test_nonsymlink_and_nested_alias_exposures_are_rejected( string $extra, string $source ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'physical-bind-exposes-authority' );
		Wstm108_HostTopology::outside( '/private/owned', array( $source ), Wstm108_HostTopology::mounts( self::table() . $extra ) );
	}

	public static function unsupported_tables(): array {
		return array(
			array( "1 0 8:1 / / rw - overlay overlay rw\n" ),
			array( "1 0 8:1 / / rw idmapped - ext4 /dev/root rw\n" ),
			array( "1 0 8:1 / / rw - ext4 /dev/root rw" ),
			array( self::table() . "3 1 0:42 / /private rw - tmpfs tmpfs rw\n" ),
			array( "1 0 8:1 / / rw - ext4 /dev/root rw\n2 1 0:42 / /private\\000bad rw - tmpfs tmpfs rw\n" ),
		);
	}

	public static function ambiguous_mount_predicates(): array {
		return array(
			'nonpositive cast ID' => array( "0 0 8:1 / / rw - ext4 synthetic rw\n" ),
			'duplicate ID' => array( self::table() . "2 1 0:42 / /different rw - tmpfs synthetic rw\n" ),
			'duplicate canonical point' => array( self::table() . "3 1 0:42 / /private/ rw - tmpfs synthetic rw\n" ),
		);
	}

	/** @dataProvider ambiguous_mount_predicates */
	public function test_each_code_six_predicate_refuses_independently( string $bytes ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 78 );
		$this->expectExceptionMessage( 'WSTM108 BLOCKED topology: ambiguous-stacked-mount' );
		Wstm108_HostTopology::mounts( $bytes );
	}

	/** @dataProvider unsupported_tables */
	public function test_unknown_or_ambiguous_topology_is_blocked( string $bytes ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 78 );
		Wstm108_HostTopology::coordinate( '/private', Wstm108_HostTopology::mounts( $bytes ) );
	}

	private static function identity(): array {
		return array( 'dev' => 42, 'ino' => 10, 'mode' => 0040700, 'nlink' => 2, 'uid' => 1000, 'gid' => 1000 );
	}

	public function test_owned_mkdir_changes_only_the_recorded_parent_link_count(): void {
		$before = self::identity();
		$after = $before; ++$after['nlink'];
		$child = $before; ++$child['ino'];
		$transition = Wstm108_HostTopology::owned_child_transition( $before, $after, $child );
		self::assertSame( $before, $transition['before'] );
		self::assertSame( 3, $transition['after_nlink'] );
		self::assertSame( $child, $transition['child'] );
	}

	public static function transition_mutations(): array {
		return array_map( static fn( $key ) => array( $key ), array( 'ino', 'dev', 'uid', 'gid', 'mode', 'nlink', 'child-mode', 'child-owner', 'child-device', 'extra-key' ) );
	}

	/** @dataProvider transition_mutations */
	public function test_foreign_or_unexplained_root_transition_is_not_adopted( string $key ): void {
		$before = self::identity(); $after = $before; ++$after['nlink'];
		$child = $before; ++$child['ino'];
		if ( 'child-mode' === $key ) { $child['mode'] = 0040755; }
		elseif ( 'child-owner' === $key ) { ++$child['uid']; }
		elseif ( 'child-device' === $key ) { ++$child['dev']; }
		elseif ( 'extra-key' === $key ) { $after['adopted'] = true; }
		else { ++$after[ $key ]; }
		$this->expectException( RuntimeException::class );
		Wstm108_HostTopology::owned_child_transition( $before, $after, $child );
	}

	public function test_native_admission_never_treats_windows_as_posix(): void {
		if ( 'Linux' === PHP_OS_FAMILY ) {
			$this->expectException( RuntimeException::class );
			Wstm108_HostTopology::admit( 'tcp://remote:2375' );
			return;
		}
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'native-linux-prerequisite' );
		Wstm108_HostTopology::admit( 'unix:///run/docker.sock' );
	}
}
