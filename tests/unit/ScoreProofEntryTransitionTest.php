<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/score-proof-entry-transition.php';
require_once __DIR__ . '/fixtures/legacy-runtime-transition.php';
require_once __DIR__ . '/fixtures/shared-helper-transition.php';

final class ScoreProofEntryTransitionTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_exact_invocation_reversal_preserves_all_seals_and_original_hashes(): void {
		$map = Wstm167ScoreProofEntryTransition::load();
		self::assertSame( '91a6a242306fcda43986d282773d8cbe1a9d1b73', $map['base_commit'] );
		self::assertSame( [
			'tests/unit/AdmissionNativeFixtureTransitionTest.php',
			'tests/unit/UntrustedAuthorityObserverTest.php',
			'tests/unit/UntrustedLegacyRetentionTest.php',
		], array_keys( $map['files'] ) );
		Wstm167ScoreProofEntryTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['files'] as $path => $binding ) {
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', Wstm167ScoreProofEntryTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['preserved_raw_sha256'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167CiDiagnosticTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		self::assertSame( 'bbc14f6fdf5184f515a93dedd19b29d707245e52bd4d17207a61b5fbfd8e2076', Wstm167ScoreMarkerTransition::SEAL );
		$path = 'tests/unit/fixtures/bounded-admission-transition.php';
		self::assertSame( 'c88a4fcab2fa45d3468f8acb6292cb7f2bae8cdb410e11f52f9a5dfc831412f2',
			hash( 'sha256', Wstm167ScoreProofEntryTransition::restore( $path, $this->read( $path ) ) ) );
		$legacy = Wstm166LegacyRuntimeTransition::load();
		$path = 'tests/unit/AdmissionNativeFixtureTransitionTest.php';
		$before = Wstm167ScoreProofEntryTransition::restore( $path, $this->read( $path ) );
		self::assertSame( $legacy['files'][ $path ]['baseline_raw_sha256'], hash( 'sha256', Wstm166LegacyRuntimeTransition::restore( $path, $before ) ) );
		Wstm127SourceTransition::verify_dependencies();
		$unbound = "<?php\r\n// outside the reviewed scope\r\n";
		self::assertSame( $unbound, Wstm167ScoreProofEntryTransition::restore( 'tests/other.php', $unbound ) );
	}

	public function test_omitted_reverted_and_foreign_consumers_cannot_bypass_dependency_checks(): void {
		$outer = Wstm167ScopedHostObservationTransition::load();
		foreach ( Wstm167ScoreProofEntryTransition::load()['files'] as $path => $binding ) {
			$current = $this->read( $path );
			$before = Wstm167ScoreProofEntryTransition::restore( $path, $current );
			foreach ( [ $before, $current . "\nforeign", str_replace( "\n", "\r\n", $current ) ] as $foreign ) {
				try {
					Wstm167ScoreProofEntryTransition::restore( $path, $foreign );
					self::fail( 'Accepted unreviewed entry source: ' . $path );
				} catch ( RuntimeException $error ) {
					$context = isset( $outer['files'][ $path ] ) ? 'CI diagnostic current source drift: ' : 'Score proof entry current source drift: ';
					self::assertSame( $context . $path, $error->getMessage() );
				}
			}
			foreach ( [ false, '', $current . "\nforeign" ] as $foreign ) {
				try {
					Wstm167ScoreProofEntryTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Accepted omitted/foreign entry dependency.' );
				} catch ( RuntimeException $error ) {
					$context = 'Score proof entry dependency current source drift: ';
					if ( isset( $outer['files'][ $path ] ) ) {
						$context = is_string( $foreign ) ? 'CI diagnostic current source drift: ' : 'CI diagnostic dependency drift: ';
					} elseif ( isset( $outer['dependencies'][ $path ] ) ) {
						$context = 'CI diagnostic dependency drift: ';
					}
					self::assertSame( $context . $path, $error->getMessage() );
				}
			}
		}
		$path = 'tests/unit/fixtures/bounded-admission-transition.php';
		try {
			Wstm167ScoreProofEntryTransition::restore( $path, $this->read( $path ) . "\nforeign" );
			self::fail( 'Accepted drift in the previously sealed score consumer.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Score marker current source drift: ' . $path, $error->getMessage() );
		}
	}

	public function test_omitted_or_forged_ledger_cannot_change_expected_historical_hashes(): void {
		$json = $this->read( 'tests/unit/fixtures/score-proof-entry-transition.json' );
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$forgeries = [ $json . "\n" ];
		foreach ( array_keys( $map ) as $key ) {
			$foreign = $map;
			unset( $foreign[ $key ] );
			$forgeries[] = json_encode( $foreign, JSON_THROW_ON_ERROR );
		}
		foreach ( [ 'hunks', 'start', 'before', 'after', 'current_raw_sha256', 'baseline_raw_sha256' ] as $key ) {
			$forgeries[] = str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json );
		}
		foreach ( $forgeries as $foreign ) {
			try {
				Wstm167ScoreProofEntryTransition::load( $foreign );
				self::fail( 'Accepted forged or omitted proof ledger.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Score proof entry transition seal mismatch.', $error->getMessage() );
			}
		}
	}
}
