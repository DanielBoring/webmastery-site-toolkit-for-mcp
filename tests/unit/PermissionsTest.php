<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm_test_cap_calls'] = array();
		$GLOBALS['wstm_test_user_caps'] = array();
		unset( $GLOBALS['wstm_test_object_capability'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm_test_cap_calls'], $GLOBALS['wstm_test_user_caps'] );
	}

	private function assertDecision( $result, string $cap, bool $allowed ): void {
		if ( $allowed ) {
			self::assertTrue( $result );
			return;
		}
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( Webmastery_MCP_Local_Error::class, get_class( $result ) );
		self::assertSame( 'forbidden', $result->get_error_code() );
		self::assertSame( "Requires {$cap} capability.", $result->get_error_message() );
		self::assertSame( array(), $result->get_error_data() );
		self::assertSame( "Requires {$cap} capability.", Webmastery_MCP_Response::from_wp_error( $result )['error']['message'] );
	}

	private function assertHelperContract( string $class ): void {
		foreach ( array( 'read', 'manage_options', 'custom_plugin_capability' ) as $cap ) {
			foreach ( array( false, true, false, true ) as $allowed ) {
				$GLOBALS['wstm_test_user_caps'] = $allowed ? array( $cap ) : array( 'unrelated_capability' );
				$GLOBALS['wstm_test_cap_calls'] = array();
				$this->assertDecision( $class::check( $cap ), $cap, $allowed );
				self::assertSame( array( array( $cap ) ), $GLOBALS['wstm_test_cap_calls'] );
			}
			$GLOBALS['wstm_test_cap_calls'] = array();
			$callback = 'manage_options' === $cap ? $class::admin() : $class::cap( $cap );
			self::assertInstanceOf( Closure::class, $callback );
			self::assertSame( 0, ( new ReflectionFunction( $callback ) )->getNumberOfParameters() );
			self::assertSame( array(), $GLOBALS['wstm_test_cap_calls'], 'Factories must defer capability evaluation.' );
			foreach ( array( false, true, false, true ) as $allowed ) {
				$GLOBALS['wstm_test_user_caps'] = $allowed ? array( $cap ) : array( 'unrelated_capability' );
				foreach ( array( null, 'ignored', array( 'capability' => 'manage_options' ) ) as $input ) {
					$GLOBALS['wstm_test_cap_calls'] = array();
					$this->assertDecision( $callback( $input ), $cap, $allowed );
					self::assertSame( array( array( $cap ) ), $GLOBALS['wstm_test_cap_calls'], 'Each call must recheck exactly once.' );
				}
			}
		}
	}

	public function testActualHelperAndDeferredFactories(): void {
		self::assertTrue( class_exists( Webmastery_MCP_Permissions::class, false ), 'Unit bootstrap must load the helper.' );
		$this->assertHelperContract( Webmastery_MCP_Permissions::class );
	}

	public static function mutationProvider(): array {
		return array(
			'eager factory' => array(
				'return static function () use ( $cap ): bool|WP_Error {' . "\n\t\t\t" . 'return self::check( $cap );',
				'$result = self::check( $cap ); return static function () use ( $result ): bool|WP_Error { return $result;',
			),
			'cached decision' => array(
				'return self::check( $cap );',
				'static $result; return $result ??= self::check( $cap );',
			),
			'always allow' => array( 'if ( ! current_user_can( $cap ) )', 'if ( false )' ),
			'wrong queried capability' => array( 'current_user_can( $cap )', "current_user_can( 'manage_options' )" ),
			'wrong admin capability' => array( "self::cap( 'manage_options' )", "self::cap( 'read' )" ),
			'false denial' => array(
				'Webmastery_MCP_Response::local_error( \'forbidden\', "Requires {$cap} capability." )',
				'false',
			),
			'untrusted error provenance' => array(
				'Webmastery_MCP_Response::local_error( \'forbidden\', "Requires {$cap} capability." )',
				'new WP_Error( \'forbidden\', "Requires {$cap} capability." )',
			),
			'changed diagnostic' => array( 'Requires {$cap} capability.', 'Permission denied.' ),
			'duplicate lookup' => array( 'current_user_can( $cap )', '( current_user_can( $cap ) & current_user_can( $cap ) )' ),
		);
	}

	/** @dataProvider mutationProvider */
	public function testContractRejectsMutatedActualHelper( string $before, string $after ): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-permissions.php' );
		$source = str_replace( "\r\n", "\n", $source );
		$source = str_replace( $before, $after, $source, $count );
		self::assertSame( 1, $count, 'Mutation must change exactly one actual source location.' );
		$namespace = 'Wstm119Mutation' . md5( $before . $after );
		eval(
			'namespace ' . $namespace . '; use \Closure; use \WP_Error; use \Webmastery_MCP_Response; '
			. 'function current_user_can( $cap ) { return \current_user_can( $cap ); } '
			. substr( $source, 5 )
		);
		try {
			$this->assertHelperContract( $namespace . '\Webmastery_MCP_Permissions' );
		} catch ( AssertionFailedError $error ) {
			self::assertNotSame( '', $error->getMessage() );
			return;
		}
		self::fail( 'Permission contract accepted the mutant.' );
	}
}
