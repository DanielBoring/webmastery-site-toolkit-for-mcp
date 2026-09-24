<?php

require_once __DIR__ . '/untrusted-content-files.php';
require_once __DIR__ . '/untrusted-content-private-wire.php';
require_once __DIR__ . '/untrusted-content-wire.php';

/**
 * Own both proof artifacts before WordPress or any fixture mutation can run.
 * Keep the exclusive-create handles: subsequent writes never reopen a path.
 */
final class Wstm108_Evidence {
	/** @var array<string, resource> */
	private array $handles = array();
	private array $secrets = array();
	private ?Wstm108_PrivateWire $private = null;
	private ?string $scope = null;
	private array $event_ids = array();
	private int $case_start = 0;
	private bool $sealed = false;

	public static function assert_available( string $summary_path ): void {
		\Wstm108_Files::directory( dirname( $summary_path ) );
		$paths = array( 'summary' => $summary_path, 'journal' => $summary_path . '.http.jsonl' );
		foreach ( $paths as $path ) {
			clearstatcache( true, $path );
			if ( file_exists( $path ) || is_link( $path ) ) {
				throw new RuntimeException( 'WSTM108 Evidence path already exists; refusing to overwrite: ' . $path );
			}
		}
	}

	public function __construct( string $summary_path ) {
		self::assert_available( $summary_path );
		$paths = array( 'summary' => $summary_path, 'journal' => $summary_path . '.http.jsonl' );
		try {
			foreach ( $paths as $kind => $path ) {
				$handle = @fopen( $path, 'x+b' );
				if ( false === $handle ) {
					throw new RuntimeException( 'WSTM108 Cannot exclusively reserve evidence path: ' . $path );
				}
				$this->handles[ $kind ] = $handle;
			}
		} catch ( Throwable $error ) {
			$this->close();
			// Never unlink by name: a concurrent process may have replaced the path.
			// Any partial reservation is retained, so retries also fail closed.
			throw $error;
		}
	}

	public function __destruct() {
		$this->close();
	}

	private function close(): void {
		foreach ( $this->handles as $handle ) {
			fclose( $handle );
		}
		$this->handles = array();
	}

	public function secret( string $secret ): void {
		if ( '' !== $secret ) {
			$this->secrets[] = $secret;
			$this->secrets[] = trim( json_encode( $secret, JSON_THROW_ON_ERROR ), '"' );
			$this->secrets[] = rawurlencode( $secret );
		}
	}

	public function redact( string $text ): string {
		return str_replace( $this->secrets, '[REDACTED]', $text );
	}

	public function attach( Wstm108_PrivateWire $private, string $scope ): void {
		if ( null !== $this->private ) {
			throw new RuntimeException( 'WSTM108 Evidence already has a private source-bound scope.' );
		}
		$private->begin( $scope );
		$this->private = $private;
		$this->scope = $scope;
	}

	public function append( array $event ): int {
		if ( null === $this->private || null === $this->scope || $this->sealed ) {
			throw new RuntimeException( 'WSTM108 Original wire has no open independently owned private capture.' );
		}
		$witness = $this->private->capture( $this->scope, $event );
		$this->event_ids[] = $witness['id'];
		$this->write( 'journal', json_encode( $witness, JSON_THROW_ON_ERROR ) . "\n" );
		return $witness['id'];
	}

	public function validate( int $id, string $parser, ?array $validation = null ): void {
		if ( null === $this->private || null === $this->scope || ! in_array( $id, $this->event_ids, true ) || $this->sealed ) {
			throw new RuntimeException( 'WSTM108 Foreign or closed original-wire verdict.' );
		}
		$this->write( 'journal', json_encode( $this->private->validate( $this->scope, $id, $parser, $validation ), JSON_THROW_ON_ERROR ) . "\n" );
	}

	public function begin_case(): void {
		$this->case_start = count( $this->event_ids );
	}

	public function case_verdict( array $case ): void {
		$id = $this->append( array( 'boundary' => 'case-result', 'case' => $case ) );
		$this->validate( $id, 'case-result' );
		$this->private->case_verdict( $this->scope, $case['label'], $case['passed'], array_slice( $this->event_ids, $this->case_start ), $case );
	}

	public function seal( bool $passed, array $summary ): array {
		if ( null === $this->private || null === $this->scope || $this->sealed ) {
			throw new RuntimeException( 'WSTM108 Missing or duplicate private semantic seal.' );
		}
		$proof = $this->private->seal( $this->scope, $passed, self::public_summary( $summary ) );
		$this->sealed = true;
		return $proof;
	}

