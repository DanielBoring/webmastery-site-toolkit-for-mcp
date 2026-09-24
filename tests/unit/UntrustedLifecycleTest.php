<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-lifecycle.php';
require_once __DIR__ . '/fixtures/untrusted-stage-envelope.php';

final class UntrustedLifecycleTest extends TestCase {
	private string $root;
	private string $plugin;
	private string $lock;
	private array $binding;
	private array $files;
	private array $directories;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm108-stage-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->root, 0700 ) );
		$this->root = str_replace( '\\', '/', realpath( $this->root ) );
		$this->plugin = $this->root . '/plugin';
		$this->lock = $this->root . '/private';
		$this->directories = array( $this->root . '/wp-content', $this->root . '/wp-content/mu-plugins', $this->plugin, $this->plugin . '/tests', $this->plugin . '/tests/e2e' );
		foreach ( $this->directories as $directory ) {
			self::assertTrue( mkdir( $directory, 0700 ) );
		}
		$this->files = array( $this->root . '/wp-config.php' );
		file_put_contents( $this->files[0], "<?php\n// SECRET_CONFIGURATION_BYTES\n" );
		foreach ( array( 'untrusted-content-probe.php', 'untrusted-content-fixture.php', 'error-contract-fixture.php' ) as $name ) {
			$this->files[] = $this->plugin . '/tests/e2e/' . $name;
			file_put_contents( end( $this->files ), "<?php\n// owned fixture {$name}\n" );
		}
		$this->binding = array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'owned-project', 'source_sha' => str_repeat( 'b', 40 ), 'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
	}

	protected function tearDown(): void {
		$owned = array_merge( $this->files, glob( $this->lock . '/wire-*.bin' ), array( $this->lock . '/private-wire.json', $this->lock . '/state.json', $this->lock . '/state-original', $this->lock . '/state-link', $this->lock . '/resources.json', $this->lock . '/unexpected', $this->root . '/wp-content/mu-plugins/wstm108-probe.php', $this->root . '/wp-content/mu-plugins/wstm108-runtime.php' ) );
		foreach ( $owned as $file ) {
			if ( file_exists( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->lock ) ) {
			rmdir( $this->lock );
		}
		foreach ( array_reverse( $this->directories ) as $directory ) {
			rmdir( $directory );
		}
		rmdir( $this->root );
	}

	private function lifecycle( ?array $binding = null ): Wstm108_Lifecycle {
		return new Wstm108_Lifecycle( $this->root, $this->plugin, $this->lock, $binding ?? $this->binding );
	}

	private static function original(): array {
		return array(
			'optin_defined' => false, 'optin' => null, 'owner_defined' => false, 'owner' => null,
			'abilities' => array( 'native' => 'schema-sha' ), 'servers' => array( 'gateway' => 'original-descriptor' ),
			'observers' => array_fill_keys( Wstm108_Runtime::OBSERVERS, array( array( 'file' => '/native.php', 'callback' => 'native_callback' ) ) ),
			'wordpress' => '7.1.1', 'active_plugins' => array( 'native-plugin' ),
		);
	}

	private function enabled_snapshot(): array {
		$enabled = array_replace( self::original(), array( 'optin_defined' => true, 'optin' => true, 'owner_defined' => true, 'owner' => $this->binding['owner'] ) );
		foreach ( Wstm108_Runtime::additions() as $name ) {
			$enabled['abilities'][ $name ] = hash( 'sha256', $name );
		}
		$enabled['servers']['wstm118-individual'] = array(
			'namespace' => 'wstm118', 'route' => 'tools', 'name' => 'Error contract fixture',
			'description' => 'Disposable individual-tool proof', 'version' => '1.0.0',
			'observer_class' => 'WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
			'error_class' => 'WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler',
			'component_counts' => array( 'tools' => 95, 'resources' => 0, 'prompts' => 0 ),
		);
		foreach ( Wstm108_Runtime::hooks() as $hook => [ $file, $priority, $args ] ) {
			$path = $this->plugin . '/tests/e2e/' . $file;
			$enabled['observers'][ $hook ][] = array( 'file' => $path, 'priority' => $priority, 'accepted_args' => $args, 'source_sha256' => hash_file( 'sha256', $path ) );
		}
		return $enabled;
	}

	public static function verify( array $identity, array $expected, array $stale ): array {
		return array( 'identity' => $identity + array( 'uid' => 33 ), 'runtime' => $expected, 'php' => PHP_VERSION, 'sapi' => 'apache2handler' );
	}

	private function enabled(): Wstm108_Lifecycle {
		$lifecycle = $this->lifecycle();
		$lifecycle->acquire();
		$lifecycle->bind_context( Wstm108_ProofFixture::context( $this->binding ), str_repeat( 'd', 64 ) );
		$lifecycle->create_wire();
		$lifecycle->install_probe( self::original() );
		$lifecycle->attest( 'original', self::original(), array( self::class, 'verify' ) );
		$lifecycle->enable();
		$lifecycle->attest( 'enabled', $this->enabled_snapshot(), array( self::class, 'verify' ) );
		return $lifecycle;
	}

	private function retirement_evidence( Wstm108_Lifecycle $lifecycle ): array {
		$file = Wstm108_Files::create( $this->lock . '/resources.json', 'synthetic resource journal; no WordPress objects' );
		$lifecycle->bind_resources( $file['identity'] );
		$wire = $lifecycle->wire();
		foreach ( array( 'original', 'enabled', 'runner', 'restored', 'finalize' ) as $scope ) {
			$wire->begin( $scope );
			$record = $wire->capture( $scope, array( 'status' => 200, 'body' => 'synthetic lifecycle observation' ) );
			$wire->validate( $scope, $record['id'], 'oracle' );
			$wire->seal( $scope, true, array( 'synthetic' => true ) );
		}
		return $file;
	}

	private function retire_private_files( Wstm108_Lifecycle $lifecycle, array $prepared, array $resource ): void {
		$targets = $lifecycle->verify_retirement_targets( $prepared );
		Wstm108_Files::remove( $this->lock . '/resources.json', $resource );
		$lifecycle->wire( $prepared )->retire( array_diff_key( $targets, array( 'probe' => true, 'resources.json' => true ) ) );
	}

	private function certificate( ?array $binding = null, bool $complete = true ): Wstm108_Proof {
		$context = Wstm108_ProofFixture::context( $binding ?? $this->binding );
		$proof = Wstm108_ProofFixture::runner( $context, str_repeat( 'd', 64 ) );
		$proof->cleanup_complete = $complete;
		return Wstm108_Proof::runner( $proof, $context, str_repeat( 'd', 64 ), Wstm108_ProofFixture::native() );
	}

	public function test_entire_lifecycle_never_changes_original_config_bytes_or_metadata(): void {
		$config = Wstm108_Files::file( $this->root . '/wp-config.php' );
		$lifecycle = $this->enabled();
		$loader = file_get_contents( $this->root . '/wp-content/mu-plugins/wstm108-runtime.php' );
		self::assertStringContainsString( var_export( $this->plugin . '/tests/e2e/error-contract-fixture.php', true ), $loader );
		self::assertStringContainsString( var_export( $this->plugin . '/tests/e2e/untrusted-content-fixture.php', true ), $loader );
		self::assertSame( $config, Wstm108_Files::file( $this->root . '/wp-config.php' ) );
		$lifecycle->restore_loader();
		$lifecycle->attest( 'restored', self::original(), array( self::class, 'verify' ) );
		$resource = $this->retirement_evidence( $lifecycle );
		$prepared = $lifecycle->prepare_retirement( $this->certificate() );
		$this->retire_private_files( $lifecycle, $prepared, $resource );
		$lifecycle->finalize( $this->certificate(), $prepared );
		self::assertSame( $config, Wstm108_Files::file( $this->root . '/wp-config.php' ) );
		self::assertDirectoryDoesNotExist( $this->lock );
		self::assertFileDoesNotExist( $this->root . '/wp-content/mu-plugins/wstm108-probe.php' );
		self::assertFileDoesNotExist( $this->root . '/wp-content/mu-plugins/wstm108-runtime.php' );
	}

	public static function collisions(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'lock', 'probe', 'loader', 'constant' ) );
	}

	public function test_resource_retirement_is_preflighted_before_private_lifecycle_removal(): void {
		$lifecycle = $this->enabled();
		$lifecycle->restore_loader();
		$lifecycle->attest( 'restored', self::original(), array( self::class, 'verify' ) );
		$file = $this->retirement_evidence( $lifecycle );
		$certificate = $this->certificate();
		$lifecycle->preflight_finalization( $certificate );
		$prepared = $lifecycle->prepare_retirement( $certificate );
		try {
			$lifecycle->finalize( $certificate, $prepared );
			self::fail( 'Resource journal must be retired independently first.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Private journal', $error->getMessage() );
		}
		self::assertFileExists( $this->lock . '/state.json' );
		$this->retire_private_files( $lifecycle, $prepared, $file );
		$lifecycle->finalize( $certificate, $prepared );
		self::assertDirectoryDoesNotExist( $this->lock );
	}

	public function test_static_runner_wiring_enrolls_before_capability_mutation_and_seals_afterward(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/untrusted-content-runner.php' );
		$created = strpos( $source, '$resources = new Wstm108_Resources(' );
		$bound = strpos( $source, '$lifecycle->bind_resources( $resources->journal_identity() );' );
		$intent = strpos( $source, '$resources->intent( \'user\',' );
		$insert = strpos( $source, '$id = wp_insert_user(' );
		$enrolled = strpos( $source, '$resources->user_created( $role, (int) $id );' );
		$capability = strpos( $source, "->add_cap( 'list_users' )" );
		$sealed = strpos( $source, '$resources->user( $role, (int) $id );' );
		$password = strpos( $source, '$resources->intent( \'password\',' );
		$positions = array( $created, $bound, $intent, $insert, $enrolled, $capability, $sealed, $password );
		foreach ( $positions as $position ) { self::assertNotFalse( $position ); }
		$ordered = $positions;
		sort( $ordered, SORT_NUMERIC );
		self::assertSame( $positions, $ordered, 'This source-order guard complements, not replaces, the real capability-aware resource and WordPress controls.' );
	}

	public static function late_wire_ownership_changes(): array {
		return array( array( 'probe' ), array( 'resource-reappeared' ), array( 'loader-reappeared' ), array( 'unknown-entry' ) );
	}

	/** @dataProvider late_wire_ownership_changes */
	public function test_remaining_lifecycle_ownership_is_rechecked_before_wire_mutation( string $kind ): void {
		$lifecycle = $this->enabled();
		$lifecycle->restore_loader();
		$lifecycle->attest( 'restored', self::original(), array( self::class, 'verify' ) );
		$resource = $this->retirement_evidence( $lifecycle );
		$prepared = $lifecycle->prepare_retirement( $this->certificate() );
		$targets = $lifecycle->verify_retirement_targets( $prepared );
		Wstm108_Files::remove( $this->lock . '/resources.json', $resource );
		$wire = $lifecycle->wire( $prepared );
		$changed = $this->lock . '/unknown-entry';
		if ( 'probe' === $kind ) { $changed = $this->root . '/wp-content/mu-plugins/wstm108-probe.php'; }
		if ( 'resource-reappeared' === $kind ) { $changed = $this->lock . '/resources.json'; }
		if ( 'loader-reappeared' === $kind ) { $changed = $this->root . '/wp-content/mu-plugins/wstm108-runtime.php'; }
		file_put_contents( $changed, 'late foreign bytes must survive' );
		if ( 'unknown-entry' === $kind ) { $this->files[] = $changed; }
		$before = array();
		foreach ( glob( $this->lock . '/*' ) as $path ) { $before[ $path ] = file_get_contents( $path ); }
		try {
			$wire->retire( array_diff_key( $targets, array( 'probe' => true, 'resources.json' => true ) ) );
			self::fail( 'Late lifecycle ownership change must prevent even the wire retirement-intent write.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( array_keys( $before ), glob( $this->lock . '/*' ) );
			foreach ( $before as $path => $bytes ) { self::assertSame( $bytes, file_get_contents( $path ) ); }
			self::assertSame( 'late foreign bytes must survive', file_get_contents( $changed ) );
		}
	}

	public function test_foreign_context_cannot_replace_the_precredential_binding(): void {
		$lifecycle = $this->enabled();
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Foreign source context' );
		$lifecycle->bind_context( Wstm108_ProofFixture::context( $this->binding ), str_repeat( 'e', 64 ) );
	}

	private function fresh_process( ?array $anchor ): array {
		$input = array(
			'root' => $this->root, 'plugin' => $this->plugin, 'lock' => $this->lock, 'binding' => $this->binding,
			'anchor' => $anchor, 'context' => Wstm108_ProofFixture::context( $this->binding ), 'sha256' => str_repeat( 'd', 64 ),
		);
		$code = 'require ' . var_export( dirname( __DIR__ ) . '/e2e/untrusted-content-lifecycle.php', true ) . ';'
			. '$v=json_decode(base64_decode($argv[1],true),true,512,JSON_THROW_ON_ERROR);'
			. '$life=new Wstm108_Lifecycle($v["root"],$v["plugin"],$v["lock"],$v["binding"],$v["anchor"]);'
			. '$life->bind_context($v["context"],$v["sha256"]);echo json_encode($life->public_state(),JSON_THROW_ON_ERROR);';
		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code, base64_encode( json_encode( $input, JSON_THROW_ON_ERROR ) ) ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $out, $error );
	}

	public function test_independent_anchor_survives_legitimate_updates_and_fresh_process_reads(): void {
		$lifecycle = $this->enabled();
		$anchor = $lifecycle->anchor();
		$before = Wstm108_Files::file( $this->lock . '/state.json' );
		list( $status, $out, $error ) = $this->fresh_process( $anchor );
		self::assertSame( 0, $status, $error );
		self::assertSame( json_decode( json_encode( $lifecycle->public_state(), JSON_THROW_ON_ERROR ), true ), json_decode( $out, true, 512, JSON_THROW_ON_ERROR ) );
		self::assertSame( $before, Wstm108_Files::file( $this->lock . '/state.json' ) );
		$lifecycle->restore_loader();
		$lifecycle->attest( 'restored', self::original(), array( self::class, 'verify' ) );
		self::assertSame( $anchor, $lifecycle->anchor() );
		list( $status, $out, $error ) = $this->fresh_process( $anchor );
		self::assertSame( 0, $status, $error );
		self::assertNotNull( json_decode( $out, true, 512, JSON_THROW_ON_ERROR )['restored_attestation'] );
	}

	public static function anchor_refusals(): array {
		return array_map( static fn( $kind ) => array( $kind ), array(
			'missing-anchor', 'same-bytes-replacement', 'uid-mismatch', 'gid-mismatch', 'mode-mismatch',
			'lock-uid-mismatch', 'hardlink', 'partial-update',
		) );
	}

	/** @dataProvider anchor_refusals */
	public function test_fresh_process_refuses_changed_or_unbound_journal_before_any_mutation( string $kind ): void {
		$lifecycle = $this->enabled();
		$anchor = $lifecycle->anchor();
		$path = $this->lock . '/state.json';
		$original = Wstm108_Files::file( $path );
		if ( 'missing-anchor' === $kind ) { $anchor = null; }
		if ( 'same-bytes-replacement' === $kind ) {
			self::assertTrue( rename( $path, $this->lock . '/state-original' ) );
			Wstm108_Files::create( $path, $original['bytes'] );
			self::assertSame( $original['bytes'], file_get_contents( $path ) );
		}
		if ( 'uid-mismatch' === $kind ) { ++$anchor['state_identity']['uid']; }
		if ( 'gid-mismatch' === $kind ) { ++$anchor['state_identity']['gid']; }
		if ( 'mode-mismatch' === $kind ) { $anchor['state_identity']['mode'] ^= 0004; }
		if ( 'lock-uid-mismatch' === $kind ) { ++$anchor['lock_identity']['uid']; }
		if ( 'hardlink' === $kind ) { self::assertTrue( link( $path, $this->lock . '/state-link' ) ); }
		if ( 'partial-update' === $kind ) { file_put_contents( $path, '{"binding":' ); }
		$before = array();
		foreach ( glob( $this->lock . '/*' ) as $file ) { $before[ $file ] = file_get_contents( $file ); }
		$config = Wstm108_Files::file( $this->root . '/wp-config.php' );
		$probe = Wstm108_Files::file( $this->root . '/wp-content/mu-plugins/wstm108-probe.php' );
		$loader = Wstm108_Files::file( $this->root . '/wp-content/mu-plugins/wstm108-runtime.php' );
		list( $status, $out, $error ) = $this->fresh_process( $anchor );
		self::assertNotSame( 0, $status );
		self::assertSame( '', $out );
		self::assertNotSame( '', $error );
		self::assertSame( array_keys( $before ), glob( $this->lock . '/*' ) );
		foreach ( $before as $file => $bytes ) { self::assertSame( $bytes, file_get_contents( $file ) ); }
		self::assertSame( $config, Wstm108_Files::file( $this->root . '/wp-config.php' ) );
		self::assertSame( $probe, Wstm108_Files::file( $this->root . '/wp-content/mu-plugins/wstm108-probe.php' ) );
		self::assertSame( $loader, Wstm108_Files::file( $this->root . '/wp-content/mu-plugins/wstm108-runtime.php' ) );
	}

	/** @dataProvider collisions */
	public function test_existing_runtime_or_owner_collisions_are_retained( string $kind ): void {
		$path = $this->root . '/wp-config.php';
		if ( 'lock' === $kind ) {
			mkdir( $this->lock, 0700 );
			$path = $this->lock . '/state.json';
		}
		if ( 'probe' === $kind || 'loader' === $kind ) {
			$path = $this->root . '/wp-content/mu-plugins/wstm108-' . ( 'probe' === $kind ? 'probe' : 'runtime' ) . '.php';
		}
		file_put_contents( $path, 'FOREIGN WSTM108_ALLOW_DISPOSABLE bytes' );
		try {
			$this->lifecycle()->acquire();
			self::fail( 'Expected fail-closed collision.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'collision', $error->getMessage() );
		}
		self::assertSame( 'FOREIGN WSTM108_ALLOW_DISPOSABLE bytes', file_get_contents( $path ) );
	}

	public static function retention_failures(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'foreign-owner', 'foreign-config', 'foreign-loader', 'missing-http', 'changed-schema', 'partial-cleanup', 'foreign-proof', 'unknown-lock-file' ) );
	}

	public static function unexpected_runtime_deltas(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'native-schema', 'native-server', 'native-observer', 'missing-fixture-hook', 'duplicate-fixture-hook', 'foreign-ability', 'provider', 'wrong-hook-source', 'wrong-hook-priority', 'wrong-catalog-count' ) );
	}

	/** @dataProvider unexpected_runtime_deltas */
	public function test_enabled_runtime_must_preserve_every_original_registration_and_exact_fixture_delta( string $kind ): void {
		$lifecycle = $this->lifecycle();
		$lifecycle->acquire();
		$lifecycle->install_probe( self::original() );
		$lifecycle->attest( 'original', self::original(), array( self::class, 'verify' ) );
		$lifecycle->enable();
		$enabled = $this->enabled_snapshot();
		if ( 'native-schema' === $kind ) { $enabled['abilities']['native'] = 'changed'; }
		if ( 'native-server' === $kind ) { $enabled['servers']['gateway'] = 'changed'; }
		if ( 'native-observer' === $kind ) { unset( $enabled['observers']['mcp_adapter_init'][0] ); }
		if ( 'missing-fixture-hook' === $kind ) { array_pop( $enabled['observers']['mcp_adapter_init'] ); }
		if ( 'duplicate-fixture-hook' === $kind ) { $enabled['observers']['mcp_adapter_init'][] = $enabled['observers']['mcp_adapter_init'][1]; }
		if ( 'foreign-ability' === $kind ) { $enabled['abilities']['foreign/injected'] = 'unexpected'; }
		if ( 'provider' === $kind ) { $enabled['active_plugins'][] = 'unexpected-plugin'; }
		if ( 'wrong-hook-source' === $kind ) { $enabled['observers']['mcp_adapter_init'][1]['source_sha256'] = str_repeat( '0', 64 ); }
		if ( 'wrong-hook-priority' === $kind ) { $enabled['observers']['mcp_adapter_init'][1]['priority'] = 20; }
		if ( 'wrong-catalog-count' === $kind ) { $enabled['servers']['wstm118-individual']['component_counts']['tools'] = 94; }
		try {
			$lifecycle->attest( 'enabled', $enabled, static function (): array {
				self::fail( 'Unexpected delta must fail before contacting HTTP or creating credentials.' );
			} );
			self::fail( 'Unexpected enabled state was accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Runtime delta', $error->getMessage() );
		}
		self::assertFileExists( $this->lock . '/state.json' );
		self::assertFileExists( $this->root . '/wp-content/mu-plugins/wstm108-runtime.php' );
	}

	/** @dataProvider retention_failures */
	public function test_foreign_or_incomplete_restoration_never_retires_private_state( string $kind ): void {
		$lifecycle = $this->enabled();
		$this->retirement_evidence( $lifecycle );
		$binding = $this->binding;
		try {
			if ( 'foreign-owner' === $kind ) {
				$binding = $this->binding;
				$binding['owner'] = str_repeat( 'd', 32 );
				$this->lifecycle( $binding )->restore_loader();
			}
			if ( 'foreign-config' === $kind ) {
				file_put_contents( $this->root . '/wp-config.php', 'Foreign configuration' );
			}
			if ( 'foreign-loader' === $kind ) {
				file_put_contents( $this->root . '/wp-content/mu-plugins/wstm108-runtime.php', 'Foreign loader' );
			}
			$lifecycle->restore_loader();
			if ( 'missing-http' !== $kind ) {
				$original = self::original();
				if ( 'changed-schema' === $kind ) {
					$original['abilities']['native'] = 'changed';
				}
				$lifecycle->attest( 'restored', $original, array( self::class, 'verify' ) );
			}
			if ( 'foreign-proof' === $kind ) { $binding['source_sha'] = str_repeat( 'e', 40 ); }
			if ( 'unknown-lock-file' === $kind ) { file_put_contents( $this->lock . '/unexpected', 'Keep me' ); }
			$lifecycle->prepare_retirement( $this->certificate( $binding, 'partial-cleanup' !== $kind ) );
			self::fail( 'Expected failure retention.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'WSTM108', $error->getMessage() );
		}
		self::assertFileExists( $this->lock . '/state.json' );
		self::assertFileExists( $this->root . '/wp-content/mu-plugins/wstm108-probe.php' );
		if ( 'foreign-config' === $kind ) { self::assertSame( 'Foreign configuration', file_get_contents( $this->root . '/wp-config.php' ) ); }
		if ( 'foreign-loader' === $kind ) { self::assertSame( 'Foreign loader', file_get_contents( $this->root . '/wp-content/mu-plugins/wstm108-runtime.php' ) ); }
	}

	public static function prepared_changes(): array {
		return array_map( static fn( $kind ) => array( $kind ), array( 'substituted-generation', 'substituted-inventory', 'changed-state', 'changed-wire', 'changed-resource', 'new-file', 'replayed-prepare' ) );
	}

	/** @dataProvider prepared_changes */
	public function test_prepare_is_readonly_for_targets_and_retire_refuses_every_stale_or_substituted_binding( string $kind ): void {
		$lifecycle = $this->enabled();
		$lifecycle->restore_loader();
		$lifecycle->attest( 'restored', self::original(), array( self::class, 'verify' ) );
		$this->retirement_evidence( $lifecycle );
		$before_targets = array();
		foreach ( glob( $this->lock . '/*' ) as $path ) {
			if ( basename( $path ) !== 'state.json' ) { $before_targets[ $path ] = file_get_contents( $path ); }
		}
		$prepared = $lifecycle->prepare_retirement( $this->certificate() );
		foreach ( $before_targets as $path => $bytes ) { self::assertSame( $bytes, file_get_contents( $path ) ); }
		if ( 'substituted-generation' === $kind ) { $prepared['generation'] = str_repeat( '0', 32 ); }
		if ( 'substituted-inventory' === $kind ) { $prepared['inventory_sha256'] = str_repeat( '0', 64 ); }
		if ( 'changed-state' === $kind ) { file_put_contents( $this->lock . '/state.json', "\n", FILE_APPEND ); }
		if ( 'changed-wire' === $kind ) { file_put_contents( $this->lock . '/wire-000001.bin', 'changed original wire' ); }
		if ( 'changed-resource' === $kind ) { file_put_contents( $this->lock . '/resources.json', 'foreign resource journal' ); }
		if ( 'new-file' === $kind ) { file_put_contents( $this->lock . '/unexpected', 'foreign file' ); }
		$before = array();
		foreach ( glob( $this->lock . '/*' ) as $path ) { $before[ $path ] = file_get_contents( $path ); }
		try {
			if ( 'replayed-prepare' === $kind ) { $lifecycle->prepare_retirement( $this->certificate() ); }
			else { $lifecycle->verify_retirement_targets( $prepared ); }
			self::fail( 'Prepared ownership must not be refreshed or adopted.' );
		} catch ( RuntimeException $error ) {
			self::assertNotSame( '', $error->getMessage() );
		}
		self::assertSame( array_keys( $before ), glob( $this->lock . '/*' ) );
		foreach ( $before as $path => $bytes ) { self::assertSame( $bytes, file_get_contents( $path ) ); }
		self::assertFileExists( $this->root . '/wp-content/mu-plugins/wstm108-probe.php' );
	}
}
