<?php

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/e2e/untrusted-content-proof.php';

/** Synthetic evidence for pure validators and no-Docker orchestration tests only. */
final class Wstm108_ProofFixture {
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
			$individual[] = array( 'name' => 'synthetic-tool-' . $index, 'inputSchema' => (object) array( 'type' => 'object' ) );
			$r = $registered[ $name ];
			$schemas[ $name ] = hash( 'sha256', wstm108_wire_canonical( array( 'label' => $r['label'], 'description' => $r['description'], 'input' => $r['input_schema'], 'output' => $r['output_schema'], 'meta' => $r['meta'] ) ) );
		}
		for ( $index = 90; $index < 95; ++$index ) {
			$individual[] = array( 'name' => 'synthetic-fixture-' . $index, 'inputSchema' => (object) array( 'type' => 'object' ) );
		}
		$actors = array();
		$catalogs = array();
		foreach ( Wstm108_Plan::ACTORS as $index => $role ) {
			$actors[ $role ] = array( 'id' => $index + 1, 'roles' => array( 'reader' === $role ? 'subscriber' : $role ), 'caps' => (object) array( 'read' => true ) );
			$catalogs[ 'gateway:' . $role ] = array_slice( $individual, 0, 3 );
			$catalogs[ 'individual:' . $role ] = $individual;
		}
		return self::object( array(
			'completed' => true, 'cleanup_complete' => true, 'binding' => $context['binding'], 'stage_context_sha256' => $sha256,
			'boundaries' => Wstm108_Plan::BOUNDARIES, 'invoked_boundaries' => Wstm108_Plan::BOUNDARIES,
			'actual_files' => $context['files'], 'actual_runtime' => array( 'fixture' => 'enabled', 'abilities' => $schemas ),
			'expected_labels' => $labels, 'cases' => array_map( static fn( $label ) => array( 'label' => $label, 'passed' => true ), $labels ),
			'passed' => count( $labels ), 'failed' => 0, 'status' => 'passed', 'blocked' => array(),
			'registered' => $registered, 'actors' => $actors, 'catalogs' => $catalogs, 'resource_proof' => self::resources( $context['binding'] ),
		) );
	}

	public static function runner_journal( object $runner ): string {
		$lines = array();
		foreach ( $runner->catalogs as $label => $tools ) {
			$body = json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'result' => array( 'tools' => $tools ) ), JSON_THROW_ON_ERROR );
			$lines[] = json_encode( array( 'boundary' => $label, 'method' => 'tools/list', 'status' => 200, 'body' => $body, 'body_base64' => base64_encode( $body ) ), JSON_THROW_ON_ERROR );
		}
		return implode( "\n", $lines ) . "\n";
	}

	public static function controls(): string {
		$lines = array();
		foreach ( array( 'untrusted-content-stage.php' => 'WSTM108_STAGE_DISPOSABLE', 'untrusted-content-runner.php' => 'WSTM108_ALLOW_DISPOSABLE' ) as $name => $flag ) {
			foreach ( array( 'actual-cli-missing-optin' => 2, 'actual-http-cli-only' => 403 ) as $boundary => $status ) {
				$body = 2 === $status ? '' : 'CLI only.';
				$lines[] = json_encode( array( 'file' => $name, 'boundary' => $boundary, 'status' => $status, 'body' => $body, 'body_base64' => base64_encode( $body ), 'stderr' => $flag . '=1 is required before WordPress' ), JSON_THROW_ON_ERROR );
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	public static function phase( string $operation, array $context, string $sha256 ): object {
		$config = hash( 'sha256', 'synthetic config' );
		$identity = array( 'dev' => 1, 'ino' => 2, 'uid' => 0, 'gid' => 0, 'mode' => 0100644, 'nlink' => 1 );
		$boot = array(
			'identity' => array( 'owner' => $context['binding']['owner'], 'uid' => 33, 'root' => '/synthetic/wp', 'plugin_root' => '/synthetic/plugin', 'config_sha256' => $config ),
			'runtime' => array( 'fixture' => 'original' ), 'php' => '8.4.0', 'sapi' => 'apache2handler',
		);
		$enabled = $boot;
		$enabled['runtime'] = self::runner( $context, $sha256 )->actual_runtime;
		$providers = array();
		foreach ( array( 'mcp_adapter' => '0.6.1', 'yoast' => '28.5', 'seopress' => '10.2' ) as $name => $version ) {
			$providers[ $name ] = array( 'path' => $name . '/plugin.php', 'version' => $version, 'active' => true );
		}
		return self::object( array(
			'operation' => $operation, 'completed' => true, 'binding' => $context['binding'],
			'stage_context_sha256' => $sha256, 'actual_files' => $context['files'],
			'providers' => $providers, 'stage_options_absent' => true,
			'state' => array(
				'binding' => $context['binding'], 'config_sha256' => $config, 'config_identity' => $identity,
				'mu_identity' => array_replace( $identity, array( 'mode' => 0040755, 'nlink' => 2 ) ),
				'original_attestation' => $boot, 'enabled_attestation' => $enabled, 'restored_attestation' => $boot,
			),
			'retired' => array( 'runtime_loader' => true, 'probe' => true, 'resource_journal' => true, 'private_lock' => true ),
		) );
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
		if ( ! in_array( $operation, array( 'acquire', 'original', 'enable', 'enabled', 'restored', 'finalize' ), true ) ) {
			throw new RuntimeException( 'Unknown synthetic orchestration phase.' );
		}
		$record = Wstm108_ProofFixture::phase( $operation, $context, $context_file['sha256'] );
		$journal = 'original' === $operation ? Wstm108_ProofFixture::controls() : '';
	}
	Wstm108_Files::create( $path . '.http.jsonl', $journal, true );
	Wstm108_Files::create( $path, json_encode( $record, JSON_THROW_ON_ERROR ), true );
}
