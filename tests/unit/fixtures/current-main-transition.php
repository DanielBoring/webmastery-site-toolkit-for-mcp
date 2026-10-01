<?php

declare(strict_types=1);

/** Exact current-main integration before the accepted selected-listener projection. */
final class Wstm167CurrentMainTransition {
	public const SEAL = '21ab59664f8a249323e9c94bbcf197965a3a42c562f6c271cc98b330a38007c7';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/current-main-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Current main transition seal mismatch.' );
		}
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== $map['schema'] || 'a761873df07406dcde676d14f80a78283b572af2' !== $map['base_commit']
			|| '545e7599f6f7ad081f08d9abdedd6c8a31f76d6c' !== $map['main_commit']
			|| 'd6bbca72dcd3f823bffee69b3cd03e4ff51232cc324d919b05191103d11c53ee' !== $map['predecessor_seal'] ) {
			throw new RuntimeException( 'Current main predecessor mismatch.' );
		}
		return $map;
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

	public static function restore( string $path, string $source, string $context = 'CI diagnostic current source drift' ): string {
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( $context . ': ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Current main reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Current main restored source drift: ' . $path );
		}
		return $before;
	}
}
