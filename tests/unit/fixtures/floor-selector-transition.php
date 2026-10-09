<?php

declare(strict_types=1);

final class Wstm167FloorSelectorTransition {
	public const SEAL = '204877c9ff0325b4a6a90f040de918759d9ec45ed22b75a29a075de3451c2d78';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/floor-selector-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) { throw new RuntimeException( 'Floor selector transition seal mismatch.' ); }
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== $map['schema'] || array(
			'style' => 'b8d2ad18c87ee97c8eeb86f8601b6d1fce5af16842b7a4c4de9e886138ba53e0',
			'167' => 'e847ac561c95854f66b5b04af627e992fa8886dd76eab018b730b07f42d290fa',
			'127' => '2be14f26642f28014a9df9e99df36cb6697520b158938f1b3bad23e9da44c4f5',
			'119' => '1bdd69f8b930f9c744afe557800eeafd195f30eeaa9b61c9b81fff5267e426d6',
		) !== $map['predecessor_seals'] ) { throw new RuntimeException( 'Floor selector predecessor mismatch.' ); }
		return $map;
	}

	public static function verify_dependencies( ?callable $read = null ): void {
		$read = $read ?? static fn( $path ) => file_get_contents( dirname( __DIR__, 3 ) . '/' . $path );
		$map = self::load();
		foreach ( $map['dependencies'] as $path => $binding ) {
			$source = $read( $path );
			if ( ! hash_equals( $binding['raw_sha256'], hash( 'sha256', $source ) )
				|| ! hash_equals( $binding['sha256'], hash( 'sha256', str_replace( "\r\n", "\n", $source ) ) ) ) {
				throw new RuntimeException( 'Floor selector dependency drift: ' . $path );
			}
		}
		foreach ( $map['files'] as $path => $binding ) {
			$source = $read( $path );
			if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'Floor selector raw consumer drift: ' . $path );
			}
			self::restore( $path, $source );
		}
	}

	public static function restore( string $path, string $source ): string {
		$map = self::load();
		$source = str_replace( "\r\n", "\n", $source );
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$b = $map['files'][ $path ];
		if ( ! hash_equals( $b['current_sha256'], hash( 'sha256', $source ) ) || ! hash_equals( $b['current_blob'], self::blob( $source ) ) ) {
			throw new RuntimeException( 'Floor selector current source drift: ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $b['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Floor selector reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $b['baseline_sha256'], hash( 'sha256', $before ) ) || ! hash_equals( $b['baseline_blob'], self::blob( $before ) ) ) {
			throw new RuntimeException( 'Floor selector restored source drift: ' . $path );
		}
		return $before;
	}

	private static function blob( string $source ): string { return sha1( 'blob ' . strlen( $source ) . "\0" . $source ); }
}
