<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/qa-runtime.php';

final class QaRuntimeSelectorTest extends TestCase {
	private function config(): array {
		return array(
			'profile' => 'php80-floor', 'php_base_image' => 'php:8.0.30-cli@sha256:' . str_repeat( 'a', 64 ),
			'php_binary_sha256' => str_repeat( 'b', 64 ), 'wordpress_version' => '6.9',
			'wordpress_archive_sha256' => str_repeat( 'c', 64 ), 'mysql_image' => 'mysql:8.0.36@sha256:' . str_repeat( 'd', 64 ),
			'runtime_image' => 'sha256:' . str_repeat( '3', 64 ), 'candidate_sha' => str_repeat( 'e', 40 ), 'candidate_tree' => str_repeat( 'f', 40 ),
			'candidate_root' => '/owned/candidate', 'project' => 'wstm-php80-owned', 'http_port' => 18080,
			'artifact_directory' => 'owned_floor_evidence', 'wp_config' => '/private/wp-config.php',
			'wp_config_sha256' => str_repeat( '1', 64 ), 'mysql_env_file' => '/private/mysql.env',
			'mysql_env_sha256' => str_repeat( '2', 64 ), 'dependency_policy' => 'pinned',
		);
	}

	private function environment(): array {
		return array(
			WstmQaRuntime::PROFILE => 'php80-floor', WstmQaRuntime::CONFIG => '/private/floor.json',
			WstmQaRuntime::HASH => str_repeat( '9', 64 ), 'COMPOSE_PROJECT_NAME' => 'wstm-php80-owned',
			'E2E_ARTIFACTS_DIR' => 'owned_floor_evidence', 'DEPENDENCY_POLICY' => 'pinned',
		);
	}

	private function plan(): array {
		return WstmQaRuntime::plan( $this->environment(), '/owned/candidate', $this->config() );
	}

	public function testSingleFloorSelectionReusesClosedConfigAndExactPlanner(): void {
		$plan = $this->plan();
		$fixture = wstm_php80_floor_plan( $this->config() );
		self::assertSame( $fixture['compose_argv'], $plan['compose_argv'] );
		self::assertSame( $fixture['environment'], $plan['environment'] );
		self::assertArrayNotHasKey( 'admitted', $plan );
		self::assertArrayNotHasKey( 'authority', $plan );
		self::assertArrayNotHasKey( 'fixture_grant', $plan );
		self::assertSame( '0', $plan['environment']['E2E_MANAGE_COMPOSE'] );
		self::assertSame( 'http://127.0.0.1:18080', $plan['environment']['WORDPRESS_URL'] );
	}

	public function testDefaultAndOriginalZipSelectionRemainUnchanged(): void {
		self::assertSame( array( 'docker', 'compose' ), WstmQaRuntime::plan( array(), '/source' )['compose_argv'] );
		self::assertSame( array( 'docker', 'compose', '--project-name', 'owned' ), WstmQaRuntime::plan( array( 'COMPOSE_PROJECT_NAME' => 'owned', 'WORDPRESS_IMAGE' => 'wordpress:6.9-php8.1-apache' ), '/source' )['compose_argv'] );
		$environment = array( 'COMPOSE_PROJECT_NAME' => 'owned', 'E2E_PACKAGE_ROOT' => '/original', 'E2E_PACKAGE_ZIP' => '/original.zip', 'COMPOSE_FILE' => 'ambient-not-selected' );
		self::assertSame( array( 'docker', 'compose', '--project-name', 'owned', '-f', 'docker-compose.yml', '-f', 'docker-compose.release.yml' ), WstmQaRuntime::plan( $environment, '/source' )['compose_argv'] );
		self::assertSame( array(), WstmQaRuntime::plan( $environment, '/source' )['environment'] );
	}

	/** @dataProvider environment_drift */
	public function testMissingIdentityAndConflictingProfilesFailClosed( string $key, mixed $value ): void {
		$environment = $this->environment();
		if ( null === $value ) { unset( $environment[ $key ] ); } else { $environment[ $key ] = $value; }
		$this->expectException( RuntimeException::class );
		WstmQaRuntime::plan( $environment, '/owned/candidate', $this->config() );
	}

	public static function environment_drift(): array {
		return array(
			array( WstmQaRuntime::PROFILE, null ), array( WstmQaRuntime::PROFILE, 'php81-compatibility' ),
			array( WstmQaRuntime::CONFIG, null ), array( WstmQaRuntime::CONFIG, '' ),
			array( WstmQaRuntime::HASH, null ), array( WstmQaRuntime::HASH, 'not-original' ),
			array( 'COMPOSE_FILE', 'foreign.yml' ), array( 'COMPOSE_PROFILES', 'foreign' ), array( 'COMPOSE_ENV_FILES', '/foreign' ),
			array( 'E2E_PACKAGE_ROOT', '/package' ), array( 'E2E_PACKAGE_ZIP', '/package.zip' ),
			array( 'WORDPRESS_IMAGE', 'wordpress:current' ), array( 'MYSQL_IMAGE', 'mysql:current' ),
			array( 'WORDPRESS_PORT', '80' ), array( 'MYSQL_PORT', '3306' ),
			array( 'COMPOSE_PROJECT_NAME', 'foreign' ), array( 'E2E_ARTIFACTS_DIR', 'foreign' ),
			array( 'DEPENDENCY_POLICY', 'latest-seo' ), array( 'PHP80_RUNTIME_IMAGE', 'mutable:tag' ),
			array( 'PHP80_CANDIDATE_ROOT', '/foreign' ), array( 'PHP80_MYSQL_ENV_FILE', '/foreign.env' ),
			array( 'PHP80_WP_CONFIG_SHA256', str_repeat( '0', 64 ) ), array( 'E2E_MANAGE_COMPOSE', '1' ),
			array( 'WORDPRESS_URL', 'http://foreign' ),
		);
	}

