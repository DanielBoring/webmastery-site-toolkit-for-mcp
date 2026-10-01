<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/legacy-runtime-transition.php';
require_once __DIR__ . '/fixtures/score-proof-entry-transition.php';

final class AdmissionNativeFixtureTransitionTest extends TestCase {
	private const SEAL = '08cc480bd501c8a190699ef5a114fffd694332b4485bbc595ed13e0b87a8e99e';

	private static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/fixtures/admission-native-fixture-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) { throw new RuntimeException( 'Native fixture transition seal mismatch.' ); }
		return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}

	private static function restore( string $source, array $binding ): string {
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) { throw new RuntimeException( 'Native fixture current source drift.' ); }
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) { throw new RuntimeException( 'Native fixture reverse hunk mismatch.' ); }
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$original = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $original ) ) ) { throw new RuntimeException( 'Native fixture original source drift.' ); }
		return $original;
	}

	public function test_exact_fixture_reversal_and_unchanged_historical_proofs(): void {
		$map = self::load();
		self::assertSame( 1, $map['schema'] );
		self::assertSame( 'Synthetic admission fixture compatibility only', $map['scope'] );
		self::assertCount( 4, $map['files'] );
		$root = dirname( __DIR__, 2 );
		foreach ( $map['files'] as $path => $binding ) {
			$current = file_get_contents( $root . '/' . $path );
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', self::restore( $current, $binding ) ) );
			try {
				self::restore( $current . "\nforeign", $binding );
				self::fail( 'Foreign fixture source accepted.' );
			} catch ( RuntimeException $error ) { self::assertSame( 'Native fixture current source drift.', $error->getMessage() ); }
			$foreign = $binding;
			$foreign['hunks'][0]['after'][] = 'foreign';
			try {
				self::restore( $current, $foreign );
				self::fail( 'Foreign fixture reversal accepted.' );
			} catch ( RuntimeException $error ) { self::assertSame( 'Native fixture reverse hunk mismatch.', $error->getMessage() ); }
		}
		foreach ( $map['frozen_files'] as $path => $hash ) {
			$current = file_get_contents( $root . '/' . $path );
			$current = Wstm167ScoreProofEntryTransition::restore( $path, $current );
			self::assertSame( $hash, hash( 'sha256', Wstm166LegacyRuntimeTransition::restore( $path, $current ) ) );
		}
	}

	public function test_forged_fixture_ledger_is_not_a_reviewed_reversal(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/admission-native-fixture-transition.json' );
		foreach ( array( 'scope', 'files', 'frozen_files', 'baseline_raw_sha256', 'current_raw_sha256', 'hunks', 'start' ) as $key ) {
			try {
				self::load( str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json ) );
				self::fail( 'Forged fixture ledger accepted.' );
			} catch ( RuntimeException $error ) { self::assertSame( 'Native fixture transition seal mismatch.', $error->getMessage() ); }
		}
	}
}
