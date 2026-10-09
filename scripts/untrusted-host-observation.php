<?php

declare(strict_types=1);

/** Fixed system reads only. The caller, custody, parser and PHP remain nonroot. */
final class Wstm108_HostObservation {
	public const MODE = 'system-readonly-v1';
	private const LIMIT = 4194304;
	private const STDERR_LIMIT = 65536;
	private static int $count = 0;
	private static int $deadline = 0;

	public static function begin_pass(): void {
		self::$count = 0;
		self::$deadline = hrtime( true ) + 120000000000;
		if ( class_exists( 'Wstm108_KernelMounts', false ) ) {
			self::$deadline = \Wstm108_KernelMounts::restrict_observation( self::$deadline );
		}
	}

	private static function require( bool $condition, string $reason = 'unreadable-kernel-evidence' ): void {
		if ( ! $condition ) { throw new Wstm108_TopologyRefusal( $reason ); }
	}

	public static function enabled( array $environment ): bool {
		if ( ! array_key_exists( 'WSTM108_HOST_INSPECTION', $environment ) ) { return false; }
		self::require( self::MODE === $environment['WSTM108_HOST_INSPECTION'], 'invalid-host-inspection-mode' );
		return true;
	}

	public static function command( string $operation, int $pid ): array {
		self::require( $pid > 0 && $pid <= 2147483647, 'untrusted-pid-discovery-hint' );
		$process = '/proc/' . $pid;
		$commands = array(
			'descriptors' => array( '/usr/bin/find', '-P', $process . '/fd', '-mindepth', '1', '-maxdepth', '1', '-printf', '%f\\0%l\\0' ),
			'namespace' => array( '/usr/bin/stat', '-L', '--printf=%d:%i:%u:%g:%f:%h\n', '--', $process . '/ns/mnt' ),
			'mountinfo' => array( '/usr/bin/head', '-c', '4194305', '--', $process . '/mountinfo' ),
		);
		self::require( isset( $commands[ $operation ] ) );
		// A nonroot controller cannot signal a root child. Only this fixed system
		// supervisor may bound the three fixed reads; it never receives a script.
		return array_merge( array( '/usr/bin/timeout', '--signal=TERM', '--kill-after=1s', '8s',
			'/usr/bin/sudo', '-n', '--user=root', '--', '/usr/bin/timeout',
			'--signal=TERM', '--kill-after=1s', '5s', '/usr/bin/env', '-i',
			'PATH=/usr/bin:/bin', 'LC_ALL=C' ), $commands[ $operation ] );
	}

	public static function environment(): array {
		return array( 'PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C' );
	}

	public static function descriptors( string $bytes ): array {
		self::require( '' !== $bytes && strlen( $bytes ) <= self::LIMIT && "\0" === substr( $bytes, -1 ),
			'daemon-descriptor-race-or-denial' );
		$fields = explode( "\0", substr( $bytes, 0, -1 ) );
		self::require( 0 === count( $fields ) % 2 && count( $fields ) <= 16380, 'daemon-descriptors-inaccessible' );
		$links = array();
		for ( $i = 0; $i < count( $fields ); $i += 2 ) {
			$name = $fields[ $i ];
			self::require( 1 === preg_match( '/^(?:0|[1-9][0-9]{0,9})$/D', $name )
				&& ( strlen( $name ) < 10 || strcmp( $name, '2147483647' ) <= 0 )
				&& ! array_key_exists( $name, $links ) && '' !== $fields[ $i + 1 ]
				&& strlen( $fields[ $i + 1 ] ) <= 4096, 'daemon-descriptor-race-or-denial' );
			$links[ $name ] = $fields[ $i + 1 ];
		}
		ksort( $links, SORT_STRING );
		return $links;
	}

	public static function namespace_identity( string $bytes ): array {
		self::require( 1 === preg_match( '/^([0-9]+):([0-9]+):([0-9]+):([0-9]+):([a-f0-9]+):([0-9]+)\n$/D', $bytes, $fields ),
			'unreadable-kernel-identity' );
		foreach ( array( 1, 2, 3, 4, 6 ) as $index ) {
			self::require( strlen( $fields[ $index ] ) < 19 || ( 19 === strlen( $fields[ $index ] )
				&& strcmp( $fields[ $index ], (string) PHP_INT_MAX ) <= 0 ), 'unreadable-kernel-identity' );
		}
		self::require( strlen( $fields[5] ) <= 8 && 0 !== (int) $fields[2] && hexdec( $fields[5] ) <= 4294967295,
			'unreadable-kernel-identity' );
		return array( 'dev' => (int) $fields[1], 'ino' => (int) $fields[2], 'mode' => (int) hexdec( $fields[5] ),
			'nlink' => (int) $fields[6], 'uid' => (int) $fields[3], 'gid' => (int) $fields[4] );
	}

