<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/latest-main-transition.php';

final class HostPrerequisiteTransitionTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_exact_reversal_restores_all_518_raw_paths_before_unchanged_current_nsfs_chain(): void {
		$map = Wstm167HostPrerequisiteTransition::load();
		self::assertSame( Wstm167NsfsNetMetadataTransition::SEAL, $map['predecessor_seal'] );
		self::assertSame( '29f76178ceed3cf49eea02122defe7524eb2b555', $map['base_commit'] );
		self::assertCount( 518, $map['baseline_inventory'] );
		Wstm167LatestMainTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['baseline_inventory'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167HostPrerequisiteTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['frozen_json'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $this->read( $path ) ), $path );
		}
	}

	public function test_current_execution_and_prerequisite_controls_remain_exact_and_every_path_is_bound(): void {
		$map = Wstm167HostPrerequisiteTransition::load();
		foreach ( $map['current_execution'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167SignedPhpTransition::restore( $path, $this->read( $path ) ) ), $path );
			self::assertTrue( isset( $map['dependencies'][ $path ] ) || isset( $map['files'][ $path ] ), $path );
		}
		self::assertArrayHasKey( 'composer.json', $map['files'] );
		self::assertArrayHasKey( 'tests/unit/fixtures/nsfs-net-metadata-transition.php', $map['files'] );
		self::assertArrayHasKey( 'scripts/host-prerequisite-inventory.py', $map['dependencies'] );
		self::assertArrayHasKey( 'tests/unit/HostPrerequisiteTransitionTest.php', $map['dependencies'] );
		self::assertArrayNotHasKey( 'tests/unit/HostPrerequisiteTransitionTest.php', $map['files'] );
		foreach ( array(
			'host-prerequisite-inventory.py' => '3156c7acc8fd38defc52f297d3bfba0c04539cb1536835675deb450c66d0e1c6',
			'provision-php82-permissions.py' => 'b4d886163e156239c58358fd473099debfc6b9b186d1e341ba42ecfbd18cc6d5',
			'host-prerequisite-setup.py' => 'f7582b1d1e17c8cc8edb5b84e2d7b42ce7615488cdcf46382d31404c5e6bc10a',
		) as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167OriginFailureTransition::restore( 'scripts/' . $path, $this->read( 'scripts/' . $path ) ) ), $path );
			self::assertSame( $hash, $map['dependencies'][ 'scripts/' . $path ] );
		}
		self::assertCount( 527, array_merge( $map['files'], $map['dependencies'], array( $map['helper_path'] => true, 'tests/unit/fixtures/host-prerequisite-transition.json' => true ) ) );
		$helper = preg_replace( "/^\tpublic const SEAL = 'HOST_PREREQUISITE_SEAL_PENDING';(?=\\r?$)/m", "\tpublic const SEAL = '" . Wstm167HostPrerequisiteTransition::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
		self::assertSame( 1, $replaced );
		self::assertSame( $helper, Wstm167OriginFailureTransition::restore( $map['helper_path'], $this->read( $map['helper_path'] ) ) );
	}

	public function test_missing_truncated_foreign_crlf_old_raw_and_helper_body_drift_refuse(): void {
		$map = Wstm167HostPrerequisiteTransition::load();
		$paths = array_merge( array_keys( $map['files'] ), array(
			'tests/unit/HostPrerequisiteTransitionTest.php', $map['helper_path'],
			'scripts/host-prerequisite-inventory.py', 'scripts/provision-php82-permissions.py', 'scripts/host-prerequisite-setup.py',
			'tests/unit/test_host_prerequisite_inventory.py', 'tests/unit/test_provision_php82_permissions.py', 'tests/unit/test_host_prerequisite_setup.py',
			'tests/unit/fixtures/nsfs-net-metadata-transition.json', 'scripts/untrusted-host-controller.php',
		) );
		foreach ( $paths as $path ) {
			$current = $this->read( $path );
			foreach ( array( false, '', substr( $current, 0, -1 ), $current . "\nforeign", str_replace( "\n", "\r\n", $current ) ) as $foreign ) {
				try {
					Wstm167LatestMainTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Foreign or missing prerequisite source accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() );
				}

			}
			if ( isset( $map['files'][ $path ] ) ) {
				$old = Wstm167HostPrerequisiteTransition::restore( $path, $current );
				try {
					Wstm167HostPrerequisiteTransition::restore( $path, $old );
					self::fail( 'Historical raw source accepted as current prerequisite source.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
	}

	public function test_static_and_normal_ci_cover_all_three_mock_suites_without_native_entry(): void {
		$composer = json_decode( $this->read( 'composer.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertContains( '@test:host-prerequisites', $composer['scripts']['qa:static'] );
		self::assertContains( '@test:host-prerequisites', $composer['scripts']['test:ci-safeguards'] );
		self::assertSame( array(
			'python3 -B -m unittest discover -s tests/unit -p test_host_prerequisite_inventory.py -v',
			'python3 -B -m unittest discover -s tests/unit -p test_provision_php82_permissions.py -v',
			'python3 -B -m unittest discover -s tests/unit -p test_host_prerequisite_setup.py -v',
		), $composer['scripts']['test:host-prerequisites'] );
		$lint = $this->read( 'scripts/php-lint.php' );
		self::assertStringContainsString( 'ast.parse', $lint );
		self::assertStringContainsString( "'py' === \$file->getExtension()", $lint );
		self::assertStringNotContainsString( 'host-prerequisite-setup.py 8.', $lint );
		foreach ( array( 'e2e-qa.yml', 'release-package-qa.yml' ) as $workflow ) {
			$source = $this->read( '.github/workflows/' . $workflow );
			self::assertStringContainsString( 'scripts/', $source );
			self::assertStringContainsString( 'tests/', $source );
			self::assertStringContainsString( "steps.php-permissions.outcome == 'success'", $source );
		}
	}

	public function test_tampered_maps_and_old_seals_refuse_after_cache_population(): void {
		$json = $this->read( 'tests/unit/fixtures/host-prerequisite-transition.json' );
		$map = Wstm167HostPrerequisiteTransition::load();
		$forgeries = array( '', substr( $json, 0, -1 ), $json . "\n", str_replace( "\n", "\r\n", $json ),
			$this->read( 'tests/unit/fixtures/nsfs-net-metadata-transition.json' ),
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
				Wstm167HostPrerequisiteTransition::load( $foreign );
				self::fail( 'Unsealed prerequisite map accepted after decoded cache population.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Host prerequisite transition seal mismatch.', $error->getMessage() );
			}
		}
	}

	public function test_resealed_wrong_baseline_is_rejected_by_unchanged_3876_witness(): void {
		$path = 'composer.json';
		$current = $this->read( $path );
		$map = Wstm167HostPrerequisiteTransition::load();
		$wrong = Wstm167HostPrerequisiteTransition::restore( $path, $current ) . "\nforeign_guard";
		$map['files'][ $path ]['hunks'][] = array(
			'start' => count( explode( "\n", $current ) ), 'before' => array( 'foreign_guard' ), 'after' => array(),
		);
		$map['files'][ $path ]['baseline_raw_sha256'] = hash( 'sha256', $wrong );
		$json = json_encode( $map, JSON_THROW_ON_ERROR );
		$older_json = $this->read( 'tests/unit/fixtures/nsfs-net-metadata-transition.json' );
		$altered = json_decode( $older_json, true, 512, JSON_THROW_ON_ERROR );
		$altered['dependencies'][ $path ] = hash( 'sha256', $wrong );
		foreach ( array( $older_json, json_encode( $altered, JSON_THROW_ON_ERROR ) ) as $index => $historical_json ) {
			$namespace = 'WstmHostPrerequisiteFrozenWitness' . $index;
			$GLOBALS[ $namespace ] = array( 'host-prerequisite-transition.json' => $json, 'nsfs-net-metadata-transition.json' => $historical_json );
			$outer = str_replace(
				"public const SEAL = '" . Wstm167HostPrerequisiteTransition::SEAL . "';",
				"public const SEAL = '" . hash( 'sha256', $json ) . "';",
				Wstm167OriginFailureTransition::restore( 'tests/unit/fixtures/host-prerequisite-transition.php', $this->read( 'tests/unit/fixtures/host-prerequisite-transition.php' ) ), $replaced
			);
			self::assertSame( 1, $replaced );
			$older = Wstm167HostPrerequisiteTransition::restore( 'tests/unit/fixtures/nsfs-net-metadata-transition.php', $this->read( 'tests/unit/fixtures/nsfs-net-metadata-transition.php' ) );
			foreach ( array( $outer, $older ) as $source_index => $source ) {
				$body = preg_replace( '/^<\?php\s+declare\(strict_types=1\);\s*/', '', $source, 1, $removed );
				self::assertSame( 1, $removed );
				$reader = 0 === $source_index
					? 'function file_get_contents( $path ) { $fixtures = $GLOBALS[' . var_export( $namespace, true ) . ']; $name = basename( $path ); if ( ! array_key_exists( $name, $fixtures ) ) { throw new \RuntimeException( "Unexpected test-only fixture read." ); } return $fixtures[ $name ]; }'
					: '';
				eval( 'namespace ' . $namespace . '; use \RuntimeException; ' . $reader . $body );
			}
			$outer_class = $namespace . '\\Wstm167HostPrerequisiteTransition';
			$older_class = $namespace . '\\Wstm167NsfsNetMetadataTransition';
			try {
				self::assertSame( $wrong, $outer_class::restore( $path, $current ) );
				try {
					$older_class::verify_dependencies( fn( $entry ) => $outer_class::restore( $entry, Wstm167OriginFailureTransition::restore( $entry, $this->read( $entry ) ) ) );
					self::fail( 'Resealed prerequisite baseline replaced the unchanged 3876 witness.' );
				} catch ( RuntimeException $error ) {
					self::assertSame(
						0 === $index ? 'CI diagnostic current source drift: ' . $path : 'Nsfs net metadata transition seal mismatch.',
						$error->getMessage()
					);
				}
			} finally {
				unset( $GLOBALS[ $namespace ] );
			}
		}
	}
}
