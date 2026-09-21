<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DestructiveBooleanLedgerTest extends TestCase {
	public function test_approved_oracle_delta_preserves_all_typed_inputs_and_other_cases(): void {
		$directory = dirname( __DIR__ ) . '/e2e/';
		$ledger = json_decode( file_get_contents( $directory . 'destructive-safety-boolean-ledger.json' ), true, 512, JSON_THROW_ON_ERROR );
		$manifest = json_decode( file_get_contents( $directory . 'abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertCount( 563, $manifest );
		self::assertCount( 16, $ledger['cases'] );
		$unchanged = $manifest;
		foreach ( $ledger['cases'] as $change ) {
			$case = $manifest[ $change['index'] ];
			self::assertSame( $change['label'], $case['label'] );
			self::assertSame( $change['value'], $case['input'][ $change['flag'] ] );
			self::assertSame( 'editor', $case['role'] );
			self::assertSame( 'failure', $case['expect'] );
			self::assertSame( 'canonical', $case['expect_error_shape'] );
			self::assertTrue( $case['assert_unchanged'] );
			foreach ( $change['after'] as $field => $value ) {
				self::assertSame( $value, $case[ $field ] );
			}
			unset( $unchanged[ $change['index'] ] );
		}
		self::assertCount( 547, $unchanged );
		self::assertSame( $ledger['unchanged_cases_sha256'], hash( 'sha256', json_encode( $unchanged ) ) );
		foreach ( $manifest as &$case ) {
			unset( $case['expect_error_code'], $case['expect_error_reason'] );
		}
		unset( $case );
		self::assertSame( $ledger['all_non_oracle_fields_sha256'], hash( 'sha256', json_encode( $manifest ) ) );
	}

	public function test_runtime_matrix_changes_only_the_calibrated_non_direct_values(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/destructive-safety-runner.php' );
		self::assertStringContainsString( "'direct' === \$boundary || in_array( \$label, array( 'string', 'number' ), true ) ? 'missing_confirmation' : 'ability_invalid_input'", $source );
		self::assertStringContainsString( "'direct' === \$boundary || \$index < 4 ? 'invalid_input' : 'ability_invalid_input'", $source );
		self::assertStringContainsString( "array( 'true', 'false', 0, 1, null, array() )", $source );
		self::assertStringContainsString( "'direct' === \$boundary ? 'too_many_ids' : 'ability_invalid_input'", $source );
		self::assertStringContainsString( "'ability' === \$boundary ? 'ability_invalid_permissions' : 'forbidden'", $source );
		self::assertStringContainsString( "\$entry['before'] === \$entry['after']", $source );
		self::assertStringContainsString( 'array() === $events', $source );
	}
}
