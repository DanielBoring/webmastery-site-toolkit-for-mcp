<?php

declare(strict_types=1);

final class Wstm108_Plan {
	public const BOUNDARIES = array( 'gateway', 'individual' );
	public const ACTORS = array( 'administrator', 'subscriber', 'reader' );
	public const ANNOTATION_COUNT = 90;
	public const CASES_PER_BOUNDARY = 98;

	public static function native_from_manifest( string $path ): array {
		$cases = json_decode( file_get_contents( $path ), false, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $cases ) || array() === $cases || array_keys( $cases ) !== range( 0, count( $cases ) - 1 ) ) {
			throw new RuntimeException( 'WSTM108 Missing original typed manifest case list.' );
		}
		$names = array();
		foreach ( $cases as $case ) {
			if ( ! $case instanceof stdClass || ! is_string( $case->ability ?? null ) ) {
				throw new RuntimeException( 'WSTM108 Invalid typed manifest ability identity.' );
			}
			$names[] = $case->ability;
		}
		$names = array_values( array_unique( $names, SORT_STRING ) );
		sort( $names, SORT_STRING );
		self::abilities( $names );
		return $names;
	}

	public static function abilities( array $native ): array {
		if ( 85 !== count( $native ) || count( array_unique( $native, SORT_STRING ) ) !== count( $native ) ) {
			throw new RuntimeException( 'WSTM108 Native audit must contain exactly 85 unique abilities.' );
		}
		foreach ( $native as $name ) {
			if ( ! is_string( $name ) || 0 !== strpos( $name, 'webmastery-site-toolkit-for-mcp/' )
				|| false !== strpos( $name, '/wstm118-' ) || false !== strpos( $name, '-cpt-wstm108-record' ) ) {
				throw new RuntimeException( 'WSTM108 Native ability inventory contains a foreign or stage-only name.' );
			}
		}
		foreach ( array( 'list', 'get', 'create', 'update', 'delete' ) as $action ) {
			$native[] = 'webmastery-site-toolkit-for-mcp/' . $action . '-cpt-wstm108-record';
		}
		sort( $native, SORT_STRING );
		return $native;
	}

	public static function labels( array $native ): array {
		$labels = array_map( static fn( $name ) => 'annotations:' . $name, self::abilities( $native ) );
		foreach ( self::BOUNDARIES as $boundary ) {
			$cases = array();
			foreach ( array( 'post', 'page', 'wstm108_record' ) as $type ) {
				foreach ( array( 'get', 'list', 'update', 'create' ) as $operation ) {
					$cases[] = $type . ':' . $operation;
				}
			}
			$cases = array_merge( $cases, array( 'blocks', 'patch-content-block', 'patch-post-content:exact', 'patch-post-content:heading', 'post:revisions', 'page:revisions' ) );
			foreach ( array( 'media' => array( 'list', 'get', 'update' ), 'comments' => array( 'list', 'reply', 'update' ) ) as $type => $operations ) {
				foreach ( $operations as $operation ) {
					$cases[] = $type . ':' . $operation;
				}
			}
			foreach ( array( 'administrator', 'reader' ) as $actor ) {
				foreach ( array( 'list', 'get' ) as $operation ) {
					$cases[] = 'user:' . $actor . ':' . $operation;
				}
			}
			$cases = array_merge( $cases, array( 'user-access-audit', 'standalone-meta-read-update', 'standalone-meta-delete' ) );
			foreach ( array(
				'_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_canonical',
				'_yoast_wpseo_bctitle', '_yoast_wpseo_schema_page_type', '_yoast_wpseo_schema_article_type',
				'_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description', '_yoast_wpseo_opengraph-image',
				'_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description', '_yoast_wpseo_twitter-image',
				'_yoast_wpseo_linkdex', '_yoast_wpseo_content_score', '_yoast_wpseo_inclusive_language_score',
				'_yoast_wpseo_primary_category', '_yoast_wpseo_is_cornerstone', '_yoast_wpseo_meta-robots-noindex',
				'_yoast_wpseo_meta-robots-nofollow', '_yoast_wpseo_meta-robots-adv',
				'_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw', '_seopress_robots_canonical',
				'_seopress_social_fb_title', '_seopress_social_fb_desc', '_seopress_social_fb_img',
				'_seopress_social_twitter_title', '_seopress_social_twitter_desc', '_seopress_social_twitter_img',
				'_seopress_robots_primary_cat', '_seopress_robots_index', '_seopress_robots_follow',
				'_seopress_robots_imageindex', '_seopress_robots_archive', '_seopress_robots_snippet',
				'_seopress_robots_breadcrumbs', '_seopress_news_disabled', '_seopress_video_disabled',
			) as $key ) {
				$cases[] = 'seo-key-policy:' . $key;
			}
			$cases = array_merge( $cases, array(
				'seo-metrics', 'yoast:metadata', 'seopress:metadata', 'get-seo-scores', 'get-readability-scores',
				'seo-forbidden-read-trap:yoast_meta_description', 'seo-forbidden-read-trap:seopress_meta_description',
				'seo-forbidden-read-trap:yoast_focus_keyword', 'seo-forbidden-read-trap:seopress_focus_keywords',
				'no-opaque-head', 'no-combined-seo-alias-write', 'private-image-url-still-denied', 'seo-site-overview-records',
			) );
			foreach ( array(
				'get-post', 'update-post', 'get-page', 'get-cpt-wstm108-record', 'get-media', 'update-comment',
				'get-user', 'user-access-audit', 'get-post-meta', 'seo-analyze-post', 'get-yoast-metadata',
				'get-seopress-metadata', 'delete-post-meta', 'seo-site-overview',
			) as $name ) {
				$cases[] = 'subscriber-denied:' . $name;
			}
			if ( self::CASES_PER_BOUNDARY !== count( $cases ) ) {
				throw new RuntimeException( 'WSTM108 Frozen semantic case inventory differs.' );
			}
			$labels = array_merge( $labels, array_map( static fn( $label ) => $boundary . ':' . $label, $cases ) );
		}
		return $labels;
	}

	public static function validate_cases( array $cases, array $native ): void {
		if ( self::labels( $native ) !== array_column( $cases, 'label' ) ) {
			throw new RuntimeException( 'WSTM108 Missing, extra, duplicated, reordered or unrecognized case labels.' );
		}
		foreach ( $cases as $case ) {
			if ( ! array_key_exists( 'passed', $case ) || ! is_bool( $case['passed'] ) ) {
				throw new RuntimeException( 'WSTM108 Every planned case requires an actual boolean outcome.' );
			}
		}
	}
}
