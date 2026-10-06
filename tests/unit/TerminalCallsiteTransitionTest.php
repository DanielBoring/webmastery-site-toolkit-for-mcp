<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/latest-main-transition.php';

final class TerminalCallsiteTransitionTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_exact_outer_reversal_restores_every_published_raw_path_and_old_seal(): void {
		$map = Wstm167TerminalCallsiteTransition::load();
		self::assertSame( '2dc4d85a15c7cacda9e60ef44a14e4c37d19d08e', $map['base_commit'] );
		self::assertSame( Wstm167LatestMainTransition::SEAL, $map['predecessor_seal'] );
		self::assertCount( 499, $map['baseline_inventory'] );
		Wstm167LatestMainTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['baseline_inventory'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167TerminalCallsiteTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['frozen_json'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $this->read( $path ) ), $path );
		}
	}

	public function test_actual_runtime_and_diagnostic_controls_are_exact_c2_not_historical_execution(): void {
		$map = Wstm167TerminalCallsiteTransition::load();
		foreach ( $map['diagnostic_execution'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $this->read( $path ) ), $path );
			self::assertTrue( isset( $map['dependencies'][ $path ] ) || isset( $map['files'][ $path ] ), $path );
		}
		foreach ( array( 'tests/unit/AdmissionCallsiteTest.php', 'tests/unit/NativePrerequisiteDiagnosticTest.php', 'tests/unit/fixtures/callsite-entry-shutdown.php', 'tests/unit/TerminalCallsiteTransitionTest.php' ) as $path ) {
			self::assertArrayHasKey( $path, $map['dependencies'] );
			self::assertArrayNotHasKey( $path, $map['files'] );
		}
		$helper = preg_replace( "/^\tpublic const SEAL = '[^']+';/m", "\tpublic const SEAL = '" . Wstm167TerminalCallsiteTransition::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
		self::assertSame( 1, $replaced );
		self::assertSame( $helper, Wstm167TerminalOutputTransition::restore( $map['helper_path'], $this->read( $map['helper_path'] ) ) );
	}

	public function test_new_missing_truncated_foreign_crlf_and_old_raw_inputs_refuse(): void {
		$map = Wstm167TerminalCallsiteTransition::load();
		$paths = array_merge( array_keys( $map['files'] ), array( 'tests/unit/AdmissionCallsiteTest.php', 'tests/unit/NativePrerequisiteDiagnosticTest.php', 'tests/unit/fixtures/callsite-entry-shutdown.php', 'tests/unit/TerminalCallsiteTransitionTest.php', $map['helper_path'] ) );
		foreach ( $paths as $path ) {
			$current = $this->read( $path );
			$foreign_sources = array( false, '', substr( $current, 0, -1 ), $current . "\nforeign" );
			$crlf = str_replace( "\n", "\r\n", $current );
			$foreign_sources[] = $crlf;
			foreach ( $foreign_sources as $foreign ) {
				try {
					Wstm167LatestMainTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Unreviewed diagnostic source accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() );
				}
			}
			if ( isset( $map['files'][ $path ] ) ) {
				$old = Wstm167TerminalCallsiteTransition::restore( $path, $current );
				try {
					Wstm167TerminalCallsiteTransition::restore( $path, $old );
					self::fail( 'Published raw source accepted as current diagnostic source.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
	}

	public function test_omitted_tampered_foreign_and_old_maps_fail_even_after_decoded_cache_is_populated(): void {
		$json = $this->read( 'tests/unit/fixtures/terminal-callsite-transition.json' );
		$map = Wstm167TerminalCallsiteTransition::load();
		$forgeries = array( '', $json . "\n", str_replace( "\n", "\r\n", $json ), $this->read( 'tests/unit/fixtures/latest-main-transition.json' ), $this->read( 'tests/unit/fixtures/kernel-mount-transition.json' ) );
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
				Wstm167TerminalCallsiteTransition::load( $foreign );
				self::fail( 'Unreviewed diagnostic map accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Terminal callsite transition seal mismatch.', $error->getMessage() );
			}
		}
	}

	public function test_resealed_wrong_diagnostic_baseline_is_rejected_by_unchanged_latest_main_witness(): void {
		$path = 'scripts/untrusted-host-controller.php';
		$current = $this->read( $path );
		$map = Wstm167TerminalCallsiteTransition::load();
		$wrong = Wstm167TerminalCallsiteTransition::restore( $path, $current ) . "\nforeign_guard";
		$map['files'][ $path ]['hunks'][] = array(
			'start' => count( explode( "\n", $current ) ),
			'before' => array( 'foreign_guard' ),
			'after' => array(),
		);
		$map['files'][ $path ]['baseline_raw_sha256'] = hash( 'sha256', $wrong );
		$json = json_encode( $map, JSON_THROW_ON_ERROR );
		$older_json = $this->read( 'tests/unit/fixtures/latest-main-transition.json' );
		$altered = json_decode( $older_json, true, 512, JSON_THROW_ON_ERROR );
		$altered['dependencies'][ $path ] = hash( 'sha256', $wrong );
		foreach ( array( $older_json, json_encode( $altered, JSON_THROW_ON_ERROR ) ) as $index => $historical_json ) {
			$namespace = 'WstmTerminalCallsiteFrozenWitness' . $index;
			$GLOBALS[ $namespace ] = array( 'terminal-callsite-transition.json' => $json, 'latest-main-transition.json' => $historical_json );
			$outer = str_replace(
				"public const SEAL = '" . Wstm167TerminalCallsiteTransition::SEAL . "';",
				"public const SEAL = '" . hash( 'sha256', $json ) . "';",
				Wstm167TerminalOutputTransition::restore( 'tests/unit/fixtures/terminal-callsite-transition.php', $this->read( 'tests/unit/fixtures/terminal-callsite-transition.php' ) ),
				$replaced
			);
			self::assertSame( 1, $replaced );
			$older = Wstm167TerminalCallsiteTransition::restore( 'tests/unit/fixtures/latest-main-transition.php', $this->read( 'tests/unit/fixtures/latest-main-transition.php' ) );
			foreach ( array( $outer, $older ) as $source_index => $source ) {
				$body = preg_replace( '/^<\?php\s+declare\(strict_types=1\);\s*/', '', $source, 1, $removed );
				self::assertSame( 1, $removed );
				$reader = 0 === $source_index
					? 'function file_get_contents( $path ) { $fixtures = $GLOBALS[' . var_export( $namespace, true ) . ']; $name = basename( $path ); if ( ! array_key_exists( $name, $fixtures ) ) { throw new \RuntimeException( "Unexpected test-only fixture read." ); } return $fixtures[ $name ]; }'
					: '';
				eval( 'namespace ' . $namespace . '; use \RuntimeException; ' . $reader . $body );
			}
			$outer_class = $namespace . '\\Wstm167TerminalCallsiteTransition';
			$older_class = $namespace . '\\Wstm167LatestMainTransition';
			try {
				self::assertSame( $wrong, $outer_class::restore( $path, $current ) );
				try {
					$older_class::verify_dependencies( fn( $entry ) => $outer_class::restore( $entry, $this->read( $entry ) ) );
					self::fail( 'Resealed diagnostic baseline replaced the published main witness.' );
				} catch ( RuntimeException $error ) {
					self::assertSame(
						0 === $index ? 'CI diagnostic current source drift: ' . $path : 'Latest main transition seal mismatch.',
						$error->getMessage()
					);
				}
			} finally {
				unset( $GLOBALS[ $namespace ] );
			}
		}
	}
}
