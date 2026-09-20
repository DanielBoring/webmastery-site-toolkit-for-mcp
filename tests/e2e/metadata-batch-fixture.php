<?php
/**
 * Disposable metadata-batch inputs and state oracle; never a production plugin.
 */

defined( 'ABSPATH' ) || exit;

function wstm110_batch_aliases(): array {
	return array(
		'yoast_meta_description' => array( '_yoast_wpseo_metadesc', 'description' ),
		'yoast_focus_keyword' => array( '_yoast_wpseo_focuskw', 'keyword' ),
		'yoast_seo_title' => array( '_yoast_wpseo_title', 'title' ),
		'yoast_canonical_url' => array( '_yoast_wpseo_canonical', 'https://example.test/canonical/' ),
		'yoast_breadcrumb_title' => array( '_yoast_wpseo_bctitle', 'breadcrumb' ),
		'yoast_schema_page_type' => array( '_yoast_wpseo_schema_page_type', 'WebPage' ),
		'yoast_schema_article_type' => array( '_yoast_wpseo_schema_article_type', 'Article' ),
		'yoast_opengraph_title' => array( '_yoast_wpseo_opengraph-title', 'open graph title' ),
		'yoast_opengraph_description' => array( '_yoast_wpseo_opengraph-description', 'open graph description' ),
		'yoast_opengraph_image' => array( '_yoast_wpseo_opengraph-image', 'https://example.test/og.jpg' ),
		'yoast_twitter_title' => array( '_yoast_wpseo_twitter-title', 'twitter title' ),
		'yoast_twitter_description' => array( '_yoast_wpseo_twitter-description', 'twitter description' ),
		'yoast_twitter_image' => array( '_yoast_wpseo_twitter-image', 'https://example.test/twitter.jpg' ),
		'yoast_primary_category' => array( '_yoast_wpseo_primary_category', 7 ),
		'yoast_robots_noindex' => array( '_yoast_wpseo_meta-robots-noindex', false ),
		'yoast_robots_nofollow' => array( '_yoast_wpseo_meta-robots-nofollow', true ),
		'yoast_robots_advanced' => array( '_yoast_wpseo_meta-robots-adv', 'noarchive' ),
		'seopress_meta_description' => array( '_seopress_titles_desc', 'description' ),
		'seopress_focus_keywords' => array( '_seopress_analysis_target_kw', 'keyword' ),
		'seopress_seo_title' => array( '_seopress_titles_title', 'title' ),
		'seopress_canonical_url' => array( '_seopress_robots_canonical', 'https://example.test/canonical/' ),
		'seopress_opengraph_title' => array( '_seopress_social_fb_title', 'open graph title' ),
		'seopress_opengraph_description' => array( '_seopress_social_fb_desc', 'open graph description' ),
		'seopress_opengraph_image' => array( '_seopress_social_fb_img', 'https://example.test/og.jpg' ),
		'seopress_twitter_title' => array( '_seopress_social_twitter_title', 'twitter title' ),
		'seopress_twitter_description' => array( '_seopress_social_twitter_desc', 'twitter description' ),
		'seopress_twitter_image' => array( '_seopress_social_twitter_img', 'https://example.test/twitter.jpg' ),
		'seopress_primary_category' => array( '_seopress_robots_primary_cat', 7 ),
		'seopress_robots_noindex' => array( '_seopress_robots_index', true ),
		'seopress_robots_nofollow' => array( '_seopress_robots_follow', false ),
		'seopress_robots_noimageindex' => array( '_seopress_robots_imageindex', true ),
		'seopress_robots_noarchive' => array( '_seopress_robots_archive', true ),
		'seopress_robots_nosnippet' => array( '_seopress_robots_snippet', true ),
		'seopress_breadcrumb_title' => array( '_seopress_robots_breadcrumbs', 'breadcrumb' ),
	);
}

