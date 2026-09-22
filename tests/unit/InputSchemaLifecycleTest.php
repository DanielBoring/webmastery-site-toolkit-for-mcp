<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/input-schema-lifecycle.php';

final class InputSchemaLifecycleTest extends TestCase {
	private string $base;
	private string $root;
	private string $plugin;
	private string $artifacts;
	private string $lock;
	private string $token;
	private string $source;
	private array $original;
	private string $config;
	private array $before;
	private Wstm126_Lifecycle $lifecycle;
	private array $http_calls = array();

	protected function setUp(): void {
		$this->base = dirname( __DIR__, 2 ) . '/.input-schema-unit-' . bin2hex( random_bytes( 8 ) );
		$this->root = $this->base . '/site';
		$this->plugin = $this->root . '/plugin';
		$this->artifacts = $this->base . '/artifacts';
		$this->lock = $this->base . '/private-lock';
		$this->token = str_repeat( 'a', 32 );
		$this->source = str_repeat( 'b', 40 );
		foreach ( array( $this->root . '/wp-content/mu-plugins', $this->plugin . '/includes', $this->plugin . '/tests/e2e', $this->artifacts ) as $directory ) {
			mkdir( $directory, 0700, true );
		}
		$this->config = "<?php\n/* PRIVATE_DB_PASSWORD untouched config bytes */\ndefine('EMPTY_TRASH_DAYS', 7);\ndefine('WSTM116_DISPOSABLE_RUNTIME', false);\ndefine('UNRELATED_CUSTOM_FLAG', 'keep');\n";
		file_put_contents( $this->root . '/wp-config.php', $this->config );
		chmod( $this->root . '/wp-config.php', 0640 );
		foreach ( array( 'includes/class-ability.php', 'includes/class-input.php', 'includes/class-response.php', 'tests/e2e/input-schema-fixture.php', 'tests/e2e/error-contract-fixture.php', 'tests/e2e/metadata-batch-fixture.php', 'tests/e2e/input-schema-boot.php', 'tests/e2e/input-schema-probe.php' ) as $relative ) {
			file_put_contents( $this->plugin . '/' . $relative, "<?php\n/* fake mounted " . $relative . " */\n" );
		}
		$this->before = $this->config_metadata();
		$this->original = array(
			'flags' => array(
				'EMPTY_TRASH_DAYS' => array( 'defined' => true, 'value' => 7 ),
				'WSTM116_DISPOSABLE_RUNTIME' => array( 'defined' => true, 'value' => false ),
				'WSTM116_STAGE_TOKEN' => array( 'defined' => true, 'value' => hash( 'sha256', 'unrelated-stage-owner' ) ),
				'WSTM126_DISPOSABLE_RUNTIME' => array( 'defined' => false, 'value' => null ),
				'WSTM126_STAGE_TOKEN' => array( 'defined' => false, 'value' => null ),
			),
			'functions' => array(), 'constants' => array(), 'classes' => array(), 'loaded' => array( 'schema' => false, 'error' => false, 'foreign' => false ), 'observation_absent' => true,
		);
		$this->lifecycle = $this->instance();
	}

