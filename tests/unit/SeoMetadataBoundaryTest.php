<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm110Boundary\OpaqueHeadRead;
use Wstm110Boundary\Probe;
use Wstm110Boundary\UnauthorizedRead;

require_once dirname( __DIR__ ) . '/e2e/metadata-batch-fixture.php';
require_once __DIR__ . '/fixtures/metadata-boundary-stubs.php';

final class SeoMetadataBoundaryTest extends TestCase {
	private const OBSERVATIONS = array(
		'posts_missing_focus_keyword' => '_yoast_wpseo_focuskw',
		'posts_missing_meta_description' => '_yoast_wpseo_metadesc',
		'seopress_posts_missing_focus_keywords' => '_seopress_analysis_target_kw',
		'seopress_posts_missing_meta_description' => '_seopress_titles_desc',
	);

	private function execute( string $slug, array $input ): array {
		try {
			$result = Probe::execute( $slug, $input );
		} catch ( UnauthorizedRead | OpaqueHeadRead $error ) {
			self::fail( $error->getMessage() );
		}
		self::assertIsArray( $result );
		return $result;
	}

	private function seed_overview(): void {
		Probe::reset();
		for ( $id = 1; $id <= 101; $id++ ) {
			Probe::$posts[ $id ] = (object) array(
				'ID' => $id, 'post_type' => $id % 2 ? 'post' : 'page', 'post_status' => 'publish',
				'post_title' => 'Overview fixture', 'post_content' => '', 'post_name' => 'fixture-' . $id,
				'post_modified_gmt' => '2026-01-01 00:00:00',
			);
			foreach ( self::OBSERVATIONS as $key ) {
				Probe::$metadata[ $id ][ $key ] = 101 === $id ? '' : 'Present metadata';
			}
		}
	}

	private function assert_overview_budget( array $result ): void {
		self::assertSame( array(
			'mode' => 'sample', 'post_limit' => 100, 'ordering' => 'ID ASC', 'counts_are_sitewide' => false,
		), $result['data']['observation_scope'] );
		self::assertCount( 1, Probe::$queries );
		self::assertSame( 100, Probe::$queries[0]['posts_per_page'] );
		self::assertSame( 'ID', Probe::$queries[0]['orderby'] );
		self::assertSame( 'ASC', Probe::$queries[0]['order'] );
		self::assertSame( 'publish', Probe::$queries[0]['post_status'] );
		self::assertArrayNotHasKey( 'meta_query', Probe::$queries[0] );
		self::assertLessThanOrEqual( 400, count( Probe::$reads ) );
		self::assertLessThanOrEqual( 901, count( Probe::$capabilities ), 'Bound plugin-level checks, not WordPress internal map_meta_cap recursion.' );
		$key_checks = array_filter( Probe::$capabilities, static fn( $call ) => 'edit_post_meta' === $call[0] );
		self::assertLessThanOrEqual( 400, count( $key_checks ) );
		foreach ( Probe::$reads as $read ) {
			self::assertLessThanOrEqual( 100, $read[0] );
			self::assertContains( array( 'edit_post_meta', $read ), Probe::$capabilities );
			self::assertContains( array( 'edit_post', array( $read[0] ) ), Probe::$capabilities );
		}
		self::assertSame( array( 'posts' => 51, 'pages' => 50 ), $result['data']['total_published'] );
	}

	public function test_overview_excludes_101st_matching_post_and_reports_sample_not_sitewide_health(): void {
		$this->seed_overview();
		$result = $this->execute( 'seo-site-overview', array() );
		self::assertTrue( $result['success'] );
		$this->assert_overview_budget( $result );
		foreach ( self::OBSERVATIONS as $field => $key ) {
			self::assertSame( 0, $result['data'][ $field ]['count'] );
			self::assertSame( array(), $result['data'][ $field ]['ids'] );
			self::assertSame( 100, $result['data'][ $field ]['observed_count'] );
		}
	}

	public function test_overview_sparse_permissions_count_only_authorized_missing_observations(): void {
		$this->seed_overview();
		for ( $id = 1; $id <= 100; $id++ ) {
			Probe::$denied_by_id[ $id ] = array_values( self::OBSERVATIONS );
			Probe::$metadata[ $id ] = array_fill_keys( array_values( self::OBSERVATIONS ), '' );
		}
		Probe::$denied_by_id[2] = array();
		Probe::$denied_by_id[3] = array( '_yoast_wpseo_focuskw' );
		Probe::$denied_objects = array( 4 );
		Probe::$denied_by_id[4] = array();
		$result = $this->execute( 'seo-site-overview', array() );
		$this->assert_overview_budget( $result );
		foreach ( self::OBSERVATIONS as $field => $key ) {
			$expected = '_yoast_wpseo_focuskw' === $key ? array( 2 ) : array( 2, 3 );
			self::assertSame( $expected, $result['data'][ $field ]['ids'] );
			self::assertSame( count( $expected ), $result['data'][ $field ]['count'] );
			self::assertSame( count( $expected ), $result['data'][ $field ]['observed_count'] );
		}
	}

