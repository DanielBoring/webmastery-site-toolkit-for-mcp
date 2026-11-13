<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

function get_terms( $args ) {
	$GLOBALS['wstm_test_get_terms_calls'][] = $args;
	return $GLOBALS['wstm_test_get_terms_result'] ?? array();
}

final class TaxonomyWriteTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm_test_abilities'] = array();
		$GLOBALS['wstm_test_taxonomies'] = array();
		$GLOBALS['wstm_test_terms'] = array();
		$GLOBALS['wstm_test_delete_calls'] = array();
		$GLOBALS['wstm_test_cap_calls'] = array();
		$GLOBALS['wstm_test_user_caps'] = array( 'custom_delete', 'delete_term' );
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$GLOBALS['wstm_test_taxonomies'][ $taxonomy ] = (object) array( 'cap' => (object) array( 'edit_terms' => 'custom_edit', 'delete_terms' => 'custom_delete' ) );
			$GLOBALS['wstm_test_terms'][ $taxonomy ][42] = (object) array( 'term_id' => 42, 'taxonomy' => $taxonomy );
		}
		Webmastery_MCP_Taxonomy::register();
	}

	protected function tearDown(): void {
		foreach ( array( 'abilities', 'taxonomies', 'terms', 'delete_calls', 'cap_calls', 'user_caps', 'delete_result', 'get_terms_calls', 'get_terms_result' ) as $key ) {
			unset( $GLOBALS[ 'wstm_test_' . $key ] );
		}
	}

	public static function deletion_results(): array {
		$cases = array();
		foreach ( array( 'category', 'tag' ) as $slug ) {
			$cases[ "{$slug} forced zero" ] = array( $slug, 0, false );
			$cases[ "{$slug} forced false" ] = array( $slug, false, false );
			$cases[ "{$slug} forced WP_Error" ] = array( $slug, new WP_Error( 'db_error', 'Original storage error.' ), false );
			$cases[ "{$slug} true" ] = array( $slug, true, true );
		}
		return $cases;
	}

	/** @dataProvider deletion_results */
	public function test_deletion_result_is_truthful( string $slug, $core_result, bool $success ): void {
		$GLOBALS['wstm_test_delete_result'] = $core_result;
		$callbacks = $GLOBALS['wstm_test_abilities'][ "webmastery-site-toolkit-for-mcp/delete-{$slug}" ];
		$input = array( "{$slug}_id" => -42, 'confirm' => true );
		$this->assertTrue( $callbacks['permission_callback']( $input ) );
		$result = $callbacks['execute_callback']( $input );
		if ( $success ) {
			$this->assertSame( array( 'success' => true, 'data' => array( 'id' => 42, 'deleted' => true ) ), $result );
		} else {
			$this->assertEquals( array(
				'success' => false,
				'error' => array(
					'code' => 'upstream_failed',
					'reason' => is_wp_error( $core_result ) ? 'external_error' : 'delete_failed',
					'message' => is_wp_error( $core_result ) ? 'An external operation failed.' : ucfirst( $slug ) . ' was not deleted.',
					'details' => (object) array(),
				),
			), $result );
		}
		$this->assertSame( array( array( 42, 'category' === $slug ? 'category' : 'post_tag' ) ), $GLOBALS['wstm_test_delete_calls'] );
		$this->assertSame( array( array( 'custom_delete' ), array( 'delete_term', 42 ), array( 'custom_delete' ), array( 'delete_term', 42 ) ), $GLOBALS['wstm_test_cap_calls'] );
	}

	public function test_unregistered_taxonomy_fails_closed_without_deletion(): void {
		$GLOBALS['wstm_test_taxonomies']['post_tag'] = false;
		$callbacks = $GLOBALS['wstm_test_abilities']['webmastery-site-toolkit-for-mcp/delete-tag'];
		$this->assertSame( 'invalid_taxonomy', $callbacks['permission_callback']( array( 'tag_id' => 42 ) )->get_error_code() );
		$this->assertEquals( array( 'success' => false, 'error' => array(
			'code' => 'invalid_input',
			'reason' => 'invalid_taxonomy',
			'message' => 'Taxonomy is not registered.',
			'details' => (object) array(),
		) ), $callbacks['execute_callback']( array( 'tag_id' => 42, 'confirm' => true ) ) );
		$this->assertSame( array(), $GLOBALS['wstm_test_delete_calls'] );
	}

	public static function list_paging_cases(): array {
		$cases = array();
		foreach ( array( 'categories' => 'category', 'tags' => 'post_tag' ) as $slug => $taxonomy ) {
			$cases[ "{$slug} defaults" ] = array( $slug, $taxonomy, array(), 100, 0 );
			$cases[ "{$slug} first page" ] = array( $slug, $taxonomy, array( 'page' => 1, 'per_page' => 25 ), 25, 0 );
			$cases[ "{$slug} third page" ] = array( $slug, $taxonomy, array( 'page' => 3, 'per_page' => 25 ), 25, 50 );
			$cases[ "{$slug} default per_page second page" ] = array( $slug, $taxonomy, array( 'page' => 2 ), 100, 100 );
			$cases[ "{$slug} per_page capped" ] = array( $slug, $taxonomy, array( 'page' => 2, 'per_page' => 500 ), 200, 200 );
			$cases[ "{$slug} page below one" ] = array( $slug, $taxonomy, array( 'page' => 0, 'per_page' => 10 ), 10, 0 );
		}
		return $cases;
	}

	/** @dataProvider list_paging_cases */
	public function test_list_pages_with_offset_and_keeps_bare_list( string $slug, string $taxonomy, array $input, int $number, int $offset ): void {
		$GLOBALS['wstm_test_get_terms_result'] = array( (object) array( 'term_id' => 7, 'name' => 'News', 'slug' => 'news', 'description' => '', 'count' => 3, 'parent' => 0 ) );
		$ability = $GLOBALS['wstm_test_abilities'][ "webmastery-site-toolkit-for-mcp/list-{$slug}" ];
		$result = $ability['execute_callback']( $input );
		$this->assertSame( array( 'success' => true, 'data' => array( array( 'id' => 7, 'name' => 'News', 'slug' => 'news', 'description' => '', 'count' => 3, 'parent' => 0 ) ) ), $result );
		$this->assertSame( array( array( 'taxonomy' => $taxonomy, 'number' => $number, 'offset' => $offset, 'hide_empty' => false ) ), $GLOBALS['wstm_test_get_terms_calls'] );
	}

	public function test_list_returns_zero_based_list_when_terms_keep_their_keys(): void {
		$term_b = (object) array( 'term_id' => 10, 'name' => 'B', 'slug' => 'b', 'description' => '', 'count' => 1, 'parent' => 0 );
		$term_c = (object) array( 'term_id' => 12, 'name' => 'C', 'slug' => 'c', 'description' => '', 'count' => 2, 'parent' => 0 );
		foreach ( array( 'categories', 'tags' ) as $slug ) {
			$GLOBALS['wstm_test_get_terms_result'] = array( 1 => $term_b, 5 => $term_c );
			$ability = $GLOBALS['wstm_test_abilities'][ "webmastery-site-toolkit-for-mcp/list-{$slug}" ];
			$result  = $ability['execute_callback']( array( 'page' => 2, 'per_page' => 2 ) );
			$this->assertTrue( $result['success'] );
			$this->assertSame( array( 0, 1 ), array_keys( $result['data'] ) );
			$this->assertSame( array( 10, 12 ), array_column( $result['data'], 'id' ) );
		}
	}

	public function test_list_offset_overflow_returns_empty_page_without_query(): void {
		foreach ( array( 'categories', 'tags' ) as $slug ) {
			$ability = $GLOBALS['wstm_test_abilities'][ "webmastery-site-toolkit-for-mcp/list-{$slug}" ];
			$this->assertSame( array( 'success' => true, 'data' => array() ), $ability['execute_callback']( array( 'page' => PHP_INT_MAX, 'per_page' => 200 ) ) );
		}
		$this->assertArrayNotHasKey( 'wstm_test_get_terms_calls', $GLOBALS );

		$ability = $GLOBALS['wstm_test_abilities']['webmastery-site-toolkit-for-mcp/list-tags'];
		$largest = intdiv( PHP_INT_MAX, 200 ) + 1;
		$ability['execute_callback']( array( 'page' => $largest, 'per_page' => 200 ) );
		$this->assertSame( ( $largest - 1 ) * 200, $GLOBALS['wstm_test_get_terms_calls'][0]['offset'] );
		$this->assertIsInt( $GLOBALS['wstm_test_get_terms_calls'][0]['offset'] );
	}

	public function test_list_schema_declares_page_and_describes_paging(): void {
		foreach ( array( 'categories' => 'Categories', 'tags' => 'Tags' ) as $slug => $label ) {
			$ability = $GLOBALS['wstm_test_abilities'][ "webmastery-site-toolkit-for-mcp/list-{$slug}" ];
			$this->assertSame( array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ), $ability['input_schema']['properties']['page'] );
			$this->assertSame( array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ), $ability['input_schema']['properties']['per_page'] );
			$this->assertStringContainsString( 'request the next page until a page returns fewer than per_page items', $ability['description'] );
			$this->assertStringNotContainsString( "List all WordPress {$label}.", $ability['description'] );
		}
	}
}
