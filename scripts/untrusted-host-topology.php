<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-files.php';

/** Passive Linux evidence only; inaccessible or ambiguous topology is unsupported. */
final class Wstm108_HostTopology {
	private static function require( bool $condition, string $reason ): void {
		if ( ! $condition ) { throw new RuntimeException( 'WSTM108 BLOCKED topology: ' . $reason, 78 ); }
	}

	private static function path( string $path ): string {
		self::require( '' !== $path && '/' === $path[0] && false === strpos( $path, "\0" )
			&& ! preg_match( '/[\x00-\x1f\x7f]|\/\/|(?:^|\/)\.\.?(?:\/|$)/', $path ), 'noncanonical-path' );
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	private static function contains( string $parent, string $child ): bool {
		return '/' === $parent || $parent === $child || 0 === strpos( $child, $parent . '/' );
	}

	private static function unescape( string $value ): string {
		self::require( ! preg_match( '/\\\\(?!040|011|012|134)/', $value ), 'unknown-mount-escape' );
		return strtr( $value, array( '\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\' ) );
	}

	public static function mounts( string $bytes ): array {
		self::require( '' !== $bytes && strlen( $bytes ) <= 4194304 && "\n" === substr( $bytes, -1 ), 'partial-mount-table' );
		$records = array();
		$points = array();
		$lines = explode( "\n", rtrim( $bytes, "\n" ) );
		self::require( count( $lines ) <= 8192, 'mount-table-limit' );
		foreach ( $lines as $line ) {
			$fields = explode( ' ', $line );
			$separator = array_search( '-', $fields, true );
			self::require( false !== $separator && $separator >= 6 && count( $fields ) === $separator + 4
				&& ctype_digit( $fields[0] ) && ctype_digit( $fields[1] )
				&& 1 === preg_match( '/^[0-9]+:[0-9]+$/D', $fields[2] ), 'malformed-mount-record' );
			$id = (int) $fields[0];
			$root = self::path( self::unescape( $fields[3] ) );
			$point = self::path( self::unescape( $fields[4] ) );
			self::require( $id > 0 && ! isset( $records[ $id ] ) && ! isset( $points[ $point ] ), 'ambiguous-stacked-mount' );
			foreach ( array_slice( $fields, 6, $separator - 6 ) as $optional ) {
				self::require( 'unbindable' === $optional || 1 === preg_match( '/^(shared|master|propagate_from):[0-9]+$/D', $optional ), 'unsupported-mount-mapping' );
			}
			$records[ $id ] = array( 'id' => $id, 'parent' => (int) $fields[1], 'device' => $fields[2],
				'root' => $root, 'point' => $point, 'type' => $fields[ $separator + 1 ] );
			$points[ $point ] = true;
		}
		self::require( isset( $points['/'] ), 'missing-namespace-root' );
		return array_values( $records );
	}

	public static function coordinate( string $path, array $mounts ): array {
		$path = self::path( $path );
		$selected = null;
		foreach ( $mounts as $mount ) {
			if ( self::contains( $mount['point'], $path )
				&& ( null === $selected || strlen( $mount['point'] ) > strlen( $selected['point'] ) ) ) {
				$selected = $mount;
			}
		}
		self::require( null !== $selected && in_array( $selected['type'], array( 'ext4', 'tmpfs' ), true ), 'unsupported-filesystem-coordinate' );
		$relative = substr( $path, '/' === $selected['point'] ? 1 : strlen( $selected['point'] ) );
		$physical = '/' . trim( trim( $selected['root'], '/' ) . '/' . ltrim( $relative, '/' ), '/' );
		return array( 'device' => $selected['device'], 'path' => self::path( $physical ), 'mount_id' => $selected['id'], 'type' => $selected['type'] );
	}

	public static function outside( string $authority, array $sources, array $mounts ): void {
		$owned = self::coordinate( $authority, $mounts );
		foreach ( $sources as $source ) {
			self::require( is_string( $source ), 'nonstring-bind-source' );
			$source = self::path( $source );
			$exposures = array( self::coordinate( $source, $mounts ) );
			foreach ( $mounts as $mount ) {
				if ( self::contains( $source, $mount['point'] ) ) {
					$exposures[] = self::coordinate( $mount['point'], $mounts );
				}
			}

			foreach ( $exposures as $exposure ) {
				self::require( $owned['device'] !== $exposure['device'] || ! self::contains( $exposure['path'], $owned['path'] ), 'physical-bind-exposes-authority' );
			}
		}
	}

	public static function native_coordinates( array $paths, array $mounts ): void {
		self::require( 'Linux' === PHP_OS_FAMILY && PHP_INT_SIZE >= 8, 'native-coordinate-prerequisite' );
		$points = $paths;
		foreach ( $paths as $path ) {
			foreach ( $mounts as $mount ) {
				if ( self::contains( $path, $mount['point'] ) ) { $points[] = $mount['point']; }
			}
		}
		foreach ( array_unique( $points, SORT_STRING ) as $path ) {
			$coordinate = self::coordinate( $path, $mounts );
			self::require( realpath( $path ) === $path, 'coordinate-path-changed' );
			$before = self::stat( $path );
			$device = $before['dev'];
			self::require( is_int( $device ) && $device >= 0, 'unsupported-native-device' );
			$major = ( ( $device >> 8 ) & 0xfff ) | ( ( $device >> 32 ) & 0xfffff000 );
			$minor = ( $device & 0xff ) | ( ( $device >> 12 ) & 0xffffff00 );
			self::require( $coordinate['device'] === $major . ':' . $minor && $before === self::stat( $path )
				&& realpath( $path ) === $path, 'kernel-coordinate-or-identity-disagrees' );
		}
	}

	public static function stable_identity( array $identity ): array {
		$keys = array_keys( $identity );
		sort( $keys, SORT_STRING );
		self::require( $keys === array( 'dev', 'gid', 'ino', 'mode', 'nlink', 'uid' )
			&& count( $identity ) === count( array_filter( $identity, 'is_int' ) ), 'incomplete-directory-identity' );
		return array_intersect_key( $identity, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode' ) ) );
	}

	public static function owned_child_transition( array $before, array $after, array $child ): array {
		self::stable_identity( $child );
		self::require( self::stable_identity( $before ) === self::stable_identity( $after )
			&& 0040000 === ( $before['mode'] & 0170000 )
			&& is_int( $before['nlink'] ) && $before['nlink'] >= 2 && $after['nlink'] === $before['nlink'] + 1
			&& 0040700 === $child['mode'] && $before['uid'] === $child['uid'] && 2 === $child['nlink']
			&& $before['dev'] === $child['dev'] && $before['ino'] !== $child['ino'], 'unowned-child-creation-transition' );
		return array( 'before' => $before, 'after_nlink' => $after['nlink'], 'child' => $child );
	}

	private static function read( string $path, int $limit ): string {
		$bytes = @file_get_contents( $path, false, null, 0, $limit + 1 );
		self::require( is_string( $bytes ) && '' !== $bytes && strlen( $bytes ) <= $limit, 'unreadable-kernel-evidence' );
		return $bytes;
	}

	private static function stat( string $path ): array {
		clearstatcache( true, $path );
		$stat = @stat( $path );
		self::require( is_array( $stat ), 'unreadable-kernel-identity' );
		return array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) );
	}

	private static function peer( string $socket, int $pid ): array {
		$process = '/proc/' . $pid;
		$identity = self::stat( $process );
		self::require( 0 === $identity['uid'], 'unsupported-daemon-owner' );
		$stat = self::read( $process . '/stat', 65536 );
		self::require( 1 === preg_match( '/^([0-9]+) \(.+\) (.+)\n?$/D', $stat, $matches ) && (int) $matches[1] === $pid, 'ambiguous-process-identity' );
		$fields = explode( ' ', rtrim( $matches[2], "\n" ) );
		self::require( isset( $fields[19] ) && ctype_digit( $fields[19] ), 'missing-process-start-identity' );
		$listener = array();
		foreach ( explode( "\n", self::read( '/proc/net/unix', 4194304 ) ) as $line ) {
			$columns = preg_split( '/\s+/', trim( $line ), 8 );
			if ( 8 !== count( $columns ) || ! in_array( $columns[7], array( '/run/docker.sock', '/var/run/docker.sock' ), true ) ) { continue; }
			if ( realpath( $columns[7] ) !== $socket ) { continue; }
			self::require( '00010000' === $columns[3] && '0001' === $columns[4] && '01' === $columns[5]
				&& ctype_digit( $columns[6] ), 'ambiguous-selected-listener' );
			$listener[] = $columns[6];
		}
		self::require( 1 === count( $listener ), 'selected-listener-not-unique' );
		$descriptors = @scandir( $process . '/fd' );
		self::require( is_array( $descriptors ) && count( $descriptors ) <= 8192, 'daemon-descriptors-inaccessible' );
		$matched = array();
		foreach ( $descriptors as $descriptor ) {
			if ( ! ctype_digit( $descriptor ) ) { continue; }
			$link = @readlink( $process . '/fd/' . $descriptor );
			self::require( is_string( $link ), 'daemon-descriptor-race-or-denial' );
			if ( 'socket:[' . $listener[0] . ']' === $link ) { $matched[] = $descriptor; }
		}
		self::require( array() !== $matched, 'pid-hint-does-not-own-selected-listener' );
		$namespace = self::stat( $process . '/ns/mnt' );
		$self_namespace = self::stat( '/proc/self/ns/mnt' );
		self::require( $namespace['dev'] === $self_namespace['dev'] && $namespace['ino'] === $self_namespace['ino'], 'daemon-mount-namespace-differs' );
		self::require( $identity === self::stat( $process ), 'daemon-identity-changed-during-read' );
		return array( 'pid' => $pid, 'identity' => $identity, 'start_ticks' => $fields[19],
			'listener_inode' => $listener[0], 'listener_fds' => $matched, 'mount_namespace' => $namespace );
	}

	public static function admit( string $endpoint ): array {
		self::require( 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' ), 'native-linux-prerequisite' );
		self::require( in_array( $endpoint, array( 'unix:///run/docker.sock', 'unix:///var/run/docker.sock' ), true ), 'nonlocal-endpoint' );
		$socket = realpath( substr( $endpoint, 7 ) );
		self::require( '/run/docker.sock' === $socket, 'unrecognized-socket-alias' );
		$socket_before = self::stat( $socket );
		self::require( 0140000 === ( $socket_before['mode'] & 0170000 ) && 0 === $socket_before['uid'], 'nonroot-or-nonsocket-endpoint' );
		$hint = Wstm108_Files::file( '/run/docker.pid' );
		self::require( 0 === $hint['identity']['uid'] && 0 === ( $hint['identity']['mode'] & 0022 )
			&& 1 === preg_match( '/^[1-9][0-9]{0,9}\n?$/D', $hint['bytes'] ), 'untrusted-pid-discovery-hint' );
		$pid = (int) trim( $hint['bytes'] );
		$before = self::peer( $socket, $pid );
		$mountinfo = self::read( '/proc/self/mountinfo', 4194304 );
		self::require( $mountinfo === self::read( '/proc/' . $pid . '/mountinfo', 4194304 ), 'daemon-mount-table-differs' );
		$mounts = self::mounts( $mountinfo );
		self::require( $before === self::peer( $socket, $pid ) && $socket_before === self::stat( $socket )
			&& $mountinfo === self::read( '/proc/self/mountinfo', 4194304 ), 'peer-or-topology-changed-during-admission' );
		Wstm108_Files::assert_file( '/run/docker.pid', $hint );
		return array( 'endpoint' => $endpoint, 'socket_identity' => $socket_before, 'peer' => $before,
			'mountinfo_sha256' => hash( 'sha256', $mountinfo ), 'mounts' => $mounts );
	}
}
