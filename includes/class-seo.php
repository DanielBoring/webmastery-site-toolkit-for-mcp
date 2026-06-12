<?php

defined( 'ABSPATH' ) || exit;

class Unlock_MCP_SEO {

	public static function register() {
		self::register_analyze_post();
		self::register_site_overview();
	}

	public static function permission_analyze_post( $input = [] ) {
		$id   = absint( $input['post_id'] ?? 0 );
		$post = get_post( $id );

		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', 'Requires edit_post capability for this post.' );
		}
		return true;
	}

	private static function register_analyze_post() {
		wp_register_ability( 'wp-mcp/seo-analyze-post', [
			'label'               => 'SEO: Analyze Post',
			'description'         => 'Analyze a post or page for SEO best practices and return findings.',
			'category'            => 'wp-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'Post or page ID to analyze' ],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => [ self::class, 'execute_analyze_post' ],
			'permission_callback' => [ self::class, 'permission_analyze_post' ],
			'meta' => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute_analyze_post( $input = [] ) {
		$id   = absint( $input['post_id'] );
		$post = get_post( $id );

		if ( ! $post ) {
			return [ 'success' => false, 'error' => 'Post not found.' ];
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return [ 'success' => false, 'error' => 'You do not have permission to analyze this post.' ];
		}

		$issues = [];
		$good   = [];
		$data   = [];

		$title         = $post->post_title;
		$content       = $post->post_content;
		$plain_content = wp_strip_all_tags( $content );
		$word_count    = str_word_count( $plain_content );
		$title_len     = mb_strlen( $title );

		$data['title']        = $title;
		$data['url']          = get_permalink( $id );
		$data['word_count']   = $word_count;
		$data['title_length'] = $title_len;

		// Title length
		if ( $title_len < 30 ) {
			$issues[] = [ 'check' => 'title_length', 'severity' => 'warn', 'message' => "Title is too short ({$title_len} chars). Aim for 50–60 characters." ];
		} elseif ( $title_len > 60 ) {
			$issues[] = [ 'check' => 'title_length', 'severity' => 'warn', 'message' => "Title is too long ({$title_len} chars). Search engines truncate after ~60 characters." ];
		} else {
			$good[] = [ 'check' => 'title_length', 'message' => "Title length is good ({$title_len} chars)." ];
		}

		// Word count
		if ( $word_count < 300 ) {
			$issues[] = [ 'check' => 'word_count', 'severity' => 'warn', 'message' => "Content is thin ({$word_count} words). Aim for 300+ words." ];
		} else {
			$good[] = [ 'check' => 'word_count', 'message' => "Content length is good ({$word_count} words)." ];
		}

		// Yoast meta description
		$meta_desc                      = get_post_meta( $id, '_yoast_wpseo_metadesc', true );
		$data['yoast_meta_description'] = $meta_desc;
		if ( empty( $meta_desc ) ) {
			$issues[] = [ 'check' => 'meta_description', 'severity' => 'warn', 'message' => 'No Yoast meta description set.' ];
		} else {
			$desc_len = mb_strlen( $meta_desc );
			if ( $desc_len < 120 || $desc_len > 160 ) {
				$issues[] = [ 'check' => 'meta_description', 'severity' => 'info', 'message' => "Meta description is {$desc_len} chars. Ideal range is 120–160." ];
			} else {
				$good[] = [ 'check' => 'meta_description', 'message' => "Meta description length is good ({$desc_len} chars)." ];
			}
		}

		// Yoast focus keyword
		$focus_kw                    = get_post_meta( $id, '_yoast_wpseo_focuskw', true );
		$data['yoast_focus_keyword'] = $focus_kw;
		if ( empty( $focus_kw ) ) {
			$issues[] = [ 'check' => 'focus_keyword', 'severity' => 'warn', 'message' => 'No Yoast focus keyword set.' ];
		} elseif ( false !== stripos( $title, $focus_kw ) ) {
			$good[] = [ 'check' => 'keyword_in_title', 'message' => "Focus keyword \"{$focus_kw}\" found in title." ];
		} else {
			$issues[] = [ 'check' => 'keyword_in_title', 'severity' => 'warn', 'message' => "Focus keyword \"{$focus_kw}\" not found in title." ];
		}

		// Images without alt text
		$images_without_alt         = self::count_images_without_alt( $content );
		$data['images_without_alt'] = $images_without_alt;
		if ( $images_without_alt > 0 ) {
			$issues[] = [ 'check' => 'image_alt', 'severity' => 'warn', 'message' => "{$images_without_alt} image(s) missing alt text." ];
		} elseif ( preg_match_all( '/<img\s/i', $content ) ) {
			$good[] = [ 'check' => 'image_alt', 'message' => 'All images have alt text.' ];
		}

		// Link counts
		$internal_links         = self::count_links( $content, home_url() );
		$external_links         = self::count_links( $content, home_url(), true );
		$data['internal_links'] = $internal_links;
		$data['external_links'] = $external_links;

		if ( 0 === $internal_links ) {
			$issues[] = [ 'check' => 'internal_links', 'severity' => 'info', 'message' => 'No internal links found. Internal links help with crawlability.' ];
		} else {
			$good[] = [ 'check' => 'internal_links', 'message' => "{$internal_links} internal link(s) found." ];
		}

		// Slug length
		$slug         = $post->post_name;
		$slug_len     = mb_strlen( $slug );
		$data['slug'] = $slug;
		if ( $slug_len > 75 ) {
			$issues[] = [ 'check' => 'slug_length', 'severity' => 'info', 'message' => "Slug is long ({$slug_len} chars). Shorter slugs are generally better." ];
		} else {
			$good[] = [ 'check' => 'slug_length', 'message' => "Slug length is fine ({$slug_len} chars)." ];
		}

		return [
			'success' => true,
			'data'    => [
				'post_id' => $id,
				'metrics' => $data,
				'issues'  => $issues,
				'good'    => $good,
				'score'   => count( $good ) . '/' . ( count( $good ) + count( $issues ) ) . ' checks passed',
			],
		];
	}

	private static function count_images_without_alt( $content ) {
		preg_match_all( '/<img\s[^>]*>/i', $content, $matches );
		$count = 0;
		foreach ( $matches[0] as $img ) {
			if ( ! preg_match( '/\balt\s*=\s*"[^"]+"/i', $img ) && ! preg_match( "/\\balt\\s*=\\s*'[^']+'/i", $img ) ) {
				++$count;
			}
		}
		return $count;
	}

	private static function count_links( $content, $home, $external = false ) {
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
		$count = 0;
		foreach ( $matches[1] as $href ) {
			$is_internal = str_starts_with( $href, $home ) || str_starts_with( $href, '/' );
			if ( $external ? ! $is_internal : $is_internal ) {
				++$count;
			}
		}
		return $count;
	}

	private static function register_site_overview() {
		wp_register_ability( 'wp-mcp/seo-site-overview', [
			'label'               => 'SEO: Site Overview',
			'description'         => 'Get a site-level SEO overview: sitemap, robots.txt, and posts missing Yoast optimization.',
			'category'            => 'wp-mcp',
			'execute_callback'    => [ self::class, 'execute_site_overview' ],
			'permission_callback' => function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'forbidden', 'Requires manage_options capability.' );
				}
				return true;
			},
			'meta' => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute_site_overview( $input = [] ) {
		$data = [];

		// Sitemap
		$sitemap_url      = home_url( '/sitemap_index.xml' );
		$sitemap_response = wp_remote_head( $sitemap_url, [ 'timeout' => 5 ] );
		$sitemap_ok       = ! is_wp_error( $sitemap_response ) && wp_remote_retrieve_response_code( $sitemap_response ) === 200;
		$data['sitemap']  = [ 'url' => $sitemap_url, 'accessible' => $sitemap_ok ];

		// Robots.txt
		$robots_url         = home_url( '/robots.txt' );
		$robots_response    = wp_remote_head( $robots_url, [ 'timeout' => 5 ] );
		$robots_ok          = ! is_wp_error( $robots_response ) && wp_remote_retrieve_response_code( $robots_response ) === 200;
		$data['robots_txt'] = [ 'url' => $robots_url, 'accessible' => $robots_ok ];

		// Published posts missing Yoast focus keyword
		$no_keyword                          = new WP_Query( [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Querying Yoast fields; only runs on explicit admin request.
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => [
				'relation' => 'OR',
				[
					'key'     => '_yoast_wpseo_focuskw',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_yoast_wpseo_focuskw',
					'value'   => '',
					'compare' => '=',
				],
			],
			'fields' => 'ids',
		] );
		$data['posts_missing_focus_keyword'] = [
			'count' => $no_keyword->found_posts,
			'ids'   => array_slice( $no_keyword->posts, 0, 20 ),
		];

		// Posts with no Yoast meta description
		$no_desc                                = new WP_Query( [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Querying Yoast fields; only runs on explicit admin request.
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => [
				'relation' => 'OR',
				[
					'key'     => '_yoast_wpseo_metadesc',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_yoast_wpseo_metadesc',
					'value'   => '',
					'compare' => '=',
				],
			],
			'fields' => 'ids',
		] );
		$data['posts_missing_meta_description'] = [
			'count' => $no_desc->found_posts,
			'ids'   => array_slice( $no_desc->posts, 0, 20 ),
		];

		// Total published post/page count for context
		$data['total_published'] = [
			'posts' => (int) wp_count_posts( 'post' )->publish,
			'pages' => (int) wp_count_posts( 'page' )->publish,
		];

		return [ 'success' => true, 'data' => $data ];
	}
}
