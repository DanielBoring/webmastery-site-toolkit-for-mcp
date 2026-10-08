<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/latest-main-transition.php';

final class OriginFailureTransitionTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_exact_reversal_restores_all_527_raw_paths_before_unchanged_current_prerequisite_chain(): void {
		$map = Wstm167OriginFailureTransition::load();
		self::assertSame( Wstm167HostPrerequisiteTransition::SEAL, $map['predecessor_seal'] );
		self::assertSame( 'db5a66e78ee65a0e1382609d562802fd18baabfc', $map['base_commit'] );
		self::assertCount( 527, $map['baseline_inventory'] );
		Wstm167LatestMainTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['baseline_inventory'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167OriginFailureTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['frozen_json'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $this->read( $path ) ), $path );
		}
	}

	public function test_current_execution_and_prerequisite_controls_remain_exact_and_every_path_is_bound(): void {
		$map = Wstm167OriginFailureTransition::load();
		foreach ( $map['current_execution'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167SignedPhpTransition::restore( $path, $this->read( $path ) ) ), $path );
			self::assertTrue( isset( $map['dependencies'][ $path ] ) || isset( $map['files'][ $path ] ), $path );
		}
		self::assertArrayHasKey( 'docs/qa-strategy.md', $map['files'] );
		self::assertArrayHasKey( 'tests/unit/fixtures/host-prerequisite-transition.php', $map['files'] );
		self::assertArrayHasKey( 'scripts/host-prerequisite-inventory.py', $map['files'] );
		self::assertArrayHasKey( 'tests/unit/OriginFailureTransitionTest.php', $map['dependencies'] );
		self::assertArrayNotHasKey( 'tests/unit/OriginFailureTransitionTest.php', $map['files'] );
		foreach ( array(
			'host-prerequisite-inventory.py' => '85b7e83fb13eeb8bcea67471d11c4cd620a54c8376d432d8d27753c2ce992a95',
			'provision-php82-permissions.py' => '8bd450bf25243e4138c9e8ff801a421823687ca40a476b44e02e6167ac6e834c',
			'host-prerequisite-setup.py' => '62c304a0c3889fe5e4f4e670e17deddbd4ab7ab066955fcb6c46201b4c35d000',
		) as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167SignedPhpTransition::restore( 'scripts/' . $path, $this->read( 'scripts/' . $path ) ) ), $path );
			self::assertSame( $hash, $map['files'][ 'scripts/' . $path ]['current_raw_sha256'] );
		}
		self::assertCount( 530, array_merge( $map['files'], $map['dependencies'], array( $map['helper_path'] => true, 'tests/unit/fixtures/origin-failure-transition.json' => true ) ) );
		$helper = preg_replace( "/^\tpublic const SEAL = 'ORIGIN_FAILURE_SEAL_PENDING';(?=\\r?$)/m", "\tpublic const SEAL = '" . Wstm167OriginFailureTransition::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
		self::assertSame( 1, $replaced );
		self::assertSame( $helper, Wstm167SignedPhpTransition::restore( $map['helper_path'], $this->read( $map['helper_path'] ) ) );
	}

	public function test_missing_truncated_foreign_crlf_old_raw_and_helper_body_drift_refuse(): void {
		$map = Wstm167OriginFailureTransition::load();
		$paths = array_merge( array_keys( $map['files'] ), array(
			'tests/unit/OriginFailureTransitionTest.php', $map['helper_path'],
			'scripts/host-prerequisite-inventory.py', 'scripts/provision-php82-permissions.py', 'scripts/host-prerequisite-setup.py',
			'tests/unit/test_host_prerequisite_inventory.py', 'tests/unit/test_provision_php82_permissions.py', 'tests/unit/test_host_prerequisite_setup.py',
			'tests/unit/fixtures/host-prerequisite-transition.json', 'scripts/untrusted-host-controller.php',
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
				$old = Wstm167OriginFailureTransition::restore( $path, $current );
				try {
					Wstm167OriginFailureTransition::restore( $path, $old );
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
		$json = $this->read( 'tests/unit/fixtures/origin-failure-transition.json' );
		$map = Wstm167OriginFailureTransition::load();
		$forgeries = array( '', substr( $json, 0, -1 ), $json . "\n", str_replace( "\n", "\r\n", $json ),
			$this->read( 'tests/unit/fixtures/host-prerequisite-transition.json' ),
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
				Wstm167OriginFailureTransition::load( $foreign );
				self::fail( 'Unsealed prerequisite map accepted after decoded cache population.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Origin failure transition seal mismatch.', $error->getMessage() );
			}
		}
	}

	public function test_resealed_wrong_baseline_is_rejected_by_unchanged_f9_witness(): void {
		$path = 'scripts/host-prerequisite-inventory.py';
		$current = Wstm167SignedPhpTransition::restore( $path, $this->read( $path ) );
		$map = Wstm167OriginFailureTransition::load();
		$wrong = Wstm167OriginFailureTransition::restore( $path, $current ) . "\nforeign_guard";
		$map['files'][ $path ]['hunks'][] = array(
			'start' => count( explode( "\n", $current ) ), 'before' => array( 'foreign_guard' ), 'after' => array(),
		);
		$map['files'][ $path ]['baseline_raw_sha256'] = hash( 'sha256', $wrong );
		$json = json_encode( $map, JSON_THROW_ON_ERROR );
		$older_json = $this->read( 'tests/unit/fixtures/host-prerequisite-transition.json' );
		$altered = json_decode( $older_json, true, 512, JSON_THROW_ON_ERROR );
		$altered['dependencies'][ $path ] = hash( 'sha256', $wrong );
		foreach ( array( $older_json, json_encode( $altered, JSON_THROW_ON_ERROR ) ) as $index => $historical_json ) {
			$namespace = 'WstmOriginFailureFrozenWitness' . $index;
			$GLOBALS[ $namespace ] = array( 'origin-failure-transition.json' => $json, 'host-prerequisite-transition.json' => $historical_json );
			$outer = str_replace(
				"public const SEAL = '" . Wstm167OriginFailureTransition::SEAL . "';",
				"public const SEAL = '" . hash( 'sha256', $json ) . "';",
				Wstm167SignedPhpTransition::restore( 'tests/unit/fixtures/origin-failure-transition.php', $this->read( 'tests/unit/fixtures/origin-failure-transition.php' ) ), $replaced
			);
			self::assertSame( 1, $replaced );
			$older = Wstm167OriginFailureTransition::restore( 'tests/unit/fixtures/host-prerequisite-transition.php', $this->read( 'tests/unit/fixtures/host-prerequisite-transition.php' ) );
			foreach ( array( $outer, $older ) as $source_index => $source ) {
				$body = preg_replace( '/^<\?php\s+declare\(strict_types=1\);\s*/', '', $source, 1, $removed );
				self::assertSame( 1, $removed );
				$reader = 0 === $source_index
					? 'function file_get_contents( $path ) { $fixtures = $GLOBALS[' . var_export( $namespace, true ) . ']; $name = basename( $path ); if ( ! array_key_exists( $name, $fixtures ) ) { throw new \RuntimeException( "Unexpected test-only fixture read." ); } return $fixtures[ $name ]; }'
					: '';
				eval( 'namespace ' . $namespace . '; use \RuntimeException; ' . $reader . $body );
			}
			$outer_class = $namespace . '\\Wstm167OriginFailureTransition';
			$older_class = $namespace . '\\Wstm167HostPrerequisiteTransition';
			try {
				self::assertSame( $wrong, $outer_class::restore( $path, $current ) );
				try {
					$older_class::verify_dependencies( fn( $entry ) => $outer_class::restore( $entry, Wstm167SignedPhpTransition::restore( $entry, $this->read( $entry ) ) ) );
					self::fail( 'Resealed prerequisite baseline replaced the unchanged 3876 witness.' );
				} catch ( RuntimeException $error ) {
					self::assertSame(
						0 === $index ? 'CI diagnostic dependency drift: ' . $path : 'Host prerequisite transition seal mismatch.',
						$error->getMessage()
					);
				}
			} finally {
				unset( $GLOBALS[ $namespace ] );
			}
		}
	}
}
