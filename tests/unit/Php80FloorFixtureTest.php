<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/fixtures/php80-floor/fixture.php';
require_once dirname( __DIR__ ) . '/fixtures/php80-floor/router.php';

final class Php80FloorFixtureTest extends TestCase {
	private function config(): array {
		return array(
			'profile' => 'php80-floor', 'php_base_image' => 'php:8.0.30-cli@sha256:' . str_repeat( 'a', 64 ),
			'php_binary_sha256' => str_repeat( 'b', 64 ), 'wordpress_version' => '6.9',
			'wordpress_archive_sha256' => str_repeat( 'c', 64 ), 'mysql_image' => 'mysql:8.0.36@sha256:' . str_repeat( 'd', 64 ),
			'runtime_image' => null, 'candidate_sha' => str_repeat( 'e', 40 ), 'candidate_tree' => str_repeat( 'f', 40 ),
			'candidate_root' => '/owned/candidate', 'project' => 'wstm-php80-owned', 'http_port' => 18080,
			'artifact_directory' => 'owned_floor_evidence', 'wp_config' => '/private/wp-config.php',
			'wp_config_sha256' => str_repeat( '1', 64 ), 'mysql_env_file' => '/private/mysql.env',
			'mysql_env_sha256' => str_repeat( '2', 64 ), 'dependency_policy' => 'pinned',
		);
	}

	private function record( string $sapi ): array {
		return array( 'profile' => 'php80-floor', 'php' => '8.0.30', 'sapi' => $sapi, 'php_binary_sha256' => str_repeat( 'b', 64 ) );
	}

	public function testBuildAndRuntimeSelectionAreDistinctWithoutAuthorizingCommands(): void {
		$config = $this->config();
		$plan = wstm_php80_floor_plan( $config );
		self::assertNull( $plan['compose_argv'] );
		self::assertFalse( $plan['executes_commands'] );
		self::assertSame( 'not_supplied', $plan['authorization'] );
		self::assertContains( 'PHP80_BASE_IMAGE=' . $config['php_base_image'], $plan['build_argv'] );
		self::assertContains( 'WP_CORE_SHA256=' . $config['wordpress_archive_sha256'], $plan['build_argv'] );
		$config['runtime_image'] = 'sha256:' . str_repeat( '3', 64 );
		$plan = wstm_php80_floor_plan( $config );
		self::assertSame( '/owned/candidate/tests/fixtures/php80-floor/compose.yml', end( $plan['compose_argv'] ) );
		self::assertSame( $config['runtime_image'], $plan['environment']['PHP80_RUNTIME_IMAGE'] );
		self::assertSame( '0', $plan['environment']['E2E_MANAGE_COMPOSE'] );
		self::assertSame( 'pinned', $plan['environment']['DEPENDENCY_POLICY'] );
		self::assertArrayNotHasKey( 'WSTM108_HOST_PHP', $plan['environment'] );
		self::assertArrayNotHasKey( 'GITHUB_RUN_ID', $plan['environment'] );
	}

	/** @dataProvider invalidInputs */
	public function testProfilePinsEnvironmentAndSelectionRefuseDrift( string $key, mixed $value ): void {
		$config = $this->config();
		$config[ $key ] = $value;
		$this->expectException( RuntimeException::class );
		wstm_php80_floor_config( $config );
	}

	public static function invalidInputs(): array {
		return array(
			array( 'profile', 'php81-compatibility' ), array( 'dependency_policy', 'latest-seo' ),
			array( 'php_base_image', 'php:8.0.30-cli' ), array( 'php_base_image', 'php:8.4-cli@sha256:' . str_repeat( 'a', 64 ) ),
			array( 'mysql_image', 'mysql:8.0.36' ), array( 'wordpress_version', '7.1.2' ),
			array( 'php_binary_sha256', str_repeat( 'B', 64 ) ), array( 'wordpress_archive_sha256', '' ),
			array( 'runtime_image', 'wstm-php80-floor:latest' ), array( 'runtime_image', '' ),
			array( 'candidate_sha', 'old-head' ), array( 'candidate_tree', '' ),
			array( 'candidate_root', '/owned/../candidate' ), array( 'candidate_root', '/' ),
			array( 'project', 'shared-default' ), array( 'http_port', '18080' ), array( 'http_port', 18080.0 ),
			array( 'http_port', 0 ), array( 'http_port', 65536 ), array( 'artifact_directory', '../evidence' ),
			array( 'wp_config', '/private/mysql.env' ), array( 'mysql_env_sha256', null ),
			array( 'wp_config', '/owned/candidate/private.php' ), array( 'mysql_env_file', '/owned/candidate/mysql.env' ),
			array( 'foreign', true ),
		);
	}

