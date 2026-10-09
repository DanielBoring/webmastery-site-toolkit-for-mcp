<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/shared-helper-transition.php';

final class FloorSelectorTransitionTest extends TestCase {
	public function testExactNewLayerReversesBeforeUnchangedHistoricalLayers(): void {
		$map = Wstm167FloorSelectorTransition::load();
		$read = Wstm166BoundedAdmissionTransition::reader( static fn( $path ) => file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
		Wstm166BoundedAdmissionTransition::verify_dependencies( static fn( $path ) => file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
		Wstm167FloorSelectorTransition::verify_dependencies( $read );
		foreach ( $map['files'] as $path => $binding ) {
			$current = $read( $path );
			$before = Wstm167FloorSelectorTransition::restore( $path, $current );
			self::assertSame( $binding['baseline_sha256'], hash( 'sha256', $before ) );
			self::assertSame( $binding['current_raw_sha256'], hash( 'sha256', $current ) );
			foreach ( array( $current . "\n// foreign\n", str_replace( "\n", "\n ", $current ) ) as $foreign ) {
				try { Wstm167FloorSelectorTransition::restore( $path, $foreign ); self::fail( 'Foreign consumer accepted.' ); }
				catch ( RuntimeException $error ) { self::assertStringContainsString( 'current source drift', $error->getMessage() ); }
			}
		}
		foreach ( array(
			'array-syntax-transition.json' => Wstm119ArraySyntaxTransition::SEAL,
			'bounded-integration-transition.json' => Wstm167SourceTransition::SEAL,
			'posts-extraction-transition.json' => Wstm127SourceTransition::SEAL,
			'shared-helper-transition.json' => Wstm119SourceTransition::SEAL,
		) as $name => $seal ) {
			self::assertSame( $seal, hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( __DIR__ . '/fixtures/' . $name ) ) ) );
		}
		Wstm127SourceTransition::verify_dependencies();
	}

	public function testNewDependenciesAndForgedProofFailClosed(): void {
		$root = dirname( __DIR__, 2 );
		$map = Wstm167FloorSelectorTransition::load();
		foreach ( array_keys( $map['dependencies'] ) as $foreign ) {
			try {
				Wstm167FloorSelectorTransition::verify_dependencies( static fn( $path ) => Wstm166BoundedAdmissionTransition::restore( $path, file_get_contents( $root . '/' . $path ) ) . ( $path === $foreign ? "\nforeign" : '' ) );
				self::fail( 'Foreign dependency accepted.' );
			} catch ( RuntimeException $error ) { self::assertStringContainsString( 'dependency drift', $error->getMessage() ); }
		}
		$json = file_get_contents( __DIR__ . '/fixtures/floor-selector-transition.json' );
		foreach ( array( 'files', 'dependencies', 'predecessor_seals', 'current_blob', 'baseline_sha256', 'hunks', 'start' ) as $key ) {
			try { Wstm167FloorSelectorTransition::load( str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json ) ); self::fail( 'Forged proof accepted.' ); }
			catch ( RuntimeException $error ) { self::assertSame( 'Floor selector transition seal mismatch.', $error->getMessage() ); }
		}
	}
}
