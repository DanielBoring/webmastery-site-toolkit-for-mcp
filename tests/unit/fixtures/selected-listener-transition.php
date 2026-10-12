<?php

declare(strict_types=1);

require_once __DIR__ . '/current-main-transition.php';

/** Exact outer correction before the accepted budget/control projection. */
final class Wstm167SelectedListenerTransition {
	public const SEAL = 'd6bbca72dcd3f823bffee69b3cd03e4ff51232cc324d919b05191103d11c53ee';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/selected-listener-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Selected listener transition seal mismatch.' );
		}
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== $map['schema'] || 'b5c087e4d4213264aba693c4eef9dee22c11ceac' !== $map['base_commit']
			|| '6126a718e4a19904fa4ee64c2b6cc159ee0cb82239a391dbb0ac73c1a10e0591' !== $map['predecessor_seal'] ) {
			throw new RuntimeException( 'Selected listener predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		Wstm167CurrentMainTransition::verify_dependencies( $read );
		$map = self::load();
		foreach ( $map['dependencies'] as $path => $hash ) {
			$source = $read( $path );
			if ( is_string( $source ) ) { $source = Wstm167CurrentMainTransition::restore( $path, $source ); }
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
		$source = Wstm167CurrentMainTransition::restore( $path, $source, $context );
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( $context . ': ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Selected listener reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Selected listener restored source drift: ' . $path );
		}
		return $before;
	}
}
