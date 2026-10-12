<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/shared-helper-transition.php';

final class BoundedAdmissionTransitionTest extends TestCase {
	public function test_exact_reversal_precedes_unchanged_floor_and_historical_layers(): void {
		$root = dirname( __DIR__, 2 );
		$read = static fn( $path ) => file_get_contents( $root . '/' . $path );
		$map = Wstm166BoundedAdmissionTransition::load();
		Wstm166BoundedAdmissionTransition::verify_dependencies( $read );
		Wstm167FloorSelectorTransition::verify_dependencies( Wstm166BoundedAdmissionTransition::reader( $read ) );
		foreach ( $map['files'] as $path => $binding ) {
			$current = $read( $path );
			self::assertSame( $binding['baseline_sha256'], hash( 'sha256', Wstm166BoundedAdmissionTransition::restore( $path, $current ) ) );
			try {
				Wstm166BoundedAdmissionTransition::restore( $path, $current . "\nforeign" );
				self::fail( 'Foreign composed consumer accepted.' );
			} catch ( RuntimeException $error ) { self::assertStringContainsString( 'current source drift', $error->getMessage() ); }
		}
		self::assertSame( Wstm166BoundedAdmissionTransition::PREDECESSOR, hash( 'sha256', $read( 'tests/unit/fixtures/floor-selector-transition.json' ) ) );
		Wstm127SourceTransition::verify_dependencies();
	}

	public function test_new_capture_dependencies_and_forged_map_refuse(): void {
		$root = dirname( __DIR__, 2 );
		$map = Wstm166BoundedAdmissionTransition::load();
		foreach ( array_keys( $map['dependencies'] ) as $foreign ) {
			try {
				Wstm166BoundedAdmissionTransition::verify_dependencies( static fn( $path ) => file_get_contents( $root . '/' . $path ) . ( $path === $foreign ? "\nforeign" : '' ) );
				self::fail( 'Foreign capture dependency accepted.' );
			} catch ( RuntimeException $error ) { self::assertStringContainsString( 'dependency drift', $error->getMessage() ); }
		}
		$json = file_get_contents( __DIR__ . '/fixtures/bounded-admission-transition.json' );
		foreach ( array( 'files', 'dependencies', 'predecessor_seal', 'hunks', 'start' ) as $key ) {
			try { Wstm166BoundedAdmissionTransition::load( str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json ) ); self::fail( 'Forged map accepted.' ); }
			catch ( RuntimeException $error ) { self::assertSame( 'Bounded admission transition seal mismatch.', $error->getMessage() ); }
		}
	}
}
