<?php

declare(strict_types=1);

require_once __DIR__ . '/selected-listener-transition.php';

/** Exact outer correction before the accepted diagnostic projection. */
final class Wstm167CiBudgetControlTransition {
	public const SEAL = '6126a718e4a19904fa4ee64c2b6cc159ee0cb82239a391dbb0ac73c1a10e0591';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/ci-budget-control-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'CI budget/control transition seal mismatch.' );
		}
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== $map['schema'] || Wstm167CiDiagnosticTransition::SEAL !== $map['predecessor_seal'] ) {
			throw new RuntimeException( 'CI budget/control predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		Wstm167SelectedListenerTransition::verify_dependencies( $read );
		$map = self::load();
		foreach ( $map['dependencies'] as $path => $hash ) {
			$source = $read( $path );
			if ( is_string( $source ) ) { $source = Wstm167SelectedListenerTransition::restore( $path, $source ); }
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

	public static function restore( string $path, string $source ): string {
		$source = Wstm167SelectedListenerTransition::restore( $path, $source,
			'tests/unit/fixtures/bounded-admission-transition.php' === $path ? 'Score marker current source drift' : 'CI diagnostic current source drift' );
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( 'CI diagnostic current source drift: ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'CI budget/control reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'CI budget/control restored source drift: ' . $path );
		}
		return $before;
	}
}
