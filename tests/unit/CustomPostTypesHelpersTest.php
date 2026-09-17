<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for Webmastery_MCP_Custom_Post_Types::validate_taxonomy_terms(),
 * the helper the update-cpt-* ability must call before wp_update_post() so that
 * invalid/unauthorized taxonomy_terms payloads are rejected before any write
 * occurs. See https://github.com/DanielBoring/webmastery-site-toolkit-for-mcp/issues/107
 */
final class CustomPostTypesHelpersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wstm_test_taxonomies'] = array();
		$GLOBALS['wstm_test_user_caps']  = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm_test_taxonomies'], $GLOBALS['wstm_test_user_caps'] );

		parent::tearDown();
	}

	private static function call_private( string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( Webmastery_MCP_Custom_Post_Types::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	private static function register_taxonomy( string $taxonomy, array $object_types, string $assign_cap = 'assign_terms' ): void {
		$taxonomy_object               = new stdClass();
		$taxonomy_object->object_types = $object_types;
		$taxonomy_object->cap          = new stdClass();
		$taxonomy_object->cap->assign_terms = $assign_cap;

		$GLOBALS['wstm_test_taxonomies'][ $taxonomy ] = $taxonomy_object;
	}

	public function test_empty_taxonomy_terms_is_valid(): void {
		$this->assertTrue( self::call_private( 'validate_taxonomy_terms', array( 'mcp_book', array() ) ) );
	}

	public function test_non_array_taxonomy_terms_is_rejected(): void {
		$result = self::call_private( 'validate_taxonomy_terms', array( 'mcp_book', 'not-an-array' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy_terms', $result->get_error_code() );
	}

	public function test_unregistered_taxonomy_is_rejected(): void {
		$result = self::call_private( 'validate_taxonomy_terms', array( 'mcp_book', array( 'wstm107_missing_taxonomy' => array( 1 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
	}

	public function test_taxonomy_not_attached_to_post_type_is_rejected(): void {
		self::register_taxonomy( 'mcp_genre', array( 'mcp_case_study' ) );

		$result = self::call_private( 'validate_taxonomy_terms', array( 'mcp_book', array( 'mcp_genre' => array( 1 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
	}

	public function test_user_without_assign_capability_is_rejected(): void {
		self::register_taxonomy( 'mcp_genre', array( 'mcp_book' ), 'assign_mcp_genres' );
		$GLOBALS['wstm_test_user_caps'] = array();

		$result = self::call_private( 'validate_taxonomy_terms', array( 'mcp_book', array( 'mcp_genre' => array( 1 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function test_mixed_valid_and_invalid_taxonomy_payload_fails_before_any_assignment(): void {
		self::register_taxonomy( 'mcp_genre', array( 'mcp_book' ), 'assign_mcp_genres' );
		$GLOBALS['wstm_test_user_caps'] = array( 'assign_mcp_genres' );

		$result = self::call_private(
			'validate_taxonomy_terms',
			array(
				'mcp_book',
				array(
					'mcp_genre'                => array( 1 ),
					'wstm107_missing_taxonomy' => array( 2 ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
	}

	public function test_valid_taxonomy_with_assign_capability_passes(): void {
		self::register_taxonomy( 'mcp_genre', array( 'mcp_book' ), 'assign_mcp_genres' );
		$GLOBALS['wstm_test_user_caps'] = array( 'assign_mcp_genres' );

		$this->assertTrue(
			self::call_private( 'validate_taxonomy_terms', array( 'mcp_book', array( 'mcp_genre' => array( 1 ) ) ) )
		);
	}
}
