<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-files.php';
require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-provenance.php';

/** A companion recognized by unchanged f93 gates protects its clear/echo interval. */
final class Wstm108_ReleaseGuard {
	public const AUTHORIZATION = 'untrusted-content-release-authorization.receipt.json';
	private Wstm108_HostAuthority $authority;
	private array $host;

	public function __construct( Wstm108_HostAuthority $authority ) {
		$this->authority = $authority;
		$this->host = $authority->handle();
	}

	private static function require( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException( 'WSTM108 release: ' . $message ); }
	}

	private static function metadata( array $file ): array {
		return array( 'identity' => $file['identity'], 'sha256' => $file['sha256'], 'length' => strlen( $file['bytes'] ) );
	}

	private function path( bool $companion ): string {
		$b = $this->host['binding'];
		return $this->host['checkout'] . '/build/wstm116-retention-' . $b['project'] . ( $companion ? '-wstm108-' . $b['owner'] : '' );
	}

	private function private_file( string $name, array $metadata ): array {
		$file = Wstm108_Files::read_bound( $this->host['directory'] . '/' . $name, $metadata['identity'] );
		self::require( self::metadata( $file ) === $metadata, 'independently bound release evidence changed.' );
		return $file;
	}

	public function arm(): array {
		$this->authority->handle();
		$b = $this->host['binding'];
		$build = Wstm108_Files::directory( $this->host['checkout'] . '/build' );
		$primary = Wstm108_Files::file( $this->path( false ) );
		self::require( posix_geteuid() === $build['uid'] && 0 === ( $build['mode'] & 0022 )
			&& posix_geteuid() === $primary['identity']['uid'] && 0100600 === $primary['identity']['mode'], 'primary/build ownership is not the original private arm.' );
		self::require( "owner={$b['owner']}\nproject={$b['project']}\nsource={$b['source_sha']}\n" === $primary['bytes'], 'unchanged primary arm must precede companion creation.' );
		$bytes = "version=1\npurpose=wstm108-release-companion\nowner={$b['owner']}\nproject={$b['project']}\nsource={$b['source_sha']}\n";
		$intent = array( 'version' => 1, 'binding' => $b, 'context_sha256' => $this->host['context_sha256'],
			'build_identity' => $build, 'primary' => $primary, 'companion_bytes' => $bytes );
		$file = Wstm108_Files::create( $this->host['directory'] . '/companion-intent.private.json', json_encode( $intent, JSON_THROW_ON_ERROR ) );
		$record = $intent + array( 'intent' => self::metadata( $file ) );
		self::require( $build === Wstm108_Files::directory( $this->host['checkout'] . '/build' ), 'build changed after durable intent.' );
		Wstm108_Files::assert_file( $this->path( false ), $primary );
		$record['companion'] = Wstm108_Files::create( $this->path( true ), $bytes );
		self::require( 0100600 === $record['companion']['identity']['mode'], 'companion lacks private ownership.' );
		Wstm108_Files::assert_file( $this->path( false ), $primary );
		$file = Wstm108_Files::create( $this->host['directory'] . '/companion-enrollment.private.json', json_encode( $record, JSON_THROW_ON_ERROR ) );
		$record['enrollment'] = self::metadata( $file );
		$this->verify( $record, true );
		return $record;
	}

	public function verify( array $record, bool $primary_present ): void {
		$this->authority->handle();
		self::require( array_keys( $record ) === array( 'version', 'binding', 'context_sha256', 'build_identity', 'primary', 'companion_bytes', 'intent', 'companion', 'enrollment' )
			&& 1 === $record['version'] && $this->host['binding'] === $record['binding'] && $this->host['context_sha256'] === $record['context_sha256']
			&& $record['build_identity'] === Wstm108_Files::directory( $this->host['checkout'] . '/build' ), 'missing or changed original companion/build binding.' );
		$this->private_file( 'companion-intent.private.json', $record['intent'] );
		$enrollment = $this->private_file( 'companion-enrollment.private.json', $record['enrollment'] );
		self::require( json_encode( array_diff_key( $record, array( 'enrollment' => true ) ), JSON_THROW_ON_ERROR ) === $enrollment['bytes'], 'companion enrollment is not the independently captured original.' );
		Wstm108_Files::assert_file( $this->path( true ), $record['companion'] );
		if ( $primary_present ) {
			Wstm108_Files::assert_file( $this->path( false ), $record['primary'] );
		} else {
			clearstatcache( true, $this->path( false ) );
			self::require( false === @lstat( $this->path( false ) ), 'primary is not absent after validated clear.' );
		}
	}

	public function clear_primary( array $record ): array {
		$this->verify( $record, true );
		$b = $this->host['binding'];
		$environment = getenv();
		$environment['COMPOSE_PROJECT_NAME'] = $b['project'];
		$capture = $this->authority->capture( 'clear-primary', array(
			'bash', '--noprofile', '--norc', '-c',
			'set -Eeuo pipefail; set +x; source "$1"; wstm116_clear_retention "$2" "$3"',
			'wstm108-clear', $this->host['checkout'] . '/scripts/destructive-retention.sh', $b['owner'], $b['source_sha'],
		), $this->host['checkout'], $environment );
		try {
			Wstm108_HostAuthority::frame( $capture, 'Retention retired after verified runtime restoration and runner cleanup: project=' . $b['project'] . "\n" );
			$this->verify( $record, false );
		} catch ( Throwable $error ) {
			$code = 0 !== $capture['child_exit'] ? $capture['child_exit'] : 1;
			try {
				$this->authority->receipt( 'clear-primary.process.receipt.json', Wstm108_HostAuthority::witness( $capture, false ) );
			} catch ( Throwable $receipt_error ) {
				throw new RuntimeException( 'Clear outcome could not be durably certified; companion must not be released.', $code, $receipt_error );
			}
			throw new RuntimeException( 'Clear failed or remained ambiguous; primary may be absent, no companion release authorized.', $code, $error );
		}
		$witness = Wstm108_HostAuthority::witness( $capture, true );
		return array( 'witness' => $witness, 'receipt' => $this->authority->receipt( 'clear-primary.process.receipt.json', $witness ) );
	}

	private function prerequisites( array $state, array $context ): void {
		self::require( $this->host['binding'] === $context['binding'], 'source binding differs.' );
		$required = array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'runner-proof', 'restored', 'finalize', 'retire', 'final-proof', 'clear-primary' );
		self::require( $required === array_map( static fn( $p ) => $p['witness']['action'], $state['processes'] ), 'complete successful producer/clear chain required.' );
		$this->authority->verify_processes( $state['processes'] );
		$this->verify( $state['companion'], false );
		$retirement = $this->private_file( 'retirement.receipt.json', $state['retirement_receipt'] );
		self::require( json_decode( $retirement['bytes'], true, 512, JSON_THROW_ON_ERROR ) === array(
			'binding' => $this->host['binding'], 'prepared' => $state['prepared'], 'processes' => array_slice( $state['processes'], 0, 10 ),
		), 'final proof receipt differs from the witnessed retirement.' );
		$file = Wstm108_Files::file( $this->host['checkout'] . '/' . $context['artifact_directory'] . '/context.json' );
		self::require( $this->host['context_sha256'] === $file['sha256'], 'source context changed before release.' );
		foreach ( $context['host_files'] as $path => $sha ) {
			self::require( $sha === Wstm108_Files::file( $this->host['checkout'] . '/' . $path )['sha256'], 'host source changed before release.' );
		}
		self::require( $context['files']['harness'] === Wstm108_Provenance::harness( $this->host['checkout'] )
			&& $context['files']['production'] === Wstm108_Provenance::production( $context['host_production_root'] ), 'source/original-ZIP production or harness inventory changed before release.' );
		if ( null !== $this->host['binding']['package_sha256'] ) {
			$zip = getenv( 'E2E_PACKAGE_ZIP' );
			$zip = is_string( $zip ) ? realpath( $zip ) : false;
			self::require( is_string( $zip ) && $this->host['binding']['package_sha256'] === Wstm108_Files::file( $zip )['sha256'], 'original ZIP bytes changed before release.' );
		}
	}

	private function evidence( array $context ): array {
		$directory = $this->host['checkout'] . '/' . $context['artifact_directory'];
		$names = array( 'context.json', 'stage.log' );
		foreach ( array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'restored', 'finalize', 'retire' ) as $phase ) {
			$names[] = $phase . '.json';
			$names[] = $phase . '.json.http.jsonl';
		}
		sort( $names, SORT_STRING );
		self::require( array_merge( array( '.', '..' ), $names ) === scandir( $directory ), 'public evidence producers are incomplete or foreign.' );
		$files = array();
		foreach ( $names as $name ) { $files[ $name ] = self::metadata( Wstm108_Files::file( $directory . '/' . $name ) ); }
		return $files;
	}

	private function authorization( array $state, array $evidence ): array {
		return array(
			'version' => 1, 'state' => 'precommit_authorized', 'binding' => $this->host['binding'],
			'context_sha256' => $this->host['context_sha256'],
			'prepared_sha256' => hash( 'sha256', json_encode( $state['prepared'], JSON_THROW_ON_ERROR ) ),
			'process_verdicts_sha256' => hash( 'sha256', json_encode( $state['processes'], JSON_THROW_ON_ERROR ) ),
			'evidence_inventory_sha256' => hash( 'sha256', json_encode( $evidence, JSON_THROW_ON_ERROR ) ),
			'primary_guard_absent' => true, 'companion_guard_present' => true,
			'commit_point' => 'final_companion_unlink', 'commit_outcome' => 'not_observed', 'qa_outcome' => 'not_asserted',
		);
	}

	public function authorize( array $state, array $context ): array {
		$this->prerequisites( $state, $context );
		$evidence = $this->evidence( $context );
		$private = Wstm108_Files::create( $this->host['directory'] . '/release-evidence.private.json', json_encode( $evidence, JSON_THROW_ON_ERROR ) );
		$public = Wstm108_Files::create( $this->host['directory'] . '/' . self::AUTHORIZATION, json_encode( $this->authorization( $state, $evidence ), JSON_THROW_ON_ERROR ) );
		$this->prerequisites( $state, $context );
		self::require( $evidence === $this->evidence( $context ), 'evidence changed while authorizing release.' );
		return array( 'private' => self::metadata( $private ), 'public' => self::metadata( $public ) );
	}

	public function commit( array $state, array $context, callable $precommit ): void {
		$this->prerequisites( $state, $context );
		self::require( is_array( $state['release_authorization'] ) && array_keys( $state['release_authorization'] ) === array( 'private', 'public' ), 'no independently held release authorization.' );
		$private = $this->private_file( 'release-evidence.private.json', $state['release_authorization']['private'] );
		$evidence = json_decode( $private['bytes'], true, 512, JSON_THROW_ON_ERROR );
		self::require( $evidence === $this->evidence( $context ), 'evidence changed after authorization.' );
		$public = $this->private_file( self::AUTHORIZATION, $state['release_authorization']['public'] );
		self::require( $public['bytes'] === json_encode( $this->authorization( $state, $evidence ), JSON_THROW_ON_ERROR ), 'release receipt is not the exact typed precommit authorization.' );
		$this->verify( $state['companion'], false );
		$path = $this->path( true );
		self::require( true === $precommit(), 'original controller capture guard vetoed terminal release.' );
		// Successful unlink IS release commit. No post-unlink I/O or success prerequisite.
		self::require( @unlink( $path ), 'terminal unlink did not report success; release outcome must not be inferred.' );
	}
}
