<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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
		foreach ( array( 'abilities', 'taxonomies', 'terms', 'delete_calls', 'cap_calls', 'user_caps', 'delete_result' ) as $key ) {
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
}
