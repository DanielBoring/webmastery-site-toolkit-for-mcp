<?php

declare(strict_types=1);

final class Wstm167ScoreMarkerTransition {
	public const SEAL = 'bbc14f6fdf5184f515a93dedd19b29d707245e52bd4d17207a61b5fbfd8e2076';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/score-marker-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Score marker transition seal mismatch.' );
		}
		return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}

	public static function verify_dependencies( callable $read, string $context = 'Score marker dependency' ): void {
		foreach ( self::load()['files'] as $path => $binding ) {
			$source = $read( $path );
			if ( ! is_string( $source ) || ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
				throw new RuntimeException( $context . ' current source drift: ' . $path );
			}
			self::restore( $path, $source, $context );
		}
	}

	public static function restore( string $path, string $source, string $context = 'Score marker' ): string {
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) {
			return $source;
		}
		$source = str_replace( "\r\n", "\n", $source );
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_sha256'], hash( 'sha256', $source ) )
			|| ! hash_equals( $binding['current_blob'], self::blob( $source ) ) ) {
			throw new RuntimeException( $context . ' current source drift: ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Score marker reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_sha256'], hash( 'sha256', $before ) )
			|| ! hash_equals( $binding['baseline_blob'], self::blob( $before ) )
			|| ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Score marker restored source drift: ' . $path );
		}
		return $before;
	}

	private static function blob( string $source ): string {
		return sha1( 'blob ' . strlen( $source ) . "\0" . $source );
	}
}