	public function test_overview_all_denied_reports_zero_observations_without_reading_or_false_health_claims(): void {
		$this->seed_overview();
		Probe::$denied_keys = array_values( self::OBSERVATIONS );
		$result = $this->execute( 'seo-site-overview', array() );
		$this->assert_overview_budget( $result );
		self::assertSame( array(), Probe::$reads );
		foreach ( self::OBSERVATIONS as $field => $key ) {
			self::assertSame( 0, $result['data'][ $field ]['observed_count'] );
			self::assertSame( 0, $result['data'][ $field ]['count'] );
			self::assertSame( array(), $result['data'][ $field ]['ids'] );
		}
		self::assertArrayNotHasKey( 'health', $result['data'] );
		self::assertArrayNotHasKey( 'score', $result['data'] );
	}

	public function test_overview_missing_ids_are_capped_without_capping_authorized_count(): void {
		$this->seed_overview();
		Probe::$metadata = array();
		$result = $this->execute( 'seo-site-overview', array() );
		$this->assert_overview_budget( $result );
		foreach ( self::OBSERVATIONS as $field => $key ) {
			self::assertSame( range( 1, 20 ), $result['data'][ $field ]['ids'] );
			self::assertSame( 100, $result['data'][ $field ]['count'] );
			self::assertSame( 100, $result['data'][ $field ]['observed_count'] );
		}
	}

	public function test_yoast_post_inspection_never_requests_opaque_generated_head(): void {
		Probe::reset();
		$result = $this->execute( 'get-yoast-metadata', array( 'post_id' => 42 ) );
		self::assertTrue( $result['success'] );
		self::assertSame( array(), Probe::$head_requests );
		self::assertFalse( $result['data']['generated_head']['available'] );
		self::assertSame( 'unsupported', $result['data']['generated_head']['error']['code'] );
		self::assertSame( 'key_authorization_unavailable', $result['data']['generated_head']['error']['reason'] );
		self::assertSame( array( 'title', 'url', 'metadata', 'raw_meta' ), $result['data']['untrusted_fields'] );
		self::assertArrayNotHasKey( 'untrusted_fields', $result['data']['generated_head'] );
		self::assertArrayNotHasKey( 'html', $result['data']['generated_head'] );
		self::assertArrayNotHasKey( 'json', $result['data']['generated_head'] );
	}

	public function test_yoast_url_inspection_is_explicitly_unsupported_without_opaque_fallback(): void {
		Probe::reset();
		$result = $this->execute( 'get-yoast-metadata', array( 'url' => 'https://example.test/archive/' ) );
		self::assertFalse( $result['success'] );
		self::assertSame( 'unsupported', $result['error']['code'] );
		self::assertSame( 'generated_head_key_authorization_unavailable', $result['error']['reason'] );
		self::assertSame( array(), Probe::$head_requests );
		self::assertSame( array(), Probe::$reads );
	}

	public static function score_keys(): array {
		return array(
			array( 'get-seo-scores', '_yoast_wpseo_linkdex' ),
			array( 'get-readability-scores', '_yoast_wpseo_content_score' ),
		);
	}

