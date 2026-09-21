<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm121\Probe;
use Wstm121\Webmastery_MCP_Posts as Posts;

require_once __DIR__ . '/fixtures/bounded-list-stubs.php';

final class BoundedListTest extends TestCase {
	protected function setUp(): void {
		Probe::reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private function posts( int $page = 1, int $per_page = 20, string $fields = 'summary', string $order = 'ASC' ): array {
		$method = new ReflectionMethod( Posts::class, 'query_readable_posts' );
		$method->setAccessible( true );
		return $method->invoke( null, array( 'post_type' => 'post', 'orderby' => 'title', 'order' => $order ), $page, $per_page, $fields );
	}

	public function test_large_candidate_set_does_not_authorize_the_library(): void {
		$result = $this->posts( 1, 100 );
		self::assertLessThanOrEqual( 100, count( Probe::$caps ) );
		self::assertSame( 101, Probe::$queries[0]['posts_per_page'] );
		self::assertSame( 0, Probe::$queries[0]['offset'] );
		self::assertTrue( Probe::$queries[0]['no_found_rows'] );
		self::assertSame( 'ids', Probe::$queries[0]['fields'] );
		self::assertCount( 100, $result['items'] );
		self::assertSame( 2, $result['next_page'] );
	}

	public function test_empty_intermediate_window_does_not_end_iteration(): void {
		Probe::$size = 9;
		Probe::$denied = array( 4, 5, 6 );
		$seen = array();
		for ( $page = 1; $page <= 3; ++$page ) {
			$result = $this->posts( $page, 3 );
			self::assertSame( $page, $result['page'] );
			self::assertSame( 3, $result['per_page'] );
			self::assertSame( 3 === $page ? null : $page + 1, $result['next_page'] );
			if ( 2 === $page ) {
				self::assertSame( array(), $result['items'] );
			}
			$seen = array_merge( $seen, array_column( $result['items'], 'id' ) );
			self::assertArrayNotHasKey( 'total', $result );
			self::assertArrayNotHasKey( 'total_pages', $result );
		}
		self::assertSame( array( 1, 2, 3, 7, 8, 9 ), $seen );
		self::assertSame( array( 0, 3, 6 ), array_column( Probe::$queries, 'offset' ) );
		self::assertSame( array(), $this->posts( 4, 3 )['items'] );
	}

	public function test_summary_omits_only_content_full_preserves_stored_bytes(): void {
		$summary = $this->posts( 1, 1 )['items'][0];
		$full = $this->posts( 1, 1, 'full' )['items'][0];
		self::assertArrayNotHasKey( 'content', $summary );
		self::assertSame( Probe::$content, $full['content'] );
		unset( $full['content'] );
		self::assertSame( $full, $summary );
		self::assertSame( 'Stored \\excerpt "value"', $summary['excerpt'] );
	}

	public function test_sticky_id_outside_window_cannot_expand_or_reorder_candidates(): void {
		Probe::$sticky = array( 20000 );
		$result = $this->posts( 1, 3 );
		self::assertTrue( Probe::$queries[0]['ignore_sticky_posts'] );
		self::assertSame( array( 1, 2, 3 ), array_column( $result['items'], 'id' ) );
		self::assertSame( array( array( 1, 2, 3 ) ), Probe::$primed );
		self::assertSame( 2, $result['next_page'] );
		self::assertSame( array( 4, 5, 6 ), array_column( $this->posts( 2, 3 )['items'], 'id' ) );
	}

	public function test_distinct_candidates_and_clamped_limit_only_prime_the_bounded_window(): void {
		$this->posts( 1, 999 );
		self::assertSame( 101, Probe::$queries[0]['posts_per_page'] );
		self::assertCount( 100, Probe::$primed[0] );
		self::assertSame( range( 1, 100 ), Probe::$primed[0] );
	}

	public function test_tied_sort_uses_same_direction_id_without_lookahead_skips(): void {
		Probe::$size = 8;
		$seen = array();
		for ( $page = 1; $page <= 3; ++$page ) {
			$result = $this->posts( $page, 3, 'summary', 'DESC' );
			$seen = array_merge( $seen, array_column( $result['items'], 'id' ) );
		}
		self::assertSame( range( 8, 1 ), $seen );
		self::assertSame( array( 'title' => 'DESC', 'ID' => 'DESC' ), Probe::$queries[0]['orderby'] );
		self::assertSame( 8, count( Probe::$caps ) );
	}

	public static function list_surfaces(): array {
		return array(
			array( 'list-posts', 'post' ), array( 'list-pages', 'page' ),
			array( 'list-cpt-test', 'test' ), array( 'list-media', 'attachment' ),
			array( 'get-seo-scores', 'post' ), array( 'get-readability-scores', 'page' ),
			array( 'list-orphaned-media', 'attachment' ),
		);
	}

	/** @dataProvider list_surfaces */
	public function test_all_list_surfaces_bound_large_denied_libraries( string $slug, string $type ): void {
		Probe::$type = $type;
		Probe::$denied = range( 1, 20000 );
		Posts::register();
		\Wstm121\Webmastery_MCP_Media::register();
		\Wstm121\Webmastery_MCP_Content_Hygiene::register();
		\Wstm121\Webmastery_MCP_SEO::register();
		$method = new ReflectionMethod( \Wstm121\Webmastery_MCP_Custom_Post_Types::class, 'register_custom_post_type' );
		$method->setAccessible( true );
		$method->invoke( null, (object) array(
			'name' => 'test', 'label' => 'Test', 'labels' => (object) array( 'singular_name' => 'Test' ),
			'hierarchical' => false, 'cap' => (object) array( 'read_post' => 'read_test', 'edit_posts' => 'edit_tests', 'edit_others_posts' => 'edit_others_tests' ),
		), 'test' );
		$execute = Probe::$abilities[ 'webmastery-site-toolkit-for-mcp/' . $slug ]['execute_callback'];
		$result = $execute( array( 'page' => 2, 'per_page' => 100 ) );
		self::assertTrue( $result['success'] );
		self::assertSame( array(), $result['data']['items'] );
		self::assertSame( 3, $result['data']['next_page'] );
		self::assertSame( 101, Probe::$queries[0]['posts_per_page'] );
		self::assertSame( 100, Probe::$queries[0]['offset'] );
		self::assertSame( array( range( 19900, 19801 ) ), Probe::$primed );
		$checked = array();
		foreach ( Probe::$caps as [ $cap, $args ] ) {
			if ( isset( $args[0] ) ) { $checked[] = $args[0]; }
		}
		self::assertCount( 100, array_unique( $checked ) );
		self::assertLessThanOrEqual( 510, count( Probe::$caps ) );
		self::assertArrayNotHasKey( 'total', $result['data'] );
		self::assertArrayNotHasKey( 'total_pages', $result['data'] );
	}

	public function test_revision_summary_and_full_preserve_original_shape_and_values(): void {
		Posts::register();
		$execute = Probe::$abilities['webmastery-site-toolkit-for-mcp/list-revisions']['execute_callback'];
		$summary = $execute( array( 'post_id' => 1 ) );
		$full = $execute( array( 'post_id' => 1, 'fields' => 'full' ) );
		self::assertSame( Probe::$content, $full['data']['revisions'][0]['content'] );
		unset( $full['data']['revisions'][0]['content'] );
		self::assertSame( $full, $summary );
		self::assertSame( array( 'post_id', 'type', 'revisions' ), array_keys( $summary['data'] ) );
	}

	public function test_cpt_summary_and_get_normalization_preserve_all_other_values(): void {
		Probe::$type = 'test';
		$type = (object) array( 'cap' => (object) array( 'read_post' => 'read_test' ) );
		$list = new ReflectionMethod( \Wstm121\Webmastery_MCP_Custom_Post_Types::class, 'query_readable_posts' );
		$list->setAccessible( true );
		$args = array( $type, array( 'orderby' => 'id', 'order' => 'ASC' ), 1, 1 );
		$summary = $list->invokeArgs( null, $args );
		$full = $list->invokeArgs( null, array_merge( $args, array( 'full' ) ) );
		$get = new ReflectionMethod( \Wstm121\Webmastery_MCP_Custom_Post_Types::class, 'normalize_post' );
		$get->setAccessible( true );
		self::assertSame( $get->invoke( null, 1 ), $full['items'][0] );
		self::assertSame( Probe::$content, $full['items'][0]['content'] );
		unset( $full['items'][0]['content'] );
		self::assertSame( $full, $summary );
		self::assertSame( array( 'ID' => 'ASC' ), Probe::$queries[0]['orderby'] );
	}

	public function test_controlled_large_body_summary_budget_is_not_a_full_payload_cap(): void {
		$original = Probe::$content;
		try {
			Probe::$content = str_repeat( 'x', 55000 );
			$summary = $this->posts();
			$full = $this->posts( 1, 20, 'full' );
			$bytes = strlen( json_encode( array( 'success' => true, 'data' => $summary ), JSON_THROW_ON_ERROR ) );
			self::assertLessThanOrEqual( 65536, $bytes );
			self::assertGreaterThan( 1000000, strlen( json_encode( $full, JSON_THROW_ON_ERROR ) ) );
			self::assertSame( Probe::$content, $full['items'][0]['content'] );
		} finally {
			Probe::$content = $original;
		}
	}

	public function test_batched_references_preserve_per_attachment_hits_and_prepared_literal_escaping(): void {
		$db = new \Wstm121\ReferenceDatabase();
		$GLOBALS['wpdb'] = $db;
		Probe::$type = 'attachment';
		$attachments = array_map( '\Wstm121\get_post', range( 1, 100 ) );
		$attachments[2]->guid = 'literal_%\\quote\'';
		$db->featured = array( '1' );
		$db->matched_patterns = array(
			'%https://example.test/uploads/2.png%',
			'%' . $db->esc_like( $attachments[2]->guid ) . '%',
		);
		$result = \Wstm121\Webmastery_MCP_Content_Hygiene::attachments_referenced( $attachments );
		self::assertSame( array( 1, 2, 3 ), array_keys( array_filter( $result ) ) );
		self::assertCount( 100, $result );
		self::assertCount( 5, $db->queries );
		self::assertSame( array_merge( array( '_thumbnail_id' ), array_map( 'strval', range( 1, 100 ) ) ), $db->queries[0][1] );
		self::assertStringContainsString( 'meta_value IN (', $db->queries[0][0] );
		$patterns = array();
		foreach ( array_slice( $db->queries, 1 ) as [ $sql, $values ] ) {
			self::assertLessThanOrEqual( 50, count( $values ) );
			self::assertSame( count( $values ), substr_count( $sql, 'post_content LIKE %s' ) );
			$patterns = array_merge( $patterns, $values );
		}
		self::assertCount( 198, $patterns );
		self::assertContains( '%literal\\_\\%\\\\quote\'%', $patterns );
		self::assertNotContains( '%https://example.test/uploads/1.png%', $patterns );
		foreach ( range( 1, 5 ) as $failure ) {
			$db->queries = array();
			$db->fail_query = $failure;
			$error = \Wstm121\Webmastery_MCP_Content_Hygiene::attachments_referenced( $attachments );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'content_hygiene_query_failed', $error->get_error_code() );
		}
	}
}
