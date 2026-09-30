<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/update-compatibility-baselines.php';
require_once dirname( __DIR__, 2 ) . '/scripts/compatibility-matrix.php';
require_once dirname( __DIR__, 2 ) . '/scripts/verify-candidate-floor.php';

final class CompatibilityBaselinesTest extends TestCase {
	private string $root;
	private array $baseline;

	protected function setUp(): void {
		$this->root = __DIR__ . '/.compatibility-fixture-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root . '/.github', 0777, true );
		$this->baseline = webmastery_mcp_read_baselines( dirname( __DIR__, 2 ) . '/.github/compatibility-versions.json' );
		$this->baseline['future_field'] = (object) array( 'nested' => (object) array(), 'enabled' => true );
		$this->writeConfig( $this->baseline );
		file_put_contents( $this->root . '/docker-compose.yml', "image: \${WORDPRESS_IMAGE:-wordpress:{$this->baseline['wordpress']}-php8.4-apache}\n" );
		file_put_contents( $this->root . '/readme.txt', "=== Fixture ===\nTested up to: 7.0\nRequires PHP: 8.0\n" );
	}

	protected function tearDown(): void {
		foreach ( array( '/.github/compatibility-versions.json', '/docker-compose.yml', '/readme.txt', '/scripts/update-compatibility-baselines.php', '/scripts/compatibility-baselines.php', '/candidate-runtime.json', '/candidate-server-observer.php', '/candidate-server-trace.json' ) as $file ) {
			if ( file_exists( $this->root . $file ) ) {
				unlink( $this->root . $file );
			}
		}
		if ( is_dir( $this->root . '/scripts' ) ) {
			rmdir( $this->root . '/scripts' );
		}
		rmdir( $this->root . '/.github' );
		rmdir( $this->root );
	}

	private function snapshot(): array {
		$contents = array();
		foreach ( array( '/.github/compatibility-versions.json', '/docker-compose.yml', '/readme.txt' ) as $file ) {
			$contents[ $file ] = file_get_contents( $this->root . $file );
		}
		return $contents;
	}

	private function runCli( array $arguments ): array {
		if ( ! is_dir( $this->root . '/scripts' ) ) {
			mkdir( $this->root . '/scripts' );
			foreach ( array( 'update-compatibility-baselines.php', 'compatibility-baselines.php' ) as $script ) {
				self::assertTrue( copy( dirname( __DIR__, 2 ) . '/scripts/' . $script, $this->root . '/scripts/' . $script ) );
			}
		}
		// Execute the real entry point with argv; its own location selects the disposable root.
		return $this->runPhpEntryPoint( $this->root . '/scripts/update-compatibility-baselines.php', $arguments );
	}

	private function runPhpEntryPoint( string $script, array $arguments ): array {
		$process = proc_open(
			array_merge( array( PHP_BINARY, $script ), $arguments ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			$this->root
		);
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $stdout, str_replace( "\r\n", "\n", $stderr ) );
	}

	private function cliArguments( array $options ): array {
		$arguments = array();
		foreach ( $options as $key => $value ) {
			$arguments[] = "--{$key}={$value}";
		}
		return $arguments;
	}