	/**
	 * @dataProvider score_keys
	 */
	public function test_scores_default_to_published_content_and_preserve_explicit_status_filters( string $slug, string $key ): void {
		$this->seed_overview();
		Probe::$posts = array_slice( Probe::$posts, 0, 6, true );
		foreach ( array( 'publish', 'draft', 'pending', 'private', 'future', 'publish' ) as $index => $status ) {
			$id = $index + 1;
			Probe::$posts[ $id ]->post_status = $status;
			Probe::$metadata[ $id ][ $key ] = (string) $id;
		}
		$ability = Probe::$abilities[ 'webmastery-site-toolkit-for-mcp/' . $slug ];
		self::assertSame( 'publish', $ability['input_schema']['properties']['status']['default'] );
		foreach ( array( '' => array( 1, 6 ), 'any' => range( 1, 6 ), 'draft' => array( 2 ), 'pending' => array( 3 ), 'private' => array( 4 ), 'future' => array( 5 ) ) as $status => $expected ) {
			$input = '' === $status ? array() : array( 'status' => $status );
			$result = $this->execute( $slug, $input );
			self::assertTrue( $result['success'] );
			self::assertSame( $expected, array_column( $result['data']['items'], 'post_id' ) );
			self::assertSame( '' === $status ? 'publish' : $status, end( Probe::$queries )['post_status'] );
			self::assertNull( $result['data']['next_page'] );
			self::assertArrayNotHasKey( 'total', $result['data'] );
			self::assertArrayNotHasKey( 'total_pages', $result['data'] );
		}
		foreach ( array( array(), array( 'status' => 'any' ), array( 'status' => 'private' ) ) as $input ) {
			Probe::$reads = array();
			Probe::$denied_objects = array( 1, 4 );
			Probe::$denied_by_id[6] = array( $key );
			$result = $this->execute( $slug, $input );
			self::assertSame( array(), array_intersect( array( 1, 4, 6 ), array_column( $result['data']['items'], 'post_id' ) ) );
			foreach ( Probe::$reads as $read ) {
				self::assertNotContains( $read[0], array( 1, 4, 6 ), 'Denied object/key must never be read.' );
			}
		}
		Probe::$deny_base = true;
		Probe::$queries = Probe::$reads = array();
		foreach ( array( array(), array( 'status' => 'any' ), array( 'status' => 'draft' ), array( 'status' => 'pending' ), array( 'status' => 'private' ) ) as $input ) {
			self::assertInstanceOf( WP_Error::class, ( $ability['permission_callback'] )( $input ) );
			$result = $this->execute( $slug, $input );
			self::assertFalse( $result['success'] );
			self::assertSame( 'forbidden', $result['error']['code'] );
		}
		self::assertSame( array(), Probe::$queries );
		self::assertSame( array(), Probe::$reads );
	}

	/**
	 * @dataProvider score_keys
	 */
	public function test_scores_filter_key_permissions_within_candidate_windows( string $slug, string $key ): void {
		$this->seed_overview();
		for ( $id = 1; $id <= 101; $id++ ) {
			Probe::$metadata[ $id ][ $key ] = (string) $id;
			Probe::$denied_by_id[ $id ] = array( $key );
		}
		Probe::$denied_by_id[2] = Probe::$denied_by_id[4] = array();
		$seen = array();
		for ( $page = 1; $page <= 101; ++$page ) {
			$result = $this->execute( $slug, array( 'per_page' => 1, 'page' => $page ) );
			self::assertTrue( $result['success'] );
			self::assertArrayNotHasKey( 'total', $result['data'] );
			self::assertArrayNotHasKey( 'total_pages', $result['data'] );
			self::assertSame( 101 === $page ? null : $page + 1, $result['data']['next_page'] );
			$items = $result['data']['items'];
			self::assertCount( in_array( $page, array( 2, 4 ), true ) ? 1 : 0, $items );
			foreach ( $items as $item ) {
				self::assertSame( $item['post_id'], $item['score'] );
				$seen[] = $item['post_id'];
			}
		}
		self::assertSame( array( 2, 4 ), $seen );
		self::assertSame( array( array( 2, $key ), array( 4, $key ) ), Probe::$reads );
		self::assertSame( array_fill( 0, 101, 2 ), array_column( Probe::$queries, 'posts_per_page' ) );
	}

	public static function read_abilities(): array {
		return array(
			array( 'seo-analyze-post', array( 'post_id' => 42 ) ),
			array( 'get-yoast-metadata', array( 'post_id' => 42 ) ),
			array( 'get-yoast-metadata', array( 'url' => 'https://example.test/' ) ),
			array( 'get-seopress-metadata', array( 'post_id' => 42 ) ),
			array( 'get-rankmath-metadata', array( 'post_id' => 42 ) ),
			array( 'get-aioseo-metadata', array( 'post_id' => 42 ) ),
			array( 'get-seo-scores', array() ),
			array( 'get-readability-scores', array() ),
			array( 'seo-site-overview', array() ),
		);
	}

	/**
	 * @dataProvider read_abilities
	 */
	public function test_direct_reads_enforce_base_permissions_before_metadata_work( string $slug, array $input ): void {
		Probe::reset();
		$wpdb = $this->aioseo_database( array( 'title' => 'WSTM_AIOSEO_FORBIDDEN' ) );
		Probe::$deny_base = true;
		$result = $this->execute( $slug, $input );
		self::assertFalse( $result['success'] );
		self::assertSame( array(), $wpdb->queries );
		self::assertSame( array(), Probe::$queries );
		self::assertSame( array(), Probe::$reads );
		self::assertSame( array(), Probe::$head_requests );
	}

