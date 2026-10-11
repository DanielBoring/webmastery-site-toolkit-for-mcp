<?php

declare(strict_types=1);

final class Wstm_Product_Stage_Artifacts {
	public static function path( string $stage, string $token ): string {
		if ( ! in_array( $stage, array( 'destructive', 'input-schema' ), true ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			throw new RuntimeException( 'Invalid private stage artifact identity.' );
		}
		return '/qa-artifacts/' . $stage . '-' . $token;
	}

	public static function directory( string $directory, string $stage, string $token, ?callable $inspect = null ): void {
		if ( self::path( $stage, $token ) !== $directory ) {
			throw new RuntimeException( 'Artifact directory must match the private stage token.' );
		}
		$inspect = $inspect ?? static function ( string $path ) {
			clearstatcache( true, $path );
			return @lstat( $path );
		};
		foreach ( array( '/qa-artifacts', $directory ) as $path ) {
			$stat = $inspect( $path );
			if ( ! is_array( $stat ) || ! isset( $stat['mode'], $stat['uid'], $stat['gid'] ) ||
				! is_int( $stat['mode'] ) || ! is_int( $stat['uid'] ) || ! is_int( $stat['gid'] ) ||
				0040000 !== ( $stat['mode'] & 0170000 ) || 0700 !== ( $stat['mode'] & 07777 ) ||
				33 !== $stat['uid'] || 33 !== $stat['gid'] ) {
				throw new RuntimeException( 'Missing, unreadable or unsafe private stage directory.' );
			}
		}
	}
}
