<?php

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/e2e/untrusted-content-proof.php';
require_once dirname( __DIR__, 2 ) . '/e2e/untrusted-content-evidence.php';

/** Synthetic evidence for pure validators and no-Docker orchestration tests only. */
final class Wstm108_ProofFixture {
	public static function evidence( string $path, string $scope = 'runner' ): array {
		$evidence = new Wstm108_Evidence( $path );
		$directory = str_replace( '\\', '/', realpath( dirname( $path ) ) ) . '/wstm108-private-unit-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'Cannot create synthetic unit private directory.' ); }
		$identity = Wstm108_Files::directory( $directory );
		$wire = Wstm108_PrivateWire::create( $directory, self::context()['binding'], str_repeat( 'd', 64 ), static function () use ( $directory, $identity ): void {
			if ( $identity !== Wstm108_Files::directory( $directory ) ) { throw new RuntimeException( 'Synthetic unit directory identity changed.' ); }
		} );
		$evidence->attach( $wire, $scope );
		return array( $evidence, $directory, $wire );
	}

	public static function event( int $id, string $scope, array $origin, ?string $body, string $parser, ?array $validation = null, ?int $status = 200, array $extra = array() ): array {
		$event = $origin + $extra;
		if ( null !== $body ) { $event['body'] = $body; }
		if ( null !== $status ) { $event['status'] = $status; }
		$frame = "WSTM108-WIRE-1\n" . serialize( $event );
		return array(
			'id' => $id, 'scope' => $scope, 'length' => strlen( $frame ), 'sha256' => hash( 'sha256', $frame ),
			'body_length' => null === $body ? null : strlen( $body ), 'body_sha256' => null === $body ? null : hash( 'sha256', $body ),
			'status' => $status, 'state' => 'validated', 'parser' => $parser, 'removed' => false, 'origin' => (object) $origin, 'validation' => $validation,
		);
	}

