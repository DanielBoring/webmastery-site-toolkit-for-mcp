<?php

declare(strict_types=1);

final class Wstm119ArraySyntaxTransition {
	public const SEAL = 'b8d2ad18c87ee97c8eeb86f8601b6d1fce5af16842b7a4c4de9e886138ba53e0';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/array-syntax-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Array syntax transition seal mismatch.' );
		}
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== $map['schema'] || [
			'119' => '1bdd69f8b930f9c744afe557800eeafd195f30eeaa9b61c9b81fff5267e426d6',
			'127' => '2be14f26642f28014a9df9e99df36cb6697520b158938f1b3bad23e9da44c4f5',
			'167' => 'e847ac561c95854f66b5b04af627e992fa8886dd76eab018b730b07f42d290fa',
		] !== $map['predecessor_seals'] ) {
			throw new RuntimeException( 'Array syntax predecessor mismatch.' );
		}
		return $map;
	}

	public static function restore( string $path, string $source, string $context = 'Array syntax' ): string {
		$map = self::load();
		$source = str_replace( "\r\n", "\n", $source );
		if ( ! isset( $map['files'][ $path ] ) ) {
			return $source;
		}
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_sha256'], hash( 'sha256', $source ) )
			|| ! hash_equals( $binding['current_blob'], self::blob( $source ) ) ) {
			throw new RuntimeException( $context . ' current source drift: ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Array syntax reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$restored = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_sha256'], hash( 'sha256', $restored ) )
			|| ! hash_equals( $binding['baseline_blob'], self::blob( $restored ) ) ) {
			throw new RuntimeException( 'Array syntax restored source drift: ' . $path );
		}
		return $restored;
	}

	private static function blob( string $source ): string {
		return sha1( 'blob ' . strlen( $source ) . "\0" . $source );
	}
}
