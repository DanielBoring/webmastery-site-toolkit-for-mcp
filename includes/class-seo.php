<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_SEO {

	public static function register() {
		self::register_analyze_post();
		self::register_site_overview();
		self::register_yoast_metadata();
		self::register_seopress_metadata();
		self::register_score_ability( 'get-seo-scores', 'SEO Scores', '_yoast_wpseo_linkdex', 'Yoast SEO analysis scores.' );
		self::register_score_ability( 'get-readability-scores', 'Readability Scores', '_yoast_wpseo_content_score', 'Yoast readability analysis scores.' );
	}

	public static function permission_analyze_post( $input = [] ) {
		$id   = absint( $input['post_id'] ?? 0 );
		$post = get_post( $id );

		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return Webmastery_MCP_Response::local_error( 'not_found', 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this post.' );
		}
		return true;
	}

	private static function register_analyze_post() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/seo-analyze-post', [
			'label'               => 'SEO: Analyze Post',
			'description'         => 'Analyze a post or page for SEO best practices and return findings.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
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
		$permission = self::permission_analyze_post( $input );
		if ( is_wp_error( $permission ) ) {
			return Webmastery_MCP_Response::from_wp_error( $permission );
		}
		$id   = absint( $input['post_id'] );
		$post = get_post( $id );

		if ( ! $post ) {
			return Webmastery_MCP_Response::legacy_error( 'not_found', 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to analyze this post.' );
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

		$descriptions       = self::read_metadata_fields( $id, [
			'yoast_meta_description'    => '_yoast_wpseo_metadesc',
			'seopress_meta_description' => '_seopress_titles_desc',
		] );
		$data               = array_merge( $data, $descriptions['values'] );
		$yoast_meta_desc    = $descriptions['values']['yoast_meta_description'] ?? '';
		$seopress_meta_desc = $descriptions['values']['seopress_meta_description'] ?? '';
		$meta_desc          = '' !== $yoast_meta_desc ? $yoast_meta_desc : $seopress_meta_desc;
		$description_known  = '' !== $meta_desc || [] === $descriptions['unavailable_fields'];
		if ( $description_known ) {
			$data['seo_provider_meta_source'] = '' !== $yoast_meta_desc ? 'yoast' : ( '' !== $seopress_meta_desc ? 'seopress' : null );
		}
		$data['seo_plugins'] = [
			'yoast_active'    => self::is_yoast_active(),
			'seopress_active' => self::is_seopress_active(),
		];
		$unavailable_checks  = [];
		if ( ! $description_known ) {
			$unavailable_checks[] = 'meta_description';
		} elseif ( empty( $meta_desc ) ) {
			$issues[] = [ 'check' => 'meta_description', 'severity' => 'warn', 'message' => 'No Yoast SEO or SEOPress meta description set.' ];
		} else {
			$desc_len = mb_strlen( $meta_desc );
			if ( $desc_len < 120 || $desc_len > 160 ) {
				$issues[] = [ 'check' => 'meta_description', 'severity' => 'info', 'message' => "Meta description is {$desc_len} chars. Ideal range is 120–160." ];
			} else {
				$good[] = [ 'check' => 'meta_description', 'message' => "Meta description length is good ({$desc_len} chars)." ];
			}
		}

		$keywords          = self::read_metadata_fields( $id, [
			'yoast_focus_keyword'     => '_yoast_wpseo_focuskw',
			'seopress_focus_keywords' => '_seopress_analysis_target_kw',
		] );
		$data              = array_merge( $data, $keywords['values'] );
		$yoast_focus_kw    = $keywords['values']['yoast_focus_keyword'] ?? '';
		$seopress_focus_kw = $keywords['values']['seopress_focus_keywords'] ?? '';
		$focus_kw          = '' !== $yoast_focus_kw ? $yoast_focus_kw : $seopress_focus_kw;
		$keyword_known     = '' !== $focus_kw || [] === $keywords['unavailable_fields'];
		if ( $keyword_known ) {
			$data['seo_provider_focus_source'] = '' !== $yoast_focus_kw ? 'yoast' : ( '' !== $seopress_focus_kw ? 'seopress' : null );
		}
		if ( ! $keyword_known ) {
			$unavailable_checks[] = 'focus_keyword';
		} elseif ( empty( $focus_kw ) ) {
			$issues[] = [ 'check' => 'focus_keyword', 'severity' => 'warn', 'message' => 'No Yoast SEO focus keyphrase or SEOPress target keyword set.' ];
		} elseif ( false !== stripos( $title, $focus_kw ) ) {
			$good[] = [ 'check' => 'keyword_in_title', 'message' => 'Focus keyword found in title.' ];
		} else {
			$issues[] = [ 'check' => 'keyword_in_title', 'severity' => 'warn', 'message' => 'Focus keyword not found in title.' ];
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

		$analysis           = [
			'post_id' => $id,
			'metrics' => $data,
			'issues'  => $issues,
			'good'    => $good,
			'score'   => count( $good ) . '/' . ( count( $good ) + count( $issues ) ) . ' checks passed',
		];
		$unavailable_fields = array_merge( $descriptions['unavailable_fields'], $keywords['unavailable_fields'] );
		if ( [] !== $unavailable_fields ) {
			$analysis['unavailable_fields'] = $unavailable_fields;
			$analysis['unevaluable_checks'] = $unavailable_checks;
		}
		return [ 'success' => true, 'data' => $analysis ];
	}

	private static function read_metadata_fields( int $post_id, array $keys ): array {
		$result = [ 'values' => [], 'unavailable_fields' => [] ];
		foreach ( $keys as $field => $key ) {
			if ( ! Webmastery_MCP_Posts::can_read_post_meta_key( $post_id, $key ) ) {
				$result['unavailable_fields'][ $field ] = Webmastery_MCP_Response::error(
					'forbidden',
					'This metadata field is unavailable under the effective key-level permission.',
					[],
					'metadata_not_readable'
				)['error'];
				continue;
			}
			$result['values'][ $field ] = get_post_meta( $post_id, $key, true );
		}
		return $result;
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
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/seo-site-overview', [
			'label'               => 'SEO: Site Overview',
			'description'         => 'Inspect sitemap and robots.txt, plus authorized metadata observations from at most 100 published posts/pages in ID order. Missing-field counts describe only that sample, not the whole site.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute_site_overview' ],
			'permission_callback' => function () {
				return self::permission_site_overview();
			},
			'meta' => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_yoast_metadata() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-yoast-metadata', [
			'label'               => 'SEO: Yoast Metadata',
			'description'         => 'Inspect Yoast SEO metadata for a post or page, omitting fields without effective key permission. Opaque generated head output and URL-only inspection are unsupported.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'Optional post or page ID to inspect.' ],
					'url'     => [ 'type' => 'string', 'description' => 'Legacy URL target; URL-only requests return unsupported. Use post_id for authorized metadata inspection.' ],
				],
			],
			'execute_callback'    => [ self::class, 'execute_yoast_metadata' ],
			'permission_callback' => [ self::class, 'permission_yoast_metadata' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_seopress_metadata() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-seopress-metadata', [
			'label'               => 'SEO: SEOPress Metadata',
			'description'         => 'Inspect SEOPress metadata for a post or page, omitting fields without effective key permission and reporting unavailable_fields.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'Post or page ID to inspect.' ],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => [ self::class, 'execute_seopress_metadata' ],
			'permission_callback' => [ self::class, 'permission_seopress_metadata' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function permission_yoast_metadata( $input = [] ) {
		$id = absint( $input['post_id'] ?? 0 );
		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
				return Webmastery_MCP_Response::local_error( 'not_found', 'Post or page not found.' );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this post or page.' );
			}

			return true;
		}

		if ( ! empty( $input['url'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', 'URL-level Yoast metadata inspection requires manage_options capability.' );
			}

			return true;
		}

		return Webmastery_MCP_Response::local_error( 'missing_target', 'Provide post_id or url.' );
	}

	private static function yoast_post_meta_keys() {
		return [
			'title'                    => '_yoast_wpseo_title',
			'meta_description'         => '_yoast_wpseo_metadesc',
			'focus_keyphrase'          => '_yoast_wpseo_focuskw',
			'canonical_url'            => '_yoast_wpseo_canonical',
			'breadcrumb_title'         => '_yoast_wpseo_bctitle',
			'schema_page_type'         => '_yoast_wpseo_schema_page_type',
			'schema_article_type'      => '_yoast_wpseo_schema_article_type',
			'opengraph_title'          => '_yoast_wpseo_opengraph-title',
			'opengraph_description'    => '_yoast_wpseo_opengraph-description',
			'opengraph_image'          => '_yoast_wpseo_opengraph-image',
			'twitter_title'            => '_yoast_wpseo_twitter-title',
			'twitter_description'      => '_yoast_wpseo_twitter-description',
			'twitter_image'            => '_yoast_wpseo_twitter-image',
			'seo_score'                => '_yoast_wpseo_linkdex',
			'readability_score'        => '_yoast_wpseo_content_score',
			'inclusive_language_score' => '_yoast_wpseo_inclusive_language_score',
			'primary_category'         => '_yoast_wpseo_primary_category',
			'cornerstone'              => '_yoast_wpseo_is_cornerstone',
			'robots_noindex'           => '_yoast_wpseo_meta-robots-noindex',
			'robots_nofollow'          => '_yoast_wpseo_meta-robots-nofollow',
			'robots_advanced'          => '_yoast_wpseo_meta-robots-adv',
		];
	}

	private static function seopress_post_meta_keys() {
		return [
			'title'                 => '_seopress_titles_title',
			'meta_description'      => '_seopress_titles_desc',
			'focus_keywords'        => '_seopress_analysis_target_kw',
			'canonical_url'         => '_seopress_robots_canonical',
			'opengraph_title'       => '_seopress_social_fb_title',
			'opengraph_description' => '_seopress_social_fb_desc',
			'opengraph_image'       => '_seopress_social_fb_img',
			'twitter_title'         => '_seopress_social_twitter_title',
			'twitter_description'   => '_seopress_social_twitter_desc',
			'twitter_image'         => '_seopress_social_twitter_img',
			'primary_category'      => '_seopress_robots_primary_cat',
			'robots_noindex'        => '_seopress_robots_index',
			'robots_nofollow'       => '_seopress_robots_follow',
			'robots_noimageindex'   => '_seopress_robots_imageindex',
			'robots_noarchive'      => '_seopress_robots_archive',
			'robots_nosnippet'      => '_seopress_robots_snippet',
			'breadcrumb_title'      => '_seopress_robots_breadcrumbs',
			'news_sitemap_disabled' => '_seopress_news_disabled',
			'video_sitemap_disabled' => '_seopress_video_disabled',
		];
	}

	private static function normalize_yoast_meta_value( $field, $value ) {
		if ( '' === $value ) {
			return null;
		}

		if ( in_array( $field, [ 'seo_score', 'readability_score', 'inclusive_language_score', 'primary_category' ], true ) ) {
			return (int) $value;
		}
		if ( in_array( $field, [ 'cornerstone', 'robots_noindex', 'robots_nofollow' ], true ) ) {
			return rest_sanitize_boolean( $value );
		}

		return $value;
	}

	private static function normalize_seopress_meta_value( $field, $value ) {
		if ( '' === $value ) {
			return null;
		}

		if ( 'primary_category' === $field ) {
			return (int) $value;
		}
		if ( in_array( $field, [ 'robots_noindex', 'robots_nofollow', 'robots_noimageindex', 'robots_noarchive', 'robots_nosnippet', 'news_sitemap_disabled', 'video_sitemap_disabled' ], true ) ) {
			return rest_sanitize_boolean( $value );
		}

		return $value;
	}

	public static function execute_yoast_metadata( $input = [] ) {
		$permission = self::permission_yoast_metadata( $input );
		if ( is_wp_error( $permission ) ) {
			return Webmastery_MCP_Response::from_wp_error( $permission );
		}
		$id = absint( $input['post_id'] ?? 0 );
		if ( ! $id ) {
			return Webmastery_MCP_Response::error(
			'unsupported',
			'URL-only generated head inspection cannot enforce per-key authorization. Use post_id to inspect authorized metadata fields.',
			[],
			'generated_head_key_authorization_unavailable'
			);
		}

		if ( ! self::is_yoast_active() ) {
			return [
				'success' => true,
				'data'    => [
					'yoast_active' => false,
					'note'         => 'Yoast SEO is not active, so Yoast metadata and head output are not available.',
				],
			];
		}

		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return Webmastery_MCP_Response::legacy_error( 'not_found', 'Post or page not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to inspect Yoast metadata for this post or page.' );
		}

		$meta     = [];
		$raw_meta = [];
		$read     = self::read_metadata_fields( $id, self::yoast_post_meta_keys() );
		foreach ( self::yoast_post_meta_keys() as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $read['values'] ) ) {
				continue;
			}
			$value              = $read['values'][ $field ];
			$meta[ $field ]     = self::normalize_yoast_meta_value( $field, $value );
			$raw_meta[ $field ] = [
				'key'   => $meta_key,
				'value' => $value,
			];
		}

		return [
			'success' => true,
			'data'    => [
				'yoast_active'   => true,
				'post_id'        => $id,
				'post_type'      => $post->post_type,
				'title'          => $post->post_title,
				'url'            => get_permalink( $id ),
				'metadata'       => $meta,
				'raw_meta'       => $raw_meta,
				'unavailable_fields' => $read['unavailable_fields'],
				'generated_head' => [
					'available' => false,
					'error' => Webmastery_MCP_Response::error(
						'unsupported',
						'Opaque generated head output cannot enforce per-key authorization.',
						[],
						'key_authorization_unavailable'
					)['error'],
				],
			],
		];
	}

	public static function permission_seopress_metadata( $input = [] ) {
		$id   = absint( $input['post_id'] ?? 0 );
		$post = get_post( $id );

		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return Webmastery_MCP_Response::local_error( 'not_found', 'Post or page not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this post or page.' );
		}

		return true;
	}

	public static function execute_seopress_metadata( $input = [] ) {
		$permission = self::permission_seopress_metadata( $input );
		if ( is_wp_error( $permission ) ) {
			return Webmastery_MCP_Response::from_wp_error( $permission );
		}
		$id = absint( $input['post_id'] ?? 0 );

		if ( ! self::is_seopress_active() ) {
			return [
				'success' => true,
				'data'    => [
					'seopress_active' => false,
					'note'            => 'SEOPress is not active, so SEOPress metadata is not available.',
				],
			];
		}

		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return Webmastery_MCP_Response::legacy_error( 'not_found', 'Post or page not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to inspect SEOPress metadata for this post or page.' );
		}

		$meta     = [];
		$raw_meta = [];
		$read     = self::read_metadata_fields( $id, self::seopress_post_meta_keys() );
		foreach ( self::seopress_post_meta_keys() as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $read['values'] ) ) {
				continue;
			}
			$value              = $read['values'][ $field ];
			$meta[ $field ]     = self::normalize_seopress_meta_value( $field, $value );
			$raw_meta[ $field ] = [
				'key'   => $meta_key,
				'value' => $value,
			];
		}

		return [
			'success' => true,
			'data'    => [
				'seopress_active' => true,
				'post_id'         => $id,
				'post_type'       => $post->post_type,
				'title'           => $post->post_title,
				'url'             => get_permalink( $id ),
				'metadata'        => $meta,
				'raw_meta'        => $raw_meta,
				'unavailable_fields' => $read['unavailable_fields'],
			],
		];
	}

	private static function is_yoast_active() {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'WPSEO_FILE' )
			|| class_exists( 'WPSEO_Options' )
			|| function_exists( 'wpseo_init' );
	}

	private static function is_seopress_active() {
		$active_plugins = (array) get_option( 'active_plugins', [] );

		return defined( 'SEOPRESS_VERSION' )
			|| defined( 'SEOPRESS_PRO_VERSION' )
			|| class_exists( 'SEOPress' )
			|| function_exists( 'seopress_activation' )
			|| in_array( 'wp-seopress/seopress.php', $active_plugins, true );
	}

	private static function score_input_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'per_page'       => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ],
				'page'           => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'post_type'      => [ 'type' => 'string', 'enum' => [ 'post', 'page' ], 'description' => 'Optional post type filter.' ],
				'status'         => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'future', 'any' ], 'description' => 'Optional post status filter.' ],
				'modified_after' => [ 'type' => 'string', 'description' => 'Optional GMT modified-after filter, parseable by strtotime().' ],
			],
		];
	}

	private static function register_score_ability( $slug, $label, $meta_key, $description ) {
		wp_register_ability( "webmastery-site-toolkit-for-mcp/{$slug}", [
			'label'               => "SEO: {$label}",
			'description'         => $description . ' Only objects with effective permission for this score key are included in items and pagination totals.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => self::score_input_schema(),
			'execute_callback'    => function ( $input ) use ( $meta_key ) {
				return self::execute_score_list( $input, $meta_key );
			},
			'permission_callback' => [ self::class, 'permission_score_list' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function permission_score_list( $input = [] ) {
		if ( 'page' === ( $input['post_type'] ?? null ) && ! current_user_can( 'edit_pages' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_pages capability.' );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_posts capability.' );
		}
		return true;
	}

	public static function execute_score_list( $input, $meta_key ) {
		$permission = self::permission_score_list( $input );
		if ( is_wp_error( $permission ) ) {
			return Webmastery_MCP_Response::from_wp_error( $permission );
		}
		if ( ! self::is_yoast_active() ) {
			return [
				'success' => true,
				'data'    => [
					'items'        => [],
					'total'        => 0,
					'total_pages'  => 0,
					'yoast_active' => false,
					'note'         => 'Yoast SEO is not active, so no Yoast scores are available.',
				],
			];
		}

		$post_type = $input['post_type'] ?? [ 'post', 'page' ];
		if ( is_string( $post_type ) && ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
			return Webmastery_MCP_Response::legacy_error( 'invalid_content_type', 'post_type must be post or page.' );
		}

		$status = $input['status'] ?? 'any';
		if ( ! in_array( $status, [ 'publish', 'draft', 'pending', 'private', 'future', 'any' ], true ) ) {
			return Webmastery_MCP_Response::legacy_error( 'invalid_status', 'status is invalid.' );
		}

		$per_page = min( max( 1, (int) ( $input['per_page'] ?? 10 ) ), 100 );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$args     = [
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => -1,
			'paged'          => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		if ( is_array( $post_type ) && ! current_user_can( 'edit_pages' ) ) {
			$args['post_type'] = 'post';
		}

		if ( 'page' === $args['post_type'] && ! current_user_can( 'edit_others_pages' ) ) {
			$args['author'] = get_current_user_id();
		} elseif ( 'post' === $args['post_type'] && ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		} elseif ( is_array( $args['post_type'] ) && ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		if ( ! empty( $input['modified_after'] ) ) {
			$modified_after = strtotime( sanitize_text_field( $input['modified_after'] ) );
			if ( false === $modified_after ) {
				return Webmastery_MCP_Response::legacy_error( 'invalid_input', 'modified_after must be a parseable date/time.' );
			}

			$args['date_query'] = [
				[
					'column'    => 'post_modified_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', $modified_after ),
					'inclusive' => false,
				],
			];
		}

		$query        = new WP_Query( $args );
		$readable_ids = [];

		foreach ( $query->posts as $post_id ) {
			if ( Webmastery_MCP_Posts::can_read_post_meta_key( (int) $post_id, $meta_key ) ) {
				$readable_ids[] = (int) $post_id;
			}
		}

		$total    = count( $readable_ids );
		$page_ids = array_slice( $readable_ids, ( $page - 1 ) * $per_page, $per_page );
		$items    = [];

		foreach ( $page_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$raw_score = get_post_meta( $post->ID, $meta_key, true );
			$items[]   = [
				'post_id'      => (int) $post->ID,
				'title'        => $post->post_title,
				'url'          => get_permalink( $post->ID ),
				'post_type'    => $post->post_type,
				'modified_gmt' => $post->post_modified_gmt,
				'score'        => '' === $raw_score ? null : (int) $raw_score,
			];
		}

		return [
			'success' => true,
			'data'    => [
				'items'        => $items,
				'total'        => $total,
				'total_pages'  => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
				'yoast_active' => true,
				'seopress_active' => self::is_seopress_active(),
			],
		];
	}

	public static function permission_site_overview() {
		return current_user_can( 'manage_options' )
			? true
			: Webmastery_MCP_Response::local_error( 'forbidden', 'Requires manage_options capability.' );
	}

	public static function execute_site_overview( $input = [] ) {
		$permission = self::permission_site_overview();
		if ( is_wp_error( $permission ) ) {
			return Webmastery_MCP_Response::from_wp_error( $permission );
		}
		$data = [];

		// Sitemap
		$sitemap_url      = home_url( '/sitemap_index.xml' );
		$sitemap_response = wp_remote_head( $sitemap_url, [ 'timeout' => 5 ] );
		$sitemap_ok       = ! is_wp_error( $sitemap_response ) && wp_remote_retrieve_response_code( $sitemap_response ) === 200;
		$data['sitemap']  = [ 'url' => $sitemap_url, 'accessible' => $sitemap_ok ];
		if ( $sitemap_ok ) {
			$sitemap_body = wp_remote_retrieve_body( wp_remote_get( $sitemap_url, [ 'timeout' => 5 ] ) );
			preg_match_all( '/<loc>(.*?)<\/loc>/i', $sitemap_body, $sitemap_matches );
			$data['sitemap']['entries']     = array_values( array_map( 'esc_url_raw', $sitemap_matches[1] ?? [] ) );
			$data['sitemap']['entry_count'] = count( $data['sitemap']['entries'] );
		}

		// Robots.txt
		$robots_url         = home_url( '/robots.txt' );
		$robots_response    = wp_remote_head( $robots_url, [ 'timeout' => 5 ] );
		$robots_ok          = ! is_wp_error( $robots_response ) && wp_remote_retrieve_response_code( $robots_response ) === 200;
		$data['robots_txt'] = [ 'url' => $robots_url, 'accessible' => $robots_ok ];

		$data['providers'] = [
			'yoast_active'    => self::is_yoast_active(),
			'seopress_active' => self::is_seopress_active(),
		];

		$candidates                = new WP_Query( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$data['observation_scope'] = [
			'mode' => 'sample',
			'post_limit' => 100,
			'ordering' => 'ID ASC',
			'counts_are_sitewide' => false,
		];
		$observations              = [
			'posts_missing_focus_keyword' => '_yoast_wpseo_focuskw',
			'posts_missing_meta_description' => '_yoast_wpseo_metadesc',
		];
		if ( self::is_seopress_active() ) {
			$observations['seopress_posts_missing_focus_keywords']   = '_seopress_analysis_target_kw';
			$observations['seopress_posts_missing_meta_description'] = '_seopress_titles_desc';
		}
		foreach ( $observations as $field => $key ) {
			$observation = [ 'count' => 0, 'ids' => [], 'observed_count' => 0 ];
			foreach ( $candidates->posts as $post_id ) {
				if ( ! Webmastery_MCP_Posts::can_read_post_meta_key( (int) $post_id, $key ) ) {
					continue;
				}
				++$observation['observed_count'];
				if ( '' === get_post_meta( (int) $post_id, $key, true ) ) {
					++$observation['count'];
					if ( count( $observation['ids'] ) < 20 ) {
						$observation['ids'][] = (int) $post_id;
					}
				}
			}
			$data[ $field ] = $observation;
		}

		// Total published post/page count for context
		$data['total_published'] = [
			'posts' => (int) wp_count_posts( 'post' )->publish,
			'pages' => (int) wp_count_posts( 'page' )->publish,
		];

		return [ 'success' => true, 'data' => $data ];
	}
}
