<?php

declare(strict_types=1);

/** Exact kernel-mount generation before the immutable scoped-observation proof. */
final class Wstm167KernelMountTransition {
	public const SEAL = 'f72da33a676b56bf88c158bbe0270c9919231be829cd8826951b96f118a98172';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/kernel-mount-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Kernel mount transition seal mismatch.' );
		}
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== $map['schema'] || '9d1eb1447c982866ab77b2dc0c1fd5d24926f931' !== $map['base_commit']
			|| 'd9c69cd85ca6132b2999357c397cb70cb91ecb91f4f856016809bdcd1a18bd13' !== $map['predecessor_seal'] ) {
			throw new RuntimeException( 'Kernel mount predecessor mismatch.' );
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
				throw new RuntimeException( 'Kernel mount reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Kernel mount restored source drift: ' . $path );
		}
		return $before;
	}
}