	public function testMissingInputsAreNotDefaulted(): void {
		foreach ( array_keys( $this->config() ) as $key ) {
			$config = $this->config();
			unset( $config[ $key ] );
			try {
				wstm_php80_floor_config( $config );
				self::fail( 'Missing field accepted: ' . $key );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'Missing or extra', $error->getMessage() );
			}
		}
	}

	public function testDifferentHttpVersionSapiElfAndShapeAreNotFloorProof(): void {
		wstm_php80_floor_pair( $this->config(), $this->record( 'cli' ), $this->record( 'cli-server' ) );
		foreach ( array( 'php' => '8.1.33', 'sapi' => 'cli', 'php_binary_sha256' => str_repeat( '4', 64 ), 'extra' => true ) as $key => $value ) {
			$http = $this->record( 'cli-server' );
			$http[ $key ] = $value;
			try {
				wstm_php80_floor_pair( $this->config(), $this->record( 'cli' ), $http );
				self::fail( 'Substituted HTTP observation accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'CLI/HTTP', $error->getMessage() );
			}
		}
	}

	public function testFullCandidateGateIsNotReplacedByMatchingVersionProbes(): void {
		$config = $this->config();
		$config['runtime_image'] = 'sha256:' . str_repeat( '3', 64 );
		$pins = webmastery_mcp_read_baselines( dirname( __DIR__, 2 ) . '/.github/compatibility-versions.json' );
		$runtime = array(
			'requested_source' => $config['candidate_sha'], 'actual_source' => $config['candidate_sha'],
			'source_tree' => $config['candidate_tree'],
			'mounted_source_exit' => 0, 'checkout_diff_exit' => 0, 'wordpress' => '6.9', 'php' => '8.0.30',
			'mysql_server' => '8.0.36', 'wp_cli' => 'WP-CLI ' . $pins['wp_cli'], 'dependency_policy' => 'pinned', 'qa_result' => 'success',
			'loaded_source' => array( 'entrypoint_included' => true, 'response_file' => '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/includes/class-response.php' ),
			'plugins' => array(),
		);
		foreach ( array( 'webmastery-site-toolkit-for-mcp' => '3.0.0', 'mcp-adapter' => $pins['mcp_adapter'], 'wordpress-seo' => $pins['yoast'], 'wp-seopress' => $pins['seopress'] ) as $name => $version ) {
			$runtime['plugins'][] = array( 'name' => $name, 'version' => $version, 'status' => 'active' );
		}
		wstm_php80_floor_verify( $config, $runtime, $pins, $this->record( 'cli' ), $this->record( 'cli-server' ) );
		$unselected = $config;
		$unselected['runtime_image'] = null;
		try {
			wstm_php80_floor_verify( $unselected, $runtime, $pins, $this->record( 'cli' ), $this->record( 'cli-server' ) );
			self::fail( 'Unselected build accepted for runtime verification.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'immutable runtime image', $error->getMessage() );
		}
		foreach ( array(
			'qa_result' => 'failure', 'mounted_source_exit' => 1, 'checkout_diff_exit' => 1,
			'actual_source' => str_repeat( '9', 40 ), 'mysql_server' => '8.0.37', 'wordpress' => '6.9.1',
			'source_tree' => str_repeat( '9', 40 ),
			'dependency_policy' => 'latest-seo', 'wp_cli' => 'WP-CLI 0.0.0', 'plugins' => array(),
		) as $key => $value ) {
			$bad = $runtime;
			$bad[ $key ] = $value;
			try {
				wstm_php80_floor_verify( $config, $bad, $pins, $this->record( 'cli' ), $this->record( 'cli-server' ) );
				self::fail( 'Candidate gate weakened: ' . $key );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}

	/** @dataProvider routes */
	public function testRouterKeepsRestRewritesAndPrivateFileRefusals( string $uri, string $expected ): void {
		self::assertSame( $expected, wstm_php80_floor_route( $uri, '/nonexistent-owned-root' ) );
	}

	public static function routes(): array {
		return array(
			array( '/_wstm_php80_floor', 'probe' ), array( '/_wstm_php80_floor?request=original', 'probe' ),
			array( '/wp-json/mcp/mcp-adapter-default-server', 'index' ), array( '/?rest_route=/wp/v2/posts', 'index' ),
			array( '/post-slug/', 'index' ), array( '/wp-config.php', 'refuse' ), array( '/wp-config-sample.php', 'refuse' ),
			array( '/%2e%2e/private', 'refuse' ), array( '/.htaccess', 'refuse' ), array( '/a%5cb', 'refuse' ), array( '/%00', 'refuse' ),
		);
	}

	public function testExistingPublicFileIsServedWithoutBootstrappingWordPress(): void {
		$root = sys_get_temp_dir() . '/wstm-php80-route-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $root, 0700 ) );
		try {
			self::assertSame( 6, file_put_contents( $root . '/public.css', 'public' ) );
			self::assertSame( 'file', wstm_php80_floor_route( '/public.css?original=1', $root ) );
			self::assertSame( 'index', wstm_php80_floor_route( '/missing.css', $root ) );
		} finally {
			unlink( $root . '/public.css' );
			rmdir( $root );
		}
	}

	public function testNativeSymlinksCannotExposeForeignOrPrivateFiles(): void {
		if ( 'Linux' !== PHP_OS_FAMILY ) {
			self::markTestSkipped( 'Requires native Linux filesystem symlinks; no runtime is started.' );
		}
		$parent = sys_get_temp_dir() . '/wstm-php80-links-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $parent, 0700 ) );
		self::assertTrue( mkdir( $parent . '/root', 0700 ) );
		$root = $parent . '/root';
		try {
			self::assertSame( 6, file_put_contents( $parent . '/foreign.txt', 'public' ) );
			self::assertSame( 7, file_put_contents( $root . '/wp-config.php', 'private' ) );
			self::assertSame( 7, file_put_contents( $root . '/.original', 'private' ) );
			foreach ( array( 'foreign' => $parent . '/foreign.txt', 'config' => $root . '/wp-config.php', 'hidden' => $root . '/.original' ) as $name => $target ) {
				self::assertTrue( symlink( $target, $root . '/' . $name ) );
				self::assertSame( 'refuse', wstm_php80_floor_route( '/' . $name, $root ) );
			}
		} finally {
			foreach ( array( 'foreign', 'config', 'hidden', 'wp-config.php', '.original' ) as $name ) {
				if ( is_link( $root . '/' . $name ) || is_file( $root . '/' . $name ) ) {
					unlink( $root . '/' . $name );
				}
			}
			unlink( $parent . '/foreign.txt' );
			rmdir( $root );
			rmdir( $parent );
		}
	}

	public function testSourceUsesOneElfAndExistingDownloaderWithoutInventingAnImage(): void {
		$root = dirname( __DIR__ ) . '/fixtures/php80-floor/';
		$dockerfile = file_get_contents( $root . 'Dockerfile' );
		$entry = file_get_contents( $root . 'entrypoint.sh' );
		self::assertStringContainsString( 'COPY scripts/compatibility-download.sh', $dockerfile );
		self::assertStringContainsString( 'docker-php-ext-install mysqli', $dockerfile );
		self::assertSame( 2, substr_count( $dockerfile, 'sha256sum --check --strict' ) );
		self::assertStringContainsString( 'exec /usr/local/bin/php -S', $entry );
		self::assertStringContainsString( 'test ! -e /var/www/html/wp-config.php', $entry );
		self::assertStringContainsString( 'test ! -L /var/www/html/wp-config.php', $entry );
		self::assertStringNotContainsString( 'openssl', $entry );
		self::assertStringNotContainsString( 'wordpress:6.9-php8.0', $dockerfile );
		self::assertStringContainsString( '127.0.0.1:', file_get_contents( $root . 'compose.yml' ) );
		self::assertSame( 2, substr_count( file_get_contents( $root . 'compose.yml' ), 'create_host_path: false' ) );
	}
}
