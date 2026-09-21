<?php
/**
 * Disposable SEO read oracle; independent field inventory and forbidden-read trap.
 */

defined( 'ABSPATH' ) || exit;

function wstm110_seo_fields(): array {
	return array(
		'get-yoast-metadata' => array(
			'title' => '_yoast_wpseo_title', 'meta_description' => '_yoast_wpseo_metadesc',
			'focus_keyphrase' => '_yoast_wpseo_focuskw', 'canonical_url' => '_yoast_wpseo_canonical',
			'breadcrumb_title' => '_yoast_wpseo_bctitle', 'schema_page_type' => '_yoast_wpseo_schema_page_type',
			'schema_article_type' => '_yoast_wpseo_schema_article_type', 'opengraph_title' => '_yoast_wpseo_opengraph-title',
			'opengraph_description' => '_yoast_wpseo_opengraph-description', 'opengraph_image' => '_yoast_wpseo_opengraph-image',
			'twitter_title' => '_yoast_wpseo_twitter-title', 'twitter_description' => '_yoast_wpseo_twitter-description',
			'twitter_image' => '_yoast_wpseo_twitter-image', 'seo_score' => '_yoast_wpseo_linkdex',
			'readability_score' => '_yoast_wpseo_content_score', 'inclusive_language_score' => '_yoast_wpseo_inclusive_language_score',
			'primary_category' => '_yoast_wpseo_primary_category', 'cornerstone' => '_yoast_wpseo_is_cornerstone',
			'robots_noindex' => '_yoast_wpseo_meta-robots-noindex', 'robots_nofollow' => '_yoast_wpseo_meta-robots-nofollow',
			'robots_advanced' => '_yoast_wpseo_meta-robots-adv',
		),
		'get-seopress-metadata' => array(
			'title' => '_seopress_titles_title', 'meta_description' => '_seopress_titles_desc',
			'focus_keywords' => '_seopress_analysis_target_kw', 'canonical_url' => '_seopress_robots_canonical',
			'opengraph_title' => '_seopress_social_fb_title', 'opengraph_description' => '_seopress_social_fb_desc',
			'opengraph_image' => '_seopress_social_fb_img', 'twitter_title' => '_seopress_social_twitter_title',
			'twitter_description' => '_seopress_social_twitter_desc', 'twitter_image' => '_seopress_social_twitter_img',
			'primary_category' => '_seopress_robots_primary_cat', 'robots_noindex' => '_seopress_robots_index',
			'robots_nofollow' => '_seopress_robots_follow', 'robots_noimageindex' => '_seopress_robots_imageindex',
			'robots_noarchive' => '_seopress_robots_archive', 'robots_nosnippet' => '_seopress_robots_snippet',
			'breadcrumb_title' => '_seopress_robots_breadcrumbs', 'news_sitemap_disabled' => '_seopress_news_disabled',
			'video_sitemap_disabled' => '_seopress_video_disabled',
		),
	);
}

add_filter( 'get_post_metadata', static function ( $value, $id, $key ) {
	$probe = get_option( 'wstm110_seo_read_probe', array() );
	if ( (int) ( $probe['id'] ?? 0 ) !== (int) $id || ( $probe['key'] ?? null ) !== $key ) {
		return $value;
	}
	$reads = get_option( 'wstm110_seo_read_events', array() );
	$reads[] = array( $id, $key );
	update_option( 'wstm110_seo_read_events', $reads, false );
	if ( ! empty( $probe['forbidden'] ) ) {
		throw new RuntimeException( 'SEO attempted to read an unauthorized fixture metadata key.' );
	}
	return $value;
}, PHP_INT_MIN, 3 );
