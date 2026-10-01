<?php

declare(strict_types=1);

final class Wstm108_Bounded_Manifest_Transition {
	private const SEAL = 'f8c66be7aae8b3831b233b24c7f37dc8ed2bdaf9797712b7db826349bff66cd7';

	private static function fingerprint( $value ): string {
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	private static function ledger(): object {
		$ledger = json_decode( file_get_contents( __DIR__ . '/untrusted-bounded-manifest-transition.json' ), false, 512, JSON_THROW_ON_ERROR );
		if ( self::SEAL !== self::fingerprint( $ledger ) || 1 !== $ledger->schema ) {
			throw new RuntimeException( 'Unapproved bounded-marker manifest transition.' );
		}
		return $ledger;
	}

	private static function indexed( array $manifest, object $ledger ): array {
		if ( 601 !== count( $manifest ) || self::fingerprint( $manifest ) !== $ledger->current_sha256 ) {
			throw new RuntimeException( 'Bounded-marker complete typed manifest drift.' );
		}
		return array_column( $manifest, null, 'label' );
	}

	private static function reverse( array &$cases, array $changes ): void {
		foreach ( $changes as $change ) {
			if ( ! isset( $cases[ $change->label ] ) || self::fingerprint( $cases[ $change->label ] ) !== self::fingerprint( $change->after ) ) {
				throw new RuntimeException( 'Bounded-marker exact case transition drift.' );
			}
			$cases[ $change->label ] = $change->before;
		}
	}

	/** Restore only additive marker assertions before the frozen bounded projections. */
	public static function bounded( array $manifest ): array {
		$ledger = self::ledger();
		if ( self::fingerprint( $manifest ) === $ledger->bounded_sha256 ) {
			return $manifest;
		}
		\PHPUnit\Framework\Assert::assertCount( 601, $manifest, 'Bounded-marker exact case cardinality.' );
		\PHPUnit\Framework\Assert::assertSame( $ledger->current_sha256, self::fingerprint( $manifest ), 'Bounded-marker complete typed manifest drift.' );
		$cases = self::indexed( $manifest, $ledger );
		self::reverse( $cases, $ledger->marker_changes );
		$result = array_values( $cases );
		if ( self::fingerprint( $result ) !== $ledger->bounded_sha256 ) {
			throw new RuntimeException( 'Bounded-marker prerequisite reconstruction drift.' );
		}
		return $result;
	}

	/** Reconstruct the exact earlier marker oracle, never a live request/response. */
	public static function historical( array $manifest ): array {
		$ledger = self::ledger();
		$cases = self::indexed( $manifest, $ledger );
		self::reverse( $cases, $ledger->changes );
		foreach ( $ledger->additions as $addition ) {
			if ( ! isset( $cases[ $addition->label ] ) || self::fingerprint( $cases[ $addition->label ] ) !== self::fingerprint( $addition ) ) {
				throw new RuntimeException( 'Bounded-marker exact addition transition drift.' );
			}
			unset( $cases[ $addition->label ] );
		}
		$result = array();
		foreach ( $ledger->predecessor_order as $label ) {
			if ( ! isset( $cases[ $label ] ) ) {
				throw new RuntimeException( 'Bounded-marker predecessor case missing.' );
			}
			$result[] = $cases[ $label ];
		}
		if ( 589 !== count( $cases ) || self::fingerprint( $result ) !== $ledger->predecessor_sha256 ) {
			throw new RuntimeException( 'Bounded-marker historical reconstruction drift.' );
		}
		return $result;
	}
}
