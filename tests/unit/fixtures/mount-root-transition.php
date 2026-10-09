<?php

declare(strict_types=1);

require_once __DIR__ . '/mount-root-net-transition.php';

/** Exact mount-root diagnostic generation before the immutable output proof. */
final class Wstm167MountRootTransition {
	public const SEAL = '3e733b67ba6502ac692fdd8cfe88e2726cd59f0eff3b530510a45a1d02dfc31e';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/mount-root-transition.json' );
		if ( ! is_string( $json ) || ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Mount root transition seal mismatch.' );
		}
		// Raw bytes are checked even after the decoded map has been cached.
		static $map = null;
		if ( null === $map ) {
			$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		if ( 1 !== $map['schema'] || '29f47d7272bb1a71b10cdff11be02aa9cc21a7f0' !== $map['base_commit']
			|| '10b6acdbd16cf00b9d099bb50d491e424b8a57563c08ea928e2d5d4642be83f3' !== $map['predecessor_seal']
			|| 'tests/unit/fixtures/mount-root-transition.php' !== $map['helper_path']
			|| 'b8931b79576b108d45f7c81f4b9f0f41348b4d348263281e58acf94078a4cf94' !== $map['base_inventory_sha256']
			|| 'a9acf478e45f4e0a6cc70e3a9fece93a12618b0843f9b67b2c45fde71cb107c9' !== $map['diagnostic_inventory_sha256'] ) {
			throw new RuntimeException( 'Mount root predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		Wstm167MountRootNetTransition::verify_dependencies( $read );
		$current_read = $read;
		$read = static function ( string $path ) use ( $current_read ) {
			$source = $current_read( $path );
			return is_string( $source ) ? Wstm167MountRootNetTransition::restore( $path, $source ) : $source;
		};
		$map = self::load();
		$expected_helper = preg_replace( "/^\tpublic const SEAL = 'MOUNT_ROOT_SEAL_PENDING';(?=\\r?$)/m", "\tpublic const SEAL = '" . self::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
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
			$source = is_string( $current ) ? Wstm167MountRootNetTransition::restore( $path, $current ) : $current;
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
		$source = Wstm167MountRootNetTransition::restore( $path, $source, $context );
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) { return $source; }
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( $context . ': ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Mount root reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Mount root restored source drift: ' . $path );
		}
		return $before;
	}
}
