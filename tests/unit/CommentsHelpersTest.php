<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-comments.php';

final class CommentsHelpersTest extends TestCase {
	/**
	 * @dataProvider invalid_input_provider
	 */
	public function test_moderation_rejects_unvalidated_input( $input, string $code ): void {
		$helper = new ReflectionMethod( Webmastery_MCP_Comments::class, 'moderate_comment_or_error' );
		$helper->setAccessible( true );
		$result = $helper->invoke( null, $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		$permission = new ReflectionMethod( Webmastery_MCP_Comments::class, 'moderate_permission' );
		$permission->setAccessible( true );
		$result = ( $permission->invoke( null ) )( $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public static function invalid_input_provider(): array {
		$cases = array();
		foreach ( array( null, false, 1, 'comment', new stdClass() ) as $input ) {
			$cases[] = array( $input, 'invalid_input' );
		}
		$cases[] = array( array(), 'invalid_comment_id' );
		foreach ( array( null, false, true, 0, -1, 1.5, '', 'invalid', array( 1 ), new stdClass() ) as $id ) {
			$cases[] = array( array( 'comment_id' => $id ), 'invalid_comment_id' );
		}
		return $cases;
	}

	public function test_permission_without_input_fails_explicitly(): void {
		$permission = new ReflectionMethod( Webmastery_MCP_Comments::class, 'moderate_permission' );
		$permission->setAccessible( true );
		$result = ( $permission->invoke( null ) )();
		$this->assertSame( 'invalid_comment_id', $result->get_error_code() );
	}
}
