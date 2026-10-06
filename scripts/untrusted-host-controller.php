<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-host-topology.php';
require_once __DIR__ . '/untrusted-host-observation.php';
require_once __DIR__ . '/untrusted-export.php';

/** The controller retains originals and publication custody; children receive neither channel. */
final class Wstm108_HostController {
	public static function terminal_output(): array {
		try { $output = @fopen( 'php://fd/9', 'ab' ); }
		catch ( Throwable $report_error ) { return array( 'handle' => null, 'status' => 'open-exception' ); }
		return array( 'handle' => $output, 'status' => is_resource( $output ) ? 'opened' : 'open-refused' );
	}

	private static function write_terminal_record( $stream, string $bytes ): string {
		if ( ! is_resource( $stream ) ) { return 'unavailable'; }
		try {
			for ( $offset = 0; $offset < strlen( $bytes ); $offset += $written ) {
				$written = @fwrite( $stream, substr( $bytes, $offset ) );
				if ( ! is_int( $written ) || $written <= 0 ) { return 'write-refused'; }
			}
			return @fflush( $stream ) ? 'written' : 'flush-refused';
		} catch ( Throwable $report_error ) {
			return 'stream-exception';
		}
	}

	/** Report closed codes and explicit I/O outcomes without replacing the original refusal exit. */
	public static function report_terminal_failure( Throwable $error, $diagnostic, $output = null, string $output_status = 'opened' ): array {
		$witness = Wstm108_HostTopology::failure_witness( $error );
		$json = '';
		$private = "WSTM108 controller refused; private diagnostic channel retained; terminal release outcome must not be inferred.\n";
		if ( null !== $witness ) {
			$json = json_encode( $witness, JSON_THROW_ON_ERROR );
			$private .= 'WSTM108_ADMISSION_REFUSAL_V1 ' . $json . "\n";
		}
		$site = \Wstm108_AdmissionCallsite::identify( $error );
		$private .= 'WSTM108_ADMISSION_CALLSITE_V1 ' . $site . "\n";
		$public = ( null === $witness ? '' : 'untrusted_admission_failure=' . $json . "\n" )
			. 'untrusted_admission_callsite_v1=' . $site . "\n";
		$channels = array( 'diagnostic' => self::write_terminal_record( $diagnostic, $private ), 'output' => 'not-requested' );
		if ( in_array( $output_status, array( 'open-refused', 'open-exception' ), true ) ) {
			$channels['output'] = $output_status;
		} elseif ( 'opened' !== $output_status ) {
			$channels['output'] = 'unavailable';
		} elseif ( null !== $witness || is_resource( $output ) ) {
			$channels['output'] = self::write_terminal_record( $output, $public );
		}
		$result = array( 'status' => 'reported', 'channels' => $channels, 'outcome_delivery' => array() );
		if ( 'written' === $channels['diagnostic'] && in_array( $channels['output'], array( 'written', 'not-requested' ), true ) ) { return $result; }
		$outcome = json_encode( array( 'phase' => 'diagnostic-report', 'status' => 'diagnostic-io-refused',
			'diagnostic' => $channels['diagnostic'], 'output' => $channels['output'] ), JSON_THROW_ON_ERROR );
		// Frame a fresh line after any incomplete record; never adopt its prefix as a complete witness.
		$result['outcome_delivery'] = array(
			'diagnostic' => self::write_terminal_record( $diagnostic, "\nWSTM108_DIAGNOSTIC_IO_REFUSAL_V1 " . $outcome . "\n" ),
			'output' => self::write_terminal_record( $output, "\nuntrusted_diagnostic_failure=" . $outcome . "\n" ),
		);
		$result['status'] = in_array( 'written', $result['outcome_delivery'], true ) ? 'diagnostic-io-refused' : 'unreported';
		return $result;
	}

