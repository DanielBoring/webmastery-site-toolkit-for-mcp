<?php

declare(strict_types=1);

require_once __DIR__ . '/terminal-output-transition.php';

/** Exact diagnostic generation before the immutable latest-main proof. */
final class Wstm167TerminalCallsiteTransition {
	public const SEAL = '158babfc2dc340aa6aa18a48f6523f55cc56e195a95f2b94764eecf61d43b06a';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/terminal-callsite-transition.json' );
		if ( ! is_string( $json ) || ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Terminal callsite transition seal mismatch.' );
		}
		// Every call checks current raw bytes before reusing the decoded immutable map.
		static $map = null;
		if ( null === $map ) {
			$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		if ( 1 !== $map['schema'] || '2dc4d85a15c7cacda9e60ef44a14e4c37d19d08e' !== $map['base_commit']
			|| '88cb8434798ed0030deba5d3ee14ded9930f38800734f1f56b2e0b75e592ff09' !== $map['predecessor_seal']
			|| 'tests/unit/fixtures/terminal-callsite-transition.php' !== $map['helper_path']
			|| 'fd754612732dac07b465ae321ca06d7f9f1f40dd7624f7651837eec06547f6aa' !== $map['diagnostic_inventory_sha256'] ) {
			throw new RuntimeException( 'Terminal callsite predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		Wstm167TerminalOutputTransition::verify_dependencies( $read );
		$current_read = $read;
		$read = static function ( string $path ) use ( $current_read ) {
			$source = $current_read( $path );
			return is_string( $source ) ? Wstm167TerminalOutputTransition::restore( $path, $source ) : $source;
		};
		$map = self::load();
		$expected_helper = preg_replace( "/^\tpublic const SEAL = '[^']+';/m", "\tpublic const SEAL = '" . self::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
		$helper = $read( $map['helper_path'] );
		if ( 1 !== $replaced || ! is_string( $helper ) || ! hash_equals( hash( 'sha256', $expected_helper ), hash( 'sha256', $helper ) ) ) {
			throw new RuntimeException( 'CI diagnostic dependency drift: ' . $map['helper_path'] );
		}
		foreach ( $map['dependencies'] as $path => $hash ) {
			$source = $read( $path );
			if ( ! is_string( $source ) || ! hash_equals( $hash, hash( 'sha256', $source ) ) ) {
				self::dependency_drift( $path, $source, $map );
			}
		}
		foreach ( $map['files'] as $path => $binding ) {
			$current = $current_read( $path );
			$source = $current;
			if ( is_string( $source ) ) {
				$source = Wstm167TerminalOutputTransition::restore( $path, $source );
			}
			if ( ! is_string( $source ) || ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
				self::dependency_drift( $path, $source, $map );
			}
			self::restore( $path, $current );
		}
	}

	private static function dependency_drift( string $path, $source, array $map ): void {
		$context = is_string( $source ) && in_array( $path, $map['source_dependencies'], true )
			? 'CI diagnostic current source drift: ' : 'CI diagnostic dependency drift: ';
		$context = $map['dependency_contexts'][ $path ] ?? $context;
		throw new RuntimeException( $context . $path );
	}

	public static function restore( string $path, string $source, string $context = 'CI diagnostic current source drift' ): string {
		$source = Wstm167TerminalOutputTransition::restore( $path, $source, $context );
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( $context . ': ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Terminal callsite reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Terminal callsite restored source drift: ' . $path );
		}
		return $before;
	}
}
