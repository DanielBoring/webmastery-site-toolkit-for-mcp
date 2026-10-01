<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-files.php';

/** Closed projections only. Publication safety is independent of runtime success. */
final class Wstm108_Export {
	public const FILES = array( 'untrusted-export-manifest.json', 'untrusted-failure-witnesses.json', 'untrusted-proof.json' );
	public const ACTIONS = array( 'admission', 'provenance', 'reserve', 'arm', 'companion', 'acquire', 'original', 'enable', 'enabled',
		'runner', 'runner-proof', 'restored', 'finalize', 'retire', 'final-proof', 'clear-primary', 'authorize-release', 'export' );
	public const REASONS = array( 'blocked', 'ownership-refused', 'capture-incomplete', 'child-failed', 'frame-refused',
		'semantic-failed', 'restoration-failed', 'publication-refused', 'controller-failed' );
	private string $directory;
	private array $identity;
	private array $binding;
	private array $run;
	private $guard;

	private static function require( bool $condition, string $reason ): void {
		if ( ! $condition ) { throw new RuntimeException( 'WSTM108 export refused: ' . $reason ); }
	}

	private static function keys( array $value, array $keys ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		return $actual === $keys;
	}

	private static function hash( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	public static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	public static function require_host(): void {
		self::require( PHP_VERSION_ID >= 80100 && 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' )
			&& function_exists( 'fsync' ) && ( new ReflectionFunction( 'fsync' ) )->isInternal(),
			'host-requires-native-linux-php81-posix-and-builtin-fsync' );
	}

	public static function binding( array $value ): void {
		self::require( self::keys( $value, array( 'owner', 'project', 'source_sha', 'tree_sha', 'package_sha256' ) )
			&& is_string( $value['owner'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $value['owner'] )
			&& is_string( $value['project'] ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,127}$/D', $value['project'] )
			&& is_string( $value['source_sha'] ) && 1 === preg_match( '/^[a-f0-9]{40}$/D', $value['source_sha'] )
			&& is_string( $value['tree_sha'] ) && 1 === preg_match( '/^[a-f0-9]{40}$/D', $value['tree_sha'] )
			&& ( null === $value['package_sha256'] || self::hash( $value['package_sha256'] ) ), 'binding-shape' );
	}

	public static function proof( array $value ): void {
		self::require( self::keys( $value, array( 'version', 'binding', 'run', 'status', 'context_sha256', 'validated_actions', 'release' ) )
			&& 1 === $value['version'] && is_array( $value['binding'] )
			&& is_array( $value['run'] )
			&& in_array( $value['status'], array( 'passed', 'failed', 'unknown' ), true )
			&& ( null === $value['context_sha256'] || self::hash( $value['context_sha256'] ) )
			&& is_array( $value['validated_actions'] ) && self::is_list( $value['validated_actions'] )
			&& is_array( $value['release'] ), 'proof-shape' );
		self::binding( $value['binding'] );
		self::run( $value['run'] );
		$seen = array();
		foreach ( $value['validated_actions'] as $action ) {
			self::require( is_string( $action ) && in_array( $action, self::ACTIONS, true ) && ! isset( $seen[ $action ] ), 'unsafe-action' );
			$seen[ $action ] = true;
		}
		$release = $value['release'];
		self::require( self::keys( $release, array( 'authorization', 'commit_outcome', 'qa_outcome' ) )
			&& in_array( $release['authorization'], array( 'not_authorized', 'precommit_authorized' ), true )
			&& 'not_observed' === $release['commit_outcome'] && 'not_asserted' === $release['qa_outcome'], 'unsafe-release-claim' );
		if ( 'passed' === $value['status'] ) {
			self::require( self::hash( $value['context_sha256'] ) && 'precommit_authorized' === $release['authorization']
				&& array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'runner-proof', 'restored', 'finalize', 'retire', 'final-proof', 'clear-primary' ) === $value['validated_actions'],
				'incomplete-success-projection' );
		}
	}

	public static function run( array $value ): void {
		self::require( self::keys( $value, array( 'id', 'attempt', 'job' ) )
			&& is_string( $value['id'] ) && 1 === preg_match( '/^[1-9][0-9]{0,19}$/D', $value['id'] )
			&& is_string( $value['attempt'] ) && 1 === preg_match( '/^[1-9][0-9]{0,9}$/D', $value['attempt'] )
			&& is_string( $value['job'] ) && 1 === preg_match( '/^[a-zA-Z0-9_-]{1,128}$/D', $value['job'] ), 'run-binding' );
	}

	public static function failures( array $value, array $binding ): void {
		self::require( self::keys( $value, array( 'version', 'binding', 'failures' ) )
			&& 1 === $value['version'] && $binding === $value['binding'] && is_array( $value['failures'] )
			&& self::is_list( $value['failures'] ) && count( $value['failures'] ) <= 32, 'failure-witness-shape' );
		foreach ( $value['failures'] as $failure ) {
			self::require( is_array( $failure ) && self::keys( $failure, array( 'action', 'reason', 'child_exit', 'capture_complete' ) )
				&& in_array( $failure['action'], self::ACTIONS, true ) && in_array( $failure['reason'], self::REASONS, true )
				&& ( null === $failure['child_exit'] || ( is_int( $failure['child_exit'] ) && $failure['child_exit'] >= 0 && $failure['child_exit'] <= 255 ) )
				&& is_bool( $failure['capture_complete'] ), 'unsafe-failure-witness' );
		}
	}

	public function __construct( string $directory, array $identity, array $binding, array $run, callable $guard ) {
		self::require_host();
		self::binding( $binding );
		self::run( $run );
		$this->directory = $directory;
		$this->identity = $identity;
		$this->binding = $binding;
		$this->run = $run;
		$this->guard = $guard;
		$this->verify_directory();
		self::require( array( '.', '..' ) === scandir( $directory ), 'export-reservation-collision' );
	}

	private function verify_directory(): void {
		( $this->guard )();
		self::require( $this->identity === Wstm108_Files::directory( $this->directory )
			&& 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' )
			&& posix_geteuid() === $this->identity['uid'] && 0040700 === $this->identity['mode'], 'export-custody' );
	}

	private static function sync( string $path, array $expected, bool $directory = false ): void {
		$handle = @fopen( $path, 'rb' );
		self::require( is_resource( $handle ), 'sync-open' );
		try {
			$stat = fstat( $handle );
			$identity = is_array( $stat ) ? array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ) : null;
			self::require( $identity === ( $directory ? $expected : $expected['identity'] ) && fsync( $handle ), 'sync-identity-or-persistence' );
		} finally { fclose( $handle ); }
		if ( $directory ) { self::require( $expected === Wstm108_Files::directory( $path ), 'directory-changed-after-sync' ); }
		else { Wstm108_Files::assert_file( $path, $expected ); }
	}

