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
	private array $paths = array();
	private array $files = array();
	private int $reserved_bytes = 0;
	private bool $write_failed = false;
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
		$this->paths = $paths;
		$mask = umask( 0077 );
		try {
			foreach ( $paths as $kind => $path ) {
				$handle = @fopen( $path, 'x+b' );
				if ( false === $handle ) {
					throw new RuntimeException( 'WSTM108 Cannot exclusively reserve evidence path: ' . $path );
				}
				$this->handles[ $kind ] = $handle;
				$this->files[ $kind ] = Wstm108_Files::file( $path );
			}
		} catch ( Throwable $error ) {
			$this->close();
			// Never unlink by name: a concurrent process may have replaced the path.
			// Any partial reservation is retained, so retries also fail closed.
			throw $error;
		} finally {
			umask( $mask );
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
		if ( null === $this->private || null === $this->scope || $this->sealed || $this->write_failed ) {
			throw new RuntimeException( 'WSTM108 Missing or duplicate private semantic seal.' );
		}
		$final = $summary;
		if ( array_key_exists( 'cases', $final ) ) { $final['cases'] = null; }
		$references = array();
		foreach ( $summary['cases'] ?? array() as $case ) {
			$references[] = array( 'label' => $case['label'], 'passed' => $case['passed'],
				'sha256' => hash( 'sha256', serialize( $case ) ) );
		}
		$event = array( 'boundary' => 'local-summary', 'schema' => 'wstm108-final-summary-v2',
			'summary' => $final, 'case_refs' => $references );
		$witness = $this->private->capture_final( $this->scope, $event );
		$this->event_ids[] = $witness['id'];
		$this->write( 'journal', json_encode( $witness, JSON_THROW_ON_ERROR ) . "\n" );
		$this->validate( $witness['id'], 'local-summary' );
		$proof = $this->private->seal( $this->scope, $passed, self::public_summary( $summary ) );
		$this->sealed = true;
		return $proof;
	}

	public function finalize( array $summary, bool $passed ): array {
		if ( $this->sealed || ( ! $passed && 'passed' === ( $summary['status'] ?? null ) )
			|| ( $passed && ( 'passed' !== ( $summary['status'] ?? null )
			|| true !== ( $summary['completed'] ?? null ) || true !== ( $summary['cleanup_complete'] ?? null )
			|| 0 !== ( $summary['failed'] ?? null ) ) ) ) {
			throw new RuntimeException( 'WSTM108 Final evidence status or closed scope refused.' );
		}
		$this->save( $summary );
		$summary['wire_proof'] = $this->seal( $passed, $summary );
		$summary['http_journal_sha256'] = Wstm108_Files::file( $this->paths['journal'] )['sha256'];
		$this->save( $summary );
		return $summary;
	}

	private static function digest( $value ): string {
		return hash( 'sha256', wstm108_wire_canonical( $value ) );
	}

	private const SUMMARY_NODES = 131072;
	private const SUMMARY_BYTES = 33554432;

	private static function summary_reserve( int $length, array &$budget ): void {
		if ( $length < 0 || $length > self::SUMMARY_BYTES - $budget['bytes'] ) {
			throw new RuntimeException( 'WSTM108 Summary commitment byte boundary exceeded.' );
		}
		$budget['bytes'] += $length;
	}

	private static function summary_emit( HashContext $hash, string $bytes, array &$budget ): void {
		self::summary_reserve( strlen( $bytes ), $budget );
		hash_update( $hash, $bytes );
	}

	private static function summary_value( HashContext $hash, $value, int $depth, array &$budget, SplObjectStorage $active ): void {
		if ( $depth > 512 || $budget['nodes'] >= self::SUMMARY_NODES ) {
			throw new RuntimeException( 'WSTM108 Summary commitment node or nesting boundary exceeded.' );
		}
		++$budget['nodes'];
		if ( is_array( $value ) || $value instanceof stdClass ) {
			$object = $value instanceof stdClass;
			if ( $object && $active->contains( $value ) ) {
				throw new RuntimeException( 'WSTM108 Summary commitment cyclic object refused.' );
			}
			$remaining = intdiv( self::SUMMARY_NODES - $budget['nodes'], 2 );
			$count = $object ? 0 : count( $value );
			if ( $object ) {
				foreach ( $value as $item ) {
					if ( ++$count > $remaining ) { break; }
				}
				unset( $item );
			}
			if ( $count > $remaining ) {
				throw new RuntimeException( 'WSTM108 Summary commitment container node boundary exceeded.' );
			}
			self::summary_emit( $hash, ( $object ? 'O' : 'A' ) . $count . ':', $budget );
			if ( $object ) { $active->attach( $value ); }
			try {
				foreach ( $value as $key => $item ) {
					self::summary_value( $hash, $key, $depth + 1, $budget, $active );
					self::summary_value( $hash, $item, $depth + 1, $budget, $active );
				}
				self::summary_emit( $hash, 'E', $budget );
			} finally {
				if ( $object ) { $active->detach( $value ); }
			}
			return;
		}
		if ( is_string( $value ) ) {
			$length = strlen( $value );
			$header = 'S' . $length . ':';
			self::summary_reserve( strlen( $header ) + $length, $budget );
			hash_update( $hash, $header );
			for ( $offset = 0; $offset < $length; $offset += 65536 ) {
				hash_update( $hash, substr( $value, $offset, 65536 ) );
			}
			return;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value )
			|| ( is_float( $value ) && is_finite( $value ) ) ) {
			self::summary_emit( $hash, serialize( $value ), $budget );
			return;
		}
		throw new RuntimeException( 'WSTM108 Unsupported summary value type in exact commitment.' );
	}

	public static function summary_commitment( array $summary ): string {
		$hash = hash_init( 'sha256' );
		$budget = array( 'nodes' => 0, 'bytes' => 0 );
		self::summary_emit( $hash, "WSTM108-TYPED-SUMMARY-2\n", $budget );
		self::summary_value( $hash, $summary, 0, $budget, new SplObjectStorage() );
		return hash_final( $hash );
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
		$public['private_summary_encoding'] = 'wstm108-typed-value-stream-v2';
		$public['private_summary_sha256'] = self::summary_commitment( $summary );
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
		$text = json_encode( self::public_summary( $summary ), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . "\n";
		$this->write( 'summary', $text, true );
	}

	private function write( string $kind, string $text, bool $replace = false ): void {
		$previous = $this->files[ $kind ]['bytes'];
		$length = strlen( $text ) + ( $replace ? 0 : strlen( $previous ) );
		if ( $this->write_failed || $length > 33554432 || $this->reserved_bytes + strlen( $text ) > 134217728 ) {
			throw new RuntimeException( 'WSTM108 Finite owned evidence write quota or retained failure refused.' );
		}
		$this->reserved_bytes += strlen( $text );
		$this->write_failed = true;
		if ( 'journal' === $kind && null !== $this->private ) { $this->private->reserve_journal( strlen( $text ) ); }
		Wstm108_Files::assert_file( $this->paths[ $kind ], $this->files[ $kind ] );
		if ( $replace && ( ! rewind( $this->handles[ $kind ] ) || ! ftruncate( $this->handles[ $kind ], 0 ) ) ) {
			throw new RuntimeException( 'WSTM108 Cannot reset owned proof summary.' );
		}
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
		$actual = Wstm108_Files::read_bound( $this->paths[ $kind ], $this->files[ $kind ]['identity'] );
		if ( ( $replace ? $text : $previous . $text ) !== $actual['bytes'] ) {
			throw new RuntimeException( 'WSTM108 Owned evidence readback differs.' );
		}
		$this->files[ $kind ] = $actual;
		$this->write_failed = false;
	}
}
