<?php

require_once __DIR__ . '/bounded-integration-transition.php';

final class Wstm127SourceTransition {
	public const SEAL = '2be14f26642f28014a9df9e99df36cb6697520b158938f1b3bad23e9da44c4f5';

	public static function load( ?string $json = null ): array {
		$json = str_replace( "\r\n", "\n", $json ?? file_get_contents( __DIR__ . '/posts-extraction-transition.json' ) );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Posts extraction transition seal mismatch.' );
		}
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( '1bdd69f8b930f9c744afe557800eeafd195f30eeaa9b61c9b81fff5267e426d6' !== $map['predecessor_seal'] ) {
			throw new RuntimeException( 'Posts extraction predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( ?callable $read = null ): void {
		$read = $read ?? static fn( $path ) => file_get_contents( dirname( __DIR__, 3 ) . '/' . $path );
		Wstm167SourceTransition::verify_dependencies( $read );
		foreach ( self::load()['dependencies'] as $path => $hash ) {
			$source = Wstm167SourceTransition::restore( $path, $read( $path ) );
			if ( ! hash_equals( $hash, hash( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'Posts extraction dependency current source drift: ' . $path );
			}
		}
	}

	public static function restore( string $path, string $source ): string {
		$map = self::load();
		$source = Wstm167SourceTransition::restore( $path, $source );
		self::verify_dependencies();
		if ( ! isset( $map['files'][ $path ] ) ) {
			return $source;
		}
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_sha256'], hash( 'sha256', $source ) )
			|| ! hash_equals( $binding['current_blob'], self::blob( $source ) ) ) {
			throw new RuntimeException( 'Posts extraction current source drift: ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Posts extraction reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$restored = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_sha256'], hash( 'sha256', $restored ) )
			|| ! hash_equals( $binding['baseline_blob'], self::blob( $restored ) ) ) {
			throw new RuntimeException( 'Posts extraction restored source drift: ' . $path );
		}
		return $restored;
	}

	private static function blob( string $source ): string {
		return sha1( 'blob ' . strlen( $source ) . "\0" . $source );
	}
}
