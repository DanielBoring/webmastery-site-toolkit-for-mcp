<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/schema-integration-ledger.php';

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
		$manifest = wstm126_parent_manifest( $manifest );
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
		$manifest = wstm126_parent_manifest( $manifest );
		if ( 'privacy-role' === $mutation ) { $manifest[372]->role = 'subscriber'; }
		if ( 'privacy-integer-float' === $mutation ) { $manifest[377]->input->include_table_names = 1.0; }
		if ( 'privacy-order' === $mutation ) { [ $manifest[372], $manifest[373] ] = array( $manifest[373], $manifest[372] ); }
		if ( 'extra-case' === $mutation ) { $manifest[] = clone $manifest[372]; }
		if ( 'missing-case' === $mutation ) { array_pop( $manifest ); }
		if ( 'baseline-order' === $mutation ) { [ $manifest[0], $manifest[1] ] = array( $manifest[1], $manifest[0] ); }
		if ( 'baseline-input' === $mutation ) { $manifest[0]->input->unapproved = true; }
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
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/destructive-safety-runner.php' );
		self::assertStringContainsString( "'direct' === \$boundary ? 'missing_confirmation' : 'ability_invalid_input'", $source );
		self::assertStringContainsString( "'direct' === \$boundary ? 'invalid_input' : 'ability_invalid_input'", $source );
		self::assertStringContainsString( "'invalid_input' === \$raw['error']['code'] && 'ability_invalid_input' === \$raw['error']['reason']", $source );
		self::assertStringContainsString( "true === \$entry['permission_is_wp_error']", $source );
		self::assertStringContainsString( "array( 'true', 'false', 0, 1, null, array() )", $source );
		self::assertStringContainsString( "'direct' === \$boundary ? 'too_many_ids' : 'ability_invalid_input'", $source );
		self::assertStringContainsString( "'ability' === \$boundary ? 'ability_invalid_permissions' : 'forbidden'", $source );
		self::assertStringContainsString( "\$entry['before'] === \$entry['after']", $source );
		self::assertStringContainsString( 'array() === $events', $source );
	}
}
