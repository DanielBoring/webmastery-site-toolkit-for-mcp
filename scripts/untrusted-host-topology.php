<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-files.php';

final class Wstm108_TopologyRefusal extends RuntimeException {
	private string $reason;

	public function __construct( string $reason ) {
		$this->reason = $reason;
		parent::__construct( 'WSTM108 BLOCKED topology: ' . $reason, 78 );
		\Wstm108_AdmissionCallsite::record_creation( $this );
	}

	public function reason(): string {
		return $this->reason;
	}
}

/** Passive Linux evidence only; inaccessible or ambiguous topology is unsupported. */
final class Wstm108_HostTopology {
	public const REFUSAL_REASONS = array(
		'noncanonical-path', 'unknown-mount-escape', 'partial-mount-table', 'mount-table-limit',
		'malformed-mount-record', 'ambiguous-stacked-mount', 'unsupported-mount-mapping', 'missing-namespace-root',
		'unsupported-filesystem-coordinate', 'nonstring-bind-source', 'physical-bind-exposes-authority',
		'native-coordinate-prerequisite', 'coordinate-path-changed', 'unsupported-native-device',
		'kernel-coordinate-or-identity-disagrees', 'incomplete-directory-identity', 'unowned-child-creation-transition',
		'unreadable-kernel-evidence', 'unreadable-kernel-identity', 'unsupported-daemon-owner',
		'ambiguous-process-identity', 'missing-process-start-identity', 'ambiguous-selected-listener',
		'selected-listener-not-unique', 'daemon-descriptors-inaccessible', 'daemon-descriptor-race-or-denial',
		'pid-hint-does-not-own-selected-listener', 'daemon-mount-namespace-differs', 'daemon-identity-changed-during-read',
		'native-linux-prerequisite', 'nonlocal-endpoint', 'unrecognized-socket-alias', 'nonroot-or-nonsocket-endpoint',
		'untrusted-pid-discovery-hint', 'daemon-mount-table-differs', 'peer-or-topology-changed-during-admission',
		'invalid-host-inspection-mode',
	);

	public static function failure_witness( Throwable $error ): ?array {
		if ( ! $error instanceof Wstm108_TopologyRefusal || 78 !== $error->getCode()
			|| ! in_array( $error->reason(), self::REFUSAL_REASONS, true ) ) {
			return null;
		}
		return array( 'phase' => 'topology', 'reason' => $error->reason() );
	}

	private static function require( bool $condition, string $reason ): void {
		if ( ! $condition ) { throw new Wstm108_TopologyRefusal( $reason ); }
	}

