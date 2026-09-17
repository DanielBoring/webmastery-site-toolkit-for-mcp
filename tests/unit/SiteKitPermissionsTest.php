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
}
