<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_SEO {

	public static function register() {
		self::register_analyze_post();
		self::register_site_overview();
		self::register_yoast_metadata();
		self::register_score_ability( 'get-seo-scores', 'SEO Scores', '_yoast_wpseo_linkdex', 'Yoast SEO analysis scores.' );
		self::register_score_ability( 'get-readability-scores', 'Readability Scores', '_yoast_wpseo_content_score', 'Yoast readability analysis scores.' );
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
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/seo-site-overview', [
			'label'               => 'SEO: Site Overview',
			'description'         => 'Get a site-level SEO overview: sitemap, robots.txt, and posts missing Yoast optimization.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
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

	private static function register_yoast_metadata() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-yoast-metadata', [
			'label'               => 'SEO: Yoast Metadata',
			'description'         => 'Inspect Yoast SEO metadata and generated Yoast head data for a post, page, or URL.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'Optional post or page ID to inspect.' ],
					'url'     => [ 'type' => 'string', 'description' => 'Optional absolute URL to inspect through Yoast head output.' ],
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

	public static function permission_yoast_metadata( $input = [] ) {
		$id = absint( $input['post_id'] ?? 0 );
		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
				return new WP_Error( 'not_found', 'Post or page not found.' );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'forbidden', 'Requires edit_post capability for this post or page.' );
			}

			return true;
		}

		if ( ! empty( $input['url'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'forbidden', 'URL-level Yoast metadata inspection requires manage_options capability.' );
			}

			return true;
		}

		return new WP_Error( 'missing_target', 'Provide post_id or url.' );
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

	private static function get_generated_yoast_head_for_post( $post ) {
		$post_type_object = get_post_type_object( $post->post_type );
		$rest_base        = $post_type_object->rest_base ?? $post->post_type;
		$request          = new WP_REST_Request( 'GET', "/wp/v2/{$rest_base}/{$post->ID}" );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			return [
				'available' => false,
				'error'     => [
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
				],
			];
		}

		$data = $response->get_data();

		return [
			'available'       => isset( $data['yoast_head_json'] ) || isset( $data['yoast_head'] ),
			'yoast_head_json' => $data['yoast_head_json'] ?? null,
			'yoast_head'      => $data['yoast_head'] ?? null,
		];
	}

	private static function get_generated_yoast_head_for_url( $url ) {
		$request = new WP_REST_Request( 'GET', '/yoast/v1/get_head' );
		$request->set_param( 'url', $url );
		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			return [
				'available' => false,
				'error'     => [
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
				],
			];
		}

		$data = $response->get_data();

		return [
			'available'       => isset( $data['json'] ) || isset( $data['html'] ),
			'yoast_head_json' => $data['json'] ?? null,
			'yoast_head'      => $data['html'] ?? null,
		];
	}

	public static function execute_yoast_metadata( $input = [] ) {
		$id  = absint( $input['post_id'] ?? 0 );
		$url = esc_url_raw( (string) ( $input['url'] ?? '' ) );

		if ( ! self::is_yoast_active() ) {
			return [
				'success' => true,
				'data'    => [
					'yoast_active' => false,
					'note'         => 'Yoast SEO is not active, so Yoast metadata and head output are not available.',
				],
			];
		}

		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
				return [ 'success' => false, 'error' => [ 'code' => 'not_found', 'message' => 'Post or page not found.' ] ];
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return [ 'success' => false, 'error' => [ 'code' => 'forbidden', 'message' => 'You do not have permission to inspect Yoast metadata for this post or page.' ] ];
			}

			$meta     = [];
			$raw_meta = [];
			foreach ( self::yoast_post_meta_keys() as $field => $meta_key ) {
				$value              = get_post_meta( $id, $meta_key, true );
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
					'generated_head' => self::get_generated_yoast_head_for_post( $post ),
				],
			];
		}

		if ( '' === $url ) {
			return [ 'success' => false, 'error' => [ 'code' => 'missing_target', 'message' => 'Provide post_id or url.' ] ];
		}

		return [
			'success' => true,
			'data'    => [
				'yoast_active'   => true,
				'url'            => $url,
				'generated_head' => self::get_generated_yoast_head_for_url( $url ),
			],
		];
	}

	private static function is_yoast_active() {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'WPSEO_FILE' )
			|| class_exists( 'WPSEO_Options' )
			|| function_exists( 'wpseo_init' );
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
			'description'         => $description,
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => self::score_input_schema(),
			'execute_callback'    => function ( $input ) use ( $meta_key ) {
				return self::execute_score_list( $input, $meta_key );
			},
			'permission_callback' => function ( $input = [] ) {
				if ( 'page' === ( $input['post_type'] ?? null ) && ! current_user_can( 'edit_pages' ) ) {
					return new WP_Error( 'forbidden', 'Requires edit_pages capability.' );
				}
				if ( ! current_user_can( 'edit_posts' ) ) {
					return new WP_Error( 'forbidden', 'Requires edit_posts capability.' );
				}
				return true;
			},
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute_score_list( $input, $meta_key ) {
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
			return [ 'success' => false, 'error' => 'post_type must be post or page.' ];
		}

		$status = $input['status'] ?? 'any';
		if ( ! in_array( $status, [ 'publish', 'draft', 'pending', 'private', 'future', 'any' ], true ) ) {
			return [ 'success' => false, 'error' => 'status is invalid.' ];
		}

		$args = [
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => min( max( 1, (int) ( $input['per_page'] ?? 10 ) ), 100 ),
			'paged'          => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
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
				return [ 'success' => false, 'error' => 'modified_after must be a parseable date/time.' ];
			}

			$args['date_query'] = [
				[
					'column'    => 'post_modified_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', $modified_after ),
					'inclusive' => false,
				],
			];
		}

		$query = new WP_Query( $args );
		$items = [];

		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
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
				'total'        => (int) $query->found_posts,
				'total_pages'  => (int) $query->max_num_pages,
				'yoast_active' => true,
			],
		];
	}

	public static function execute_site_overview( $input = [] ) {
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
