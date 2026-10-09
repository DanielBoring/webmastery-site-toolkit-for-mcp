<?php

declare(strict_types=1);

final class Wstm108_Schema_Integration {
	private const LEDGER_SHA256 = '4989018232ed31d2246d2cf12e4c6c622df3005ffb1f3ec8c15c837d270dcb14';

	public static function fingerprint( $value ): string {
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public static function ledger(): object {
		$ledger = json_decode( file_get_contents( __DIR__ . '/untrusted-schema-integration-ledger.json' ), false, 512, JSON_THROW_ON_ERROR );
		if ( self::LEDGER_SHA256 !== self::fingerprint( $ledger ) ) {
			throw new RuntimeException( 'Unapproved schema-marker integration ledger.' );
		}
		return $ledger;
	}

	public static function main_input( array $manifest ): array {
		if ( 601 === count( $manifest ) ) {
			require_once __DIR__ . '/untrusted-bounded-manifest-transition.php';
			$manifest = Wstm108_Bounded_Manifest_Transition::historical( $manifest );
		}
		$ledger = self::ledger();
		$hash = self::fingerprint( $manifest );
		if ( $hash === $ledger->main_manifest_sha256 ) {
			return $manifest;
		}
		if ( $hash !== $ledger->merged_manifest_sha256 || count( $manifest ) !== 589 ) {
			throw new RuntimeException( 'Unexpected complete typed integrated manifest.' );
		}
		foreach ( $ledger->changes as $change ) {
			if ( $manifest[ $change->index ]->label !== $change->label
				|| self::fingerprint( $manifest[ $change->index ] ) !== $change->after_sha256
				|| self::fingerprint( $change->before ) !== $change->before_sha256 ) {
				throw new RuntimeException( 'Invalid exact schema-marker case provenance.' );
			}
			$manifest[ $change->index ] = $change->before;
		}
		if ( self::fingerprint( $manifest ) !== $ledger->main_manifest_sha256 ) {
			throw new RuntimeException( 'Integration did not reconstruct immutable main.' );
		}
		return $manifest;
	}

	public static function pr_input( array $manifest ): array {
		require_once __DIR__ . '/schema-integration-ledger.php';
		$ledger = self::ledger();
		$parent = wstm126_parent_manifest( self::main_input( $manifest ) );
		foreach ( $ledger->changes as $change ) {
			if ( null === $change->pr_index ) {
				continue;
			}
			$case = $parent[ $change->pr_index ];
			if ( $case->label !== $change->label ) {
				throw new RuntimeException( 'Historical marker case order changed.' );
			}
			if ( ! property_exists( $case, 'assert_values' ) ) {
				$case->assert_values = new stdClass();
			}
			foreach ( get_object_vars( $change->additions ) as $field => $value ) {
				if ( property_exists( $case->assert_values, $field ) ) {
					throw new RuntimeException( 'Integration would replace a parent oracle.' );
				}
				$case->assert_values->$field = $value;
			}
		}
		if ( self::fingerprint( $parent ) !== $ledger->pr_manifest_sha256 ) {
			throw new RuntimeException( 'Integration did not reconstruct immutable PR source.' );
		}
		return $parent;
	}
}
