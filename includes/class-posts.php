<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Posts {

	private const POST_META_KEY_MAX_LENGTH  = 255;
	private const POST_META_VALUE_MAX_BYTES = 100000;
	private const POST_META_VALUE_MAX_DEPTH = 10;

	public static function register() {
		self::register_post_type( 'post' );
		self::register_post_type( 'page' );
		self::register_list_content_blocks();
		self::register_patch_content_block();
		self::register_patch_post_content();
		self::register_set_featured_image();
		self::register_remove_featured_image();
		self::register_list_revisions();
		self::register_restore_revision();
		self::register_get_post_meta();
		self::register_update_post_meta();
		self::register_delete_post_meta();
		self::register_bulk_trash_posts();
		self::register_bulk_publish_posts();
	}

	private static function normalize( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		$data = [
			'id'                => $post->ID,
			'title'             => $post->post_title,
			'content'           => $post->post_content,
			'excerpt'           => $post->post_excerpt,
			'status'            => $post->post_status,
			'slug'              => $post->post_name,
			'url'               => get_permalink( $post->ID ),
			'author'            => (int) $post->post_author,
			'author_name'       => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'author_login'      => get_the_author_meta( 'user_login', (int) $post->post_author ),
			'date_created'      => $post->post_date,
			'date_modified'     => $post->post_modified,
			'type'              => $post->post_type,
			'featured_image_id' => (int) get_post_thumbnail_id( $post->ID ),
		];

		if ( 'post' === $post->post_type ) {
			$data['categories'] = wp_get_post_categories( $post->ID, [ 'fields' => 'ids' ] );
			$data['tags']       = wp_get_post_tags( $post->ID, [ 'fields' => 'ids' ] );
		}

		return $data;
	}

	private static function writable_protected_meta_keys() {
		return [
			'_yoast_wpseo_focuskw'                => 'string',
			'_yoast_wpseo_metadesc'               => 'string',
			'_yoast_wpseo_title'                  => 'string',
			'_yoast_wpseo_canonical'              => 'url',
			'_yoast_wpseo_bctitle'                => 'string',
			'_yoast_wpseo_schema_page_type'       => 'string',
			'_yoast_wpseo_schema_article_type'    => 'string',
			'_yoast_wpseo_opengraph-title'        => 'string',
			'_yoast_wpseo_opengraph-description'  => 'string',
			'_yoast_wpseo_opengraph-image'        => 'url',
			'_yoast_wpseo_twitter-title'          => 'string',
			'_yoast_wpseo_twitter-description'    => 'string',
			'_yoast_wpseo_twitter-image'          => 'url',
			'_yoast_wpseo_inclusive_language_score' => 'integer_string',
			'_yoast_wpseo_primary_category'         => 'integer_string',
			'_yoast_wpseo_is_cornerstone'           => 'boolean_string',
			'_yoast_wpseo_meta-robots-noindex'      => 'boolean_string',
			'_yoast_wpseo_meta-robots-nofollow'     => 'boolean_string',
			'_yoast_wpseo_meta-robots-adv'          => 'string',
			'_seopress_titles_title'                => 'string',
			'_seopress_titles_desc'                 => 'string',
			'_seopress_analysis_target_kw'          => 'string',
			'_seopress_robots_canonical'            => 'url',
			'_seopress_social_fb_title'             => 'string',
			'_seopress_social_fb_desc'              => 'string',
			'_seopress_social_fb_img'               => 'url',
			'_seopress_social_twitter_title'        => 'string',
			'_seopress_social_twitter_desc'         => 'string',
			'_seopress_social_twitter_img'          => 'url',
			'_seopress_robots_primary_cat'          => 'integer_string',
			'_seopress_robots_index'                => 'seopress_boolean_string',
			'_seopress_robots_follow'               => 'seopress_boolean_string',
			'_seopress_robots_imageindex'           => 'seopress_boolean_string',
			'_seopress_robots_archive'              => 'seopress_boolean_string',
			'_seopress_robots_snippet'              => 'seopress_boolean_string',
			'_seopress_robots_breadcrumbs'          => 'string',
		];
	}

	private static function allowed_protected_post_meta_keys() {
		return array_fill_keys( array_keys( self::writable_protected_meta_keys() ), true );
	}

	private static function validate_post_meta_key( $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return new WP_Error( 'invalid_meta_key', 'meta_key is required.' );
		}
		if ( strlen( $key ) > self::POST_META_KEY_MAX_LENGTH ) {
			return new WP_Error( 'invalid_meta_key', 'meta_key must be 255 bytes or fewer.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_\-:.]+$/', $key ) ) {
			return new WP_Error( 'invalid_meta_key', 'meta_key may only contain letters, numbers, underscores, hyphens, colons, and periods.' );
		}

		return $key;
	}

	private static function can_access_post_meta_key( $key ) {
		return ! is_protected_meta( $key, 'post' ) || isset( self::allowed_protected_post_meta_keys()[ $key ] );
	}

	private static function normalize_post_meta_value( $value, $depth = 0 ) {
		if ( $depth > self::POST_META_VALUE_MAX_DEPTH ) {
			return new WP_Error( 'invalid_meta_value', 'meta_value nesting is too deep.' );
		}

		if ( null === $value ) {
			return new WP_Error( 'invalid_meta_value', 'meta_value must be a scalar, object, or array.' );
		}

		if ( is_string( $value ) ) {
			return sanitize_textarea_field( $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = [];

			foreach ( $value as $item_key => $item_value ) {
				$normalized_item = null === $item_value ? null : self::normalize_post_meta_value( $item_value, $depth + 1 );
				if ( is_wp_error( $normalized_item ) ) {
					return $normalized_item;
				}

				$normalized[ is_int( $item_key ) ? $item_key : sanitize_key( (string) $item_key ) ] = $normalized_item;
			}

			return $normalized;
		}

		return new WP_Error( 'invalid_meta_value', 'meta_value must be a scalar, object, or array.' );
	}

	private static function validate_post_meta_value( $value ) {
		$normalized = self::normalize_post_meta_value( $value );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$encoded = wp_json_encode( $normalized );
		if ( false === $encoded ) {
			return new WP_Error( 'invalid_meta_value', 'meta_value could not be encoded safely.' );
		}
		if ( strlen( $encoded ) > self::POST_META_VALUE_MAX_BYTES ) {
			return new WP_Error( 'invalid_meta_value', 'meta_value must encode to 100000 bytes or fewer.' );
		}

		return $normalized;
	}

	private static function prepare_post_meta_update_value( $key, $value ) {
		$protected_keys = self::writable_protected_meta_keys();

		if ( isset( $protected_keys[ $key ] ) ) {
			$normalized = self::normalize_meta_value( $value, $protected_keys[ $key ] );
			if ( null === $normalized ) {
				return new WP_Error( 'invalid_meta_value', 'meta_value is not valid for this protected meta key.' );
			}

			return $normalized;
		}

		return self::validate_post_meta_value( $value );
	}

	private static function normalize_post_meta_response_value( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::normalize_post_meta_response_value( $item );
			}
		}

		return $value;
	}

	private static function meta_schema() {
		return [
			'type'                 => 'object',
			'description'          => 'Post meta to write. REST-registered keys and supported Yoast SEO or SEOPress protected keys are persisted; unsupported protected keys fail with details instead of being silently ignored.',
			'additionalProperties' => [
				'type' => [ 'string', 'number', 'integer', 'boolean', 'null' ],
			],
		];
	}

	private static function yoast_input_schema_props() {
		return [
			'yoast_meta_description'       => [ 'type' => 'string', 'description' => 'Yoast SEO meta description (stored as _yoast_wpseo_metadesc)' ],
			'yoast_focus_keyword'          => [ 'type' => 'string', 'description' => 'Yoast SEO focus keyphrase (stored as _yoast_wpseo_focuskw)' ],
			'yoast_seo_title'              => [ 'type' => 'string', 'description' => 'Yoast SEO title (stored as _yoast_wpseo_title)' ],
			'yoast_canonical_url'          => [ 'type' => 'string', 'description' => 'Yoast canonical URL (stored as _yoast_wpseo_canonical)' ],
			'yoast_breadcrumb_title'       => [ 'type' => 'string', 'description' => 'Yoast breadcrumb title (stored as _yoast_wpseo_bctitle)' ],
			'yoast_schema_page_type'       => [ 'type' => 'string', 'description' => 'Yoast Schema.org page type (stored as _yoast_wpseo_schema_page_type)' ],
			'yoast_schema_article_type'    => [ 'type' => 'string', 'description' => 'Yoast Schema.org article type (stored as _yoast_wpseo_schema_article_type)' ],
			'yoast_opengraph_title'        => [ 'type' => 'string', 'description' => 'Yoast Open Graph title (stored as _yoast_wpseo_opengraph-title)' ],
			'yoast_opengraph_description'  => [ 'type' => 'string', 'description' => 'Yoast Open Graph description (stored as _yoast_wpseo_opengraph-description)' ],
			'yoast_opengraph_image'        => [ 'type' => 'string', 'description' => 'Yoast Open Graph image URL (stored as _yoast_wpseo_opengraph-image)' ],
			'yoast_twitter_title'          => [ 'type' => 'string', 'description' => 'Yoast Twitter title (stored as _yoast_wpseo_twitter-title)' ],
			'yoast_twitter_description'    => [ 'type' => 'string', 'description' => 'Yoast Twitter description (stored as _yoast_wpseo_twitter-description)' ],
			'yoast_twitter_image'          => [ 'type' => 'string', 'description' => 'Yoast Twitter image URL (stored as _yoast_wpseo_twitter-image)' ],
			'yoast_primary_category'       => [ 'type' => 'integer', 'description' => 'Yoast primary category term ID (stored as _yoast_wpseo_primary_category)' ],
			'yoast_robots_noindex'         => [ 'type' => 'boolean', 'description' => 'Yoast robots noindex flag (stored as _yoast_wpseo_meta-robots-noindex)' ],
			'yoast_robots_nofollow'        => [ 'type' => 'boolean', 'description' => 'Yoast robots nofollow flag (stored as _yoast_wpseo_meta-robots-nofollow)' ],
			'yoast_robots_advanced'        => [ 'type' => 'string', 'description' => 'Yoast advanced robots directives, comma-separated (stored as _yoast_wpseo_meta-robots-adv)' ],
		];
	}

	private static function seopress_input_schema_props() {
		return [
			'seopress_meta_description'      => [ 'type' => 'string', 'description' => 'SEOPress meta description (stored as _seopress_titles_desc)' ],
			'seopress_focus_keywords'        => [ 'type' => 'string', 'description' => 'SEOPress target keywords (stored as _seopress_analysis_target_kw)' ],
			'seopress_seo_title'             => [ 'type' => 'string', 'description' => 'SEOPress title (stored as _seopress_titles_title)' ],
			'seopress_canonical_url'         => [ 'type' => 'string', 'description' => 'SEOPress canonical URL (stored as _seopress_robots_canonical)' ],
			'seopress_opengraph_title'       => [ 'type' => 'string', 'description' => 'SEOPress Open Graph title (stored as _seopress_social_fb_title)' ],
			'seopress_opengraph_description' => [ 'type' => 'string', 'description' => 'SEOPress Open Graph description (stored as _seopress_social_fb_desc)' ],
			'seopress_opengraph_image'       => [ 'type' => 'string', 'description' => 'SEOPress Open Graph image URL (stored as _seopress_social_fb_img)' ],
			'seopress_twitter_title'         => [ 'type' => 'string', 'description' => 'SEOPress Twitter/X title (stored as _seopress_social_twitter_title)' ],
			'seopress_twitter_description'   => [ 'type' => 'string', 'description' => 'SEOPress Twitter/X description (stored as _seopress_social_twitter_desc)' ],
			'seopress_twitter_image'         => [ 'type' => 'string', 'description' => 'SEOPress Twitter/X image URL (stored as _seopress_social_twitter_img)' ],
			'seopress_primary_category'      => [ 'type' => 'integer', 'description' => 'SEOPress primary category term ID (stored as _seopress_robots_primary_cat)' ],
			'seopress_robots_noindex'        => [ 'type' => 'boolean', 'description' => 'SEOPress noindex flag; true stores _seopress_robots_index=yes' ],
			'seopress_robots_nofollow'       => [ 'type' => 'boolean', 'description' => 'SEOPress nofollow flag; true stores _seopress_robots_follow=yes' ],
			'seopress_robots_noimageindex'   => [ 'type' => 'boolean', 'description' => 'SEOPress noimageindex flag; true stores _seopress_robots_imageindex=yes' ],
			'seopress_robots_noarchive'      => [ 'type' => 'boolean', 'description' => 'SEOPress noarchive flag; true stores _seopress_robots_archive=yes' ],
			'seopress_robots_nosnippet'      => [ 'type' => 'boolean', 'description' => 'SEOPress nosnippet flag; true stores _seopress_robots_snippet=yes' ],
			'seopress_breadcrumb_title'      => [ 'type' => 'string', 'description' => 'SEOPress breadcrumb title (stored as _seopress_robots_breadcrumbs)' ],
		];
	}

	private static function normalize_meta_value( $value, $type = 'string' ) {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		if ( 'boolean_string' === $type ) {
			return rest_sanitize_boolean( $value ) ? '1' : '0';
		}
		if ( 'seopress_boolean_string' === $type ) {
			return rest_sanitize_boolean( $value ) ? 'yes' : '';
		}
		if ( 'integer_string' === $type ) {
			return (string) absint( $value );
		}
		if ( 'url' === $type ) {
			$raw_url = (string) $value;
			$url     = esc_url_raw( $raw_url );
			return '' === $raw_url || '' !== $url ? $url : null;
		}

		return sanitize_text_field( (string) $value );
	}

	private static function registered_rest_meta_keys( $type ) {
		$registered = get_registered_meta_keys( 'post', $type );
		$keys       = [];

		foreach ( $registered as $key => $args ) {
			if ( ! empty( $args['show_in_rest'] ) ) {
				$keys[ $key ] = $args;
			}
		}

		return $keys;
	}

	private static function prepare_meta_writes( $input, $type ) {
		$requested = [];

		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$requested = $input['meta'];
		}

		if ( isset( $input['yoast_meta_description'] ) ) {
			$requested['_yoast_wpseo_metadesc'] = $input['yoast_meta_description'];
		}
		if ( isset( $input['yoast_focus_keyword'] ) ) {
			$requested['_yoast_wpseo_focuskw'] = $input['yoast_focus_keyword'];
		}
		if ( isset( $input['yoast_seo_title'] ) ) {
			$requested['_yoast_wpseo_title'] = $input['yoast_seo_title'];
		}
		if ( isset( $input['yoast_canonical_url'] ) ) {
			$requested['_yoast_wpseo_canonical'] = $input['yoast_canonical_url'];
		}
		if ( isset( $input['yoast_breadcrumb_title'] ) ) {
			$requested['_yoast_wpseo_bctitle'] = $input['yoast_breadcrumb_title'];
		}
		if ( isset( $input['yoast_schema_page_type'] ) ) {
			$requested['_yoast_wpseo_schema_page_type'] = $input['yoast_schema_page_type'];
		}
		if ( isset( $input['yoast_schema_article_type'] ) ) {
			$requested['_yoast_wpseo_schema_article_type'] = $input['yoast_schema_article_type'];
		}
		if ( isset( $input['yoast_opengraph_title'] ) ) {
			$requested['_yoast_wpseo_opengraph-title'] = $input['yoast_opengraph_title'];
		}
		if ( isset( $input['yoast_opengraph_description'] ) ) {
			$requested['_yoast_wpseo_opengraph-description'] = $input['yoast_opengraph_description'];
		}
		if ( isset( $input['yoast_opengraph_image'] ) ) {
			$requested['_yoast_wpseo_opengraph-image'] = $input['yoast_opengraph_image'];
		}
		if ( isset( $input['yoast_twitter_title'] ) ) {
			$requested['_yoast_wpseo_twitter-title'] = $input['yoast_twitter_title'];
		}
		if ( isset( $input['yoast_twitter_description'] ) ) {
			$requested['_yoast_wpseo_twitter-description'] = $input['yoast_twitter_description'];
		}
		if ( isset( $input['yoast_twitter_image'] ) ) {
			$requested['_yoast_wpseo_twitter-image'] = $input['yoast_twitter_image'];
		}
		if ( isset( $input['yoast_primary_category'] ) ) {
			$requested['_yoast_wpseo_primary_category'] = $input['yoast_primary_category'];
		}
		if ( isset( $input['yoast_robots_noindex'] ) ) {
			$requested['_yoast_wpseo_meta-robots-noindex'] = $input['yoast_robots_noindex'];
		}
		if ( isset( $input['yoast_robots_nofollow'] ) ) {
			$requested['_yoast_wpseo_meta-robots-nofollow'] = $input['yoast_robots_nofollow'];
		}
		if ( isset( $input['yoast_robots_advanced'] ) ) {
			$requested['_yoast_wpseo_meta-robots-adv'] = $input['yoast_robots_advanced'];
		}
		if ( isset( $input['seopress_meta_description'] ) ) {
			$requested['_seopress_titles_desc'] = $input['seopress_meta_description'];
		}
		if ( isset( $input['seopress_focus_keywords'] ) ) {
			$requested['_seopress_analysis_target_kw'] = $input['seopress_focus_keywords'];
		}
		if ( isset( $input['seopress_seo_title'] ) ) {
			$requested['_seopress_titles_title'] = $input['seopress_seo_title'];
		}
		if ( isset( $input['seopress_canonical_url'] ) ) {
			$requested['_seopress_robots_canonical'] = $input['seopress_canonical_url'];
		}
		if ( isset( $input['seopress_opengraph_title'] ) ) {
			$requested['_seopress_social_fb_title'] = $input['seopress_opengraph_title'];
		}
		if ( isset( $input['seopress_opengraph_description'] ) ) {
			$requested['_seopress_social_fb_desc'] = $input['seopress_opengraph_description'];
		}
		if ( isset( $input['seopress_opengraph_image'] ) ) {
			$requested['_seopress_social_fb_img'] = $input['seopress_opengraph_image'];
		}
		if ( isset( $input['seopress_twitter_title'] ) ) {
			$requested['_seopress_social_twitter_title'] = $input['seopress_twitter_title'];
		}
		if ( isset( $input['seopress_twitter_description'] ) ) {
			$requested['_seopress_social_twitter_desc'] = $input['seopress_twitter_description'];
		}
		if ( isset( $input['seopress_twitter_image'] ) ) {
			$requested['_seopress_social_twitter_img'] = $input['seopress_twitter_image'];
		}
		if ( isset( $input['seopress_primary_category'] ) ) {
			$requested['_seopress_robots_primary_cat'] = $input['seopress_primary_category'];
		}
		if ( isset( $input['seopress_robots_noindex'] ) ) {
			$requested['_seopress_robots_index'] = $input['seopress_robots_noindex'];
		}
		if ( isset( $input['seopress_robots_nofollow'] ) ) {
			$requested['_seopress_robots_follow'] = $input['seopress_robots_nofollow'];
		}
		if ( isset( $input['seopress_robots_noimageindex'] ) ) {
			$requested['_seopress_robots_imageindex'] = $input['seopress_robots_noimageindex'];
		}
		if ( isset( $input['seopress_robots_noarchive'] ) ) {
			$requested['_seopress_robots_archive'] = $input['seopress_robots_noarchive'];
		}
		if ( isset( $input['seopress_robots_nosnippet'] ) ) {
			$requested['_seopress_robots_snippet'] = $input['seopress_robots_nosnippet'];
		}
		if ( isset( $input['seopress_breadcrumb_title'] ) ) {
			$requested['_seopress_robots_breadcrumbs'] = $input['seopress_breadcrumb_title'];
		}

		$protected_keys = self::writable_protected_meta_keys();
		$rest_keys      = self::registered_rest_meta_keys( $type );
		$prepared       = [
			'writes'      => [],
			'not_written' => [],
		];

		foreach ( $requested as $key => $value ) {
			$key = (string) $key;

			if ( isset( $protected_keys[ $key ] ) ) {
				$normalized = self::normalize_meta_value( $value, $protected_keys[ $key ] );
				if ( null === $normalized ) {
					$prepared['not_written'][] = [
						'key'    => $key,
						'reason' => 'invalid_value',
					];
					continue;
				}

				$prepared['writes'][ $key ] = $normalized;
				continue;
			}

			if ( isset( $rest_keys[ $key ] ) ) {
				$normalized = self::normalize_meta_value( $value );
				if ( null === $normalized ) {
					$prepared['not_written'][] = [
						'key'    => $key,
						'reason' => 'invalid_value',
					];
					continue;
				}

				$prepared['writes'][ $key ] = $normalized;
				continue;
			}

			$prepared['not_written'][] = [
				'key'    => $key,
				'reason' => is_protected_meta( $key, 'post' ) ? 'unsupported_protected_meta' : 'not_registered_for_rest',
			];
		}

		return $prepared;
	}

	private static function apply_meta_writes( $post_id, $writes ) {
		$written = [];

		foreach ( $writes as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
			$written[ $key ] = get_post_meta( $post_id, $key, true );
		}

		return $written;
	}

	private static function meta_write_error_response( $prepared ) {
		return [
			'success' => false,
			'error'   => [
				'code'    => 'meta_write_failed',
				'message' => 'One or more meta keys are not writable by this ability.',
			],
			'data'    => [
				'meta' => [
					'written'     => [],
					'not_written' => $prepared['not_written'],
				],
			],
		];
	}

	private static function permission( $cap ) {
		return function () use ( $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability." );
			}
			return true;
		};
	}

	private static function object_permission( $type, $input_key, $cap ) {
		return function ( $input = [] ) use ( $type, $input_key, $cap ) {
			$id   = absint( $input[ $input_key ] ?? 0 );
			$post = get_post( $id );

			if ( ! $post || $post->post_type !== $type ) {
				return new WP_Error( 'not_found', ucfirst( $type ) . ' not found.' );
			}
			if ( ! current_user_can( $cap, $id ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability for this {$type}." );
			}
			return true;
		};
	}

	private static function create_permission( $type ) {
		return function ( $input = [] ) use ( $type ) {
			$edit_cap    = 'post' === $type ? 'edit_posts' : 'edit_pages';
			$publish_cap = 'post' === $type ? 'publish_posts' : 'publish_pages';
			$status      = $input['status'] ?? 'draft';

			if ( ! current_user_can( $edit_cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$edit_cap} capability." );
			}
			if ( in_array( $status, [ 'publish', 'future' ], true ) && ! current_user_can( $publish_cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$publish_cap} capability." );
			}
			if ( 'page' === $type && ! empty( $input['parent'] ) && ! current_user_can( 'edit_post', absint( $input['parent'] ) ) ) {
				return new WP_Error( 'forbidden', 'Requires edit_post capability for the parent page.' );
			}
			return true;
		};
	}

	private static function restore_permission( $type, $input_key ) {
		return function ( $input = [] ) use ( $type, $input_key ) {
			$id   = absint( $input[ $input_key ] ?? 0 );
			$post = get_post( $id );

			if ( ! $post || $post->post_type !== $type ) {
				return new WP_Error( 'not_found', ucfirst( $type ) . ' not found.' );
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				return new WP_Error( 'forbidden', "Requires delete_post capability for this {$type}." );
			}

			return true;
		};
	}

	private static function featured_image_permission() {
		return function ( $input = [] ) {
			$id   = absint( $input['post_id'] ?? 0 );
			$post = get_post( $id );

			if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
				return new WP_Error( 'not_found', 'Post or page not found.' );
			}

			$cap = 'post' === $post->post_type ? 'edit_posts' : 'edit_pages';
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability." );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'forbidden', 'Requires edit_post capability for this post or page.' );
			}

			return true;
		};
	}

	private static function post_meta_permission() {
		return function ( $input = [] ) {
			$id   = absint( $input['post_id'] ?? 0 );
			$post = get_post( $id );

			if ( ! $post ) {
				return new WP_Error( 'not_found', 'Post not found.' );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'forbidden', 'Requires edit_post capability for this post.' );
			}

			return true;
		};
	}

	private static function revision_target_permission( $input_key ) {
		return function ( $input = [] ) use ( $input_key ) {
			$id   = absint( $input[ $input_key ] ?? 0 );
			$post = get_post( $id );

			if ( ! $post ) {
				return new WP_Error( 'not_found', 'Post or revision not found.' );
			}

			$parent_id = 'revision' === $post->post_type ? (int) $post->post_parent : (int) $post->ID;
			$parent    = get_post( $parent_id );

			if ( ! $parent || ! in_array( $parent->post_type, [ 'post', 'page' ], true ) ) {
				return new WP_Error( 'not_found', 'Post or page not found.' );
			}
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error( 'forbidden', 'Requires edit_posts capability.' );
			}
			if ( ! current_user_can( 'edit_post', $parent_id ) ) {
				return new WP_Error( 'forbidden', 'Requires edit_post capability for this post or page.' );
			}

			return true;
		};
	}

	private static function bulk_post_ids_schema( $description ) {
		return [
			'type'       => 'object',
			'properties' => [
				'ids' => [
					'type'        => 'array',
					'description' => $description,
					'items'       => [ 'type' => 'integer' ],
					'minItems'    => 1,
				],
			],
			'required'   => [ 'ids' ],
		];
	}

	private static function bulk_post_summary( $ids, $successes, $failures ) {
		return [
			'success' => true,
			'data'    => [
				'requested'     => count( $ids ),
				'success_count' => count( $successes ),
				'failure_count' => count( $failures ),
				'successes'     => $successes,
				'failures'      => $failures,
			],
		];
	}

	private static function register_bulk_trash_posts() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/bulk-trash-posts', [
			'label'               => 'Bulk Trash Posts',
			'description'         => 'Move multiple WordPress posts to trash and return per-post successes and failures.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => self::bulk_post_ids_schema( 'Post IDs to move to trash.' ),
			'execute_callback'    => function ( $input ) {
				$ids       = array_map( 'absint', (array) ( $input['ids'] ?? [] ) );
				$successes = [];
				$failures  = [];

				foreach ( $ids as $id ) {
					$post = get_post( $id );

					if ( ! $id || ! $post || 'post' !== $post->post_type ) {
						$failures[] = [
							'id'      => $id,
							'code'    => 'not_found',
							'message' => 'Post not found.',
						];
						continue;
					}

					if ( ! current_user_can( 'delete_post', $id ) ) {
						$failures[] = [
							'id'      => $id,
							'code'    => 'forbidden',
							'message' => 'You do not have permission to delete this post.',
						];
						continue;
					}

					$result = wp_trash_post( $id );
					if ( ! $result ) {
						$failures[] = [
							'id'      => $id,
							'code'    => 'trash_failed',
							'message' => 'Failed to trash post.',
						];
						continue;
					}

					$successes[] = [
						'id'     => $id,
						'status' => 'trash',
					];
				}

				return self::bulk_post_summary( $ids, $successes, $failures );
			},
			'permission_callback' => self::permission( 'delete_posts' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_bulk_publish_posts() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/bulk-publish-posts', [
			'label'               => 'Bulk Publish Posts',
			'description'         => 'Publish multiple draft WordPress posts and return per-post successes and failures.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => self::bulk_post_ids_schema( 'Draft post IDs to publish.' ),
			'execute_callback'    => function ( $input ) {
				$ids       = array_map( 'absint', (array) ( $input['ids'] ?? [] ) );
				$successes = [];
				$failures  = [];

				foreach ( $ids as $id ) {
					$post = get_post( $id );

					if ( ! $id || ! $post || 'post' !== $post->post_type ) {
						$failures[] = [
							'id'      => $id,
							'code'    => 'not_found',
							'message' => 'Post not found.',
						];
						continue;
					}

					if ( ! current_user_can( 'edit_post', $id ) ) {
						$failures[] = [
							'id'      => $id,
							'code'    => 'forbidden',
							'message' => 'You do not have permission to edit this post.',
						];
						continue;
					}

					if ( 'draft' !== $post->post_status ) {
						$failures[] = [
							'id'      => $id,
							'code'    => 'invalid_status',
							'message' => 'Only draft posts can be bulk published.',
						];
						continue;
					}

					$result = wp_update_post(
						wp_slash(
							[
								'ID'          => $id,
								'post_status' => 'publish',
							]
						),
						true
					);

					if ( is_wp_error( $result ) ) {
						$failures[] = [
							'id'      => $id,
							'code'    => $result->get_error_code(),
							'message' => $result->get_error_message(),
						];
						continue;
					}

					$successes[] = [
						'id'     => $id,
						'status' => 'publish',
					];
				}

				return self::bulk_post_summary( $ids, $successes, $failures );
			},
			'permission_callback' => self::permission( 'edit_posts' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function get_featured_image_target( $input ) {
		$id   = absint( $input['post_id'] ?? 0 );
		$post = get_post( $id );

		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return new WP_Error( 'not_found', 'Post or page not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to update this post or page.' );
		}

		return $post;
	}

	private static function error_response( $code, $message, $data = [] ) {
		$response = [
			'success' => false,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];

		if ( ! empty( $data ) ) {
			$response['data'] = $data;
		}

		return $response;
	}

	private static function content_hash( $content ) {
		return hash( 'sha256', (string) $content );
	}

	private static function block_hash( $block ) {
		return self::content_hash( serialize_block( $block ) );
	}

	private static function is_empty_freeform_block( $block ) {
		return null === ( $block['blockName'] ?? null )
			&& '' === trim( $block['innerHTML'] ?? '' )
			&& empty( $block['innerBlocks'] );
	}

	private static function filter_empty_freeform_blocks( $blocks ) {
		$filtered = [];

		foreach ( $blocks as $block ) {
			if ( self::is_empty_freeform_block( $block ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::filter_empty_freeform_blocks( $block['innerBlocks'] );
			}

			$filtered[] = $block;
		}

		return $filtered;
	}

	private static function parse_editable_blocks( $content ) {
		return self::filter_empty_freeform_blocks( parse_blocks( $content ) );
	}

	private static function block_text( $block ) {
		$html = $block['innerHTML'] ?? '';
		if ( '' === $html && ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$html = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
		}

		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) );
	}

	private static function heading_text( $block ) {
		if ( 'core/heading' !== ( $block['blockName'] ?? null ) ) {
			return '';
		}

		return self::block_text( $block );
	}

	private static function heading_level( $block ) {
		if ( isset( $block['attrs']['level'] ) ) {
			return max( 1, min( 6, absint( $block['attrs']['level'] ) ) );
		}

		if ( ! empty( $block['innerHTML'] ) && preg_match( '/<h([1-6])\b/i', $block['innerHTML'], $matches ) ) {
			return absint( $matches[1] );
		}

		return 2;
	}

	private static function get_content_target( $content_id, $content_type ) {
		$id           = absint( $content_id );
		$content_type = sanitize_key( $content_type );
		$post         = get_post( $id );

		if ( ! in_array( $content_type, [ 'post', 'page' ], true ) ) {
			return new WP_Error( 'invalid_content_type', 'content_type must be post or page.' );
		}
		if ( ! $post || $post->post_type !== $content_type ) {
			return new WP_Error( 'not_found', ucfirst( $content_type ) . ' not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit this ' . $content_type . '.' );
		}

		return $post;
	}

	private static function content_permission() {
		return function ( $input = [] ) {
			$post = self::get_content_target( $input['content_id'] ?? 0, $input['content_type'] ?? '' );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			return true;
		};
	}

	private static function normalize_block( $block, $path ) {
		return [
			'path'              => $path,
			'block_name'        => $block['blockName'] ?? null,
			'text'              => self::block_text( $block ),
			'html'              => $block['innerHTML'] ?? '',
			'attrs'             => $block['attrs'] ?? [],
			'inner_block_count' => count( $block['innerBlocks'] ?? [] ),
			'hash'              => self::block_hash( $block ),
		];
	}

	private static function flatten_blocks( $blocks, $prefix = '' ) {
		$flat = [];

		foreach ( $blocks as $index => $block ) {
			$path   = '' === $prefix ? (string) $index : "{$prefix}.{$index}";
			$flat[] = self::normalize_block( $block, $path );

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, self::flatten_blocks( $block['innerBlocks'], $path ) );
			}
		}

		return $flat;
	}

	private static function parse_block_path( $path ) {
		$path = trim( (string) $path );

		if ( '' === $path || ! preg_match( '/^\d+(?:\.\d+)*$/', $path ) ) {
			return new WP_Error( 'invalid_block_path', 'block_path must be a dotted numeric path like 0 or 2.1.' );
		}

		return array_map( 'absint', explode( '.', $path ) );
	}

	private static function get_block_by_segments( $blocks, $segments ) {
		$current_blocks = $blocks;
		$current_block  = null;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $current_blocks ) || ! array_key_exists( $segment, $current_blocks ) ) {
				return new WP_Error( 'target_not_found', 'Block path not found.' );
			}

			$current_block  = $current_blocks[ $segment ];
			$current_blocks = $current_block['innerBlocks'] ?? [];
		}

		return $current_block;
	}

	private static function replace_block_by_segments( &$blocks, $segments, $replacement_block ) {
		$segment = array_shift( $segments );

		if ( ! array_key_exists( $segment, $blocks ) ) {
			return new WP_Error( 'target_not_found', 'Block path not found.' );
		}

		if ( empty( $segments ) ) {
			$blocks[ $segment ] = $replacement_block;
			return true;
		}

		if ( empty( $blocks[ $segment ]['innerBlocks'] ) || ! is_array( $blocks[ $segment ]['innerBlocks'] ) ) {
			return new WP_Error( 'target_not_found', 'Block path not found.' );
		}

		return self::replace_block_by_segments( $blocks[ $segment ]['innerBlocks'], $segments, $replacement_block );
	}

	private static function find_block_paths_by_hash( $blocks, $hash, $prefix = '' ) {
		$matches = [];

		foreach ( $blocks as $index => $block ) {
			$path = '' === $prefix ? (string) $index : "{$prefix}.{$index}";

			if ( self::block_hash( $block ) === $hash ) {
				$matches[] = $path;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$matches = array_merge( $matches, self::find_block_paths_by_hash( $block['innerBlocks'], $hash, $path ) );
			}
		}

		return $matches;
	}

	private static function register_list_content_blocks() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-content-blocks', [
			'label'               => 'List Content Blocks',
			'description'         => 'List Gutenberg block paths and hashes for a post or page.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'content_id'   => [
						'type'        => 'integer',
						'description' => 'Post or page ID to inspect',
					],
					'content_type' => [
						'type'        => 'string',
						'enum'        => [ 'post', 'page' ],
						'description' => 'Content type to inspect.',
					],
				],
				'required'   => [ 'content_id', 'content_type' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = self::get_content_target( $input['content_id'], $input['content_type'] );

				if ( is_wp_error( $post ) ) {
					return self::error_response( $post->get_error_code(), $post->get_error_message() );
				}

				$blocks = self::parse_editable_blocks( $post->post_content );

				return [
					'success' => true,
					'data'    => [
						'id'           => $post->ID,
						'type'         => $post->post_type,
						'content_hash' => self::content_hash( $post->post_content ),
						'blocks'       => self::flatten_blocks( $blocks ),
					],
				];
			},
			'permission_callback' => self::content_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_patch_content_block() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/patch-content-block', [
			'label'               => 'Patch Content Block',
			'description'         => 'Replace one exact Gutenberg block in a post or page by path or unique hash.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'content_id'            => [
						'type'        => 'integer',
						'description' => 'Post or page ID to patch',
					],
					'content_type'          => [
						'type'        => 'string',
						'enum'        => [ 'post', 'page' ],
						'description' => 'Content type to patch.',
					],
					'target_type'           => [
						'type'        => 'string',
						'enum'        => [ 'block_path', 'block_hash' ],
						'description' => 'Use block_path from list-content-blocks, or a unique block_hash.',
					],
					'block_path'            => [
						'type'        => 'string',
						'description' => 'Dotted block path, such as 0 or 2.1.',
					],
					'block_hash'            => [
						'type'        => 'string',
						'description' => 'Current block hash from list-content-blocks.',
					],
					'replacement_content'   => [
						'type'        => 'string',
						'description' => 'Replacement content that parses to exactly one block.',
					],
					'expected_block_hash'   => [
						'type'        => 'string',
						'description' => 'Optional expected hash for the block at the target path.',
					],
					'expected_content_hash' => [
						'type'        => 'string',
						'description' => 'Optional sha256 hash of the current post_content.',
					],
				],
				'required'   => [ 'content_id', 'content_type', 'target_type', 'replacement_content' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = self::get_content_target( $input['content_id'], $input['content_type'] );

				if ( is_wp_error( $post ) ) {
					return self::error_response( $post->get_error_code(), $post->get_error_message() );
				}

				$current_content = $post->post_content;
				$before_hash     = self::content_hash( $current_content );
				$expected_hash   = sanitize_text_field( $input['expected_content_hash'] ?? '' );

				if ( '' !== $expected_hash && ! hash_equals( $before_hash, $expected_hash ) ) {
					return self::error_response( 'precondition_failed', 'Content hash did not match; reload before patching.' );
				}

				$blocks      = self::parse_editable_blocks( $current_content );
				$target_type = sanitize_key( $input['target_type'] );

				if ( 'block_path' === $target_type ) {
					$segments = self::parse_block_path( $input['block_path'] ?? '' );
				} elseif ( 'block_hash' === $target_type ) {
					$hash = sanitize_text_field( $input['block_hash'] ?? '' );
					if ( '' === $hash ) {
						return self::error_response( 'missing_target', 'block_hash is required when target_type is block_hash.' );
					}

					$matches = self::find_block_paths_by_hash( $blocks, $hash );
					if ( 0 === count( $matches ) ) {
						return self::error_response( 'target_not_found', 'Block hash target not found.' );
					}
					if ( count( $matches ) > 1 ) {
						return self::error_response( 'ambiguous_target', 'Block hash target matched more than once.' );
					}

					$segments = self::parse_block_path( $matches[0] );
				} else {
					return self::error_response( 'invalid_target_type', 'target_type must be block_path or block_hash.' );
				}

				if ( is_wp_error( $segments ) ) {
					return self::error_response( $segments->get_error_code(), $segments->get_error_message() );
				}

				$target_block = self::get_block_by_segments( $blocks, $segments );
				if ( is_wp_error( $target_block ) ) {
					return self::error_response( $target_block->get_error_code(), $target_block->get_error_message() );
				}

				$before_block_hash = self::block_hash( $target_block );
				$expected_block    = sanitize_text_field( $input['expected_block_hash'] ?? '' );
				if ( '' !== $expected_block && ! hash_equals( $before_block_hash, $expected_block ) ) {
					return self::error_response( 'precondition_failed', 'Block hash did not match; reload before patching.' );
				}

				$replacement_blocks = self::parse_editable_blocks( wp_kses_post( $input['replacement_content'] ) );
				if ( 1 !== count( $replacement_blocks ) ) {
					return self::error_response( 'invalid_replacement', 'replacement_content must parse to exactly one block.' );
				}

				$replacement_result = self::replace_block_by_segments( $blocks, $segments, $replacement_blocks[0] );
				if ( is_wp_error( $replacement_result ) ) {
					return self::error_response( $replacement_result->get_error_code(), $replacement_result->get_error_message() );
				}

				$result = wp_update_post(
					wp_slash(
						[
							'ID'           => $post->ID,
							'post_content' => wp_kses_post( serialize_blocks( $blocks ) ),
						]
					),
					true
				);

				if ( is_wp_error( $result ) ) {
					return self::error_response( 'update_failed', $result->get_error_message() );
				}

				$updated_post = get_post( $post->ID );
				$path         = implode( '.', $segments );

				return [
					'success' => true,
					'data'    => [
						'id'                  => $post->ID,
						'type'                => $post->post_type,
						'target'              => [
							'type'       => $target_type,
							'block_path' => $path,
						],
						'block_hash_before'   => $before_block_hash,
						'block_hash_after'    => self::block_hash( $replacement_blocks[0] ),
						'content_hash_before' => $before_hash,
						'content_hash_after'  => self::content_hash( $updated_post->post_content ),
						'content'             => $updated_post->post_content,
					],
				];
			},
			'permission_callback' => self::content_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function patch_content_by_heading( $content, $heading_text, $replacement_content ) {
		$blocks  = self::parse_editable_blocks( $content );
		$matches = [];
		$count   = count( $blocks );

		foreach ( $blocks as $index => $block ) {
			if ( self::heading_text( $block ) === $heading_text ) {
				$matches[] = $index;
			}
		}

		if ( 0 === count( $matches ) ) {
			return new WP_Error( 'target_not_found', 'Heading target not found.' );
		}
		if ( count( $matches ) > 1 ) {
			return new WP_Error( 'ambiguous_target', 'Heading target matched more than once.' );
		}

		$heading_index      = $matches[0];
		$heading_level      = self::heading_level( $blocks[ $heading_index ] );
		$section_start      = $heading_index + 1;
		$section_end        = count( $blocks );
		$replacement_blocks = self::parse_editable_blocks( $replacement_content );

		for ( $index = $section_start; $index < $count; $index++ ) {
			$is_next_section = 'core/heading' === ( $blocks[ $index ]['blockName'] ?? null )
				&& self::heading_level( $blocks[ $index ] ) <= $heading_level;

			if ( $is_next_section ) {
				$section_end = $index;
				break;
			}
		}

		array_splice( $blocks, $section_start, $section_end - $section_start, $replacement_blocks );

		return [
			'content'         => serialize_blocks( $blocks ),
			'replaced_blocks' => $section_end - $section_start,
			'target'          => [
				'type'          => 'heading',
				'heading_text'  => $heading_text,
				'heading_level' => $heading_level,
			],
		];
	}

	private static function patch_content_by_exact_match( $content, $old_content, $replacement_content ) {
		$count = substr_count( $content, $old_content );

		if ( 0 === $count ) {
			return new WP_Error( 'target_not_found', 'Exact content target not found.' );
		}
		if ( $count > 1 ) {
			return new WP_Error( 'ambiguous_target', 'Exact content target matched more than once.' );
		}

		return [
			'content'         => str_replace( $old_content, $replacement_content, $content ),
			'replaced_blocks' => null,
			'target'          => [
				'type' => 'exact',
			],
		];
	}

	private static function register_patch_post_content() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/patch-post-content', [
			'label'               => 'Patch Post Content',
			'description'         => 'Safely update one targeted part of a post body without replacing the full post content.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'               => [
						'type'        => 'integer',
						'description' => 'Post ID to patch',
					],
					'target_type'           => [
						'type'        => 'string',
						'enum'        => [ 'heading', 'exact' ],
						'description' => 'Use heading for block-aware section replacement, or exact for strict raw-content replacement.',
					],
					'heading_text'          => [
						'type'        => 'string',
						'description' => 'Exact heading text to target when target_type is heading.',
					],
					'old_content'           => [
						'type'        => 'string',
						'description' => 'Exact current content to replace when target_type is exact.',
					],
					'replacement_content'   => [
						'type'        => 'string',
						'description' => 'Replacement HTML or block markup for the targeted content.',
					],
					'expected_content_hash' => [
						'type'        => 'string',
						'description' => 'Optional sha256 hash of the current post_content; fails if the post changed before patching.',
					],
				],
				'required'   => [ 'post_id', 'target_type', 'replacement_content' ],
			],
			'execute_callback'    => function ( $input ) {
				$id   = absint( $input['post_id'] );
				$post = get_post( $id );

				if ( ! $post || 'post' !== $post->post_type ) {
					return self::error_response( 'not_found', 'Post not found.' );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return self::error_response( 'forbidden', 'You do not have permission to patch this post.' );
				}

				$current_content = $post->post_content;
				$before_hash     = self::content_hash( $current_content );

				$expected_hash = sanitize_text_field( $input['expected_content_hash'] ?? '' );

				if ( '' !== $expected_hash && ! hash_equals( $before_hash, $expected_hash ) ) {
					return self::error_response( 'precondition_failed', 'Post content hash did not match; reload the post before patching.' );
				}

				$target_type         = sanitize_key( $input['target_type'] );
				$replacement_content = wp_kses_post( $input['replacement_content'] );

				if ( 'heading' === $target_type ) {
					if ( empty( $input['heading_text'] ) ) {
						return self::error_response( 'missing_target', 'heading_text is required when target_type is heading.' );
					}

					$patch = self::patch_content_by_heading(
						$current_content,
						sanitize_text_field( $input['heading_text'] ),
						$replacement_content
					);
				} elseif ( 'exact' === $target_type ) {
					if ( ! isset( $input['old_content'] ) || '' === $input['old_content'] ) {
						return self::error_response( 'missing_target', 'old_content is required when target_type is exact.' );
					}

					$patch = self::patch_content_by_exact_match(
						$current_content,
						wp_kses_post( $input['old_content'] ),
						$replacement_content
					);
				} else {
					return self::error_response( 'invalid_target_type', 'target_type must be heading or exact.' );
				}

				if ( is_wp_error( $patch ) ) {
					return self::error_response( $patch->get_error_code(), $patch->get_error_message() );
				}

				$args = [
					'ID'           => $id,
					'post_content' => wp_kses_post( $patch['content'] ),
				];

				$result = wp_update_post( wp_slash( $args ), true );

				if ( is_wp_error( $result ) ) {
					return self::error_response( 'update_failed', $result->get_error_message() );
				}

				$updated_post = get_post( $id );
				$after_hash   = self::content_hash( $updated_post->post_content );

				return [
					'success' => true,
					'data'    => [
						'id'                  => $id,
						'target'              => $patch['target'],
						'replaced_blocks'     => $patch['replaced_blocks'],
						'content_hash_before' => $before_hash,
						'content_hash_after'  => $after_hash,
						'post'                => self::normalize( $id ),
					],
				];
			},
			'permission_callback' => self::object_permission( 'post', 'post_id', 'edit_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_set_featured_image() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/set-featured-image', [
			'label'               => 'Set Featured Image',
			'description'         => 'Set the featured image for a WordPress post or page by attachment ID.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'       => [ 'type' => 'integer', 'description' => 'Post or page ID' ],
					'attachment_id' => [ 'type' => 'integer', 'description' => 'Image attachment ID to use as the featured image' ],
				],
				'required'   => [ 'post_id', 'attachment_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = self::get_featured_image_target( $input );

				if ( is_wp_error( $post ) ) {
					return [ 'success' => false, 'error' => $post->get_error_message() ];
				}

				$attachment_id = absint( $input['attachment_id'] );
				$attachment    = get_post( $attachment_id );

				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return [ 'success' => false, 'error' => 'Attachment not found.' ];
				}
				if ( ! wp_attachment_is_image( $attachment_id ) ) {
					return [ 'success' => false, 'error' => 'Attachment must be an image.' ];
				}

				$result = set_post_thumbnail( $post->ID, $attachment_id );

				if ( ! $result ) {
					return [ 'success' => false, 'error' => 'Failed to set featured image.' ];
				}

				return [ 'success' => true, 'data' => self::normalize( $post->ID ) ];
			},
			'permission_callback' => self::featured_image_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_remove_featured_image() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/remove-featured-image', [
			'label'               => 'Remove Featured Image',
			'description'         => 'Remove the featured image from a WordPress post or page.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'Post or page ID' ],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = self::get_featured_image_target( $input );

				if ( is_wp_error( $post ) ) {
					return [ 'success' => false, 'error' => $post->get_error_message() ];
				}

				delete_post_thumbnail( $post->ID );

				return [ 'success' => true, 'data' => self::normalize( $post->ID ) ];
			},
			'permission_callback' => self::featured_image_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function normalize_revision( $revision ) {
		$revision = get_post( $revision );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return null;
		}

		return [
			'id'            => (int) $revision->ID,
			'post_id'       => (int) $revision->post_parent,
			'author'        => (int) $revision->post_author,
			'author_name'   => get_the_author_meta( 'display_name', (int) $revision->post_author ),
			'author_login'  => get_the_author_meta( 'user_login', (int) $revision->post_author ),
			'title'         => $revision->post_title,
			'content'       => $revision->post_content,
			'excerpt'       => $revision->post_excerpt,
			'date_created'  => $revision->post_date,
			'date_modified' => $revision->post_modified,
		];
	}

	private static function register_list_revisions() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-revisions', [
			'label'               => 'List Revisions',
			'description'         => 'List saved revisions for a WordPress post or page.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'  => [ 'type' => 'integer', 'description' => 'Post or page ID whose revisions should be listed.' ],
					'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$post_id = absint( $input['post_id'] ?? 0 );
				$post    = get_post( $post_id );

				if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
					return self::error_response( 'not_found', 'Post or page not found.' );
				}
				if ( ! current_user_can( 'edit_posts' ) ) {
					return self::error_response( 'forbidden', 'Requires edit_posts capability.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return self::error_response( 'forbidden', 'You do not have permission to list revisions for this post or page.' );
				}

				$per_page  = min( max( 1, absint( $input['per_page'] ?? 20 ) ), 100 );
				$revisions = wp_get_post_revisions(
					$post_id,
					[
						'posts_per_page' => $per_page,
						'orderby'        => 'date',
						'order'          => 'DESC',
					]
				);

				return [
					'success' => true,
					'data'    => [
						'post_id'   => $post_id,
						'type'      => $post->post_type,
						'revisions' => array_values( array_filter( array_map( [ self::class, 'normalize_revision' ], $revisions ) ) ),
					],
				];
			},
			'permission_callback' => self::revision_target_permission( 'post_id' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_restore_revision() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/restore-revision', [
			'label'               => 'Restore Revision',
			'description'         => 'Restore a WordPress post or page to a specific saved revision.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'revision_id' => [ 'type' => 'integer', 'description' => 'Revision ID to restore.' ],
				],
				'required'   => [ 'revision_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$revision_id = absint( $input['revision_id'] ?? 0 );
				$revision    = get_post( $revision_id );

				if ( ! $revision || 'revision' !== $revision->post_type ) {
					return self::error_response( 'not_found', 'Revision not found.' );
				}

				$post_id = (int) $revision->post_parent;
				$post    = get_post( $post_id );

				if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
					return self::error_response( 'not_found', 'Post or page not found.' );
				}
				if ( ! current_user_can( 'edit_posts' ) ) {
					return self::error_response( 'forbidden', 'Requires edit_posts capability.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return self::error_response( 'forbidden', 'You do not have permission to restore revisions for this post or page.' );
				}

				$result = wp_restore_post_revision( $revision_id );

				if ( ! $result || is_wp_error( $result ) ) {
					$message = is_wp_error( $result ) ? $result->get_error_message() : 'Failed to restore revision.';
					return self::error_response( 'restore_revision_failed', $message );
				}

				return [
					'success' => true,
					'data'    => [
						'revision' => self::normalize_revision( $revision_id ),
						'post'     => self::normalize( $post_id ),
					],
				];
			},
			'permission_callback' => self::revision_target_permission( 'revision_id' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_get_post_meta() {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Ability schema and response field names, not query arguments.
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-post-meta', [
			'label'               => 'Get Post Meta',
			'description'         => 'Get allowed custom field values for a post. Protected keys are hidden unless explicitly allowlisted by the plugin.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'  => [ 'type' => 'integer', 'description' => 'Post ID whose meta should be read.' ],
					'meta_key' => [ 'type' => 'string', 'description' => 'Optional specific meta key to read.' ],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$post_id = absint( $input['post_id'] ?? 0 );
				$post    = get_post( $post_id );

				if ( ! $post ) {
					return self::error_response( 'not_found', 'Post not found.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return self::error_response( 'forbidden', 'You do not have permission to read meta for this post.' );
				}

				$meta = [];

				if ( isset( $input['meta_key'] ) && '' !== (string) $input['meta_key'] ) {
					$key = self::validate_post_meta_key( $input['meta_key'] );
					if ( is_wp_error( $key ) ) {
						return self::error_response( $key->get_error_code(), $key->get_error_message() );
					}
					if ( ! self::can_access_post_meta_key( $key ) ) {
						return self::error_response( 'protected_meta_key', 'Protected meta keys are denied unless explicitly allowlisted.' );
					}

					$meta[ $key ] = array_map( [ self::class, 'normalize_post_meta_response_value' ], get_post_meta( $post_id, $key, false ) );
				} else {
					foreach ( get_post_meta( $post_id ) as $key => $values ) {
						$key = (string) $key;
						if ( ! self::can_access_post_meta_key( $key ) ) {
							continue;
						}

						$meta[ $key ] = array_map( [ self::class, 'normalize_post_meta_response_value' ], (array) $values );
					}
				}

				return [
					'success' => true,
					'data'    => [
						'post_id' => $post_id,
						'meta'    => $meta,
					],
				];
			},
			'permission_callback' => self::post_meta_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	}

	private static function register_update_post_meta() {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Ability schema and response field names, not query arguments.
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/update-post-meta', [
			'label'               => 'Update Post Meta',
			'description'         => 'Update one allowed post meta key with a scalar or structured JSON-compatible value.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'    => [ 'type' => 'integer', 'description' => 'Post ID whose meta should be updated.' ],
					'meta_key'   => [ 'type' => 'string', 'description' => 'Meta key to update.' ],
					'meta_value' => [
						'type'        => [ 'string', 'number', 'integer', 'boolean', 'object', 'array' ],
						'description' => 'Scalar value or JSON object/array to store.',
					],
				],
				'required'   => [ 'post_id', 'meta_key', 'meta_value' ],
			],
			'execute_callback'    => function ( $input ) {
				$post_id = absint( $input['post_id'] ?? 0 );
				$post    = get_post( $post_id );

				if ( ! $post ) {
					return self::error_response( 'not_found', 'Post not found.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return self::error_response( 'forbidden', 'You do not have permission to update meta for this post.' );
				}

				$key = self::validate_post_meta_key( $input['meta_key'] ?? '' );
				if ( is_wp_error( $key ) ) {
					return self::error_response( $key->get_error_code(), $key->get_error_message() );
				}
				if ( ! self::can_access_post_meta_key( $key ) ) {
					return self::error_response( 'protected_meta_key', 'Protected meta keys are denied unless explicitly allowlisted.' );
				}

				$value = self::prepare_post_meta_update_value( $key, $input['meta_value'] ?? null );
				if ( is_wp_error( $value ) ) {
					return self::error_response( $value->get_error_code(), $value->get_error_message() );
				}

				$previous_value = self::normalize_post_meta_response_value( get_post_meta( $post_id, $key, true ) );
				$updated        = update_post_meta( $post_id, $key, wp_slash( $value ) );
				$current_value  = self::normalize_post_meta_response_value( get_post_meta( $post_id, $key, true ) );

				return [
					'success' => true,
					'data'    => [
						'post_id'        => $post_id,
						'meta_key'       => $key,
						'updated'        => (bool) $updated,
						'previous_value' => $previous_value,
						'current_value'  => $current_value,
					],
				];
			},
			'permission_callback' => self::post_meta_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	}

	private static function register_delete_post_meta() {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Ability schema and response field names, not query arguments.
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/delete-post-meta', [
			'label'               => 'Delete Post Meta',
			'description'         => 'Delete one allowed post meta key from a post.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'  => [ 'type' => 'integer', 'description' => 'Post ID whose meta should be deleted.' ],
					'meta_key' => [ 'type' => 'string', 'description' => 'Meta key to delete.' ],
				],
				'required'   => [ 'post_id', 'meta_key' ],
			],
			'execute_callback'    => function ( $input ) {
				$post_id = absint( $input['post_id'] ?? 0 );
				$post    = get_post( $post_id );

				if ( ! $post ) {
					return self::error_response( 'not_found', 'Post not found.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return self::error_response( 'forbidden', 'You do not have permission to delete meta for this post.' );
				}

				$key = self::validate_post_meta_key( $input['meta_key'] ?? '' );
				if ( is_wp_error( $key ) ) {
					return self::error_response( $key->get_error_code(), $key->get_error_message() );
				}
				if ( ! self::can_access_post_meta_key( $key ) ) {
					return self::error_response( 'protected_meta_key', 'Protected meta keys are denied unless explicitly allowlisted.' );
				}

				$before_count  = count( get_post_meta( $post_id, $key, false ) );
				$deleted       = delete_post_meta( $post_id, $key );
				$deleted_count = $deleted ? $before_count : 0;

				return [
					'success' => true,
					'data'    => [
						'post_id'       => $post_id,
						'meta_key'      => $key,
						'deleted_count' => $deleted_count,
					],
				];
			},
			'permission_callback' => self::post_meta_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}

	private static function register_post_type( $type ) {
		$slug  = 'post' === $type ? 'posts' : 'pages';
		$label = 'post' === $type ? 'Post' : 'Page';

		// --- list ---
		$list_input = [
			'type'       => 'object',
			'properties' => [
				'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash', 'any' ], 'default' => 'any' ],
				'search'   => [ 'type' => 'string' ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
				'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'author'   => [ 'type' => 'integer' ],
				'orderby'  => [ 'type' => 'string', 'enum' => [ 'date', 'title', 'modified', 'id' ], 'default' => 'date' ],
				'order'    => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
			],
		];

		if ( 'post' === $type ) {
			$list_input['properties']['category_id'] = [ 'type' => 'integer' ];
		}

		wp_register_ability( "webmastery-site-toolkit-for-mcp/list-{$slug}", [
			'label'               => "List {$label}s",
			'description'         => "List WordPress {$slug} with optional filters.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => $list_input,
			'execute_callback'    => function ( $input ) use ( $type, $slug ) {
				$args = [
					'post_type'      => $type,
					'post_status'    => $input['status'] ?? 'any',
					'posts_per_page' => min( (int) ( $input['per_page'] ?? 20 ), 100 ),
					'paged'          => max( 1, (int) ( $input['page'] ?? 1 ) ),
					'orderby'        => $input['orderby'] ?? 'date',
					'order'          => strtoupper( $input['order'] ?? 'DESC' ),
				];

				if ( ! empty( $input['search'] ) ) {
					$args['s'] = sanitize_text_field( $input['search'] );
				}
				if ( ! empty( $input['author'] ) ) {
					$args['author'] = absint( $input['author'] );
				}
				if ( ! current_user_can( 'edit_others_' . $slug ) ) {
					$args['author'] = get_current_user_id();
				}
				if ( 'post' === $type && ! empty( $input['category_id'] ) ) {
					$args['cat'] = absint( $input['category_id'] );
				}

				$query = new WP_Query( $args );

				return [
					'success' => true,
					'data'    => [
						'items'       => array_values( array_filter( array_map( [ self::class, 'normalize' ], $query->posts ) ) ),
						'total'       => (int) $query->found_posts,
						'total_pages' => (int) $query->max_num_pages,
					],
				];
			},
			'permission_callback' => self::permission( 'post' === $type ? 'edit_posts' : 'edit_pages' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- get ---
		wp_register_ability( "webmastery-site-toolkit-for-mcp/get-{$type}", [
			'label'               => "Get {$label}",
			'description'         => "Get a single WordPress {$type} by ID.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					"{$type}_id" => [ 'type' => 'integer', 'description' => ucfirst( $type ) . ' ID' ],
				],
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return [ 'success' => false, 'error' => ucfirst( $type ) . ' not found.' ];
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to view this ' . $type . '.' ];
				}

				return [ 'success' => true, 'data' => self::normalize( $post ) ];
			},
			'permission_callback' => self::object_permission( $type, "{$type}_id", 'edit_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- create ---
		$create_props = [
			'title'   => [ 'type' => 'string', 'description' => "{$label} title" ],
			'content' => [ 'type' => 'string', 'description' => "{$label} content (HTML)" ],
			'status'         => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'future' ], 'default' => 'draft' ],
			'scheduled_date' => [ 'type' => 'string', 'description' => 'ISO 8601 datetime to publish (required when status is future, e.g. 2025-12-01T09:00:00)' ],
			'excerpt'        => [ 'type' => 'string' ],
			'slug'           => [ 'type' => 'string' ],
			'meta'           => self::meta_schema(),
		];

		if ( 'post' === $type ) {
			$create_props['category_ids'] = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
			$create_props['tag_ids']      = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
		}
		if ( 'page' === $type ) {
			$create_props['parent'] = [ 'type' => 'integer', 'description' => 'Parent page ID (0 for top-level)' ];
		}

		$create_props = array_merge( $create_props, self::yoast_input_schema_props(), self::seopress_input_schema_props() );

		wp_register_ability( "webmastery-site-toolkit-for-mcp/create-{$type}", [
			'label'               => "Create {$label}",
			'description'         => "Create a new WordPress {$type}.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $create_props,
				'required'   => [ 'title', 'content' ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$permission = self::create_permission( $type );
				$allowed    = $permission( $input );
				if ( is_wp_error( $allowed ) ) {
					return [ 'success' => false, 'error' => $allowed->get_error_message() ];
				}

				$meta_writes = self::prepare_meta_writes( $input, $type );
				if ( ! empty( $meta_writes['not_written'] ) ) {
					return self::meta_write_error_response( $meta_writes );
				}

				$args = [
					'post_type'    => $type,
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => wp_kses_post( $input['content'] ),
					'post_status'  => in_array( $input['status'] ?? 'draft', [ 'draft', 'publish', 'pending', 'future' ], true )
										? $input['status']
										: 'draft',
				];

				if ( ! empty( $input['scheduled_date'] ) ) {
					$args['post_date']     = wp_date( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $input['scheduled_date'] ) ) );
					$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
				}
				if ( ! empty( $input['excerpt'] ) ) {
					$args['post_excerpt'] = sanitize_text_field( $input['excerpt'] );
				}
				if ( ! empty( $input['slug'] ) ) {
					$args['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( 'page' === $type && isset( $input['parent'] ) ) {
					$args['post_parent'] = absint( $input['parent'] );
				}

				$id = wp_insert_post( wp_slash( $args ), true );

				if ( is_wp_error( $id ) ) {
					return [ 'success' => false, 'error' => $id->get_error_message() ];
				}

				if ( 'post' === $type ) {
					if ( ! empty( $input['category_ids'] ) ) {
						wp_set_post_categories( $id, array_map( 'absint', (array) $input['category_ids'] ) );
					}
					if ( ! empty( $input['tag_ids'] ) ) {
						wp_set_post_tags( $id, array_map( 'absint', (array) $input['tag_ids'] ) );
					}
				}

				$data = self::normalize( $id );
				if ( ! empty( $meta_writes['writes'] ) ) {
					$data['meta'] = [
						'written'     => self::apply_meta_writes( $id, $meta_writes['writes'] ),
						'not_written' => [],
					];
				}

				return [ 'success' => true, 'data' => $data ];
			},
			'permission_callback' => self::create_permission( $type ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- update ---
		$update_props = [
			"{$type}_id" => [ 'type' => 'integer', 'description' => ucfirst( $type ) . ' ID to update' ],
			'title'      => [ 'type' => 'string' ],
			'content'    => [ 'type' => 'string' ],
			'status'         => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'private', 'future' ] ],
			'scheduled_date' => [ 'type' => 'string', 'description' => 'ISO 8601 datetime to publish (required when status is future)' ],
			'excerpt'        => [ 'type' => 'string' ],
			'slug'           => [ 'type' => 'string' ],
			'meta'           => self::meta_schema(),
		];

		if ( 'post' === $type ) {
			$update_props['category_ids'] = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
			$update_props['tag_ids']      = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
		}
		if ( 'page' === $type ) {
			$update_props['parent'] = [ 'type' => 'integer', 'description' => 'Parent page ID (0 for top-level)' ];
		}

		$update_props = array_merge( $update_props, self::yoast_input_schema_props(), self::seopress_input_schema_props() );

		wp_register_ability( "webmastery-site-toolkit-for-mcp/update-{$type}", [
			'label'               => "Update {$label}",
			'description'         => "Update an existing WordPress {$type}.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $update_props,
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type, $slug ) {
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return [ 'success' => false, 'error' => ucfirst( $type ) . ' not found.' ];
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to update this ' . $type . '.' ];
				}
				if ( isset( $input['status'] ) && in_array( $input['status'], [ 'publish', 'future' ], true ) && ! current_user_can( 'publish_' . $slug ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to publish this ' . $type . '.' ];
				}

				$meta_writes = self::prepare_meta_writes( $input, $type );
				if ( ! empty( $meta_writes['not_written'] ) ) {
					return self::meta_write_error_response( $meta_writes );
				}

				$args = [ 'ID' => $id ];

				if ( isset( $input['title'] ) ) {
					$args['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['content'] ) ) {
					$args['post_content'] = wp_kses_post( $input['content'] );
				}
				if ( isset( $input['excerpt'] ) ) {
					$args['post_excerpt'] = sanitize_text_field( $input['excerpt'] );
				}
				if ( isset( $input['slug'] ) ) {
					$args['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( isset( $input['status'] ) && in_array( $input['status'], [ 'draft', 'publish', 'pending', 'private', 'future' ], true ) ) {
					$args['post_status'] = $input['status'];
				}
				if ( ! empty( $input['scheduled_date'] ) ) {
					$args['post_date']     = wp_date( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $input['scheduled_date'] ) ) );
					$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
				}
				if ( 'page' === $type && isset( $input['parent'] ) ) {
					$args['post_parent'] = absint( $input['parent'] );
				}

				$result = wp_update_post( wp_slash( $args ), true );

				if ( is_wp_error( $result ) ) {
					return [ 'success' => false, 'error' => $result->get_error_message() ];
				}

				if ( 'post' === $type ) {
					if ( isset( $input['category_ids'] ) ) {
						wp_set_post_categories( $id, array_map( 'absint', (array) $input['category_ids'] ) );
					}
					if ( isset( $input['tag_ids'] ) ) {
						wp_set_post_tags( $id, array_map( 'absint', (array) $input['tag_ids'] ) );
					}
				}

				$data = self::normalize( $id );
				if ( ! empty( $meta_writes['writes'] ) ) {
					$data['meta'] = [
						'written'     => self::apply_meta_writes( $id, $meta_writes['writes'] ),
						'not_written' => [],
					];
				}

				return [ 'success' => true, 'data' => $data ];
			},
			'permission_callback' => self::object_permission( $type, "{$type}_id", 'edit_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- delete (trash) ---
		wp_register_ability( "webmastery-site-toolkit-for-mcp/delete-{$type}", [
			'label'               => "Delete {$label}",
			'description'         => "Move a WordPress {$type} to trash.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					"{$type}_id" => [ 'type' => 'integer', 'description' => ucfirst( $type ) . ' ID to trash' ],
				],
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return [ 'success' => false, 'error' => ucfirst( $type ) . ' not found.' ];
				}
				if ( ! current_user_can( 'delete_post', $id ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to delete this ' . $type . '.' ];
				}

				$result = wp_trash_post( $id );

				if ( ! $result ) {
					return [ 'success' => false, 'error' => 'Failed to trash ' . $type . '.' ];
				}

				return [ 'success' => true, 'data' => [ 'id' => $id, 'status' => 'trash' ] ];
			},
			'permission_callback' => self::object_permission( $type, "{$type}_id", 'delete_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		// --- restore (untrash) ---
		wp_register_ability( "webmastery-site-toolkit-for-mcp/restore-{$type}", [
			'label'               => "Restore {$label}",
			'description'         => "Restore a WordPress {$type} from trash.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					"{$type}_id" => [ 'type' => 'integer', 'description' => ucfirst( $type ) . ' ID to restore from trash' ],
				],
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return [ 'success' => false, 'error' => ucfirst( $type ) . ' not found.' ];
				}
				if ( ! current_user_can( 'delete_post', $id ) ) {
					return [ 'success' => false, 'error' => "You do not have permission to restore this {$type}." ];
				}

				$result = wp_untrash_post( $id );

				if ( ! $result ) {
					return [ 'success' => false, 'error' => 'Failed to restore ' . $type . ' from trash.' ];
				}

				return [ 'success' => true, 'data' => self::normalize( $id ) ];
			},
			'permission_callback' => self::restore_permission( $type, "{$type}_id" ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
