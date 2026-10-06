<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-kernel-mount-model.php';
require_once __DIR__ . '/untrusted-kernel-authority-owner.php';

/** Live own-process evidence only; arrays/receipts cannot construct a session. */
final class Wstm108_KernelMounts {
	private const HOLDER_SHA256 = '7258b8a9e0ca9d2de24b86dee108f57488aadb6898226ca5681251eabfdcca72';
	private static Wstm108_HostController|Wstm108_KernelAuthorityOwner|null $owner = null;
	private static int $deadline = 0;
	private static int $reads = 0;
	private static array $remaining = array();
	private static int $parent_query_slots = 0;
	private static bool $helper_reservation_started = false;
	private static bool $query_phase = true;
	private static ?Closure $collector = null;
	private static ?Closure $finisher = null;
	private static ?string $session_table = null;
	private static bool $failed = false;
	private static int $collector_deadline = 0;

	private function __construct() {}
	private function __clone() {}
	public function __serialize(): array { throw new Wstm108_TopologyRefusal( 'native-coordinate-prerequisite' ); }
	public function __unserialize( array $data ): void { throw new Wstm108_TopologyRefusal( 'native-coordinate-prerequisite' ); }

	private static function require( bool $condition, string $reason = 'unreadable-kernel-evidence' ): void {
		if ( ! $condition ) { throw new Wstm108_TopologyRefusal( $reason ); }
	}