	/** @dataProvider workflowJobProvider */
	public function testWorkflowCliPromotionAndNoOp( string $job ): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/compatibility-qa.yml' );
		self::assertSame( 1, preg_match( '/^  ' . preg_quote( $job, '/' ) . ':\n(.*?)(?=^  [a-z-]+:|\z)/ms', $workflow, $section ) );
		self::assertSame( 1, preg_match( '/for key in ([a-z0-9_ ]+); do/', $section[1], $loop ) );
		$keys = explode( ' ', $loop[1] );
		self::assertSame( array( 'wordpress', 'mcp_adapter', 'mcp_adapter_sha256', 'wp_cli', 'wp_cli_sha512', 'yoast', 'seopress', 'plugin_check' ), $keys );
		self::assertStringContainsString( 'args+=("--${key//_/-}=$(php scripts/compatibility-baselines.php "$key" "$proposal")")', $section[1] );
		self::assertStringContainsString( 'php scripts/update-compatibility-baselines.php "${args[@]}" --confirmed-wordpress="$wp"', $section[1] );
		$proposal = $this->baseline;
		$options = array();
		foreach ( $keys as $key ) {
			$proposal[ $key ] = '99.1.2';
		}
		$proposal['mcp_adapter_sha256'] = str_repeat( 'a', 64 );
		$proposal['wp_cli_sha512'] = str_repeat( 'b', 128 );
		foreach ( $keys as $key ) {
			$options[ str_replace( '_', '-', $key ) ] = $proposal[ $key ];
		}
		$options['confirmed-wordpress'] = $proposal['wordpress'];
		$arguments = $this->cliArguments( $options );
		self::assertSame( array( 0, "Updated compatibility baselines.\n", '' ), $this->runCli( $arguments ) );
		self::assertEquals( $proposal, webmastery_mcp_read_baselines( $this->root . '/.github/compatibility-versions.json' ) );
		self::assertSame( "image: \${WORDPRESS_IMAGE:-wordpress:99.1.2-php8.4-apache}\n", file_get_contents( $this->root . '/docker-compose.yml' ) );
		self::assertSame( "=== Fixture ===\nTested up to: 99.1\nRequires PHP: 8.0\n", file_get_contents( $this->root . '/readme.txt' ) );
		$before = $this->snapshot();
		self::assertSame( array( 0, "Compatibility baselines unchanged.\n", '' ), $this->runCli( $arguments ) );
		self::assertSame( $before, $this->snapshot() );
	}

	public static function workflowJobProvider(): array {
		return array(
			'current checker metadata' => array( 'current-plugin-check' ),
			'proposed commit metadata' => array( 'open-update-pr' ),
		);
	}

	public static function sourceBoundJobProvider(): array {
		return array(
			'baseline-only dirty harness' => array( 'compatibility-qa', '.github/compatibility-versions.json', 'cp compatibility-artifacts/lane-versions.json', 'bash scripts/e2e-test.sh all' ),
			'current checker production metadata' => array( 'current-plugin-check', '.github/compatibility-versions.json docker-compose.yml readme.txt', 'php scripts/update-compatibility-baselines.php', 'bash scripts/release-qa.sh' ),
		);
	}

	/** @dataProvider sourceBoundJobProvider */
	public function testGeneratedInputsUseExactLocalCandidateBeforeQa( string $job, string $paths, string $generator, string $qa ): void {
		$workflow = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/compatibility-qa.yml' ) );
		self::assertSame( 1, preg_match( '/^  ' . preg_quote( $job, '/' ) . ':\n(.*?)(?=^  [a-z-]+:|\z)/ms', $workflow, $section ) );
		$source = $section[1];
		self::assertSame( 1, preg_match( '/      - name: Commit exact generated inputs for source-bound QA\n(.*?)(?=      - name:)/s', $source, $step ) );
		$candidate = $step[1];
		$exclude = implode( ' ', array_map( static fn( string $path ): string => "':(exclude)" . $path . "'", explode( ' ', $paths ) ) );
		self::assertStringContainsString( 'git diff --quiet -- . ' . $exclude, $candidate );
		self::assertStringContainsString( 'git add -- ' . $paths . "\n", $candidate );
		foreach ( array(
			'test "$(git rev-parse HEAD)" = "$BASE_SOURCE_SHA"',
			'if [[ -e compatibility-artifacts/tested-source.json || -L compatibility-artifacts/tested-source.json ]]; then',
			'git diff --cached --quiet || { status=$?;',
			'untracked="$(git ls-files --others --exclude-standard -- . \':(exclude)compatibility-artifacts/**\')"',
			'test -z "$untracked"',
			"if git diff --cached --quiet; then\n            changed=false",
			'test "$status" = 1 || exit "$status"',
			'git diff --exit-code HEAD',
			'tested="$(git rev-parse HEAD)"',
			'set -o noclobber',
			'{base_source:$base,tested_source:$tested,tested_tree:$tree,changed:$changed}',
			'printf \'source-sha=%s\n\' "$tested"',
		) as $guard ) {
			self::assertStringContainsString( $guard, $candidate );
		}
		self::assertStringNotContainsString( 'git push', $candidate );
		self::assertStringNotContainsString( 'git checkout', $candidate );
		self::assertStringNotContainsString( '--allow-empty', $candidate );
		self::assertStringNotContainsString( 'git add .', $candidate );
		self::assertLessThan( strpos( $candidate, 'git add -- ' ), strpos( $candidate, 'if [[ -e compatibility-artifacts/tested-source.json' ) );
		self::assertLessThan( strpos( $source, $step[0] ), strpos( $source, $generator ) );
		self::assertLessThan( strpos( $source, $qa ), strpos( $source, $step[0] ) );
		self::assertStringContainsString( 'BASE_SOURCE_SHA: ${{ needs.discover-versions.outputs.source-sha }}', $candidate );
		self::assertStringContainsString( 'WSTM108_EXPECTED_SOURCE: ${{ steps.tested-source.outputs.source-sha }}', $source );
		self::assertStringNotContainsString( 'WSTM108_EXPECTED_SOURCE: ${{ needs.discover-versions.outputs.source-sha }}', $source );
	}

	/** @dataProvider invalidCliProvider */
	public function testCliRejectsInvalidArgumentsBeforeAnyWrite( array $overrides, array $remove, array $extra, string $error ): void {
		$options = array_merge( $this->options(), array( 'wp-cli' => '99.1.0', 'wp-cli-sha512' => str_repeat( 'b', 128 ) ), $overrides );
		foreach ( $remove as $key ) {
			unset( $options[ $key ] );
		}
		$before = $this->snapshot();
		$result = $this->runCli( array_merge( $this->cliArguments( $options ), $extra ) );
		self::assertSame( 1, $result[0] );
		self::assertSame( '', $result[1] );
		self::assertStringStartsWith( 'ERROR ', $result[2] );
		self::assertStringContainsString( $error, $result[2] );
		self::assertSame( $before, $this->snapshot() );
	}

	public static function invalidCliProvider(): array {
		return array(
			'duplicate version' => array( array(), array(), array( '--wordpress=99.1.2' ), 'Expected unique' ),
			'duplicate adapter digest' => array( array(), array(), array( '--mcp-adapter-sha256=' . str_repeat( 'a', 64 ) ), 'Expected unique' ),
			'duplicate cli digest' => array( array(), array(), array( '--wp-cli-sha512=' . str_repeat( 'b', 128 ) ), 'Expected unique' ),
			'unknown digit option' => array( array(), array(), array( '--sha123=abc' ), 'Unknown or invalid option: sha123' ),
			'unknown option' => array( array(), array(), array( '--typo=abc' ), 'Unknown or invalid option: typo' ),
			'empty value' => array( array(), array(), array( '--yoast=' ), 'Expected unique' ),
			'positional argument' => array( array(), array(), array( 'yoast=99.1' ), 'Expected unique' ),
			'missing equals' => array( array(), array(), array( '--yoast' ), 'Expected unique' ),
			'underscore option' => array( array(), array(), array( '--plugin_check=99.1' ), 'Expected unique' ),
			'missing wordpress' => array( array(), array( 'wordpress' ), array(), 'Required:' ),
			'missing adapter' => array( array(), array( 'mcp-adapter' ), array(), 'Required:' ),
			'missing confirmation' => array( array(), array( 'confirmed-wordpress' ), array(), 'WordPress promotion requires' ),
			'wrong confirmation' => array( array( 'confirmed-wordpress' => '99.2.0' ), array(), array(), 'WordPress promotion requires' ),
			'missing adapter digest' => array( array(), array( 'mcp-adapter-sha256' ), array(), 'Changing mcp_adapter requires' ),
			'missing cli digest' => array( array(), array( 'wp-cli-sha512' ), array(), 'Changing wp_cli requires' ),
			'short adapter digest' => array( array( 'mcp-adapter-sha256' => 'abcd' ), array(), array(), 'invalid compatibility baseline: mcp_adapter_sha256' ),
			'nonhex adapter digest' => array( array( 'mcp-adapter-sha256' => str_repeat( 'g', 64 ) ), array(), array(), 'invalid compatibility baseline: mcp_adapter_sha256' ),
			'short cli digest' => array( array( 'wp-cli-sha512' => str_repeat( 'a', 64 ) ), array(), array(), 'invalid compatibility baseline: wp_cli_sha512' ),
			'nonhex cli digest' => array( array( 'wp-cli-sha512' => str_repeat( 'g', 128 ) ), array(), array(), 'invalid compatibility baseline: wp_cli_sha512' ),
			'invalid version' => array( array( 'wordpress' => '99.1;echo unsafe' ), array(), array(), 'Invalid version or downgrade' ),
			'invalid schema version' => array( array( 'mcp-adapter' => '99.1' ), array(), array(), 'invalid compatibility baseline: mcp_adapter' ),
			'downgrade' => array( array( 'wordpress' => '1.0', 'confirmed-wordpress' => '1.0' ), array(), array(), 'Invalid version or downgrade' ),
		);
	}

	/** @dataProvider invalidReferenceProvider */
	public function testCliRejectsInvalidReferencesBeforeAnyWrite( string $file, string $contents ): void {
		file_put_contents( $this->root . $file, $contents );
		$before = $this->snapshot();
		$result = $this->runCli( $this->cliArguments( $this->options() ) );
		self::assertSame( 1, $result[0] );
		self::assertSame( '', $result[1] );
		self::assertStringStartsWith( 'ERROR Expected exactly one version reference', $result[2] );
		self::assertSame( $before, $this->snapshot() );
	}

	private function writeConfig( array $config ): void {
		file_put_contents( $this->root . '/.github/compatibility-versions.json', json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
	}

	private function options(): array {
		return array(
			'wordpress'          => '99.1.2',
			'confirmed-wordpress' => '99.1.2',
			'mcp-adapter'        => '99.2.3',
			'mcp-adapter-sha256' => str_repeat( 'a', 64 ),
		);
	}

	public function testPromotionPreservesFullSchemaSuffixAndMajorMinorTestedHeader(): void {
		self::assertTrue( webmastery_mcp_update_baselines( $this->root, $this->options() ) );
		$updated = webmastery_mcp_read_baselines( $this->root . '/.github/compatibility-versions.json' );
		foreach ( array( 'wp_cli', 'wp_cli_sha512', 'yoast', 'seopress', 'plugin_check', 'future_field' ) as $key ) {
			self::assertEquals( $this->baseline[ $key ], $updated[ $key ] );
		}
		self::assertStringContainsString( 'wordpress:99.1.2-php8.4-apache', file_get_contents( $this->root . '/docker-compose.yml' ) );
		self::assertStringContainsString( "Tested up to: 99.1\nRequires PHP: 8.0", file_get_contents( $this->root . '/readme.txt' ) );
		self::assertFalse( webmastery_mcp_update_baselines( $this->root, $this->options() ) );
	}

	public function testAdapterOnlyUpdateDoesNotChangeTestedClaim(): void {
		file_put_contents( $this->root . '/readme.txt', "Tested up to: 6.9\r\n" );
		webmastery_mcp_update_baselines( $this->root, array( 'mcp-adapter' => '99.0.0', 'mcp-adapter-sha256' => str_repeat( 'b', 64 ) ) );
		self::assertSame( "Tested up to: 6.9\r\n", file_get_contents( $this->root . '/readme.txt' ) );
	}

	public function testPromotionPreservesCrLfHeader(): void {
		file_put_contents( $this->root . '/readme.txt', "Tested up to: 7.0\r\nRequires PHP: 8.0\r\n" );
		webmastery_mcp_update_baselines( $this->root, $this->options() );
		self::assertSame( "Tested up to: 99.1\r\nRequires PHP: 8.0\r\n", file_get_contents( $this->root . '/readme.txt' ) );
	}

	public function testNoOpDoesNotRewriteFiles(): void {
		$before = file_get_contents( $this->root . '/.github/compatibility-versions.json' );
		self::assertFalse( webmastery_mcp_update_baselines( $this->root, array() ) );
		self::assertSame( $before, file_get_contents( $this->root . '/.github/compatibility-versions.json' ) );
	}

	/** @dataProvider invalidReferenceProvider */
	public function testMissingOrDuplicateReferencesFailBeforeAnyWrite( string $file, string $contents ): void {
		$before = file_get_contents( $this->root . '/.github/compatibility-versions.json' );
		file_put_contents( $this->root . $file, $contents );
		try {
			webmastery_mcp_update_baselines( $this->root, $this->options() );
			self::fail( 'Invalid references were accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'exactly one', $error->getMessage() );
			self::assertSame( $before, file_get_contents( $this->root . '/.github/compatibility-versions.json' ) );
		}
	}

	public static function invalidReferenceProvider(): array {
		return array(
			'missing image' => array( '/docker-compose.yml', "image: wordpress:latest\n" ),
			'duplicate images' => array( '/docker-compose.yml', "wordpress:7.0.1-php8.2-apache\nwordpress:7.0.1-php8.4-apache\n" ),
			'missing tested' => array( '/readme.txt', "Tested up to: latest\n" ),
			'duplicate tested' => array( '/readme.txt', "Tested up to: 7.0\nTested up to: 7.1\n" ),
		);
	}

	/** @dataProvider invalidOptionsProvider */
	public function testInvalidOptionsDoNotWrite( array $options ): void {
		$before = file_get_contents( $this->root . '/.github/compatibility-versions.json' );
		try {
			webmastery_mcp_update_baselines( $this->root, $options );
			self::fail( 'Invalid options were accepted.' );
		} catch ( RuntimeException | InvalidArgumentException $error ) {
			self::assertSame( $before, file_get_contents( $this->root . '/.github/compatibility-versions.json' ) );
		}
	}

	public static function invalidOptionsProvider(): array {
		return array(
			'no confirmation' => array( array( 'wordpress' => '99.1' ) ),
			'wrong confirmation' => array( array( 'wordpress' => '99.1', 'confirmed-wordpress' => '99.2' ) ),
			'adapter needs digest' => array( array( 'mcp-adapter' => '99.1.0' ) ),
			'cli needs digest' => array( array( 'wp-cli' => '99.1.0' ) ),
			'bad digest' => array( array( 'mcp-adapter-sha256' => '1234' ) ),
			'injection' => array( array( 'wordpress' => '99.1;echo unsafe' ) ),
			'invalid schema version' => array( array( 'mcp-adapter' => '99.1', 'mcp-adapter-sha256' => str_repeat( 'a', 64 ) ) ),
			'downgrade' => array( array( 'wordpress' => '1.0', 'confirmed-wordpress' => '1.0' ) ),
			'unknown flag' => array( array( 'typo' => '7.1' ) ),
		);
	}

	public function testReaderRejectsMissingKeyAndMalformedJson(): void {
		unset( $this->baseline['wp_cli_sha512'] );
		$this->writeConfig( $this->baseline );
		try {
			webmastery_mcp_read_baselines( $this->root . '/.github/compatibility-versions.json' );
			self::fail( 'Missing digest accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'wp_cli_sha512', $error->getMessage() );
		}
		file_put_contents( $this->root . '/.github/compatibility-versions.json', '{malformed' );
		$this->expectException( JsonException::class );
		webmastery_mcp_read_baselines( $this->root . '/.github/compatibility-versions.json' );
	}

	public function testMatrixRetainsFloorAndUsesCandidateDigestOnlyForCandidateAdapter(): void {
		$latest = $this->baseline;
		$latest['wordpress'] = '99.1';
		$latest['mcp_adapter'] = '99.2.3';
		$latest['mcp_adapter_sha256'] = str_repeat( 'c', 64 );
		$lanes = webmastery_mcp_compatibility_matrix( $this->baseline, $latest )['include'];
		self::assertCount( 8, $lanes );
		self::assertCount( 8, array_unique( array_column( $lanes, 'label' ) ) );
		self::assertSame( 'wordpress:6.9-php8.1-apache', $lanes[0]['wordpress-image'] );
		self::assertSame( 'wp69-php81-compatibility', $lanes[0]['label'] );
		self::assertNotContains( 'supported-floor', array_column( $lanes, 'label' ) );
		self::assertSame( 'mysql:8.0.36', $lanes[0]['mysql-image'] );
		foreach ( $lanes as $lane ) {
			$expected = str_contains( $lane['mcp-adapter-zip'], '/v99.2.3/' ) ? $latest['mcp_adapter_sha256'] : $this->baseline['mcp_adapter_sha256'];
			self::assertSame( $expected, $lane['mcp-adapter-sha256'] );
		}
		self::assertStringContainsString( '-php8.4-', $lanes[5]['wordpress-image'] );
		self::assertSame( 'mysql:8.4', $lanes[6]['mysql-image'] );
		self::assertSame( 'latest-seo', $lanes[7]['dependency-policy'] );
	}

	public function testCurrentCheckerRequiresRuntimeEvidenceBeforeUpdatingCandidateMetadata(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/compatibility-qa.yml' );
		$start    = strpos( $workflow, '  current-plugin-check:' );
		$end      = strpos( $workflow, '  open-update-pr:' );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$job = substr( $workflow, $start, $end - $start );
		self::assertStringContainsString( 'needs: [discover-versions, compatibility-qa]', $job );
		self::assertStringContainsString( 'test "$RUNTIME_RESULT" = success', $job );
		self::assertStringContainsString( '--confirmed-wordpress="$wp"', $job );
		$update = strpos( $job, 'php scripts/update-compatibility-baselines.php' );
		$check  = strpos( $job, 'run: bash scripts/release-qa.sh' );
		self::assertNotFalse( $update );
		self::assertNotFalse( $check );
		self::assertLessThan( $check, $update );
	}

	private function candidateRuntime(): array {
		return array(
			'requested_source'    => str_repeat( 'a', 40 ),
			'actual_source'       => str_repeat( 'a', 40 ),
			'mounted_source_exit' => 0,
			'checkout_diff_exit'  => 0,
			'loaded_source'       => array(
				'entrypoint_included' => true,
				'response_file'       => '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/includes/class-response.php',
			),
			'wordpress'          => '6.9.4',
			'php'                => '8.0.30',
			'mysql_server'       => '8.0.36',
			'dependency_policy'  => 'pinned',
			'wp_cli'             => 'WP-CLI ' . $this->baseline['wp_cli'],
			'qa_result'          => 'success',
			'plugins'            => array(
				array( 'name' => 'webmastery-site-toolkit-for-mcp', 'version' => '2.6.0', 'status' => 'active' ),
				array( 'name' => 'mcp-adapter', 'version' => $this->baseline['mcp_adapter'], 'status' => 'active' ),
				array( 'name' => 'wordpress-seo', 'version' => $this->baseline['yoast'], 'status' => 'active' ),
				array( 'name' => 'wp-seopress', 'version' => $this->baseline['seopress'], 'status' => 'active' ),
			),
		);
	}

	public function testCandidateFloorAcceptsExactSourceAndObservedPinnedVersionsWithoutWrites(): void {
		$before  = $this->snapshot();
		$runtime = $this->candidateRuntime();
		webmastery_mcp_verify_candidate_floor( $runtime, $this->baseline );
		$runtime['wordpress'] = '6.9';
		webmastery_mcp_verify_candidate_floor( $runtime, $this->baseline );
		self::assertSame( $before, $this->snapshot() );
	}

	public function testPhp81CompatibilityCannotBeAcceptedAsPhp80Floor(): void {
		$runtime        = $this->candidateRuntime();
		$runtime['php'] = '8.1.34';
		webmastery_mcp_verify_candidate_floor( $runtime, $this->baseline, 'php81-compatibility' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Observed runtime does not match php80-floor: php' );
		webmastery_mcp_verify_candidate_floor( $runtime, $this->baseline );
	}

	public function testCompatibilityCliExplicitlyReportsPendingMinimum(): void {
		$path           = $this->root . '/candidate-runtime.json';
		$runtime        = $this->candidateRuntime();
		$runtime['php'] = '8.1.34';
		file_put_contents( $path, json_encode( $runtime, JSON_THROW_ON_ERROR ) );
		$result = $this->runPhpEntryPoint( dirname( __DIR__, 2 ) . '/scripts/verify-candidate-floor.php',
			array( $path, $this->root . '/.github/compatibility-versions.json', 'php81-compatibility' ) );
		self::assertSame( 0, $result[0] );
		self::assertStringContainsString( 'NOT PHP 8.0 floor proof; genuine PHP 8.0 remains required', $result[1] );
		$result = $this->runPhpEntryPoint( dirname( __DIR__, 2 ) . '/scripts/verify-candidate-floor.php',
			array( $path, $this->root . '/.github/compatibility-versions.json', 'invented' ) );
		self::assertSame( 1, $result[0] );
		self::assertStringContainsString( 'Unknown exact candidate runtime profile', $result[2] );
	}

	public function testCandidateFloorCliRetainsEvidenceAndReportsRealFailure(): void {
		$script    = dirname( __DIR__, 2 ) . '/scripts/verify-candidate-floor.php';
		$path      = $this->root . '/candidate-runtime.json';
		$arguments = array( $path, $this->root . '/.github/compatibility-versions.json' );
		$before    = $this->snapshot();
		foreach ( array( 'success', 'failure' ) as $outcome ) {
			$runtime              = $this->candidateRuntime();
			$runtime['qa_result'] = $outcome;
			$original             = json_encode( $runtime, JSON_THROW_ON_ERROR );
			file_put_contents( $path, $original );
			$result = $this->runPhpEntryPoint( $script, $arguments );
			self::assertSame( 'success' === $outcome ? 0 : 1, $result[0] );
			if ( 'success' === $outcome ) {
				self::assertStringContainsString( 'cleanup outcome remains separate', $result[1] );
				self::assertSame( '', $result[2] );
			} else {
				self::assertSame( '', $result[1] );
				self::assertStringContainsString( 'ERROR Full candidate E2E did not succeed', $result[2] );
			}
			self::assertSame( $original, file_get_contents( $path ) );
			self::assertSame( $before, $this->snapshot() );
		}
		file_put_contents( $path, '[]' );
		$result = $this->runPhpEntryPoint( $script, $arguments );
		self::assertSame( 1, $result[0] );
		self::assertStringContainsString( 'Runtime evidence must be a JSON object', $result[2] );
	}

	public function testCandidateFloorRequiresServerObservationEvenWithExpectedClient(): void {
		$runtime                 = $this->candidateRuntime();
		$runtime['mysql_client'] = 'mysql  Ver 8.0.36 for Linux on x86_64 (MySQL Community Server - GPL)';
		unset( $runtime['mysql_server'] );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Observed runtime does not match php80-floor: mysql_server' );
		webmastery_mcp_verify_candidate_floor( $runtime, $this->baseline );
	}

	/** @dataProvider candidateServerObservationProvider */
	public function testCandidateServerObserverQueriesWordPressAndPreservesOutputOrFailsPrivately( $value, string $error, bool $success ): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/compatibility-qa.yml' );
		$command  = 'capture mysql-server docker compose exec -T wordpress wp --allow-root eval \\' . "\n";
		self::assertSame( 1, preg_match( '/' . preg_quote( $command, '/' ) . " +'([^']+)'/", $workflow, $match ) );
		$script = $this->root . '/candidate-server-observer.php';
		$trace  = $this->root . '/candidate-server-trace.json';
		$before = $this->snapshot();
		$fixture = <<<'PHP'
<?php
$input = json_decode( $argv[1], false, 512, JSON_THROW_ON_ERROR );
$GLOBALS['wpdb'] = new class( $input->value, $input->error, $input->suppressed ) {
	public string $last_error = '';
	public array $queries = array();
	public array $suppressions = array();
	public bool $suppressed;
	private $value;
	private string $error;
	public function __construct( $value, string $error, bool $suppressed ) {
		$this->value = $value;
		$this->error = $error;
		$this->suppressed = $suppressed;
	}
	public function suppress_errors( bool $suppress ): bool {
		$previous = $this->suppressed;
		$this->suppressions[] = $suppress;
		$this->suppressed = $suppress;
		return $previous;
	}
	public function get_var( string $query ) {
		$this->queries[] = $query;
		$this->last_error = $this->error;
		if ( ! $this->suppressed && '' !== $this->error ) {
			echo $this->error;
		}
		return $this->value;
	}
};
register_shutdown_function( static function () use ( $argv ): void {
	$db = $GLOBALS['wpdb'];
	file_put_contents( $argv[2], json_encode( array(
		'queries' => $db->queries,
		'suppressions' => $db->suppressions,
		'suppressed' => $db->suppressed,
	), JSON_THROW_ON_ERROR ) );
} );
PHP;
		// WP-CLI evaluates its code in a function scope, so the observer must import the real global connection.
		file_put_contents( $script, $fixture . "\n(static function (): void {\n" . $match[1] . "\n})();\n" );
		foreach ( array( false, true ) as $suppressed ) {
			$input = json_encode( array( 'value' => $value, 'error' => $error, 'suppressed' => $suppressed ), JSON_THROW_ON_ERROR );
			$result = $this->runPhpEntryPoint( $script, array( $input, $trace ) );
			self::assertSame( $success ? 0 : 1, $result[0] );
			self::assertSame( $success ? $value : '', $result[1] );
			self::assertSame( $success ? '' : "ERROR Could not observe MySQL server version.\n", $result[2] );
			self::assertSame(
				array( 'queries' => array( 'SELECT VERSION()' ), 'suppressions' => array( true, $suppressed ), 'suppressed' => $suppressed ),
				json_decode( file_get_contents( $trace ), true, 512, JSON_THROW_ON_ERROR )
			);
			self::assertSame( $before, $this->snapshot() );
			unlink( $trace );
		}
	}

	public static function candidateServerObservationProvider(): array {
		return array(
			'pinned server' => array( '8.0.36', '', true ),
			'wrong server preserved for verifier' => array( '8.0.35', '', true ),
			'untrimmed output preserved for verifier' => array( ' 8.0.36', '', true ),
			'extra row preserved for verifier' => array( "8.0.36\nunexpected", '', true ),
			'query error without result' => array( null, 'PRIVATE database error', false ),
			'query error despite result' => array( '8.0.36', 'PRIVATE database error', false ),
			'null result without error' => array( null, '', false ),
			'empty result' => array( '', '', false ),
			'whitespace result' => array( " \t\n", '', false ),
			'integer result' => array( 8036, '', false ),
			'float result' => array( 8.036, '', false ),
			'boolean result' => array( false, '', false ),
			'array result' => array( array( '8.0.36' ), '', false ),
			'object result' => array( (object) array( 'version' => '8.0.36' ), '', false ),
		);
	}

	/** @dataProvider invalidCandidateRuntimeProvider */
	public function testCandidateFloorRejectsMissingWrongOrNonpassingEvidence( string $key, $value ): void {
		$runtime         = $this->candidateRuntime();
		$runtime[ $key ] = $value;
		$this->expectException( RuntimeException::class );
		webmastery_mcp_verify_candidate_floor( $runtime, $this->baseline );
	}

	public static function invalidCandidateRuntimeProvider(): array {
		return array(
			'abbreviated source' => array( 'requested_source', 'aaaaaaa' ),
			'uppercase source' => array( 'requested_source', str_repeat( 'A', 40 ) ),
			'source newline' => array( 'requested_source', str_repeat( 'a', 40 ) . "\n" ),
			'wrong checked out source' => array( 'actual_source', str_repeat( 'b', 40 ) ),
			'missing source observation' => array( 'actual_source', null ),
			'changed mounted source' => array( 'mounted_source_exit', 1 ),
			'missing mounted observation' => array( 'mounted_source_exit', null ),
			'string exit is not success' => array( 'mounted_source_exit', '0' ),
			'changed tracked checkout' => array( 'checkout_diff_exit', 1 ),
			'missing loaded source' => array( 'loaded_source', null ),
			'entrypoint not loaded' => array( 'loaded_source', array( 'entrypoint_included' => false, 'response_file' => '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/includes/class-response.php' ) ),
			'wrong loaded source' => array( 'loaded_source', array( 'entrypoint_included' => true, 'response_file' => '/other/includes/class-response.php' ) ),
			'baseline WordPress is not floor' => array( 'wordpress', '7.1.1' ),
			'WordPress prerelease is not floor' => array( 'wordpress', '6.9-RC1' ),
			'WordPress prefix is not version' => array( 'wordpress', '6.90' ),
			'baseline PHP is not floor' => array( 'php', '8.2.33' ),
			'PHP prerelease is not floor' => array( 'php', '8.1.34-dev' ),
			'missing PHP observation' => array( 'php', null ),
			'null MySQL server observation' => array( 'mysql_server', null ),
			'wrong MySQL server patch' => array( 'mysql_server', '8.0.35' ),
			'wrong MySQL server line' => array( 'mysql_server', '8.4.0' ),
			'MySQL client banner is not server version' => array( 'mysql_server', 'mysql  Ver 8.0.36 for Linux' ),
			'empty MySQL server observation' => array( 'mysql_server', '' ),
			'numeric MySQL server observation' => array( 'mysql_server', 8036 ),
			'array MySQL server observation' => array( 'mysql_server', array( '8.0.36' ) ),
			'boolean MySQL server observation' => array( 'mysql_server', true ),
			'MySQL server suffix' => array( 'mysql_server', '8.0.36-unexpected' ),
			'MySQL server extra row' => array( 'mysql_server', "8.0.36\n8.0.36" ),
			'MySQL server trailing newline' => array( 'mysql_server', "8.0.36\n" ),
			'floating dependencies' => array( 'dependency_policy', 'latest-seo' ),
			'wrong CLI' => array( 'wp_cli', 'WP-CLI 99.0.0' ),
			'missing plugin inventory' => array( 'plugins', null ),
			'empty plugin inventory' => array( 'plugins', array() ),
			'failed E2E' => array( 'qa_result', 'failure' ),
			'skipped E2E' => array( 'qa_result', 'skipped' ),
			'cancelled E2E' => array( 'qa_result', 'cancelled' ),
			'missing E2E observation' => array( 'qa_result', null ),
		);
	}

	/** @dataProvider candidatePluginMismatchProvider */
	public function testCandidateFloorRejectsDependencyMismatch( int $index, string $mutation ): void {
		$runtime = $this->candidateRuntime();
		if ( 'missing' === $mutation ) {
			array_splice( $runtime['plugins'], $index, 1 );
		} elseif ( 'duplicate' === $mutation ) {
			$runtime['plugins'][] = $runtime['plugins'][ $index ];
		} elseif ( 'inactive' === $mutation ) {
			$runtime['plugins'][ $index ]['status'] = 'inactive';
		} else {
			$runtime['plugins'][ $index ]['version'] = '99.0.0';
		}
		$this->expectException( RuntimeException::class );
		webmastery_mcp_verify_candidate_floor( $runtime, $this->baseline );
	}

	public static function candidatePluginMismatchProvider(): array {
		$cases = array();
		foreach ( array( 'toolkit', 'adapter', 'yoast', 'seopress' ) as $index => $name ) {
			foreach ( array( 'missing', 'duplicate', 'inactive', 'wrong-version' ) as $mutation ) {
				if ( 0 === $index && 'wrong-version' === $mutation ) {
					continue;
				}
				$cases[ $name . '-' . $mutation ] = array( $index, $mutation );
			}
		}
		return $cases;
	}

	public function testCandidateCompatibilityUsesSiblingProofHostWithoutClaimingPhp80Floor(): void {
		$workflow = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/compatibility-qa.yml' ) );
		$jobs = array();
		foreach ( array( 'candidate-floor', 'compatibility-qa' ) as $name ) {
			self::assertSame( 1, preg_match( '/^  ' . preg_quote( $name, '/' ) . ':\n(.*?)(?=^  [a-z-]+:|\z)/ms', $workflow, $match ) );
			$jobs[ $name ] = $match[1];
		}
		$proof_steps = '/      - name: Set up host QA PHP\n.*?      - name: Bind canonical host QA interpreter\n.*?(?=      - name:)/s';
		$steps = array();
		foreach ( $jobs as $name => $source ) {
			self::assertSame( 1, preg_match( $proof_steps, $source, $steps[ $name ] ), $name . ' must select its independent proof host before QA.' );
		}
		self::assertSame( $steps['compatibility-qa'][0], $steps['candidate-floor'][0] );
		foreach ( array(
			'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240',
			"php-version: '8.4'", 'extensions: zip, posix', 'coverage: none',
			'binary="$(readlink -e /usr/bin/php8.4)"',
			'test "${binary##*/}" = php8.4',
			'printf \'WSTM108_HOST_PHP=%s\n\' "$binary" >> "$GITHUB_ENV"',
		) as $guard ) {
			self::assertStringContainsString( $guard, $steps['candidate-floor'][0] );
		}
		$candidate = $jobs['candidate-floor'];
		self::assertSame( 1, preg_match( '/      - name: Run unchanged full candidate E2E harness\n(.*?)(?=      - name:)/s', $candidate, $qa ) );
		foreach ( array( 'id: qa', 'E2E_MANAGE_COMPOSE: 1', 'E2E_KEEP_COMPOSE: 1',
			'WSTM108_HOST_AUTHORITY_ROOT: ${{ runner.temp }}', 'run: bash scripts/e2e-test.sh all' ) as $guard ) {
			self::assertStringContainsString( $guard, $qa[1] );
		}
		self::assertSame( 1, substr_count( $candidate, 'WSTM108_HOST_AUTHORITY_ROOT:' ) );
		self::assertLessThan( strpos( $candidate, 'Bind source and candidate-pinned' ), strpos( $candidate, $steps['candidate-floor'][0] ) );
		self::assertLessThan( strpos( $candidate, $qa[0] ), strpos( $candidate, $steps['candidate-floor'][0] ) );
		self::assertStringContainsString( '.label == "wp69-php81-compatibility"', $candidate );
		self::assertStringContainsString( 'wordpress:6.9-php8.1-apache', $candidate );
		self::assertStringContainsString( 'candidate-pins.json php81-compatibility', $candidate );
		self::assertStringContainsString( 'Genuine PHP 8.0 full candidate E2E remains required.', $candidate );
		foreach ( array( 'php80-floor', 'wordpress:6.9-php8.0-apache', 'wordpress:6.9-php8.4-apache',
			'WSTM108_MOCK', 'sudo ', 'chmod ', 'mkdir ', 'continue-on-error:' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $steps['candidate-floor'][0] . $qa[1] );
		}
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-bootstrap.sh' );
		foreach ( array( 'umask 077', 'set -o noclobber', 'mkdir -m 700 -- "$directory"',
			'[[ -n "$root" && "$root" == "$(readlink -e -- "$root")"',
			'[[ -d "$root" && ! -L "$root"', '(( ( 8#$(stat -c %a -- "$root") & 0022 ) == 0 ))' ) as $guard ) {
			self::assertStringContainsString( $guard, $bootstrap );
		}
	}

	public function testCandidateWorkflowIsIsolatedFromDiscoveryAndPromotion(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/compatibility-qa.yml' );
		$jobs     = array();
		foreach ( array( 'candidate-floor', 'discover-versions', 'open-update-pr', 'report' ) as $name ) {
			self::assertSame( 1, preg_match( '/^  ' . preg_quote( $name, '/' ) . ':\n(.*?)(?=^  [a-z-]+:|\z)/ms', $workflow, $match ) );
			$jobs[ $name ] = $match[1];
		}
		$candidate = $jobs['candidate-floor'];
		self::assertStringContainsString( "if: github.event_name == 'workflow_dispatch' && inputs.candidate_sha != ''", $candidate );
		self::assertStringContainsString( '[[ ! "$CANDIDATE_SHA" =~ ^[0-9a-f]{40}$ ]] || [[ "$OPEN_UPDATE_PR" != false ]]', $candidate );
		self::assertLessThan( strpos( $candidate, 'uses: actions/checkout@' ), strpos( $candidate, '[[ ! "$CANDIDATE_SHA"' ) );
		self::assertStringContainsString( 'ref: ${{ github.workflow_sha }}' . "\n          path: candidate-floor-tools", $candidate );
		self::assertStringContainsString( 'ref: ${{ inputs.candidate_sha }}' . "\n          path: candidate", $candidate );
		self::assertStringContainsString( 'test "$(git rev-parse HEAD)" = "$CANDIDATE_SHA"', $candidate );
		self::assertStringContainsString( 'test "$(git -C ../candidate-floor-tools rev-parse HEAD)" = "$WORKFLOW_SHA"', $candidate );
		self::assertStringContainsString( 'php scripts/compatibility-matrix.php .github/compatibility-versions.json .github/compatibility-versions.json', $candidate );
		self::assertStringContainsString( 'run: bash scripts/e2e-test.sh all', $candidate );
		self::assertStringContainsString( 'wordpress sha256sum --check --strict < compatibility-artifacts/source.sha256', $candidate );
		self::assertStringContainsString( 'new ReflectionClass("Webmastery_MCP_Response")', $candidate );
		self::assertStringContainsString( 'php ../candidate-floor-tools/scripts/verify-candidate-floor.php', $candidate );
		self::assertStringContainsString( 'compatibility-artifacts/runtime.json compatibility-artifacts/candidate-pins.json php81-compatibility', $candidate );
		self::assertStringContainsString( '.label == "wp69-php81-compatibility"', $candidate );
		self::assertStringContainsString( 'Not PHP 8.0 minimum-runtime proof', $candidate );
		self::assertStringNotContainsString( 'supported-floor', $candidate );
		self::assertStringContainsString( 'run: bash scripts/destructive-retention.sh cleanup', $candidate );
		self::assertStringContainsString( 'capture checkout git diff --exit-code HEAD', $candidate );
		self::assertStringContainsString( 'capture mysql-server docker compose exec -T wordpress wp --allow-root eval', $candidate );
		self::assertStringNotContainsString( 'wp --allow-root db query', $candidate );
		self::assertStringContainsString( '.["mysql-image"] == "mysql:8.0.36"', $candidate );
		self::assertStringContainsString( '$wpdb->get_var( "SELECT VERSION()" )', $candidate );
		self::assertStringContainsString( '--rawfile mysql_server compatibility-artifacts/mysql-server.stdout', $candidate );
		self::assertStringContainsString( 'mysql_server:($mysql_server|line)', $candidate );
		self::assertStringContainsString( 'capture mysql docker compose exec -T mysql mysql --version', $candidate );
		self::assertStringContainsString( 'mcp-candidate-floor-${{ github.run_id }}-${{ github.run_attempt }}', $candidate );
		self::assertStringContainsString( 'candidate/e2e-artifacts/', $candidate );
		foreach ( array( 'contents: write', 'issues: write', 'pull-requests: write', 'gh ', 'git push', 'update-compatibility-baselines.php', 'discover-compatibility.sh', 'download-artifact@', 'bounded-list-controller.py', 'continue-on-error:' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $candidate );
		}
		foreach ( array( 'discover-versions', 'open-update-pr', 'report' ) as $normal ) {
			self::assertStringContainsString( "inputs.candidate_sha == ''", $jobs[ $normal ] );
		}
		self::assertStringContainsString( 'test "$SOURCE_REF" = "refs/heads/$DEFAULT_BRANCH"', $jobs['discover-versions'] );
		self::assertStringContainsString( 'test "$(git rev-parse HEAD)" = "$(git rev-parse FETCH_HEAD)"', $jobs['discover-versions'] );
		self::assertStringContainsString( "needs.current-plugin-check.result == 'success'", $jobs['open-update-pr'] );
		self::assertStringContainsString( "if: always() && inputs.candidate_sha == ''", $jobs['report'] );
	}
}
