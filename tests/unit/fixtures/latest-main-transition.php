<?php

declare(strict_types=1);

require_once __DIR__ . '/terminal-callsite-transition.php';

/** Exact latest-main integration before the immutable kernel-mount proof. */
final class Wstm167LatestMainTransition {
	public const SEAL = '88cb8434798ed0030deba5d3ee14ded9930f38800734f1f56b2e0b75e592ff09';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/latest-main-transition.json' );
		if ( ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Latest main transition seal mismatch.' );
		}
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== $map['schema'] || '545e7599f6f7ad081f08d9abdedd6c8a31f76d6c' !== $map['base_main_commit']
			|| 'c135420c10615616a49e27eb703f955f1ea1f228' !== $map['main_commit']
			|| '9d1eb1447c982866ab77b2dc0c1fd5d24926f931' !== $map['published_commit']
			|| 'f72da33a676b56bf88c158bbe0270c9919231be829cd8826951b96f118a98172' !== $map['predecessor_seal'] ) {
			throw new RuntimeException( 'Latest main predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		Wstm167TerminalCallsiteTransition::verify_dependencies( $read );
		$map = self::load();
		foreach ( $map['dependencies'] as $path => $hash ) {
			$source = $read( $path );
			if ( is_string( $source ) ) {
				$source = Wstm167TerminalCallsiteTransition::restore( $path, $source );
			}
			if ( ! is_string( $source ) || ! hash_equals( $hash, hash( 'sha256', $source ) ) ) {
				$context = is_string( $source ) && in_array( $path, $map['source_dependencies'], true )
					? 'CI diagnostic current source drift: ' : 'CI diagnostic dependency drift: ';
				$context = $map['dependency_contexts'][ $path ] ?? $context;
				throw new RuntimeException( $context . $path );
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
		$source = Wstm167TerminalCallsiteTransition::restore( $path, $source, $context );
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( $context . ': ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Latest main reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Latest main restored source drift: ' . $path );
		}
		return $before;
	}
}