	public function testSourceRootAndUnbuiltImageAreNotSelectable(): void {
		foreach ( array( 'candidate_root' => '/foreign', 'runtime_image' => null, 'extra' => true ) as $key => $value ) {
			$config = $this->config(); $config[ $key ] = $value;
			try {
				WstmQaRuntime::plan( $this->environment(), '/owned/candidate', $config );
				self::fail( 'Invalid selection accepted.' );
			} catch ( RuntimeException $error ) { self::assertNotSame( '', $error->getMessage() ); }
		}
	}

	public function testOriginalRetentionCannotBeDowngradedOrRebound(): void {
		$config = $this->config(); $environment = $this->environment(); $owner = str_repeat( '7', 32 );
		$binding = array( 'source_sha' => $config['candidate_sha'], 'tree_sha' => $config['candidate_tree'], 'project' => $config['project'], 'package_sha256' => null );
		WstmQaRuntime::assert_binding( $this->plan(), $binding );
		$bytes = WstmQaRuntime::retention_bytes( $this->plan(), $environment, $owner, $config['project'], $config['candidate_sha'] );
		self::assertStringContainsString( 'config_sha256=' . $environment[ WstmQaRuntime::HASH ], $bytes );
		self::assertStringContainsString( 'runtime_image=' . $config['runtime_image'], $bytes );
		self::assertStringContainsString( 'source_tree=' . $config['candidate_tree'], $bytes );
		$default = WstmQaRuntime::plan( array(), '/source' );
		self::assertSame( "owner={$owner}\nproject={$config['project']}\nsource={$config['candidate_sha']}\n", WstmQaRuntime::retention_bytes( $default, array(), $owner, $config['project'], $config['candidate_sha'] ) );
		self::assertNotSame( $bytes, WstmQaRuntime::retention_bytes( $default, array(), $owner, $config['project'], $config['candidate_sha'] ) );
		foreach ( array( 'source_sha', 'tree_sha', 'project', 'package_sha256' ) as $key ) {
			$bad = $binding; $bad[ $key ] = 'foreign';
			try { WstmQaRuntime::assert_binding( $this->plan(), $bad ); self::fail( 'Foreign binding accepted.' ); }
			catch ( RuntimeException $error ) { self::assertStringContainsString( 'binding differs', $error->getMessage() ); }
		}
	}

	private function mounts( bool $live ): array {
		$c = $this->config();
		return $live ? array(
			array( 'Type' => 'bind', 'Source' => $c['candidate_root'], 'Destination' => '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp', 'RW' => true ),
			array( 'Type' => 'bind', 'Source' => $c['wp_config'], 'Destination' => '/run/php80-floor/wp-config.php', 'RW' => false ),
		) : array(
			array( 'type' => 'bind', 'source' => $c['candidate_root'], 'target' => '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp', 'bind' => array( 'create_host_path' => false ) ),
			array( 'type' => 'bind', 'source' => $c['wp_config'], 'target' => '/run/php80-floor/wp-config.php', 'read_only' => true, 'bind' => array( 'create_host_path' => false ) ),
		);
	}

	public function testPlannedImageAndBindDriftIsRejectedWithoutAdmissionReceipts(): void {
		$c = $this->config();
		$projection = array( 'name' => $c['project'], 'services' => array(
			'mysql' => array( 'image' => $c['mysql_image'] ),
			'wordpress' => array( 'image' => $c['runtime_image'], 'volumes' => $this->mounts( false ) ),
		) );
		$args = array_merge( $this->plan()['compose_argv'], array( 'config', '--format', 'json', '--no-env-resolution' ) );
		$seen = array(); $query = WstmQaRuntime::projected_query( $this->plan(), static fn( $argv ) => $projection, $seen );
		self::assertSame( $projection, $query( $args ) );
		$variants = array();
		$bad = $projection; $bad['services']['wordpress']['image'] = 'mutable:tag'; $variants[] = $bad;
		$bad = $projection; $bad['services']['wordpress']['volumes'][0]['source'] = '/foreign'; $variants[] = $bad;
		$bad = $projection; $bad['services']['wordpress']['volumes'][1]['read_only'] = false; $variants[] = $bad;
		$bad = $projection; $bad['services']['wordpress']['volumes'][0]['bind']['create_host_path'] = true; $variants[] = $bad;
		$bad = $projection; $bad['services']['foreign'] = array(); $variants[] = $bad;
		foreach ( $variants as $bad ) {
			$seen = array(); $query = WstmQaRuntime::projected_query( $this->plan(), static fn( $argv ) => $bad, $seen );
			try { $query( $args ); self::fail( 'Foreign plan accepted.' ); }
			catch ( RuntimeException $error ) { self::assertNotSame( '', $error->getMessage() ); }
		}
	}

