<?php

declare(strict_types=1);

/** Filesystem guards shared by the owned stage and its private resource journal. */
final class Wstm108_Files {
	private static function require( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'WSTM108 ' . $message );
		}
	}

	private static function normalized( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	public static function directory( string $path ): array {
		clearstatcache();
		$resolved = realpath( $path );
		self::require( false !== $resolved && self::normalized( $resolved ) === self::normalized( $path ), 'Directory must already exist at its exact resolved path.' );
		for ( $ancestor = $path; ; $ancestor = dirname( $ancestor ) ) {
			self::require( is_dir( $ancestor ) && ! is_link( $ancestor ), 'Symlinked or missing directory ancestry.' );
			if ( dirname( $ancestor ) === $ancestor ) {
				break;
			}
		}
		return self::identity( $path );
	}

	private static function identity( string $path ): array {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		self::require( is_array( $stat ) && ! is_link( $path ), 'Missing or symlinked ownership path.' );
		return array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) );
	}

	public static function file( string $path ): array {
		self::directory( dirname( $path ) );
		$identity = self::identity( $path );
		self::require( 0100000 === ( $identity['mode'] & 0170000 ) && 1 === $identity['nlink'], 'Ownership path must be a single-link regular file.' );
		$bytes = @file_get_contents( $path );
		self::require( is_string( $bytes ) && $identity === self::identity( $path ), 'File changed while taking its identity.' );
		return array( 'identity' => $identity, 'sha256' => hash( 'sha256', $bytes ), 'bytes' => $bytes );
	}

	public static function read_bound( string $path, array $expected_identity ): array {
		self::directory( dirname( $path ) );
		self::require( self::identity( $path ) === $expected_identity
			&& 0100000 === ( $expected_identity['mode'] & 0170000 ) && 1 === $expected_identity['nlink'],
			'Authoritative journal identity changed before opening; retaining evidence.' );
		$handle = @fopen( $path, 'rb' );
		self::require( false !== $handle, 'Cannot open the independently bound journal.' );
		try {
			self::require( flock( $handle, LOCK_SH | LOCK_NB ), 'Authoritative journal is being updated.' );
			$stat = fstat( $handle );
			self::require( is_array( $stat ) && $expected_identity === array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) )
				&& self::identity( $path ) === $expected_identity, 'Authoritative journal was replaced before reading.' );
			$bytes = stream_get_contents( $handle );
			$after = fstat( $handle );
			self::require( is_string( $bytes ) && is_array( $after )
				&& $expected_identity === array_intersect_key( $after, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) )
				&& self::identity( $path ) === $expected_identity, 'Authoritative journal changed while reading.' );
			return array( 'identity' => $expected_identity, 'sha256' => hash( 'sha256', $bytes ), 'bytes' => $bytes );
		} finally {
			fclose( $handle );
		}
	}

	public static function create( string $path, string $bytes, bool $public = false ): array {
		$directory = self::directory( dirname( $path ) );
		$old_mask = umask( $public ? 0022 : 0077 );
		try {
			$handle = @fopen( $path, 'x+b' );
		} finally {
			umask( $old_mask );
		}
		self::require( false !== $handle, 'Exclusive ownership collision; preexisting bytes retained.' );
		try {
			$stat = fstat( $handle );
			self::require( is_array( $stat ) && $directory === self::directory( dirname( $path ) ), 'Parent changed during exclusive creation.' );
			$identity = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) );
			self::require( $identity === self::identity( $path ), 'Created file was replaced before writing.' );
			self::write( $handle, $bytes );
			$file = self::file( $path );
			self::require( $file['identity'] === $identity && $file['bytes'] === $bytes, 'Created file identity or persisted bytes changed.' );
			return $file;
		} finally {
			fclose( $handle );
		}
	}

	public static function assert_file( string $path, array $expected ): void {
		self::require( self::read_bound( $path, $expected['identity'] ) === $expected, 'Owned file bytes, mode, owner or identity changed; retaining evidence.' );
	}

	public static function update( string $path, array $expected, string $bytes ): array {
		self::assert_file( $path, $expected );
		$handle = @fopen( $path, 'r+b' );
		self::require( false !== $handle, 'Cannot open the owned private journal.' );
		try {
			self::require( flock( $handle, LOCK_EX | LOCK_NB ), 'Private journal is in use by another process.' );
			$stat = fstat( $handle );
			self::require( is_array( $stat ) && $expected['identity'] === array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ), 'Journal was replaced before opening.' );
			self::require( $expected['identity'] === self::identity( $path ) && $expected['bytes'] === stream_get_contents( $handle ), 'Journal changed before the locked write.' );
			$before_write = fstat( $handle );
			self::require( is_array( $before_write ) && $expected['identity'] === array_intersect_key( $before_write, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) )
				&& $expected['identity'] === self::identity( $path ), 'Journal ownership changed immediately before persistence.' );
			self::require( rewind( $handle ) && ftruncate( $handle, 0 ), 'Cannot reset the owned journal handle.' );
			self::write( $handle, $bytes );
			self::require( rewind( $handle ) && $bytes === stream_get_contents( $handle ), 'Journal bytes did not persist through the owned handle.' );
			$after_write = fstat( $handle );
			self::require( is_array( $after_write ) && $expected['identity'] === array_intersect_key( $after_write, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) )
				&& $expected['identity'] === self::identity( $path ), 'Journal path changed during persistence; retaining evidence.' );
			$actual = array( 'identity' => $expected['identity'], 'sha256' => hash( 'sha256', $bytes ), 'bytes' => $bytes );
		} finally {
			fclose( $handle );
		}
		self::assert_file( $path, $actual );
		return $actual;
	}

	public static function remove( string $path, array $expected ): void {
		self::assert_file( $path, $expected );
		self::require( unlink( $path ), 'Cannot remove the exact owned file.' );
		clearstatcache( true, $path );
		self::require( false === @lstat( $path ), 'Owned path reappeared; refusing further mutation.' );
	}

	private static function write( $handle, string $bytes ): void {
		$offset = 0;
		while ( $offset < strlen( $bytes ) ) {
			$count = fwrite( $handle, substr( $bytes, $offset ) );
			self::require( is_int( $count ) && $count > 0, 'Cannot persist owned file bytes.' );
			$offset += $count;
		}
		self::require( fflush( $handle ), 'Cannot flush owned file evidence.' );
	}
}
