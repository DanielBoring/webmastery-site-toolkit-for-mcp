<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-host-topology.php';
require_once __DIR__ . '/untrusted-export.php';

/** The controller retains originals and publication custody; children receive neither channel. */
final class Wstm108_HostController {
	private string $directory;
	private array $identity;
	private array $environment;
	private array $context = array();
	private array $state = array();
	private array $failures = array();
	private array $helper_captures = array();
	private int $first_failure = 0;
	private ?array $publication = null;
	private ?array $publication_channel = null;
	private ?array $parent_identity = null;
	private bool $reserving_authority_child = false;
	private array $controller_streams = array();

	public function __construct( string $directory, array $identity, array $environment ) {
		Wstm108_Export::require_host();
		if ( array_key_exists( 'WSTM108_HOST_PHP', $environment ) ) {
			self::require( is_string( $environment['WSTM108_HOST_PHP'] ) && '' !== $environment['WSTM108_HOST_PHP']
				&& $environment['WSTM108_HOST_PHP'] === realpath( PHP_BINARY ), 'explicit-host-interpreter-differs' );
		}
		$this->directory = $directory;
		$this->identity = $identity;
		$this->environment = $environment;
		$this->verify();
	}

	private static function require( bool $condition, string $reason ): void {
		if ( ! $condition ) { throw new RuntimeException( 'WSTM108 controller refused: ' . $reason ); }
	}

	public static function identity( string $encoded ): array {
		self::require( 1 === preg_match( '/^([0-9]+):([0-9]+):([0-9]+):([0-9]+):([a-f0-9]+):([0-9]+)$/D', $encoded, $matches ), 'bootstrap-identity-format' );
		return array( 'dev' => (int) $matches[1], 'ino' => (int) $matches[2], 'mode' => (int) hexdec( $matches[5] ),
			'nlink' => (int) $matches[6], 'uid' => (int) $matches[3], 'gid' => (int) $matches[4] );
	}

	private function verify(): void {
		self::require( 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' )
			&& $this->identity === Wstm108_Files::directory( $this->directory )
			&& 0040700 === $this->identity['mode'] && posix_geteuid() === $this->identity['uid'], 'original-bootstrap-custody' );
		if ( null !== $this->parent_identity ) {
			$current = Wstm108_Files::directory( $this->environment['WSTM108_HOST_AUTHORITY_ROOT'] );
			if ( $this->parent_identity !== $current ) {
				self::require( $this->reserving_authority_child, 'original-parent-changed' );
				$binding = $this->context['binding'];
				$child = Wstm108_Files::directory( $this->environment['WSTM108_HOST_AUTHORITY_ROOT']
					. '/wstm108-authority-' . $binding['project'] . '-' . $binding['owner'] );
				Wstm108_HostTopology::owned_child_transition( $this->parent_identity, $current, $child );
			}
		}
	}

	public static function child_environment( array $environment ): array {
		foreach ( array_keys( $environment ) as $key ) {
			if ( 0 === strpos( $key, 'GITHUB_' ) || 0 === strpos( $key, 'WSTM108_EXPORT_' )
				|| in_array( $key, array( 'BASH_ENV', 'ENV', 'SHELLOPTS', 'BASHOPTS', 'BASH_XTRACEFD', 'PS4' ), true ) ) {
				unset( $environment[ $key ] );
			}
		}
		return $environment;
	}

	private static function metadata( array $file ): array {
		return array( 'identity' => $file['identity'], 'sha256' => $file['sha256'], 'length' => strlen( $file['bytes'] ) );
	}

