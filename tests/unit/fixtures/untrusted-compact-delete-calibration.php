<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

final class Wstm108_Compact_Delete_Calibration {
	public const LEDGER_SHA256 = '6fdda53c7ae361d154d000cfa580c3e9a313420c37268cd06ca86a5cc5cd6e14';

	public static function ledger(): object {
		$ledger = json_decode( file_get_contents( __DIR__ . '/untrusted-compact-delete-calibration.json' ), false, 512, JSON_THROW_ON_ERROR );
		Assert::assertSame( self::LEDGER_SHA256, Wstm108_Manifest_Projection::fingerprint( $ledger ) );
		Assert::assertSame( '18a8716e469778819b1c92a3d6720df19c1d029b', $ledger->source_sha );
		Assert::assertSame( '2feed8d18d0721a4c7ca2e0187005c8cfae76322', $ledger->baseline_sha );
		Assert::assertSame( array( 69, 79, 202, 219, 509 ), array_column( $ledger->cases, 'index' ) );
		return $ledger;
	}

	public static function restore_historical_cases( array $manifest, object $ledger ): array {
		Assert::assertSame( self::LEDGER_SHA256, Wstm108_Manifest_Projection::fingerprint( $ledger ) );
		Assert::assertCount( 570, $manifest );
		Assert::assertSame( range( 0, 569 ), array_keys( $manifest ) );
		$copy = json_decode( json_encode( $manifest, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 512, JSON_THROW_ON_ERROR );
		foreach ( $ledger->cases as $entry ) {
			Assert::assertSame( $entry->after_sha256, Wstm108_Manifest_Projection::fingerprint( $entry->after ) );
			Assert::assertSame( $entry->before_sha256, Wstm108_Manifest_Projection::fingerprint( $entry->before ) );
			Assert::assertSame( serialize( $entry->after ), serialize( $copy[ $entry->index ] ), 'Unapproved compact-delete case change: ' . $entry->label );
			$copy[ $entry->index ] = json_decode( json_encode( $entry->before, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 512, JSON_THROW_ON_ERROR );
		}
		return $copy;
	}
}