	private string $directory;
	private array $identity;
	private array $environment;
	private array $context = array();
	private array $state = array();
	private array $failures = array();
	private array $helper_captures = array();
	private array $query_captures = array();
	private array $observation_captures = array();
	private int $first_failure = 0;
	private ?array $publication = null;
	private ?array $publication_channel = null;
	private ?array $parent_identity = null;
	private bool $reserving_authority_child = false;
	private array $controller_streams = array();
	private int $query_deadline = 0;
	private int $query_count = 0;
	private ?array $kernel_directory = null;
	private array $kernel_captures = array();
	private array $kernel_allocations = array();

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
		if ( Wstm108_HostObservation::enabled( $environment ) ) {
			self::require( posix_geteuid() > 0 && ! isset( $environment['WSTM108_HOST_OBSERVATION_CUSTODY'] ), 'inspection-custody-already-set-or-root-controller' );
		}
	}

	private function reserve_observations(): void {
		if ( ! Wstm108_HostObservation::enabled( $this->environment ) ) { return; }
		$this->verify();
		$directory = $this->directory . '/host-observations';
		self::require( mkdir( $directory, 0700 ), 'exclusive-host-observation-reservation' );
		$child = Wstm108_Files::directory( $directory );
		$updated = Wstm108_Files::directory( $this->directory );
		Wstm108_HostTopology::owned_child_transition( $this->identity, $updated, $child );
		$this->identity = $updated;
		$this->environment['WSTM108_HOST_OBSERVATION_CUSTODY'] = base64_encode( json_encode( array(
			'directory' => $directory, 'identity' => Wstm108_HostTopology::stable_identity( $child ),
		), JSON_THROW_ON_ERROR ) );
		self::require( putenv( 'WSTM108_HOST_OBSERVATION_CUSTODY=' . $this->environment['WSTM108_HOST_OBSERVATION_CUSTODY'] ), 'inspection-custody-environment' );
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
				|| 0 === strpos( $key, 'WSTM108_KERNEL_' )
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

	public function capture( string $name, array $command, string $cwd, array $environment, array $query_streams = array(), $kernel_allocation = null ): array {
		self::require( 1 === preg_match( '/^[a-z][a-z0-9-]{0,95}$/D', $name ) && array() !== $command, 'capture-action' );
		self::require( array() === $query_streams || ( array( 10, 11 ) === array_keys( $query_streams )
			&& is_resource( $query_streams[10] ) && is_resource( $query_streams[11] )
			&& 1 === preg_match( '/-query-[1-9][0-9]*$/D', $name ) ), 'query-original-descriptors' );
		self::require( null === $kernel_allocation || ( is_resource( $kernel_allocation ) && array() === $query_streams
			&& PHP_BINARY === $command[0] && __DIR__ . '/untrusted-authority.php' === ( $command[1] ?? null )
			&& '--kernel-allocation' === end( $command ) ), 'kernel-allocation-descriptor-scope' );
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
			$process = proc_open( $command, array_replace( array( 0 => array( 'file', '/dev/null', 'r' ), 1 => $streams['stdout'], 2 => $streams['stderr'],
				3 => array( 'file', '/dev/null', 'w' ), 4 => array( 'file', '/dev/null', 'w' ), 5 => array( 'file', '/dev/null', 'w' ), 9 => array( 'file', '/dev/null', 'w' ) ),
					$query_streams, null === $kernel_allocation ? array() : array( 5 => $kernel_allocation ) ),
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
		if ( 0 === $this->query_deadline ) { $this->query_deadline = hrtime( true ) + 120000000000; }
		if ( ++$this->query_count > 64 || hrtime( true ) >= $this->query_deadline ) {
			throw new RuntimeException( 'WSTM108 controller refused: admission-query-budget', 78 );
		}
		self::require( 1 === preg_match( '/^[a-z][a-z0-9-]{0,63}$/D', $prefix ), 'admission-query-prefix' );
		$name = $prefix . '-query-' . ++$sequence;
		$directory = $this->directory . '/' . $name . '-supervised';
		self::require( mkdir( $directory, 0700 ), 'exclusive-query-reservation' );
		$child = Wstm108_Files::directory( $directory );
		$updated = Wstm108_Files::directory( $this->directory );
		Wstm108_HostTopology::owned_child_transition( $this->identity, $updated, $child );
		$this->identity = $updated;
		$request = Wstm108_Files::create( $directory . '/request.private.json', json_encode( array(
			'argv' => $command, 'cwd' => dirname( __DIR__ ), 'deadline_ns' => $this->query_deadline,
		), JSON_THROW_ON_ERROR ) );
		self::sync_file( $directory . '/request.private.json', $request );
		$python = realpath( '/usr/bin/python3' );
		self::require( is_string( $python ), 'native-query-supervisor-unavailable' );
		$interpreter = Wstm108_Files::file( $python );
		self::require( 0 === $interpreter['identity']['uid'] && 0 === ( $interpreter['identity']['mode'] & 0022 )
			&& 0 !== ( $interpreter['identity']['mode'] & 0111 ), 'native-query-supervisor-executable' );
		$environment = $this->environment;
		foreach ( array( 'DOCKER_CONTEXT', 'DOCKER_HOST', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH' ) as $key ) { unset( $environment[ $key ] ); }
		$query_streams = array();
		$query_originals = array();
		foreach ( array( 10 => 'stdout', 11 => 'stderr' ) as $fd => $stream ) {
			$mask = umask( 0077 );
			try { $handle = fopen( $directory . '/' . $stream . '.log', 'x+b' ); } finally { umask( $mask ); }
			self::require( is_resource( $handle ), 'query-original-open' );
			$query_streams[ $fd ] = $handle;
			$query_originals[ $stream ] = Wstm108_Files::file( $directory . '/' . $stream . '.log' );
		}
		try {
			$capture = $this->capture( $name, array( $python, '-B', __DIR__ . '/untrusted-query.py', $directory . '/request.private.json' ),
				dirname( __DIR__ ), $environment, $query_streams );
			$this->successful_child( $capture, 'discovery-child-failed' );
			self::require( "WSTM108_QUERY_COMPLETE_V1\n" === $capture['streams']['stdout']['bytes'], 'supervised-query-framing' );
			Wstm108_Files::assert_file( $directory . '/request.private.json', $request );
			Wstm108_Files::assert_file( $python, $interpreter );
			$receipt = json_decode( Wstm108_Files::file( $directory . '/receipt.json' )['bytes'], true, 512, JSON_THROW_ON_ERROR );
			self::require( is_array( $receipt ) && $command === ( $receipt['argv'] ?? null ) && 0 === ( $receipt['exit_code'] ?? null )
				&& true === ( $receipt['root_exit_observed'] ?? null ) && true === ( $receipt['stdout_eof'] ?? null )
				&& true === ( $receipt['stderr_eof'] ?? null ) && false === ( $receipt['truncated'] ?? null )
				&& array_key_exists( 'failure', $receipt ) && null === $receipt['failure']
				&& array() === ( $receipt['secondary_errors'] ?? null ), 'supervised-query-receipt' );
			$originals = array();
			foreach ( array( 'stdout', 'stderr' ) as $stream ) {
				$originals[ $stream ] = Wstm108_Files::read_bound( $directory . '/' . $stream . '.log', $query_originals[ $stream ]['identity'] );
				self::require( strlen( $originals[ $stream ]['bytes'] ) === ( $receipt[ $stream . '_bytes' ] ?? null )
					&& $originals[ $stream ]['sha256'] === ( $receipt[ $stream . '_sha256' ] ?? null )
					&& 0 === ( $receipt[ $stream . '_diagnostic_bytes' ] ?? null )
					&& 0 === ( $receipt[ $stream . '_observed_not_retained' ] ?? null ), 'supervised-query-original-stream' );
				self::sync_file( $directory . '/' . $stream . '.log', $originals[ $stream ] );
			}
			self::require( '' === $originals['stderr']['bytes'], 'supervised-query-stderr' );
			$files = array();
			foreach ( array( 'request.private.json', 'stdout.log', 'stderr.log', 'stdout.overflow.bin', 'stderr.overflow.bin', 'terminal.json', 'receipt.json' ) as $file ) {
				$files[ $file ] = self::metadata( Wstm108_Files::file( $directory . '/' . $file ) );
			}
			$this->query_captures[ basename( $directory ) ] = $files;
			$this->verify();
			$bytes = $originals['stdout']['bytes'];
		} finally {
			foreach ( $query_streams as $handle ) { fclose( $handle ); }
		}
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

	private function begin_query_pass(): void {
		$this->query_deadline = hrtime( true ) + 120000000000;
		$this->query_count = 0;
		require_once __DIR__ . '/untrusted-kernel-mounts.php';
		if ( self::class === \Wstm108_HostController::class ) { \Wstm108_KernelMounts::controller_pass( $this ); }
	}

	public function kernel_query_deadline(): int {
		self::require( $this->query_deadline > 0, 'admission-query-budget' );
		return $this->query_deadline;
	}

	public function kernel_query_slot(): void {
		self::require( ++$this->query_count <= 64 && hrtime( true ) < $this->query_deadline, 'admission-query-budget' );
	}

	public function kernel_custody(): array {
		$this->verify();
		if ( null === $this->kernel_directory ) {
			$directory = $this->directory . '/kernel-mounts';
			self::require( mkdir( $directory, 0700 ), 'exclusive-kernel-mount-reservation' );
			$child = Wstm108_Files::directory( $directory );
			$updated = Wstm108_Files::directory( $this->directory );
			Wstm108_HostTopology::owned_child_transition( $this->identity, $updated, $child );
			$this->identity = $updated;
			$this->kernel_directory = array( 'directory' => $directory, 'identity' => $child );
		}
		self::require( $this->kernel_directory['identity'] === Wstm108_Files::directory( $this->kernel_directory['directory'] ),
			'kernel-mount-custody-changed' );
		return $this->kernel_directory;
	}

	public function kernel_record( string $stem, array $custody, array $files, array $intent ): void {
		$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1] ?? array();
		self::require( 'Wstm108_KernelMounts' === ( $caller['class'] ?? null ) && 'record_completed_capture' === ( $caller['function'] ?? null )
			&& $custody === $this->kernel_directory && ! isset( $this->kernel_captures[ $stem ] )
			&& 1 === preg_match( '/^kernel-[a-f0-9]{32}$/D', $stem ), 'kernel-mount-record-owner' );
		$this->kernel_captures[ $stem ] = array( 'files' => $files, 'intent' => $intent );
	}

	private function action( string $action ): void {
		$environment = $this->environment;
		$environment['WSTM108_HOST_STATE'] = base64_encode( json_encode( $this->state, JSON_THROW_ON_ERROR ) );
		$this->begin_query_pass();
		require_once __DIR__ . '/qa-runtime.php';
		$runtime = $this->query( array( PHP_BINARY, __DIR__ . '/qa-runtime.php', 'frame' ), 'selection-' . $action );
		WstmQaRuntime::assert_binding( $runtime, $this->context['binding'] );
		$mounts = WstmQaRuntime::discover( $runtime,
			fn( array $command ) => $this->query( $command, 'before-' . $action ), $this->environment['WSTM108_HOST_AUTHORITY_ROOT'] );
		$environment['WSTM108_ADMITTED_MOUNTS'] = base64_encode( json_encode( $mounts, JSON_THROW_ON_ERROR ) );
		$arguments = array( PHP_BINARY, __DIR__ . '/untrusted-authority.php', 'reserve' === $action ? 'reserve' : 'run',
			dirname( __DIR__ ) . '/' . $this->context['artifact_directory'] . '/context.json' );
		if ( 'reserve' !== $action ) { $arguments[] = $action; }
		$this->reserving_authority_child = 'reserve' === $action;
		$allocation = null;
		if ( Wstm108_HostTopology::stacked( $mounts['topology']['mounts'] ) ) {
			$share = \Wstm108_KernelMounts::reserve_helper();
			$allocation_started = hrtime( true );
			try {
			$custody = $this->kernel_custody();
			$stat = file_get_contents( '/proc/self/stat' );
			self::require( is_string( $stat ) && strlen( $stat ) <= 65536
				&& 1 === preg_match( '/^([0-9]+) \(.+\) (.+)\n?$/D', $stat, $fields ), 'kernel-parent-start' );
			$fields = explode( ' ', rtrim( $fields[2], "\n" ) );
			self::require( isset( $fields[19] ) && ctype_digit( $fields[19] ), 'kernel-parent-start' );
			$name = 'allocation-' . bin2hex( random_bytes( 16 ) ) . '.private.json';
			$path = $custody['directory'] . '/' . $name;
			$file = Wstm108_Files::create( $path, json_encode( array( 'version' => 1, 'action' => $action,
				'parent_pid' => getmypid(), 'parent_start' => $fields[19],
				'parent_namespace' => array_intersect_key( stat( '/proc/self/ns/mnt' ),
					array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ),
				'context_sha256' => hash( 'sha256', json_encode( $this->context, JSON_THROW_ON_ERROR ) ),
				'authority_sha256' => hash_file( 'sha256', __DIR__ . '/untrusted-authority.php' ),
				'custody' => $custody, 'remaining' => $share ), JSON_THROW_ON_ERROR ) );
			self::require( strlen( $file['bytes'] ) <= 4096, 'kernel-allocation-limit' );
			self::sync_file( $path, $file );
			$this->kernel_allocations[ $name ] = $file;
			$allocation = fopen( $path, 'rb' );
			self::require( is_resource( $allocation ), 'kernel-allocation-open' );
			$opened = fstat( $allocation );
			self::require( is_array( $opened ) && 0 === ftell( $allocation )
				&& $file['identity'] === array_intersect_key( $opened,
					array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ), 'kernel-allocation-original' );
			Wstm108_Files::assert_file( $path, $file );
			$arguments[] = '--kernel-allocation';
			} finally {
				\Wstm108_KernelMounts::storage_interval( $allocation_started, isset( $file['bytes'] ) ? strlen( $file['bytes'] ) : 0 );
			}
		}
		if ( self::class === \Wstm108_HostController::class ) { \Wstm108_KernelMounts::pause_for_capture(); }
		try { $capture = $this->capture( 'helper-' . $action, $arguments, dirname( __DIR__ ), $environment, array(), $allocation ); }
		finally { if ( is_resource( $allocation ) ) { fclose( $allocation ); } }
		if ( null !== $this->kernel_directory ) { $this->import_kernel_captures(); }
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

	private function import_kernel_captures(): void {
		$started = hrtime( true );
		try {
		$directory = $this->kernel_custody()['directory'];
		$names = scandir( $directory );
		self::require( is_array( $names ) && count( $names ) <= 8192, 'kernel-original-inventory-limit' );
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name || isset( $this->kernel_allocations[ $name ] ) ) { continue; }
			self::require( 1 === preg_match( '/^(kernel-[a-f0-9]{32})\.(stdout\.private|stderr\.private|input\.private|kernel\.private|intent\.private\.json)$/D', $name, $match ),
				'foreign-kernel-original' );
			$stem = $match[1];
			if ( isset( $this->kernel_captures[ $stem ] ) ) { continue; }
			$intent = Wstm108_Files::file( $directory . '/' . $stem . '.intent.private.json' );
			$record = json_decode( $intent['bytes'], true, 32, JSON_THROW_ON_ERROR );
			self::require( is_array( $record ) && array_keys( $record ) === array( 'version', 'state', 'exit', 'eof', 'argv', 'executable', 'deadline', 'streams' )
				&& 1 === $record['version'] && 'complete' === $record['state'] && 0 === $record['exit']
				&& array( 1 => true, 2 => true ) === $record['eof'] && is_array( $record['streams'] )
				&& array( 'stdout', 'stderr', 'input', 'kernel' ) === array_keys( $record['streams'] ), 'kernel-helper-capture-incomplete' );
			$files = array();
			foreach ( $record['streams'] as $suffix => $metadata ) {
				self::require( is_array( $metadata ) && array_keys( $metadata ) === array( 'identity', 'sha256', 'length' ), 'kernel-helper-capture-shape' );
				$file = Wstm108_Files::read_bound( $directory . '/' . $stem . '.' . $suffix . '.private', $metadata['identity'] );
				self::require( self::metadata( $file ) === $metadata && strlen( $file['bytes'] ) <= 16777216, 'kernel-helper-original-drift' );
				$files[ $suffix ] = $file;
			}
			$this->kernel_captures[ $stem ] = array( 'files' => $files, 'intent' => $intent );
		}
		} finally { \Wstm108_KernelMounts::storage_interval( $started ); }
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
		if ( self::class === \Wstm108_HostController::class && class_exists( 'Wstm108_KernelMounts', false ) ) {
			\Wstm108_KernelMounts::finish_scope();
		}
		$this->verify();
		$this->verify_controller_streams();
		self::require( 0 === $this->first_failure && array() === $this->failures, 'failed-producer-cannot-release' );
		$names = array( '.', '..', 'controller.stderr.private', 'controller.stdout.private', 'export' );
		if ( Wstm108_HostObservation::enabled( $this->environment ) ) {
			$names[] = 'host-observations';
			$this->observation_captures = Wstm108_HostObservation::retained( $this->environment, $this->observation_captures );
		}
		if ( null !== $this->kernel_directory ) {
			$kernel_started = hrtime( true );
			try {
			$names[] = 'kernel-mounts';
			self::require( $this->kernel_directory['identity'] === Wstm108_Files::directory( $this->kernel_directory['directory'] ),
				'kernel-mount-custody-changed' );
			$kernel_names = array( '.', '..' );
			foreach ( $this->kernel_allocations as $name => $file ) {
				$kernel_names[] = $name;
				Wstm108_Files::assert_file( $this->kernel_directory['directory'] . '/' . $name, $file );
			}
			foreach ( $this->kernel_captures as $stem => $capture ) {
				$kernel_names[] = $stem . '.intent.private.json';
				Wstm108_Files::assert_file( $this->kernel_directory['directory'] . '/' . $stem . '.intent.private.json', $capture['intent'] );
				$record = json_decode( $capture['intent']['bytes'], true, 32, JSON_THROW_ON_ERROR );
				self::require( 'complete' === ( $record['state'] ?? null ) && 0 === ( $record['exit'] ?? null )
					&& array( 1 => true, 2 => true ) === ( $record['eof'] ?? null ), 'kernel-mount-capture-incomplete' );
				foreach ( $capture['files'] as $suffix => $file ) {
					$kernel_names[] = $stem . '.' . $suffix . '.private';
					Wstm108_Files::assert_file( $this->kernel_directory['directory'] . '/' . $stem . '.' . $suffix . '.private', $file );
					self::require( self::metadata( $file ) === $record['streams'][ $suffix ], 'kernel-mount-original-drift' );
				}
			}
			sort( $kernel_names, SORT_STRING );
			self::require( $kernel_names === scandir( $this->kernel_directory['directory'] ), 'foreign-or-missing-kernel-mount-capture' );
			} finally { \Wstm108_KernelMounts::storage_interval( $kernel_started ); }
		}
		foreach ( $this->helper_captures as $name => $capture ) {
			self::require( true === $capture['capture_complete'] && 0 === $capture['child_exit'], 'incomplete-producer-chain' );
			$names[] = $name . '.intent.private.json';
			Wstm108_Files::assert_file( $this->directory . '/' . $name . '.intent.private.json', $capture['intent'] );
			foreach ( array( 'stdout', 'stderr' ) as $stream ) {
				$names[] = $name . '.' . $stream . '.private';
				Wstm108_Files::assert_file( $this->directory . '/' . $name . '.' . $stream . '.private', $capture['streams'][ $stream ] );
			}
		}
		foreach ( $this->query_captures as $name => $files ) {
			$names[] = $name;
			$directory = $this->directory . '/' . $name;
			Wstm108_Files::directory( $directory );
			$expected = array_merge( array( '.', '..' ), array_keys( $files ) );
			sort( $expected, SORT_STRING );
			self::require( $expected === scandir( $directory ), 'foreign-or-missing-query-capture' );
			foreach ( $files as $file => $original ) {
				self::require( self::metadata( Wstm108_Files::read_bound( $directory . '/' . $file, $original['identity'] ) ) === $original, 'query-original-capture-drift' );
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
		self::require( 1 === preg_match( '/^[a-f0-9]{32}$/D', $owner ) && in_array( $mode, array( 'contract', 'e2e', 'all', 'admission' ), true )
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
		// Until this returns, only nonsecret endpoint/mount admission is permitted.
		$this->reserve_observations();
		$this->begin_query_pass();
		require_once __DIR__ . '/qa-runtime.php';
		if ( WstmQaRuntime::floor( $this->environment ) ) { $this->environment['E2E_ARTIFACTS_DIR'] = $artifact_base; }
		$runtime = $this->query( array( PHP_BINARY, __DIR__ . '/qa-runtime.php', 'frame' ), 'selection' );
		$this->environment = array_merge( $this->environment, $runtime['environment'] );
		$mounts = WstmQaRuntime::discover( $runtime, array( $this, 'query' ), $root );
		Wstm108_HostAuthority::outside( dirname( $output_path ), $mounts['bind_sources'], $mounts['topology'] );
		$this->verify();
		if ( 'admission' === $mode ) {
			\Wstm108_KernelMounts::finish_scope();
			$this->verify_controller_streams();
			return 0;
		}
		\Wstm108_KernelMounts::finish_scope();
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
			try { \Wstm108_KernelMounts::fail_scope(); } catch ( Throwable $finalization_error ) {}
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
			\Wstm108_KernelMounts::finish_scope();
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
	\Wstm108_AdmissionCallsite::initialize();
	try {
		if ( count( $argv ) !== 9 ) { throw new RuntimeException( 'Explicit bootstrap arguments required.' ); }
		$controller = new Wstm108_HostController( $argv[1], Wstm108_HostController::identity( $argv[4] ), getenv() );
		exit( $controller->run( $argv ) );
	} catch ( Throwable $error ) {
		$output = Wstm108_HostController::terminal_output();
		$report = Wstm108_HostController::report_terminal_failure( $error, STDERR, $output['handle'], $output['status'] );
		// Neither channel can carry a refusal outcome: retain the structured non-success result and original exit.
		if ( 'unreported' === $report['status'] ) {
			exit( $error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1 );
		}
		exit( $error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1 );
	}
}
