<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/permissions-stubs.php';

final class PermissionsCharacterizationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm119'] = array(
			'user' => 11,
			'caps' => array(),
			'calls' => array(),
			'abilities' => array(),
			'boundaries' => array(),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm119'] );
	}

	private function registerAbilities(): array {
		Wstm119\Webmastery_MCP_Health::register();
		Wstm119\Webmastery_MCP_Security::register();
		Wstm119\Webmastery_MCP_Site_Info::register();
		Wstm119\Webmastery_MCP_Webmaster_Verification::register();
		self::assertSame( array(), $GLOBALS['wstm119']['calls'], 'Registration must not check the current user.' );
		return $GLOBALS['wstm119']['abilities'];
	}

	public static function callbackProvider(): array {
		return array(
			'health closure' => array( 'site-health-check', 'manage_options' ),
			'security closure' => array( 'security-audit', 'manage_options' ),
			'site read facade' => array( 'get-site-info', 'read' ),
			'user read facade' => array( 'get-user-info', 'read' ),
			'site admin facade' => array( 'get-environment-info', 'manage_options' ),
			'verification read facade' => array( 'webmaster-verification-status', 'read' ),
		);
	}

	/** @dataProvider callbackProvider */
	public function testActualCallbacksObserveEveryEffectiveCapabilityChange( string $ability, string $cap ): void {
		$callbacks = $this->registerAbilities();
		$callback = $callbacks[ 'webmastery-site-toolkit-for-mcp/' . $ability ]['permission_callback'];
		self::assertSame( 0, ( new ReflectionFunction( Closure::fromCallable( $callback ) ) )->getNumberOfParameters() );
		$other = 'read' === $cap ? 'manage_options' : 'read';
		$expected_calls = array();
		foreach ( array(
			array( 11, array( $other ), false ),
			array( 11, array( $cap ), true ),
			array( 11, array(), false ),
			array( 22, array( $cap ), true ),
			array( 33, array( $other ), false ),
		) as [ $user, $caps, $allowed ] ) {
			$GLOBALS['wstm119']['user'] = $user;
			$GLOBALS['wstm119']['caps'] = $caps;
			// The original zero-argument callbacks ignore even non-array input.
			foreach ( array( null, array( 'capability' => $other ), 'ignored', new stdClass() ) as $input ) {
				$result = $callback( $input );
				if ( $allowed ) {
					self::assertTrue( $result );
				} else {
					$this->assertLocalDenial( $result, $cap );
				}
				$expected_calls[] = array( $user, $cap );
				self::assertSame( $expected_calls, $GLOBALS['wstm119']['calls'], 'Exactly one effective capability lookup per invocation.' );
			}
		}
	}

	private function assertLocalDenial( $result, string $cap ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( Webmastery_MCP_Local_Error::class, get_class( $result ) );
		self::assertSame( 'forbidden', $result->get_error_code() );
		self::assertSame( "Requires {$cap} capability.", $result->get_error_message() );
		self::assertSame( array(), $result->get_error_data() );
		$envelope = Webmastery_MCP_Response::from_wp_error( $result );
		self::assertFalse( $envelope['success'] );
		self::assertSame( array(
			'code' => 'forbidden',
			'reason' => 'forbidden',
			'message' => "Requires {$cap} capability.",
		), array_diff_key( $envelope['error'], array( 'details' => true ) ) );
		self::assertEquals( (object) array(), $envelope['error']['details'] );
	}

	public function testPublicFacadesRemainCallableWithoutRegistration(): void {
		foreach ( array(
			array( Wstm119\Webmastery_MCP_Site_Info::class, 'permission', 'read' ),
			array( Wstm119\Webmastery_MCP_Site_Info::class, 'admin_permission', 'manage_options' ),
			array( Wstm119\Webmastery_MCP_Webmaster_Verification::class, 'permission', 'read' ),
		) as [ $class, $method, $cap ] ) {
			$GLOBALS['wstm119']['caps'] = array();
			$this->assertLocalDenial( $class::$method(), $cap );
			$GLOBALS['wstm119']['caps'] = array( $cap );
			self::assertTrue( $class::$method() );
		}
		self::assertCount( 6, $GLOBALS['wstm119']['calls'] );
	}

	public function testDirectVerificationDeniesBeforeAllPublicCacheAndPluginBoundaries(): void {
		$GLOBALS['wstm119']['caps'] = array( 'manage_options', 'activate_plugins' );
		$result = Wstm119\Webmastery_MCP_Webmaster_Verification::execute();
		$this->assertLocalDenial( $result, 'read' );
		self::assertSame( array( array( 11, 'read' ) ), $GLOBALS['wstm119']['calls'] );
		self::assertSame( array(), $GLOBALS['wstm119']['boundaries'] );
	}
}