	public function publish( array $proof, array $failures ): array {
		$this->verify_directory();
		self::proof( $proof );
		self::require( $this->binding === $proof['binding'] && $this->run === $proof['run'], 'foreign-proof' );
		self::failures( $failures, $this->binding );
		self::require( 'passed' !== $proof['status'] || array() === $failures['failures'], 'failure-witness-cannot-promote-success' );
		self::require( array( '.', '..' ) === scandir( $this->directory ), 'export-reservation-collision' );
		$originals = array();
		$digests = array();
		foreach ( array( 'untrusted-proof.json' => $proof, 'untrusted-failure-witnesses.json' => $failures ) as $name => $value ) {
			$this->verify_directory();
			$file = Wstm108_Files::create( $this->directory . '/' . $name, json_encode( $value, JSON_THROW_ON_ERROR ) . "\n" );
			self::sync( $this->directory . '/' . $name, $file );
			$originals[ $name ] = $file;
			$digests[ $name ] = $file['sha256'];
		}
		ksort( $digests, SORT_STRING );
		$manifest = array( 'version' => 1, 'binding' => $this->binding, 'files' => $digests );
		$name = 'untrusted-export-manifest.json';
		$originals[ $name ] = Wstm108_Files::create( $this->directory . '/' . $name, json_encode( $manifest, JSON_THROW_ON_ERROR ) . "\n" );
		self::sync( $this->directory . '/' . $name, $originals[ $name ] );
		$custody = array();
		foreach ( $originals as $name => $file ) {
			$this->verify_directory();
			Wstm108_Files::assert_file( $this->directory . '/' . $name, $file );
			self::require( chmod( $this->directory . '/' . $name, 0400 ), 'seal-permissions' );
			$file['identity']['mode'] = 0100400;
			Wstm108_Files::assert_file( $this->directory . '/' . $name, $file );
			self::sync( $this->directory . '/' . $name, $file );
			$custody[ $name ] = array( 'identity' => $file['identity'], 'sha256' => $file['sha256'], 'length' => strlen( $file['bytes'] ) );
		}
		ksort( $custody, SORT_STRING );
		self::sync( $this->directory, $this->identity, true );
		$publication = array( 'version' => 1, 'directory' => $this->identity, 'files' => $custody );
		self::verify_published( $this->directory, $originals['untrusted-export-manifest.json']['sha256'], $publication,
			array( 'source' => $this->binding['source_sha'], 'project' => $this->binding['project'], 'run' => $this->run ) );
		return array( 'root' => $this->directory, 'manifest_sha256' => $originals['untrusted-export-manifest.json']['sha256'],
			'custody' => $publication, 'proof_status' => $proof['status'] );
	}

