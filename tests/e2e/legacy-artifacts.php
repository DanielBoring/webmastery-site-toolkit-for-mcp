<?php

declare(strict_types=1);

final class Wstm_E2E_Artifacts {
	public static function directory( string $fallback ): string {
		$directory = getenv( 'WSTM_E2E_ARTIFACTS_DIR' );
		return false === $directory || '' === $directory ? $fallback : $directory;
	}

	public static function path( string $fallback ): string {
		$directory = getenv( 'WSTM_E2E_ARTIFACTS_DIR' );
		if ( false === $directory || '' === $directory ) {
			return $fallback;
		}
		return rtrim( $directory, '/\\' ) . '/' . basename( $fallback );
	}
}
