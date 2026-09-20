<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-comments.php';

function get_comment( $id ) {
	$GLOBALS['wstm105_comment_reads'][] = $id;
	return $GLOBALS['wstm105_comments'][ $id ] ?? null;
}

final class CommentsHelpersTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm_test_user_caps'] = array( 'moderate_comments' );
		$GLOBALS['wstm105_comments'] = array();
		$GLOBALS['wstm105_comment_reads'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm_test_user_caps'], $GLOBALS['wstm105_comments'], $GLOBALS['wstm105_comment_reads'] );
	}

	/**
	 * @dataProvider invalid_input_provider
	 */
	public function test_moderation_rejects_unvalidated_input( $input, string $code ): void {
		$helper = new ReflectionMethod( Webmastery_MCP_Comments::class, 'moderate_comment_or_error' );
		$helper->setAccessible( true );
		$result = $helper->invoke( null, $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wstm105_comment_reads'] );

		$permission = new ReflectionMethod( Webmastery_MCP_Comments::class, 'moderate_permission' );
		$permission->setAccessible( true );
		$result = ( $permission->invoke( null ) )( $input );
		$this->assertTrue( $result );

		$GLOBALS['wstm_test_user_caps'] = array();
		$result = ( $permission->invoke( null ) )( $input );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public static function invalid_input_provider(): array {
		$cases = array();
		foreach ( array( null, false, 1, 'comment', new stdClass() ) as $input ) {
			$cases[] = array( $input, 'invalid_input' );
		}
		$cases[] = array( array(), 'not_found' );
		foreach ( array( null, false, true, 0, -1, 1.5, '', 'invalid', array( 1 ), new stdClass() ) as $id ) {
			$cases[] = array( array( 'comment_id' => $id ), 'not_found' );
		}
		return $cases;
	}

	public function test_permission_defers_missing_input_but_execute_fails_explicitly(): void {
		$permission = new ReflectionMethod( Webmastery_MCP_Comments::class, 'moderate_permission' );
		$permission->setAccessible( true );
		$this->assertTrue( ( $permission->invoke( null ) )() );
		Webmastery_MCP_Comments::register();
		foreach ( array( 'update', 'approve', 'trash', 'spam' ) as $action ) {
			$execute = $GLOBALS['wstm_test_abilities'][ "webmastery-site-toolkit-for-mcp/{$action}-comment" ]['execute_callback'];
			$result = $execute( array() );
			$this->assertSame( false, $result['success'] );
			$this->assertEquals( array( 'code' => 'not_found', 'reason' => 'not_found', 'message' => 'Comment not found.', 'details' => (object) array() ), $result['error'] );
		}
		$this->assertSame( array(), $GLOBALS['wstm105_comment_reads'] );
	}

	public function test_existing_comment_requires_both_capabilities(): void {
		$comment = (object) array( 'comment_ID' => '15' );
		$GLOBALS['wstm105_comments'][15] = $comment;
		$helper = new ReflectionMethod( Webmastery_MCP_Comments::class, 'moderate_comment_or_error' );
		$helper->setAccessible( true );
		foreach ( array( array(), array( 'moderate_comments' ), array( 'edit_comment' ), array( 'moderate_comments', 'edit_comment' ) ) as $caps ) {
			$GLOBALS['wstm_test_user_caps'] = $caps;
			foreach ( array( false, true ) as $permission_check ) {
				$result = $helper->invoke( null, array( 'comment_id' => 15 ), $permission_check );
				if ( 2 === count( $caps ) ) {
					$this->assertSame( $comment, $result );
				} else {
					$this->assertSame( 'forbidden', $result->get_error_code() );
				}
			}
		}
	}

	public function test_missing_comment_preserves_direct_and_permission_precedence(): void {
		$helper = new ReflectionMethod( Webmastery_MCP_Comments::class, 'moderate_comment_or_error' );
		$helper->setAccessible( true );
		foreach ( array( array(), array( 'moderate_comments' ) ) as $caps ) {
			$GLOBALS['wstm_test_user_caps'] = $caps;
			$this->assertSame( 'not_found', $helper->invoke( null, array( 'comment_id' => 999 ) )->get_error_code() );
			$this->assertSame( $caps ? 'not_found' : 'forbidden', $helper->invoke( null, array( 'comment_id' => 999 ), true )->get_error_code() );
		}
	}
}