	/** Called only at the two original controller query-pass lifecycle sites. */
	public static function controller_pass( Wstm108_HostController $owner ): void {
		$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1] ?? array();
		self::require( Wstm108_HostController::class === ( $caller['class'] ?? null )
			&& 'begin_query_pass' === ( $caller['function'] ?? null ), 'native-coordinate-prerequisite' );
		self::finish_scope();
		self::$owner = $owner;
		self::$deadline = $owner->kernel_query_deadline();
		self::$reads = 0;
		self::$query_phase = true;
		self::$parent_query_slots = 0;
		self::$helper_reservation_started = false;
		self::$remaining = array( 'ns' => 8000000000, 'launches' => 8, 'bytes' => 16777216 );
	}

	public static function restrict_observation( int $deadline ): int {
		if ( null === self::$owner ) { return $deadline; }
		self::require( $deadline > 0 );
		self::$deadline = 0 === self::$deadline ? $deadline : min( self::$deadline, $deadline );
		if ( self::$owner instanceof Wstm108_KernelAuthorityOwner ) { self::$owner->attach( self::$deadline ); }
		return self::$deadline;
	}

	public static function authority_entry( array $context, string $action ): void {
		self::require( null === self::$owner, 'native-coordinate-prerequisite' );
		self::$owner = Wstm108_KernelAuthorityOwner::inherited( $context, $action );
		self::$remaining = self::$owner->allowance();
		self::$deadline = 0;
		self::$query_phase = false;
	}

	public static function pause_for_capture(): void {
		$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1] ?? array();
		self::require( in_array( array( $caller['class'] ?? null, $caller['function'] ?? null ),
			array( array( Wstm108_HostController::class, 'action' ), array( Wstm108_HostAuthority::class, 'capture' ) ), true ),
			'native-coordinate-prerequisite' );
		self::finish_scope();
		self::$deadline = 0;
		self::$reads = 0;
		self::$query_phase = false;
		if ( self::$owner instanceof Wstm108_KernelAuthorityOwner ) { self::$owner->pause(); }
	}

	public static function reserve_helper(): array {
		self::require( self::$owner instanceof Wstm108_HostController && self::$query_phase
			&& ! self::$helper_reservation_started && 0 === self::$parent_query_slots, 'native-coordinate-prerequisite' );
		self::$helper_reservation_started = true;
		self::finish_scope();
		$ns = min( 4000000000, self::$remaining['ns'] - 2000000000 );
		// Returned-state, terminal inventory and final precommit checks each
		// need a fresh live session after the preceding durable finalization.
		$launches = min( 4, self::$remaining['launches'] - 3 );
		$bytes = min( 8388608, intdiv( self::$remaining['bytes'], 2 ) );
		self::require( $ns > 1000000000 && $launches > 0 && $bytes > 0 );
		self::$remaining = Wstm108_KernelMountModel::spend( self::$remaining, $ns, $launches, $bytes );
		for ( $i = 0; $i < $launches; ++$i ) { self::$owner->kernel_query_slot(); }
		// Parent post-capture checks borrow new original observation clocks,
		// not a revived query clock. Reserve their query slots now, once.
		for ( $i = 0; $i < self::$remaining['launches']; ++$i ) { self::$owner->kernel_query_slot(); }
		self::$parent_query_slots = self::$remaining['launches'];
		return array( 'ns' => $ns, 'launches' => $launches, 'bytes' => $bytes );
	}

	private static function consume_launch(): void {
		self::require( null !== self::$owner, 'native-coordinate-prerequisite' );
		self::$remaining = Wstm108_KernelMountModel::spend( self::$remaining, 0, 1, 0 );
		if ( self::$owner instanceof Wstm108_HostController && self::$parent_query_slots > 0 ) {
			--self::$parent_query_slots;
		} else {
			self::require( self::$query_phase || self::$owner instanceof Wstm108_KernelAuthorityOwner,
				'native-coordinate-prerequisite' );
			self::$owner->kernel_query_slot();
		}
	}

	public static function storage_interval( int $start, int $bytes = 0 ): void {
		$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1] ?? array();
		self::require( Wstm108_HostController::class === ( $caller['class'] ?? null )
			&& in_array( $caller['function'] ?? null, array( 'action', 'import_kernel_captures', 'verify_captures' ), true )
			&& $start > 0 && $bytes >= 0 && $bytes <= 4096 && null === self::$collector,
			'native-coordinate-prerequisite' );
		$now = hrtime( true );
		self::require( $now >= $start );
		$elapsed = $now - $start;
		$fits = $elapsed <= self::$remaining['ns'] && $bytes <= self::$remaining['bytes'];
		self::$remaining = Wstm108_KernelMountModel::spend( self::$remaining,
			min( $elapsed, self::$remaining['ns'] ), 0, min( $bytes, self::$remaining['bytes'] ) );
		self::require( $fits && ( 0 === self::$deadline || $now < self::$deadline ) );
	}

	public static function finish_scope(): void {
		$finisher = self::$finisher;
		self::$collector = null;
		self::$finisher = null;
		self::$session_table = null;
		try { if ( null !== $finisher ) { $finisher(); } }
		finally { self::$collector_deadline = 0; }
	}

	public static function fail_scope(): void {
		self::$failed = true;
		self::finish_scope();
	}

	public static function observation_deadline( int $deadline ): int {
		if ( null === self::$owner ) { return $deadline; }
		self::require( self::$deadline > 0 );
		return min( $deadline, self::$deadline, self::$collector_deadline > 0 ? self::$collector_deadline : $deadline );
	}

	public static function observation_read(): void {
		if ( null === self::$owner ) { return; }
		self::require( ++self::$reads <= 64 && hrtime( true ) < self::observation_deadline( self::$deadline ) );
	}

	private static function existing_deadline(): int {
		self::require( null !== self::$owner && self::$deadline > 0 && hrtime( true ) < self::$deadline
			&& Wstm108_HostObservation::enabled( getenv() ), 'native-coordinate-prerequisite' );
		return self::$query_phase ? min( self::$deadline, self::$owner->kernel_query_deadline() ) : self::$deadline;
	}

	public static function visibility( string $bytes ): void {
		$mounts = Wstm108_HostTopology::structural_mounts( $bytes );
		$points = array();
		$duplicates = array( '/' => true );
		foreach ( $mounts as $mount ) {
			if ( isset( $points[ $mount['point'] ] ) ) { $duplicates[ $mount['point'] ] = true; }
			$points[ $mount['point'] ] = true;
		}
		self::collect( $bytes, $mounts, array_keys( $duplicates ), null, array() );
	}

	public static function outside( string $authority, array $sources, array $mounts ): void {
		self::existing_deadline();
		$bytes = self::read( '/proc/self/mountinfo', 4194304 );
		self::require( Wstm108_HostTopology::structural_mounts( $bytes ) === $mounts, 'peer-or-topology-changed-during-admission' );
		$paths = array( $authority );
		foreach ( $mounts as $mount ) {
			if ( Wstm108_KernelMountModel::contains( $authority, $mount['point'] ) ) { $paths[] = $mount['point']; }
		}
		foreach ( $sources as $source ) {
			self::require( is_string( $source ), 'nonstring-bind-source' );
			$paths[] = Wstm108_KernelMountModel::path( $source );
			if ( is_dir( $source ) ) {
				foreach ( $mounts as $mount ) {
					if ( Wstm108_KernelMountModel::contains( $source, $mount['point'] ) ) { $paths[] = $mount['point']; }
				}
			}
		}
		$points = array();
		foreach ( $mounts as $mount ) {
			if ( isset( $points[ $mount['point'] ] ) ) { $paths[] = $mount['point']; }
			$points[ $mount['point'] ] = true;
		}
		$paths[] = '/';
		self::collect( $bytes, $mounts, array_values( array_unique( $paths, SORT_STRING ) ), $authority, $sources );
	}

	private static function read( string $path, int $limit ): string {
		$bytes = @file_get_contents( $path, false, null, 0, $limit + 1 );
		self::require( is_string( $bytes ) && '' !== $bytes && strlen( $bytes ) <= $limit );
		return $bytes;
	}

	private static function identity( string $path ): array {
		clearstatcache( true, $path );
		$stat = @stat( $path );
		self::require( is_array( $stat ), 'unreadable-kernel-identity' );
		$identity = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) );
		self::require( 6 === count( array_filter( $identity, 'is_int' ) ) && $identity['dev'] >= 0 && $identity['ino'] > 0, 'unreadable-kernel-identity' );
		return $identity;
	}

	private static function actor( int $pid, callable $retain ): array {
		self::require( $pid > 0 && $pid <= 2147483647, 'ambiguous-process-identity' );
		$path = '/proc/' . $pid;
		$status = self::read( $path . '/status', 65536 );
		$stat = self::read( $path . '/stat', 65536 );
		$retain( 'status:' . $pid, $status );
		$retain( 'stat:' . $pid, $stat );
		$parsed = Wstm108_KernelMountModel::status( $status );
		self::require( $parsed['Pid'] === $pid && 1 === preg_match( '/^([0-9]+) \(.+\) (.+)\n?$/D', $stat, $match )
			&& (int) $match[1] === $pid, 'ambiguous-process-identity' );
		$fields = explode( ' ', rtrim( $match[2], "\n" ) );
		self::require( isset( $fields[19] ) && ctype_digit( $fields[19] ), 'missing-process-start-identity' );
		$tasks = @scandir( $path . '/task' );
		self::require( is_array( $tasks ) && array( '.', '..', (string) $pid ) === $tasks, 'ambiguous-process-identity' );
		$executable = @readlink( $path . '/exe' );
		self::require( is_string( $executable ) && '/' === substr( $executable, 0, 1 )
			&& false === strpos( $executable, ' (deleted)' ), 'ambiguous-process-identity' );
		$actor = array( 'status' => $parsed, 'start' => $fields[19], 'process' => self::identity( $path ),
			'namespace' => self::identity( $path . '/ns/mnt' ), 'executable' => $executable, 'exe_identity' => self::identity( $path . '/exe' ) );
		$retain( 'actor-identity:' . $pid, json_encode( $actor, JSON_THROW_ON_ERROR ) );
		return $actor;
	}

	private static function guard( array $parent, ?array $child, int $pid, int $deadline, callable $retain ): void {
		self::require( hrtime( true ) < $deadline );
		self::require( $parent === self::actor( getmypid(), $retain ), 'daemon-identity-changed-during-read' );
		if ( null !== $child ) {
			self::require( $child === self::actor( $pid, $retain ), 'daemon-identity-changed-during-read' );
		}
	}

	private static function executable(): array {
		$python = realpath( '/usr/bin/python3' );
		self::require( is_string( $python ) && 0 === strpos( $python, '/usr/bin/' ), 'native-coordinate-prerequisite' );
		$files = array();
		foreach ( array( '/', '/usr', '/usr/bin', $python ) as $path ) {
			clearstatcache( true, $path );
			$stat = @lstat( $path );
			self::require( realpath( $path ) === $path && is_array( $stat ) && 0 === $stat['uid']
				&& 0 === ( $stat['mode'] & 0022 ) && 0 === ( $stat['mode'] & 06000 )
				&& ( $path === $python ? 0100000 : 0040000 ) === ( $stat['mode'] & 0170000 )
				&& ( $path !== $python || 0 !== ( $stat['mode'] & 0111 ) ), 'native-coordinate-prerequisite' );
			$files[ $path ] = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size', 'mtime', 'ctime' ) ) );
		}
		$files[ $python ]['header'] = Wstm108_HostObservation::elf_header( self::read_header( $python ) );
		$script = __DIR__ . '/untrusted-kernel-mount-holder.py';
		$file = Wstm108_Files::file( $script );
		self::require( realpath( $script ) === $script && self::HOLDER_SHA256 === $file['sha256']
			&& 0100000 === ( $file['identity']['mode'] & 0170000 ) && 0 === ( $file['identity']['mode'] & 0022 )
			&& 1 === $file['identity']['nlink'] && in_array( $file['identity']['uid'], array( 0, posix_geteuid() ), true ), 'native-coordinate-prerequisite' );
		return array( 'python' => $python, 'files' => $files, 'script' => $file );
	}

	private static function read_header( string $path ): string {
		$bytes = @file_get_contents( $path, false, null, 0, 64 );
		self::require( is_string( $bytes ) && 64 === strlen( $bytes ) );
		return $bytes;
	}

	private static function device( int $device ): string {
		self::require( $device >= 0, 'unsupported-native-device' );
		return ( ( ( $device >> 8 ) & 0xfff ) | ( ( $device >> 32 ) & 0xfffff000 ) )
			. ':' . ( ( $device & 0xff ) | ( ( $device >> 12 ) & 0xffffff00 ) );
	}

	private static function held( int $pid, int $fd, string $path, callable $retain ): array {
		self::require( $fd >= 3 && $fd <= 2147483647 );
		$path = Wstm108_KernelMountModel::path( $path );
		$info = self::read( '/proc/' . $pid . '/fdinfo/' . $fd, 65536 );
		$retain( 'fdinfo:' . $pid . ':' . $fd, $info );
		$parsed = Wstm108_KernelMountModel::fdinfo( $info );
		clearstatcache( true, $path );
		self::require( realpath( $path ) === $path && @readlink( '/proc/' . $pid . '/fd/' . $fd ) === $path, 'coordinate-path-changed' );
		$identity = self::identity( '/proc/' . $pid . '/fd/' . $fd );
		self::require( $parsed['ino'] === $identity['ino'] && $identity === self::identity( $path )
			&& 0120000 !== ( $identity['mode'] & 0170000 ), 'kernel-coordinate-or-identity-disagrees' );
		$retain( 'fd-identity:' . $pid . ':' . $fd, json_encode( array( 'path' => $path, 'identity' => $identity ), JSON_THROW_ON_ERROR ) );
		return array( 'mount_id' => $parsed['mount_id'], 'identity' => $identity, 'device' => self::device( $identity['dev'] ) );
	}

	private static function row( array $mounts, string $path, array $held ): array {
		return Wstm108_KernelMountModel::selected_row( $path, $mounts, $held['mount_id'], $held['device'] );
	}

	private static function write( $stream, string $bytes, int $deadline ): void {
		for ( $offset = 0; $offset < strlen( $bytes ); $offset += $written ) {
			self::require( hrtime( true ) < $deadline );
			$written = @fwrite( $stream, substr( $bytes, $offset, 8192 ) );
			self::require( is_int( $written ) && $written >= 0 );
			if ( 0 === $written ) { usleep( 1000 ); }
		}
	}

	private static function collect( string $table, array $mounts, array $paths, ?string $authority, array $sources ): void {
		if ( null !== self::$collector ) {
			self::require( self::$session_table === $table, 'peer-or-topology-changed-during-admission' );
			try { ( self::$collector )( $paths, $authority, $sources ); }
			catch ( Throwable $error ) {
				self::$failed = true;
				self::finish_scope();
				throw $error;
			}
			return;
		}
		$start = hrtime( true );
		$deadline = Wstm108_KernelMountModel::deadline( self::existing_deadline(), $start, self::$remaining );
		self::require( 'Linux' === PHP_OS_FAMILY && PHP_INT_SIZE >= 8 && function_exists( 'posix_getgroups' )
			&& function_exists( 'fsync' ) && ( new ReflectionFunction( 'fsync' ) )->isInternal()
			&& $deadline - $start > 1000000000 && self::$remaining['launches'] > 0, 'native-coordinate-prerequisite' );
		$work_deadline = $deadline - 1000000000;
		$stream_limit = self::$remaining['bytes'] - 16384;
		self::require( $stream_limit > 0, 'native-coordinate-prerequisite' );
		self::$collector_deadline = $work_deadline;
		self::consume_launch();
		$custody = self::$owner->kernel_custody();
		$stem = 'kernel-' . bin2hex( random_bytes( 16 ) );
		$files = array();
		$handles = array();
		$hashes = array();
		$pipes = array();
		$process = null;
		$exit = null;
		$complete = false;
		$eof = array( 1 => false, 2 => false );
		$total = 0;
		$intent = null;
		$intent_path = $custody['directory'] . '/' . $stem . '.intent.private.json';
		$parent = null;
		$deferred = false;
		self::$failed = false;
		$cleanup = static function ( bool $success ) use ( &$process, &$pipes, &$exit, &$eof, &$total, &$intent, &$handles,
			$intent_path, $start, $deadline ): bool {
			if ( is_resource( $process ) ) {
				$status = null;
				if ( hrtime( true ) < $deadline ) {
					proc_terminate( $process, 15 );
					do {
						$status = proc_get_status( $process );
						if ( ! is_array( $status ) || ! $status['running'] ) { break; }
						if ( hrtime( true ) >= $deadline - 500000000 ) { proc_terminate( $process, 9 ); }
						usleep( 1000 );
					} while ( hrtime( true ) < $deadline );
				}
				if ( is_array( $status ) && ! $status['running'] ) {
					$exit = $status['exitcode']; proc_close( $process );
				}
				// As in HostObservation, Linux PHP disposes running process
				// resources with WNOHANG. Unknown exit/EOF remains failure;
				// do not retain a resource indefinitely or call proc_close.
				$process = null;
			}
			foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			$pipes = array();
			$persistence = true;
			foreach ( $handles as $handle ) {
				if ( ! $success ) {
					$flushed = hrtime( true ) < $deadline && fflush( $handle );
					$synced = hrtime( true ) < $deadline && fsync( $handle );
					$persistence = $flushed && $synced && $persistence;
				}
				fclose( $handle );
			}
			$handles = array();
			$success = $success && $persistence && hrtime( true ) < $deadline && $total <= self::$remaining['bytes'];
			if ( ! $success && null !== $intent && hrtime( true ) < $deadline ) {
				$intent = Wstm108_Files::update( $intent_path, $intent, json_encode( array( 'version' => 1, 'state' => 'failed',
					'exit' => $exit, 'eof' => $eof, 'deadline' => $deadline ), JSON_THROW_ON_ERROR ) );
				self::sync( $intent_path, $intent );
				$total += strlen( $intent['bytes'] );
			}
			$elapsed = hrtime( true ) - $start;
			self::$remaining = Wstm108_KernelMountModel::spend( self::$remaining, min( $elapsed, self::$remaining['ns'] ), 0, min( $total, self::$remaining['bytes'] ) );
			self::require( $persistence );
			return $success;
		};
		try {
			foreach ( array( 'stdout', 'stderr', 'input', 'kernel' ) as $suffix ) {
				$path = $custody['directory'] . '/' . $stem . '.' . $suffix . '.private';
				$files[ $suffix ] = Wstm108_Files::create( $path, '' );
				$handles[ $suffix ] = @fopen( $path, 'r+b' );
				self::require( is_resource( $handles[ $suffix ] ) );
				$hashes[ $suffix ] = hash_init( 'sha256' );
			}
			$intent_path = $custody['directory'] . '/' . $stem . '.intent.private.json';
			$intent = Wstm108_Files::create( $intent_path, json_encode( array( 'version' => 1, 'state' => 'reserved',
				'deadline' => $deadline ), JSON_THROW_ON_ERROR ) );
			$total += strlen( $intent['bytes'] );
			self::sync( $intent_path, $intent );
			$persist = static function ( string $suffix, string $bytes, int $until ) use ( $files, $handles, $hashes, $custody, $stem ): void {
				$stat = fstat( $handles[ $suffix ] );
				self::require( is_array( $stat ) && $files[ $suffix ]['identity'] === array_intersect_key( $stat,
					array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ) );
				clearstatcache();
				$path = $custody['directory'] . '/' . $stem . '.' . $suffix . '.private';
				self::require( ! is_link( $path ) && $files[ $suffix ]['identity'] === self::identity( $path ) );
				self::write( $handles[ $suffix ], $bytes, $until );
				hash_update( $hashes[ $suffix ], $bytes );
				self::require( fflush( $handles[ $suffix ] ) && fsync( $handles[ $suffix ] ) && hrtime( true ) < $until );
			};
			$retain = static function ( string $label, string $bytes ) use ( &$total, $persist, $work_deadline, $stream_limit ): void {
				$record = json_encode( array( 'label' => $label, 'bytes' => base64_encode( $bytes ) ), JSON_THROW_ON_ERROR ) . "\n";
				$total += strlen( $record );
				self::require( $total <= $stream_limit && hrtime( true ) < $work_deadline );
				$persist( 'kernel', $record, $work_deadline );
			};
			$retain( 'mountinfo', $table );
			$parent = self::actor( getmypid(), $retain );
			Wstm108_KernelMountModel::safe_exec( $parent['status'], posix_geteuid(), posix_getegid(), posix_getgroups() );
			self::require( posix_getuid() === posix_geteuid() && posix_getgid() === posix_getegid()
				&& $table === self::read( '/proc/self/mountinfo', 4194304 ), 'native-coordinate-prerequisite' );
			$executable = self::executable();
			self::guard( $parent, null, 0, $work_deadline, $retain );
			$argv = array( $executable['python'], '-I', '-S', '-B', __DIR__ . '/untrusted-kernel-mount-holder.py', (string) $work_deadline );
			$intent = Wstm108_Files::update( $intent_path, $intent, json_encode( array( 'version' => 1, 'state' => 'reserved',
				'argv' => $argv, 'executable' => $executable, 'deadline' => $deadline ), JSON_THROW_ON_ERROR ) );
			$total += strlen( $intent['bytes'] );
			self::require( $total <= $stream_limit );
			self::sync( $intent_path, $intent );
			self::require( $executable === self::executable() && hrtime( true ) < $work_deadline );
			$process = proc_open( $argv, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ),
				3 => array( 'file', '/dev/null', 'w' ), 4 => array( 'file', '/dev/null', 'w' ),
				5 => array( 'file', '/dev/null', 'w' ), 9 => array( 'file', '/dev/null', 'w' ) ),
				$pipes, __DIR__, array( 'PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C' ) );
			self::require( is_resource( $process ) );
			foreach ( $pipes as $pipe ) { self::require( stream_set_blocking( $pipe, false ) ); }
			$status = proc_get_status( $process );
			self::require( is_array( $status ) && true === $status['running'] && is_int( $status['pid'] ) );
			$pid = $status['pid'];
			$buffer = '';
			$lengths = array( 1 => 0, 2 => 0 );
			$receive = static function ( bool $line ) use ( &$buffer, &$total, &$eof, &$lengths, $pipes, $persist, $work_deadline, $stream_limit ): ?array {
				$chunk_deadline = min( $work_deadline, hrtime( true ) + 1000000000 );
				while ( true ) {
					self::require( hrtime( true ) < $chunk_deadline );
					foreach ( array( 1 => 'stdout', 2 => 'stderr' ) as $fd => $suffix ) {
						$bytes = @fread( $pipes[ $fd ], 8192 );
						self::require( is_string( $bytes ) );
						if ( '' !== $bytes ) {
							$lengths[ $fd ] += strlen( $bytes );
							$total += strlen( $bytes );
							self::require( $lengths[ $fd ] <= ( 1 === $fd ? 4194304 : 65536 ) && $total <= $stream_limit );
							$persist( $suffix, $bytes, $chunk_deadline );
							if ( 1 === $fd ) { $buffer .= $bytes; }
						}
						$eof[ $fd ] = feof( $pipes[ $fd ] );
					}
					self::require( 0 === $lengths[2] && strlen( $buffer ) <= 262144 );
					if ( $line && false !== ( $end = strpos( $buffer, "\n" ) ) ) {
						$bytes = substr( $buffer, 0, $end + 1 );
						$buffer = substr( $buffer, $end + 1 );
						$value = json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR );
						self::require( is_array( $value ) && '' === $buffer && $bytes === json_encode( $value,
							JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR ) . "\n" );
						return $value;
					}
					if ( ! $line && $eof[1] && $eof[2] ) { self::require( '' === $buffer ); return null; }
					self::require( ! $line || ! $eof[1] );
					usleep( 1000 );
				}
			};
			self::require( array( 'ready' => 1 ) === $receive( true ) );
			$child = self::actor( $pid, $retain );
			Wstm108_KernelMountModel::safe_exec( $child['status'], posix_geteuid(), posix_getegid(), posix_getgroups() );
			foreach ( array( 'Uid', 'Gid', 'Groups', 'CapEff', 'CapPrm', 'CapInh', 'CapAmb', 'CapBnd', 'NoNewPrivs' ) as $field ) {
				self::require( $child['status'][ $field ] === $parent['status'][ $field ], 'native-coordinate-prerequisite' );
			}
			self::require( $child['namespace'] === $parent['namespace'], 'daemon-mount-namespace-differs' );
			self::require( $child['executable'] === $executable['python']
				&& $child['exe_identity'] === array_intersect_key( $executable['files'][ $executable['python'] ],
					array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ), 'native-coordinate-prerequisite' );
			$held = array();
			$seen_fds = array();
			$chunks = 0;
			$gather = static function ( array $paths, ?string $authority, array $sources ) use ( &$held, &$seen_fds, &$chunks, &$total,
				$parent, $child, $pid, $table, $mounts, $retain, $work_deadline, $persist, $pipes, $receive, $executable, $stream_limit ): void {
			self::guard( $parent, $child, $pid, $work_deadline, $retain );
			$before = array();
			foreach ( $paths as $path ) {
				self::require( Wstm108_KernelMountModel::path( $path ) === $path );
				$before[ $path ] = self::identity( $path );
			}
			$queue = array_values( array_diff( array_unique( $paths, SORT_STRING ), array_keys( $held ) ) );
			while ( array() !== $queue ) {
				self::require( ++$chunks <= 256 && count( $held ) + count( $queue ) <= 8192, 'mount-table-limit' );
				$batch = array_splice( $queue, 0, 32 );
				foreach ( $batch as $path ) { self::require( Wstm108_KernelMountModel::path( $path ) === $path ); }
				self::guard( $parent, $child, $pid, $work_deadline, $retain );
				self::require( $table === self::read( '/proc/self/mountinfo', 4194304 ), 'peer-or-topology-changed-during-admission' );
				$request = json_encode( array( 'paths' => $batch ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
				$total += strlen( $request );
				self::require( $total <= $stream_limit );
				$persist( 'input', $request, $work_deadline );
				self::write( $pipes[0], $request, min( $work_deadline, hrtime( true ) + 1000000000 ) );
				$response = $receive( true );
				self::require( array( 'opened' ) === array_keys( $response ) && is_array( $response['opened'] )
					&& count( $batch ) === count( $response['opened'] ) && array_keys( $response['opened'] ) === range( 0, count( $batch ) - 1 ) );
				self::guard( $parent, $child, $pid, $work_deadline, $retain );
				foreach ( $response['opened'] as $index => $row ) {
					self::require( is_array( $row ) && array( 'path', 'fd' ) === array_keys( $row )
						&& $batch[ $index ] === $row['path'] && is_int( $row['fd'] ) && ! isset( $seen_fds[ $row['fd'] ] ) );
					$seen_fds[ $row['fd'] ] = true;
					$identity = self::held( $pid, $row['fd'], $row['path'], $retain );
					$selected = self::row( $mounts, $row['path'], $identity );
					$held[ $row['path'] ] = array( 'fd' => $row['fd'], 'held' => $identity, 'row' => $selected );
					if ( ! isset( $held[ $selected['point'] ] ) && ! in_array( $selected['point'], $queue, true )
						&& ! in_array( $selected['point'], $batch, true ) ) { $queue[] = $selected['point']; }
				}
				self::require( $table === self::read( '/proc/self/mountinfo', 4194304 ), 'peer-or-topology-changed-during-admission' );
			}
			$validate = static function () use ( $held, $pid, $parent, $child, $table, $retain, $work_deadline ): void {
				self::guard( $parent, $child, $pid, $work_deadline, $retain );
				self::require( $table === self::read( '/proc/self/mountinfo', 4194304 ), 'peer-or-topology-changed-during-admission' );
				foreach ( $held as $path => $item ) {
					self::require( $item['held'] === self::held( $pid, $item['fd'], $path, $retain )
						&& isset( $held[ $item['row']['point'] ] )
						&& $item['held']['mount_id'] === $held[ $item['row']['point'] ]['held']['mount_id'], 'kernel-coordinate-or-identity-disagrees' );
				}
				self::guard( $parent, $child, $pid, $work_deadline, $retain );
			};
			$validate();
			foreach ( $before as $path => $identity ) {
				self::require( $identity === $held[ $path ]['held']['identity'], 'coordinate-path-changed' );
			}
			if ( null !== $authority ) {
				$coordinate = static function ( string $path ) use ( $held, $mounts, $parent, $child, $pid, $retain, $work_deadline, $table ): array {
					self::guard( $parent, $child, $pid, $work_deadline, $retain );
					self::require( $table === self::read( '/proc/self/mountinfo', 4194304 ), 'peer-or-topology-changed-during-admission' );
					self::require( isset( $held[ $path ] ), 'kernel-coordinate-or-identity-disagrees' );
					self::require( $held[ $path ]['held'] === self::held( $pid, $held[ $path ]['fd'], $path, $retain ), 'coordinate-path-changed' );
					return Wstm108_KernelMountModel::coordinate( $path, $mounts, $held[ $path ]['held']['mount_id'], $held[ $path ]['held']['device'] );
				};
				$protected = array( $coordinate( $authority ) );
				foreach ( $mounts as $mount ) {
					if ( Wstm108_KernelMountModel::contains( $authority, $mount['point'] ) ) { $protected[] = $coordinate( $mount['point'] ); }
				}
				foreach ( $sources as $source ) {
					$exposures = array( $source );
					$directory = 0040000 === ( $held[ $source ]['held']['identity']['mode'] & 0170000 );
					if ( $directory ) {
						foreach ( $mounts as $mount ) {
							if ( Wstm108_KernelMountModel::contains( $source, $mount['point'] ) ) { $exposures[] = $mount['point']; }
						}
					}
					foreach ( array_unique( $exposures, SORT_STRING ) as $path ) {
						$exposure = $coordinate( $path );
						foreach ( $protected as $owned ) {
							self::require( ! Wstm108_KernelMountModel::exposes( $owned, $exposure, $directory ), 'physical-bind-exposes-authority' );
						}
					}
				}
			}
			$validate();
			self::require( $executable === self::executable() );
			};
			$gather( $paths, $authority, $sources );
			$finish = static function () use ( &$complete, &$process, &$pipes, &$exit, &$eof, &$total, &$intent, &$files, $handles,
				&$held, $child, $pid, $cleanup, $deadline, $work_deadline, $receive, $retain, $persist, $hashes, $parent, $executable, $table, $intent_path, $argv, $custody, $stem ): void {
			try {
			self::require( ! self::$failed );
			self::guard( $parent, $child, $pid, $work_deadline, $retain );
			foreach ( $held as $path => $item ) {
				self::require( $item['held'] === self::held( $pid, $item['fd'], $path, $retain ), 'coordinate-path-changed' );
			}
			self::guard( $parent, $child, $pid, $work_deadline, $retain );
			self::require( $table === self::read( '/proc/self/mountinfo', 4194304 ), 'peer-or-topology-changed-during-admission' );
			$request = "{\"close\":1}\n";
			$persist( 'input', $request, $work_deadline );
			$total += strlen( $request );
			self::write( $pipes[0], $request, $work_deadline );
			self::require( array( 'closed' => 1 ) === $receive( true ) );
			fclose( $pipes[0] ); unset( $pipes[0] );
			$receive( false );
			do {
				$status = proc_get_status( $process );
				self::require( is_array( $status ) && hrtime( true ) < $work_deadline );
				if ( $status['running'] ) { usleep( 1000 ); }
			} while ( $status['running'] );
			$exit = $status['exitcode'];
			self::require( 0 === $exit );
			proc_close( $process ); $process = null;
			self::guard( $parent, null, 0, $work_deadline, $retain );
			self::require( $executable === self::executable() && $table === self::read( '/proc/self/mountinfo', 4194304 )
				&& $eof === array( 1 => true, 2 => true ) );
			foreach ( $handles as $suffix => $handle ) {
				self::require( fflush( $handle ) && fsync( $handle ) );
				$files[ $suffix ] = Wstm108_Files::read_bound( $custody['directory'] . '/' . $stem . '.' . $suffix . '.private', $files[ $suffix ]['identity'] );
				$stat = fstat( $handle );
				self::require( is_array( $stat ) && $stat['size'] === strlen( $files[ $suffix ]['bytes'] )
					&& $files[ $suffix ]['sha256'] === hash_final( hash_copy( $hashes[ $suffix ] ) )
					&& $files[ $suffix ]['identity'] === array_intersect_key( $stat,
						array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ) );
			}
			$record = array( 'version' => 1, 'state' => 'complete', 'exit' => $exit, 'eof' => $eof, 'argv' => $argv,
				'executable' => $executable, 'deadline' => $deadline, 'streams' => array_map( array( self::class, 'metadata' ), $files ) );
			$intent = Wstm108_Files::update( $intent_path, $intent, json_encode( $record, JSON_THROW_ON_ERROR ) );
			$total += strlen( $intent['bytes'] );
			self::sync( $intent_path, $intent );
			self::require( hrtime( true ) < $deadline && $total <= self::$remaining['bytes'] );
			self::record_completed_capture( $stem, $custody, $files, $intent );
			$complete = true;
			} finally { $complete = $cleanup( $complete ); }
		if ( $complete && hrtime( true ) >= $deadline ) {
			$complete = false;
		}
		self::require( $complete && hrtime( true ) < $deadline );
			};
			self::$session_table = $table;
			self::$collector = $gather;
			self::$finisher = $finish;
			$deferred = true;
		} finally {
			if ( ! $deferred ) {
				try { $cleanup( false ); }
				finally { self::$collector_deadline = 0; }
			}
		}
	}

	// Named owner identity is stable across PHP's version-dependent closure names.
	private static function record_completed_capture( string $stem, array $custody, array $files, array $intent ): void {
		self::$owner->kernel_record( $stem, $custody, $files, $intent );
	}

	private static function metadata( array $file ): array {
		return array( 'identity' => $file['identity'], 'sha256' => $file['sha256'], 'length' => strlen( $file['bytes'] ) );
	}

	private static function sync( string $path, array $file ): void {
		$handle = @fopen( $path, 'rb' );
		self::require( is_resource( $handle ) );
		try { self::require( fsync( $handle ) ); } finally { fclose( $handle ); }
		Wstm108_Files::assert_file( $path, $file );
	}
}
