<?php

require_once __DIR__ . '/posts-extraction-transition.php';

final class Wstm119SourceTransition {
	public const SEAL = '1bdd69f8b930f9c744afe557800eeafd195f30eeaa9b61c9b81fff5267e426d6';

	public static function load( ?string $json = null ): array {
		$json = str_replace( "\r\n", "\n", $json ?? file_get_contents( __DIR__ . '/shared-helper-transition.json' ) );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Shared-helper transition seal mismatch.' );
		}
		return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}

	public static function restore( string $path, string $source ): string {
		$map = self::load();
		$source = Wstm127SourceTransition::restore( $path, $source );
		if ( ! isset( $map['files'][ $path ] ) ) {
			return $source;
		}
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_sha256'], hash( 'sha256', $source ) )
			|| ! hash_equals( $binding['current_blob'], self::blob( $source ) ) ) {
			throw new RuntimeException( 'Shared-helper current source drift: ' . $path );
		}
		foreach ( $map['helpers'] as $helper => $hash ) {
			$bytes = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 3 ) . '/' . $helper ) );
			$bytes = Wstm127SourceTransition::restore( $helper, $bytes );
			if ( ! hash_equals( $hash, hash( 'sha256', $bytes ) ) ) {
				throw new RuntimeException( 'Shared-helper dependency source drift: ' . $helper );
			}
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			$after = array_map( static fn( $line ) => rtrim( $line, "\n" ), $hunk['after'] );
			$before = array_map( static fn( $line ) => rtrim( $line, "\n" ), $hunk['before'] );
			if ( $after !== array_slice( $lines, $hunk['start'], count( $after ) ) ) {
				throw new RuntimeException( 'Shared-helper reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $after ), $before );
		}
		$restored = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_sha256'], hash( 'sha256', $restored ) )
			|| ! hash_equals( $binding['baseline_blob'], self::blob( $restored ) ) ) {
			throw new RuntimeException( 'Shared-helper restored source drift: ' . $path );
		}
		return $restored;
	}

	private static function blob( string $source ): string {
		return sha1( 'blob ' . strlen( $source ) . "\0" . $source );
	}
}
