<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/update-compatibility-baselines.php';
require_once dirname( __DIR__, 2 ) . '/scripts/compatibility-matrix.php';

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
		foreach ( array( '/.github/compatibility-versions.json', '/docker-compose.yml', '/readme.txt', '/scripts/update-compatibility-baselines.php', '/scripts/compatibility-baselines.php' ) as $file ) {
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
		$process = proc_open(
			array_merge( array( PHP_BINARY, $this->root . '/scripts/update-compatibility-baselines.php' ), $arguments ),
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
}
