<?php

declare(strict_types=1);

/**
 * Owns one disposable upload directory, never WordPress's existing upload tree.
 *
 * The caller attests the HTTP UID before construction, uses seed() only for
 * fixture creation, and deletes only its independently tracked files.
 * finalize() only removes the exact ownership marker and an empty directory.
 */
final class Wstm116_Uploads {
	private string $root;
	private string $base_url;
	private string $directory;
	private string $subdir;
	private string $marker;
	private string $proof;
	private int $uid;
	private array $root_identity;
	private ?array $identity = null;
	private ?array $marker_identity = null;
	private bool $created = false;
	private bool $ready = false;
	private bool $seeding = false;
	private $read_owner;
	private $change_owner;

	/**
	 * Optional ownership operations are test seams; production uses fileowner/chown.
	 * $owner must be a caller-generated, unpredictable 128-bit lowercase hex token.
	 */
	public function __construct( string $root, string $base_url, string $owner, $uid, ?callable $read_owner = null, ?callable $change_owner = null ) {
		$this->require( 1 === preg_match( '/^[a-f0-9]{32}$/D', $owner ), 'Invalid upload ownership token.' );
		$this->require( is_int( $uid ) && $uid >= 0, 'HTTP upload UID must be an attested non-negative integer.' );
		$this->require( '' !== $base_url, 'Missing original upload base URL.' );
		$this->root = $root;
		$this->base_url = $base_url;
		$this->uid = $uid;
		$this->read_owner = $read_owner ?? static function ( string $path ) { return @fileowner( $path ); };
		$this->change_owner = $change_owner ?? static function ( string $path, int $owner_uid ): bool { return @chown( $path, $owner_uid ); };
		$this->assert_real_root();
		$this->root_identity = $this->fingerprint( $this->root );
		$this->subdir = '/wstm116-' . $owner;
		$this->directory = rtrim( $root, '/\\' ) . $this->subdir;
		$this->marker = $this->directory . '/.wstm116-owner';
		// A separate proof also distinguishes replaced directories on zero-inode filesystems.
		$this->proof = $owner . ':' . bin2hex( random_bytes( 32 ) ) . "\n";
	}

	private function require( bool $ok, string $message ): void {
		if ( ! $ok ) {
			throw new RuntimeException( $message );
		}
	}

	private function normalized( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	private function assert_real_root(): void {
		clearstatcache();
		$resolved = realpath( $this->root );
		$this->require( false !== $resolved && $this->normalized( $resolved ) === $this->normalized( $this->root ), 'Upload basedir must be an existing resolved real path.' );
		$path = $resolved;
		while ( true ) {
			$this->require( ! is_link( $path ) && is_dir( $path ), 'Symlink or non-directory in upload root ancestry: ' . $path );
			$parent = dirname( $path );
			if ( $parent === $path ) {
				break;
			}
			$path = $parent;
		}
	}

	private function fingerprint( string $path, bool $owned = false ): array {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		$this->require( false !== $stat && ! is_link( $path ), 'Missing or symlinked upload ownership path: ' . $path );
		$result = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode' ) ) );
		if ( $owned ) {
			$uid = ( $this->read_owner )( $path );
			$this->require( is_int( $uid ) && $uid >= 0, 'Cannot determine owned upload directory UID.' );
			$result['uid'] = $uid;
		}
		return $result;
	}

	private function assert_root(): void {
		$this->assert_real_root();
		$this->require( $this->root_identity === $this->fingerprint( $this->root ), 'Original upload basedir identity changed; evidence retained.' );
	}

	public function acquire(): void {
		$this->require( ! $this->created && null === $this->identity, 'Upload directory was already acquired.' );
		$this->assert_root();
		clearstatcache();
		$this->require( false === @lstat( $this->directory ), 'Upload directory collision; no ownership claimed: ' . $this->directory );
		$this->require( @mkdir( $this->directory, 0755, false ), 'Cannot exclusively create upload directory; no ownership claimed.' );
		// Record successful mkdir before any subsequent operation can fail.
		$this->created = true;
		$this->identity = $this->fingerprint( $this->directory, true );
		$this->require( 0040000 === ( $this->identity['mode'] & 0170000 ), 'Created upload path is not a real directory; evidence retained.' );
		$this->require( 0700 === ( $this->identity['mode'] & 0700 ), 'Created upload directory lacks owner access; evidence retained.' );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this->require( 0 === ( $this->identity['mode'] & 0022 ), 'Created upload directory permits foreign writes; evidence retained.' );
		}
		$this->assert_root();
		$this->require( $this->identity === $this->fingerprint( $this->directory, true ), 'New upload directory changed before marker creation; evidence retained.' );
		$handle = @fopen( $this->marker, 'x' );
		$this->require( false !== $handle, 'Cannot exclusively create upload ownership marker; evidence retained.' );
		try {
			$this->require( strlen( $this->proof ) === @fwrite( $handle, $this->proof ) && @fflush( $handle ), 'Cannot persist upload ownership marker; evidence retained.' );
		} finally {
			fclose( $handle );
		}
		$this->marker_identity = $this->fingerprint( $this->marker );
		$this->assert_owned();
		if ( $this->uid !== $this->identity['uid'] ) {
			$this->require( true === ( $this->change_owner )( $this->directory, $this->uid ), 'Cannot assign newly created upload directory to HTTP UID; evidence retained.' );
			$expected = $this->identity;
			$expected['uid'] = $this->uid;
			$this->require( $expected === $this->fingerprint( $this->directory, true ), 'Upload directory ownership verification failed; evidence retained.' );
			$this->identity = $expected;
		}
		$this->assert_owned();
		$this->ready = true;
	}

