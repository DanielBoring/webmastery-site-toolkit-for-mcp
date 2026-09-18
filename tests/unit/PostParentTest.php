<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostParentTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm_test_posts'] = array();
		$GLOBALS['wstm_test_hierarchical_types'] = array( 'page', 'custom' );
		$GLOBALS['wstm_test_user_caps'] = array( 'edit_post' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm_test_posts'], $GLOBALS['wstm_test_hierarchical_types'], $GLOBALS['wstm_test_user_caps'], $GLOBALS['wstm_test_object_capability'] );
	}

	private function post( int $id, int $parent = 0, string $type = 'page' ): void {
		$GLOBALS['wstm_test_posts'][ $id ] = (object) array( 'ID' => $id, 'post_parent' => $parent, 'post_type' => $type );
	}

	public function test_zero_allows_detach_even_for_nonhierarchical_type_without_parent_permissions(): void {
		$GLOBALS['wstm_test_user_caps'] = array();
		self::assertTrue( Webmastery_MCP_Post_Parent::validate( 'post', 0, 1 ) );
	}

	public function test_missing_wrong_type_and_nonhierarchical_parent_are_rejected(): void {
		self::assertSame( 'invalid_parent', Webmastery_MCP_Post_Parent::validate( 'page', 2 )->get_error_code() );
		$this->post( 2, 0, 'post' );
		self::assertSame( 'invalid_parent', Webmastery_MCP_Post_Parent::validate( 'page', 2 )->get_error_code() );
		self::assertSame( 'invalid_parent', Webmastery_MCP_Post_Parent::validate( 'post', 2 )->get_error_code() );
	}

	public function test_immediate_parent_uses_exact_capability_and_id_not_ancestor_permissions(): void {
		$this->post( 2, 3, 'custom' );
		$this->post( 3, 0, 'custom' );
		$calls = array();
		$GLOBALS['wstm_test_object_capability'] = static function ( $cap, $id ) use ( &$calls ) {
			$calls[] = array( $cap, $id );
			return 'edit_custom' === $cap && 2 === $id;
		};
		self::assertTrue( Webmastery_MCP_Post_Parent::validate( 'custom', 2, 1, 'edit_custom' ) );
		self::assertSame( array( array( 'edit_custom', 2 ) ), $calls );
		self::assertSame( 'forbidden', Webmastery_MCP_Post_Parent::validate( 'custom', 2, 1 )->get_error_code() );
	}

	public function test_self_descendant_and_existing_cycles_fail_without_mutating_graph(): void {
		$this->post( 1 );
		$this->post( 2, 1 );
		$this->post( 3, 4 );
		$this->post( 4, 3 );
		$before = serialize( $GLOBALS['wstm_test_posts'] );
		foreach ( array( 1, 2, 3 ) as $parent ) {
			self::assertSame( 'invalid_parent', Webmastery_MCP_Post_Parent::validate( 'page', $parent, 1 )->get_error_code() );
		}
		self::assertSame( 'invalid_parent', Webmastery_MCP_Post_Parent::validate( 'page', 3 )->get_error_code() );
		self::assertSame( $before, serialize( $GLOBALS['wstm_test_posts'] ) );
	}

	public function test_same_parent_and_long_chain_have_no_arbitrary_depth_limit(): void {
		$this->post( 1, 2 );
		for ( $id = 2; $id <= 10000; $id++ ) {
			$this->post( $id, 10000 === $id ? 0 : $id + 1 );
		}
		self::assertTrue( Webmastery_MCP_Post_Parent::validate( 'page', 2, 1 ) );
	}

	public function test_missing_distant_ancestor_does_not_reject_valid_immediate_parent(): void {
		$this->post( 2, 999 );
		self::assertTrue( Webmastery_MCP_Post_Parent::validate( 'page', 2, 1 ) );
	}
}