	public static function elf_header( string $header ): string {
		self::require( 64 === strlen( $header ) && "\x7fELF\x02\x01\x01" === substr( $header, 0, 7 )
			&& in_array( ord( $header[7] ), array( 0, 3 ), true ) );
		$fields = unpack( 'vtype/vmachine/Vversion', substr( $header, 16, 8 ) );
		$sizes = unpack( 'vheader/vprogram/vcount', substr( $header, 52, 6 ) );
		self::require( is_array( $fields ) && is_array( $sizes )
			&& in_array( $fields['type'], array( 2, 3 ), true ) && in_array( $fields['machine'], array( 62, 183 ), true )
			&& 1 === $fields['version'] && 64 === $sizes['header'] && 56 === $sizes['program']
			&& $sizes['count'] > 0 && $sizes['count'] <= 1024 );
		return hash( 'sha256', $header );
	}

	private static function system_identity( string $path, bool $directory ): array {
		clearstatcache( true, $path );
		$identity = @lstat( $path );
		self::require( realpath( $path ) === $path && is_array( $identity ) && 0 === $identity['uid']
			&& 0 === ( $identity['mode'] & 0022 )
			&& ( '/usr/bin/sudo' === $path || 0 === ( $identity['mode'] & 06000 ) )
			&& ( $directory ? 0040000 : 0100000 ) === ( $identity['mode'] & 0170000 )
			&& ( $directory || 0 !== ( $identity['mode'] & 0111 ) ) );
		$identity = array_intersect_key( $identity, array_flip( array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime' ) ) );
		if ( ! $directory ) {
			$header = @file_get_contents( $path, false, null, 0, 64 );
			self::require( is_string( $header ) );
			$identity['elf_header_sha256'] = self::elf_header( $header );
		}
		return $identity;
	}

	private static function tools( array $command ): array {
		$identities = array();
		foreach ( array( '/', '/usr', '/usr/bin', $command[0], $command[4], $command[8], $command[12], $command[16] ) as $path ) {
			$identities[ $path ] = self::system_identity( $path, in_array( $path, array( '/', '/usr', '/usr/bin' ), true ) );
		}
		return $identities;
	}

	private static function custody( array $environment ): array {
		self::require( 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' ) && posix_geteuid() > 0
			&& function_exists( 'fsync' ) && ( new ReflectionFunction( 'fsync' ) )->isInternal() );
		$encoded = $environment['WSTM108_HOST_OBSERVATION_CUSTODY'] ?? '';
		self::require( is_string( $encoded ) && strlen( $encoded ) <= 8192 );
		$json = base64_decode( $encoded, true );
		self::require( is_string( $json ) && base64_encode( $json ) === $encoded );
		$handle = json_decode( $json, true );
		self::require( is_array( $handle ) && array_keys( $handle ) === array( 'directory', 'identity' )
			&& is_string( $handle['directory'] ) && is_array( $handle['identity'] ) );
		$identity = Wstm108_Files::directory( $handle['directory'] );
		self::require( Wstm108_HostTopology::stable_identity( $identity ) === $handle['identity']
			&& 0040700 === $identity['mode'] && posix_geteuid() === $identity['uid'] );
		return $handle;
	}

	private static function verify_custody( array $handle ): void {
		self::require( $handle['identity'] === Wstm108_HostTopology::stable_identity( Wstm108_Files::directory( $handle['directory'] ) ) );
	}

	private static function persist( $stream, string $bytes ): void {
		for ( $offset = 0; $offset < strlen( $bytes ); $offset += $written ) {
			$written = @fwrite( $stream, substr( $bytes, $offset ) );
			self::require( is_int( $written ) && $written > 0 );
		}
		self::require( fflush( $stream ) && fsync( $stream ) );
	}

	/** Pipes bound transport; originals are flushed privately before any parser. */
	private static function capture( array $command, array $handle ): string {
		$deadline = min( self::$deadline, hrtime( true ) + 8000000000 );
		if ( class_exists( 'Wstm108_KernelMounts', false ) ) { $deadline = \Wstm108_KernelMounts::observation_deadline( $deadline ); }
		self::require( hrtime( true ) < $deadline );
		$tools = self::tools( $command );
		self::verify_custody( $handle );
		$name = $handle['directory'] . '/host-observation-' . bin2hex( random_bytes( 16 ) );
		$streams = array();
		$originals = array();
		$pipes = array();
		$process = null;
		$status = null;
		$complete = false;
		$intent = null;
		$exit = null;
		$eof = array( 1 => false, 2 => false );
		$lengths = array( 1 => 0, 2 => 0 );
		try {
			foreach ( array( 1 => 'stdout', 2 => 'stderr' ) as $fd => $suffix ) {
				$file = Wstm108_Files::create( $name . '.' . $suffix . '.private', '' );
				self::require( 0100600 === $file['identity']['mode'] && posix_geteuid() === $file['identity']['uid'] );
				$streams[ $fd ] = @fopen( $name . '.' . $suffix . '.private', 'r+b' );
				self::require( is_resource( $streams[ $fd ] ) );
				$originals[ $fd ] = $file;
				self::require( $file['identity'] === array_intersect_key( fstat( $streams[ $fd ] ),
					array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ) );
			}
			$intent_path = $name . '.intent.private.json';
			$intent = Wstm108_Files::create( $intent_path, json_encode( array( 'version' => 1, 'state' => 'reserved',
				'argv' => $command, 'tools' => $tools, 'streams' => $originals ), JSON_THROW_ON_ERROR ) );
			foreach ( $streams as $stream ) { self::persist( $stream, '' ); }
			$reserved = fopen( $intent_path, 'rb' );
			self::require( is_resource( $reserved ) );
			try { self::require( fsync( $reserved ) ); } finally { fclose( $reserved ); }
			Wstm108_Files::assert_file( $intent_path, $intent );
			self::require( self::tools( $command ) === $tools && hrtime( true ) < $deadline );
			$process = @proc_open( $command, array( 0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ),
				3 => array( 'file', '/dev/null', 'w' ), 4 => array( 'file', '/dev/null', 'w' ),
				5 => array( 'file', '/dev/null', 'w' ), 9 => array( 'file', '/dev/null', 'w' ),
				10 => array( 'file', '/dev/null', 'w' ), 11 => array( 'file', '/dev/null', 'w' ) ),
				$pipes, '/', self::environment() );
			self::require( is_resource( $process ) );
			foreach ( $pipes as $pipe ) { self::require( stream_set_blocking( $pipe, false ) ); }
			$lengths = array( 1 => 0, 2 => 0 );
			$eof = array( 1 => false, 2 => false );
			$exit = null;
			while ( true ) {
				self::require( hrtime( true ) < $deadline );
				foreach ( $pipes as $fd => $pipe ) {
					$limit = 1 === $fd ? self::LIMIT : self::STDERR_LIMIT;
					$remaining = $limit + 1 - $lengths[ $fd ];
					self::require( $remaining > 0 );
					$bytes = fread( $pipe, min( 8192, $remaining ) );
					self::require( is_string( $bytes ) );
					if ( '' !== $bytes ) { self::persist( $streams[ $fd ], $bytes ); }
					self::require( hrtime( true ) < $deadline );
					$lengths[ $fd ] += strlen( $bytes );
					$eof[ $fd ] = feof( $pipe );
					self::require( $lengths[ $fd ] <= $limit );
				}
				$status = proc_get_status( $process );
				self::require( is_array( $status ) );
				if ( ! $status['running'] ) {
					if ( null === $exit ) { $exit = $status['exitcode']; }
					if ( $eof[1] && $eof[2] ) { break; }
				}
				usleep( 1000 );
			}
			foreach ( $pipes as $pipe ) { fclose( $pipe ); }
			$pipes = array();
			$process = null;
			self::require( 0 === $exit && 0 === $lengths[2] && $lengths[1] <= self::LIMIT
				&& self::tools( $command ) === $tools );
			self::verify_custody( $handle );
			$captured = array();
			foreach ( $streams as $fd => $stream ) {
				self::persist( $stream, '' );
				$suffix = 1 === $fd ? 'stdout' : 'stderr';
				$captured[ $suffix ] = Wstm108_Files::read_bound( $name . '.' . $suffix . '.private', $originals[ $fd ]['identity'] );
				$info = fstat( $stream );
				self::require( is_array( $info ) && $info['size'] === $lengths[ $fd ]
					&& strlen( $captured[ $suffix ]['bytes'] ) === $lengths[ $fd ]
					&& $originals[ $fd ]['identity'] === array_intersect_key( $info,
						array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ) );
			}
			self::require( hrtime( true ) < $deadline );
			$intent = Wstm108_Files::update( $intent_path, $intent, json_encode( array( 'version' => 1,
				'state' => 'complete', 'exit' => $exit, 'eof' => $eof, 'argv' => $command,
				'tools' => $tools, 'streams' => array_map( static fn( $file ) => array(
					'identity' => $file['identity'], 'sha256' => $file['sha256'], 'length' => strlen( $file['bytes'] ) ), $captured ) ), JSON_THROW_ON_ERROR ) );
			$record = fopen( $intent_path, 'rb' );
			self::require( is_resource( $record ) );
			try { self::require( fsync( $record ) ); } finally { fclose( $record ); }
			Wstm108_Files::assert_file( $intent_path, $intent );
			self::require( hrtime( true ) < $deadline );
			$complete = true;
			return $captured['stdout']['bytes'];
		} finally {
			try {
				self::require( self::tools( $command ) === $tools );
				if ( ! $complete ) { self::verify_custody( $handle ); }
				if ( $complete ) { self::require( hrtime( true ) < $deadline ); }
			} catch ( Wstm108_TopologyRefusal $error ) {
				$complete = false;
				throw $error;
			} finally {
				try {
					if ( ! $complete && is_array( $intent ) ) {
						if ( is_resource( $process ) && null === $exit ) {
							$status = proc_get_status( $process );
							if ( is_array( $status ) && ! $status['running'] ) { $exit = $status['exitcode']; }
						}
						foreach ( $pipes as $fd => $pipe ) { $eof[ $fd ] = feof( $pipe ); }
						$failed_streams = array();
						foreach ( $streams as $fd => $stream ) {
							self::persist( $stream, '' );
							$suffix = 1 === $fd ? 'stdout' : 'stderr';
							$file = Wstm108_Files::read_bound( $name . '.' . $suffix . '.private', $originals[ $fd ]['identity'] );
							$failed_streams[ $suffix ] = array( 'identity' => $file['identity'],
								'sha256' => $file['sha256'], 'length' => strlen( $file['bytes'] ) );
						}
						$intent = Wstm108_Files::update( $intent_path, $intent, json_encode( array( 'version' => 1,
							'state' => 'failed', 'exit' => $exit, 'eof' => $eof, 'deadline_expired' => hrtime( true ) >= $deadline,
							'running' => is_array( $status ) ? $status['running'] : null, 'argv' => $command,
							'tools' => $tools, 'streams' => $failed_streams ), JSON_THROW_ON_ERROR ) );
						$record = fopen( $intent_path, 'rb' );
						self::require( is_resource( $record ) );
						try { self::require( fsync( $record ) ); } finally { fclose( $record ); }
						Wstm108_Files::assert_file( $intent_path, $intent );
					}
				} finally {
					foreach ( $pipes as $pipe ) { fclose( $pipe ); }
					// Linux PHP 8.0/8.4 resource disposal uses WNOHANG, unlike proc_close.
					// Leave fixed system timers intact; never signal a reaped/reused PID
					// or assume nonroot PHP can terminate root descendants.
					$process = null;
					foreach ( $streams as $stream ) { if ( is_resource( $stream ) ) { fclose( $stream ); } }
				}
			}
		}
	}

	public static function read( string $operation, int $pid ): string {
		$environment = getenv();
		self::require( self::enabled( $environment ) );
		$command = self::command( $operation, $pid );
		if ( 0 === self::$deadline ) { self::$deadline = hrtime( true ) + 120000000000; }
		self::require( ++self::$count <= 64 && hrtime( true ) < self::$deadline );
		if ( class_exists( 'Wstm108_KernelMounts', false ) ) { \Wstm108_KernelMounts::observation_read(); }
		$scope = self::scope( $pid );
		$bytes = self::capture( $command, self::custody( $environment ) );
		self::require( $scope === self::scope( $pid ), 'daemon-identity-changed-during-read' );
		self::require( hrtime( true ) < self::$deadline );
		return $bytes;
	}

	private static function scope( int $pid ): array {
		$hint = Wstm108_Files::file( '/run/docker.pid' );
		self::require( 0 === $hint['identity']['uid'] && 0 === ( $hint['identity']['mode'] & 0022 )
			&& 1 === preg_match( '/^[1-9][0-9]{0,9}\n?$/D', $hint['bytes'] )
			&& $pid === (int) trim( $hint['bytes'] ), 'untrusted-pid-discovery-hint' );
		clearstatcache();
		$socket = @lstat( '/run/docker.sock' );
		$process = @stat( '/proc/' . $pid );
		self::require( '/run/docker.sock' === realpath( '/run/docker.sock' ) && is_array( $socket )
			&& 0 === $socket['uid'] && 0140000 === ( $socket['mode'] & 0170000 ), 'nonroot-or-nonsocket-endpoint' );
		self::require( is_array( $process ) && 0 === $process['uid'], 'unsupported-daemon-owner' );
		$bytes = @file_get_contents( '/proc/' . $pid . '/stat', false, null, 0, 65537 );
		self::require( is_string( $bytes ) && strlen( $bytes ) <= 65536
			&& 1 === preg_match( '/^([0-9]+) \(.+\) (.+)\n?$/D', $bytes, $fields )
			&& $pid === (int) $fields[1], 'ambiguous-process-identity' );
		$fields = explode( ' ', rtrim( $fields[2], "\n" ) );
		self::require( isset( $fields[19] ) && ctype_digit( $fields[19] ), 'missing-process-start-identity' );
		$keys = array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) );
		return array( 'hint' => $hint, 'socket' => array_intersect_key( $socket, $keys ),
			'process' => array_intersect_key( $process, $keys ), 'start_ticks' => $fields[19] );
	}

	/** Bind retained originals independently; incomplete observations block release. */
	public static function retained( array $environment, array $previous ): array {
		self::require( self::enabled( $environment ) );
		$handle = self::custody( $environment );
		$names = @scandir( $handle['directory'] );
		self::require( is_array( $names ) && count( $names ) <= 8192 );
		$files = array();
		$stems = array();
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) { continue; }
			self::require( 1 === preg_match( '/^(host-observation-[a-f0-9]{32})\.(stdout\.private|stderr\.private|intent\.private\.json)$/D', $name, $match ) );
			$path = $handle['directory'] . '/' . $name;
			clearstatcache( true, $path );
			$identity = @lstat( $path );
			self::require( is_array( $identity ) && 0100600 === $identity['mode'] && 1 === $identity['nlink']
				&& posix_geteuid() === $identity['uid'] && $identity['size'] <= self::LIMIT );
			$file = Wstm108_Files::file( $path );
			$files[ $name ] = array( 'identity' => $file['identity'], 'sha256' => $file['sha256'], 'length' => strlen( $file['bytes'] ) );
			if ( isset( $previous[ $name ] ) ) { self::require( $previous[ $name ] === $files[ $name ] ); }
			$stems[ $match[1] ][ $match[2] ] = $file;
		}
		self::require( array() === array_diff( array_keys( $previous ), array_keys( $files ) ) );
		foreach ( $stems as $stem => $streams ) {
			$keys = array_keys( $streams ); sort( $keys, SORT_STRING );
			self::require( $keys === array( 'intent.private.json', 'stderr.private', 'stdout.private' ) );
			$intent = json_decode( $streams['intent.private.json']['bytes'], true );
			self::require( is_array( $intent ) && array_keys( $intent ) === array( 'version', 'state', 'exit', 'eof', 'argv', 'tools', 'streams' )
				&& 1 === $intent['version'] && 'complete' === $intent['state'] && 0 === $intent['exit']
				&& array( 1 => true, 2 => true ) === $intent['eof']
				&& is_array( $intent['argv'] ) && is_array( $intent['tools'] ) && is_array( $intent['streams'] )
				&& array( 'stdout', 'stderr' ) === array_keys( $intent['streams'] ) );
			$operation = null;
			foreach ( array( 'descriptors', 'namespace', 'mountinfo' ) as $candidate ) {
				$target = 'descriptors' === $candidate ? ( $intent['argv'][18] ?? '' ) : ( end( $intent['argv'] ) );
				if ( is_string( $target ) && 1 === preg_match( '/^\/proc\/([1-9][0-9]{0,9})\/(?:fd|ns\/mnt|mountinfo)$/D', $target, $match )
					&& $intent['argv'] === self::command( $candidate, (int) $match[1] ) ) {
					$operation = $candidate; break;
				}
			}
			self::require( null !== $operation && $intent['tools'] === self::tools( $intent['argv'] ) );
			foreach ( array( 'stdout', 'stderr' ) as $stream ) {
				$name = $stem . '.' . $stream . '.private';
				self::require( $files[ $name ] === $intent['streams'][ $stream ] );
			}
			self::require( '' === $streams['stderr.private']['bytes'] );
			$bytes = $streams['stdout.private']['bytes'];
			if ( 'descriptors' === $operation ) { self::descriptors( $bytes ); }
			elseif ( 'namespace' === $operation ) { self::namespace_identity( $bytes ); }
			else { Wstm108_HostTopology::structural_mounts( $bytes ); }
		}
		self::require( $names === scandir( $handle['directory'] ) );
		self::verify_custody( $handle );
		return $files;
	}
}
