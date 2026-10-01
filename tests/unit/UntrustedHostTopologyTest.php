<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-observation.php';
require_once __DIR__ . '/fixtures/selected-listener-transition.php';

final class UntrustedHostTopologyTest extends TestCase {
	private static function observer_literal_reasons( string $source ): array {
		$tokens = array_values( array_filter( token_get_all( $source ),
			static fn( $token ) => ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) );
		$reasons = array();
		$pairs = array( ')' => '(', ']' => '[', '}' => '{' );
		for ( $i = 0; $i + 3 < count( $tokens ); ++$i ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || 'self' !== strtolower( $tokens[ $i ][1] )
				|| ! is_array( $tokens[ $i + 1 ] ) || T_DOUBLE_COLON !== $tokens[ $i + 1 ][0]
				|| ! is_array( $tokens[ $i + 2 ] ) || ! in_array( $tokens[ $i + 2 ][0], array( T_STRING, T_REQUIRE ), true )
				|| 'require' !== strtolower( $tokens[ $i + 2 ][1] ) || '(' !== $tokens[ $i + 3 ] ) { continue; }
			$stack = array( '(' ); $commas = 0; $argument = array();
			for ( $j = $i + 4; $j < count( $tokens ); ++$j ) {
				$token = $tokens[ $j ];
				if ( is_string( $token ) && in_array( $token, array( '(', '[', '{' ), true ) ) {
					$stack[] = $token;
				} elseif ( is_string( $token ) && isset( $pairs[ $token ] ) ) {
					self::assertSame( $pairs[ $token ], array_pop( $stack ), 'Observer guard delimiters must balance.' );
					if ( array() === $stack ) { break; }
				}
				if ( 1 === count( $stack ) && ',' === $token ) { ++$commas; $argument = array(); continue; }
				if ( $commas > 0 ) { $argument[] = $token; }
			}
			self::assertSame( array(), $stack, 'Observer guard call must be complete.' );
			self::assertLessThanOrEqual( 1, $commas, 'Observer guard has a condition and optional literal reason only.' );
			if ( 0 === $commas ) { continue; }
			self::assertCount( 1, $argument );
			self::assertIsArray( $argument[0] );
			self::assertSame( T_CONSTANT_ENCAPSED_STRING, $argument[0][0] );
			self::assertSame( 1, preg_match( "/^'([a-z][a-z-]+)'$/D", $argument[0][1], $match ) );
			$reasons[] = $match[1];
		}
		return $reasons;
	}

	public function test_observer_reason_extraction_ignores_nested_arguments_and_noncode(): void {
		$source = <<<'PHP'
<?php
self::require( is_resource( fopen( '/synthetic/not-opened', 'rb' ) ) );
self::require( array( 'first', 'second' ) === array( 'first', 'second' ), 'actual-literal-reason' );
self::require( [ 'first', 'second' ] === [ 'first', 'second' ], 'another-literal-reason' );
// self::require( false, 'comment-only-reason' );
$not_code = "self::require( false, 'quoted-only-reason' );";
PHP;
		self::assertSame( array( 'actual-literal-reason', 'another-literal-reason' ), self::observer_literal_reasons( $source ) );
	}

	public function test_refusal_allowlist_matches_every_existing_topology_guard(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php' );
		$source = Wstm167SelectedListenerTransition::restore( 'scripts/untrusted-host-topology.php', $source );
		$source = substr( $source, strpos( $source, 'private static function path(' ) );
		preg_match_all( "/'([a-z][a-z-]+)' \\);/", $source, $matches );
		self::assertSame( array_slice( Wstm108_HostTopology::REFUSAL_REASONS, 0, 36 ), $matches[1] );
		self::assertCount( 36, array_unique( $matches[1] ) );
		$observer = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-observation.php' );
		$observer_reasons = array_values( array_unique( self::observer_literal_reasons( $observer ) ) );
		self::assertNotEmpty( $observer_reasons );
		self::assertContains( 'invalid-host-inspection-mode', $observer_reasons );
		self::assertCount( 37, Wstm108_HostTopology::REFUSAL_REASONS );
		self::assertSame( Wstm108_HostTopology::REFUSAL_REASONS, array_values( array_unique( Wstm108_HostTopology::REFUSAL_REASONS ) ) );
		self::assertSame( Wstm108_HostTopology::REFUSAL_REASONS, array_values( array_unique( array_merge( $matches[1], $observer_reasons ) ) ) );
		$require = new ReflectionMethod( Wstm108_HostTopology::class, 'require' );
		$require->setAccessible( true );
		foreach ( $matches[1] as $reason ) {
			try {
				$require->invoke( null, false, $reason );
				self::fail( 'Existing refusal must still throw.' );
			} catch ( Wstm108_TopologyRefusal $error ) {
				self::assertSame( 78, $error->getCode() );
				self::assertSame( 'WSTM108 BLOCKED topology: ' . $reason, $error->getMessage() );
				self::assertSame( array( 'phase' => 'topology', 'reason' => $reason ), Wstm108_HostTopology::failure_witness( $error ) );
			}
		}
		$observer_require = new ReflectionMethod( Wstm108_HostObservation::class, 'require' );
		$observer_require->setAccessible( true );
		$default_reason = $observer_require->getParameters()[1]->getDefaultValue();
		self::assertSame( 'unreadable-kernel-evidence', $default_reason );
		foreach ( array_merge( $observer_reasons, array( $default_reason ) ) as $reason ) {
			try {
				$arguments = $reason === $default_reason ? array( false ) : array( false, $reason );
				$observer_require->invokeArgs( null, $arguments );
				self::fail( 'Observer-owned refusal must still throw.' );
			} catch ( Wstm108_TopologyRefusal $error ) {
				self::assertSame( 78, $error->getCode() );
				self::assertSame( 'WSTM108 BLOCKED topology: ' . $reason, $error->getMessage() );
				self::assertSame( array( 'phase' => 'topology', 'reason' => $reason ), Wstm108_HostTopology::failure_witness( $error ) );
			}
		}
	}

	public function test_observer_invalid_mode_keeps_exact_refusal_and_closed_witness(): void {
		self::assertFalse( Wstm108_HostObservation::enabled( array() ) );
		try {
			Wstm108_HostObservation::enabled( array( 'WSTM108_HOST_INSPECTION' => 'invalid-observer-test-mode' ) );
			self::fail( 'An invalid inspection mode must refuse at its actual observer guard.' );
		} catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			self::assertSame( 'WSTM108 BLOCKED topology: invalid-host-inspection-mode', $error->getMessage() );
			self::assertSame( array( 'phase' => 'topology', 'reason' => 'invalid-host-inspection-mode' ), Wstm108_HostTopology::failure_witness( $error ) );
		}
	}

	public function test_unknown_or_message_forged_refusals_have_no_witness(): void {
		foreach ( array(
			new RuntimeException( 'WSTM108 BLOCKED topology: nonlocal-endpoint', 78 ),
			new RuntimeException( "/PRIVATE_PATH_SENTINEL\nsecret=PRIVATE_SECRET_SENTINEL", 78 ),
			new Wstm108_TopologyRefusal( "nonlocal-endpoint\nPRIVATE_SECRET_SENTINEL" ),
		) as $error ) {
			self::assertNull( Wstm108_HostTopology::failure_witness( $error ) );
		}
	}

	public function test_actual_malformed_table_keeps_exact_refusal_and_closed_witness(): void {
		try {
			Wstm108_HostTopology::mounts( "PRIVATE_SECRET_SENTINEL\n" );
			self::fail( 'Malformed table must refuse without native admission.' );
		} catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 78, $error->getCode() );
			self::assertSame( array( 'phase' => 'topology', 'reason' => 'malformed-mount-record' ),
				Wstm108_HostTopology::failure_witness( $error ) );
		}
	}

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
