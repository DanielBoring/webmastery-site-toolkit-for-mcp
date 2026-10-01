<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php';
require_once __DIR__ . '/fixtures/ci-diagnostic-transition.php';

final class SelectedListenerTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	private static function row( string $inode = '101', bool $connected = false ): array {
		return array( '0000000000000000:', '00000002', '00000000', $connected ? '00000000' : '00010000',
			'0001', $connected ? '03' : '01', $inode, '/run/docker.sock' );
	}

	private static function legacy( array $rows ): string {
		$listeners = array();
		foreach ( $rows as $columns ) {
			if ( '00010000' !== $columns[3] || '0001' !== $columns[4] || '01' !== $columns[5] || ! ctype_digit( $columns[6] ) ) {
				throw new Wstm108_TopologyRefusal( 'ambiguous-selected-listener' );
			}
			$listeners[] = $columns[6];
		}
		if ( 1 !== count( $listeners ) ) { throw new Wstm108_TopologyRefusal( 'selected-listener-not-unique' ); }
		return $listeners[0];
	}

	public function test_owned_probe_shapes_reproduce_old_predicate_but_select_only_the_listener(): void {
		$listener = self::row(); $accepted = self::row( '202', true );
		self::assertSame( self::legacy( array( $listener ) ), Wstm108_HostTopology::selected_listener( array( $listener ) ) );
		foreach ( array( array( $listener, $accepted ), array( $accepted, $listener ), array( $accepted, $listener, self::row( '303', true ) ) ) as $rows ) {
			self::assertSame( '101', Wstm108_HostTopology::selected_listener( $rows ) );
			try {
				self::legacy( $rows ); self::fail( 'Original same-path predicate should refuse normal accepted rows.' );
			} catch ( Wstm108_TopologyRefusal $error ) {
				self::assertSame( 'ambiguous-selected-listener', $error->reason() );
			}
		}
		$baseline = Wstm167SelectedListenerTransition::restore( 'scripts/untrusted-host-topology.php', $this->read( 'scripts/untrusted-host-topology.php' ) );
		self::assertStringContainsString( "self::require( '00010000' === \$columns[3] && '0001' === \$columns[4] && '01' === \$columns[5]\n\t\t\t\t&& ctype_digit( \$columns[6] ), 'ambiguous-selected-listener' );", $baseline );
	}

	public static function nonunique(): array {
		return array( 'empty' => array( array() ), 'connected only' => array( array( self::row( '202', true ) ) ),
			'two listeners' => array( array( self::row(), self::row( '303' ) ) ),
			'two listeners with accepted row' => array( array( self::row(), self::row( '202', true ), self::row( '303' ) ) ) );
	}

	/** @dataProvider nonunique */
	public function test_no_or_multiple_listeners_remain_fail_closed( array $rows ): void {
		$this->expectException( Wstm108_TopologyRefusal::class );
		$this->expectExceptionCode( 78 );
		$this->expectExceptionMessage( 'WSTM108 BLOCKED topology: selected-listener-not-unique' );
		Wstm108_HostTopology::selected_listener( $rows );
	}

	public static function malformed(): array {
		$cases = array();
		foreach ( array( false, true ) as $connected ) {
			foreach ( array( 0 => array( 'unknown:', '0:', '0000000000000000' ), 1 => array( '00000000', 'garbage', '2' ),
				2 => array( '00000001', '0' ), 3 => array( '00020000', '00010001', 'garbage', '0' ),
				4 => array( '0002', '0005', '1' ), 5 => array( '02', '07', '00', '3' ),
				6 => array( '0', '01', '-1', '101x', '18446744073709551616', str_repeat( '9', 80 ) ),
				7 => array( '/unknown.sock', "/run/docker.sock\nPRIVATE_SENTINEL" ) ) as $column => $values ) {
				foreach ( $values as $i => $value ) {
					$row = self::row( '202', $connected ); $row[ $column ] = $value;
					$cases[ ( $connected ? 'connected' : 'listener' ) . '-' . $column . '-' . $i ] = array( array( self::row(), $row ) );
				}
			}
			$row = self::row( '202', $connected ); $row[3] = $connected ? '00010000' : '00000000';
			$cases[ 'flag/state mismatch-' . (int) $connected ] = array( array( self::row(), $row ) );
		}
		$row = self::row( '202', true );
		$cases['missing field'] = array( array( self::row(), array_slice( $row, 1 ) ) );
		$cases['extra field'] = array( array( self::row(), array_merge( $row, array( 'extra' ) ) ) );
		$foreign = $row; $foreign[6] = 202; $cases['nonstring'] = array( array( self::row(), $foreign ) );
		$foreign = $row; unset( $foreign[0] ); $foreign['num'] = $row[0]; $cases['associative'] = array( array( self::row(), $foreign ) );
		$cases['nonarray'] = array( array( self::row(), 'PRIVATE_SENTINEL' ) );
		$cases['repeated connected inode'] = array( array( self::row(), $row, $row ) );
		$cases['listener inode reused by connected row'] = array( array( self::row(), self::row( '101', true ) ) );
		$cases['duplicate listener'] = array( array( self::row(), self::row() ) );
		return $cases;
	}

	/** @dataProvider malformed */
	public function test_every_unknown_or_malformed_selected_record_refuses( array $rows ): void {
		try {
			Wstm108_HostTopology::selected_listener( $rows ); self::fail( 'Malformed selected record accepted.' );
		} catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			self::assertSame( array( 'phase' => 'topology', 'reason' => 'ambiguous-selected-listener' ), Wstm108_HostTopology::failure_witness( $error ) );
			self::assertStringNotContainsString( 'PRIVATE_SENTINEL', $error->getMessage() );
		}
	}

	public function test_aliases_pointer_widths_and_uint64_inode_are_classified_without_runtime_reads(): void {
		$row = self::row( '18446744073709551615' ); $row[0] = '00000000:'; $row[7] = '/var/run/docker.sock';
		self::assertSame( $row[6], Wstm108_HostTopology::selected_listener( array( $row, self::row( '202', true ) ) ) );
		$source = $this->read( 'scripts/untrusted-host-topology.php' );
		$start = strpos( $source, 'public static function selected_listener(' );
		$parser = substr( $source, $start, strpos( $source, 'private static function peer(', $start ) - $start );
		foreach ( array( '/proc/', 'realpath(', 'readlink(', 'scandir(', 'file_get_contents(', 'getenv(', 'shell_exec(', 'exec(', 'self::read(', 'self::stat(' ) as $operation ) {
			self::assertStringNotContainsString( $operation, $parser );
		}
	}

	public function test_peer_preserves_all_identity_descriptor_namespace_and_alias_guards(): void {
		$source = $this->read( 'scripts/untrusted-host-topology.php' );
		$before = Wstm167SelectedListenerTransition::restore( 'scripts/untrusted-host-topology.php', $source );
		// Isolate the selected-listener change from the separately sealed observation transport.
		$selected = Wstm167CurrentMainTransition::restore( 'scripts/untrusted-host-topology.php', $source );
		self::assertSame( substr( $before, strpos( $before, '$descriptors = ' ) ),
			substr( $selected, strpos( $selected, '$descriptors = ' ) ) );
		$prefix = 'private static function peer('; $end = '$rows = array();';
		self::assertSame( substr( $before, strpos( $before, $prefix ), strpos( $before, '$listener = array();' ) - strpos( $before, $prefix ) ),
			substr( $selected, strpos( $selected, $prefix ), strpos( $selected, $end ) - strpos( $selected, $prefix ) ) );
		self::assertStringContainsString( "self::read( '/proc/net/unix', 4194304 )", $source );
		self::assertStringContainsString( "array_intersect( array( '/run/docker.sock', '/var/run/docker.sock' ), \$columns )", $source );
		self::assertStringContainsString( 'realpath( reset( $aliases ) ) !== $socket', $source );
		self::assertStringContainsString( '$listener = array( self::selected_listener( $rows ) );', $source );
		preg_match_all( "/'([a-z][a-z-]+)' \\);/", substr( $source, strpos( $source, 'private static function path(' ) ), $matches );
		preg_match_all( "/self::require\\([^;]*'([a-z][a-z-]+)'\\s*\\);/",
			$this->read( 'scripts/untrusted-host-observation.php' ), $observation_matches );
		$used_reasons = array_values( array_unique( array_merge( $matches[1], $observation_matches[1] ) ) );
		$reasons = Wstm108_HostTopology::REFUSAL_REASONS;
		self::assertCount( count( $reasons ), array_unique( $reasons ) );
		sort( $used_reasons ); sort( $reasons );
		self::assertSame( $reasons, $used_reasons );
	}

	public function test_additive_outer_chain_keeps_all_accepted_seals_and_workflows_exact(): void {
		$map = Wstm167SelectedListenerTransition::load();
		self::assertSame( 'b5c087e4d4213264aba693c4eef9dee22c11ceac', $map['base_commit'] );
		self::assertSame( Wstm167CiBudgetControlTransition::SEAL, $map['predecessor_seal'] );
		self::assertSame( '6126a718e4a19904fa4ee64c2b6cc159ee0cb82239a391dbb0ac73c1a10e0591', hash( 'sha256', $this->read( 'tests/unit/fixtures/ci-budget-control-transition.json' ) ) );
		self::assertSame( Wstm167CiDiagnosticTransition::SEAL, hash( 'sha256', $this->read( 'tests/unit/fixtures/ci-diagnostic-transition.json' ) ) );
		Wstm167CiDiagnosticTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['files'] as $path => $binding ) {
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', Wstm167SelectedListenerTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['dependencies'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167CurrentMainTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
	}

	public function test_forged_omitted_reverted_and_foreign_evidence_cannot_bypass_new_outer_layer(): void {
		$map = Wstm167SelectedListenerTransition::load(); $json = $this->read( 'tests/unit/fixtures/selected-listener-transition.json' );
		$forgeries = array( '', $json . "\n" );
		foreach ( array_keys( $map ) as $key ) { $foreign = $map; unset( $foreign[ $key ] ); $forgeries[] = json_encode( $foreign, JSON_THROW_ON_ERROR ); }
		foreach ( array( 'hunks', 'start', 'before', 'after', 'baseline_raw_sha256', 'current_raw_sha256' ) as $key ) {
			$forgeries[] = str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json );
		}
		foreach ( $forgeries as $foreign ) {
			try { Wstm167SelectedListenerTransition::load( $foreign ); self::fail( 'Forged ledger accepted.' ); }
			catch ( RuntimeException $error ) { self::assertSame( 'Selected listener transition seal mismatch.', $error->getMessage() ); }
		}
		foreach ( array_merge( array_keys( $map['files'] ), array_keys( $map['dependencies'] ) ) as $path ) {
			foreach ( array( false, '', $this->read( $path ) . "\nforeign" ) as $foreign ) {
				try { Wstm167CiDiagnosticTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) ); self::fail( 'Missing or foreign evidence accepted.' ); }
				catch ( RuntimeException $error ) { self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() ); }
			}
		}
		foreach ( $map['files'] as $path => $binding ) {
			$current = $this->read( $path );
			foreach ( array( Wstm167SelectedListenerTransition::restore( $path, $current ), str_replace( "\n", "\r\n", $current ) ) as $foreign ) {
				try { Wstm167CiDiagnosticTransition::restore( $path, $foreign ); self::fail( 'Old raw fallback accepted.' ); }
				catch ( RuntimeException $error ) {
					$context = 'tests/unit/fixtures/bounded-admission-transition.php' === $path ? 'Score marker current source drift' : 'CI diagnostic current source drift';
					self::assertSame( $context . ': ' . $path, $error->getMessage() );
				}
			}
		}
	}
}
