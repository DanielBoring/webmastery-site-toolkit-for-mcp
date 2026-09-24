<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-files.php';
require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-plan.php';
require_once __DIR__ . '/untrusted-release.php';
require_once __DIR__ . '/untrusted-host-topology.php';
require_once __DIR__ . '/untrusted-host-controller.php';

/**
 * Host-only authority. Its receipt is recovery evidence, never an automatic
 * replacement for the independently retained in-memory handle.
 */
final class Wstm108_HostAuthority {
	private array $handle;

	private function __construct( array $handle ) {
		$this->handle = $handle;
	}

	private static function require( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException( 'WSTM108 host authority: ' . $message ); }
	}

	private static function normalized( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	private static function contains( string $parent, string $child ): bool {
		return $parent === $child || 0 === strpos( $child, $parent . '/' );
	}

	public static function outside( string $authority, array $sources, array $topology ): void {
		Wstm108_Files::directory( $authority );
		foreach ( $sources as $source ) {
			self::require( is_string( $source ) && '' !== $source && false === strpos( $source, "\0" ), 'missing or ambiguous bind source.' );
			$canonical = realpath( $source );
			self::require( false !== $canonical && self::normalized( $canonical ) === self::normalized( $source ), 'noncanonical, inaccessible or unmapped bind source.' );
			Wstm108_Files::directory( is_dir( $source ) ? $source : dirname( $source ) );
			self::require( ! is_link( $source ) && ! self::contains( self::normalized( $source ), self::normalized( $authority ) ), 'authority overlaps an effective bind source.' );
		}
		Wstm108_HostTopology::native_coordinates( array_merge( array( $authority ), $sources ), $topology['mounts'] );
		Wstm108_HostTopology::outside( $authority, $sources, $topology['mounts'] );
	}

	private static function private_directory( string $path ): array {
		self::require( function_exists( 'posix_geteuid' ) && 'Windows' !== PHP_OS_FAMILY, 'native POSIX ownership is required; Windows ACL/daemon aliases are not inferred.' );
		$identity = Wstm108_Files::directory( $path );
		self::require( $identity['uid'] === posix_geteuid() && 0700 === ( $identity['mode'] & 0777 ), 'foreign or nonprivate owner directory.' );
		return $identity;
	}

	private static function metadata( array $file ): array {
		return array( 'identity' => $file['identity'], 'sha256' => $file['sha256'], 'length' => strlen( $file['bytes'] ) );
	}

	public static function root( string $root ): array {
		self::require( '' !== $root && 'Windows' !== PHP_OS_FAMILY && function_exists( 'posix_geteuid' ), 'explicit verifiable native host authority root is required.' );
		$root_identity = Wstm108_Files::directory( $root );
		self::require( $root_identity['uid'] === posix_geteuid() && 0 === ( $root_identity['mode'] & 0022 ), 'authority root is foreign or writable by other principals.' );
		return $root_identity;
	}

	public static function create( string $root, string $checkout, array $binding, string $context_sha256, array $mounts ): self {
		\Wstm108_Export::require_host();
		$root_identity = self::root( $root );
		self::require( array_keys( $binding ) === array( 'owner', 'project', 'source_sha', 'tree_sha', 'package_sha256' )
			&& 1 === preg_match( '/^[a-f0-9]{32}$/D', $binding['owner'] )
			&& 1 === preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $binding['project'] )
			&& 1 === preg_match( '/^[a-f0-9]{40}$/D', $binding['source_sha'] )
			&& 1 === preg_match( '/^[a-f0-9]{40}$/D', $binding['tree_sha'] )
			&& ( null === $binding['package_sha256'] || ( is_string( $binding['package_sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $binding['package_sha256'] ) ) )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $context_sha256 ), 'invalid source/project authority binding.' );
		self::require( array_keys( $mounts ) === array( 'project', 'daemon', 'docker_root', 'bind_sources', 'volumes', 'planned_oneoffs', 'docker', 'daemon_id', 'topology' )
			&& $binding['project'] === $mounts['project'] && is_array( $mounts['bind_sources'] ) && array() !== $mounts['bind_sources']
			&& is_array( $mounts['volumes'] ) && array() === $mounts['planned_oneoffs']
			&& in_array( $mounts['daemon'], array( 'unix:///var/run/docker.sock', 'unix:///run/docker.sock' ), true ), 'missing complete local owned-project mount projection.' );
		self::require( $mounts['topology'] === Wstm108_HostTopology::admit( $mounts['daemon'] ), 'native topology changed before creation.' );
		self::outside( $root, array_merge( array( $checkout, $mounts['docker_root'] ), $mounts['bind_sources'] ), $mounts['topology'] );
		$directory = self::normalized( $root ) . '/wstm108-authority-' . $binding['project'] . '-' . $binding['owner'];
		$intent = Wstm108_Files::create( $root . '/wstm108-create-' . $binding['owner'] . '.private.json',
			json_encode( array( 'root_identity' => $root_identity, 'child' => $directory, 'binding' => $binding ), JSON_THROW_ON_ERROR ) );
		self::require( $root_identity === self::root( $root ), 'root changed before the owned mkdir.' );
		self::require( @mkdir( $directory, 0700 ), 'exclusive authority directory collision.' );
		$identity = self::private_directory( $directory );
		$transition = Wstm108_HostTopology::owned_child_transition( $root_identity, self::root( $root ), $identity );
		Wstm108_Files::assert_file( $root . '/wstm108-create-' . $binding['owner'] . '.private.json', $intent );
		$receipt = array(
			'version' => 1, 'binding' => $binding, 'context_sha256' => $context_sha256,
			'checkout' => $checkout, 'authority_root' => $root, 'authority_identity' => $root_identity,
			'directory' => $directory, 'directory_identity' => $identity, 'mounts' => $mounts,
			'root_transition' => $transition, 'creation_intent' => self::metadata( $intent ),
		);
		$file = Wstm108_Files::create( $directory . '/reservation.receipt.json', json_encode( $receipt, JSON_THROW_ON_ERROR ) );
		self::require( 0600 === ( $file['identity']['mode'] & 0777 ) && $file['identity']['uid'] === posix_geteuid(), 'reservation receipt lacks private host ownership.' );
		return new self( $receipt + array( 'reservation' => self::metadata( $file ) ) );
	}

	public function handle(): array {
		$this->verify();
		return $this->handle;
	}

	public static function resume( array $handle ): self {
		self::require( array_keys( $handle ) === array( 'version', 'binding', 'context_sha256', 'checkout', 'authority_root', 'authority_identity', 'directory', 'directory_identity', 'mounts', 'root_transition', 'creation_intent', 'reservation' ), 'missing independent host handle; receipts are not adopted.' );
		$self = new self( $handle );
		$self->verify();
		return $self;
	}

	private function verify(): void {
		$h = $this->handle;
		$root = Wstm108_Files::directory( $h['authority_root'] );
		self::require( $h['root_transition']['before'] === $h['authority_identity']
			&& Wstm108_HostTopology::stable_identity( $root ) === Wstm108_HostTopology::stable_identity( $h['authority_identity'] )
			&& $root['nlink'] === $h['root_transition']['after_nlink']
			&& self::private_directory( $h['directory'] ) === $h['directory_identity'], 'independent host ownership changed.' );
		self::require( $h['root_transition'] === Wstm108_HostTopology::owned_child_transition( $h['authority_identity'], $root, $h['directory_identity'] )
			&& $h['mounts']['topology'] === Wstm108_HostTopology::admit( $h['mounts']['daemon'] ), 'owned root transition or topology changed.' );
		self::outside( $h['directory'], array_merge( array( $h['checkout'], $h['mounts']['docker_root'] ), $h['mounts']['bind_sources'] ), $h['mounts']['topology'] );
		$intent = Wstm108_Files::read_bound( $h['authority_root'] . '/wstm108-create-' . $h['binding']['owner'] . '.private.json', $h['creation_intent']['identity'] );
		self::require( self::metadata( $intent ) === $h['creation_intent'], 'original mkdir intent changed.' );
		$file = Wstm108_Files::read_bound( $h['directory'] . '/reservation.receipt.json', $h['reservation']['identity'] );
		self::require( self::metadata( $file ) === $h['reservation'], 'original host reservation receipt changed.' );
	}

	public function receipt( string $name, array $data ): array {
		self::require( in_array( $name, array(
			'anchor.receipt.json', 'prepared.receipt.json', 'retirement.receipt.json',
			'acquire.process.receipt.json', 'original.process.receipt.json', 'enable.process.receipt.json',
			'enabled.process.receipt.json', 'runner.process.receipt.json', 'restored.process.receipt.json',
			'finalize.process.receipt.json', 'retire.process.receipt.json', 'runner-proof.process.receipt.json', 'final-proof.process.receipt.json',
			'clear-primary.process.receipt.json',
		), true ), 'unknown receipt basename.' );
		$this->verify();
		$file = Wstm108_Files::create( $this->handle['directory'] . '/' . $name, json_encode( $data, JSON_THROW_ON_ERROR ) );
		self::require( 0600 === ( $file['identity']['mode'] & 0777 ), 'receipt is not private.' );
		return self::metadata( $file );
	}

	public function verify_processes( array $processes ): void {
		$this->verify();
		$seen = array();
		foreach ( $processes as $record ) {
			self::require( is_array( $record ) && array_keys( $record ) === array( 'witness', 'receipt' ), 'malformed independent process chain.' );
			$witness = $record['witness'];
			$action = $witness['action'] ?? null;
			self::require( is_string( $action ) && in_array( $action, array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'runner-proof', 'restored', 'finalize', 'retire', 'final-proof', 'clear-primary' ), true )
				&& ! isset( $seen[ $action ] ) && 0 === ( $witness['child_exit'] ?? null )
				&& true === ( $witness['capture_complete'] ?? null ) && true === ( $witness['validated'] ?? null ), 'incomplete or failed independent process verdict.' );
			$seen[ $action ] = true;
			$file = Wstm108_Files::read_bound( $this->handle['directory'] . '/' . $action . '.process.receipt.json', $record['receipt']['identity'] );
			self::require( self::metadata( $file ) === $record['receipt']
				&& json_decode( $file['bytes'], true, 512, JSON_THROW_ON_ERROR ) === $witness, 'original process receipt was replaced or changed.' );
			foreach ( array( 'stdout', 'stderr' ) as $stream ) {
				$file = Wstm108_Files::read_bound( $this->handle['directory'] . '/' . $action . '.' . $stream . '.private', $witness[ $stream ]['identity'] );
				self::require( self::metadata( $file ) === $witness[ $stream ], 'original private process transcript changed.' );
			}
		}
	}

	/** No argv, arbitrary diagnostics or original stream content is public. */
	public function capture( string $action, array $command, string $cwd, array $environment ): array {
		self::require( in_array( $action, array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'runner-proof', 'restored', 'finalize', 'retire', 'final-proof', 'clear-primary' ), true ), 'unknown owned action.' );
		$this->verify();
		self::require( $cwd === $this->handle['checkout'] && array() !== $command, 'foreign command source root.' );
		$handles = array();
		$files = array();
		foreach ( array( 'stdout', 'stderr' ) as $stream ) {
			$path = $this->handle['directory'] . '/' . $action . '.' . $stream . '.private';
			$mask = umask( 0077 );
			try { $handle = @fopen( $path, 'x+b' ); } finally { umask( $mask ); }
			self::require( is_resource( $handle ), 'exclusive process capture reservation failed before launch.' );
			$handles[ $stream ] = $handle;
			$stat = fstat( $handle );
			self::require( is_array( $stat ) && 0600 === ( $stat['mode'] & 0777 ) && posix_geteuid() === $stat['uid'] && 1 === $stat['nlink'], 'capture lacks exclusive 0600 ownership.' );
			$files[ $stream ] = array( 'path' => $path, 'identity' => array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ) );
			Wstm108_Files::read_bound( $path, $files[ $stream ]['identity'] );
		}

		$environment = self::child_environment( $environment );
		$intent = array( 'version' => 1, 'binding' => $this->handle['binding'], 'action' => $action, 'state' => 'reserved', 'captures' => $files );
		$intent_file = Wstm108_Files::create( $this->handle['directory'] . '/' . $action . '.capture.private.json', json_encode( $intent, JSON_THROW_ON_ERROR ) );
		$exit = null;
		$captured = array();
		try {
			$this->verify();
			$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ),
				3 => array( 'file', '/dev/null', 'w' ), 4 => array( 'file', '/dev/null', 'w' ), 9 => array( 'file', '/dev/null', 'w' ) ), $pipes, $cwd, $environment );
			self::require( is_resource( $process ), 'owned process could not start after reservation.' );
			fclose( $pipes[0] );
			$lengths = array( 'stdout' => 0, 'stderr' => 0 );
			$hashes = array( 'stdout' => hash_init( 'sha256' ), 'stderr' => hash_init( 'sha256' ) );
			$write_failed = false;
			$pump_failed = self::pump( array( 'stdout' => $pipes[1], 'stderr' => $pipes[2] ), static function ( string $stream, string $bytes ) use ( $handles, &$lengths, $hashes, &$write_failed ): void {
				$lengths[ $stream ] += strlen( $bytes );
				hash_update( $hashes[ $stream ], $bytes );
				if ( $write_failed ) { return; }
				for ( $offset = 0; $offset < strlen( $bytes ); $offset += $count ) {
					$count = @fwrite( $handles[ $stream ], substr( $bytes, $offset ) );
					if ( ! is_int( $count ) || $count < 1 ) { $write_failed = true; break; }
				}
			} );
			$exit = proc_close( $process );
			self::require( ! $write_failed && ! $pump_failed, 'original process streams were not completely persisted.' );
			foreach ( $handles as $stream => $handle ) {
				self::require( fflush( $handle ), 'process capture flush failed.' );
				$stat = fstat( $handle );
				self::require( is_array( $stat ) && $files[ $stream ]['identity'] === array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ), 'process capture identity changed.' );
				$captured[ $stream ] = Wstm108_Files::read_bound( $files[ $stream ]['path'], $files[ $stream ]['identity'] );
				self::require( $stat['size'] === strlen( $captured[ $stream ]['bytes'] )
					&& $lengths[ $stream ] === strlen( $captured[ $stream ]['bytes'] )
					&& hash_final( $hashes[ $stream ] ) === $captured[ $stream ]['sha256'], 'process capture is incomplete.' );
			}

			$this->verify();
			$intent['state'] = 'complete';
			$intent['child_exit'] = $exit;
			$intent['captures'] = array_map( static fn( $file ) => self::metadata( $file ), $captured );
			Wstm108_Files::update( $this->handle['directory'] . '/' . $action . '.capture.private.json', $intent_file, json_encode( $intent, JSON_THROW_ON_ERROR ) );
			return array( 'action' => $action, 'child_exit' => $exit, 'capture_complete' => true, 'streams' => $captured );
		} catch ( Throwable $error ) {
			// Persist the observed child status independently; never reinterpret a
			// capture failure as that child's success or as a usable transcript.
			$failure = array( 'action' => $action, 'child_exit' => $exit, 'capture_complete' => false, 'error_sha256' => hash( 'sha256', $error->getMessage() ) );
			$code = null !== $exit && 0 !== $exit ? $exit : 1;
			try {
				$this->verify();
				Wstm108_Files::create( $this->handle['directory'] . '/' . $action . '.capture-failure.private.json', json_encode( $failure, JSON_THROW_ON_ERROR ) );
			} catch ( Throwable $persistence_error ) {
				throw new RuntimeException( 'WSTM108 capture failed and its failure receipt could not be safely persisted; child_exit=' . ( null === $exit ? 'not-observed' : (string) $exit ) . '; guard retained.', $code, $persistence_error );
			}
			throw new RuntimeException( 'WSTM108 process capture failed; child_exit=' . ( null === $exit ? 'not-observed' : (string) $exit ) . '; private evidence and guard retained.', $code, $error );
		} finally {
			foreach ( $handles as $handle ) { fclose( $handle ); }
		}
	}

	/** Drain both original streams even when persistence fails; do not kill the child. */
	private static function pump( array $streams, callable $consume ): bool {
		$failed = false;
		foreach ( $streams as $stream ) {
			if ( ! stream_set_blocking( $stream, false ) ) { $failed = true; }
		}
		while ( array() !== $streams ) {
			$read = array_values( $streams );
			$write = null;
			$except = null;
			if ( false === @stream_select( $read, $write, $except, null ) ) { $failed = true; $read = array_values( $streams ); }
			foreach ( $streams as $name => $stream ) {
				if ( ! in_array( $stream, $read, true ) ) { continue; }
				$bytes = fread( $stream, 65536 );
				if ( false === $bytes ) { $failed = true; }
				if ( is_string( $bytes ) && '' !== $bytes ) { $consume( $name, $bytes ); }
				if ( feof( $stream ) || false === $bytes ) { fclose( $stream ); unset( $streams[ $name ] ); }
			}
		}
		return $failed;
	}

	public static function query( array $command ): array|string {
		throw new RuntimeException( 'Unsupported direct discovery: the native descriptor-owning controller is required; no child query or receipt adoption.' );
	}

	public static function admitted_mounts( string $encoded, string $project, string $root ): array {
		$bytes = base64_decode( $encoded, true );
		self::require( is_string( $bytes ) && base64_encode( $bytes ) === $encoded, 'missing independently passed admitted topology.' );
		$value = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
		self::require( is_array( $value ) && array_keys( $value ) === array( 'project', 'daemon', 'docker_root', 'bind_sources', 'volumes', 'planned_oneoffs', 'docker', 'daemon_id', 'topology' )
			&& $project === $value['project'] && self::docker_binary() === $value['docker']
			&& Wstm108_HostTopology::admit( $value['daemon'] ) === $value['topology'], 'admitted endpoint or topology changed before helper.' );
		self::outside( $root, array_merge( array( $value['docker_root'] ), $value['bind_sources'] ), $value['topology'] );
		return $value;
	}

	public static function child_environment( array $environment ): array {
		$environment = \Wstm108_HostController::child_environment( $environment );
		foreach ( array( 'DOCKER_CONTEXT', 'DOCKER_HOST', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH', 'DOCKER_TLS' ) as $key ) {
			unset( $environment[ $key ] );
		}
		return $environment;
	}

	public static function resolve_endpoint( array $environment, string $binary, callable $query ): string {
		$context = $environment['DOCKER_CONTEXT'] ?? '';
		$host = $environment['DOCKER_HOST'] ?? '';
		self::require( is_string( $context ) && is_string( $host ) && ( '' === $context || '' === $host ), 'conflicting Docker selectors; no daemon query authorized.' );
		if ( '' !== $host ) { $endpoint = $host; }
		else {
			self::require( '' === $context || 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $context ), 'invalid Docker context selector.' );
			$command = array( $binary, 'context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}' );
			if ( '' !== $context ) { $command[] = $context; }
			$endpoint = $query( $command );
		}
		self::require( in_array( $endpoint, array( 'unix:///var/run/docker.sock', 'unix:///run/docker.sock' ), true ), 'remote, unmapped or nonnative Docker endpoint.' );
		return $endpoint;
	}

	public static function docker_binary(): array {
		$path = realpath( '/usr/bin/docker' );
		self::require( is_string( $path ), 'pinned native Docker binary is unavailable.' );
		$file = Wstm108_Files::file( $path );
		self::require( 0 === $file['identity']['uid'] && 0 === ( $file['identity']['mode'] & 0022 )
			&& 0 !== ( $file['identity']['mode'] & 0111 ), 'Docker binary is not a trusted native executable.' );
		return array( 'path' => $path, 'file' => self::metadata( $file ) );
	}

	public static function docker_argv( array $docker, string $endpoint, array $arguments ): array {
		self::require( in_array( $endpoint, array( 'unix:///var/run/docker.sock', 'unix:///run/docker.sock' ), true ), 'unverified Docker endpoint.' );
		$file = Wstm108_Files::read_bound( $docker['path'], $docker['file']['identity'] );
		self::require( self::metadata( $file ) === $docker['file'], 'pinned Docker executable changed.' );
		return array_merge( array( $docker['path'], '--host', $endpoint ), $arguments );
	}

	public static function frame( array $capture, string $expected_stdout ): void {
		self::require( true === ( $capture['capture_complete'] ?? null ) && 0 === ( $capture['child_exit'] ?? null )
			&& '' === ( $capture['streams']['stderr']['bytes'] ?? null )
			&& $expected_stdout === ( $capture['streams']['stdout']['bytes'] ?? null ), 'process status or complete output framing failed; originals remain private.' );
	}

	public static function encoded_frame( array $capture, string $prefix ): array {
		self::require( in_array( $prefix, array( 'WSTM108_ANCHOR ', 'WSTM108_PREPARED ' ), true )
			&& true === ( $capture['capture_complete'] ?? null ) && 0 === ( $capture['child_exit'] ?? null )
			&& '' === ( $capture['streams']['stderr']['bytes'] ?? null ), 'failed acquire/prepare output is not parsed.' );
		$stdout = $capture['streams']['stdout']['bytes'] ?? null;
		self::require( is_string( $stdout ) && 1 === preg_match( '/^' . preg_quote( $prefix, '/' ) . '([A-Za-z0-9+\/]+={0,2})\n$/D', $stdout, $matches ), 'extra, partial or malformed acquire/prepare framing.' );
		$bytes = base64_decode( $matches[1], true );
		self::require( is_string( $bytes ) && base64_encode( $bytes ) === $matches[1], 'noncanonical acquire/prepare encoding.' );
		$value = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
		self::require( is_array( $value ), 'nonobject acquire/prepare authority.' );
		return $value;
	}

	public static function witness( array $capture, bool $validated ): array {
		return array(
			'action' => $capture['action'], 'child_exit' => $capture['child_exit'],
			'capture_complete' => $capture['capture_complete'], 'validated' => $validated,
			'stdout' => self::metadata( $capture['streams']['stdout'] ),
			'stderr' => self::metadata( $capture['streams']['stderr'] ),
		);
	}

	/** The caller queries only this project's configuration, containers and volumes. */
	public static function discover_mounts( string $project, array $compose, callable $query, string $authority_root ): array {
		self::require( 'docker' === ( $compose[0] ?? null ) && 'compose' === ( $compose[1] ?? null ), 'unrecognized owned Compose selector.' );
		$docker = self::docker_binary();
		$daemon = self::resolve_endpoint( getenv(), $docker['path'], $query );
		$topology = Wstm108_HostTopology::admit( $daemon );
		$compose = self::docker_argv( $docker, $daemon, array_slice( $compose, 1 ) );
		$call = static fn( array $arguments ) => $query( self::docker_argv( $docker, $daemon, $arguments ) );
		$daemon_id = $call( array( 'info', '--format', '{{json .ID}}' ) );
		self::require( is_string( $daemon_id ) && 1 === preg_match( '/^[A-Za-z0-9:_-]{1,128}$/D', $daemon_id ), 'missing native daemon identity.' );
		$docker_root = $call( array( 'info', '--format', '{{json .DockerRootDir}}' ) );
		self::require( is_string( $docker_root ), 'missing daemon storage projection.' );
		Wstm108_Files::directory( $docker_root );
		// Admit only narrow live mount/label projections before any full Compose data.
		$live = $call( array( 'ps', '--all', '--filter', 'label=com.docker.compose.project=' . $project, '--format', '{"ID":"{{.ID}}"}' ) );
		self::require( is_array( $live ) && array() !== $live, 'no live owned topology before admission.' );
		$initial_sources = array( $docker_root );
		foreach ( $live as $container ) {
			self::require( is_array( $container ) && is_string( $container['ID'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{12,64}$/D', $container['ID'] ), 'invalid admission container.' );
			$projection = $call( array( 'inspect', '--format', '{"project":{{json (index .Config.Labels "com.docker.compose.project")}},"mounts":{{json .Mounts}}}', $container['ID'] ) );
			self::require( is_array( $projection ) && $project === ( $projection['project'] ?? null ) && is_array( $projection['mounts'] ?? null ), 'foreign admission projection.' );
			foreach ( $projection['mounts'] as $mount ) {
				self::require( is_array( $mount ) && in_array( $mount['Type'] ?? null, array( 'bind', 'volume', 'tmpfs' ), true ), 'unknown admission mount.' );
				if ( 'tmpfs' !== $mount['Type'] ) {
					self::require( is_string( $mount['Source'] ?? null ), 'missing admission source.' );
					$initial_sources[] = $mount['Source'];
				}
			}
		}
		self::outside( $authority_root, $initial_sources, $topology );
		$config = $query( array_merge( $compose, array( 'config', '--format', 'json', '--no-env-resolution' ) ) );
		self::require( is_array( $config ) && $project === ( $config['name'] ?? null ) && is_array( $config['services'] ?? null ), 'ambiguous effective owned Compose configuration.' );
		$binds = array();
		$volumes = array();
		foreach ( $config['services'] as $service ) {
			foreach ( $service['volumes'] ?? array() as $volume ) {
				self::require( is_array( $volume ) && is_string( $volume['type'] ?? null ), 'unresolved planned service mount.' );
				if ( 'bind' === $volume['type'] ) {
					self::require( is_string( $volume['source'] ?? null ) && '' !== $volume['source'], 'unresolved planned bind source.' );
					$binds[] = $volume['source'];
				} elseif ( 'volume' === $volume['type'] ) {
					$name = $volume['source'] ?? null;
					$definition = is_string( $name ) ? ( $config['volumes'][ $name ] ?? null ) : null;
					self::require( is_array( $definition ) && false === ( $definition['external'] ?? false )
						&& is_string( $definition['name'] ?? null ) && array() === ( $definition['driver_opts'] ?? array() ), 'external, anonymous or bind-backed planned volume is not proven.' );
					$volumes[] = $definition['name'];
				} else {
					self::require( 'tmpfs' === $volume['type'], 'unknown planned mount type.' );
				}
			}
		}
		// Capture only the explicit mount/label projection, never inspect environments.
		$containers = $query( array_merge( $compose, array( 'ps', '--all', '--format', 'json' ) ) );
		self::require( is_array( $containers ) && array() !== $containers, 'owned container inventory is absent.' );
		foreach ( $containers as $container ) {
			self::require( is_array( $container ) && is_string( $container['ID'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{12,64}$/D', $container['ID'] ), 'unresolved owned container identifier.' );
			$inspection = $call( array( 'inspect', '--format', '{"project":{{json (index .Config.Labels "com.docker.compose.project")}},"mounts":{{json .Mounts}}}', $container['ID'] ) );
			self::require( is_array( $inspection ) && $project === ( $inspection['project'] ?? null ) && is_array( $inspection['mounts'] ?? null ), 'foreign or partial owned-project mount inventory.' );
			foreach ( $inspection['mounts'] as $mount ) {
				if ( 'bind' === ( $mount['Type'] ?? null ) ) {
					self::require( is_string( $mount['Source'] ?? null ), 'missing live bind source.' );
					$binds[] = $mount['Source'];
				} elseif ( 'volume' === ( $mount['Type'] ?? null ) ) {
					self::require( is_string( $mount['Name'] ?? null ), 'missing live named volume.' );
					$volumes[] = $mount['Name'];
				} else {
					self::require( 'tmpfs' === ( $mount['Type'] ?? null ), 'unknown live mount type.' );
				}
			}
		}
		$volumes = array_values( array_unique( $volumes, SORT_STRING ) );
		sort( $volumes, SORT_STRING );
		$volume_projection = array();
		foreach ( $volumes as $name ) {
			self::require( 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $name ), 'invalid named volume.' );
			$volume = $call( array( 'volume', 'inspect', '--format', '{"name":{{json .Name}},"driver":{{json .Driver}},"mountpoint":{{json .Mountpoint}},"project":{{json (index .Labels "com.docker.compose.project")}},"options":{{json .Options}}}', $name ) );
			self::require( is_array( $volume ) && $name === ( $volume['name'] ?? null ) && $project === ( $volume['project'] ?? null )
				&& 'local' === ( $volume['driver'] ?? null ) && in_array( $volume['options'] ?? null, array( null, array() ), true )
				&& self::normalized( $docker_root ) . '/volumes/' . $name . '/_data' === ( $volume['mountpoint'] ?? null ),
				'foreign, bind-backed or nonlocal volume canonicality is unproven.' );
			$volume_projection[] = array( 'name' => $name, 'mountpoint' => $volume['mountpoint'], 'driver' => 'local' );
		}
		$binds = array_values( array_unique( $binds, SORT_STRING ) );
		sort( $binds, SORT_STRING );
		self::outside( $authority_root, array_merge( array( $docker_root ), $binds ), $topology );
		self::require( $topology === Wstm108_HostTopology::admit( $daemon ) && $daemon_id === $call( array( 'info', '--format', '{{json .ID}}' ) ), 'daemon or topology changed during discovery.' );
		return array( 'project' => $project, 'daemon' => $daemon, 'docker_root' => $docker_root, 'bind_sources' => $binds, 'volumes' => $volume_projection, 'planned_oneoffs' => array(),
			'docker' => $docker, 'daemon_id' => $daemon_id, 'topology' => $topology );
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	$observed_child = null;
	$release_attempted = 'commit' === ( $argv[1] ?? null );
	try {
		Wstm108_Export::require_host();
		if ( 'cli' !== PHP_SAPI ) { throw new RuntimeException( 'CLI host authority only.' ); }
		$authority_root = getenv( 'WSTM108_HOST_AUTHORITY_ROOT' ) ?: '';
		Wstm108_HostAuthority::root( $authority_root );
		$mode = $argv[1] ?? '';
		$context_path = $argv[2] ?? '';
		$context_file = Wstm108_Files::file( $context_path );
		$context = json_decode( $context_file['bytes'], true, 512, JSON_THROW_ON_ERROR );
		$checkout = str_replace( '\\', '/', realpath( dirname( __DIR__ ) ) );
		if ( $checkout !== ( $context['host_harness_root'] ?? null )
			|| $checkout !== str_replace( '\\', '/', realpath( getcwd() ) )
			|| $checkout . '/' . ( $context['artifact_directory'] ?? '' ) . '/context.json' !== str_replace( '\\', '/', $context_path )
			|| ! in_array( $mode, array( 'reserve', 'run' ), true ) ) {
			throw new RuntimeException( 'Foreign explicit host context or command root.' );
		}
		foreach ( $context['host_files'] as $path => $sha256 ) {
			if ( ! is_string( $path ) || false !== strpos( $path, '..' ) || $sha256 !== Wstm108_Files::file( $checkout . '/' . $path )['sha256'] ) {
				throw new RuntimeException( 'Host command sources differ from the exact committed context.' );
			}
		}
		$binding = $context['binding'];
		$compose = array( 'docker', 'compose', '--project-name', $binding['project'] );
		if ( null !== $binding['package_sha256'] ) {
			$compose = array_merge( $compose, array( '-f', 'docker-compose.yml', '-f', 'docker-compose.release.yml' ) );
		}
		if ( 'reserve' === $mode ) {
			$mounts = Wstm108_HostAuthority::admitted_mounts( getenv( 'WSTM108_ADMITTED_MOUNTS' ) ?: '', $binding['project'], $authority_root );
			$authority = Wstm108_HostAuthority::create( $authority_root, $checkout, $binding, $context_file['sha256'], $mounts );
			$result = array( 'host' => $authority->handle(), 'anchor' => null, 'prepared' => null, 'processes' => array(),
				'companion' => null, 'retirement_receipt' => null, 'release_authorization' => null );
		} else {
			$encoded = getenv( 'WSTM108_HOST_STATE' );
			$bytes = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
			if ( false === $bytes || base64_encode( $bytes ) !== $encoded ) { throw new RuntimeException( 'Missing independent in-memory host state; no receipt fallback.' ); }
			$result = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_array( $result ) || array_keys( $result ) !== array( 'host', 'anchor', 'prepared', 'processes', 'companion', 'retirement_receipt', 'release_authorization' ) ) { throw new RuntimeException( 'Invalid independent host state.' ); }
			$authority = Wstm108_HostAuthority::resume( $result['host'] );
			if ( $authority_root !== $result['host']['authority_root'] ) { throw new RuntimeException( 'Explicit host authority root changed.' ); }
			$authority->verify_processes( $result['processes'] );
			$mounts = Wstm108_HostAuthority::admitted_mounts( getenv( 'WSTM108_ADMITTED_MOUNTS' ) ?: '', $binding['project'], $authority_root );
			if ( $binding !== $result['host']['binding'] || $context_file['sha256'] !== $result['host']['context_sha256'] || $mounts !== $result['host']['mounts'] ) {
				throw new RuntimeException( 'Source context or effective mount inventory changed before the owned command.' );
			}
			$action = $argv[3] ?? '';
			$previous = array_map( static fn( $record ) => $record['witness']['action'], $result['processes'] );
			$ordered = array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'runner-proof', 'restored', 'finalize', 'retire', 'final-proof' );
			$release = new Wstm108_ReleaseGuard( $authority );
			if ( 'companion' === $action ) {
				if ( array() !== $previous || null !== $result['companion'] || null !== $result['anchor'] ) { throw new RuntimeException( 'Companion creation is not repeatable or adoptable.' ); }
				$result['companion'] = $release->arm();
			} elseif ( 'clear-primary' === $action ) {
				if ( $ordered !== $previous || null === $result['retirement_receipt'] ) { throw new RuntimeException( 'Primary clear requires the complete final proof chain.' ); }
				$result['processes'][] = $release->clear_primary( $result['companion'] );
			} elseif ( 'authorize-release' === $action ) {
				if ( null !== $result['release_authorization'] ) { throw new RuntimeException( 'Release authorization cannot be repeated or adopted.' ); }
				$result['release_authorization'] = $release->authorize( $result, $context );
			} else {
			$release->verify( $result['companion'], true );
			$index = array_search( $action, $ordered, true );
			if ( false === $index || in_array( $action, $previous, true )
				|| ( 'restored' === $action ? ( array() === $previous || 'acquire' !== $previous[0] ) : array_slice( $ordered, 0, $index ) !== $previous ) ) {
				throw new RuntimeException( 'Missing successful process verdicts before the requested owned action.' );
			}
			$plugin = '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp';
			$container_context = $plugin . '/' . $context['artifact_directory'] . '/context.json';
			$environment = Wstm108_HostAuthority::child_environment( getenv() );
			$environment['WSTM108_STATE_ANCHOR'] = null === $result['anchor'] ? '' : base64_encode( json_encode( $result['anchor'], JSON_THROW_ON_ERROR ) );
			$environment['WSTM108_PREPARED'] = null === $result['prepared'] ? '' : base64_encode( json_encode( $result['prepared'], JSON_THROW_ON_ERROR ) );
			$environment['WSTM108_PROCESS_VERDICTS'] = base64_encode( json_encode( array( 'binding' => $binding, 'processes' => $result['processes'] ), JSON_THROW_ON_ERROR ) );
			$command = array_merge( Wstm108_HostAuthority::docker_argv( $mounts['docker'], $mounts['daemon'], array_slice( $compose, 1 ) ),
				array( 'exec', '-T', '-e', 'WSTM108_STATE_ANCHOR', '-e', 'WSTM108_PREPARED', '-e', 'WSTM108_PROCESS_VERDICTS' ) );
			if ( in_array( $action, array( 'runner-proof', 'final-proof' ), true ) ) {
				$command = array(
					PHP_BINARY, __DIR__ . '/untrusted-cleanup-proof.php', 'runner.json', 'context.json',
					$binding['source_sha'], $binding['project'], $binding['owner'], dirname( $context_path ),
				);
				if ( 'final-proof' === $action ) { $command[] = '--final'; }
			} elseif ( 'runner' === $action ) {
				$environment['WSTM108_ALLOW_DISPOSABLE'] = '1';
				$environment['WSTM108_STAGE_CONTEXT'] = $container_context;
				$environment['WSTM108_ARTIFACT'] = $plugin . '/' . $context['artifact_directory'] . '/runner.json';
				$command = array_merge( $command, array( '-e', 'WSTM108_ALLOW_DISPOSABLE', '-e', 'WSTM108_STAGE_CONTEXT', '-e', 'WSTM108_ARTIFACT', 'wordpress', 'php', '-d', 'memory_limit=1G', $plugin . '/tests/e2e/untrusted-content-runner.php' ) );
			} else {
				$environment['WSTM108_STAGE_DISPOSABLE'] = '1';
				$command = array_merge( $command, array( '-e', 'WSTM108_STAGE_DISPOSABLE', 'wordpress', 'php', $plugin . '/tests/e2e/untrusted-content-stage.php', $action, $container_context ) );
			}
			$capture = $authority->capture( $action, $command, $checkout, $environment );
			$observed_child = $capture['child_exit'];
			try {
				if ( 'acquire' === $action ) {
					$anchor = Wstm108_HostAuthority::encoded_frame( $capture, 'WSTM108_ANCHOR ' );
					require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-lifecycle.php';
					new Wstm108_Lifecycle( '/var/www/html', $plugin, '/tmp/wstm108-stage', $binding, $anchor );
					if ( $context_file['sha256'] !== $anchor['context_sha256'] ) { throw new RuntimeException( 'Acquire anchor source context differs.' ); }
					$authority->receipt( 'anchor.receipt.json', array( 'binding' => $binding, 'anchor' => $anchor, 'process' => Wstm108_HostAuthority::witness( $capture, true ) ) );
					$result['anchor'] = $anchor;
				} elseif ( 'finalize' === $action ) {
					$prepared = Wstm108_HostAuthority::encoded_frame( $capture, 'WSTM108_PREPARED ' );
					if ( array_keys( $prepared ) !== array( 'version', 'binding', 'context_sha256', 'generation', 'state_sha256', 'inventory_sha256', 'target_count' )
						|| 1 !== $prepared['version'] || $binding !== $prepared['binding'] || $context_file['sha256'] !== $prepared['context_sha256']
						|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $prepared['generation'] )
						|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $prepared['state_sha256'] )
						|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $prepared['inventory_sha256'] ) || ! is_int( $prepared['target_count'] ) || $prepared['target_count'] < 5 ) {
						throw new RuntimeException( 'Foreign or incomplete prepared retirement frame.' );
					}
					$authority->receipt( 'prepared.receipt.json', array( 'binding' => $binding, 'prepared' => $prepared, 'process' => Wstm108_HostAuthority::witness( $capture, true ) ) );
					$result['prepared'] = $prepared;
				} elseif ( in_array( $action, array( 'runner-proof', 'final-proof' ), true ) ) {
					Wstm108_HostAuthority::frame( $capture, "Verified complete source/project/owner-bound untrusted proof and resource cleanup.\n" );
				} elseif ( 'runner' === $action ) {
					$labels = Wstm108_Plan::labels( Wstm108_Plan::native_from_manifest( dirname( __DIR__ ) . '/tests/e2e/abilities-manifest.json' ) );
					$expected = implode( '', array_map( static fn( $label ) => 'PASS ' . $label . "\n", $labels ) )
						. 'WSTM108 passed: ' . count( $labels ) . ' passed; 0 failed. Evidence: ' . $environment['WSTM108_ARTIFACT'] . "\n";
					Wstm108_HostAuthority::frame( $capture, $expected );
				} else {
					Wstm108_HostAuthority::frame( $capture, 'WSTM108 stage ' . $action . " completed with owned evidence.\n" );
				}
			} catch ( Throwable $error ) {
				$authority->receipt( $action . '.process.receipt.json', Wstm108_HostAuthority::witness( $capture, false ) );
				throw $error;
			}
			$witness = Wstm108_HostAuthority::witness( $capture, true );
			$receipt = $authority->receipt( $action . '.process.receipt.json', $witness );
			$result['processes'][] = array( 'witness' => $witness, 'receipt' => $receipt );
			if ( 'final-proof' === $action ) {
				$result['retirement_receipt'] = $authority->receipt( 'retirement.receipt.json', array( 'binding' => $binding, 'prepared' => $result['prepared'], 'processes' => $result['processes'] ) );
			}
			}
		}
		echo 'WSTM108_HOST ' . base64_encode( json_encode( $result, JSON_THROW_ON_ERROR ) ) . "\n";
	} catch ( Throwable $error ) {
		$status = is_int( $observed_child ) && 0 !== $observed_child ? $observed_child : ( $error->getCode() > 0 && $error->getCode() < 256 ? $error->getCode() : 1 );
		fwrite( STDERR, 'WSTM108 host action refused; status=' . $status . '; diagnostic_sha256=' . hash( 'sha256', $error->getMessage() )
			. ( $release_attempted ? '; release_outcome=unknown; no assertion of a remaining guard' : '; no transcript or receipt is adopted' ) . ".\n" );
		exit( $status );
	}
}