	private function bind_controller_streams(): void {
		self::require( array() === $this->controller_streams && 0 === ob_get_level(), 'controller-channel-already-bound-or-buffered' );
		foreach ( array( 3 => array( 'stdout', STDOUT ), 4 => array( 'stderr', STDERR ) ) as $descriptor => $channel ) {
			$handle = fopen( 'php://fd/' . $descriptor, 'rb' );
			self::require( is_resource( $handle ), 'bootstrap-original-channel' );
			$stat = fstat( $handle );
			self::require( is_array( $stat ), 'bootstrap-channel-stat' );
			$identity = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) );
			$this->controller_streams[ $channel[0] ] = array( 'handle' => $handle, 'writer' => $channel[1], 'identity' => $identity );
			self::require( 0100600 === $identity['mode'] && 1 === $identity['nlink'] && posix_geteuid() === $identity['uid'],
				'bootstrap-channel-owner' );
		}
		$this->verify_controller_streams();
	}

	private function verify_controller_streams(): void {
		self::require( array( 'stdout', 'stderr' ) === array_keys( $this->controller_streams ) && 0 === ob_get_level(),
			'controller-channel-unbound-or-buffered' );
		foreach ( $this->controller_streams as $name => $channel ) {
			$handle = $channel['handle'];
			$writer = $channel['writer'];
			self::require( is_resource( $handle ) && is_resource( $writer ) && fflush( $writer ) && fflush( $handle )
				&& fsync( $writer ) && fsync( $handle ), 'controller-channel-persistence' );
			foreach ( array( $writer, $handle ) as $stream ) {
				$stat = fstat( $stream );
				self::require( is_array( $stat ) && $channel['identity'] === array_intersect_key( $stat,
					array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) )
					&& 0 === $stat['size'] && 0 === ftell( $stream ), 'controller-channel-identity-bytes-or-position' );
			}
			$file = Wstm108_Files::read_bound( $this->directory . '/controller.' . $name . '.private', $channel['identity'] );
			self::require( '' === $file['bytes'] && hash( 'sha256', '' ) === $file['sha256'], 'unexpected-controller-diagnostics' );
		}
		self::require( 0 === ob_get_level(), 'controller-channel-buffered-after-verification' );
	}

	public function handle(): array {
		$this->verify();
		return array( 'directory' => $this->directory, 'identity' => $this->identity );
	}

	private static function sync_file( string $path, array $file ): void {
		$handle = @fopen( $path, 'rb' );
		self::require( is_resource( $handle ), 'private-record-sync-open' );
		try {
			$stat = fstat( $handle );
			self::require( is_array( $stat ) && $file['identity'] === array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) )
				&& fsync( $handle ), 'private-record-sync' );
		} finally { fclose( $handle ); }
		Wstm108_Files::assert_file( $path, $file );
	}

	private function successful_child( array $capture, string $reason ): void {
		if ( 0 === $capture['child_exit'] && '' === $capture['streams']['stderr']['bytes'] ) { return; }
		$code = $capture['child_exit'] > 0 ? $capture['child_exit'] : 1;
		$this->first_failure = 0 === $this->first_failure ? $code : $this->first_failure;
		throw new RuntimeException( 'WSTM108 controller refused: ' . $reason, $code );
	}

	public function capture( string $name, array $command, string $cwd, array $environment ): array {
		self::require( 1 === preg_match( '/^[a-z][a-z0-9-]{0,95}$/D', $name ) && array() !== $command, 'capture-action' );
		$this->verify();
		if ( array() !== $this->controller_streams ) { $this->verify_controller_streams(); }
		$streams = array();
		$originals = array();
		$intent_path = $this->directory . '/' . $name . '.intent.private.json';
		$intent = Wstm108_Files::create( $intent_path, json_encode( array( 'action' => $name, 'state' => 'reserving' ), JSON_THROW_ON_ERROR ) );
		foreach ( array( 'stdout', 'stderr' ) as $stream ) {
			$path = $this->directory . '/' . $name . '.' . $stream . '.private';
			$mask = umask( 0077 );
			try { $handle = @fopen( $path, 'x+b' ); } finally { umask( $mask ); }
			self::require( is_resource( $handle ), 'capture-collision-before-invocation' );
			$stat = fstat( $handle );
			$identity = is_array( $stat ) ? array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ) : null;
			self::require( is_array( $identity ) && 0100600 === $identity['mode'] && 1 === $identity['nlink']
				&& posix_geteuid() === $identity['uid'], 'capture-original-descriptor' );
			$originals[ $stream ] = Wstm108_Files::read_bound( $path, $identity );
			$streams[ $stream ] = $handle;
		}
		$intent = Wstm108_Files::update( $intent_path, $intent, json_encode( array( 'action' => $name, 'state' => 'reserved',
			'streams' => array_map( static fn( $file ) => self::metadata( $file ), $originals ) ), JSON_THROW_ON_ERROR ) );
		self::sync_file( $intent_path, $intent );
		$exit = null;
		try {
			$this->verify();
			// Direct inherited file descriptors preserve startup/fatal bytes too.
			$process = proc_open( $command, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => $streams['stdout'], 2 => $streams['stderr'],
				3 => array( 'file', '/dev/null', 'w' ), 4 => array( 'file', '/dev/null', 'w' ), 5 => array( 'file', '/dev/null', 'w' ), 9 => array( 'file', '/dev/null', 'w' ) ),
				$pipes, $cwd, self::child_environment( $environment ) );
			self::require( is_resource( $process ), 'capture-launch' );
			$exit = proc_close( $process );
			self::require( $exit >= 0 && $exit <= 255, 'child-exit-unobserved' );
			if ( $exit > 0 && 0 === $this->first_failure ) { $this->first_failure = $exit; }
			$captured = array();
			foreach ( $streams as $stream => $handle ) {
				self::require( fflush( $handle ) && fsync( $handle ), 'capture-persistence' );
				$captured[ $stream ] = Wstm108_Files::read_bound( $this->directory . '/' . $name . '.' . $stream . '.private', $originals[ $stream ]['identity'] );
				$stat = fstat( $handle );
				self::require( is_array( $stat ) && $stat['size'] === strlen( $captured[ $stream ]['bytes'] )
					&& $originals[ $stream ]['identity'] === array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ), 'capture-path-or-descriptor-changed' );
			}
			$this->verify();
			$intent = Wstm108_Files::update( $intent_path, $intent, json_encode( array( 'action' => $name, 'state' => 'complete', 'child_exit' => $exit,
				'streams' => array_map( static fn( $file ) => self::metadata( $file ), $captured ) ), JSON_THROW_ON_ERROR ) );
			self::sync_file( $intent_path, $intent );
			$result = array( 'action' => $name, 'child_exit' => $exit, 'capture_complete' => true, 'streams' => $captured, 'intent' => $intent );
			$this->helper_captures[ $name ] = $result;
			if ( array() !== $this->controller_streams ) { $this->verify_controller_streams(); }
			return $result;
		} catch ( Throwable $error ) {
			$code = is_int( $exit ) && $exit > 0 ? $exit : 1;
			$this->first_failure = 0 === $this->first_failure ? $code : $this->first_failure;
			// The reserved intent and any original prefixes survive even if this
			// independent observed-exit record cannot itself be persisted.
			try {
				$this->verify();
				$failure_path = $this->directory . '/' . $name . '.failure.private.json';
				$failure = Wstm108_Files::create( $failure_path,
					json_encode( array( 'action' => $name, 'child_exit' => $exit, 'capture_complete' => false ), JSON_THROW_ON_ERROR ) );
				self::sync_file( $failure_path, $failure );
			} catch ( Throwable $persistence_error ) {
				throw new RuntimeException( 'Helper capture and failure-record persistence refused; original intent/prefixes retained, exit not certified on disk.', $code, $persistence_error );
			}
			throw new RuntimeException( 'Incomplete helper capture; private originals retained.', $code, $error );
		} finally {
			foreach ( $streams as $handle ) { fclose( $handle ); }
		}
	}

	public static function exact_frame( array $capture, string $prefix ): array {
		self::require( true === $capture['capture_complete'] && 0 === $capture['child_exit']
			&& '' === $capture['streams']['stderr']['bytes'], 'helper-exit-or-stderr' );
		self::require( 1 === preg_match( '/^' . preg_quote( $prefix, '/' ) . '([A-Za-z0-9+\/]+={0,2})\n$/D', $capture['streams']['stdout']['bytes'], $matches ), 'helper-frame' );
		$bytes = base64_decode( $matches[1], true );
		self::require( is_string( $bytes ) && base64_encode( $bytes ) === $matches[1], 'helper-encoding' );
		$value = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
		self::require( is_array( $value ), 'helper-object' );
		return $value;
	}

	public function query( array $command, string $prefix = 'admission' ): array|string {
		static $sequence = 0;
		$environment = $this->environment;
		foreach ( array( 'DOCKER_CONTEXT', 'DOCKER_HOST', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH' ) as $key ) { unset( $environment[ $key ] ); }
		$capture = $this->capture( $prefix . '-query-' . ++$sequence, $command, dirname( __DIR__ ), $environment );
		$this->successful_child( $capture, 'discovery-child-failed' );
		$bytes = $capture['streams']['stdout']['bytes'];
		if ( in_array( 'ps', $command, true ) ) {
			$result = array();
			foreach ( explode( "\n", rtrim( $bytes, "\n" ) ) as $line ) {
				$value = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
				self::require( is_array( $value ), 'discovery-ps-shape' );
				$result = array_merge( $result, Wstm108_Export::is_list( $value ) ? $value : array( $value ) );
			}
			return $result;
		}
		$value = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
		self::require( is_array( $value ) || is_string( $value ), 'discovery-shape' );
		return $value;
	}

	private function action( string $action ): void {
		$environment = $this->environment;
		$environment['WSTM108_HOST_STATE'] = base64_encode( json_encode( $this->state, JSON_THROW_ON_ERROR ) );
		$compose = array( 'docker', 'compose', '--project-name', $this->context['binding']['project'] );
		if ( null !== $this->context['binding']['package_sha256'] ) { $compose = array_merge( $compose, array( '-f', 'docker-compose.yml', '-f', 'docker-compose.release.yml' ) ); }
		$mounts = Wstm108_HostAuthority::discover_mounts( $this->context['binding']['project'], $compose,
			fn( array $command ) => $this->query( $command, 'before-' . $action ), $this->environment['WSTM108_HOST_AUTHORITY_ROOT'] );
		$environment['WSTM108_ADMITTED_MOUNTS'] = base64_encode( json_encode( $mounts, JSON_THROW_ON_ERROR ) );
		$arguments = array( PHP_BINARY, __DIR__ . '/untrusted-authority.php', 'reserve' === $action ? 'reserve' : 'run',
			dirname( __DIR__ ) . '/' . $this->context['artifact_directory'] . '/context.json' );
		if ( 'reserve' !== $action ) { $arguments[] = $action; }
		$this->reserving_authority_child = 'reserve' === $action;
		$capture = $this->capture( 'helper-' . $action, $arguments, dirname( __DIR__ ), $environment );
		try {
			$state = self::exact_frame( $capture, 'WSTM108_HOST ' );
			self::require( array_keys( $state ) === array( 'host', 'anchor', 'prepared', 'processes', 'companion', 'retirement_receipt', 'release_authorization' )
				&& $this->context['binding'] === ( $state['host']['binding'] ?? null ), 'helper-state-binding' );
			if ( 'reserve' === $action ) {
				self::require( $this->parent_identity === $state['host']['authority_identity'], 'helper-must-preserve-original-parent' );
			}
			Wstm108_HostAuthority::resume( $state['host'] );
			$this->state = $state;
			if ( 'reserve' === $action ) { ++$this->parent_identity['nlink']; }
			$this->reserving_authority_child = false;
			$this->verify();
		} catch ( Throwable $error ) {
			$code = 0 !== $capture['child_exit'] ? $capture['child_exit'] : 1;
			$this->first_failure = 0 === $this->first_failure ? $code : $this->first_failure;
			$this->failures[] = array( 'action' => $action, 'reason' => 0 !== $capture['child_exit'] ? 'child-failed' : 'frame-refused',
				'child_exit' => $capture['child_exit'], 'capture_complete' => $capture['capture_complete'] );
			throw new RuntimeException( 'Helper action refused; original streams retained.', $code, $error );
		}
	}

	private function export( array $export_identity, array $run, string $status ): void {
		$this->verify_controller_streams();
		$actions = array_map( static fn( $record ) => $record['witness']['action'], $this->state['processes'] ?? array() );
		$proof = array( 'version' => 1, 'binding' => $this->context['binding'], 'run' => $run, 'status' => $status,
			'context_sha256' => hash_file( 'sha256', dirname( __DIR__ ) . '/' . $this->context['artifact_directory'] . '/context.json' ),
			'validated_actions' => $actions, 'release' => array( 'authorization' => isset( $this->state['release_authorization'] ) ? 'precommit_authorized' : 'not_authorized',
				'commit_outcome' => 'not_observed', 'qa_outcome' => 'not_asserted' ) );
		$exporter = new Wstm108_Export( $this->directory . '/export', $export_identity, $this->context['binding'], $run, function (): void {
			$this->verify(); $this->verify_controller_streams();
		} );
		$this->publication = $exporter->publish( $proof, array( 'version' => 1, 'binding' => $this->context['binding'], 'failures' => $this->failures ) );
	}

	private function publish_output( array $output_identity, string $output_path ): void {
		self::require( null !== $this->publication, 'no-safe-publication' );
		$this->verify();
		$this->verify_controller_streams();
		$stream = fopen( 'php://fd/9', 'ab' );
		self::require( is_resource( $stream ), 'publication-channel-absent' );
		try {
			$stat = fstat( $stream );
			self::require( is_array( $stat ) && $output_identity === array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ), 'publication-channel-replaced' );
			Wstm108_Files::read_bound( $output_path, $output_identity );
			$p = $this->publication;
			self::require( false === strpbrk( $p['root'], "\r\n" ), 'publication-path-framing' );
			$bytes = 'untrusted_export_root=' . $p['root'] . "\n"
				. 'untrusted_export_manifest_sha256=' . $p['manifest_sha256'] . "\n"
				. 'untrusted_export_custody=' . base64_encode( json_encode( $p['custody'], JSON_THROW_ON_ERROR ) ) . "\n"
				. 'untrusted_proof_status=' . $p['proof_status'] . "\n"
				. "untrusted_export_status=published\nuntrusted_export_ready=true\n";
			for ( $offset = 0; $offset < strlen( $bytes ); $offset += $written ) {
				$written = fwrite( $stream, substr( $bytes, $offset ) );
				self::require( is_int( $written ) && $written > 0, 'publication-channel-write' );
			}
			self::require( fflush( $stream ) && fsync( $stream ), 'publication-channel-persistence' );
			$record = Wstm108_Files::read_bound( $output_path, $output_identity );
			self::require( substr( $record['bytes'], -strlen( $bytes ) ) === $bytes, 'publication-channel-readback' );
			$this->publication_channel = array( 'path' => $output_path, 'file' => $record );
		} finally { fclose( $stream ); }
		$this->verify_controller_streams();
	}

	private function verify_captures(): void {
		$this->verify();
		$this->verify_controller_streams();
		self::require( 0 === $this->first_failure && array() === $this->failures, 'failed-producer-cannot-release' );
		$names = array( '.', '..', 'controller.stderr.private', 'controller.stdout.private', 'export' );
		foreach ( $this->helper_captures as $name => $capture ) {
			self::require( true === $capture['capture_complete'] && 0 === $capture['child_exit'], 'incomplete-producer-chain' );
			$names[] = $name . '.intent.private.json';
			Wstm108_Files::assert_file( $this->directory . '/' . $name . '.intent.private.json', $capture['intent'] );
			foreach ( array( 'stdout', 'stderr' ) as $stream ) {
				$names[] = $name . '.' . $stream . '.private';
				Wstm108_Files::assert_file( $this->directory . '/' . $name . '.' . $stream . '.private', $capture['streams'][ $stream ] );
			}
		}
		sort( $names, SORT_STRING );
		self::require( $names === scandir( $this->directory ), 'foreign-or-missing-controller-capture' );
	}

	public function run( array $arguments ): int {
		$this->bind_controller_streams();
		require_once __DIR__ . '/untrusted-authority.php';
		$root = $this->environment['WSTM108_HOST_AUTHORITY_ROOT'];
		$before = self::identity( $arguments[2] );
		$after = self::identity( $arguments[3] );
		$child = self::identity( $arguments[4] );
		self::require( $child === $this->identity && $after === Wstm108_HostAuthority::root( $root ), 'bootstrap-root-changed' );
		Wstm108_HostTopology::owned_child_transition( $before, $after, $child );
		$this->parent_identity = $after;
		$output_path = $arguments[8];
		$output_stream = fopen( 'php://fd/9', 'ab' );
		self::require( is_resource( $output_stream ), 'original-publication-channel' );
		$output_stat = fstat( $output_stream ); fclose( $output_stream );
		$output_identity = array_intersect_key( $output_stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) );
		self::require( posix_geteuid() === $output_identity['uid'] && 1 === $output_identity['nlink']
			&& 0100000 === ( $output_identity['mode'] & 0170000 ) && 0 === ( $output_identity['mode'] & 0022 ), 'publication-channel-owner' );
		Wstm108_Files::read_bound( $output_path, $output_identity );
		$owner = $arguments[5]; $mode = $arguments[6]; $artifact_base = $arguments[7];
		self::require( 1 === preg_match( '/^[a-f0-9]{32}$/D', $owner ) && in_array( $mode, array( 'contract', 'e2e', 'all' ), true )
			&& 1 === preg_match( '/^[a-zA-Z0-9_-]+$/D', $artifact_base ), 'bootstrap-nonsecret-inputs' );
		self::require( $this->directory === $root . '/wstm108-bootstrap-' . $owner, 'bootstrap-owner-path' );
		$intent_stream = fopen( 'php://fd/5', 'rb' );
		self::require( is_resource( $intent_stream ), 'original-bootstrap-intent-descriptor' );
		$intent_stat = fstat( $intent_stream ); fclose( $intent_stream );
		self::require( is_array( $intent_stat ), 'bootstrap-intent-descriptor-identity' );
		$intent_identity = array_intersect_key( $intent_stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) );
		$intent = Wstm108_Files::read_bound( $root . '/wstm108-bootstrap-intent-' . $owner . '.private', $intent_identity );
		self::require( $intent['bytes'] === "version=1\nroot={$arguments[2]}\nchild={$this->directory}\n"
			&& 0100600 === $intent['identity']['mode'] && posix_geteuid() === $intent['identity']['uid'], 'bootstrap-original-intent' );
		$run = array( 'id' => $this->environment['WSTM108_RUN_ID'], 'attempt' => $this->environment['WSTM108_RUN_ATTEMPT'], 'job' => $this->environment['WSTM108_JOB'] );
		Wstm108_Export::run( $run );
		$project = $this->environment['COMPOSE_PROJECT_NAME'];
		$compose = array( 'docker', 'compose', '--project-name', $project );
		if ( '' !== ( $this->environment['E2E_PACKAGE_ZIP'] ?? '' ) ) { $compose = array_merge( $compose, array( '-f', 'docker-compose.yml', '-f', 'docker-compose.release.yml' ) ); }
		// Until this returns, only nonsecret endpoint/mount admission is permitted.
		$mounts = Wstm108_HostAuthority::discover_mounts( $project, $compose, array( $this, 'query' ), $root );
		Wstm108_HostAuthority::outside( dirname( $output_path ), $mounts['bind_sources'], $mounts['topology'] );
		$this->verify();
		self::require( mkdir( $this->directory . '/export', 0700 ), 'exclusive-export-reservation' );
		$export_identity = Wstm108_Files::directory( $this->directory . '/export' );
		$updated = Wstm108_Files::directory( $this->directory );
		Wstm108_HostTopology::owned_child_transition( $this->identity, $updated, $export_identity );
		$this->identity['nlink'] = $updated['nlink'];
		$artifact = $artifact_base . '/untrusted-' . $owner;
		$artifact_path = dirname( __DIR__ ) . '/' . $artifact;
		self::require( mkdir( $artifact_path, 0700 ), 'exclusive-stage-evidence' );
		Wstm108_Files::create( $artifact_path . '/stage.log', '' );
		$provenance = $this->capture( 'helper-provenance', array( PHP_BINARY, __DIR__ . '/untrusted-provenance.php', $owner, $project, $mode, $artifact ), dirname( __DIR__ ), $this->environment );
		$this->successful_child( $provenance, 'provenance-helper-failed' );
		$this->context = json_decode( $provenance['streams']['stdout']['bytes'], true, 512, JSON_THROW_ON_ERROR );
		self::require( is_array( $this->context ) && $artifact === $this->context['artifact_directory']
			&& $owner === $this->context['binding']['owner'] && $project === $this->context['binding']['project'], 'provenance-helper-binding' );
		Wstm108_Files::create( $artifact_path . '/context.json', $provenance['streams']['stdout']['bytes'] );
		$acquired = false;
		try {
			$this->action( 'reserve' );
			$arm = $this->capture( 'helper-arm', array( '/bin/bash', '--noprofile', '--norc', '-c',
				'set -Eeuo pipefail; source "$1"; wstm116_arm_retention "$2" "$3"', 'wstm108-arm',
				__DIR__ . '/destructive-retention.sh', $owner, $this->context['binding']['source_sha'] ), dirname( __DIR__ ), $this->environment );
			if ( 0 !== $arm['child_exit'] || '' !== $arm['streams']['stderr']['bytes'] ) {
				throw new RuntimeException( 'Primary arm failed; original streams retained.', 0 !== $arm['child_exit'] ? $arm['child_exit'] : 1 );
			}
			$this->action( 'companion' );
			$this->action( 'acquire' ); $acquired = true;
			foreach ( array( 'original', 'enable', 'enabled', 'runner', 'runner-proof' ) as $action ) { $this->action( $action ); }
			$acquired = false; $this->action( 'restored' );
			foreach ( array( 'finalize', 'retire', 'final-proof', 'clear-primary' ) as $action ) { $this->action( $action ); }
			$this->verify_captures();
			$this->action( 'authorize-release' );
			$this->verify_captures();
			$this->export( $export_identity, $run, 'passed' );
			$this->publish_output( $output_identity, $output_path );
		} catch ( Throwable $error ) {
			$this->first_failure = 0 === $this->first_failure ? ( $error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1 ) : $this->first_failure;
			if ( $acquired ) {
				try { $this->action( 'restored' ); }
				catch ( Throwable $restoration_error ) {
					$this->failures[] = array( 'action' => 'restored', 'reason' => 'restoration-failed', 'child_exit' => null, 'capture_complete' => false );
				}
			}
			if ( array() === $this->failures ) { $this->failures[] = array( 'action' => 'export', 'reason' => 'controller-failed', 'child_exit' => null, 'capture_complete' => false ); }
			if ( null === $this->publication ) {
				try { $this->export( $export_identity, $run, 'failed' ); $this->publish_output( $output_identity, $output_path ); }
				catch ( Throwable $publication_error ) { fwrite( STDERR, "WSTM108 safe failure publication refused; raw evidence remains private.\n" ); }
			}
			return $this->first_failure;
		}
		// All required captures and publication writes precede release. These
		// already-open bootstrap streams are only terminal diagnostic channels.
		$authority = Wstm108_HostAuthority::resume( $this->state['host'] );
		$release = new Wstm108_ReleaseGuard( $authority );
		$this->verify_captures();
		self::require( null !== $this->publication_channel, 'required-publication-channel-absent' );
		Wstm108_Files::assert_file( $this->publication_channel['path'], $this->publication_channel['file'] );
		Wstm108_Export::verify_published( $this->publication['root'], $this->publication['manifest_sha256'], $this->publication['custody'],
			array( 'source' => $this->context['binding']['source_sha'], 'project' => $project, 'run' => $run ) );
		$precommit = function (): bool {
			$this->verify_captures();
			Wstm108_Files::assert_file( $this->publication_channel['path'], $this->publication_channel['file'] );
			Wstm108_Export::verify_published( $this->publication['root'], $this->publication['manifest_sha256'], $this->publication['custody'],
				array( 'source' => $this->context['binding']['source_sha'], 'project' => $this->context['binding']['project'],
					'run' => array( 'id' => $this->environment['WSTM108_RUN_ID'], 'attempt' => $this->environment['WSTM108_RUN_ATTEMPT'], 'job' => $this->environment['WSTM108_JOB'] ) ) );
			$this->verify_controller_streams();
			return true;
		};
		$release->commit( $this->state, $this->context, $precommit );
		return 0;
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		if ( count( $argv ) !== 9 ) { throw new RuntimeException( 'Explicit bootstrap arguments required.' ); }
		$controller = new Wstm108_HostController( $argv[1], Wstm108_HostController::identity( $argv[4] ), getenv() );
		exit( $controller->run( $argv ) );
	} catch ( Throwable $error ) {
		fwrite( STDERR, "WSTM108 controller refused; private diagnostic channel retained; terminal release outcome must not be inferred.\n" );
		exit( $error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1 );
	}
}