	private static function digest( $value ): string {
		return hash( 'sha256', wstm108_wire_canonical( $value ) );
	}

	/** Catalog names are discovered, not reconstructed from a guessed sanitizer. */
	public static function tool( object $tool ): array {
		if ( ! is_string( $tool->name ?? null ) || 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,128}$/D', $tool->name )
			|| ! ( $tool->inputSchema ?? null ) instanceof stdClass ) {
			throw new RuntimeException( 'WSTM108 Invalid owned catalog descriptor; original remains private.' );
		}
		$hints = array();
		foreach ( array( 'readOnlyHint', 'destructiveHint', 'idempotentHint' ) as $hint ) {
			if ( isset( $tool->annotations->$hint ) ) {
				if ( ! is_bool( $tool->annotations->$hint ) ) {
					throw new RuntimeException( 'WSTM108 Nontyped annotation hint; original remains private.' );
				}
				$hints[ $hint ] = $tool->annotations->$hint;
			}
		}
		return array(
			'name' => $tool->name, 'input_schema_sha256' => self::digest( $tool->inputSchema ),
			'output_schema_sha256' => self::digest( $tool->outputSchema ?? null ),
			'descriptor_sha256' => self::digest( $tool ), 'annotations' => (object) $hints,
		);
	}

	public static function runtime( $runtime ): array {
		$value = (array) $runtime;
		$abilities = (array) ( $value['abilities'] ?? array() );
		foreach ( $abilities as $name => $sha ) {
			if ( 1 !== preg_match( '/^[a-zA-Z0-9_\/.-]+$/D', $name ) || ! is_string( $sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha ) ) {
				throw new RuntimeException( 'WSTM108 Invalid runtime schema witness.' );
			}
		}
		return array( 'sha256' => self::digest( $runtime ), 'abilities' => (object) $abilities );
	}

	/** Never publish arbitrary response, schema, exception or provider text. */
	public static function public_summary( array $summary ): array {
		$public = array_intersect_key( $summary, array_flip( array(
			'operation', 'binding', 'stage_context_sha256', 'actual_files', 'completed', 'cleanup_complete',
			'passed', 'failed', 'status', 'expected_labels', 'boundaries', 'invoked_boundaries', 'blocked',
			'runner_sha256', 'fixture_sha256', 'evidence_sha256', 'http_journal_sha256',
			'stage_options_absent', 'retired', 'prepared', 'wire_proof', 'process_verdicts',
		) ) );
		$public['private_summary_sha256'] = hash( 'sha256', serialize( $summary ) );
		foreach ( array( 'error', 'fatal', 'cleanup_error' ) as $key ) {
			if ( array_key_exists( $key, $summary ) ) { $public[ $key . '_sha256' ] = hash( 'sha256', serialize( $summary[ $key ] ) ); }
		}
		if ( isset( $summary['blocked'] ) && array() !== $summary['blocked'] ) {
			$public['blocked'] = array( 'unavailable-prerequisite' );
		}
		if ( isset( $summary['actual_runtime'] ) ) { $public['actual_runtime'] = self::runtime( $summary['actual_runtime'] ); }
		if ( isset( $summary['state'] ) ) {
			$state = $summary['state'];
			$public['state'] = array_intersect_key( $state, array_flip( array( 'binding', 'config_sha256', 'config_identity', 'mu_identity' ) ) );
			foreach ( array( 'original_attestation', 'enabled_attestation', 'restored_attestation' ) as $key ) {
				$boot = $state[ $key ] ?? null;
				if ( null === $boot ) { $public['state'][ $key ] = null; continue; }
				// The lifecycle only saves these after exact identity/runtime validation.
				$public['state'][ $key ] = array(
					'identity' => $boot['identity'], 'runtime' => self::runtime( $boot['runtime'] ),
					'php_sha256' => self::digest( $boot['php'] ), 'sapi' => $boot['sapi'],
				);
			}
		}
		if ( isset( $summary['providers'] ) ) {
			$public['providers'] = array();
			foreach ( array( 'mcp_adapter', 'yoast', 'seopress' ) as $key ) {
				if ( ! isset( $summary['providers'][ $key ] ) ) { continue; }
				$provider = $summary['providers'][ $key ];
				$public['providers'][ $key ] = is_bool( $provider ) ? $provider : array(
					'active' => true === ( $provider['active'] ?? null ),
					'version_sha256' => self::digest( $provider['version'] ?? null ),
					'path_sha256' => self::digest( $provider['path'] ?? null ),
				);
			}
		}
		foreach ( array( 'wordpress', 'php', 'adapter' ) as $key ) {
			if ( isset( $summary[ $key ] ) ) { $public[ $key . '_sha256' ] = self::digest( $summary[ $key ] ); }
		}
		if ( isset( $summary['registered'] ) ) {
			$public['registered'] = array();
			foreach ( $summary['registered'] as $name => $registration ) {
				$r = (array) $registration;
				$meta = (array) $r['meta'];
				$annotations = (array) ( $meta['annotations'] ?? array() );
				$hints = array();
				foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $key ) {
					if ( ! is_bool( $annotations[ $key ] ?? null ) ) { throw new RuntimeException( 'WSTM108 Missing typed registered annotation.' ); }
					$hints[ $key ] = $annotations[ $key ];
				}
				$public['registered'][ $name ] = array(
					'name' => $name, 'annotations' => $hints,
					'input_schema_sha256' => self::digest( $r['input_schema'] ), 'output_schema_sha256' => self::digest( $r['output_schema'] ),
					'schema_sha256' => self::digest( array( 'label' => $r['label'], 'description' => $r['description'], 'input' => $r['input_schema'], 'output' => $r['output_schema'], 'meta' => $r['meta'] ) ),
				);
			}
		}
		if ( isset( $summary['actors'] ) ) {
			$public['actors'] = array();
			foreach ( $summary['actors'] as $role => $actor ) {
				if ( ! in_array( $role, array( 'administrator', 'subscriber', 'reader' ), true ) ) { throw new RuntimeException( 'WSTM108 Foreign actor witness.' ); }
				$public['actors'][ $role ] = array(
					'id' => $actor['id'], 'roles_sha256' => self::digest( $actor['roles'] ), 'caps_sha256' => self::digest( $actor['caps'] ),
					'list_users' => true === ( ( (array) $actor['caps'] )['list_users'] ?? null ),
				);
			}
		}
		if ( isset( $summary['catalogs'] ) ) {
			$public['catalogs'] = array();
			foreach ( $summary['catalogs'] as $boundary => $tools ) {
				if ( 1 !== preg_match( '/^(gateway|individual):(administrator|subscriber|reader)$/D', $boundary ) ) { throw new RuntimeException( 'WSTM108 Foreign catalog witness.' ); }
				$public['catalogs'][ $boundary ] = array_map( static fn( $tool ) => self::tool( (object) $tool ), $tools );
			}
		}
		if ( isset( $summary['cases'] ) ) {
			$public['cases'] = array();
			foreach ( $summary['cases'] as $case ) {
				$record = array( 'label' => $case['label'], 'passed' => $case['passed'], 'private_case_sha256' => hash( 'sha256', serialize( $case ) ) );
				if ( true === $case['passed'] && isset( $case['descriptor'] ) ) { $record['descriptor'] = self::tool( (object) $case['descriptor'] ); }
				$public['cases'][] = $record;
			}
		}
		if ( isset( $summary['resource_proof'] ) ) {
			$resources = $summary['resource_proof'];
			$public['resource_proof_sha256'] = self::digest( $resources );
			if ( true === ( $resources['cleanup_complete'] ?? null ) && 0 === ( $resources['failed'] ?? null ) && null === ( $resources['first_error'] ?? null ) ) {
				Wstm108_Resources::validate_proof( $resources, $summary['binding'] );
				$public['resource_proof'] = $resources;
			}
		}
		return $public;
	}

	public function save( array $summary ): void {
		if ( null !== $this->private && ! $this->sealed ) {
			$id = $this->append( array( 'boundary' => 'local-summary', 'summary' => $summary ) );
			$this->validate( $id, 'local-summary' );
		}
		$text = json_encode( self::public_summary( $summary ), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . "\n";
		if ( ! rewind( $this->handles['summary'] ) || ! ftruncate( $this->handles['summary'], 0 ) ) {
			throw new RuntimeException( 'WSTM108 Cannot reset owned proof summary.' );
		}
		$this->write( 'summary', $text );
	}

	private function write( string $kind, string $text ): void {
		$offset = 0;
		while ( $offset < strlen( $text ) ) {
			$written = fwrite( $this->handles[ $kind ], substr( $text, $offset ) );
			if ( false === $written || 0 === $written ) {
				throw new RuntimeException( 'WSTM108 Cannot persist owned ' . $kind . ' evidence.' );
			}
			$offset += $written;
		}
		if ( ! fflush( $this->handles[ $kind ] ) ) {
			throw new RuntimeException( 'WSTM108 Cannot flush owned ' . $kind . ' evidence.' );
		}
	}
}
