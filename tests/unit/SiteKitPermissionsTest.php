<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/site-kit-stubs.php';
require_once dirname(__DIR__, 2) . '/includes/class-site-kit.php';

final class SiteKitPermissionsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm_test_user_caps'] = array( 'read' );
		$GLOBALS['wstm125'] = array(
			'active' => true,
			'version' => '1.183.0',
			'mode' => 'allow',
			'calls' => array( 'plugin' => 0, 'routes' => 0, 'permission' => 0, 'data' => 0 ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm125'], $GLOBALS['wstm_test_user_caps'] );
	}

	public static function delegated_methods(): array {
		return array( array( 'modules' ), array( 'permissions' ), array( 'pagespeed' ) );
	}

	private function invoke( string $prefix, string $method ) {
		return call_user_func(
			array( Webmastery_MCP_Site_Kit::class, "{$prefix}_{$method}" ),
			array( 'url' => 'http://localhost/sample-page/', 'strategy' => 'mobile' )
		);
	}

	public static function delegated_entry_points(): array {
		$cases = array();
		foreach ( self::delegated_methods() as [ $method ] ) {
			foreach ( array( 'permission', 'execute' ) as $prefix ) {
				$cases[] = array( $method, $prefix );
			}
		}
		return $cases;
	}

	/** @dataProvider delegated_entry_points */
	public function test_no_read_denied_before_any_provider_work( string $method, string $prefix ): void {
		$GLOBALS['wstm_test_user_caps'] = array( 'wstm125_shared_dashboard' );
		$result = $this->invoke( $prefix, $method );
		$this->assertInstanceOf( WP_Error::class, $result, $prefix );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 'Requires read capability.', $result->get_error_message() );
		$this->assertSame( array( 'plugin' => 0, 'routes' => 0, 'permission' => 0, 'data' => 0 ), $GLOBALS['wstm125']['calls'] );
	}

	/** @dataProvider delegated_methods */
	public function test_read_only_user_can_use_allowed_upstream( string $method ): void {
		$this->assertTrue( $this->invoke( 'permission', $method ) );
		$result = $this->invoke( 'execute', $method );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'pagespeed' === $method ? 3 : 2, $GLOBALS['wstm125']['calls']['permission'] );
		$this->assertSame( 'pagespeed' === $method ? 2 : 1, $GLOBALS['wstm125']['calls']['data'] );
	}

	/** @dataProvider delegated_methods */
	public function test_upstream_denials_remain_required_in_both_paths( string $method ): void {
		foreach ( array( 'false', 'null', 'error' ) as $mode ) {
			$GLOBALS['wstm125']['mode'] = $mode;
			foreach ( array( 'permission', 'execute' ) as $prefix ) {
				$result = $this->invoke( $prefix, $method );
				$this->assertInstanceOf( WP_Error::class, $result, "{$prefix}: {$mode}" );
				$this->assertSame( 'forbidden', $result->get_error_code() );
				$this->assertSame( 0, $GLOBALS['wstm125']['calls']['data'] );
			}
		}
	}

	/** @dataProvider delegated_methods */
	public function test_missing_upstream_contract_fails_closed( string $method ): void {
		foreach ( array( 'missing-route', 'missing-callback', 'uncallable-callback' ) as $mode ) {
			$GLOBALS['wstm125']['mode'] = $mode;
			foreach ( array( 'permission', 'execute' ) as $prefix ) {
				$result = $this->invoke( $prefix, $method );
				$this->assertInstanceOf( WP_Error::class, $result, "{$prefix}: {$mode}" );
				$this->assertSame( 'site_kit_unsupported', $result->get_error_code() );
				$this->assertSame( 0, $GLOBALS['wstm125']['calls']['data'] );
			}
		}
		$GLOBALS['wstm125']['active'] = false;
		foreach ( array( 'permission', 'execute' ) as $prefix ) {
			$result = $this->invoke( $prefix, $method );
			$this->assertSame( 'site_kit_unavailable', $result->get_error_code() );
		}
	}

	public function test_status_manage_options_does_not_gain_read_floor(): void {
		$GLOBALS['wstm_test_user_caps'] = array( 'manage_options' );
		$this->assertTrue( Webmastery_MCP_Site_Kit::permission_status() );
		$this->assertTrue( Webmastery_MCP_Site_Kit::execute_status()['success'] );
		$GLOBALS['wstm125']['active'] = false;
		$this->assertTrue( Webmastery_MCP_Site_Kit::permission_status() );
		$this->assertFalse( Webmastery_MCP_Site_Kit::execute_status()['data']['plugin']['active'] );
		$GLOBALS['wstm_test_user_caps'] = array( 'read' );
		$this->assertSame( 'forbidden', Webmastery_MCP_Site_Kit::permission_status()->get_error_code() );
	}

	public function test_version_diagnostics_are_preserved(): void {
		$GLOBALS['wstm125']['mode'] = 'missing-route';
		$GLOBALS['wstm125']['version'] = '1.81.0';
		$result = Webmastery_MCP_Site_Kit::permission_permissions();
		$this->assertSame( '1.82.0', $result->get_error_data()['minimum_version'] );
		$GLOBALS['wstm125']['version'] = '';
		$result = Webmastery_MCP_Site_Kit::permission_permissions();
		$this->assertSame( array( 'detected_version' => null ), $result->get_error_data() );
	}

	public function test_pagespeed_validation_precedes_dispatch_for_read_users(): void {
		foreach ( array(
			array( 'strategy' => 'tablet' ),
			array( 'strategy' => 'mobile', 'url' => 'https://example.com/' ),
			array( 'strategy' => 'mobile', 'url' => 'http://localhost:81/' ),
			array( 'strategy' => 'mobile', 'url' => 'http://user:pass@localhost/' ),
			array( 'strategy' => 'mobile', 'url' => 'http://localhost/#fragment' ),
		) as $input ) {
			$this->assertInstanceOf( WP_Error::class, Webmastery_MCP_Site_Kit::execute_pagespeed( $input ) );
			$this->assertSame( 0, $GLOBALS['wstm125']['calls']['routes'] );
		}
	}

	public static function pagespeed_defaults(): array {
		return array(
			array( array(), 'http://localhost/', 'mobile' ),
			array( array( 'url' => 'http://localhost/sample-page/' ), 'http://localhost/sample-page/', 'mobile' ),
			array( array( 'strategy' => 'mobile' ), 'http://localhost/', 'mobile' ),
			array( array( 'strategy' => 'desktop' ), 'http://localhost/', 'desktop' ),
		);
	}

	/** @dataProvider pagespeed_defaults */
	public function test_pagespeed_defaults_reach_permission_and_dispatch( array $input, string $url, string $strategy ): void {
		$this->assertTrue( Webmastery_MCP_Site_Kit::permission_pagespeed( $input ) );
		$result = Webmastery_MCP_Site_Kit::execute_pagespeed( $input );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $strategy, $result['data']['strategy'] );
		$this->assertSame( $url, $result['data']['requested_url'] );
		$expected = array( '/google-site-kit/v1/modules/pagespeed-insights/data/pagespeed', array( 'url' => $url, 'strategy' => $strategy ) );
		$this->assertSame( $expected, $GLOBALS['wstm125']['permission_requests'][0] );
		$this->assertSame( $expected, $GLOBALS['wstm125']['permission_requests'][2] );
		$this->assertSame( $expected, $GLOBALS['wstm125']['data_requests'][1] );
	}

	public function test_pagespeed_schema_advertises_optional_mobile_default(): void {
		Webmastery_MCP_Site_Kit::register();
		$args = $GLOBALS['wstm_test_abilities']['webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed'];
		$schema = $args['input_schema'];
		$this->assertSame( array(), $schema['default'] );
		$this->assertNotContains( 'strategy', $schema['required'] ?? array() );
		$this->assertSame( 'mobile', $schema['properties']['strategy']['default'] );
		$this->assertSame( array( 'mobile', 'desktop' ), $schema['properties']['strategy']['enum'] );
		$this->assertNull( Webmastery_MCP_Input::validate( array(), $schema ) );
		$this->assertNull( Webmastery_MCP_Input::validate( array( 'url' => 'http://localhost/' ), $schema ) );
		foreach ( array( null, false, 1, array( 'mobile' ), 'both', '', 'Mobile', 'mobile!' ) as $strategy ) {
			$this->assertInstanceOf( WP_Error::class, Webmastery_MCP_Input::validate( array( 'strategy' => $strategy ), $schema ) );
			foreach ( array( 'permission_pagespeed', 'execute_pagespeed' ) as $method ) {
				$result = Webmastery_MCP_Site_Kit::$method( array( 'strategy' => $strategy ) );
				$this->assertSame( 'invalid_strategy', $result->get_error_code() );
			}
		}
		$this->assertSame( 0, $GLOBALS['wstm125']['calls']['routes'] );
		unset( $GLOBALS['wstm_test_abilities'] );
	}

	public function test_omitted_strategy_preserves_local_and_upstream_denials(): void {
		foreach ( array( 'permission_pagespeed', 'execute_pagespeed' ) as $method ) {
			$GLOBALS['wstm_test_user_caps'] = array();
			$this->assertSame( 'forbidden', Webmastery_MCP_Site_Kit::$method()->get_error_code() );
			$this->assertSame( 0, $GLOBALS['wstm125']['calls']['routes'] );
		}
		$GLOBALS['wstm_test_user_caps'] = array( 'read' );
		foreach ( array( 'false', 'null', 'error', 'missing-route', 'missing-callback', 'uncallable-callback' ) as $mode ) {
			$GLOBALS['wstm125']['mode'] = $mode;
			foreach ( array( 'permission_pagespeed', 'execute_pagespeed' ) as $method ) {
				$result = Webmastery_MCP_Site_Kit::$method();
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame( 0, $GLOBALS['wstm125']['calls']['data'] );
			}
		}
	}

	public function test_omitted_strategy_does_not_bypass_either_exact_upstream_route(): void {
		$GLOBALS['wstm125']['denied_path'] = '/google-site-kit/v1/modules/pagespeed-insights/data/pagespeed';
		$this->assertSame( 'forbidden', Webmastery_MCP_Site_Kit::permission_pagespeed()->get_error_code() );
		$this->assertSame( 'forbidden', Webmastery_MCP_Site_Kit::execute_pagespeed()->get_error_code() );
		$this->assertCount( 1, $GLOBALS['wstm125']['data_requests'] );
		$this->assertSame( '/google-site-kit/v1/core/modules/data/list', $GLOBALS['wstm125']['data_requests'][0][0] );
		$GLOBALS['wstm125']['denied_path'] = '/google-site-kit/v1/core/modules/data/list';
		$this->assertSame( 'forbidden', Webmastery_MCP_Site_Kit::execute_pagespeed()->get_error_code() );
		$this->assertCount( 1, $GLOBALS['wstm125']['data_requests'] );
	}
}
