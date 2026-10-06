<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/scoped-host-observation-transition.php';

final class KernelMountTransitionTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_exact_outer_generation_restores_the_unchanged_observation_generation(): void {
		$map = Wstm167KernelMountTransition::load();
		self::assertSame( '9d1eb1447c982866ab77b2dc0c1fd5d24926f931', $map['base_commit'] );
		self::assertSame( Wstm167ScopedHostObservationTransition::SEAL, $map['predecessor_seal'] );
		self::assertSame( $map['predecessor_seal'], hash( 'sha256', $this->read( 'tests/unit/fixtures/scoped-host-observation-transition.json' ) ) );
		Wstm167ScopedHostObservationTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['files'] as $path => $binding ) {
			self::assertSame( $binding['current_raw_sha256'], hash( 'sha256', Wstm167LatestMainTransition::restore( $path, $this->read( $path ) ) ), $path );
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', Wstm167KernelMountTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['dependencies'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167LatestMainTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
	}

	public function test_missing_truncated_foreign_and_old_raw_bytes_cannot_bypass_the_outer_generation(): void {
		$map = Wstm167KernelMountTransition::load();
		foreach ( array_merge( array_keys( $map['files'] ), array_keys( $map['dependencies'] ) ) as $path ) {
			foreach ( array( false, '', substr( $this->read( $path ), 0, -1 ), $this->read( $path ) . "\nforeign" ) as $foreign ) {
				try {
					Wstm167ScopedHostObservationTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Unreviewed kernel generation accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() );
				}
			}
		}
		foreach ( $map['files'] as $path => $binding ) {
			$current = $this->read( $path );
			foreach ( array( Wstm167KernelMountTransition::restore( $path, $current ), str_replace( "\n", "\r\n", $current ) ) as $foreign ) {
				try {
					Wstm167KernelMountTransition::restore( $path, $foreign );
					self::fail( 'Historical raw bytes accepted as current.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
	}

	public function test_forged_ledger_cannot_change_accepted_dependencies_or_reverse_hunks(): void {
		$json = $this->read( 'tests/unit/fixtures/kernel-mount-transition.json' );
		$map = Wstm167KernelMountTransition::load();
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
				Wstm167KernelMountTransition::load( $foreign );
				self::fail( 'Forged kernel transition accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Kernel mount transition seal mismatch.', $error->getMessage() );
			}
		}
	}

		public function test_resealing_a_wrong_kernel_baseline_cannot_replace_the_frozen_observation_witness(): void {
			$path = 'scripts/untrusted-host-topology.php';
			$map = Wstm167KernelMountTransition::load();
			$raw_current = $this->read( $path );
			$current = Wstm167LatestMainTransition::restore( $path, $raw_current );
			$wrong = Wstm167KernelMountTransition::restore( $path, $raw_current ) . "\nforeign_guard";
			$map['files'][ $path ]['hunks'][] = array(
				'start' => count( explode( "\n", $current ) ),
				'before' => array( 'foreign_guard' ),
				'after' => array(),
			);
			$map['files'][ $path ]['baseline_raw_sha256'] = hash( 'sha256', $wrong );
			$json = json_encode( $map, JSON_THROW_ON_ERROR );
			$older_json = $this->read( 'tests/unit/fixtures/scoped-host-observation-transition.json' );
			$altered = json_decode( $older_json, true, 512, JSON_THROW_ON_ERROR );
			$altered['files'][ $path ]['current_raw_sha256'] = hash( 'sha256', $wrong );
			foreach ( array( $older_json, json_encode( $altered, JSON_THROW_ON_ERROR ) ) as $index => $historical_json ) {
				$namespace = 'WstmKernelFrozenWitness' . $index;
				$GLOBALS[ $namespace ] = array( 'kernel-mount-transition.json' => $json, 'scoped-host-observation-transition.json' => $historical_json );
				$outer = str_replace(
					"public const SEAL = '" . Wstm167KernelMountTransition::SEAL . "';",
					"public const SEAL = '" . hash( 'sha256', $json ) . "';",
					Wstm167LatestMainTransition::restore( 'tests/unit/fixtures/kernel-mount-transition.php', $this->read( 'tests/unit/fixtures/kernel-mount-transition.php' ) ),
					$replaced
				);
				self::assertSame( 1, $replaced );
				$older = Wstm167LatestMainTransition::restore( 'tests/unit/fixtures/scoped-host-observation-transition.php', $this->read( 'tests/unit/fixtures/scoped-host-observation-transition.php' ) );
				$import = "require_once __DIR__ . '/kernel-mount-transition.php';";
				self::assertSame( 1, substr_count( $older, $import ) );
				$older = str_replace( $import, '', $older );
				foreach ( array( $outer, $older ) as $source_index => $source ) {
					$body = preg_replace( '/^<\?php\s+declare\(strict_types=1\);\s*/', '', $source, 1, $removed );
					self::assertSame( 1, $removed );
					$reader = 0 === $source_index
						? 'function file_get_contents( $path ) { $fixtures = $GLOBALS[' . var_export( $namespace, true ) . ']; $name = basename( $path ); if ( ! array_key_exists( $name, $fixtures ) ) { throw new \RuntimeException( "Unexpected test-only fixture read." ); } return $fixtures[ $name ]; }'
						: '';
					eval( 'namespace ' . $namespace . '; use \RuntimeException; ' . $reader . $body );
				}
				$outer_class = $namespace . '\\Wstm167KernelMountTransition';
				$older_class = $namespace . '\\Wstm167ScopedHostObservationTransition';
				try {
					self::assertSame( $wrong, $outer_class::restore( $path, $current ) );
					try {
						$older_class::verify_dependencies( fn( $entry ) => Wstm167LatestMainTransition::restore( $entry, $this->read( $entry ) ) );
						self::fail( 'Forged kernel baseline replaced the frozen observation witness.' );
					} catch ( RuntimeException $error ) {
						self::assertSame(
							0 === $index ? 'CI diagnostic current source drift: ' . $path : 'Scoped host observation transition seal mismatch.',
							$error->getMessage()
						);
					}
				} finally {
					unset( $GLOBALS[ $namespace ] );
				}
			}
	}
}
