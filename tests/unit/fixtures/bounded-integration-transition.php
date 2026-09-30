<?php

final class Wstm167SourceTransition {
	public const SEAL = 'e847ac561c95854f66b5b04af627e992fa8886dd76eab018b730b07f42d290fa';
	public const PREDECESSOR = '2be14f26642f28014a9df9e99df36cb6697520b158938f1b3bad23e9da44c4f5';

	public static function load( ?string $json = null ): array {
		$json = str_replace( "\r\n", "\n", $json ?? file_get_contents( __DIR__ . '/bounded-integration-transition.json' ) );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Bounded integration transition seal mismatch.' );
		}
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( self::PREDECESSOR !== $map['predecessor_seal'] || 1 !== $map['schema'] ) {
			throw new RuntimeException( 'Bounded integration predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( ?callable $read = null ): void {
		$read = $read ?? static fn( $path ) => file_get_contents( dirname( __DIR__, 3 ) . '/' . $path );
		foreach ( self::load()['dependencies'] as $path => $hash ) {
			$source = str_replace( "\r\n", "\n", $read( $path ) );
			if ( ! hash_equals( $hash, hash( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'Bounded integration dependency current source drift: ' . $path );
			}
		}
	}

	public static function restore( string $path, string $source ): string {
		$map = self::load();
		$source = str_replace( "\r\n", "\n", $source );
		if ( ! isset( $map['files'][ $path ] ) ) {
			return $source;
		}
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_sha256'], hash( 'sha256', $source ) )
			|| ! hash_equals( $binding['current_blob'], self::blob( $source ) ) ) {
			throw new RuntimeException( 'Bounded integration current source drift: ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Bounded integration reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$restored = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_sha256'], hash( 'sha256', $restored ) )
			|| ! hash_equals( $binding['baseline_blob'], self::blob( $restored ) ) ) {
			throw new RuntimeException( 'Bounded integration restored source drift: ' . $path );
		}
		return $restored;
	}

	private static function blob( string $source ): string {
		return sha1( 'blob ' . strlen( $source ) . "\0" . $source );
	}
}
