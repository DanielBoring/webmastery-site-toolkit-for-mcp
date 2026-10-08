<?php

declare(strict_types=1);

/** Current signed-package sources before the unchanged public predecessor. */
final class Wstm167SignedPhpTransition {
	public const SEAL = 'ac12c249bf9a31782e44943b3c0d72d48b94582d12e34707386db5a45d1e6848';

	public static function load( ?string $json = null ): array {
		$json = $json ?? file_get_contents( __DIR__ . '/signed-php-transition.json' );
		if ( ! is_string( $json ) || ! hash_equals( self::SEAL, hash( 'sha256', $json ) ) ) {
			throw new RuntimeException( 'Signed PHP transition seal mismatch.' );
		}
		static $map = null;
		if ( null === $map ) {
			$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		if ( 1 !== $map['schema'] || '77a3019d6361b2b9f9f440cc42a0839a76433e92' !== $map['base_commit']
			|| Wstm167OriginFailureTransition::SEAL !== $map['predecessor_seal'] ) {
			throw new RuntimeException( 'Signed PHP predecessor mismatch.' );
		}
		return $map;
	}

	public static function verify_dependencies( callable $read ): void {
		$map = self::load();
		foreach ( $map['current_hashes'] as $path => $hash ) {
			$source = $read( $path );
			if ( ! is_string( $source ) || ! hash_equals( $hash, hash( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'CI diagnostic current source drift: ' . $path );
			}
		}
		$helper = preg_replace( "/^\tpublic const SEAL = 'SIGNED_PHP_SEAL_PENDING';(?=\\r?$)/m", "\tpublic const SEAL = '" . self::SEAL . "';", $map['helper_raw_template'], 1, $replaced );
		if ( 1 !== $replaced || $read( 'tests/unit/fixtures/signed-php-transition.php' ) !== $helper ) {
			throw new RuntimeException( 'CI diagnostic dependency drift: tests/unit/fixtures/signed-php-transition.php' );
		}
		if ( $read( 'tests/unit/fixtures/signed-php-transition.json' ) !== file_get_contents( __DIR__ . '/signed-php-transition.json' ) ) {
			throw new RuntimeException( 'CI diagnostic dependency drift: tests/unit/fixtures/signed-php-transition.json' );
		}
	}

	public static function restore( string $path, string $source ): string {
		$map = self::load();
		if ( ! isset( $map['files'][ $path ] ) ) {
			return $source;
		}
		$binding = $map['files'][ $path ];
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( 'CI diagnostic current source drift: ' . $path );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Signed PHP reverse hunk mismatch: ' . $path );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Signed PHP restored source drift: ' . $path );
		}
		return $before;
	}
}
