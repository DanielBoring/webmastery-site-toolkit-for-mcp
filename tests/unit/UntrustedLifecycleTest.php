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
		$owned = array_merge( $this->files, array( $this->lock . '/state.json', $this->lock . '/resources.json', $this->lock . '/unexpected', $this->root . '/wp-content/mu-plugins/wstm108-probe.php', $this->root . '/wp-content/mu-plugins/wstm108-runtime.php' ) );
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
		$lifecycle->install_probe( self::original() );
		$lifecycle->attest( 'original', self::original(), array( self::class, 'verify' ) );
		$lifecycle->enable();
		$lifecycle->attest( 'enabled', $this->enabled_snapshot(), array( self::class, 'verify' ) );
		return $lifecycle;
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
		$lifecycle->finalize( $this->certificate() );
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
		$path = $this->lock . '/resources.json';
		$file = Wstm108_Files::create( $path, 'synthetic resource journal' );
		$certificate = $this->certificate();
		$lifecycle->preflight_finalization( $certificate );
		try {
			$lifecycle->finalize( $certificate );
			self::fail( 'Resource journal must be retired independently first.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Private journal', $error->getMessage() );
		}
		self::assertFileExists( $this->lock . '/state.json' );
		Wstm108_Files::remove( $path, $file );
		$lifecycle->finalize( $certificate );
		self::assertDirectoryDoesNotExist( $this->lock );
	}

	public function test_foreign_context_cannot_replace_the_precredential_binding(): void {
		$lifecycle = $this->enabled();
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Source context changed' );
		$lifecycle->bind_context( Wstm108_ProofFixture::context( $this->binding ), str_repeat( 'e', 64 ) );
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
			$lifecycle->finalize( $this->certificate( $binding, 'partial-cleanup' !== $kind ) );
			self::fail( 'Expected failure retention.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'WSTM108', $error->getMessage() );
		}
		self::assertFileExists( $this->lock . '/state.json' );
		self::assertFileExists( $this->root . '/wp-content/mu-plugins/wstm108-probe.php' );
		if ( 'foreign-config' === $kind ) { self::assertSame( 'Foreign configuration', file_get_contents( $this->root . '/wp-config.php' ) ); }
		if ( 'foreign-loader' === $kind ) { self::assertSame( 'Foreign loader', file_get_contents( $this->root . '/wp-content/mu-plugins/wstm108-runtime.php' ) ); }
	}
}
