<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AbilityCallbackTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_legacy_callback_exceptions_are_safe_failures_without_replay(): void {
		require __DIR__ . '/fixtures/legacy-ability.php';
		require dirname( __DIR__, 2 ) . '/includes/class-ability.php';
		$ability = new Webmastery_MCP_Ability();
		$invoke = new ReflectionMethod( $ability, 'invoke_callback' );
		$invoke->setAccessible( true );
		foreach ( array( new RuntimeException( 'PRIVATE callback' ), new Error( 'PRIVATE engine failure' ) ) as $exception ) {
			$callback = static function ( $input ) use ( $exception ) {
				throw $exception;
			};
			$ability->calls = 0;
			$result = $invoke->invoke( $ability, $callback, array( 'probe' => 1 ) );
			$this->assertSame( 1, $ability->calls );
			$this->assertInstanceOf( WP_Error::class, $result );
			$error = Webmastery_MCP_Response::from_wp_error( $result );
			$this->assertSame( 'upstream_failed', $error['error']['code'] );
			$this->assertSame( 'ability_callback_exception', $error['error']['reason'] );
			$this->assertSame( 'The ability could not complete the operation.', $error['error']['message'] );
			$this->assertSame( '{}', json_encode( $error['error']['details'] ) );
			$this->assertStringNotContainsString( 'PRIVATE', json_encode( $error ) );
			$ability->calls = 0;
			$ability->callback = $callback;
			$permission = $ability->check_permissions( array( 'probe' => 1 ) );
			$this->assertSame( 1, $ability->calls );
			$this->assertInstanceOf( WP_Error::class, $permission );
			$this->assertSame( 502, $permission->get_error_data()['status'] );
			$this->assertSame( $error['error']['reason'], Webmastery_MCP_Response::from_wp_error( $permission )['error']['reason'] );
			$this->assertStringNotContainsString( 'PRIVATE', $permission->get_error_message() );
		}
		foreach ( array( array( 'success' => true, 'data' => 'unchanged' ), new WP_Error( 'provider', 'unchanged native result' ), true ) as $value ) {
			$ability->calls = 0;
			$callback = static fn( $input ) => $input;
			$this->assertSame( $value, $invoke->invoke( $ability, $callback, $value ) );
			$this->assertSame( 1, $ability->calls );
		}
		$ability->callback = static fn() => true;
		$this->assertTrue( $ability->check_permissions() );
	}
}
