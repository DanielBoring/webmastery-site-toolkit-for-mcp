<?php

declare(strict_types=1);

require_once __DIR__ . '/score-marker-transition.php';

final class Wstm167ScoreProofEntryTransition {
	public const SEAL = 'e79abfdb00bcb85deff22b30c836b2bfbc2ac1e343a8b20dc9ba1311aaeb8ca8';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/score-proof-entry-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Score proof entry transition seal mismatch.' );
		}
		return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}

	public static function verify_dependencies( callable $read ): void {
		foreach ( self::load()['files'] as $path => $binding ) {
			$source = $read( $path );
			if ( ! is_string( $source ) || ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'Score proof entry dependency current source drift: ' . $path );
			}
			self::restore( $path, $source );
		}
	}

	public static function restore( string $path, string $source ): string {
		$map = self::load();
		if ( isset( $map['files'][ $path ] ) ) {
			$binding = $map['files'][ $path ];
			if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'Score proof entry current source drift: ' . $path );
			}
			$lines = explode( "\n", $source );
			foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
				if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
					throw new RuntimeException( 'Score proof entry reverse hunk mismatch: ' . $path );
				}
				array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
			}
			$source = implode( "\n", $lines );
			if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'Score proof entry restored source drift: ' . $path );
			}
		}
		return Wstm167ScoreMarkerTransition::restore( $path, $source );
	}
}
