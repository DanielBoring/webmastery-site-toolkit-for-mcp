<?php

declare(strict_types=1);

final class Wstm166BoundedAdmissionTransition {
	public const SEAL = 'a45c96be6790c0380788529adc509955ffc9de64b472a5fae24bb46a9e13df5f';
	public const PREDECESSOR = '204877c9ff0325b4a6a90f040de918759d9ec45ed22b75a29a075de3451c2d78';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/bounded-admission-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) { throw new RuntimeException( 'Bounded admission transition seal mismatch.' ); }
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== $map['schema'] || self::PREDECESSOR !== $map['predecessor_seal'] ) { throw new RuntimeException( 'Bounded admission predecessor mismatch.' ); }
		return $map;
	}

	public static function reader( callable $read ): callable {
		return static fn( $path ) => self::restore( $path, $read( $path ) );
	}

	public static function verify_dependencies( callable $read ): void {
		$map = self::load();
		foreach ( $map['dependencies'] as $path => $hash ) {
			if ( ! hash_equals( $hash, hash( 'sha256', $read( $path ) ) ) ) { throw new RuntimeException( 'Floor selector dependency drift: ' . $path ); }
		}
		foreach ( $map['files'] as $path => $binding ) { self::restore( $path, $read( $path ) ); }
	}

	public static function restore( string $path, string $source ): string {
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) { throw new RuntimeException( 'Bounded admission current source drift: ' . $path ); }
		$lines = explode( "\n", str_replace( "\r\n", "\n", $source ) );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) { throw new RuntimeException( 'Bounded admission reverse hunk mismatch: ' . $path ); }
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_sha256'], hash( 'sha256', $before ) ) ) { throw new RuntimeException( 'Bounded admission restored source drift: ' . $path ); }
		return $before;
	}
}