	private static function path( string $path, ?array $mount_row = null ): string {
		$canonical = '' !== $path && '/' === $path[0] && false === strpos( $path, "\0" )
			&& ! preg_match( '/[\x00-\x1f\x7f]|\/\/|(?:^|\/)\.\.?(?:\/|$)/', $path );
		if ( ! $canonical ) { self::path_failure( $path, $mount_row ); } return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	private static function contains( string $parent, string $child ): bool {
		return '/' === $parent || $parent === $child || 0 === strpos( $child, $parent . '/' );
	}

	private static function unescape( string $value ): string {
		self::require( ! preg_match( '/\\\\(?!040|011|012|134)/', $value ), 'unknown-mount-escape' );
		return strtr( $value, array( '\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\' ) );
	}

	public static function mounts( string $bytes ): array {
		return self::parse_mounts( $bytes, false );
	}

	/** Syntax only: this result does not establish visibility or grant admission. */
	public static function structural_mounts( string $bytes ): array {
		return self::parse_mounts( $bytes, true );
	}

	private static function parse_mounts( string $bytes, bool $structural ): array {
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
			$decoded_root = self::unescape( $fields[3] ); $root = true === self::relative_root_match( $decoded_root, $fields ) ? $decoded_root : self::path( $decoded_root, $fields );
			$point = self::path( self::unescape( $fields[4] ) );
			self::require( $id > 0 && ( ! $structural || (string) $id === ltrim( $fields[0], '0' ) ) && ! isset( $records[ $id ] )
				&& ( $structural || ! isset( $points[ $point ] ) ), 'ambiguous-stacked-mount' );
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

	public static function stacked( array $mounts ): bool {
		$points = array();
		foreach ( $mounts as $mount ) {
			if ( isset( $points[ $mount['point'] ] ) ) { return true; }
			$points[ $mount['point'] ] = true;
		}
		return false;
	}

	public static function coordinate( string $path, array $mounts ): array {
		$path = self::path( $path );
		self::require( ! self::stacked( $mounts ), 'ambiguous-stacked-mount' );
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

	/** Classify only canonical-path rows; connected streams are not listening inodes. */
	public static function selected_listener( array $rows ): string {
		$listeners = array();
		$inodes = array();
		foreach ( $rows as $columns ) {
			self::require( is_array( $columns ) && array_keys( $columns ) === range( 0, 7 )
				&& count( array_filter( $columns, 'is_string' ) ) === 8
				&& 1 === preg_match( '/^(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{16}):$/D', $columns[0] )
				&& 1 === preg_match( '/^[0-9a-fA-F]{8}$/D', $columns[1] ) && hexdec( $columns[1] ) > 0
				&& '00000000' === $columns[2] && '0001' === $columns[4]
				&& 1 === preg_match( '/^[1-9][0-9]{0,19}$/D', $columns[6] )
				&& ( strlen( $columns[6] ) < 20 || strcmp( $columns[6], '18446744073709551615' ) <= 0 )
				&& ! isset( $inodes[ $columns[6] ] )
				&& in_array( $columns[7], array( '/run/docker.sock', '/var/run/docker.sock' ), true )
				&& ( ( '00010000' === $columns[3] && '01' === $columns[5] )
					|| ( '00000000' === $columns[3] && '03' === $columns[5] ) ), 'ambiguous-selected-listener' );
			$inodes[ $columns[6] ] = true;
			if ( '00010000' === $columns[3] ) { $listeners[] = $columns[6]; }
		}
		self::require( 1 === count( $listeners ), 'selected-listener-not-unique' );
		return $listeners[0];
	}

	private static function peer( string $socket, int $pid ): array {
		$process = '/proc/' . $pid;
		$identity = self::stat( $process );
		self::require( 0 === $identity['uid'], 'unsupported-daemon-owner' );
		$stat = self::read( $process . '/stat', 65536 );
		self::require( 1 === preg_match( '/^([0-9]+) \(.+\) (.+)\n?$/D', $stat, $matches ) && (int) $matches[1] === $pid, 'ambiguous-process-identity' );
		$fields = explode( ' ', rtrim( $matches[2], "\n" ) );
		self::require( isset( $fields[19] ) && ctype_digit( $fields[19] ), 'missing-process-start-identity' );
		$rows = array();
		foreach ( explode( "\n", self::read( '/proc/net/unix', 4194304 ) ) as $line ) {
			$columns = preg_split( '/\s+/', trim( $line ) );
			$aliases = array_intersect( array( '/run/docker.sock', '/var/run/docker.sock' ), $columns );
			if ( array() === $aliases || realpath( reset( $aliases ) ) !== $socket ) { continue; }
			$rows[] = $columns;
		}
		$listener = array( self::selected_listener( $rows ) );
		if ( Wstm108_HostObservation::enabled( getenv() ) ) {
			$links = Wstm108_HostObservation::descriptors( Wstm108_HostObservation::read( 'descriptors', $pid ) );
			$matched = array();
			foreach ( $links as $descriptor => $link ) {
				if ( 'socket:[' . $listener[0] . ']' === $link ) { $matched[] = (string) $descriptor; }
			}
		} else {
		$descriptors = @scandir( $process . '/fd' );
		self::require( is_array( $descriptors ) && count( $descriptors ) <= 8192, 'daemon-descriptors-inaccessible' );
		$matched = array();
		foreach ( $descriptors as $descriptor ) {
			if ( ! ctype_digit( $descriptor ) ) { continue; }
			$link = @readlink( $process . '/fd/' . $descriptor );
			self::require( is_string( $link ), 'daemon-descriptor-race-or-denial' );
			if ( 'socket:[' . $listener[0] . ']' === $link ) { $matched[] = $descriptor; }
		}
		}
		self::require( array() !== $matched, 'pid-hint-does-not-own-selected-listener' );
		$namespace = Wstm108_HostObservation::enabled( getenv() )
			? Wstm108_HostObservation::namespace_identity( Wstm108_HostObservation::read( 'namespace', $pid ) )
			: self::stat( $process . '/ns/mnt' );
		$self_namespace = self::stat( '/proc/self/ns/mnt' );
		self::require( $namespace['dev'] === $self_namespace['dev'] && $namespace['ino'] === $self_namespace['ino'], 'daemon-mount-namespace-differs' );
		self::require( $identity === self::stat( $process ), 'daemon-identity-changed-during-read' );
		return array( 'pid' => $pid, 'identity' => $identity, 'start_ticks' => $fields[19],
			'listener_inode' => $listener[0], 'listener_fds' => $matched, 'mount_namespace' => $namespace );
	}

	public static function admit( string $endpoint ): array {
		require_once __DIR__ . '/untrusted-host-observation.php';
		Wstm108_HostObservation::enabled( getenv() );
		Wstm108_HostObservation::begin_pass();
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
		self::require( $pid > 0 && $pid <= 2147483647, 'untrusted-pid-discovery-hint' );
		$before = self::peer( $socket, $pid );
		$mountinfo = self::read( '/proc/self/mountinfo', 4194304 );
		$daemon_mountinfo = Wstm108_HostObservation::enabled( getenv() )
			? Wstm108_HostObservation::read( 'mountinfo', $pid ) : self::read( '/proc/' . $pid . '/mountinfo', 4194304 );
		self::require( $mountinfo === $daemon_mountinfo, 'daemon-mount-table-differs' );
		$mounts = self::structural_mounts( $mountinfo );
		if ( self::stacked( $mounts ) ) {
			self::require( Wstm108_HostObservation::enabled( getenv() ), 'ambiguous-stacked-mount' );
			require_once __DIR__ . '/untrusted-kernel-mounts.php';
			\Wstm108_KernelMounts::visibility( $mountinfo );
		}
		self::require( $before === self::peer( $socket, $pid ) && $socket_before === self::stat( $socket )
			&& $mountinfo === self::read( '/proc/self/mountinfo', 4194304 ), 'peer-or-topology-changed-during-admission' );
		if ( Wstm108_HostObservation::enabled( getenv() ) ) {
			self::require( $daemon_mountinfo === Wstm108_HostObservation::read( 'mountinfo', $pid ), 'peer-or-topology-changed-during-admission' );
		}
		Wstm108_Files::assert_file( '/run/docker.pid', $hint );
		return array( 'endpoint' => $endpoint, 'socket_identity' => $socket_before, 'peer' => $before,
			'mountinfo_sha256' => hash( 'sha256', $mountinfo ), 'mounts' => $mounts );
	}

	/** Failure-only metadata; the original predicate alone decides acceptance. */
	private static function path_failure( string $path, ?array $mount_row = null ): void {
		if ( '' === $path ) { self::require( false, 'noncanonical-path' ); }
		if ( '/' !== $path[0] ) { self::relative_root_failure( $path, $mount_row ); }
		if ( false !== strpos( $path, "\0" ) ) { self::require( false, 'noncanonical-path' ); }
		$control = preg_match( '/[\x00-\x1f\x7f]/', $path );
		$slash = preg_match( '/\/\//', $path );
		$dot = preg_match( '/(?:^|\/)\.\.?(?:\/|$)/', $path );
		if ( in_array( $control, array( 0, 1 ), true ) && in_array( $slash, array( 0, 1 ), true )
			&& in_array( $dot, array( 0, 1 ), true ) ) {
			switch ( $control + 2 * $slash + 4 * $dot ) {
				case 1: self::require( false, 'noncanonical-path' ); break;
				case 2: self::require( false, 'noncanonical-path' ); break;
				case 3: self::require( false, 'noncanonical-path' ); break;
				case 4: self::require( false, 'noncanonical-path' ); break;
				case 5: self::require( false, 'noncanonical-path' ); break;
				case 6: self::require( false, 'noncanonical-path' ); break;
				case 7: self::require( false, 'noncanonical-path' ); break;
			}
		}
		self::require( false, 'noncanonical-path' );
	}

	/** Only original row data is examined; every outcome remains a refusal. */
	private static function relative_root_failure( string $path, ?array $row ): void {
		$recognized = self::relative_root_match( $path, $row );
		if ( true === $recognized ) { self::require( false, 'noncanonical-path' ); }
		if ( false === $recognized ) { self::require( false, 'noncanonical-path' ); }
		self::require( false, 'noncanonical-path' );
	}

	/** Exact reviewed kernel metadata, never a physical or caller lookup path. */
	private static function relative_root_match( string $path, ?array $row ): ?bool {
		$separator = null === $row ? false : array_search( '-', $row, true );
		$known = null !== $row && is_int( $separator ) && $separator >= 6 && count( $row ) === $separator + 4
			&& is_string( $row[3] ?? null ) && is_string( $row[ $separator + 1 ] ?? null ) && '' !== $row[ $separator + 1 ] && 0 === preg_match( '/[\x00-\x20\x7f]/', $row[ $separator + 1 ] )
			&& $path === strtr( $row[3], array( '\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\' ) );
		$recognized = null;
		if ( $known ) {
			if ( 'nsfs' !== $row[ $separator + 1 ] ) { $recognized = false; }
			else {
				$matched = preg_match( '/\Anet:\[([1-9][0-9]{0,9})\]\z/D', $path, $match );
				if ( 0 === $matched ) { $recognized = false; }
				elseif ( 1 === $matched ) {
					$recognized = strlen( $match[1] ) < 10 || strcmp( $match[1], '4294967295' ) <= 0;
				}
			}
		}
		return $recognized;
	}
}

/** Passive finite diagnostics, inside the existing source-provenance closure. */
final class Wstm108_AdmissionCallsite {
	public const MAX_SCALAR_BYTES = 32;
	public const MAX_TERMINAL_BYTES = 256;
	public const IDS = array(
		'unknown', 'mount-root', 'mount-point', 'legacy-input', 'legacy-physical', 'legacy-source',
		'mount-root-empty', 'mount-root-relative', 'mount-root-nul',
		'mount-root-rx-c', 'mount-root-rx-s', 'mount-root-rx-d', 'mount-root-rx-cs',
		'mount-root-rx-cd', 'mount-root-rx-sd', 'mount-root-rx-csd',
		'mount-root-net-true', 'mount-root-net-false', 'mount-root-net-unknown',
		'kernel-selected-length', 'kernel-selected-canonical',
		'kernel-input-length', 'kernel-input-canonical',
		'kernel-physical-length', 'kernel-physical-canonical',
		'kernel-source-length', 'kernel-source-canonical',
		'kernel-held-length', 'kernel-held-canonical',
		'kernel-path-length', 'kernel-path-canonical',
		'kernel-chunk-length', 'kernel-chunk-canonical',
	);
	public const PREREQUISITE_IDS = array(
		'pr-host-native', 'pr-exec-schema', 'pr-exec-process', 'pr-exec-creds', 'pr-exec-caps', 'pr-exec-lane',
		'pr-deadline-input', 'pr-deadline-window',
		'pr-pass-owner', 'pr-entry-owner', 'pr-pause-owner', 'pr-reserve-state', 'pr-launch-owner', 'pr-launch-phase',
		'pr-storage-call', 'pr-active-scope', 'pr-python-path', 'pr-exec-files', 'pr-holder-file',
		'pr-collect-prereq', 'pr-stream-budget', 'pr-parent-coherence', 'pr-child-status', 'pr-child-executable',
		'pr-mount-serialize', 'pr-mount-unserialize', 'pr-own-serialize', 'pr-own-unserialize',
		'pr-own-fd5', 'pr-own-fd5-meta', 'pr-own-fd5-bytes', 'pr-own-record', 'pr-own-parent-proc', 'pr-own-parent-start',
		'pr-own-namespace', 'pr-own-status-bytes', 'pr-own-status-pid', 'pr-own-fdinfo', 'pr-own-file-link',
		'pr-own-file-bytes', 'pr-own-fd-scan', 'pr-own-linked', 'pr-own-allowance', 'pr-own-attach',
		'pr-own-deadline', 'pr-own-query-slot', 'pr-own-custody', 'pr-own-record-custody',
	);
	private const PREREQUISITE_GUARDS = array(
		'Wstm108_HostTopology' => array(
			'file' => 'untrusted-host-topology.php',
			'guards' => array( 146 => array( 'pr-host-native', 'native_coordinates' ) ),
		),
		'Wstm108_KernelMountModel' => array(
			'file' => 'untrusted-kernel-mount-model.php',
			'guards' => array(
				52 => array( 'pr-exec-schema', 'safe_exec' ), 54 => array( 'pr-exec-process', 'safe_exec' ),
				57 => array( 'pr-exec-creds', 'safe_exec' ), 60 => array( 'pr-exec-caps', 'safe_exec' ),
				62 => array( 'pr-exec-lane', 'safe_exec' ), 85 => array( 'pr-deadline-input', 'deadline' ),
				88 => array( 'pr-deadline-window', 'deadline' ),
			),
		),
		'Wstm108_KernelMounts' => array(
			'file' => 'untrusted-kernel-mounts.php',
			'guards' => array(
				36 => array( 'pr-pass-owner', 'controller_pass' ), 57 => array( 'pr-entry-owner', 'authority_entry' ),
				66 => array( 'pr-pause-owner', 'pause_for_capture' ), 77 => array( 'pr-reserve-state', 'reserve_helper' ),
				97 => array( 'pr-launch-owner', 'consume_launch' ), 102 => array( 'pr-launch-phase', 'consume_launch' ),
				110 => array( 'pr-storage-call', 'storage_interval' ), 149 => array( 'pr-active-scope', 'existing_deadline' ),
				239 => array( 'pr-python-path', 'executable' ), 244 => array( 'pr-exec-files', 'executable' ),
				253 => array( 'pr-holder-file', 'executable' ), 312 => array( 'pr-collect-prereq', 'collect' ),
				317 => array( 'pr-stream-budget', 'collect' ), 414 => array( 'pr-parent-coherence', 'collect' ),
				470 => array( 'pr-child-status', 'collect' ), 473 => array( 'pr-child-executable', 'collect' ),
			),
			'direct' => array( 26 => array( 'pr-mount-serialize', '__serialize' ), 27 => array( 'pr-mount-unserialize', '__unserialize' ) ),
		),
		'Wstm108_KernelAuthorityOwner' => array(
			'file' => 'untrusted-kernel-authority-owner.php',
			'guards' => array(
				26 => array( 'pr-own-fd5', 'inherited' ), 29 => array( 'pr-own-fd5-meta', 'inherited' ),
				32 => array( 'pr-own-fd5-bytes', 'inherited' ), 34 => array( 'pr-own-record', 'inherited' ),
				44 => array( 'pr-own-parent-proc', 'inherited' ), 47 => array( 'pr-own-parent-start', 'inherited' ),
				52 => array( 'pr-own-namespace', 'inherited' ), 56 => array( 'pr-own-status-bytes', 'inherited' ),
				59 => array( 'pr-own-status-pid', 'inherited' ), 61 => array( 'pr-own-fdinfo', 'inherited' ),
				65 => array( 'pr-own-file-link', 'inherited' ), 68 => array( 'pr-own-file-bytes', 'inherited' ),
				71 => array( 'pr-own-fd-scan', 'inherited' ), 79 => array( 'pr-own-linked', 'inherited' ),
				81 => array( 'pr-own-allowance', 'inherited' ), 93 => array( 'pr-own-attach', 'attach' ),
				103 => array( 'pr-own-deadline', 'kernel_query_deadline' ), 108 => array( 'pr-own-query-slot', 'kernel_query_slot' ),
				112 => array( 'pr-own-custody', 'kernel_custody' ), 118 => array( 'pr-own-record-custody', 'kernel_record' ),
			),
			'direct' => array( 17 => array( 'pr-own-serialize', '__serialize' ), 18 => array( 'pr-own-unserialize', '__unserialize' ) ),
		),
	);
	// PHP8.0 attributes a call to its closing token; later engines use its opening token.
	private const PHP80_GUARD_LINES = array(
		'Wstm108_HostTopology' => array( 146 => 146 ),
		'Wstm108_KernelMountModel' => array( 52 => 53, 54 => 55, 57 => 58, 60 => 60, 62 => 62, 85 => 86, 88 => 88 ),
		'Wstm108_KernelMounts' => array(
			36 => 37, 57 => 57, 66 => 68, 77 => 78, 97 => 97, 102 => 103, 110 => 113, 149 => 150,
			239 => 239, 244 => 247, 253 => 255, 312 => 314, 317 => 317, 414 => 415, 470 => 470, 473 => 475,
		),
		'Wstm108_KernelAuthorityOwner' => array(
			26 => 26, 29 => 30, 32 => 32, 34 => 42, 44 => 45, 47 => 47, 52 => 54, 56 => 56, 59 => 59,
			61 => 63, 65 => 66, 68 => 68, 71 => 71, 79 => 79, 81 => 81, 93 => 93, 103 => 103,
			108 => 108, 112 => 113, 118 => 118,
		),
	);
	private const PREREQUISITE_CALLERS = array(
		'Wstm108_HostTopology' => array(
			'native_coordinates' => array( 'untrusted-authority.php' => array( 47 ) ),
		),
		'Wstm108_KernelMountModel' => array(
			'safe_exec' => array( 'untrusted-kernel-mounts.php' => array( 413, 468 ), 'untrusted-kernel-authority-owner.php' => array( 58 ) ),
			'deadline' => array( 'untrusted-kernel-mounts.php' => array( 311 ) ),
		),
		'Wstm108_KernelMounts' => array(
			'controller_pass' => array( 'untrusted-host-controller.php' => array( 387 ) ),
			'authority_entry' => array( 'untrusted-authority.php' => array( 472 ) ),
			'pause_for_capture' => array( 'untrusted-host-controller.php' => array( 472 ), 'untrusted-authority.php' => array( 196 ) ),
			'reserve_helper' => array( 'untrusted-host-controller.php' => array( 439 ) ),
			'consume_launch' => array( 'untrusted-kernel-mounts.php' => array( 319 ) ),
			'storage_interval' => array( 'untrusted-host-controller.php' => array( 469, 524, 606 ) ),
			'existing_deadline' => array( 'untrusted-kernel-mounts.php' => array( 166, 311 ) ),
			'executable' => array( 'untrusted-kernel-mounts.php' => array( 416, 424, 558, 587 ) ),
			'collect' => array( 'untrusted-kernel-mounts.php' => array( 162, 188 ) ),
		),
		'Wstm108_KernelAuthorityOwner' => array(
			'inherited' => array( 'untrusted-kernel-mounts.php' => array( 58 ) ),
			'attach' => array( 'untrusted-kernel-mounts.php' => array( 52 ) ),
			'kernel_query_deadline' => array( 'untrusted-kernel-mounts.php' => array( 40, 151 ) ),
			'kernel_query_slot' => array( 'untrusted-kernel-mounts.php' => array( 88, 91, 104 ) ),
			'kernel_custody' => array( 'untrusted-kernel-mounts.php' => array( 320 ), 'untrusted-kernel-authority-owner.php' => array( 83, 118 ) ),
			'kernel_record' => array( 'untrusted-kernel-mounts.php' => array( 626 ) ),
		),
	);
	private const ENTRY_LINE = 756;
	private const BIRTH_LINE = 13;
	private const HOST_FAILURE_CALL_LINE = 53;
	private const HOST_FAILURE_GUARDS = array(
		302 => 'mount-root-empty', 304 => 'mount-root-nul',
		311 => 'mount-root-rx-c', 312 => 'mount-root-rx-s', 313 => 'mount-root-rx-cs',
		314 => 'mount-root-rx-d', 315 => 'mount-root-rx-cd', 316 => 'mount-root-rx-sd',
		317 => 'mount-root-rx-csd', 320 => 'unknown',
	);
	private const RELATIVE_FAILURE_CALL_LINE = 303;
	private const RELATIVE_FAILURE_GUARDS = array(
		326 => 'mount-root-net-true', 327 => 'mount-root-net-false', 328 => 'mount-root-net-unknown',
	);
	private const MODEL_LENGTH_LINE = 107;
	private const MODEL_CANONICAL_LINE = 108;
	private const PHP80_PATH_GUARD_LINES = array(
		'Wstm108_HostTopology' => array( 302 => 302, 304 => 304, 311 => 311, 312 => 312,
			313 => 313, 314 => 314, 315 => 315, 316 => 316, 317 => 317, 320 => 320,
			326 => 326, 327 => 327, 328 => 328 ),
		'Wstm108_KernelMountModel' => array( 107 => 107, 108 => 109 ),
	);
	private const HOST_CALLERS = array(
		87 => array( 'mount-root', 'parse_mounts' ),
		88 => array( 'mount-point', 'parse_mounts' ),
		112 => array( 'legacy-input', 'coordinate' ),
		124 => array( 'legacy-physical', 'coordinate' ),
		131 => array( 'legacy-source', 'outside' ),
	);
	private const MODEL_CALLERS = array(
		93 => array( 'kernel-selected', 'selected_row' ),
		124 => array( 'kernel-input', 'coordinate' ),
		128 => array( 'kernel-physical', 'coordinate' ),
	);
	private const KERNEL_CALLERS = array(
		175 => array( 'kernel-source', 'outside' ),
		273 => array( 'kernel-held', 'held' ),
		484 => array( 'kernel-path', array( '{closure}', '{closure:Wstm108_KernelMounts::collect():479}' ) ),
		491 => array( 'kernel-chunk', array( '{closure}', '{closure:Wstm108_KernelMounts::collect():479}' ) ),
	);
	private static bool $attempted = false;
	private static bool $enabled = false;
	private static ?WeakMap $births = null;

	private function __construct() {}

	private static function file( string $name ): string {
		return str_replace( '\\', '/', __DIR__ ) . '/' . $name;
	}

	public static function allows( string $reason, string $site ): bool {
		if ( 'unknown' === $site ) { return true; }
		return ( 'noncanonical-path' === $reason && in_array( $site, self::IDS, true ) )
			|| ( 'native-coordinate-prerequisite' === $reason && in_array( $site, self::PREREQUISITE_IDS, true ) );
	}

	/** Only the original controller CLI entry may set the process-local INI once. */
	public static function initialize(): void {
		try {
			$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
			if ( self::$attempted || 1 !== count( $frames )
				|| str_replace( '\\', '/', $frames[0]['file'] ?? '' ) !== self::file( 'untrusted-host-controller.php' )
				|| ( $frames[0]['line'] ?? null ) !== self::ENTRY_LINE ) { return; }
			self::$attempted = true;
			self::$enabled = false !== ini_set( 'zend.exception_ignore_args', '1' )
				&& '1' === ini_get( 'zend.exception_ignore_args' );
		} catch ( Throwable $error ) { self::$enabled = false; }
	}

	/** Weak identity custody proves creation under the verified argument-free entry. */
	public static function record_creation( Throwable $error ): void {
		if ( ! self::$enabled || ! $error instanceof Wstm108_TopologyRefusal
			|| '1' !== ini_get( 'zend.exception_ignore_args' )
			|| ! in_array( $error->reason(), array( 'noncanonical-path', 'native-coordinate-prerequisite' ), true )
			|| 78 !== $error->getCode() ) { return; }
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
		if ( str_replace( '\\', '/', $frames[0]['file'] ?? '' ) !== self::file( 'untrusted-host-topology.php' )
			|| ( $frames[0]['line'] ?? null ) !== self::BIRTH_LINE
			|| ( $frames[1]['class'] ?? null ) !== 'Wstm108_TopologyRefusal'
			|| ( $frames[1]['function'] ?? null ) !== '__construct' ) { return; }
		self::$births = self::$births ?? new WeakMap();
		if ( isset( self::$births[ $error ] ) ) { return; }
		try {
			$trace = $error->getTrace();
			$valid = count( $trace ) >= 1 && count( $trace ) <= 64;
			foreach ( $trace as $frame ) {
				if ( ! $valid || ! is_array( $frame ) || array_key_exists( 'args', $frame )
					|| array_key_exists( 'object', $frame ) ) { $valid = false; break; }
			}
			$site = $valid ? self::native_site( $error ) : 'unknown';
			self::$births[ $error ] = array( 'site' => $site, 'trace' => 'unknown' === $site ? null : $trace,
				'reason' => $error->reason(), 'origin' => array( $error->getFile(), $error->getLine() ) );
		} catch ( Throwable $diagnostic_error ) {
			self::$births[ $error ] = array( 'site' => 'unknown', 'trace' => null );
		}
	}

	public static function identify( Throwable $error ): string {
		if ( ! self::$enabled || '1' !== ini_get( 'zend.exception_ignore_args' )
			|| ! $error instanceof Wstm108_TopologyRefusal || 78 !== $error->getCode()
			|| ! in_array( $error->reason(), array( 'noncanonical-path', 'native-coordinate-prerequisite' ), true )
			|| null === self::$births || ! isset( self::$births[ $error ] ) ) { return 'unknown'; }
		$birth = self::$births[ $error ];
		if ( 'unknown' === $birth['site'] || $birth['reason'] !== $error->reason() ) { return 'unknown'; }
		try {
			if ( $error->getTrace() !== $birth['trace']
				|| array( $error->getFile(), $error->getLine() ) !== $birth['origin'] ) { return 'unknown'; }
			$site = self::native_site( $error );
			return $site === $birth['site'] ? $site : 'unknown';
		} catch ( Throwable $diagnostic_error ) { return 'unknown'; }
	}

	private static function native_site( Wstm108_TopologyRefusal $error ): string {
		if ( 'native-coordinate-prerequisite' === $error->reason() ) { return self::prerequisite_site( $error ); }
		// Exception::getTrace has no ignore-args option. Entry and birth checks precede it.
		$trace = $error->getTrace();
		if ( count( $trace ) < 3 || count( $trace ) > 64 ) { return 'unknown'; }
		foreach ( array_slice( $trace, 0, 3 ) as $frame ) {
			if ( ! is_array( $frame ) || array_key_exists( 'args', $frame ) || array_key_exists( 'object', $frame )
				|| ! isset( $frame['class'], $frame['function'] )
				|| ! is_string( $frame['class'] ) || ! is_string( $frame['function'] ) ) { return 'unknown'; }
		}
		$guard = $trace[0]; $caller = $trace[1];
		foreach ( array( $guard, $caller ) as $frame ) {
			if ( ! isset( $frame['file'], $frame['line'] ) || ! is_string( $frame['file'] )
				|| ! is_int( $frame['line'] ) ) { return 'unknown'; }
		}
		$guard['file'] = str_replace( '\\', '/', $guard['file'] );
		$caller['file'] = str_replace( '\\', '/', $caller['file'] );
		if ( 'require' !== $guard['function'] || $guard['class'] !== $caller['class'] ) { return 'unknown'; }
		$line = self::compiled_guard_line( $guard['class'], $guard['line'], PHP_VERSION_ID );
		if ( null === $line ) { return 'unknown'; }
		$guard['line'] = $line;
		if ( 'Wstm108_HostTopology' === $guard['class'] ) {
			if ( 'relative_root_failure' === $caller['function'] ) {
				return self::relative_site( $trace, $guard, $caller );
			}
			if ( $guard['file'] !== self::file( 'untrusted-host-topology.php' )
				|| ! array_key_exists( $guard['line'], self::HOST_FAILURE_GUARDS )
				|| 'path_failure' !== $caller['function'] || $caller['line'] !== self::HOST_FAILURE_CALL_LINE
				|| $caller['file'] !== self::file( 'untrusted-host-topology.php' ) || count( $trace ) < 4 ) { return 'unknown'; }
			$path = $trace[2]; $owner = $trace[3];
			if ( 'Wstm108_HostTopology' !== $path['class'] || 'path' !== $path['function']
				|| ! is_string( $path['file'] ?? null ) || str_replace( '\\', '/', $path['file'] ) !== self::file( 'untrusted-host-topology.php' )
				|| ! is_int( $path['line'] ?? null )
				|| ! is_array( $owner ) || array_key_exists( 'args', $owner ) || array_key_exists( 'object', $owner )
				|| ! isset( $owner['class'], $owner['function'] ) ) { return 'unknown'; }
			$binding = self::HOST_CALLERS[ $path['line'] ] ?? null;
			if ( ! self::owner( $owner, 'Wstm108_HostTopology', $binding ) ) { return 'unknown'; }
			return 'mount-root' === $binding[0] ? self::HOST_FAILURE_GUARDS[ $guard['line'] ] : $binding[0];
		}
		if ( 'path' !== $caller['function'] || 'Wstm108_KernelMountModel' !== $guard['class']
			|| $guard['file'] !== self::file( 'untrusted-kernel-mount-model.php' ) ) { return 'unknown'; }
		$kind = self::MODEL_LENGTH_LINE === $guard['line'] ? 'length'
			: ( self::MODEL_CANONICAL_LINE === $guard['line'] ? 'canonical' : null );
		if ( null === $kind ) { return 'unknown'; }
		$binding = null; $owner = '';
		if ( $caller['file'] === self::file( 'untrusted-kernel-mount-model.php' ) ) {
			$binding = self::MODEL_CALLERS[ $caller['line'] ] ?? null;
			$owner = 'Wstm108_KernelMountModel';
		} elseif ( $caller['file'] === self::file( 'untrusted-kernel-mounts.php' ) ) {
			$binding = self::KERNEL_CALLERS[ $caller['line'] ] ?? null;
			$owner = 'Wstm108_KernelMounts';
		}
		$site = self::owner( $trace[2], $owner, $binding ) ? $binding[0] . '-' . $kind : 'unknown';
		return in_array( $site, self::IDS, true ) ? $site : 'unknown';
	}

	private static function relative_site( array $trace, array $guard, array $caller ): string {
		$file = self::file( 'untrusted-host-topology.php' );
		if ( count( $trace ) < 5 || $guard['file'] !== $file || $caller['file'] !== $file
			|| ! array_key_exists( $guard['line'], self::RELATIVE_FAILURE_GUARDS )
			|| $caller['line'] !== self::RELATIVE_FAILURE_CALL_LINE ) { return 'unknown'; }
		$failure = $trace[2]; $path = $trace[3]; $owner = $trace[4];
		foreach ( array( $failure, $path, $owner ) as $frame ) {
			if ( ! is_array( $frame ) || array_key_exists( 'args', $frame ) || array_key_exists( 'object', $frame )
				|| ( $frame['class'] ?? null ) !== 'Wstm108_HostTopology' || ! is_string( $frame['function'] ?? null ) ) { return 'unknown'; }
		}
		if ( 'path_failure' !== $failure['function'] || ( $failure['line'] ?? null ) !== self::HOST_FAILURE_CALL_LINE
			|| ! is_string( $failure['file'] ?? null ) || str_replace( '\\', '/', $failure['file'] ) !== $file
			|| 'path' !== $path['function'] || ! is_int( $path['line'] ?? null )
			|| ! is_string( $path['file'] ?? null ) || str_replace( '\\', '/', $path['file'] ) !== $file ) { return 'unknown'; }
		$binding = self::HOST_CALLERS[ $path['line'] ] ?? null;
		if ( ! self::owner( $owner, 'Wstm108_HostTopology', $binding ) ) { return 'unknown'; }
		return 'mount-root' === $binding[0] ? self::RELATIVE_FAILURE_GUARDS[ $guard['line'] ] : $binding[0];
	}

	private static function owner( array $frame, string $class, ?array $binding ): bool {
		return null !== $binding && $frame['class'] === $class
			&& in_array( $frame['function'], (array) $binding[1], true );
	}

	private static function compiled_guard_line( string $class, int $line, int $version ): ?int {
		if ( $version < 80000 ) { return null; }
		if ( $version >= 80100 ) { return $line; }
		$coordinates = ( self::PHP80_GUARD_LINES[ $class ] ?? array() )
			+ ( self::PHP80_PATH_GUARD_LINES[ $class ] ?? array() );
		$opening = array_search( $line, $coordinates, true );
		return false === $opening ? null : $opening;
	}

	private static function prerequisite_site( Wstm108_TopologyRefusal $error ): string {
		$trace = $error->getTrace();
		if ( count( $trace ) < 1 || count( $trace ) > 64 ) { return 'unknown'; }
		$guard = $trace[0];
		if ( ! isset( $guard['class'], $guard['function'], $guard['file'], $guard['line'] )
			|| ! is_string( $guard['class'] ) || ! is_string( $guard['function'] )
			|| ! is_string( $guard['file'] ) || ! is_int( $guard['line'] )
			|| array_key_exists( 'args', $guard ) || array_key_exists( 'object', $guard ) ) { return 'unknown'; }
		$table = self::PREREQUISITE_GUARDS[ $guard['class'] ] ?? null;
		if ( null === $table ) { return 'unknown'; }
		$file = self::file( $table['file'] );
		if ( 'require' === $guard['function'] ) {
			if ( str_replace( '\\', '/', $guard['file'] ) !== $file || count( $trace ) < 2 ) { return 'unknown'; }
			$line = self::compiled_guard_line( $guard['class'], $guard['line'], PHP_VERSION_ID );
			$binding = null === $line ? null : ( $table['guards'][ $line ] ?? null );
			$caller = $trace[1];
			if ( null === $binding || ( $caller['class'] ?? null ) !== $guard['class']
				|| ( $caller['function'] ?? null ) !== $binding[1]
				|| array_key_exists( 'args', $caller ) || array_key_exists( 'object', $caller ) ) { return 'unknown'; }
		} else {
			if ( str_replace( '\\', '/', $error->getFile() ) !== $file ) { return 'unknown'; }
			$binding = $table['direct'][ $error->getLine() ] ?? null;
			if ( null === $binding || $guard['function'] !== $binding[1] ) { return 'unknown'; }
			$caller = $guard;
		}
		if ( ! isset( $caller['file'], $caller['line'] ) || ! is_string( $caller['file'] )
			|| ! is_int( $caller['line'] ) || $caller['line'] <= 0 ) { return 'unknown'; }
		$caller_file = str_replace( '\\', '/', $caller['file'] );
		foreach ( self::PREREQUISITE_CALLERS[ $guard['class'] ][ $binding[1] ] ?? array() as $name => $lines ) {
			if ( $caller_file === self::file( $name ) && in_array( $caller['line'], $lines, true ) ) { return $binding[0]; }
		}
		return 'unknown';
	}
}
