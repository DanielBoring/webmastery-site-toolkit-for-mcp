<?php

declare(strict_types=1);

/** Exact diagnostic composition before the immutable published prerequisite proof. */
final class Wstm167OriginFailureTransition {
	public const SEAL = '2cdc24a4ecceeb530d4302665529bb5b66700068245a4cca76b011bafb336e36';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/origin-failure-transition.json' );
		if ( ! is_string( $json ) || ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Origin failure transition seal mismatch.' );
		}
		// Raw bytes are checked even after the decoded map has been cached.
		static $map = null;
		if ( null === $map ) {
			$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		if ( 1 !== $map['schema'] || 'db5a66e78ee65a0e1382609d562802fd18baabfc' !== $map['base_commit']
			|| 'f9b67aeec14035aa364dd9b49f8edfe1d32337d80b40a7f07e1a202d1ffe18a4' !== $map['predecessor_seal']
			|| 'tests/unit/fixtures/origin-failure-transition.php' !== $map['helper_path']
			|| '67f41022e900d4a146652729f5533df9a9e37d3da7ff5ab3dd599270c0c12216' !== $map['base_inventory_sha256']
			|| '703bbd13c6d0fc081c07e38c6a9d8a2e085cbf138448bd21a44ff97aaa294b5e' !== $map['approved_inventory_sha256'] ) {
			throw new RuntimeException( 'Origin failure predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		$read_current = $read;
		$read = static function ( $path ) use ( $read_current ) {
			$source = $read_current( $path );
			return is_string( $source ) ? Wstm167SignedPhpTransition::restore( $path, $source ) : $source;
		};
		$map = self::load();
		$expected_helper = preg_replace( "/^\tpublic const SEAL = 'ORIGIN_FAILURE_SEAL_PENDING';(?=\\r?$)/m", "\tpublic const SEAL = '" . self::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
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
			$source = $read( $path );
			if ( ! is_string( $source ) || ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
				self::dependency_drift( $path, $source, $map );
			}
			self::restore( $path, $source );
		}
		// Preserve predecessor dependency classification before the added source binding.
		Wstm167SignedPhpTransition::verify_dependencies( $read_current );
	}

	private static function dependency_drift( string $path, $source, array $map ): void {
		$context = is_string( $source ) && in_array( $path, $map['source_dependencies'], true )
			? 'CI diagnostic current source drift: ' : 'CI diagnostic dependency drift: ';
		$context = $map['dependency_contexts'][ $path ] ?? $context;
		throw new RuntimeException( $context . $path );
	}

	public static function reader_once( string $path, string $source ): string {
		$outer = Wstm167SignedPhpTransition::load();
		if ( isset( $outer['files'][ $path ] )
			&& hash_equals( $outer['files'][ $path ]['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			$source = Wstm167SignedPhpTransition::restore( $path, $source );
		}
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		$hash = hash( 'sha256', $source );
		if ( hash_equals( $binding['current_raw_sha256'], $hash ) ) {
			return self::restore( $path, $source );
		}
		if ( hash_equals( $binding['baseline_raw_sha256'], $hash ) ) { return $source; }
		throw new RuntimeException( 'CI diagnostic current source drift: ' . $path );
	}

	public static function restore( string $path, string $source, string $context = 'CI diagnostic current source drift' ): string {
		$outer = Wstm167SignedPhpTransition::load();
		if ( isset( $outer['files'][ $path ] )
			&& hash_equals( $outer['files'][ $path ]['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			$source = Wstm167SignedPhpTransition::restore( $path, $source );
		}
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( $context . ': ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Origin failure reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Origin failure restored source drift: ' . $path );
		}
		return $before;
	}
}
