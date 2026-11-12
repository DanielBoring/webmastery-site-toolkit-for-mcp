<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-security.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-database-health.php';

function get_option( $name ) {
	return $GLOBALS['wstm111_home'];
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

function get_user_by( $field, $value ) {
	return false;
}

function get_site_transient( $name ) {
	return $GLOBALS['wstm_test_site_transients'][ $name ] ?? false;
}

function get_bloginfo( $show ) {
	return 'test';
}

function apply_filters( $name, $value ) {
	return $value;
}

final class DiagnosticsTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wstm111_home'], $GLOBALS['wpdb'], $GLOBALS['wstm_test_user_caps'], $GLOBALS['wstm_test_site_transients'] );
		parent::tearDown();
	}

	public static function home_cases(): array {
		return array(
			'https' => array( 'https://example.test', 'pass' ),
			'http' => array( 'http://example.test', 'fail' ),
			'upper' => array( 'HTTPS://example.test', 'pass' ),
			'mixed' => array( 'HtTp://example.test', 'fail' ),
			'credentials' => array( 'https://private:secret@example.test', 'pass' ),
			'empty' => array( '', 'warn' ),
			'null' => array( null, 'warn' ),
			'false' => array( false, 'warn' ),
			'array' => array( array( 'https://example.test' ), 'warn' ),
			'object' => array( (object) array( 'scheme' => 'https' ), 'warn' ),
			'integer' => array( 443, 'warn' ),
			'relative' => array( '/site', 'warn' ),
			'protocol relative' => array( '//example.test', 'warn' ),
			'no host' => array( 'https:/site', 'warn' ),
			'invalid port' => array( 'https://example.test:99999', 'warn' ),
			'host space' => array( 'https://bad host.test', 'warn' ),
			'control character' => array( "https://example.test\n", 'warn' ),
			'unknown scheme' => array( 'ftp://example.test', 'warn' ),
		);
	}

	/** @dataProvider home_cases */
	public function test_configured_home_scheme_without_request_or_admin_policy( $home, string $bucket ): void {
		$GLOBALS['wstm111_home'] = $home;
		$result = Webmastery_MCP_Security::execute();
		$matches = array();
		foreach ( array( 'pass', 'warn', 'fail' ) as $severity ) {
			foreach ( $result['data'][ $severity ] as $finding ) {
				if ( 'ssl' === $finding['check'] ) {
					$matches[ $severity ] = $finding;
				}
			}
			$this->assertSame( count( $result['data'][ $severity ] ), $result['data']['summary'][ $severity ] );
		}
		$this->assertSame( array( $bucket ), array_keys( $matches ) );
		$this->assertStringNotContainsString( 'example.test', json_encode( $matches ) );
		$this->assertStringNotContainsString( 'secret', json_encode( $matches ) );
	}

	public static function authority_cases(): array {
		$cases = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/diagnostics-authority.json' ), true, 512, JSON_THROW_ON_ERROR );
		$result = array();
		foreach ( $cases as $case ) {
			$result[ $case['id'] ] = array( $case['home'], $case['bucket'] );
		}
		return $result;
	}

	/** @dataProvider authority_cases */
	public function test_configured_home_authority_syntax( string $home, string $bucket ): void {
		$this->test_configured_home_scheme_without_request_or_admin_policy( $home, $bucket );
	}

	public function test_database_errors_retain_each_context_without_raw_details(): void {
		$GLOBALS['wpdb'] = (object) array( 'last_error' => 'SELECT private_prefix_table /srv/private RAW_SENTINEL' );
		$method = new ReflectionMethod( Webmastery_MCP_Database_Health::class, 'database_error' );
		$method->setAccessible( true );
		foreach ( array( 'post revisions', 'post revision size estimate', 'orphaned post meta', 'expired transients', 'autoloaded options', 'table sizes' ) as $context ) {
			$error = $method->invoke( null, $context );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( 'database_health_query_failed', $error->get_error_code() );
			$this->assertSame( "Database health query failed while reading {$context}.", $error->get_error_message() );
			$this->assertSame( 'SELECT private_prefix_table /srv/private RAW_SENTINEL', $GLOBALS['wpdb']->last_error );
		}
	}

	public function test_database_permission_is_unchanged(): void {
		$GLOBALS['wstm_test_user_caps'] = array();
		$this->assertSame( 'forbidden', Webmastery_MCP_Database_Health::permission()->get_error_code() );
		$GLOBALS['wstm_test_user_caps'] = array( 'manage_options' );
		$this->assertTrue( Webmastery_MCP_Database_Health::permission() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_default_debug_log_omits_absolute_path(): void {
		define( 'WP_DEBUG_LOG', true );
		define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wstm111-private-nonexistent' );
		$GLOBALS['wstm111_home'] = 'https://example.test';
		$response = Webmastery_MCP_Security::execute();
		$findings = array_values( array_filter( $response['data']['warn'], static fn( $finding ) => 'debug_log' === $finding['check'] ) );
		$this->assertCount( 1, $findings );
		$this->assertStringNotContainsString( WP_CONTENT_DIR, json_encode( $findings, JSON_UNESCAPED_SLASHES ) );
		$this->assertStringContainsString( 'Public access has not been tested.', $findings[0]['detail'] );
	}

	private static function findings( array $result, string $check ): array {
		$matches = array();
		foreach ( array( 'pass', 'warn', 'fail' ) as $severity ) {
			foreach ( $result['data'][ $severity ] as $finding ) {
				if ( $check === $finding['check'] ) {
					$matches[] = array( $severity, $finding );
				}
			}
		}
		return $matches;
	}

	public static function core_update_cases(): array {
		$latest  = (object) array( 'response' => 'latest', 'version' => '6.9' );
		$upgrade = (object) array( 'response' => 'upgrade', 'version' => '7.0' );
		return array(
			'missing transient' => array( false, 'warn', 'WordPress core update status unknown' ),
			'null transient' => array( null, 'warn', 'WordPress core update status unknown' ),
			'array transient' => array( array( 'updates' => array( $latest ) ), 'warn', 'WordPress core update status unknown' ),
			'no updates property' => array( (object) array( 'last_checked' => 1 ), 'warn', 'WordPress core update status unknown' ),
			'empty updates' => array( (object) array( 'updates' => array() ), 'warn', 'WordPress core update status unknown' ),
			'updates not array' => array( (object) array( 'updates' => 'latest' ), 'warn', 'WordPress core update status unknown' ),
			'only malformed entries' => array( (object) array( 'updates' => array( 'upgrade', array( 'response' => 'upgrade' ), (object) array( 'version' => '7.0' ), (object) array( 'response' => array( 'upgrade' ) ) ) ), 'warn', 'WordPress core update status unknown' ),
			'latest entry' => array( (object) array( 'updates' => array( $latest ) ), 'pass', 'WordPress core is up to date' ),
			'malformed then latest' => array( (object) array( 'updates' => array( null, $latest ) ), 'pass', 'WordPress core is up to date' ),
			'development entry' => array( (object) array( 'updates' => array( (object) array( 'response' => 'development' ) ) ), 'pass', 'WordPress core is up to date' ),
			'upgrade entry' => array( (object) array( 'updates' => array( $upgrade ) ), 'warn', 'WordPress core update available', 'Latest: 7.0' ),
			'latest then upgrade' => array( (object) array( 'updates' => array( $latest, $upgrade ) ), 'warn', 'WordPress core update available', 'Latest: 7.0' ),
			'upgrade without version' => array( (object) array( 'updates' => array( (object) array( 'response' => 'upgrade' ) ) ), 'warn', 'WordPress core update available', 'Latest: unknown' ),
		);
	}

	/** @dataProvider core_update_cases */
	public function test_core_version_emits_exactly_one_evidence_based_finding( $transient, string $bucket, string $label, string $latest = '' ): void {
		$GLOBALS['wstm111_home'] = 'https://example.test';
		$GLOBALS['wstm_test_site_transients'] = array( 'update_core' => $transient );
		$matches = self::findings( Webmastery_MCP_Security::execute(), 'wp_version' );
		$this->assertCount( 1, $matches );
		[ $severity, $finding ] = $matches[0];
		$this->assertSame( $bucket, $severity );
		$this->assertSame( $label, $finding['label'] );
		if ( '' !== $latest ) {
			$this->assertStringEndsWith( $latest, $finding['detail'] );
		}
		if ( 'WordPress core update status unknown' === $label ) {
			$this->assertStringContainsString( 'Cached update data is unavailable.', $finding['detail'] );
		}
	}

	public function test_undefined_salts_warn_with_all_eight_names(): void {
		$GLOBALS['wstm111_home'] = 'https://example.test';
		$matches = self::findings( Webmastery_MCP_Security::execute(), 'auth_keys' );
		$this->assertCount( 1, $matches );
		$this->assertSame( 'warn', $matches[0][0] );
		$this->assertSame( 'Auth keys/salts missing or empty in wp-config.php: AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY, AUTH_SALT, SECURE_AUTH_SALT, LOGGED_IN_SALT, NONCE_SALT', $matches[0][1]['label'] );
		$this->assertStringContainsString( 'falls back to values stored in the database', $matches[0][1]['detail'] );
	}

	private static function define_salts( array $values ): void {
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $name ) {
			if ( array_key_exists( $name, $values ) ) {
				if ( null !== $values[ $name ] ) {
					define( $name, $values[ $name ] );
				}
				continue;
			}
			define( $name, 'SALT_SENTINEL_' . $name . '_x9!Q' );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_all_eight_unique_salts_pass(): void {
		self::define_salts( array() );
		$GLOBALS['wstm111_home'] = 'https://example.test';
		$result = Webmastery_MCP_Security::execute();
		$matches = self::findings( $result, 'auth_keys' );
		$this->assertCount( 1, $matches );
		$this->assertSame( array( 'pass', array( 'check' => 'auth_keys', 'label' => 'Auth keys and salts are set' ) ), $matches[0] );
		$this->assertStringNotContainsString( 'SALT_SENTINEL', json_encode( $result ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_placeholder_in_salt_constant_fails(): void {
		self::define_salts( array( 'NONCE_SALT' => 'put your unique phrase here' ) );
		$GLOBALS['wstm111_home'] = 'https://example.test';
		$result = Webmastery_MCP_Security::execute();
		$matches = self::findings( $result, 'auth_keys' );
		$this->assertCount( 1, $matches );
		$this->assertSame( 'fail', $matches[0][0] );
		$this->assertSame( 'Default auth keys/salts detected: NONCE_SALT', $matches[0][1]['label'] );
		$this->assertStringNotContainsString( 'SALT_SENTINEL', json_encode( $result ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_empty_or_undefined_salts_warn(): void {
		self::define_salts( array( 'LOGGED_IN_SALT' => '', 'SECURE_AUTH_SALT' => null ) );
		$GLOBALS['wstm111_home'] = 'https://example.test';
		$result = Webmastery_MCP_Security::execute();
		$matches = self::findings( $result, 'auth_keys' );
		$this->assertCount( 1, $matches );
		$this->assertSame( 'warn', $matches[0][0] );
		$this->assertSame( 'Auth keys/salts missing or empty in wp-config.php: SECURE_AUTH_SALT, LOGGED_IN_SALT', $matches[0][1]['label'] );
		$this->assertStringNotContainsString( 'SALT_SENTINEL', json_encode( $result ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_placeholder_takes_precedence_over_missing_salts(): void {
		self::define_salts( array( 'AUTH_KEY' => 'put your unique phrase here', 'NONCE_SALT' => '' ) );
		$GLOBALS['wstm111_home'] = 'https://example.test';
		$result = Webmastery_MCP_Security::execute();
		$matches = self::findings( $result, 'auth_keys' );
		$this->assertCount( 1, $matches );
		$this->assertSame( 'fail', $matches[0][0] );
		$this->assertSame( 'Default auth keys/salts detected: AUTH_KEY', $matches[0][1]['label'] );
		$this->assertStringStartsWith( 'Missing or empty in wp-config.php: NONCE_SALT.', $matches[0][1]['detail'] );
		$this->assertStringNotContainsString( 'SALT_SENTINEL', json_encode( $result ) );
	}
}