	protected function tearDown(): void {
		// Delete only this test's randomly named fake filesystem, never runtime paths.
		$remove = static function ( string $path ) use ( &$remove ): void {
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
				return;
			}
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$remove( $path . '/' . $entry );
				}
			}
			rmdir( $path );
		};
		$remove( $this->base );
	}

	private function instance( ?string $token = null, ?string $root = null, ?array $boundaries = null, ?callable $filesystem = null ): Wstm126_Lifecycle {
		return new Wstm126_Lifecycle( $root ?? $this->root, $this->plugin, $this->lock, $token ?? $this->token, $this->source, 'fake-project', $this->artifacts, $boundaries ?? array( 'direct', 'http' ), $filesystem );
	}

	private static function filesystem( string $operation, string $path, ?int $mode ) {
		switch ( $operation ) {
			case 'is_link': return is_link( $path );
			case 'stat': return stat( $path );
			case 'chmod': return chmod( $path, $mode );
		}
		throw new RuntimeException( 'Unknown test filesystem operation.' );
	}

	private function config_metadata(): array {
		$path = $this->root . '/wp-config.php';
		clearstatcache( true, $path );
		return array( file_get_contents( $path ), hash_file( 'sha256', $path ), fileperms( $path ), fileowner( $path ), filegroup( $path ), fileinode( $path ) );
	}

	private function unchanged(): void {
		$this->assertSame( $this->before, $this->config_metadata(), 'Configuration bytes/hash/mode/UID/GID/inode must never change.' );
	}

	private function runtime(): array {
		$result = $this->original;
		if ( is_file( $this->wrapper() ) ) {
			$result['flags']['WSTM126_DISPOSABLE_RUNTIME'] = array( 'defined' => true, 'value' => true );
			$result['flags']['WSTM126_STAGE_TOKEN'] = array( 'defined' => true, 'value' => hash( 'sha256', $this->token ) );
			$result['functions'] = array( 'wstm118_foreign_result', 'wstm118_permission', 'wstm118_probe', 'wstm126_assert_no_work', 'wstm126_begin', 'wstm126_end', 'wstm126_require' );
			$result['loaded'] = array( 'schema' => true, 'error' => true, 'foreign' => false );
			sort( $result['functions'] );
		}
		return $result;
	}

	private function wrapper(): string {
		return $this->root . '/wp-content/mu-plugins/wstm126-schema.php';
	}

	private function probe(): string {
		return $this->root . '/wp-content/mu-plugins/wstm126-probe.php';
	}

	private function cli(): callable {
		return function ( bool $baseline ): array {
			$this->unchanged();
			return array(
				'identity' => array( 'owner' => Wstm126_Boot::owner( $this->token ), 'source' => $this->source, 'project' => 'fake-project', 'root' => realpath( $this->root ), 'plugin_root' => realpath( $this->plugin ), 'harness_root' => realpath( $this->plugin . '/tests/e2e' ), 'config_sha256' => hash_file( 'sha256', $this->root . '/wp-config.php' ), 'hashes' => Wstm126_Boot::hashes( $this->plugin ), 'uid' => 0 ),
				'runtime' => $this->runtime(), 'php' => '8.0.30', 'sapi' => 'cli',
			);
		};
	}

	private function http(): callable {
		return function ( array $identity, array $expected, array $stale ): array {
			$this->assertFileExists( $this->probe() );
			$this->http_calls[] = array( 'expected' => $expected, 'stale' => $stale );
			$body = ( $this->cli() )( false );
			$body['sapi'] = 'apache2handler';
			$body['identity']['uid'] = 33;
			$this->lifecycle->retain_wire( json_encode( $body, JSON_THROW_ON_ERROR ), 200, 1 );
			return $body;
		};
	}

	private function hashes(): callable {
		return static function ( string $plugin ): array {
			$hashes = array( 'production' => array(), 'harness' => array() );
			foreach ( Wstm126_Boot::hashes( $plugin ) as $relative => $hash ) {
				$hashes[ 0 === strpos( $relative, 'includes/' ) ? 'production' : 'harness' ][ $relative ] = $hash;
			}
			return $hashes;
		};
	}

	private function validator(): callable {
		return static function ( array $report, string $owner, string $source, string $project, string $boundary ): void {
			Wstm126_Boot::check( $report['owner'] === $owner && $report['source'] === $source && $report['project'] === $project && $report['boundary'] === $boundary && true === ( $report['cleanup']['verified'] ?? null ), 'Invocation cleanup proof rejected.' );
		};
	}

	private function reports(): void {
		foreach ( array( 'direct', 'http' ) as $boundary ) {
			file_put_contents( $this->artifacts . '/' . $boundary . '.json', json_encode( array(
				'owner' => $this->token, 'source' => $this->source, 'project' => 'fake-project', 'boundary' => $boundary,
				'hashes' => array( 'legacy' => 'preserved' ), 'source_hashes' => ( $this->hashes() )( $this->plugin ), 'cleanup' => array( 'verified' => true ), 'failed' => 1,
			) ) );
		}
	}

	private function restored(): void {
		$this->lifecycle->acquire( $this->cli() );
		$this->lifecycle->prepare( $this->cli(), $this->http() );
		$this->lifecycle->restore( $this->cli(), $this->http() );
	}

	public function test_all_phases_preserve_config_and_exact_unrelated_original_flags(): void {
		$acquire = $this->lifecycle->acquire( $this->cli() );
		$this->assertSame( 'acquired', $acquire['phase'] );
		$this->assertFileDoesNotExist( $this->probe() );
		$this->assertFileDoesNotExist( $this->wrapper() );
		$probe_key = $this->lifecycle->probe_key();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $probe_key );
		$this->assertNotSame( $this->token, $probe_key );
		$this->assertDirectoryExists( $this->lock . '/invocations' );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this->assertSame( 0700, fileperms( $this->lock . '/invocations' ) & 07777 );
			$this->assertSame( posix_geteuid(), fileowner( $this->lock . '/invocations' ) );
		}
		$this->unchanged();
		$prepare = $this->lifecycle->prepare( $this->cli(), $this->http() );
		$this->assertSame( 'active', $prepare['phase'] );
		$this->assertSame( $this->original, $this->http_calls[0]['expected'] );
		$wrapper = file_get_contents( $this->wrapper() );
		$this->assertStringContainsString( var_export( $this->plugin . '/tests/e2e/input-schema-fixture.php', true ), $wrapper );
		$this->assertStringContainsString( var_export( $this->plugin . '/tests/e2e/error-contract-fixture.php', true ), $wrapper );
		$this->assertStringNotContainsString( 'function wstm126_begin', $wrapper );
		$this->assertStringContainsString( hash( 'sha256', $probe_key ), file_get_contents( $this->probe() ) );
		$this->assertStringNotContainsString( $probe_key, file_get_contents( $this->probe() ) . $wrapper );
		$this->unchanged();
		$restore = $this->lifecycle->restore( $this->cli(), $this->http() );
		$this->assertSame( 'restored', $restore['phase'] );
		$this->assertFileDoesNotExist( $this->wrapper() );
		$this->assertFileExists( $this->probe() );
		$this->assertDirectoryExists( $this->lock );
		$this->assertSame( $this->original, $this->http_calls[2]['expected'] );
		$this->reports();
		$final = $this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
		$this->assertSame( 'finalized', $final['phase'] );
		$this->assertSame( array( 'direct', 'http' ), array_keys( $final['cleanup_proofs'] ) );
		$this->assertDirectoryDoesNotExist( $this->lock );
		$this->assertFileDoesNotExist( $this->probe() );
		$this->unchanged();
		$this->assertSame( $this->token, $final['identity']['owner'] );
		$this->assertStringNotContainsString( $probe_key, json_encode( array( $acquire, $prepare, $restore, $final ) ) );
		$this->assertStringNotContainsString( 'PRIVATE_DB_PASSWORD', json_encode( $final ) );
	}

	/** @dataProvider runtime_collisions */
	public function test_runtime_collisions_are_rejected_before_creating_resources( string $kind ): void {
		switch ( $kind ) {
			case 'function': $this->original['functions'][] = 'wstm126_foreign'; break;
			case 'namespace': $this->original['classes'][] = 'Wstm126\\Foreign'; break;
			case 'constant': $this->original['constants'][] = 'WSTM126_FOREIGN'; break;
			case 'stage': $this->original['flags']['WSTM126_STAGE_TOKEN'] = array( 'defined' => true, 'value' => 'foreign' ); break;
			case 'disposable': $this->original['flags']['WSTM126_DISPOSABLE_RUNTIME'] = array( 'defined' => true, 'value' => false ); break;
			case 'observation': $this->original['observation_absent'] = false; break;
			case 'dormant-loader': $this->original['loaded']['schema'] = true; break;
		}
		try {
			$this->lifecycle->acquire( $this->cli() );
			$this->fail( 'Namespace collision accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertDirectoryDoesNotExist( $this->lock );
			$this->assertFileDoesNotExist( $this->probe() );
			$this->unchanged();
		}
	}

	public static function runtime_collisions(): array {
		return array_map( static fn( string $value ): array => array( $value ), array( 'function', 'namespace', 'constant', 'stage', 'disposable', 'observation', 'dormant-loader' ) );
	}

	/** @dataProvider filesystem_collisions */
	public function test_known_filesystem_collisions_are_not_overwritten( string $name, string $bytes ): void {
		$path = $this->root . '/wp-content/mu-plugins/' . $name;
		file_put_contents( $path, $bytes );
		try {
			$this->lifecycle->acquire( $this->cli() );
			$this->fail( 'Known loader collision accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $bytes, file_get_contents( $path ) );
			$this->assertDirectoryDoesNotExist( $this->lock );
			$this->unchanged();
		}
	}

	public static function filesystem_collisions(): array {
		return array( array( 'wstm126-probe.php', 'foreign probe' ), array( 'wstm126-schema.php', 'foreign schema' ), array( 'error-contract-loader.php', 'foreign errors' ), array( 'ordinary.php', "<?php require '/fixture/input-schema-fixture.php';" ) );
	}

	public function test_second_owner_cannot_acquire_or_restore_owned_lock(): void {
		$this->lifecycle->acquire( $this->cli() );
		$state = file_get_contents( $this->lock . '/state.json' );
		foreach ( array( 'acquire', 'restore' ) as $operation ) {
			try {
				$this->instance( str_repeat( 'c', 32 ) )->$operation( $this->cli(), $this->http() );
				$this->fail( 'Foreign owner accepted.' );
			} catch ( RuntimeException $error ) {
				$this->assertSame( $state, file_get_contents( $this->lock . '/state.json' ) );
			}
		}
		$this->unchanged();
	}

	public function test_changed_config_is_never_repaired_or_overwritten_and_guard_is_retained(): void {
		$this->lifecycle->acquire( $this->cli() );
		file_put_contents( $this->root . '/wp-config.php', 'foreign config bytes' );
		foreach ( array( 'prepare', 'restore' ) as $operation ) {
			try {
				$this->lifecycle->$operation( $this->cli(), $this->http() );
				$this->fail( 'Foreign config accepted.' );
			} catch ( RuntimeException $error ) {
				$this->assertSame( 'foreign config bytes', file_get_contents( $this->root . '/wp-config.php' ) );
				$this->assertDirectoryExists( $this->lock );
			}
		}
	}

	/** @dataProvider altered_owned_files */
	public function test_changed_fixture_or_source_is_never_removed( string $kind ): void {
		$this->lifecycle->acquire( $this->cli() );
		$this->lifecycle->prepare( $this->cli(), $this->http() );
		$path = 'wrapper' === $kind ? $this->wrapper() : ( 'probe' === $kind ? $this->probe() : $this->plugin . '/tests/e2e/input-schema-fixture.php' );
		file_put_contents( $path, 'foreign replacement' );
		try {
			$this->lifecycle->restore( $this->cli(), $this->http() );
			$this->fail( 'Foreign file accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'foreign replacement', file_get_contents( $path ) );
			$this->assertFileExists( $this->wrapper() );
			$this->assertFileExists( $this->probe() );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}

	public static function altered_owned_files(): array {
		return array( array( 'wrapper' ), array( 'probe' ), array( 'source' ) );
	}

	/** @dataProvider partial_prepare_phases */
	public function test_partial_prepare_requires_original_http_evidence_and_safe_restore( int $fail_call ): void {
		$this->lifecycle->acquire( $this->cli() );
		$calls = 0;
		$normal = $this->http();
		try {
			$this->lifecycle->prepare( $this->cli(), static function ( array $identity, array $expected, array $stale ) use ( &$calls, $fail_call, $normal ): array {
				if ( ++$calls === $fail_call ) {
					throw new RuntimeException( 'Injected HTTP failure.' );
				}
				return $normal( $identity, $expected, $stale );
			} );
			$this->fail( 'Prepare should fail.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'Injected HTTP failure.', $error->getMessage() );
			$this->assertDirectoryExists( $this->lock );
		}
		$restored = $this->lifecycle->restore( $this->cli(), $this->http() );
		$this->assertTrue( $restored['baseline_http_verified'] );
		$this->assertTrue( $restored['restored_http_verified'] );
		$this->assertFileDoesNotExist( $this->wrapper() );
		$this->reports();
		$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
		$this->assertDirectoryDoesNotExist( $this->lock );
		$this->unchanged();
	}

	public static function partial_prepare_phases(): array {
		return array( array( 1 ), array( 2 ) );
	}

	public function test_acquire_without_prepare_still_requires_actual_original_http_before_finalize(): void {
		$this->lifecycle->acquire( $this->cli() );
		$this->reports();
		try {
			$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
			$this->fail( 'No HTTP evidence must not finalize.' );
		} catch ( RuntimeException $error ) {
			$this->assertDirectoryExists( $this->lock );
			$this->assertSame( array(), $this->http_calls );
		}
		$this->lifecycle->restore( $this->cli(), $this->http() );
		$this->assertSame( $this->original, $this->http_calls[0]['expected'] );
		$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
		$this->unchanged();
	}

	/** @dataProvider invalid_reports */
	public function test_finalize_rejects_missing_foreign_malformed_or_unproven_cleanup( string $kind ): void {
		$this->restored();
		$this->reports();
		$path = $this->artifacts . '/http.json';
		$report = json_decode( file_get_contents( $path ), true );
		switch ( $kind ) {
			case 'absent': unlink( $path ); break;
			case 'malformed': file_put_contents( $path, '{' ); break;
			case 'foreign': $report['owner'] = 'foreign'; break;
			case 'boundary': $report['boundary'] = 'direct'; break;
			case 'missing-cleanup': unset( $report['cleanup'] ); break;
			case 'failed-cleanup': $report['cleanup']['verified'] = false; break;
			case 'source-hash': $report['source_hashes']['production']['includes/class-input.php'] = 'foreign'; break;
			case 'missing-source-hashes': unset( $report['source_hashes'] ); break;
			case 'flat-source-hashes': $report['source_hashes'] = Wstm126_Boot::hashes( $this->plugin ); break;
		}
		if ( ! in_array( $kind, array( 'absent', 'malformed' ), true ) ) {
			file_put_contents( $path, json_encode( $report ) );
		}
		try {
			$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
			$this->fail( 'Invalid cleanup proof accepted.' );
		} catch ( RuntimeException | JsonException $error ) {
			$this->assertFileExists( $this->probe() );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}

	public static function invalid_reports(): array {
		return array_map( static fn( string $kind ): array => array( $kind ), array( 'absent', 'malformed', 'foreign', 'boundary', 'missing-cleanup', 'failed-cleanup', 'source-hash', 'missing-source-hashes', 'flat-source-hashes' ) );
	}

	public function test_selected_boundary_list_cannot_change_during_stage(): void {
		$this->restored();
		$this->reports();
		$this->expectExceptionMessage( 'journal identity' );
		$this->instance( null, null, array( 'direct' ) )->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
	}

	public function test_foreign_lock_contents_prevent_probe_and_lock_deletion(): void {
		$this->restored();
		$this->reports();
		file_put_contents( $this->lock . '/foreign', 'do not delete' );
		try {
			$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
			$this->fail( 'Foreign lock content accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'do not delete', file_get_contents( $this->lock . '/foreign' ) );
			$this->assertFileExists( $this->probe() );
			$this->unchanged();
		}
	}

	/** @dataProvider residual_journals */
	public function test_any_unretired_invocation_journal_blocks_finalization_without_deleting_it( string $owner ): void {
		$this->restored();
		$this->reports();
		$name = 'retained-evidence-marker' === $owner ? $this->token . '-direct.json.raw-retained'
			: ( 'owned' === $owner ? $this->token : str_repeat( 'c', 32 ) ) . '-direct.json';
		$path = $this->lock . '/invocations/' . $name;
		$journal = '{"cleanup":"not independently retired"}';
		$boot_wire_path = $this->lock . '/boot-wire/000001.bin';
		$boot_wire = file_get_contents( $boot_wire_path );
		file_put_contents( $path, $journal );
		chmod( $path, 0600 );
		try {
			$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
			$this->fail( 'Residual invocation journal accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Invocation cleanup journal remains', $error->getMessage() );
			$this->assertSame( $journal, file_get_contents( $path ) );
			$this->assertSame( $boot_wire, file_get_contents( $boot_wire_path ), 'Residual journal or evidence marker must also retain private boot wire.' );
			$this->assertFileExists( $this->probe() );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
		unlink( $path );
		$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
		$this->assertDirectoryDoesNotExist( $this->lock );
	}

	public static function residual_journals(): array {
		return array( array( 'owned' ), array( 'foreign' ), array( 'retained-evidence-marker' ) );
	}

	public function test_actual_finalization_gate_rejects_retained_invocation_journal_and_raw_spool(): void {
		$this->lifecycle->acquire( $this->cli() );
		$directory = $this->lock . '/invocations';
		Wstm126_Lifecycle::assert_invocations_empty( $directory );
		$journal = $directory . '/' . $this->token . '-http.json';
		$spool = $journal . '.wire.jsonl';
		file_put_contents( $journal, '{"cleanup_complete":false,"journal_retired":false}' );
		file_put_contents( $spool, '{"body_base64":"cHJpdmF0ZSByYXcgcmVzcG9uc2U="}' . "\n" );
		try {
			Wstm126_Lifecycle::assert_invocations_empty( $directory );
			$this->fail( 'Actual stage gate accepted retained failed response evidence.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Invocation cleanup journal remains', $error->getMessage() );
			$this->assertFileExists( $journal );
			$this->assertFileExists( $spool );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}

	public function test_missing_invocation_journal_directory_is_uncertain_not_recreated(): void {
		$this->lifecycle->acquire( $this->cli() );
		rmdir( $this->lock . '/invocations' );
		try {
			$this->lifecycle->restore( $this->cli(), $this->http() );
			$this->fail( 'Missing invocation journal directory accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertDirectoryDoesNotExist( $this->lock . '/invocations' );
			$this->assertDirectoryExists( $this->lock );
			$this->assertFileDoesNotExist( $this->probe() );
			$this->unchanged();
		}
	}

	/** @dataProvider rejected_wire_statuses */
	public function test_failed_http_raw_wire_is_private_before_parse_and_retained_until_verified_finalization( int $status ): void {
		$modes = array();
		$this->lifecycle = $this->instance( null, null, null, static function ( string $operation, string $path, ?int $mode ) use ( &$modes ) {
			if ( 'chmod' === $operation ) {
				$modes[ $path ] = $mode;
			}
			return self::filesystem( $operation, $path, $mode );
		} );
		$this->lifecycle->acquire( $this->cli() );
		$key = $this->lifecycle->probe_key();
		$wire = "\0{\"Authorization\":\"" . $key . "\",\"config\":\"PRIVATE_CONFIG_SENTINEL\"";
		$wire_path = $this->lock . '/boot-wire/000001.bin';
		$public = array();
		$http = function ( array $identity, array $expected, array $stale ) use ( $wire, $wire_path, $status, &$public, &$modes ): array {
			return Wstm126_Boot::converge(
				static fn(): array => array( 'status' => $status, 'body' => $wire ),
				function ( array $record ) use ( $wire, $wire_path, &$public, &$modes ): void {
					$this->assertSame( $wire, file_get_contents( $wire_path ), 'Exact raw bytes must exist before public logging or parsing.' );
					$this->assertSame( 0600, $modes[ $wire_path ], 'Private raw evidence must request owner-only permissions on every platform.' );
					if ( 'Windows' !== PHP_OS_FAMILY ) {
						$this->assertSame( 0600, fileperms( $wire_path ) & 07777 );
					}
					$public[] = $record;
				},
				$identity, $expected, $stale,
				static function (): void { self::fail( 'Failed wire must not retry.' ); },
				static fn(): float => 0.0,
				function ( string $body, ?int $code, int $attempt ): void { $this->lifecycle->retain_wire( $body, $code, $attempt ); }
			);
		};
		try {
			$this->lifecycle->prepare( $this->cli(), $http );
			$this->fail( 'Failed raw wire accepted.' );
		} catch ( RuntimeException | JsonException $error ) {
			$this->assertSame( $wire, file_get_contents( $wire_path ) );
			$this->assertCount( 1, $public );
			$this->assertSame( hash( 'sha256', $wire ), $public[0]['body_sha256'] );
			$this->assertStringNotContainsString( $key, json_encode( $public ) );
			$this->assertStringNotContainsString( 'PRIVATE_CONFIG_SENTINEL', json_encode( $public ) );
			$this->assertDirectoryExists( $this->lock );
			$this->assertFileExists( $this->probe() );
			$this->assertFileDoesNotExist( $this->wrapper() );
		}
		$this->lifecycle->restore( $this->cli(), $this->http() );
		$this->assertSame( $wire, file_get_contents( $wire_path ), 'Restore must preserve failed raw evidence.' );
		try {
			$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
			$this->fail( 'Missing invocation proofs accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $wire, file_get_contents( $wire_path ), 'Blocked finalization must preserve failed raw evidence.' );
			$this->assertFileExists( $this->probe() );
		}
		$this->reports();
		$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
		$this->assertDirectoryDoesNotExist( $this->lock );
		$this->assertFileDoesNotExist( $wire_path );
		$this->unchanged();
	}

	public static function rejected_wire_statuses(): array {
		return array( 'malformed-success-body' => array( 200 ), 'denied-body' => array( 403 ), 'server-error-body' => array( 500 ) );
	}

	public function test_foreign_private_wire_contents_are_never_overwritten_or_deleted(): void {
		$this->restored();
		$this->reports();
		$path = $this->lock . '/boot-wire/000001.bin';
		file_put_contents( $path, 'foreign private wire evidence' );
		try {
			$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
			$this->fail( 'Foreign private wire was deleted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Private wire evidence changed', $error->getMessage() );
			$this->assertSame( 'foreign private wire evidence', file_get_contents( $path ) );
			$this->assertFileExists( $this->probe() );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}

	public function test_untracked_private_wire_file_is_not_recursively_deleted(): void {
		$this->restored();
		$this->reports();
		$path = $this->lock . '/boot-wire/foreign.bin';
		file_put_contents( $path, 'foreign evidence' );
		try {
			$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
			$this->fail( 'Untracked private wire was deleted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Foreign or uncertain private wire file', $error->getMessage() );
			$this->assertSame( 'foreign evidence', file_get_contents( $path ) );
			$this->assertFileExists( $this->probe() );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}

	public function test_partial_private_wire_creation_retains_raw_bytes_and_blocks_recovery_guessing(): void {
		$this->lifecycle = $this->instance( null, null, null, static function ( string $operation, string $path, ?int $mode ) {
			if ( 'chmod' === $operation && '.bin' === substr( $path, -4 ) ) {
				return false;
			}
			return self::filesystem( $operation, $path, $mode );
		} );
		$this->lifecycle->acquire( $this->cli() );
		$wire = '{"malformed-private-wire":';
		$path = $this->lock . '/boot-wire/000001.bin';
		try {
			$this->lifecycle->retain_wire( $wire, 200, 1 );
			$this->fail( 'Private wire permissions failure accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Cannot set stage file permissions', $error->getMessage() );
			$this->assertSame( $wire, file_get_contents( $path ) );
			$this->assertDirectoryExists( $this->lock );
		}
		try {
			$this->lifecycle->restore( $this->cli(), $this->http() );
			$this->fail( 'Uncertain wire ownership was guessed during restore.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Foreign or uncertain private wire file', $error->getMessage() );
			$this->assertSame( $wire, file_get_contents( $path ) );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}

	public function test_lexical_alias_path_is_rejected(): void {
		$this->expectExceptionMessage( 'Unsafe lifecycle path' );
		$this->instance( null, $this->root . '/../site' );
	}

	public function test_symlinked_config_is_rejected_without_touching_target(): void {
		$target = $this->base . '/foreign-config';
		rename( $this->root . '/wp-config.php', $target );
		if ( ! @symlink( $target, $this->root . '/wp-config.php' ) ) {
			rename( $target, $this->root . '/wp-config.php' );
			$target = $this->root . '/wp-config.php';
			$this->lifecycle = $this->instance( null, null, null, static function ( string $operation, string $path, ?int $mode ) use ( $target ) {
				return 'is_link' === $operation && $path === $target ? true : self::filesystem( $operation, $path, $mode );
			} );
		}
		try {
			$this->lifecycle->acquire( $this->cli() );
			$this->fail( 'Symlinked config accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $this->config, file_get_contents( $target ) );
			$this->assertDirectoryDoesNotExist( $this->lock );
		}
	}

	public function test_private_config_mode_change_retains_guard(): void {
		$mode = 0640;
		$config_path = $this->root . '/wp-config.php';
		$this->lifecycle = $this->instance( null, null, null, static function ( string $operation, string $path, ?int $requested_mode ) use ( &$mode, $config_path ) {
			$result = self::filesystem( $operation, $path, $requested_mode );
			if ( 'stat' === $operation && $path === $config_path ) {
				$result['mode'] = ( $result['mode'] & ~07777 ) | $mode;
			}
			return $result;
		} );
		$this->lifecycle->acquire( $this->cli() );
		$mode = 0600;
		try {
			$this->lifecycle->restore( $this->cli(), $this->http() );
			$this->fail( 'Changed configuration mode accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Configuration bytes or metadata changed', $error->getMessage() );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			chmod( $config_path, 0600 );
			try {
				$this->instance()->restore( $this->cli(), $this->http() );
				$this->fail( 'Actual POSIX configuration mode change accepted.' );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'Configuration bytes or metadata changed', $error->getMessage() );
				$this->assertDirectoryExists( $this->lock );
			}
		}
	}

	/** @dataProvider ownership_fields */
	public function test_config_ownership_changes_fail_closed_through_filesystem_seam( string $field ): void {
		$changed = false;
		$config = $this->root . '/wp-config.php';
		$this->lifecycle = $this->instance( null, null, null, static function ( string $operation, string $path, ?int $mode ) use ( &$changed, $config, $field ) {
			$result = self::filesystem( $operation, $path, $mode );
			if ( $changed && 'stat' === $operation && $path === $config ) {
				$result[ $field ]++;
			}
			return $result;
		} );
		$this->lifecycle->acquire( $this->cli() );
		$changed = true;
		try {
			$this->lifecycle->restore( $this->cli(), $this->http() );
			$this->fail( 'Foreign configuration ownership accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Configuration bytes or metadata changed', $error->getMessage() );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}

	public static function ownership_fields(): array {
		return array( array( 'uid' ), array( 'gid' ) );
	}

	public function test_foreign_journal_content_is_never_overwritten(): void {
		$this->lifecycle->acquire( $this->cli() );
		$path = $this->lock . '/state.json';
		$state = json_decode( file_get_contents( $path ), true );
		$state['payload']['phase'] = 'foreign';
		$foreign = json_encode( $state );
		file_put_contents( $path, $foreign );
		try {
			$this->lifecycle->restore( $this->cli(), $this->http() );
			$this->fail( 'Foreign state accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $foreign, file_get_contents( $path ) );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}

	public function test_cli_http_disagreement_blocks_wrapper_installation(): void {
		$this->lifecycle->acquire( $this->cli() );
		$normal = $this->http();
		try {
			$this->lifecycle->prepare( $this->cli(), static function ( array $identity, array $expected, array $stale ) use ( $normal ): array {
				$body = $normal( $identity, $expected, $stale );
				$body['runtime']['flags']['EMPTY_TRASH_DAYS']['value'] = 0;
				return $body;
			} );
			$this->fail( 'Different original CLI and HTTP state accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Actual HTTP runtime differs', $error->getMessage() );
			$this->assertFileDoesNotExist( $this->wrapper() );
			$this->assertFileExists( $this->probe() );
			$this->assertDirectoryExists( $this->lock );
		}
		$this->lifecycle->restore( $this->cli(), $this->http() );
		$this->unchanged();
	}

	public function test_preexisting_nested_mu_prerequisites_are_preserved_through_every_phase(): void {
		$directory = $this->root . '/wp-content/mu-plugins/modules';
		mkdir( $directory, 0700 );
		$path = $directory . '/prerequisites.php';
		$original = "<?php /* Externally supplied CPT and SiteKit prerequisites. */\n";
		file_put_contents( $path, $original );
		chmod( $path, 0640 );
		$before = array( fileperms( $path ), fileowner( $path ), filegroup( $path ), fileinode( $path ) );
		$this->restored();
		$this->reports();
		$this->lifecycle->finalize( $this->cli(), $this->http(), $this->validator(), $this->hashes() );
		$this->assertSame( $original, file_get_contents( $path ) );
		clearstatcache( true, $path );
		$this->assertSame( $before, array( fileperms( $path ), fileowner( $path ), filegroup( $path ), fileinode( $path ) ) );
		$this->assertDirectoryExists( $directory );
		$this->unchanged();
	}

	/** @dataProvider foreign_fixture_deltas */
	public function test_foreign_fixture_delta_blocks_transitions_without_overwriting_originals( string $delta ): void {
		$directory = $this->root . '/wp-content/mu-plugins/modules';
		mkdir( $directory, 0700 );
		$path = $directory . '/prerequisites.php';
		file_put_contents( $path, '<?php /* original prerequisite */' );
		$this->lifecycle->acquire( $this->cli() );
		if ( 'baseline-http' !== $delta ) {
			$this->lifecycle->prepare( $this->cli(), $this->http() );
		}
		switch ( $delta ) {
			case 'changed':
				file_put_contents( $path, '<?php /* foreign replacement */' );
				break;
			case 'deleted':
				unlink( $path );
				break;
			case 'added':
				file_put_contents( $directory . '/foreign.php', '<?php /* foreign fixture */' );
				break;
			case 'baseline-http':
				break;
		}
		try {
			if ( 'baseline-http' === $delta ) {
				$normal = $this->http();
				$this->lifecycle->prepare( $this->cli(), static function ( array $identity, array $expected, array $stale ) use ( $normal, $path ): array {
					$body = $normal( $identity, $expected, $stale );
					file_put_contents( $path, '<?php /* foreign replacement */' );
					return $body;
				} );
			} else {
				$this->lifecycle->restore( $this->cli(), $this->http() );
			}
			$this->fail( 'Foreign MU fixture delta accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Preexisting MU fixture tree changed', $error->getMessage() );
			$this->assertDirectoryExists( $this->lock );
			$this->assertFileExists( $this->probe() );
			if ( 'baseline-http' === $delta ) {
				$this->assertFileDoesNotExist( $this->wrapper() );
			} else {
				$this->assertFileExists( $this->wrapper() );
			}
			if ( in_array( $delta, array( 'changed', 'baseline-http' ), true ) ) {
				$this->assertSame( '<?php /* foreign replacement */', file_get_contents( $path ) );
			} elseif ( 'deleted' === $delta ) {
				$this->assertFileDoesNotExist( $path );
			} else {
				$this->assertSame( '<?php /* foreign fixture */', file_get_contents( $directory . '/foreign.php' ) );
			}
			$this->unchanged();
		}
	}

	public static function foreign_fixture_deltas(): array {
		return array( array( 'changed' ), array( 'deleted' ), array( 'added' ), array( 'baseline-http' ) );
	}

	public function test_known_nested_loader_collision_is_rejected_before_resources(): void {
		$directory = $this->root . '/wp-content/mu-plugins/modules';
		mkdir( $directory, 0700 );
		$path = $directory . '/innocent.php';
		$foreign = "<?php require '/foreign/error-contract-fixture.php';";
		file_put_contents( $path, $foreign );
		try {
			$this->lifecycle->acquire( $this->cli() );
			$this->fail( 'Nested loader collision accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'loader content collision', $error->getMessage() );
			$this->assertSame( $foreign, file_get_contents( $path ) );
			$this->assertDirectoryDoesNotExist( $this->lock );
			$this->unchanged();
		}
	}

	public function test_restored_http_uid_must_match_original_http_uid(): void {
		$this->lifecycle->acquire( $this->cli() );
		$this->lifecycle->prepare( $this->cli(), $this->http() );
		$normal = $this->http();
		try {
			$this->lifecycle->restore( $this->cli(), static function ( array $identity, array $expected, array $stale ) use ( $normal ): array {
				$body = $normal( $identity, $expected, $stale );
				$body['identity']['uid'] = 0;
				return $body;
			} );
			$this->fail( 'Changed HTTP UID accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Foreign boot provenance: uid', $error->getMessage() );
			$this->assertFileDoesNotExist( $this->wrapper() );
			$this->assertFileExists( $this->probe() );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}

	public function test_private_journal_cannot_be_inside_webroot(): void {
		$unsafe = new Wstm126_Lifecycle( $this->root, $this->plugin, $this->root . '/private-lock', $this->token, $this->source, 'fake-project', $this->artifacts, array( 'direct' ) );
		$this->expectExceptionMessage( 'outside webroot' );
		$unsafe->acquire( $this->cli() );
	}

	public function test_private_probe_credential_journal_cannot_be_within_published_artifacts(): void {
		$unsafe = new Wstm126_Lifecycle( $this->root, $this->plugin, $this->lock, $this->token, $this->source, 'fake-project', $this->base, array( 'direct' ) );
		try {
			$unsafe->acquire( $this->cli() );
			$this->fail( 'Private probe credential directory exposed inside artifacts.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'disjoint from the artifact directory', $error->getMessage() );
			$this->assertDirectoryDoesNotExist( $this->lock );
			$this->unchanged();
		}
	}

	public function test_partial_creation_intent_is_retained_not_guessed(): void {
		$this->lifecycle->acquire( $this->cli() );
		$path = $this->lock . '/state.json';
		$sealed = json_decode( file_get_contents( $path ), true );
		$sealed['payload']['pending'] = 'probe';
		$sealed['seal'] = hash_hmac( 'sha256', json_encode( $sealed['payload'], JSON_THROW_ON_ERROR ), $this->token );
		file_put_contents( $path, json_encode( $sealed ) );
		file_put_contents( $this->probe(), '<?php /* incomplete */' );
		try {
			$this->lifecycle->restore( $this->cli(), $this->http() );
			$this->fail( 'Uncertain partial file was treated as owned.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Uncertain partial creation', $error->getMessage() );
			$this->assertSame( '<?php /* incomplete */', file_get_contents( $this->probe() ) );
			$this->assertDirectoryExists( $this->lock );
			$this->unchanged();
		}
	}
}
