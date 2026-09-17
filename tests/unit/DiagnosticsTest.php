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
	return false;
}

function get_bloginfo( $show ) {
	return 'test';
}

function apply_filters( $name, $value ) {
	return $value;
}

final class DiagnosticsTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wstm111_home'], $GLOBALS['wpdb'], $GLOBALS['wstm_test_user_caps'] );
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
}
