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
	public function test_scores_filter_key_permissions_before_total_and_pagination( string $slug, string $key ): void {
		$this->seed_overview();
		for ( $id = 1; $id <= 101; $id++ ) {
			Probe::$metadata[ $id ][ $key ] = (string) $id;
			Probe::$denied_by_id[ $id ] = array( $key );
		}
		Probe::$denied_by_id[2] = Probe::$denied_by_id[4] = array();
		$result = $this->execute( $slug, array( 'per_page' => 1, 'page' => 2 ) );
		self::assertTrue( $result['success'] );
		self::assertSame( 2, $result['data']['total'] );
		self::assertSame( 2, $result['data']['total_pages'] );
		self::assertCount( 1, $result['data']['items'] );
		self::assertSame( 4, $result['data']['items'][0]['post_id'] );
		self::assertSame( 4, $result['data']['items'][0]['score'] );
		self::assertSame( array( array( 4, $key ) ), Probe::$reads );
	}

	public static function read_abilities(): array {
		return array(
			array( 'seo-analyze-post', array( 'post_id' => 42 ) ),
			array( 'get-yoast-metadata', array( 'post_id' => 42 ) ),
			array( 'get-yoast-metadata', array( 'url' => 'https://example.test/' ) ),
			array( 'get-seopress-metadata', array( 'post_id' => 42 ) ),
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
		Probe::$deny_base = true;
		$result = $this->execute( $slug, $input );
		self::assertFalse( $result['success'] );
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
		$result = $this->execute( $slug, array( 'post_id' => 42 ) );
		self::assertTrue( $result['success'] );
		self::assertNotContains( array( 42, $key ), Probe::$reads );
		self::assertContains( array( 'edit_post_meta', array( 42, $key ) ), Probe::$capabilities );
		self::assertNotEmpty( $result['data']['unavailable_fields'] );
	}
}
