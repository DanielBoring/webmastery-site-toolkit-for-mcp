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
			'dispatch_paths' => array(),
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
					$this->assert_guidance( Webmastery_MCP_Response::from_wp_error( $result ) );
			}
		}
	}

	private function assert_guidance( array $result ): void {
		$this->assertSame( 'forbidden', $result['error']['code'] );
		$this->assertSame( 'forbidden', $result['error']['reason'] );
		$this->assertSame( '{}', json_encode( $result['error']['details'] ) );
		$message = $result['error']['message'];
		foreach ( array( 'Possible causes', 'Dashboard sharing', 'sign-in/reauthentication', 'sharing icon', 'dashboard header', 'get-site-kit-status', 'requires manage_options', 'get-site-kit-permissions', 'where permitted' ) as $text ) {
			$this->assertStringContainsString( $text, $message );
		}
		$this->assertStringNotContainsString( 'PRIVATE', json_encode( $result ) );
		$this->assertLessThan( 600, strlen( $message ) );
	}

	/** @dataProvider delegated_methods */
	public function test_dispatch_authentication_failures_have_safe_guidance_without_diagnostic_reads( string $method ): void {
		$paths = array(
			'modules' => '/google-site-kit/v1/core/modules/data/list',
			'permissions' => '/google-site-kit/v1/core/user/data/permissions',
			'pagespeed' => '/google-site-kit/v1/modules/pagespeed-insights/data/pagespeed',
		);
		foreach ( array( 401, 403, 429, 500 ) as $status ) {
			$GLOBALS['wstm125']['error_path'] = $paths[ $method ];
			$GLOBALS['wstm125']['error_status'] = $status;
			$GLOBALS['wstm125']['dispatch_paths'] = array();
			$result = $this->invoke( 'execute', $method );
			$this->assertInstanceOf( WP_Error::class, $result );
			$public = Webmastery_MCP_Response::from_wp_error( $result );
			if ( in_array( $status, array( 401, 403 ), true ) ) {
				$this->assert_guidance( $public );
			} else {
				$this->assertSame( 'upstream_failed', $public['error']['code'] );
				$this->assertSame( 'site_kit_request_failed', $public['error']['reason'] );
				$this->assertSame( 'Google Site Kit could not complete the request.', $public['error']['message'] );
			}
			$this->assertStringNotContainsString( 'PRIVATE', json_encode( $public ) );
			$this->assertSame( '{}', json_encode( $public['error']['details'] ) );
			$this->assertSame( 'pagespeed' === $method ? array( $paths['modules'], $paths[ $method ] ) : array( $paths[ $method ] ), $GLOBALS['wstm125']['dispatch_paths'] );
		}
	}

	/**
	 * Native lifecycle and Adapter-result doubles, not real-core or HTTP evidence.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_guidance_survives_native_permission_and_mcp_boundaries_with_negative_controls(): void {
		require_once __DIR__ . '/fixtures/native-input-ability.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-ability.php';
		function wp_get_ability( $name ) {
			return $GLOBALS['wstm204_ability'];
		}
		Webmastery_MCP_Site_Kit::register();
		foreach ( array( 'list-site-kit-modules', 'get-site-kit-permissions', 'get-site-kit-pagespeed' ) as $short ) {
			$name = 'webmastery-site-toolkit-for-mcp/' . $short;
			$args = Webmastery_MCP_Input::register_args( Webmastery_MCP_Response::register_args( $GLOBALS['wstm_test_abilities'][ $name ], $name ), $name );
			$ability = new Webmastery_MCP_Ability( $name, $args );
			$GLOBALS['wstm204_ability'] = $ability;
			$input = 'get-site-kit-pagespeed' === $short ? array( 'strategy' => 'mobile' ) : array();
			foreach ( array( 'false', 'null', 'error', 'missing-callback', 'uncallable-callback', 'missing-route', 'no-read', 'dispatch-401', 'dispatch-403', 'dispatch-500' ) as $mode ) {
				$GLOBALS['wstm_test_user_caps'] = 'no-read' === $mode ? array() : array( 'read' );
				$GLOBALS['wstm125']['mode'] = $mode;
				$GLOBALS['wstm125']['error_path'] = '/google-site-kit/v1/' . ( 'get-site-kit-pagespeed' === $short ? 'modules/pagespeed-insights/data/pagespeed' : ( 'get-site-kit-permissions' === $short ? 'core/user/data/permissions' : 'core/modules/data/list' ) );
				$GLOBALS['wstm125']['error_status'] = (int) substr( $mode, -3 );
				$GLOBALS['wstm125']['dispatch_paths'] = array();
				$GLOBALS['wstm125']['calls'] = array( 'plugin' => 0, 'routes' => 0, 'permission' => 0, 'data' => 0 );
				$dispatch = 0 === strpos( $mode, 'dispatch-' );
				$result = $dispatch ? $ability->execute( $input ) : $ability->check_permissions( $input );
				$public = is_wp_error( $result ) ? Webmastery_MCP_Response::from_wp_error( $result ) : $result;
				if ( in_array( $mode, array( 'false', 'null', 'error', 'dispatch-401', 'dispatch-403' ), true ) ) {
					$this->assert_guidance( $public );
				} else {
					$this->assertStringNotContainsString( 'Dashboard sharing', $public['error']['message'] );
					$this->assertSame( 'no-read' === $mode ? 'forbidden' : ( $dispatch ? 'upstream_failed' : 'unsupported' ), $public['error']['code'] );
				}
				$this->assertStringNotContainsString( 'PRIVATE', json_encode( $public ) );
				foreach ( array( $name, 'mcp-adapter/execute-ability' ) as $tool_ability ) {
					$tool = new class( $tool_ability ) {
						private string $name;
						public function __construct( string $name ) { $this->name = $name; }
						public function get_observability_context(): array { return array( 'ability_name' => $this->name ); }
					};
					$carried = 'mcp-adapter/execute-ability' === $tool_ability && ! is_wp_error( $result ) ? array( 'success' => true, 'data' => $result ) : $result;
					$wire = Webmastery_MCP_Response::mcp_result( $carried, array( 'ability_name' => $name ), 'unused', $tool );
					$this->assertInstanceOf( WP_Error::class, $wire );
					$this->assertSame( json_encode( $public ), $wire->get_error_message() );
				}
				if ( ! $dispatch ) {
					$this->assertSame( array(), $GLOBALS['wstm125']['dispatch_paths'] );
					$this->assertSame( 0, $GLOBALS['wstm125']['calls']['data'] );
					$this->assertSame( 'no-read' === $mode ? 0 : 1, $GLOBALS['wstm125']['calls']['routes'] );
				}
				if ( 'no-read' === $mode ) {
					$this->assertSame( 0, array_sum( $GLOBALS['wstm125']['calls'] ) );
				}
			}
			// Core masks a failed execute-time permission preflight; keep its native category.
			$GLOBALS['wstm125']['mode'] = 'error';
			$this->assertSame( 'ability_invalid_permissions', $ability->execute( $input )['error']['reason'] );
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
