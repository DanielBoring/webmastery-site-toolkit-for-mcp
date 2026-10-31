<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/seo-stubs.php';
require_once dirname( __DIR__ ) . '/fixtures/seo-analysis.php';

final class SeoAnalysisTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wstm_test_posts'], $GLOBALS['wstm_test_stored_meta'], $GLOBALS['wstm_test_user_caps'], $GLOBALS['wstm_test_seo_home'] );
	}

	public static function cases(): array {
		return array_map( static function ( $case ) { return array( $case ); }, wstm108_seo_cases() );
	}

	private function analyze( array $case ): array {
		$GLOBALS['wstm_test_user_caps'] = array( 'edit_post', 'edit_post_meta' );
		$GLOBALS['wstm_test_posts'] = array( 42 => (object) array(
			'ID' => 42, 'post_type' => 'post',
			'post_title' => $case['title'], 'post_content' => $case['content'], 'post_name' => $case['slug'],
		) );
		$GLOBALS['wstm_test_stored_meta'] = array( 42 => $case['meta'] );
		return Wstm108\Webmastery_MCP_SEO::execute_analyze_post( array( 'post_id' => 42 ) );
	}

	/** @dataProvider cases */
	public function test_stored_keywords_are_data_not_diagnostics( array $case ): void {
		$result = $this->analyze( $case );
		$this->assertSame( array( 'success', 'data' ), array_keys( $result ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 'post_id', 'metrics', 'issues', 'good', 'score' ), array_keys( $result['data'] ) );
		$this->assertSame( 42, $result['data']['post_id'] );
		$this->assertSame( array( 'title', 'url', 'word_count', 'title_length', 'yoast_meta_description', 'seopress_meta_description', 'seo_provider_meta_source', 'seo_plugins', 'yoast_focus_keyword', 'seopress_focus_keywords', 'seo_provider_focus_source', 'images_without_alt', 'internal_links', 'external_links', 'slug', 'untrusted_fields' ), array_keys( $result['data']['metrics'] ) );
		$this->assertSame( array( 'title', 'url', 'slug', 'yoast_meta_description', 'seopress_meta_description', 'yoast_focus_keyword', 'seopress_focus_keywords' ), $result['data']['metrics']['untrusted_fields'] );
		foreach ( $case['expected'] as $path => $expected ) {
			$actual = $result;
			foreach ( explode( '.', $path ) as $key ) {
				$this->assertArrayHasKey( $key, $actual );
				$actual = $actual[ $key ];
			}
			$this->assertSame( $expected, $actual, $path );
		}
		foreach ( array_merge( $result['data']['issues'], $result['data']['good'] ) as $diagnostic ) {
			$this->assertStringNotContainsString( 'WSTM108_', $diagnostic['message'] );
		}
	}

	public static function links(): array {
		$cases = array();
		foreach ( array( 'https://www.example.test', 'http://example.test/blog' ) as $home ) {
			foreach ( array(
				'https://example.test/another-post' => array( 1, 0 ),
				'http://www.example.test/another-post' => array( 1, 0 ),
				'HTTPS://WWW.EXAMPLE.TEST/another-post' => array( 1, 0 ),
				'https://example.test:8443/another-post' => array( 1, 0 ),
				'//example.test/another-post' => array( 1, 0 ),
				'//WWW.EXAMPLE.TEST/another-post' => array( 1, 0 ),
				'/another-post' => array( 1, 0 ),
				'../another-post' => array( 1, 0 ),
				'another-post' => array( 1, 0 ),
				'?page=2' => array( 1, 0 ),
				'#section' => array( 1, 0 ),
				'  https://example.test/path  ' => array( 1, 0 ),
				'//cdn.other.test/file.js' => array( 0, 1 ),
				'https://other.test/path' => array( 0, 1 ),
				'https://www.example.test.other.test/path' => array( 0, 1 ),
				'https://example.test.other.test/path' => array( 0, 1 ),
				'https://sub.example.test/path' => array( 0, 1 ),
				'https://example.test@other.test/path' => array( 0, 1 ),
				'&#47;&#47;other.test/path' => array( 0, 1 ),
				'mailto:contact@example.test' => array( 0, 0 ),
				'tel:+15555550123' => array( 0, 0 ),
				'javascript:void(0)' => array( 0, 0 ),
				'java&#115;cript:void(0)' => array( 0, 0 ),
				'ftp://example.test/file' => array( 0, 0 ),
				'http:relative' => array( 0, 0 ),
				'https://other.test:bad/path' => array( 0, 0 ),
				'//' => array( 0, 0 ),
				'' => array( 0, 0 ),
				'   ' => array( 0, 0 ),
			) as $href => $counts ) {
				$cases[ $home . ' ' . $href ] = array( $home, $href, $counts[0], $counts[1] );
			}
		}
		return $cases;
	}

	/** @dataProvider links */
	public function test_web_links_use_site_host_membership( string $home, string $href, int $internal, int $external ): void {
		$GLOBALS['wstm_test_seo_home'] = $home;
		$case = wstm108_seo_cases()['no_keyword'];
		$case['content'] = '<a href="' . $href . '">link</a>';
		$data = $this->analyze( $case )['data'];
		$this->assertSame( $internal, $data['metrics']['internal_links'] );
		$this->assertSame( $external, $data['metrics']['external_links'] );
		$checks = array_column( array_merge( $data['issues'], $data['good'] ), null, 'check' );
		$this->assertSame( $internal ? '1 internal link(s) found.' : 'No internal links found. Internal links help with crawlability.', $checks['internal_links']['message'] );
	}

	public function test_mixed_link_fixture_preserves_metrics_and_findings(): void {
		$GLOBALS['wstm_test_seo_home'] = 'https://www.example.test';
		$this->test_stored_keywords_are_data_not_diagnostics( wstm201_seo_link_case( $GLOBALS['wstm_test_seo_home'] ) );
	}

	public static function raw_keywords(): array {
		$values = array();
		foreach ( array( '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw' ) as $key ) {
			foreach ( array( true, false ) as $found ) {
				$values[] = array( $key, $found );
			}
		}
		return $values;
	}

	/** @dataProvider raw_keywords */
	public function test_raw_keyword_bytes_are_not_stripped_or_escaped( string $key, bool $found ): void {
		$marker = 'WSTM108_<b>"inert"</b>\\path';
		$case = wstm108_seo_cases()['no_keyword'];
		$case['meta'][ $key ] = $marker;
		$case['title'] = $found ? 'SEO analysis fixture title ' . $marker : $case['title'];
		$result = $this->analyze( $case )['data'];
		$metric = '_yoast_wpseo_focuskw' === $key ? 'yoast_focus_keyword' : 'seopress_focus_keywords';
		$this->assertSame( $marker, $result['metrics'][ $metric ] );
		$this->assertSame( $case['title'], $result['metrics']['title'] );
		$this->assertSame( $found ? '6/6 checks passed' : '5/6 checks passed', $result['score'] );
		$checks = array_column( array_merge( $result['issues'], $result['good'] ), null, 'check' );
		$this->assertSame(
			$found ? array( 'check' => 'keyword_in_title', 'message' => 'Focus keyword found in title.' ) : array( 'check' => 'keyword_in_title', 'severity' => 'warn', 'message' => 'Focus keyword not found in title.' ),
			$checks['keyword_in_title']
		);
		foreach ( $checks as $diagnostic ) {
			$this->assertStringNotContainsString( 'WSTM108_', $diagnostic['message'] );
		}
	}
}