	public static function provider_keys(): array {
		$keys = array();
		foreach ( wstm110_batch_aliases() as $definition ) {
			$keys[ $definition[0] ] = $definition[0];
		}
		foreach ( array( '_yoast_wpseo_linkdex', '_yoast_wpseo_content_score', '_yoast_wpseo_inclusive_language_score', '_yoast_wpseo_is_cornerstone', '_seopress_news_disabled', '_seopress_video_disabled' ) as $key ) {
			$keys[ $key ] = $key;
		}
		$cases = array();
		foreach ( $keys as $key ) {
			$cases[ $key ] = array( str_starts_with( $key, '_yoast_' ) ? 'get-yoast-metadata' : 'get-seopress-metadata', $key );
		}
		return $cases;
	}

	/**
	 * @dataProvider provider_keys
	 */
	public function test_provider_inspection_omits_denied_key_before_reading( string $slug, string $key ): void {
		Probe::reset();
		Probe::$denied_keys = array( $key );
		Probe::$metadata[42][ $key ] = 'WSTM110_FORBIDDEN_INERT_MARKER';
		$result = $this->execute( $slug, array( 'post_id' => 42 ) );
		self::assertTrue( $result['success'] );
		self::assertNotContains( array( 42, $key ), Probe::$reads );
		self::assertContains( array( 'edit_post_meta', array( 42, $key ) ), Probe::$capabilities );
		self::assertNotEmpty( $result['data']['unavailable_fields'] );
		self::assertSame( array( 'title', 'url', 'metadata', 'raw_meta' ), $result['data']['untrusted_fields'] );
		self::assertArrayNotHasKey( 'untrusted_fields', $result['data']['metadata'] );
		self::assertArrayNotHasKey( 'untrusted_fields', $result['data']['raw_meta'] );
		self::assertStringNotContainsString( 'WSTM110_FORBIDDEN_INERT_MARKER', json_encode( $result ) );
		foreach ( $result['data']['raw_meta'] as $field ) {
			self::assertNotSame( $key, $field['key'] );
		}
	}

	public function test_analysis_uses_authorized_fallback_without_reading_or_leaking_denied_primary(): void {
		Probe::reset();
		Probe::$denied_keys = array( '_yoast_wpseo_focuskw', '_yoast_wpseo_metadesc' );
		Probe::$metadata[42] = array(
			'_yoast_wpseo_focuskw' => 'WSTM110_HIDDEN_PRIMARY',
			'_yoast_wpseo_metadesc' => 'WSTM110_HIDDEN_DESCRIPTION',
			'_seopress_analysis_target_kw' => 'Original',
			'_seopress_titles_desc' => str_repeat( 'x', 130 ),
		);
		$result = $this->execute( 'seo-analyze-post', array( 'post_id' => 42 ) );
		self::assertSame( 'seopress', $result['data']['metrics']['seo_provider_focus_source'] );
		self::assertSame( 'seopress', $result['data']['metrics']['seo_provider_meta_source'] );
		self::assertSame( array(), $result['data']['unevaluable_checks'] );
		self::assertContains( 'keyword_in_title', array_column( $result['data']['good'], 'check' ) );
		self::assertContains( 'meta_description', array_column( $result['data']['good'], 'check' ) );
		self::assertStringNotContainsString( 'WSTM110_HIDDEN', json_encode( $result ) );
		self::assertArrayNotHasKey( 'yoast_focus_keyword', $result['data']['metrics'] );
		self::assertSame( array( 'title', 'url', 'slug', 'seopress_meta_description', 'seopress_focus_keywords' ), $result['data']['metrics']['untrusted_fields'] );
	}

	public function test_analysis_does_not_confuse_denied_data_with_missing_or_evaluate_its_score(): void {
		Probe::reset();
		Probe::$denied_keys = array_values( self::OBSERVATIONS );
		Probe::$metadata[42] = array_fill_keys( Probe::$denied_keys, 'WSTM110_HIDDEN' );
		$result = $this->execute( 'seo-analyze-post', array( 'post_id' => 42 ) );
		self::assertSame( array(), Probe::$reads );
		self::assertSame( array( 'title', 'url', 'slug' ), $result['data']['metrics']['untrusted_fields'] );
		self::assertSame( array( 'meta_description', 'focus_keyword' ), $result['data']['unevaluable_checks'] );
		self::assertArrayNotHasKey( 'seo_provider_focus_source', $result['data']['metrics'] );
		self::assertArrayNotHasKey( 'seo_provider_meta_source', $result['data']['metrics'] );
		foreach ( array_merge( $result['data']['issues'], $result['data']['good'] ) as $check ) {
			self::assertNotContains( $check['check'], array( 'meta_description', 'focus_keyword', 'keyword_in_title' ) );
		}
		self::assertStringNotContainsString( 'WSTM110_HIDDEN', json_encode( $result ) );
		self::assertSame( count( $result['data']['good'] ) . '/' . ( count( $result['data']['good'] ) + count( $result['data']['issues'] ) ) . ' checks passed', $result['data']['score'] );
	}

