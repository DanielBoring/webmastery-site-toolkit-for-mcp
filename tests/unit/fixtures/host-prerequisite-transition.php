<?php

declare(strict_types=1);

require_once __DIR__ . '/origin-failure-transition.php';

/** Exact orthogonal prerequisite composition before the immutable current nsfs/net proof. */
final class Wstm167HostPrerequisiteTransition {
	public const SEAL = 'f9b67aeec14035aa364dd9b49f8edfe1d32337d80b40a7f07e1a202d1ffe18a4';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/host-prerequisite-transition.json' );
		if ( ! is_string( $json ) || ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Host prerequisite transition seal mismatch.' );
		}
		// Raw bytes are checked even after the decoded map has been cached.
		static $map = null;
		if ( null === $map ) {
			$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		if ( 1 !== $map['schema'] || '29f76178ceed3cf49eea02122defe7524eb2b555' !== $map['base_commit']
			|| '3876ae534327eacbd04f9d9ccdff51d105c3764a3dc3f6f0a35b7eb2d6fa39b8' !== $map['predecessor_seal']
			|| 'tests/unit/fixtures/host-prerequisite-transition.php' !== $map['helper_path']
			|| '1bb9e57b543c920a3ba0ac5f67c04ccd6c5149ac121d8423140f51e67be1ac20' !== $map['base_inventory_sha256']
			|| '537e229579c6f20e74966f8fd0d22e9c13b3a6fb3d75ddc4c5a03f1cc7f3ce22' !== $map['approved_inventory_sha256'] ) {
			throw new RuntimeException( 'Host prerequisite predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		Wstm167OriginFailureTransition::verify_dependencies( $read );
		$current_read = $read;
		$read = static function ( string $path ) use ( $current_read ) {
			$source = $current_read( $path );
			return is_string( $source ) ? Wstm167OriginFailureTransition::restore( $path, $source ) : $source;
		};
		$map = self::load();
		$expected_helper = preg_replace( "/^\tpublic const SEAL = 'HOST_PREREQUISITE_SEAL_PENDING';(?=\\r?$)/m", "\tpublic const SEAL = '" . self::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
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
	}

	private static function dependency_drift( string $path, $source, array $map ): void {
		$context = is_string( $source ) && in_array( $path, $map['source_dependencies'], true )
			? 'CI diagnostic current source drift: ' : 'CI diagnostic dependency drift: ';
		$context = $map['dependency_contexts'][ $path ] ?? $context;
		throw new RuntimeException( $context . $path );
	}

	public static function restore( string $path, string $source, string $context = 'CI diagnostic current source drift' ): string {
		$source = Wstm167OriginFailureTransition::reader_once( $path, $source );
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( $context . ': ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Host prerequisite reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Host prerequisite restored source drift: ' . $path );
		}
		return $before;
	}
}