	public static function journal( array $events ): string {
		$lines = array();
		foreach ( $events as $event ) {
			$before = (array) $event;
			$before['state'] = 'committed';
			$before['parser'] = null;
			$before['validation'] = null;
			$lines[] = json_encode( $before, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
			$lines[] = json_encode( $event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		}
		return implode( "\n", $lines ) . "\n";
	}

	public static function wire_proof( string $scope, array $context, string $sha256, array $events, array $cases = array() ): array {
		return array(
			'version' => 1, 'binding' => $context['binding'], 'context_sha256' => $sha256, 'scope' => $scope,
			'verdict' => 'passed', 'semantic_sha256' => hash( 'sha256', 'synthetic-semantic-verdict' ),
			'events' => $events, 'cases' => $cases,
			'events_sha256' => hash( 'sha256', json_encode( $events, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ),
		);
	}
	public static function context( ?array $binding = null ): array {
		return array(
			'binding' => $binding ?? array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'proof-fixture', 'source_sha' => str_repeat( 'b', 40 ), 'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null ),
			'files' => array( 'production' => array( 'plugin.php' => str_repeat( 'd', 64 ) ), 'harness' => array( 'runner.php' => str_repeat( 'e', 64 ) ) ),
		);
	}

	public static function native(): array {
		return Wstm108_Plan::native_from_manifest( dirname( __DIR__, 2 ) . '/e2e/abilities-manifest.json' );
	}

	public static function object( $value ) {
		return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 512, JSON_THROW_ON_ERROR );
	}

	public static function resources( array $binding ): array {
		$cleanup = array_fill_keys( array(
			'HTTP:gateway:administrator', 'HTTP:gateway:reader', 'HTTP:gateway:subscriber',
			'HTTP:individual:administrator', 'HTTP:individual:reader', 'HTTP:individual:subscriber',
			'actor:administrator', 'actor:reader', 'actor:subscriber', 'clients', 'comment:1',
			'content-absence', 'coverage', 'file:1', 'files', 'final-absence', 'journal', 'journal-final', 'options',
			'password:administrator', 'password:reader', 'password:subscriber', 'post:1', 'post:2', 'post:3', 'post:4',
			'recovery', 'references', 'session:gateway:administrator', 'session:gateway:reader', 'session:gateway:subscriber',
			'session:individual:administrator', 'session:individual:reader', 'session:individual:subscriber', 'upload-directory',
		), true );
		$proof = array(
			'version' => 1, 'source_sha' => $binding['source_sha'], 'tree_sha' => $binding['tree_sha'], 'package_sha256' => $binding['package_sha256'],
			'binding_sha256' => hash( 'sha256', json_encode( $binding, JSON_THROW_ON_ERROR ) ),
			'resource_counts' => array( 'posts' => 4, 'comments' => 1, 'files' => 1 ),
			'cleanup_complete' => true, 'cleanup' => $cleanup, 'failed' => 0, 'first_error' => null,
			'inventory_sha256' => hash( 'sha256', 'synthetic pure-validator inventory; never a live retirement receipt' ),
		);
		$proof['cleanup_sha256'] = hash_hmac( 'sha256', json_encode( $proof, JSON_THROW_ON_ERROR ), $binding['owner'] );
		return $proof;
	}

	public static function runner( array $context, string $sha256 ): object {
		$labels = Wstm108_Plan::labels( self::native() );
		$registered = array();
		$individual = array();
		$schemas = array();
		foreach ( Wstm108_Plan::abilities( self::native() ) as $index => $name ) {
			$annotations = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
			if ( 'webmastery-site-toolkit-for-mcp/bulk-publish-posts' === $name ) {
				$annotations['readonly'] = false;
				$annotations['destructive'] = true;
			}
			$registered[ $name ] = array(
				'name' => $name, 'label' => 'Synthetic ability ' . $index, 'description' => 'Pure validator fixture',
				'input_schema' => (object) array( 'type' => 'object' ), 'output_schema' => (object) array( 'type' => 'object' ),
				'meta' => array( 'annotations' => $annotations ),
			);
			$individual[] = array(
				'name' => 'synthetic-tool-' . $index, 'inputSchema' => (object) array( 'type' => 'object' ),
				'annotations' => (object) array( 'readOnlyHint' => $annotations['readonly'], 'destructiveHint' => $annotations['destructive'], 'idempotentHint' => $annotations['idempotent'] ),
			);
			$r = $registered[ $name ];
			$schemas[ $name ] = hash( 'sha256', wstm108_wire_canonical( array( 'label' => $r['label'], 'description' => $r['description'], 'input' => $r['input_schema'], 'output' => $r['output_schema'], 'meta' => $r['meta'] ) ) );
		}
		for ( $index = 90; $index < 95; ++$index ) {
			$individual[] = array( 'name' => 'synthetic-fixture-' . $index, 'inputSchema' => (object) array( 'type' => 'object' ) );
		}
		$actors = array();
		$catalogs = array();
		foreach ( Wstm108_Plan::ACTORS as $index => $role ) {
			$actors[ $role ] = array( 'id' => $index + 1, 'roles' => array( 'reader' === $role ? 'subscriber' : $role ), 'caps' => (object) array( 'read' => true, 'list_users' => 'subscriber' !== $role ) );
			$catalogs[ 'gateway:' . $role ] = array_slice( $individual, 0, 3 );
			$catalogs[ 'individual:' . $role ] = $individual;
		}
		$cases = array();
		foreach ( $labels as $label ) {
			$case = array( 'label' => $label, 'passed' => true );
			if ( 0 === strpos( $label, 'annotations:' ) ) {
				$index = array_search( substr( $label, strlen( 'annotations:' ) ), array_keys( $registered ), true );
				$case['descriptor'] = self::object( $individual[ $index ] );
			}
			$cases[] = $case;
		}
		$public = Wstm108_Evidence::public_summary( array(
			'completed' => true, 'cleanup_complete' => true, 'binding' => $context['binding'], 'stage_context_sha256' => $sha256,
			'boundaries' => Wstm108_Plan::BOUNDARIES, 'invoked_boundaries' => Wstm108_Plan::BOUNDARIES,
			'actual_files' => $context['files'], 'actual_runtime' => array( 'fixture' => 'enabled', 'abilities' => $schemas ),
			'expected_labels' => $labels, 'cases' => $cases,
			'passed' => count( $labels ), 'failed' => 0, 'status' => 'passed', 'blocked' => array(),
			'registered' => $registered, 'actors' => $actors, 'catalogs' => $catalogs, 'resource_proof' => self::resources( $context['binding'] ),
		) );
		$events = array();
		foreach ( $catalogs as $label => $tools ) {
			$body = json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'result' => array( 'tools' => $tools ) ), JSON_THROW_ON_ERROR );
			$events[] = self::event( count( $events ) + 1, 'runner', array( 'boundary' => $label, 'method' => 'tools/list' ), $body, 'catalog',
				array( 'tools' => $public['catalogs'][ $label ], 'next_cursor_sha256' => hash( 'sha256', 'null' ) ) );
		}
		$verdicts = array();
		foreach ( $cases as $index => $case ) {
			$id = count( $events ) + 1;
			$events[] = self::event( $id, 'runner', array( 'boundary' => 'case-result' ), null, 'case-result', null, null, array( 'case' => $case ) );
			$verdicts[ $case['label'] ] = array( 'passed' => true, 'events' => array( $id ), 'sha256' => $public['cases'][ $index ]['private_case_sha256'] );
		}
		$public['wire_proof'] = self::wire_proof( 'runner', $context, $sha256, $events, $verdicts );
		return self::object( $public );
	}

