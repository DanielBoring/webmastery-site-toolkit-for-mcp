<?php

declare(strict_types=1);

require_once __DIR__ . '/host-prerequisite-transition.php';

/** Exact nsfs/net metadata compatibility generation before the immutable joint diagnostic proof. */
final class Wstm167NsfsNetMetadataTransition {
	public const SEAL = '3876ae534327eacbd04f9d9ccdff51d105c3764a3dc3f6f0a35b7eb2d6fa39b8';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/nsfs-net-metadata-transition.json' );
		if ( ! is_string( $json ) || ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Nsfs net metadata transition seal mismatch.' );
		}
		// Raw bytes are checked even after the decoded map has been cached.
		static $map = null;
		if ( null === $map ) {
			$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		if ( 1 !== $map['schema'] || '0ac8c5fee269cf9061c30f41f3fb2548146b38d8' !== $map['base_commit']
			|| 'e41c9642134e2c2c9360979a9a41b026749da87fbb3e9ea86ef5bebe180b7568' !== $map['predecessor_seal']
			|| 'tests/unit/fixtures/nsfs-net-metadata-transition.php' !== $map['helper_path']
			|| 'f8cf9c01fb9719ad436784bc7b600b55acf6d0dc03866def46a67bd236a92a18' !== $map['base_inventory_sha256']
			|| '20e2c3f70b42bb33df890e220b7ff8c26887791def787f93d62832da4773d091' !== $map['diagnostic_inventory_sha256'] ) {
			throw new RuntimeException( 'Nsfs net metadata predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		Wstm167HostPrerequisiteTransition::verify_dependencies( $read );
		$current_read = $read;
		$read = static function ( string $path ) use ( $current_read ) {
			$source = $current_read( $path );
			return is_string( $source ) ? Wstm167HostPrerequisiteTransition::restore( $path, $source ) : $source;
		};
		$map = self::load();
		$expected_helper = preg_replace( "/^\tpublic const SEAL = 'NSFS_NET_METADATA_SEAL_PENDING';(?=\\r?$)/m", "\tpublic const SEAL = '" . self::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
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
			$source = is_string( $current ) ? Wstm167HostPrerequisiteTransition::restore( $path, $current ) : $current;
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
		$source = Wstm167HostPrerequisiteTransition::restore( $path, $source, $context );
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( $context . ': ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Nsfs net metadata reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Nsfs net metadata restored source drift: ' . $path );
		}
		return $before;
	}
}
