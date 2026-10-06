<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/current-main-transition.php';

final class ScopedHostObservationTransitionTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_reviewed_outer_generation_restores_exact_current_main_without_resealing_history(): void {
		$map = Wstm167ScopedHostObservationTransition::load();
		self::assertSame( '32b67155ad90cd4bbc7f0dc832e8b86b69b98c50', $map['base_commit'] );
		self::assertSame( Wstm167CurrentMainTransition::SEAL, $map['predecessor_seal'] );
		self::assertSame( Wstm167CurrentMainTransition::SEAL, hash( 'sha256', $this->read( 'tests/unit/fixtures/current-main-transition.json' ) ) );
		Wstm167CurrentMainTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['files'] as $path => $binding ) {
			self::assertSame( $binding['current_raw_sha256'], hash( 'sha256', Wstm167KernelMountTransition::restore( $path, $this->read( $path ) ) ), $path );
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', Wstm167ScopedHostObservationTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['dependencies'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167KernelMountTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
	}

	public function test_missing_foreign_truncated_and_old_raw_bytes_cannot_enter_the_historical_bridge(): void {
		$map = Wstm167ScopedHostObservationTransition::load();
		foreach ( array_merge( array_keys( $map['files'] ), array_keys( $map['dependencies'] ) ) as $path ) {
			foreach ( array( false, '', substr( $this->read( $path ), 0, -1 ), $this->read( $path ) . "\nforeign" ) as $foreign ) {
				try {
					Wstm167CurrentMainTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Unreviewed scoped observation generation accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() );
				}
			}
		}
		foreach ( $map['files'] as $path => $binding ) {
			$current = $this->read( $path );
			foreach ( array( Wstm167ScopedHostObservationTransition::restore( $path, $current ), str_replace( "\n", "\r\n", $current ) ) as $foreign ) {
				try {
					Wstm167CurrentMainTransition::restore( $path, $foreign );
					self::fail( 'Historical raw fallback accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
	}

	public function test_forged_or_omitted_projection_cannot_reseal_the_reviewed_generation(): void {
		$json = $this->read( 'tests/unit/fixtures/scoped-host-observation-transition.json' );
		$map = Wstm167ScopedHostObservationTransition::load();
		$forgeries = array( '', $json . "\n" );
		foreach ( array_keys( $map ) as $key ) {
			$foreign = $map;
			unset( $foreign[ $key ] );
			$forgeries[] = json_encode( $foreign, JSON_THROW_ON_ERROR );
		}
		foreach ( array( 'hunks', 'start', 'before', 'after', 'current_raw_sha256', 'baseline_raw_sha256' ) as $key ) {
			$forgeries[] = str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json );
		}
		foreach ( $forgeries as $foreign ) {
			try {
				Wstm167ScopedHostObservationTransition::load( $foreign );
				self::fail( 'Forged scoped observation bridge accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Scoped host observation transition seal mismatch.', $error->getMessage() );
			}
		}

	}

	public function test_even_a_resealed_wrong_outer_baseline_cannot_change_the_frozen_older_witness(): void {
		$path = 'scripts/untrusted-host-topology.php';
		$map = Wstm167ScopedHostObservationTransition::load();
		$raw_current = $this->read( $path );
		$current = Wstm167KernelMountTransition::restore( $path, $raw_current );
		$wrong = Wstm167ScopedHostObservationTransition::restore( $path, $raw_current ) . "\nforeign_guard";
		$map['files'][ $path ]['hunks'][] = array(
			'start' => count( explode( "\n", $current ) ),
			'before' => array( 'foreign_guard' ),
			'after' => array(),
		);
		$map['files'][ $path ]['baseline_raw_sha256'] = hash( 'sha256', $wrong );
		$outer_json = json_encode( $map, JSON_THROW_ON_ERROR );
		$main_json = $this->read( 'tests/unit/fixtures/current-main-transition.json' );
		$altered_main = json_decode( $main_json, true, 512, JSON_THROW_ON_ERROR );
		$altered_main['dependencies'][ $path ] = hash( 'sha256', $wrong );
		foreach ( array( $main_json, json_encode( $altered_main, JSON_THROW_ON_ERROR ) ) as $index => $older_json ) {
			$namespace = 'WstmScopedFrozenWitness' . $index;
			$GLOBALS[ $namespace ] = array(
				'scoped-host-observation-transition.json' => $outer_json,
				'current-main-transition.json' => $older_json,
			);
			$outer_source = $this->read( 'tests/unit/fixtures/scoped-host-observation-transition.php' );
			$outer_source = Wstm167KernelMountTransition::restore( 'tests/unit/fixtures/scoped-host-observation-transition.php', $outer_source );
			$outer_source = str_replace(
				"public const SEAL = '" . Wstm167ScopedHostObservationTransition::SEAL . "';",
				"public const SEAL = '" . hash( 'sha256', $outer_json ) . "';",
				$outer_source,
				$replaced
			);
			self::assertSame( 1, $replaced );
			$main_source = $this->read( 'tests/unit/fixtures/current-main-transition.php' );
			$import = "require_once __DIR__ . '/scoped-host-observation-transition.php';";
			self::assertSame( 1, substr_count( $main_source, $import ) );
			$main_source = str_replace( $import, '', $main_source );
			foreach ( array( $outer_source, $main_source ) as $source_index => $source ) {
				$body = preg_replace( '/^<\?php\s+declare\(strict_types=1\);\s*/', '', $source, 1, $removed );
				self::assertSame( 1, $removed );
				$reader = 0 === $source_index
					? 'function file_get_contents( $path ) { $fixtures = $GLOBALS[' . var_export( $namespace, true ) . ']; $name = basename( $path ); if ( ! array_key_exists( $name, $fixtures ) ) { throw new \RuntimeException( "Unexpected test-only fixture read." ); } return $fixtures[ $name ]; }'
					: '';
				eval( 'namespace ' . $namespace . '; use \RuntimeException; ' . $reader . $body );
			}
			$outer_class = $namespace . '\\Wstm167ScopedHostObservationTransition';
			$main_class = $namespace . '\\Wstm167CurrentMainTransition';
			try {
				self::assertSame( $wrong, $outer_class::restore( $path, $current ) );
				try {
					$main_class::verify_dependencies( fn( $entry ) => Wstm167KernelMountTransition::restore( $entry, $this->read( $entry ) ) );
					self::fail( 'Resealed foreign baseline replaced the accepted older witness.' );
				} catch ( RuntimeException $error ) {
					self::assertSame(
						0 === $index ? 'CI diagnostic dependency drift: ' . $path : 'Current main transition seal mismatch.',
						$error->getMessage()
					);
				}
			} finally {
				unset( $GLOBALS[ $namespace ] );
			}
		}
	}
}
