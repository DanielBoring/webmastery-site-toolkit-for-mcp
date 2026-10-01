<?php

declare(strict_types=1);

/** Outermost exact-byte reversal; no admission or authority policy is changed. */
final class Wstm167CiDiagnosticTransition {
	public const SEAL = 'a1e215abbc4e0f8ae3bf47a7becda2ab474740a8b8192ca0223afc668aeae201';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/ci-diagnostic-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'CI diagnostic transition seal mismatch.' );
		}
		return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}

	public static function verify_dependencies( callable $read ): void {
		$map = self::load();
		foreach ( $map['dependencies'] as $path => $hash ) {
			$source = $read( $path );
			if ( ! is_string( $source ) || ! hash_equals( $hash, hash( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'CI diagnostic dependency drift: ' . $path );
			}
		}
		foreach ( $map['files'] as $path => $binding ) {
			$source = $read( $path );
			if ( ! is_string( $source ) ) {
				throw new RuntimeException( 'CI diagnostic dependency drift: ' . $path );
			}
			self::restore( $path, $source );
		}
	}

	public static function restore( string $path, string $source ): string {
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( 'CI diagnostic current source drift: ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'CI diagnostic reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'CI diagnostic restored source drift: ' . $path );
		}
		return $before;
	}
}
