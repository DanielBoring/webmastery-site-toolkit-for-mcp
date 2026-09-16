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
		foreach ( array( '/.github/compatibility-versions.json', '/docker-compose.yml', '/readme.txt' ) as $file ) {
			if ( file_exists( $this->root . $file ) ) {
				unlink( $this->root . $file );
			}
		}
		rmdir( $this->root . '/.github' );
		rmdir( $this->root );
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
