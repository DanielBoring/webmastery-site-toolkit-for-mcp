<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/bounded-manifest-projection.php';
require_once __DIR__ . '/fixtures/runtime-calibration.php';

final class DestructiveBooleanLedgerTest extends TestCase {
	private static function fingerprint( array $cases, bool $without_oracles = false ): string {
		$copy = json_decode( json_encode( $cases, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 512, JSON_THROW_ON_ERROR );
		if ( $without_oracles ) {
			foreach ( $copy as $case ) {
				unset( $case->expect_error_code, $case->expect_error_reason );
			}
		}
		return hash( 'sha256', json_encode( $copy, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	private static function baseline_projection( array $manifest, array $integration ): array {
		$manifest = BoundedManifestProjection::accepted_main( $manifest );
		self::assertSame( 'db041ce338634ebf829ca01c69056b08fe6397d1', $integration['source_sha'] );
		self::assertSame( 570, $integration['manifest_count'] );
		self::assertSame( 372, $integration['insertion_index'] );
		self::assertSame( 327, $integration['source_insertion_index'] );
		self::assertCount( 570, $manifest );
		self::assertCount( 7, $integration['cases'] );
		foreach ( $integration['cases'] as $offset => $import ) {
			$case = $manifest[372 + $offset];
			self::assertSame( $import['label'], $case->label );
			self::assertSame( $import['sha256'], hash( 'sha256', json_encode( $case, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) );
		}
		array_splice( $manifest, 372, 7 );
		self::assertCount( 563, $manifest );
		return $manifest;
	}

	public function test_approved_oracle_delta_preserves_all_typed_inputs_and_other_cases(): void {
		$directory = dirname( __DIR__ ) . '/e2e/';
		$ledger = json_decode( file_get_contents( $directory . 'destructive-safety-boolean-ledger.json' ), true, 512, JSON_THROW_ON_ERROR );
		$manifest = json_decode( file_get_contents( $directory . 'abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
		$manifest = self::baseline_projection( $manifest, $ledger['integration'] );
		self::assertCount( 563, $manifest );
		self::assertCount( 16, $ledger['cases'] );
		$unchanged = $manifest;
		foreach ( $ledger['cases'] as $change ) {
			$case = $manifest[ $change['index'] ];
			self::assertSame( $change['label'], $case->label );
			self::assertSame( $change['value'], $case->input->{$change['flag']} );
			self::assertSame( 'editor', $case->role );
			self::assertSame( 'failure', $case->expect );
			self::assertSame( 'canonical', $case->expect_error_shape );
			self::assertTrue( $case->assert_unchanged );
			foreach ( $change['after'] as $field => $value ) {
				self::assertSame( $value, $case->$field );
			}
			unset( $unchanged[ $change['index'] ] );
		}
		self::assertCount( 547, $unchanged );
		self::assertSame( $ledger['unchanged_cases_sha256'], self::fingerprint( $unchanged ) );
		self::assertSame( $ledger['all_non_oracle_fields_sha256'], self::fingerprint( $manifest, true ) );
	}

	public static function integration_mutations(): array {
		return array_map( static fn( string $mutation ): array => array( $mutation ), array( 'privacy-role', 'privacy-integer-float', 'privacy-order', 'extra-case', 'missing-case', 'baseline-order', 'baseline-input' ) );
	}

	/** @dataProvider integration_mutations */
	public function test_only_exact_accepted_privacy_rows_can_be_projected_out( string $mutation ): void {
		$directory = dirname( __DIR__ ) . '/e2e/';
		$ledger = json_decode( file_get_contents( $directory . 'destructive-safety-boolean-ledger.json' ), true, 512, JSON_THROW_ON_ERROR );
		$manifest = json_decode( file_get_contents( $directory . 'abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
		$labels = array_flip( array_column( $manifest, 'label' ) );
		$privacy = $labels[ $ledger['integration']['cases'][0]['label'] ];
		$numeric = $labels[ $ledger['integration']['cases'][5]['label'] ];
		$base = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/bounded-list-manifest-migration.json' ), true, 512, JSON_THROW_ON_ERROR );
		$first = $labels[ $base['baseline'][0]['label'] ];
		$second = $labels[ $base['baseline'][1]['label'] ];
		if ( 'privacy-role' === $mutation ) { $manifest[ $privacy ]->role = 'subscriber'; }
		if ( 'privacy-integer-float' === $mutation ) { $manifest[ $numeric ]->input->include_table_names = 1.0; }
		if ( 'privacy-order' === $mutation ) { [ $manifest[ $privacy ], $manifest[ $privacy + 1 ] ] = array( $manifest[ $privacy + 1 ], $manifest[ $privacy ] ); }
		if ( 'extra-case' === $mutation ) { $manifest[] = clone $manifest[ $privacy ]; }
		if ( 'missing-case' === $mutation ) { array_pop( $manifest ); }
		if ( 'baseline-order' === $mutation ) { [ $manifest[ $first ], $manifest[ $second ] ] = array( $manifest[ $second ], $manifest[ $first ] ); }
		if ( 'baseline-input' === $mutation ) { $manifest[ $first ]->input->unapproved = true; }
		$this->expectException( PHPUnit\Framework\AssertionFailedError::class );
		$baseline = self::baseline_projection( $manifest, $ledger['integration'] );
		self::assertSame( $ledger['all_non_oracle_fields_sha256'], self::fingerprint( $baseline, true ) );
	}

	public function test_fingerprints_distinguish_objects_arrays_and_integer_float_inputs(): void {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
		$before = self::fingerprint( $manifest, true );
		$case = json_decode( '{"input":{"ids":[42],"value":{}}}', false, 512, JSON_THROW_ON_ERROR );
		$manifest[0] = $case;
		$typed = self::fingerprint( $manifest, true );
		self::assertNotSame( $before, $typed );
		$case->input->ids[0] = 42.0;
		self::assertNotSame( $typed, self::fingerprint( $manifest, true ) );
		$case->input->ids[0] = 42;
		$case->input->value = array();
		self::assertNotSame( $typed, self::fingerprint( $manifest, true ) );
	}

	public function test_runtime_matrix_changes_only_the_calibrated_non_direct_values(): void {
		foreach ( array( 'direct', 'ability', 'http', 'individual' ) as $boundary ) {
			$original = Wstm121Runtime\original_labels( $boundary );
			$actual = array_keys( Wstm121Runtime\matrix( $boundary ) );
			self::assertCount( 124, $original );
			self::assertCount( 130, $actual );
			self::assertSame( $original, array_values( array_intersect( $actual, $original ) ), 'All original controls and their relative order remain.' );
			$additional = array();
			foreach ( array( 'url', 'guid', 'unused' ) as $reference ) {
				foreach ( array( 0, 1 ) as $force ) { $additional[] = "media $reference content scan failure force $force"; }
			}
			self::assertSame( $additional, array_values( array_diff( $actual, $original ) ) );
		}
	}
}
