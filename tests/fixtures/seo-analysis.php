<?php

function wstm108_seo_cases(): array {
	$yoast = 'WSTM108_YOAST';
	$seopress = 'WSTM108_SEOPRESS';
	$cases = array();
	foreach ( array(
		'yoast_found' => array( $yoast, $seopress, $yoast, 'yoast', true ),
		'yoast_missing' => array( $yoast, $seopress, $seopress, 'yoast', false ),
		'seopress_found' => array( '', $seopress, $seopress, 'seopress', true ),
		'seopress_missing' => array( '', $seopress, $yoast, 'seopress', false ),
		'no_keyword' => array( '', '', 'without any keyword', null, false ),
	) as $name => list( $yoast_value, $seopress_value, $title_suffix, $source, $found ) ) {
		$title = 'SEO analysis fixture title ' . $title_suffix;
		$good = array(
			array( 'check' => 'title_length', 'message' => 'Title length is good (' . strlen( $title ) . ' chars).' ),
			array( 'check' => 'word_count', 'message' => 'Content length is good (301 words).' ),
			array( 'check' => 'meta_description', 'message' => 'Meta description length is good (130 chars).' ),
		);
		$issues = array();
		if ( $found ) {
			$good[] = array( 'check' => 'keyword_in_title', 'message' => 'Focus keyword found in title.' );
		} else {
			$issues[] = array(
				'check' => null === $source ? 'focus_keyword' : 'keyword_in_title',
				'severity' => 'warn',
				'message' => null === $source ? 'No Yoast SEO focus keyphrase or SEOPress target keyword set.' : 'Focus keyword not found in title.',
			);
		}
		$good[] = array( 'check' => 'internal_links', 'message' => '1 internal link(s) found.' );
		$good[] = array( 'check' => 'slug_length', 'message' => 'Slug length is fine (11 chars).' );
		$cases[ $name ] = array(
			'title' => $title,
			'content' => str_repeat( 'word ', 300 ) . '<a href="/fixture">link</a>',
			'slug' => 'seo-fixture',
			'meta' => array(
				'_yoast_wpseo_focuskw' => $yoast_value,
				'_seopress_analysis_target_kw' => $seopress_value,
				'_yoast_wpseo_metadesc' => str_repeat( 'd', 130 ),
			),
			'expected' => array(
				'data.metrics.title' => $title,
				'data.metrics.yoast_focus_keyword' => $yoast_value,
				'data.metrics.seopress_focus_keywords' => $seopress_value,
				'data.metrics.seo_provider_focus_source' => $source,
				'data.good' => $good,
				'data.issues' => $issues,
				'data.score' => $found ? '6/6 checks passed' : '5/6 checks passed',
			),
		);
	}
	return $cases;
}