	/**
	 * Call before the runner's known-file cleanup; this does not authorize any file.
	 * A failed chown still leaves the original directory identity available here.
	 */
	public function assert_owned(): void {
		$this->require( $this->created && null !== $this->identity && null !== $this->marker_identity, 'No complete upload ownership proof; refusing mutation.' );
		$this->assert_root();
		$this->require( $this->identity === $this->fingerprint( $this->directory, true ), 'Upload directory identity or ownership changed; evidence retained.' );
		$this->require( $this->marker_identity === $this->fingerprint( $this->marker ) && is_file( $this->marker ), 'Upload ownership marker identity changed; evidence retained.' );
		$contents = @file_get_contents( $this->marker );
		$this->require( is_string( $contents ) && hash_equals( $this->proof, $contents ), 'Upload ownership marker changed; evidence retained.' );
	}

	public function filter( array $uploads ): array {
		$this->require( $this->ready, 'Upload directory is not ready for seeding.' );
		$this->assert_owned();
		$this->require( ( $uploads['basedir'] ?? null ) === $this->root && ( $uploads['baseurl'] ?? null ) === $this->base_url, 'Foreign upload basedir or baseurl; refusing seeding.' );
		$this->require( array_key_exists( 'error', $uploads ) && false === $uploads['error'], 'Original upload configuration has an error; refusing seeding.' );
		$uploads['path'] = $this->directory;
		$uploads['url'] = rtrim( $this->base_url, '/' ) . $this->subdir;
		$uploads['subdir'] = $this->subdir;
		return $uploads;
	}

	/**
	 * Pass WordPress's add_filter/remove_filter callables, using default priority.
	 * Actor switches and ability calls belong after this method, never inside $seed.
	 * The callback must not already be registered outside this exclusive scope.
	 *
	 * @return mixed The caller's seed result, after removing the exact callback.
	 */
	public function seed( callable $seed, callable $add_filter, callable $remove_filter ) {
		$this->require( $this->ready, 'Upload directory is not ready for seeding.' );
		$this->require( ! $this->seeding, 'Nested upload seeding is not permitted.' );
		$this->assert_owned();
		$callback = array( $this, 'filter' );
		$this->seeding = true;
		try {
			$this->require( true === $add_filter( 'upload_dir', $callback ), 'Cannot register scoped upload filter.' );
			return $seed();
		} finally {
			$removed = false;
			try {
				$removed = true === $remove_filter( 'upload_dir', $callback );
				$this->require( $removed, 'Cannot remove scoped upload filter; abort before actor or ability calls.' );
			} finally {
				if ( $removed ) {
					$this->seeding = false;
				} else {
					$this->ready = false;
				}
			}
		}
	}

	/** Returns the selected path, not a claim that acquisition succeeded. */
	public function directory(): string {
		return $this->directory;
	}

	/**
	 * Scope guard only: $path must come from the runner's independent file ledger.
	 * Check every attachment/metadata file reference before deleting its post, not
	 * merely before unlinking the main upload. No WordPress metadata is read here.
	 * Missing files require explicit opt-in for already-deleted fixture uploads.
	 */
	public function validate_known_file( string $path, bool $allow_missing = false ): void {
		$this->assert_owned();
		$prefix = $this->normalized( $this->directory ) . '/';
		$normalized = str_replace( '\\', '/', $path );
		$this->require( 0 === strpos( $normalized, $prefix ), 'Known upload file is outside the owned directory.' );
		$name = substr( $normalized, strlen( $prefix ) );
		// Flat portable filenames exclude traversal, streams, markers, and aliases.
		$this->require( 1 === preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $name ) && '.' !== substr( $name, -1 ), 'Known upload file is not a direct portable fixture filename.' );
		$this->require( 0 === preg_match( '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $name ), 'Known upload file is a reserved device name.' );
		$parent = realpath( dirname( $path ) );
		$this->require( false !== $parent && $this->normalized( $parent ) === $this->normalized( $this->directory ), 'Known upload file parent is not the exact owned directory.' );
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( false === $stat ) {
			$entries = @scandir( $this->directory );
			$this->require( $allow_missing && is_array( $entries ) && ! in_array( $name, $entries, true ), 'Known upload file is missing or cannot be inspected.' );
			return;
		}
		$this->require( ! is_link( $path ) && 0100000 === ( $stat['mode'] & 0170000 ) && 1 === $stat['nlink'], 'Known upload file is not a single-link regular file; evidence retained.' );
		$resolved = realpath( $path );
		$this->require( false !== $resolved && $this->normalized( $resolved ) === $normalized, 'Known upload file does not resolve to its exact owned path.' );
	}

	public function finalize(): void {
		$this->require( ! $this->seeding, 'Cannot finalize uploads while the seeding filter is registered.' );
		$this->assert_owned();
		$entries = @scandir( $this->directory );
		$this->require( is_array( $entries ) && array( '.', '..', '.wstm116-owner' ) === $entries, 'Upload directory is not empty apart from its marker; refusing recursive cleanup and retaining evidence.' );
		$this->assert_owned();
		$this->require( @unlink( $this->marker ), 'Cannot remove exact upload ownership marker; evidence retained.' );
		$this->require( @rmdir( $this->directory ), 'Cannot remove empty owned upload directory; directory retained for inspection.' );
		$this->created = false;
		$this->ready = false;
		clearstatcache();
		$this->require( false === @lstat( $this->directory ), 'Upload path reappeared after cleanup; refusing further mutation.' );
	}
}
