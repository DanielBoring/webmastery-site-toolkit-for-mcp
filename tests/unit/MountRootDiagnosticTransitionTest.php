<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/latest-main-transition.php';

final class MountRootDiagnosticTransitionTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_exact_reversal_restores_all_508_raw_paths_before_unchanged_output_chain(): void {
		$map = Wstm167MountRootTransition::load();
		self::assertSame( Wstm167TerminalOutputTransition::SEAL, $map['predecessor_seal'] );
		self::assertSame( '29f47d7272bb1a71b10cdff11be02aa9cc21a7f0', $map['base_commit'] );
		self::assertCount( 508, $map['baseline_inventory'] );
		Wstm167LatestMainTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['baseline_inventory'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167MountRootTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['frozen_json'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $this->read( $path ) ), $path );
		}
	}

	public function test_current_execution_and_c5_controls_remain_exact_and_every_path_is_bound(): void {
		$map = Wstm167MountRootTransition::load();
		foreach ( $map['current_execution'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $this->read( $path ) ), $path );
			self::assertTrue( isset( $map['dependencies'][ $path ] ) || isset( $map['files'][ $path ] ), $path );
		}
		self::assertArrayHasKey( 'tests/unit/AdmissionCallsiteTest.php', $map['files'] );
		self::assertArrayHasKey( 'scripts/untrusted-host-topology.php', $map['files'] );
		self::assertArrayHasKey( 'tests/unit/MountRootDiagnosticTransitionTest.php', $map['dependencies'] );
		self::assertArrayNotHasKey( 'tests/unit/MountRootDiagnosticTransitionTest.php', $map['files'] );
		self::assertCount( 511, array_merge( $map['files'], $map['dependencies'], array( $map['helper_path'] => true, 'tests/unit/fixtures/mount-root-transition.json' => true ) ) );
		$helper = preg_replace( "/^\tpublic const SEAL = 'MOUNT_ROOT_SEAL_PENDING';(?=\\r?$)/m", "\tpublic const SEAL = '" . Wstm167MountRootTransition::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
		self::assertSame( 1, $replaced );
		self::assertSame( $helper, $this->read( $map['helper_path'] ) );
	}

	public function test_missing_truncated_foreign_crlf_old_raw_and_helper_body_drift_refuse(): void {
		$map = Wstm167MountRootTransition::load();
		$paths = array_merge( array_keys( $map['files'] ), array(
			'tests/unit/MountRootDiagnosticTransitionTest.php', $map['helper_path'],
			'tests/unit/fixtures/terminal-output-transition.json', 'scripts/untrusted-host-controller.php',
		) );
		foreach ( $paths as $path ) {
			$current = $this->read( $path );
			foreach ( array( false, '', substr( $current, 0, -1 ), $current . "\nforeign", str_replace( "\n", "\r\n", $current ) ) as $foreign ) {
				try {
					Wstm167LatestMainTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Foreign or missing c5 source accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() );
				}
			}
			if ( isset( $map['files'][ $path ] ) ) {
				$old = Wstm167MountRootTransition::restore( $path, $current );
				try {
					Wstm167MountRootTransition::restore( $path, $old );
					self::fail( 'Historical raw source accepted as current c5 source.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
	}

	public function test_tampered_maps_and_old_seals_refuse_after_cache_population(): void {
		$json = $this->read( 'tests/unit/fixtures/mount-root-transition.json' );
		$map = Wstm167MountRootTransition::load();
		$forgeries = array( '', substr( $json, 0, -1 ), $json . "\n", str_replace( "\n", "\r\n", $json ),
			$this->read( 'tests/unit/fixtures/terminal-output-transition.json' ),
			$this->read( 'tests/unit/fixtures/latest-main-transition.json' ),
			$this->read( 'tests/unit/fixtures/kernel-mount-transition.json' ) );
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
				Wstm167MountRootTransition::load( $foreign );
				self::fail( 'Unsealed c5 map accepted after decoded cache population.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Mount root transition seal mismatch.', $error->getMessage() );
			}
		}
	}

	public function test_resealed_wrong_baseline_is_rejected_by_unchanged_10b6_witness(): void {
		$path = 'scripts/untrusted-host-topology.php';
		$current = $this->read( $path );
		$map = Wstm167MountRootTransition::load();
		$wrong = Wstm167MountRootTransition::restore( $path, $current ) . "\nforeign_guard";
		$map['files'][ $path ]['hunks'][] = array(
			'start' => count( explode( "\n", $current ) ), 'before' => array( 'foreign_guard' ), 'after' => array(),
		);
		$map['files'][ $path ]['baseline_raw_sha256'] = hash( 'sha256', $wrong );
		$json = json_encode( $map, JSON_THROW_ON_ERROR );
		$older_json = $this->read( 'tests/unit/fixtures/terminal-output-transition.json' );
		$altered = json_decode( $older_json, true, 512, JSON_THROW_ON_ERROR );
		$altered['dependencies'][ $path ] = hash( 'sha256', $wrong );
		foreach ( array( $older_json, json_encode( $altered, JSON_THROW_ON_ERROR ) ) as $index => $historical_json ) {
			$namespace = 'WstmMountRootFrozenWitness' . $index;
			$GLOBALS[ $namespace ] = array( 'mount-root-transition.json' => $json, 'terminal-output-transition.json' => $historical_json );
			$outer = str_replace(
				"public const SEAL = '" . Wstm167MountRootTransition::SEAL . "';",
				"public const SEAL = '" . hash( 'sha256', $json ) . "';",
				$this->read( 'tests/unit/fixtures/mount-root-transition.php' ), $replaced
			);
			self::assertSame( 1, $replaced );
			$older = Wstm167MountRootTransition::restore( 'tests/unit/fixtures/terminal-output-transition.php', $this->read( 'tests/unit/fixtures/terminal-output-transition.php' ) );
			foreach ( array( $outer, $older ) as $source_index => $source ) {
				$body = preg_replace( '/^<\?php\s+declare\(strict_types=1\);\s*/', '', $source, 1, $removed );
				self::assertSame( 1, $removed );
				$reader = 0 === $source_index
					? 'function file_get_contents( $path ) { $fixtures = $GLOBALS[' . var_export( $namespace, true ) . ']; $name = basename( $path ); if ( ! array_key_exists( $name, $fixtures ) ) { throw new \RuntimeException( "Unexpected test-only fixture read." ); } return $fixtures[ $name ]; }'
					: '';
				eval( 'namespace ' . $namespace . '; use \RuntimeException; ' . $reader . $body );
			}
			$outer_class = $namespace . '\\Wstm167MountRootTransition';
			$older_class = $namespace . '\\Wstm167TerminalOutputTransition';
			try {
				self::assertSame( $wrong, $outer_class::restore( $path, $current ) );
				try {
					$older_class::verify_dependencies( fn( $entry ) => $outer_class::restore( $entry, $this->read( $entry ) ) );
					self::fail( 'Resealed c5 baseline replaced the unchanged 10b6 witness.' );
				} catch ( RuntimeException $error ) {
					self::assertSame(
						0 === $index ? 'CI diagnostic current source drift: ' . $path : 'Terminal output transition seal mismatch.',
						$error->getMessage()
					);
				}
			} finally {
				unset( $GLOBALS[ $namespace ] );
			}
		}
	}
}