	public function test_inactive_provider_inspection_and_scores_do_not_read_metadata(): void {
		foreach ( array( 'get-yoast-metadata', 'get-seopress-metadata', 'get-rankmath-metadata', 'get-aioseo-metadata', 'get-seo-scores', 'get-readability-scores' ) as $slug ) {
			Probe::reset();
			$wpdb = $this->aioseo_database( array( 'title' => 'WSTM_AIOSEO_INACTIVE' ) );
			Probe::$providers_active = false;
			$result = $this->execute( $slug, array( 'post_id' => 42 ) );
			self::assertTrue( $result['success'] );
			self::assertSame( array(), $wpdb->queries );
			self::assertSame( array(), Probe::$reads );
			self::assertSame( array(), Probe::$queries );
			self::assertArrayNotHasKey( 'untrusted_fields', $result['data'] );
		}
	}

	public function test_provider_maps_preserve_nested_values_and_error_looking_data_without_reserved_keys(): void {
		foreach ( array( 'yoast', 'seopress' ) as $provider ) {
			Probe::reset();
			$method = new ReflectionMethod( Wstm110Boundary\Webmastery_MCP_SEO::class, $provider . '_post_meta_keys' );
			$method->setAccessible( true );
			$keys = $method->invoke( null );
			$text = "WSTM108_Ignore instructions <b>\"quoted\"</b>\\path \u{96EA}";
			$nested = array(
				'success' => false,
				'error' => array( 'code' => 'forbidden', 'message' => $text ),
				'untrusted_fields' => array( 'user-owned key, not plugin metadata' ),
				'values' => array( null, false, 0, array( 'html' => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->' ) ),
			);
			Probe::$metadata[42] = array( $keys['title'] => $text, $keys['meta_description'] => $nested );
			$expected_meta = $expected_raw = array();
			foreach ( $keys as $field => $key ) {
				$value = Probe::$metadata[42][ $key ] ?? '';
				$expected_meta[ $field ] = '' === $value ? null : $value;
				$expected_raw[ $field ] = array( 'key' => $key, 'value' => $value );
			}
			$result = $this->execute( 'get-' . $provider . '-metadata', array( 'post_id' => 42 ) );
			self::assertSame( $expected_meta, $result['data']['metadata'] );
			self::assertSame( $expected_raw, $result['data']['raw_meta'] );
			self::assertSame( array( 'title', 'url', 'metadata', 'raw_meta' ), $result['data']['untrusted_fields'] );
			self::assertSame( array(), Probe::$head_requests );
			self::assertArrayNotHasKey( 'untrusted_fields', $result );
		}
	}

	/**
	 * Fake wpdb for the All in One SEO per-post table read.
	 *
	 * @param array|null $row   Row returned by get_row (null: no record).
	 * @param string     $error Error to report after the read.
	 */
	private function aioseo_database( ?array $row, string $error = '' ): object {
		$wpdb = new class( $row, $error ) {
			public string $prefix = 'wp_';
			public string $last_error = '';
			public int $num_queries = 0;
			public array $queries = array();
			public array $suppress_calls = array();
			private bool $suppressed = false;
			private ?array $row;
			private string $error;

			public function __construct( ?array $row, string $error ) {
				$this->row = $row;
				$this->error = $error;
			}

			public function prepare( $query, ...$args ): string {
				return vsprintf( $query, $args ) . '|' . $query;
			}

			public function get_row( $query, $output ) {
				$this->queries[] = array( $query, $output, $this->suppressed );
				$this->last_error = $this->error;
				return '' === $this->error ? $this->row : null;
			}

			public function suppress_errors( $suppress = true ) {
				$this->suppress_calls[] = $suppress;
				$previous = $this->suppressed;
				$this->suppressed = (bool) $suppress;
				return $previous;
			}

			public function is_suppressed(): bool {
				return $this->suppressed;
			}
		};
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	public static function rankmath_keys(): array {
		$cases = array();
		foreach ( \Webmastery_MCP_Post_Meta::rankmath_keys() as $field => $key ) {
			$cases[ $key ] = array( $field, $key );
		}
		return $cases;
	}

	/**
	 * @dataProvider rankmath_keys
	 */
	public function test_rankmath_inspection_omits_denied_key_before_reading( string $field, string $key ): void {
		Probe::reset();
		Probe::$denied_keys = array( $key );
		Probe::$metadata[42][ $key ] = 'WSTM_RANKMATH_FORBIDDEN_MARKER';
		$result = $this->execute( 'get-rankmath-metadata', array( 'post_id' => 42 ) );
		self::assertTrue( $result['success'] );
		self::assertTrue( $result['data']['rankmath_active'] );
		self::assertNotContains( array( 42, $key ), Probe::$reads );
		self::assertContains( array( 'edit_post_meta', array( 42, $key ) ), Probe::$capabilities );
		self::assertSame( array( $field ), array_keys( $result['data']['unavailable_fields'] ) );
		self::assertSame( 'forbidden', $result['data']['unavailable_fields'][ $field ]['code'] );
		self::assertSame( 'metadata_not_readable', $result['data']['unavailable_fields'][ $field ]['reason'] );
		self::assertArrayNotHasKey( $field, $result['data']['metadata'] );
		if ( 'robots' === $field ) {
			self::assertArrayNotHasKey( 'robots_noindex', $result['data']['metadata'] );
		}
		self::assertSame( array( 'title', 'url', 'metadata' ), $result['data']['untrusted_fields'] );
		self::assertArrayNotHasKey( 'raw_meta', $result['data'] );
		self::assertStringNotContainsString( 'WSTM_RANKMATH_FORBIDDEN_MARKER', json_encode( $result ) );
	}

	public function test_rankmath_denied_post_and_missing_post_never_read_metadata(): void {
		Probe::reset();
		Probe::$denied_objects = array( 42 );
		$ability = Probe::$abilities['webmastery-site-toolkit-for-mcp/get-rankmath-metadata'];
		self::assertInstanceOf( WP_Error::class, ( $ability['permission_callback'] )( array( 'post_id' => 42 ) ) );
		$result = $this->execute( 'get-rankmath-metadata', array( 'post_id' => 42 ) );
		self::assertFalse( $result['success'] );
		self::assertSame( 'forbidden', $result['error']['code'] );
		Probe::$denied_objects = array();
		$result = $this->execute( 'get-rankmath-metadata', array( 'post_id' => 999 ) );
		self::assertFalse( $result['success'] );
		self::assertSame( 'not_found', $result['error']['code'] );
		Probe::reset( 'mcp_book' );
		$result = $this->execute( 'get-rankmath-metadata', array( 'post_id' => 42 ) );
		self::assertSame( 'not_found', $result['error']['code'] );
		self::assertSame( array(), Probe::$reads );
	}

	public function test_rankmath_normalizes_allowlisted_fields_and_never_reads_other_keys(): void {
		Probe::reset();
		$keys = \Webmastery_MCP_Post_Meta::rankmath_keys();
		Probe::$metadata[42] = array(
			$keys['title'] => str_repeat( 'T', 1500 ),
			$keys['meta_description'] => array( 'serialized' => 'WSTM_RANKMATH_BLOB' ),
			$keys['focus_keywords'] => 'alpha,beta',
			$keys['canonical_url'] => 'https://example.test/canonical/',
			$keys['robots'] => array( 'noindex', 'nofollow', 'WSTM_RANKMATH_BAD_DIRECTIVE', array( 'nested' ), 'noindex' ),
			$keys['seo_score'] => '87',
			$keys['pillar_content'] => 'on',
			$keys['primary_category'] => '12',
			$keys['opengraph_image'] => 'https://example.test/og.jpg',
			'rank_math_schema_Article' => 'WSTM_RANKMATH_SCHEMA_BODY',
			'rank_math_advanced_robots' => 'WSTM_RANKMATH_ADVANCED',
		);
		$result = $this->execute( 'get-rankmath-metadata', array( 'post_id' => 42 ) );
		$meta = $result['data']['metadata'];
		self::assertSame( 1000, mb_strlen( $meta['title'] ) );
		self::assertNull( $meta['meta_description'] );
		self::assertSame( 'alpha,beta', $meta['focus_keywords'] );
		self::assertSame( 'https://example.test/canonical/', $meta['canonical_url'] );
		self::assertSame( array( 'noindex', 'nofollow' ), $meta['robots'] );
		self::assertTrue( $meta['robots_noindex'] );
		self::assertTrue( $meta['robots_nofollow'] );
		self::assertFalse( $meta['robots_noarchive'] );
		self::assertSame( 87, $meta['seo_score'] );
		self::assertTrue( $meta['pillar_content'] );
		self::assertSame( 12, $meta['primary_category'] );
		self::assertSame( 'https://example.test/og.jpg', $meta['opengraph_image'] );
		self::assertNull( $meta['twitter_title'] );
		self::assertSame( array(), $result['data']['unavailable_fields'] );
		self::assertSame( array_values( $keys ), array_column( Probe::$reads, 1 ) );
		$json = json_encode( $result );
		foreach ( array( 'WSTM_RANKMATH_BLOB', 'WSTM_RANKMATH_BAD_DIRECTIVE', 'WSTM_RANKMATH_SCHEMA_BODY', 'WSTM_RANKMATH_ADVANCED', 'rank_math_' ) as $leak ) {
			self::assertStringNotContainsString( $leak, $json );
		}
		Probe::reset();
		Probe::$metadata[42] = array( $keys['robots'] => '', $keys['pillar_content'] => 'off', $keys['seo_score'] => 'n/a' );
		$meta = $this->execute( 'get-rankmath-metadata', array( 'post_id' => 42 ) )['data']['metadata'];
		self::assertNull( $meta['robots'] );
		self::assertNull( $meta['robots_noindex'] );
		self::assertFalse( $meta['pillar_content'] );
		self::assertNull( $meta['seo_score'] );
	}

	public function test_rankmath_keys_get_inspect_only_compatibility_default_and_stay_unwritable(): void {
		Probe::reset();
		$this->execute( 'get-rankmath-metadata', array( 'post_id' => 42 ) );
		$keys = \Webmastery_MCP_Post_Meta::rankmath_keys();
		self::assertSame( array_values( $keys ), array_map( static fn( $call ) => $call[1][1], Probe::$compat_reads ) );
		self::assertSame( array(), array_intersect( array_values( $keys ), array_keys( \Webmastery_MCP_Post_Meta::writable_keys() ) ) );
		self::assertSame( array(), $GLOBALS['wstm_test_filters']['auth_post_meta_rank_math_title_for_post'][10] ?? array() );
		Probe::reset();
		$this->execute( 'get-seopress-metadata', array( 'post_id' => 42 ) );
		self::assertSame( array(), Probe::$compat_reads );
	}

	public function test_aioseo_reads_one_allowlisted_row_without_post_meta_and_normalizes_it(): void {
		Probe::reset();
		$wpdb = $this->aioseo_database( array(
			'title' => '#post_title #separator_sa #site_title',
			'description' => str_repeat( 'D', 1200 ),
			'focus_keyword' => 'aioseo keyword',
			'keyphrases' => '{"focus":{"keyphrase":"WSTM_AIOSEO_LEGACY"}}',
			'canonical_url' => 'https://example.test/aioseo-canonical/',
			'og_title' => 'OG',
			'og_description' => '',
			'og_image_custom_url' => 'https://example.test/aioseo-og.jpg',
			'twitter_title' => null,
			'twitter_description' => array( 'WSTM_AIOSEO_NESTED' ),
			'twitter_image_custom_url' => 'https://example.test/aioseo-tw.jpg',
			'seo_score' => '64',
			'pillar_content' => '1',
			'primary_term' => '{"category":9,"product_cat":3}',
			'robots_default' => '0',
			'robots_noindex' => '1',
			'robots_nofollow' => '0',
			'robots_noarchive' => '1',
			'robots_noimageindex' => '0',
			'robots_nosnippet' => '0',
			'schema' => '{"graphs":["WSTM_AIOSEO_SCHEMA_BODY"]}',
			'options' => 'WSTM_AIOSEO_OPTIONS',
		) );
		$result = $this->execute( 'get-aioseo-metadata', array( 'post_id' => 42 ) );
		self::assertTrue( $result['success'] );
		self::assertCount( 1, $wpdb->queries );
		[ $query, $output, $suppressed ] = $wpdb->queries[0];
		self::assertSame( 'ARRAY_A', $output );
		self::assertTrue( $suppressed );
		self::assertFalse( $wpdb->is_suppressed() );
		self::assertStringContainsString( 'FROM `wp_aioseo_posts` WHERE post_id = 42 LIMIT 1|', $query );
		self::assertStringContainsString( 'WHERE post_id = %d LIMIT 1', $query );
		self::assertStringNotContainsString( '*', $query );
		foreach ( array( 'schema', 'options', 'page_analysis', 'images', 'videos', 'local_seo', 'ai' ) as $column ) {
			self::assertDoesNotMatchRegularExpression( '/[ ,`]' . $column . '[ ,]/', explode( 'FROM', $query )[0] );
		}
		self::assertSame( array(), Probe::$reads );
		$data = $result['data'];
		self::assertTrue( $data['aioseo_active'] );
		self::assertTrue( $data['record_found'] );
		self::assertSame( array(), $data['unavailable_fields'] );
		self::assertSame( array( 'title', 'url', 'metadata' ), $data['untrusted_fields'] );
		self::assertSame( array(
			'title' => '#post_title #separator_sa #site_title',
			'meta_description' => str_repeat( 'D', 1000 ),
			'canonical_url' => 'https://example.test/aioseo-canonical/',
			'opengraph_title' => 'OG',
			'opengraph_description' => null,
			'opengraph_image_custom_url' => 'https://example.test/aioseo-og.jpg',
			'twitter_title' => null,
			'twitter_description' => null,
			'twitter_image_custom_url' => 'https://example.test/aioseo-tw.jpg',
			'focus_keyword' => 'aioseo keyword',
			'seo_score' => 64,
			'pillar_content' => true,
			'primary_category' => 9,
			'robots_default' => false,
			'robots_noindex' => true,
			'robots_nofollow' => false,
			'robots_noarchive' => true,
			'robots_noimageindex' => false,
			'robots_nosnippet' => false,
		), $data['metadata'] );
		$json = json_encode( $result );
		foreach ( array( 'WSTM_AIOSEO_LEGACY', 'WSTM_AIOSEO_NESTED', 'WSTM_AIOSEO_SCHEMA_BODY', 'WSTM_AIOSEO_OPTIONS', 'product_cat' ) as $leak ) {
			self::assertStringNotContainsString( $leak, $json );
		}
	}

	public function test_aioseo_legacy_focus_keyphrase_fallback_and_missing_record(): void {
		Probe::reset();
		$this->aioseo_database( array( 'focus_keyword' => '', 'keyphrases' => '{"focus":{"keyphrase":"legacy phrase","analysis":{"x":1}},"additional":[{"keyphrase":"WSTM_AIOSEO_ADDITIONAL"}]}' ) );
		$result = $this->execute( 'get-aioseo-metadata', array( 'post_id' => 42 ) );
		self::assertSame( 'legacy phrase', $result['data']['metadata']['focus_keyword'] );
		self::assertStringNotContainsString( 'WSTM_AIOSEO_ADDITIONAL', json_encode( $result ) );
		self::assertNull( $result['data']['metadata']['robots_noindex'] );

		Probe::reset();
		$this->aioseo_database( null );
		$result = $this->execute( 'get-aioseo-metadata', array( 'post_id' => 42 ) );
		self::assertTrue( $result['success'] );
		self::assertFalse( $result['data']['record_found'] );
		self::assertSame( array(), array_filter( $result['data']['metadata'], static fn( $value ) => null !== $value ) );
	}

	public function test_aioseo_database_failure_is_an_error_without_query_details(): void {
		Probe::reset();
		$wpdb = $this->aioseo_database( array( 'title' => 'unused' ), "Unknown column 'focus_keyword' in 'field list'" );
		$result = $this->execute( 'get-aioseo-metadata', array( 'post_id' => 42 ) );
		self::assertFalse( $result['success'] );
		self::assertSame( 'upstream_failed', $result['error']['code'] );
		self::assertFalse( $wpdb->is_suppressed() );
		self::assertStringNotContainsString( 'focus_keyword', json_encode( $result ) );
		self::assertStringNotContainsString( 'aioseo_posts', json_encode( $result ) );
	}

	public function test_aioseo_denied_or_unsupported_post_never_queries_the_table(): void {
		Probe::reset();
		$wpdb = $this->aioseo_database( array( 'title' => 'WSTM_AIOSEO_FORBIDDEN' ) );
		Probe::$denied_objects = array( 42 );
		$result = $this->execute( 'get-aioseo-metadata', array( 'post_id' => 42 ) );
		self::assertSame( 'forbidden', $result['error']['code'] );
		Probe::$denied_objects = array();
		self::assertSame( 'not_found', $this->execute( 'get-aioseo-metadata', array( 'post_id' => 999 ) )['error']['code'] );
		Probe::reset( 'attachment' );
		$GLOBALS['wpdb'] = $wpdb;
		self::assertSame( 'not_found', $this->execute( 'get-aioseo-metadata', array( 'post_id' => 42 ) )['error']['code'] );
		self::assertSame( array(), $wpdb->queries );
		self::assertStringNotContainsString( 'WSTM_AIOSEO_FORBIDDEN', json_encode( $result ) );
	}

	public function test_new_provider_abilities_share_the_closed_post_id_contract(): void {
		Probe::reset();
		$seopress = Probe::$abilities['webmastery-site-toolkit-for-mcp/get-seopress-metadata'];
		foreach ( array( 'get-rankmath-metadata', 'get-aioseo-metadata' ) as $slug ) {
			$ability = Probe::$abilities[ 'webmastery-site-toolkit-for-mcp/' . $slug ];
			self::assertSame( $seopress['input_schema'], $ability['input_schema'] );
			self::assertSame( $seopress['meta'], $ability['meta'] );
		}
	}
}