function wstm110_batch_payloads(): array {
	$payloads = array(
		'meta empty object' => array( 'meta' => (object) array() ),
		'meta empty array' => array( 'meta' => array() ),
		'meta null' => array( 'meta' => null ),
		'meta public restricted key' => array( 'meta' => array( 'wstm110_restricted' => 'changed' ) ),
		'meta public open key' => array( 'meta' => array( 'wstm110_open' => 'changed' ) ),
		'meta mixed providers' => array( 'meta' => array( '_yoast_wpseo_title' => 'changed', '_seopress_titles_title' => 'changed' ) ),
		'meta_input null' => array( 'meta_input' => null ),
		'raw Yoast key' => array( '_yoast_wpseo_title' => 'changed' ),
		'raw SEOPress key' => array( '_seopress_titles_title' => 'changed' ),
		'unknown Yoast alias' => array( 'yoast_unknown_field' => null ),
		'unknown SEOPress alias' => array( 'seopress_unknown_field' => null ),
	);
	foreach ( wstm110_batch_aliases() as $alias => $definition ) {
		foreach ( array( 'value' => $definition[1], 'null' => null, 'empty array' => array(), 'empty string' => '' ) as $label => $value ) {
			$payloads[ "{$alias} {$label}" ] = array( $alias => $value );
		}
	}
	return $payloads;
}

function wstm110_batch_snapshot(): array {
	global $wpdb;
	$snapshot = array();
	foreach ( array(
		'posts' => 'ID',
		'postmeta' => 'meta_id',
		'terms' => 'term_id',
		'term_taxonomy' => 'term_taxonomy_id',
		'term_relationships' => 'object_id, term_taxonomy_id',
	) as $table => $order ) {
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->$table} ORDER BY {$order}", ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( "Cannot snapshot metadata-batch {$table}: {$wpdb->last_error}" );
		}
		$snapshot[ $table ] = array( 'count' => count( $rows ), 'sha256' => hash( 'sha256', serialize( $rows ) ) );
	}
	$cron = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron'" );
	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( "Cannot snapshot metadata-batch cron: {$wpdb->last_error}" );
	}
	$snapshot['cron'] = hash( 'sha256', serialize( $cron ) );
	return $snapshot;
}

function wstm110_batch_mutation_hooks(): array {
	return array(
		'wp_insert_post_parent', 'wp_insert_post_data', 'pre_post_update', 'post_updated', 'wp_insert_post', 'save_post', 'wp_after_insert_post',
		'transition_post_status', 'before_delete_post', 'delete_post', 'deleted_post',
		'add_post_metadata', 'update_post_metadata', 'delete_post_metadata',
		'added_post_meta', 'updated_post_meta', 'deleted_post_meta',
		'pre_insert_term', 'create_term', 'created_term', 'edit_terms', 'edited_term',
		'add_term_relationship', 'added_term_relationship', 'set_object_terms',
		'delete_term_relationships', 'deleted_term_relationships',
		'pre_schedule_event', 'pre_unschedule_event',
	);
}

function wstm110_batch_observe( callable $record ): Closure {
	$observer = static function ( $value = null ) use ( $record ) {
		$record( current_filter() );
		return $value;
	};
	foreach ( wstm110_batch_mutation_hooks() as $hook ) {
		add_filter( $hook, $observer, PHP_INT_MIN, 1 );
	}
	return $observer;
}

function wstm110_batch_unobserve( Closure $observer ): void {
	foreach ( wstm110_batch_mutation_hooks() as $hook ) {
		remove_filter( $hook, $observer, PHP_INT_MIN );
	}
}

function wstm110_batch_assert_unchanged( array $before, array $after, array $events ): void {
	if ( $before !== $after ) {
		throw new RuntimeException( 'Rejected metadata batch changed persisted state.' );
	}
	if ( array() !== $events ) {
		throw new RuntimeException( 'Rejected metadata batch reached a mutation hook.' );
	}
}
