<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

final class QaAssertionTransition {
	private const SEAL = 'f3f20a86dcff926e696fb37d87a3c956d7799503265256ea4d5def67b7f1eaf7';

	public static function fingerprint( $value ): string {
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public static function ledger(): object {
		$ledger = json_decode( file_get_contents( __DIR__ . '/qa-assertion-transition.json' ), false, 512, JSON_THROW_ON_ERROR );
		Assert::assertSame( self::SEAL, self::fingerprint( $ledger ), 'Exact reviewed QA assertion transition.' );
		Assert::assertSame( 1, $ledger->schema );
		Assert::assertSame( 601, $ledger->case_count );
		Assert::assertCount( 11, $ledger->changes );
		return $ledger;
	}

	/** Reconstruct historical test views only; live runners always use the stronger manifest. */
	public static function before( array $manifest ): array {
		$ledger = self::ledger();
		if ( self::fingerprint( $manifest ) === $ledger->before_sha256 ) {
			return $manifest;
		}
		Assert::assertCount( $ledger->case_count, $manifest );
		Assert::assertSame( $ledger->after_sha256, self::fingerprint( $manifest ), 'Complete strengthened typed manifest drift.' );
		foreach ( $ledger->changes as $change ) {
			$case = clone $manifest[ $change->index ];
			Assert::assertSame( $change->label, $case->label );
			Assert::assertSame( 'success', $case->expect );
			Assert::assertSame( $change->after_sha256, self::fingerprint( $case ) );
			foreach ( $change->added_fields as $field ) {
				Assert::assertContains( $field, array( 'assert_paths', 'assert_values', 'assert_term_state', 'assert_bucket_counts' ) );
				Assert::assertTrue( property_exists( $case, $field ) );
				unset( $case->$field );
			}
			Assert::assertSame( $change->before_sha256, self::fingerprint( $case ), 'Only additive assertions may be reversed.' );
			$manifest[ $change->index ] = $case;
		}
		Assert::assertSame( $ledger->before_sha256, self::fingerprint( $manifest ), 'Exact historical manifest reconstruction.' );
		return $manifest;
	}
}