	public function testLiveImageOwnerServiceOneoffAndBindDriftAreRejected(): void {
		$c = $this->config();
		$projection = array( 'id' => str_repeat( 'a', 64 ), 'project' => $c['project'], 'mounts' => $this->mounts( true ),
			'service' => 'wordpress', 'oneoff' => 'False', 'image' => $c['runtime_image'], 'configured_image' => $c['runtime_image'] );
		$args = array( '/usr/bin/docker', '--host', 'unix:///run/docker.sock', 'inspect', '--format', '{{json .Mounts}}', str_repeat( 'a', 64 ) );
		$seen = array(); $query = WstmQaRuntime::projected_query( $this->plan(), static fn( $argv ) => $projection, $seen );
		self::assertSame( $projection, $query( $args ) );
		self::assertSame( array( $args[6], $c['runtime_image'] ), $seen['wordpress'] );
		$short = $args; $short[6] = substr( $args[6], 0, 12 );
		self::assertSame( $projection, $query( $short ) );
		self::assertSame( array( $args[6], $c['runtime_image'] ), $seen['wordpress'] );
		foreach ( array( 'id' => str_repeat( 'b', 64 ), 'project' => 'foreign', 'service' => 'foreign', 'oneoff' => 'True', 'image' => 'sha256:' . str_repeat( '0', 64 ), 'configured_image' => 'mutable:tag', 'mounts' => array() ) as $key => $value ) {
			$bad = $projection; $bad[ $key ] = $value; $seen = array();
			$query = WstmQaRuntime::projected_query( $this->plan(), static fn( $argv ) => $bad, $seen );
			try { $query( $args ); self::fail( 'Foreign live runtime accepted.' ); }
			catch ( RuntimeException $error ) { self::assertNotSame( '', $error->getMessage() ); }
		}
		$args[6] = str_repeat( 'b', 64 );
		$seen = array( 'wordpress' => array( str_repeat( 'a', 64 ), $c['runtime_image'] ) );
		$projection['id'] = $args[6];
		$query = WstmQaRuntime::projected_query( $this->plan(), static fn( $argv ) => $projection, $seen );
		$this->expectException( RuntimeException::class ); $query( $args );
	}

	public function testActualMysqlImageMustResolveToOriginalDigest(): void {
		$c = $this->config(); $id = 'sha256:' . str_repeat( '8', 64 );
		$projection = array( 'id' => str_repeat( 'a', 64 ), 'project' => $c['project'], 'mounts' => array(), 'service' => 'mysql', 'oneoff' => 'False', 'image' => $id, 'configured_image' => $c['mysql_image'] );
		$args = array( '/usr/bin/docker', '--host', 'unix:///run/docker.sock', 'inspect', '--format', '{{json .Mounts}}', str_repeat( 'a', 64 ) );
		foreach ( array( true, false ) as $correct ) {
			$seen = array();
			$query = WstmQaRuntime::projected_query( $this->plan(), static function ( $argv ) use ( $correct, $projection, $id, $c ) {
				if ( in_array( 'image', $argv, true ) ) {
					return array( 'id' => $id, 'digests' => array( $correct ? 'mysql@' . substr( $c['mysql_image'], strpos( $c['mysql_image'], '@' ) + 1 ) : 'foreign@sha256:' . str_repeat( 'd', 64 ) ) );
				}
				return $projection;
			}, $seen );
			if ( $correct ) { self::assertSame( $projection, $query( $args ) ); }
			else { try { $query( $args ); self::fail( 'Foreign digest accepted.' ); } catch ( RuntimeException $error ) { self::assertStringContainsString( 'selected digest', $error->getMessage() ); } }
		}
	}

	public function testOriginalPrivateConfigBytesCannotBeReplacedOrReusedWithWrongPin(): void {
		if ( 'Linux' !== PHP_OS_FAMILY ) { self::markTestSkipped( 'Native owner/mode test; no runtime is started.' ); }
		$directory = sys_get_temp_dir() . '/wstm-selector-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $directory, 0700 ) );
		$path = $directory . '/floor.json';
		try {
			$bytes = json_encode( $this->config(), JSON_THROW_ON_ERROR );
			self::assertSame( strlen( $bytes ), file_put_contents( $path, $bytes ) ); chmod( $path, 0600 );
			$e = $this->environment(); $e[ WstmQaRuntime::CONFIG ] = $path; $e[ WstmQaRuntime::HASH ] = hash( 'sha256', $bytes );
			self::assertSame( $this->config(), WstmQaRuntime::read_config( $e ) );
			file_put_contents( $path, $bytes . ' ' );
			$this->expectException( RuntimeException::class ); WstmQaRuntime::read_config( $e );
		} finally { unlink( $path ); rmdir( $directory ); }
	}
}
