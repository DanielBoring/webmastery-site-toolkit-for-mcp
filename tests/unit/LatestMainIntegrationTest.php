<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/kernel-mount-transition.php';

final class LatestMainIntegrationTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_latest_main_metadata_lock_and_unchanged_incoming_harness_are_bound(): void {
		$map = Wstm167LatestMainTransition::load();
		self::assertSame( 'c135420c10615616a49e27eb703f955f1ea1f228', $map['main_commit'] );
		self::assertSame( '545e7599f6f7ad081f08d9abdedd6c8a31f76d6c', $map['base_main_commit'] );
		self::assertSame( Wstm167KernelMountTransition::SEAL, $map['predecessor_seal'] );
		self::assertCount( 7, $map['incoming_main'] );
		foreach ( array( 'composer.lock', 'tests/e2e/database-table-privacy-fixture.php', 'tests/e2e/database-table-privacy-runner.php', 'tests/unit/DatabasePrivacyExpirationTest.php' ) as $path ) {
			self::assertSame( $map['incoming_main'][ $path ]['incoming_sha256'], hash( 'sha256', $this->read( $path ) ), $path );
		}
		$lock = json_decode( $this->read( 'composer.lock' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( '2.2.16', array_column( $lock['packages-dev'], 'version', 'name' )['phpstan/phpstan'] );
		$historical = json_decode( Wstm167LatestMainTransition::restore( 'composer.lock', $this->read( 'composer.lock' ) ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( '2.2.15', array_column( $historical['packages-dev'], 'version', 'name' )['phpstan/phpstan'] );
		foreach ( array( 'tests/unit/DatabasePrivacyExpirationTest.php', 'tests/e2e/database-table-privacy-fixture.php', 'tests/e2e/database-table-privacy-runner.php', 'tests/unit/UntrustedKernelMountTest.php', 'scripts/untrusted-kernel-mounts.php', 'tests/e2e/abilities-manifest.json' ) as $path ) {
			self::assertTrue( isset( $map['dependencies'][ $path ] ) || isset( $map['files'][ $path ] ), $path );
		}
	}

	public function test_exact_outer_reversal_restores_all_495_kernel_paths_and_frozen_seals(): void {
		$map = Wstm167LatestMainTransition::load();
		self::assertCount( 495, $map['kernel_inventory'] );
		Wstm167KernelMountTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['kernel_inventory'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167LatestMainTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['frozen_json'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $this->read( $path ) ), $path );
		}
	}

	public function test_missing_truncated_foreign_old_raw_and_crlf_bytes_fail_closed(): void {
		$map = Wstm167LatestMainTransition::load();
		$paths = array_unique( array_merge( array_keys( $map['files'] ), array_keys( $map['incoming_main'] ), array( 'tests/unit/LatestMainIntegrationTest.php', 'scripts/untrusted-kernel-mounts.php' ) ) );
		foreach ( $paths as $path ) {
			$current = $this->read( $path );
			foreach ( array( false, '', substr( $current, 0, -1 ), $current . "\nforeign", str_replace( "\n", "\r\n", $current ) ) as $foreign ) {
				try {
					Wstm167KernelMountTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Unreviewed latest main bytes accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() );
				}
			}
		}
		foreach ( $map['files'] as $path => $binding ) {
			$old = Wstm167LatestMainTransition::restore( $path, $this->read( $path ) );
			try {
				Wstm167LatestMainTransition::restore( $path, $old );
				self::fail( 'Historical raw bytes accepted as latest main.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
			}
		}
	}

	public function test_tampered_missing_or_foreign_map_is_rejected_by_exact_raw_seal(): void {
		$json = $this->read( 'tests/unit/fixtures/latest-main-transition.json' );
		$map = Wstm167LatestMainTransition::load();
		$forgeries = array( '', $json . "\n", str_replace( "\n", "\r\n", $json ) );
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
				Wstm167LatestMainTransition::load( $foreign );
				self::fail( 'Forged latest main map accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Latest main transition seal mismatch.', $error->getMessage() );
			}
		}
	}

	public function test_resealed_wrong_latest_baseline_cannot_replace_unchanged_kernel_witness(): void {
		$path = 'composer.lock';
		$current = $this->read( $path );
		$map = Wstm167LatestMainTransition::load();
		$wrong = Wstm167LatestMainTransition::restore( $path, $current ) . "\nforeign_guard";
		$map['files'][ $path ]['hunks'][] = array(
			'start' => count( explode( "\n", $current ) ),
			'before' => array( 'foreign_guard' ),
			'after' => array(),
		);
		$map['files'][ $path ]['baseline_raw_sha256'] = hash( 'sha256', $wrong );
		$json = json_encode( $map, JSON_THROW_ON_ERROR );
		$kernel_json = $this->read( 'tests/unit/fixtures/kernel-mount-transition.json' );
		$altered = json_decode( $kernel_json, true, 512, JSON_THROW_ON_ERROR );
		$altered['dependencies'][ $path ] = hash( 'sha256', $wrong );
		foreach ( array( $kernel_json, json_encode( $altered, JSON_THROW_ON_ERROR ) ) as $index => $historical_json ) {
			$namespace = 'WstmLatestMainFrozenWitness' . $index;
			$GLOBALS[ $namespace ] = array( 'latest-main-transition.json' => $json, 'kernel-mount-transition.json' => $historical_json );
			$outer = str_replace(
				"public const SEAL = '" . Wstm167LatestMainTransition::SEAL . "';",
				"public const SEAL = '" . hash( 'sha256', $json ) . "';",
				Wstm167TerminalCallsiteTransition::restore( 'tests/unit/fixtures/latest-main-transition.php', $this->read( 'tests/unit/fixtures/latest-main-transition.php' ) ),
				$replaced
			);
			self::assertSame( 1, $replaced );
			$kernel = Wstm167LatestMainTransition::restore( 'tests/unit/fixtures/kernel-mount-transition.php', $this->read( 'tests/unit/fixtures/kernel-mount-transition.php' ) );
			foreach ( array( $outer, $kernel ) as $source_index => $source ) {
				$body = preg_replace( '/^<\?php\s+declare\(strict_types=1\);\s*/', '', $source, 1, $removed );
				self::assertSame( 1, $removed );
				$reader = 0 === $source_index
					? 'function file_get_contents( $path ) { $fixtures = $GLOBALS[' . var_export( $namespace, true ) . ']; $name = basename( $path ); if ( ! array_key_exists( $name, $fixtures ) ) { throw new \RuntimeException( "Unexpected test-only fixture read." ); } return $fixtures[ $name ]; }'
					: '';
				eval( 'namespace ' . $namespace . '; use \RuntimeException; ' . $reader . $body );
			}
			$outer_class = $namespace . '\\Wstm167LatestMainTransition';
			$kernel_class = $namespace . '\\Wstm167KernelMountTransition';
			try {
				self::assertSame( $wrong, $outer_class::restore( $path, $current ) );
				try {
					$kernel_class::verify_dependencies( fn( $entry ) => $outer_class::restore( $entry, Wstm167TerminalCallsiteTransition::restore( $entry, $this->read( $entry ) ) ) );
					self::fail( 'Resealed latest baseline replaced frozen kernel proof.' );
				} catch ( RuntimeException $error ) {
					self::assertSame(
						0 === $index ? 'CI diagnostic dependency drift: ' . $path : 'Kernel mount transition seal mismatch.',
						$error->getMessage()
					);
				}
			} finally {
				unset( $GLOBALS[ $namespace ] );
			}
		}
	}
}