	public static function runner_journal( object $runner ): string {
		return self::journal( $runner->wire_proof->events );
	}

	public static function controls(): string {
		$events = array();
		foreach ( array( 'untrusted-content-stage.php' => 'WSTM108_STAGE_DISPOSABLE', 'untrusted-content-runner.php' => 'WSTM108_ALLOW_DISPOSABLE' ) as $name => $flag ) {
			foreach ( array( 'actual-cli-missing-optin' => 2, 'actual-http-cli-only' => 403 ) as $boundary => $status ) {
				$body = 2 === $status ? '' : 'CLI only.';
				$events[] = self::event( count( $events ) + 1, 'original', array( 'boundary' => $boundary, 'file' => $name ), $body, 2 === $status ? 'cli-refusal' : 'http-cli-refusal', null, $status );
			}
		}
		return self::journal( $events );
	}

	public static function phase( string $operation, array $context, string $sha256 ): object {
		$config = hash( 'sha256', 'synthetic config' );
		$identity = array( 'dev' => 1, 'ino' => 2, 'uid' => 0, 'gid' => 0, 'mode' => 0100644, 'nlink' => 1 );
		$boot = array(
			'identity' => array( 'owner' => $context['binding']['owner'], 'uid' => 33, 'root' => '/synthetic/wp', 'plugin_root' => '/synthetic/plugin', 'config_sha256' => $config ),
			'runtime' => array( 'fixture' => 'original' ), 'php' => '8.4.0', 'sapi' => 'apache2handler',
		);
		$enabled = $boot;
		$enabled['runtime'] = array( 'fixture' => 'enabled', 'abilities' => (array) self::runner( $context, $sha256 )->actual_runtime->abilities );
		$providers = array();
		foreach ( array( 'mcp_adapter' => '0.6.1', 'yoast' => '28.5', 'seopress' => '10.2' ) as $name => $version ) {
			$providers[ $name ] = array( 'path' => $name . '/plugin.php', 'version' => $version, 'active' => true );
		}
		$public = Wstm108_Evidence::public_summary( array(
			'operation' => $operation, 'completed' => true, 'binding' => $context['binding'],
			'stage_context_sha256' => $sha256, 'actual_files' => $context['files'],
			'providers' => $providers, 'stage_options_absent' => true,
			'state' => array(
				'binding' => $context['binding'], 'config_sha256' => $config, 'config_identity' => $identity,
				'mu_identity' => array_replace( $identity, array( 'mode' => 0040755, 'nlink' => 2 ) ),
				'original_attestation' => $boot, 'enabled_attestation' => $enabled, 'restored_attestation' => $boot,
			),
			'retired' => array( 'runtime_loader' => true, 'probe' => true, 'resource_journal' => true, 'private_wire' => true, 'private_lock' => true ),
		) );
		if ( in_array( $operation, array( 'original', 'enabled', 'restored', 'finalize' ), true ) ) {
			$events = 'original' === $operation ? array_map( static fn( $record ) => (array) $record, Wstm108_Proof::witnesses( self::controls() ) )
				: array( self::event( 1, $operation, array( 'boundary' => 'owned-get-probe' ), '{"synthetic":"typed owned boot"}', 'owned-current-get' ) );
			$public['wire_proof'] = self::wire_proof( $operation, $context, $sha256, $events );
		}
		if ( in_array( $operation, array( 'finalize', 'retire' ), true ) ) {
			$public['prepared'] = array( 'version' => 1, 'binding' => $context['binding'], 'context_sha256' => $sha256, 'generation' => str_repeat( 'e', 32 ),
				'state_sha256' => str_repeat( 'f', 64 ), 'inventory_sha256' => str_repeat( 'a', 64 ), 'target_count' => 20 );
			$actions = array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'runner-proof', 'restored' );
			if ( 'retire' === $operation ) { $actions[] = 'finalize'; }
			$processes = array();
			foreach ( $actions as $action ) {
				$metadata = static fn( $bytes ) => array(
					'identity' => array( 'dev' => 1, 'ino' => 2, 'uid' => 1001, 'gid' => 1001, 'mode' => 0100600, 'nlink' => 1 ),
					'sha256' => hash( 'sha256', $bytes ), 'length' => strlen( $bytes ),
				);
				$witness = array( 'action' => $action, 'child_exit' => 0, 'capture_complete' => true, 'validated' => true,
					'stdout' => $metadata( 'synthetic-success-' . $action ), 'stderr' => $metadata( '' ) );
				$processes[] = array( 'witness' => $witness, 'receipt' => $metadata( json_encode( $witness, JSON_THROW_ON_ERROR ) ) );
			}
			$public['process_verdicts'] = array( 'binding' => $context['binding'], 'processes' => $processes );
		}
		return self::object( $public );
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	if ( 'cli' !== PHP_SAPI ) {
		http_response_code( 403 );
		exit( 'CLI only.' );
	}
	$operation = $argv[1] ?? '';
	$context_file = Wstm108_Files::file( str_replace( '\\', '/', realpath( $argv[2] ) ) );
	$context = json_decode( $context_file['bytes'], true, 512, JSON_THROW_ON_ERROR );
	$path = str_replace( '\\', '/', realpath( dirname( $argv[2] ) ) ) . '/' . $operation . '.json';
	if ( 'runner' === $operation ) {
		$record = Wstm108_ProofFixture::runner( $context, $context_file['sha256'] );
		$journal = Wstm108_ProofFixture::runner_journal( $record );
		$record->http_journal_sha256 = hash( 'sha256', $journal );
	} else {
		if ( ! in_array( $operation, array( 'acquire', 'original', 'enable', 'enabled', 'restored', 'finalize', 'retire' ), true ) ) {
			throw new RuntimeException( 'Unknown synthetic orchestration phase.' );
		}
		$record = Wstm108_ProofFixture::phase( $operation, $context, $context_file['sha256'] );
		$journal = isset( $record->wire_proof ) ? Wstm108_ProofFixture::journal( $record->wire_proof->events ) : '';
	}
	Wstm108_Files::create( $path . '.http.jsonl', $journal, true );
	Wstm108_Files::create( $path, json_encode( $record, JSON_THROW_ON_ERROR ), true );
}
