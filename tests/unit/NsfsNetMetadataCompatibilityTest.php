<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php';

/** Portable parser/model controls do not establish native mount custody. */
final class NsfsNetMetadataCompatibilityTest extends TestCase {
	private function refuses( callable $call, string $reason ): void {
		try { $call(); self::fail( 'Input must refuse.' ); }
		catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			self::assertSame( array( 'phase' => 'topology', 'reason' => $reason ), Wstm108_HostTopology::failure_witness( $error ) );
		}
	}

	private function table( string $root = 'net:[1]', string $type = 'nsfs', string $point = '/namespace' ): string {
		return "1 0 8:1 / / rw - ext4 /dev/root rw\n"
			. "2 1 0:4 $root $point rw shared:5 - $type none rw\n";
	}

	public function test_minimum_maximum_and_same_width_boundaries_preserve_exact_rows_in_both_modes(): void {
		foreach ( array( 'net:[1]', 'net:[999999999]', 'net:[1000000000]', 'net:[4294967294]', 'net:[4294967295]' ) as $root ) {
			$expected = array(
				array( 'id' => 1, 'parent' => 0, 'device' => '8:1', 'root' => '/', 'point' => '/', 'type' => 'ext4' ),
				array( 'id' => 2, 'parent' => 1, 'device' => '0:4', 'root' => $root, 'point' => '/namespace', 'type' => 'nsfs' ),
			);
			foreach ( array( 'mounts', 'structural_mounts' ) as $parser ) {
				self::assertSame( $expected, Wstm108_HostTopology::$parser( $this->table( $root ) ) );
			}
		}
	}

	public function test_no_other_relative_pair_or_lookup_field_gets_an_exception(): void {
		$bad = array( 'net:[0]', 'net:[01]', 'net:[4294967296]', 'net:[9999999999]', 'net:[10000000000]',
			'net:[+1]', 'net:[-1]', 'net:[1]suffix', 'net:[1]/', 'net:[1]\\040', 'net:[1]\\011',
			'net:[1]\\012', "net:[1]\0", "net:[1]\x7f", 'mnt:[1]', 'user:[1]', 'NET:[1]', 'relative' );
		foreach ( array( 'mounts', 'structural_mounts' ) as $parser ) {
			foreach ( $bad as $root ) {
				$this->refuses( fn() => Wstm108_HostTopology::$parser( $this->table( $root ) ), 'noncanonical-path' );
			}
			foreach ( array( 'ext4', 'tmpfs', 'cgroup', 'cgroup2', 'proc', 'unknownfs', 'NSFS', '' ) as $type ) {
				$this->refuses( fn() => Wstm108_HostTopology::$parser( $this->table( 'net:[1]', $type ) ), 'noncanonical-path' );
			}
			$this->refuses( fn() => Wstm108_HostTopology::$parser( $this->table( 'net:[1]', 'nsfs', 'net:[1]' ) ), 'noncanonical-path' );
		}
		$mounts = Wstm108_HostTopology::structural_mounts( $this->table() );
		$this->refuses( static fn() => Wstm108_HostTopology::coordinate( 'net:[1]', $mounts ), 'noncanonical-path' );
		$this->refuses( static fn() => Wstm108_KernelMountModel::coordinate( 'net:[1]', $mounts, 2, '0:4' ), 'noncanonical-path' );
	}

	public function test_structural_limits_ids_tags_decoder_and_eof_still_apply_after_metadata_acceptance(): void {
		$base = $this->table();
		foreach ( array( 'mounts', 'structural_mounts' ) as $parser ) {
			foreach ( array(
				array( substr( $base, 0, -1 ), 'partial-mount-table' ),
				array( str_repeat( 'x', 4194305 ) . "\n", 'partial-mount-table' ),
				array( str_repeat( "1 0 8:1 / / rw - ext4 none rw\n", 8193 ), 'mount-table-limit' ),
				array( $base . "2 1 0:4 net:[1] /other rw - nsfs none rw\n", 'ambiguous-stacked-mount' ),
				array( str_replace( 'shared:5', 'unreviewed:5', $base ), 'unsupported-mount-mapping' ),
				array( str_replace( 'net:[1]', 'net:\\1331]', $base ), 'unknown-mount-escape' ),
				array( str_replace( 'nsfs none rw', 'nsfs none', $base ), 'malformed-mount-record' ),
			) as $case ) {
				$this->refuses( static fn() => Wstm108_HostTopology::$parser( $case[0] ), $case[1] );
			}
		}
	}

	public function test_both_coordinate_gates_refuse_nsfs_before_any_root_join(): void {
		$mounts = Wstm108_HostTopology::structural_mounts( $this->table() );
		foreach ( array( '/namespace', '/namespace/child' ) as $path ) {
			$this->refuses( static fn() => Wstm108_HostTopology::coordinate( $path, $mounts ), 'unsupported-filesystem-coordinate' );
			$this->refuses( static fn() => Wstm108_KernelMountModel::coordinate( $path, $mounts, 2, '0:4' ), 'unsupported-filesystem-coordinate' );
		}
		self::assertSame( 'net:[1]', Wstm108_KernelMountModel::selected_row( '/namespace', $mounts, 2, '0:4' )['root'] );
		$this->refuses( static fn() => Wstm108_KernelMountModel::selected_row( '/namespace', $mounts, 99, '8:1' ), 'kernel-coordinate-or-identity-disagrees' );
		$this->refuses( static fn() => Wstm108_KernelMountModel::selected_row( '/namespace', $mounts, 2, '8:1' ), 'kernel-coordinate-or-identity-disagrees' );
	}

	public function test_nested_authority_and_source_mounts_remain_in_physical_closure(): void {
		foreach ( array( '/authority/ns', '/source/ns' ) as $point ) {
			$mounts = Wstm108_HostTopology::structural_mounts( $this->table( 'net:[1]', 'nsfs', $point ) );
			self::assertCount( 2, $mounts );
			self::assertTrue( Wstm108_KernelMountModel::contains( dirname( $point ), $mounts[1]['point'] ) );
			$this->refuses( static fn() => Wstm108_HostTopology::coordinate( $point, $mounts ), 'unsupported-filesystem-coordinate' );
			$this->refuses( static fn() => Wstm108_KernelMountModel::coordinate( $point, $mounts, 2, '0:4' ), 'unsupported-filesystem-coordinate' );
			if ( '/source/ns' === $point ) {
				$this->refuses( static fn() => Wstm108_HostTopology::outside( '/authority', array( '/source' ), $mounts ), 'unsupported-filesystem-coordinate' );
			}
		}
	}

	public function test_whole_table_comparison_and_duplicate_visibility_cannot_lose_opaque_rows(): void {
		$base = $this->table();
		$mounts = Wstm108_HostTopology::structural_mounts( $base );
		self::assertSame( $mounts, Wstm108_HostTopology::structural_mounts( $base ) );
		self::assertNotSame( $mounts, Wstm108_HostTopology::structural_mounts( str_replace( 'net:[1]', 'net:[2]', $base ) ) );
		self::assertNotSame( $mounts, Wstm108_HostTopology::structural_mounts( explode( "\n", $base )[0] . "\n" ) );
		$stacked = $base . "3 1 0:4 net:[2] /namespace rw - nsfs none rw\n";
		$rows = Wstm108_HostTopology::structural_mounts( $stacked );
		self::assertCount( 3, $rows );
		self::assertSame( array( '/', 'net:[1]', 'net:[2]' ), array_column( $rows, 'root' ) );
		self::assertTrue( Wstm108_HostTopology::stacked( $rows ) );
		$this->refuses( static fn() => Wstm108_HostTopology::mounts( $stacked ), 'ambiguous-stacked-mount' );
		$this->refuses( static fn() => Wstm108_KernelMounts::visibility( $stacked ), 'native-coordinate-prerequisite' );
		$this->refuses( static fn() => Wstm108_KernelMounts::outside( '/authority', array( '/source' ), $rows ), 'native-coordinate-prerequisite' );
		$collector = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php' );
		self::assertStringContainsString( "if ( isset( \$points[ \$mount['point'] ] ) ) { \$duplicates[ \$mount['point'] ] = true; }", $collector );
		self::assertStringContainsString( 'Wstm108_HostTopology::structural_mounts( $bytes ) === $mounts', $collector );
	}

	public function test_canonical_roots_still_use_the_original_path_contract(): void {
		foreach ( array( 'ext4', 'tmpfs', 'nsfs' ) as $type ) {
			foreach ( array( '/' => '/', '/ordinary' => '/ordinary', '/ordinary/' => '/ordinary' ) as $root => $expected ) {
				foreach ( array( 'mounts', 'structural_mounts' ) as $parser ) {
					self::assertSame( $expected, Wstm108_HostTopology::$parser( $this->table( $root, $type ) )[1]['root'] );
				}
			}
			foreach ( array( '/.', '/..', '/a//b', "/a\0b", '/a\\011b' ) as $root ) {
				$this->refuses( fn() => Wstm108_HostTopology::structural_mounts( $this->table( $root, $type ) ), 'noncanonical-path' );
			}
		}
	}
}