	public static function verify_published( string $root, string $sha, array $custody, array $expected ): void {
		self::require_host();
		self::require( self::keys( $expected, array( 'source', 'project', 'run' ) ) && is_array( $expected['run'] ), 'expected-binding-shape' );
		self::run( $expected['run'] );
		self::require( self::hash( $sha ) && self::keys( $custody, array( 'version', 'directory', 'files' ) )
			&& 1 === $custody['version'] && is_array( $custody['directory'] ) && is_array( $custody['files'] )
			&& array_keys( $custody['files'] ) === self::FILES && $custody['directory'] === Wstm108_Files::directory( $root )
			&& array_merge( array( '.', '..' ), self::FILES ) === scandir( $root ), 'published-inventory-or-custody' );
		self::require( 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' )
			&& posix_geteuid() === $custody['directory']['uid'] && 0040700 === $custody['directory']['mode'], 'published-root-owner' );
		$values = array();
		foreach ( $custody['files'] as $name => $record ) {
			self::require( is_array( $record ) && self::keys( $record, array( 'identity', 'sha256', 'length' ) )
				&& is_array( $record['identity'] ) && self::hash( $record['sha256'] ) && is_int( $record['length'] )
				&& posix_geteuid() === $record['identity']['uid'] && 0100400 === $record['identity']['mode'], 'published-file-metadata' );
			$file = Wstm108_Files::read_bound( $root . '/' . $name, $record['identity'] );
			self::require( $file['sha256'] === $record['sha256'] && strlen( $file['bytes'] ) === $record['length'], 'published-file-changed' );
			$values[ $name ] = json_decode( $file['bytes'], true, 512, JSON_THROW_ON_ERROR );
			self::require( is_array( $values[ $name ] ), 'published-nonobject' );
		}
		$proof = $values['untrusted-proof.json'];
		self::proof( $proof );
		self::require( $expected['source'] === $proof['binding']['source_sha'] && $expected['project'] === $proof['binding']['project']
			&& $expected['run'] === $proof['run'], 'unexpected-source-project-or-run' );
		self::failures( $values['untrusted-failure-witnesses.json'], $proof['binding'] );
		self::require( 'passed' !== $proof['status'] || array() === $values['untrusted-failure-witnesses.json']['failures'], 'published-failure-cannot-promote' );
		$manifest = $values['untrusted-export-manifest.json'];
		self::require( self::keys( $manifest, array( 'version', 'binding', 'files' ) ) && 1 === $manifest['version']
			&& $proof['binding'] === $manifest['binding'] && $sha === $custody['files']['untrusted-export-manifest.json']['sha256']
			&& array( 'untrusted-failure-witnesses.json' => $custody['files']['untrusted-failure-witnesses.json']['sha256'],
				'untrusted-proof.json' => $custody['files']['untrusted-proof.json']['sha256'] ) === $manifest['files'], 'published-manifest-binding' );
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		Wstm108_Export::require_host();
		if ( 'verify-published' !== ( $argv[1] ?? null ) ) { throw new RuntimeException( 'Unknown export command.' ); }
		$encoded = getenv( 'WSTM108_EXPORT_CUSTODY' );
		$bytes = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
		if ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $encoded ) { throw new RuntimeException( 'Invalid original export custody.' ); }
		$root = getenv( 'WSTM108_EXPORT_ROOT' );
		$allowed = realpath( getenv( 'WSTM108_EXPECTED_EXPORT_PARENT' ) ?: '' );
		if ( ! is_string( $root ) || ! is_string( $allowed )
			|| ! preg_match( '~^' . preg_quote( $allowed, '~' ) . '/wstm108-bootstrap-[a-f0-9]{32}/export$~D', $root )
			|| realpath( $root ) !== $root ) {
			throw new RuntimeException( 'Export path is outside the expected independent parent.' );
		}
		Wstm108_Export::verify_published( $root, getenv( 'WSTM108_EXPORT_SHA256' ) ?: '',
			json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR ), array(
				'source' => getenv( 'WSTM108_EXPECTED_SOURCE' ), 'project' => getenv( 'WSTM108_EXPECTED_PROJECT' ),
				'run' => array( 'id' => getenv( 'WSTM108_EXPECTED_RUN' ), 'attempt' => getenv( 'WSTM108_EXPECTED_ATTEMPT' ), 'job' => getenv( 'WSTM108_EXPECTED_JOB' ) ),
			) );
		echo "Verified closed owned untrusted export; no QA or release success inferred.\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, "WSTM108 export selection refused; no raw artifact fallback.\n" );
		exit( 1 );
	}
}
