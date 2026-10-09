<?php

declare(strict_types=1);

require_once __DIR__ . '/nsfs-net-metadata-transition.php';

/** Exact joint mount-root diagnostic generation before the immutable mount-root proof. */
final class Wstm167MountRootNetTransition {
	public const SEAL = 'e41c9642134e2c2c9360979a9a41b026749da87fbb3e9ea86ef5bebe180b7568';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/mount-root-net-transition.json' );
		if ( ! is_string( $json ) || ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Mount root net transition seal mismatch.' );
		}
		// Raw bytes are checked even after the decoded map has been cached.
		static $map = null;
		if ( null === $map ) {
			$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		if ( 1 !== $map['schema'] || '188c48357d7bb9fd49ec8efc3387524df80b3839' !== $map['base_commit']
			|| '3e733b67ba6502ac692fdd8cfe88e2726cd59f0eff3b530510a45a1d02dfc31e' !== $map['predecessor_seal']
			|| 'tests/unit/fixtures/mount-root-net-transition.php' !== $map['helper_path']
			|| '38abba8f6df2c3451d4b6f9412e346e188e452d4a3f858ba6f8a9280bf9ed9ea' !== $map['base_inventory_sha256']
			|| '5b0881d2562d8460c814923a9dfb49c9591d19b5b0b77f473f6d8b1a0497665d' !== $map['diagnostic_inventory_sha256'] ) {
			throw new RuntimeException( 'Mount root net predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		Wstm167NsfsNetMetadataTransition::verify_dependencies( $read );
		$current_read = $read;
		$read = static function ( string $path ) use ( $current_read ) {
			$source = $current_read( $path );
			return is_string( $source ) ? Wstm167NsfsNetMetadataTransition::restore( $path, $source ) : $source;
		};
		$map = self::load();
		$expected_helper = preg_replace( "/^\tpublic const SEAL = 'MOUNT_ROOT_NET_SEAL_PENDING';(?=\\r?$)/m", "\tpublic const SEAL = '" . self::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
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
			$source = is_string( $current ) ? Wstm167NsfsNetMetadataTransition::restore( $path, $current ) : $current;
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
		$source = Wstm167NsfsNetMetadataTransition::restore( $path, $source, $context );
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( $context . ': ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Mount root net reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Mount root net restored source drift: ' . $path );
		}
		return $before;
	}
}
