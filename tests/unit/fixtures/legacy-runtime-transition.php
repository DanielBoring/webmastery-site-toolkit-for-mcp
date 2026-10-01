<?php

declare(strict_types=1);

final class Wstm166LegacyRuntimeTransition {
	public const SEAL = 'e713a22640ae27f2b3fc9de235fc32620cb6270b77a6bc3527d661dfa48b5b9d';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/legacy-runtime-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Legacy runtime transition seal mismatch.' );
		}
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== $map['schema']
			|| '08cc480bd501c8a190699ef5a114fffd694332b4485bbc595ed13e0b87a8e99e' !== $map['predecessor_seal'] ) {
			throw new RuntimeException( 'Legacy runtime transition predecessor mismatch.' );
		}
		return $map;
	}

	public static function restore( string $path, string $source ): string {
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		return self::reverse( $source, $map['files'][ $path ] );
	}

	private static function reverse( string $source, array $binding ): string {
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( 'Legacy runtime current source drift.' );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Legacy runtime reverse hunk mismatch.' );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Legacy runtime restored source drift.' );
		}
		return $before;
	}
}
