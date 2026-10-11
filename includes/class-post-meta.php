<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_Post_Meta {
	// A null write type is inspectable only, never a compatibility write grant.
	private const YOAST = [
		'title'                    => [ '_yoast_wpseo_title', 'string' ],
		'meta_description'         => [ '_yoast_wpseo_metadesc', 'string' ],
		'focus_keyphrase'          => [ '_yoast_wpseo_focuskw', 'string' ],
		'canonical_url'            => [ '_yoast_wpseo_canonical', 'url' ],
		'breadcrumb_title'         => [ '_yoast_wpseo_bctitle', 'string' ],
		'schema_page_type'         => [ '_yoast_wpseo_schema_page_type', 'string' ],
		'schema_article_type'      => [ '_yoast_wpseo_schema_article_type', 'string' ],
		'opengraph_title'          => [ '_yoast_wpseo_opengraph-title', 'string' ],
		'opengraph_description'    => [ '_yoast_wpseo_opengraph-description', 'string' ],
		'opengraph_image'          => [ '_yoast_wpseo_opengraph-image', 'url' ],
		'twitter_title'            => [ '_yoast_wpseo_twitter-title', 'string' ],
		'twitter_description'      => [ '_yoast_wpseo_twitter-description', 'string' ],
		'twitter_image'            => [ '_yoast_wpseo_twitter-image', 'url' ],
		'seo_score'                => [ '_yoast_wpseo_linkdex', null ],
		'readability_score'        => [ '_yoast_wpseo_content_score', null ],
		'inclusive_language_score' => [ '_yoast_wpseo_inclusive_language_score', 'integer_string' ],
		'primary_category'         => [ '_yoast_wpseo_primary_category', 'integer_string' ],
		'cornerstone'              => [ '_yoast_wpseo_is_cornerstone', 'boolean_string' ],
		'robots_noindex'           => [ '_yoast_wpseo_meta-robots-noindex', 'boolean_string' ],
		'robots_nofollow'          => [ '_yoast_wpseo_meta-robots-nofollow', 'boolean_string' ],
		'robots_advanced'          => [ '_yoast_wpseo_meta-robots-adv', 'string' ],
	];

	private const SEOPRESS = [
		'title'                  => [ '_seopress_titles_title', 'string' ],
		'meta_description'       => [ '_seopress_titles_desc', 'string' ],
		'focus_keywords'         => [ '_seopress_analysis_target_kw', 'string' ],
		'canonical_url'          => [ '_seopress_robots_canonical', 'url' ],
		'opengraph_title'        => [ '_seopress_social_fb_title', 'string' ],
		'opengraph_description'  => [ '_seopress_social_fb_desc', 'string' ],
		'opengraph_image'        => [ '_seopress_social_fb_img', 'url' ],
		'twitter_title'          => [ '_seopress_social_twitter_title', 'string' ],
		'twitter_description'    => [ '_seopress_social_twitter_desc', 'string' ],
		'twitter_image'          => [ '_seopress_social_twitter_img', 'url' ],
		'primary_category'       => [ '_seopress_robots_primary_cat', 'integer_string' ],
		'robots_noindex'         => [ '_seopress_robots_index', 'seopress_boolean_string' ],
		'robots_nofollow'        => [ '_seopress_robots_follow', 'seopress_boolean_string' ],
		'robots_noimageindex'    => [ '_seopress_robots_imageindex', 'seopress_boolean_string' ],
		'robots_noarchive'       => [ '_seopress_robots_archive', 'seopress_boolean_string' ],
		'robots_nosnippet'       => [ '_seopress_robots_snippet', 'seopress_boolean_string' ],
		'breadcrumb_title'       => [ '_seopress_robots_breadcrumbs', 'string' ],
		'news_sitemap_disabled'  => [ '_seopress_news_disabled', null ],
		'video_sitemap_disabled' => [ '_seopress_video_disabled', null ],
	];

	public static function yoast_keys(): array {
		return array_map( static fn( $definition ) => $definition[0], self::YOAST );
	}

	public static function seopress_keys(): array {
		return array_map( static fn( $definition ) => $definition[0], self::SEOPRESS );
	}

	public static function writable_keys(): array {
		$keys = [];
		foreach ( array_merge( array_values( self::YOAST ), array_values( self::SEOPRESS ) ) as [ $key, $type ] ) {
			if ( null !== $type ) {
				$keys[ $key ] = $type;
			}
		}
		return $keys;
	}

	private const POST_META_KEY_MAX_LENGTH  = 255;
	private const POST_META_VALUE_MAX_BYTES = 100000;
	private const POST_META_VALUE_MAX_DEPTH = 10;

	public static function register() {
		self::register_get_post_meta();
		self::register_update_post_meta();
		self::register_delete_post_meta();
	}

	private static function writable_protected_meta_keys() {
		return self::writable_keys();
	}

	private static function allowed_protected_post_meta_keys() {
		return array_fill_keys( array_keys( self::writable_protected_meta_keys() ), true );
	}

	private static function validate_post_meta_key( $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return Webmastery_MCP_Response::local_error( 'invalid_meta_key', 'meta_key is required.' );
		}
		if ( strlen( $key ) > self::POST_META_KEY_MAX_LENGTH ) {
			return Webmastery_MCP_Response::local_error( 'invalid_meta_key', 'meta_key must be 255 bytes or fewer.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_\-:.]+$/', $key ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_meta_key', 'meta_key may only contain letters, numbers, underscores, hyphens, colons, and periods.' );
		}

		return $key;
	}

	private static function can_access_post_meta_key( $key ) {
		return ! is_protected_meta( $key, 'post' ) || isset( self::allowed_protected_post_meta_keys()[ $key ] );
	}

	public static function reject_combined_metadata( array $input ): ?array {
		$fields = [];
		foreach ( array_keys( $input ) as $field ) {
			if ( in_array( $field, [ 'meta', 'meta_input' ], true )
				|| preg_match( '/^(?:yoast_|seopress_|_yoast_wpseo_|_seopress_)/', (string) $field ) ) {
				$fields[] = $field;
			}
		}
		if ( [] === $fields ) {
			return null;
		}
		return Webmastery_MCP_Response::error(
			'invalid_input',
			'Metadata requires separate calls. Create a draft without metadata, use update-post-meta for each key, verify the results, then publish. These steps are not atomic.',
			[ 'fields' => $fields ],
			'metadata_requires_separate_call'
		);
	}

	public static function can_read_post_meta_key( int $post_id, string $key ): bool {
		return self::can_edit_post_meta_key( $post_id, $key );
	}

	private static function uses_post_meta_compatibility_auth( $key, $type ) {
		return isset( self::allowed_protected_post_meta_keys()[ $key ] )
			&& ! isset( get_registered_meta_keys( 'post' )[ $key ] )
			&& ! isset( get_registered_meta_keys( 'post', $type )[ $key ] )
			&& ! has_filter( "auth_post_meta_{$key}" )
			&& ! has_filter( "auth_post_meta_{$key}_for_{$type}" )
			&& ! has_filter( "auth_post_{$type}_meta_{$key}" );
	}

	private static function can_edit_post_meta_key( $post_id, $key, $cap = 'edit_post_meta' ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$type = get_post_type( $post_id );
		if ( ! self::uses_post_meta_compatibility_auth( $key, $type ) ) {
			return current_user_can( $cap, $post_id, $key );
		}

		// Supply only the unregistered SEO default; core's effective capability filters still decide.
		$hook    = "auth_post_meta_{$key}_for_{$type}";
		$user_id = get_current_user_id();
		$allow   = static function ( $allowed, $meta_key, $object_id, $checked_user_id, $checked_cap ) use ( $key, $post_id, $user_id, $cap ) {
			return $key === $meta_key && $post_id === $object_id && $user_id === $checked_user_id && $cap === $checked_cap ? true : $allowed;
		};
		add_filter( $hook, $allow, 10, 5 );
		try {
			return current_user_can( $cap, $post_id, $key );
		} finally {
			remove_filter( $hook, $allow, 10 );
		}
	}

	private static function normalize_post_meta_value( $value, $depth = 0 ) {
		if ( $depth > self::POST_META_VALUE_MAX_DEPTH ) {
			return Webmastery_MCP_Response::local_error( 'invalid_meta_value', 'meta_value nesting is too deep.' );
		}

		if ( null === $value ) {
			return Webmastery_MCP_Response::local_error( 'invalid_meta_value', 'meta_value must be a scalar, object, or array.' );
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

		return Webmastery_MCP_Response::local_error( 'invalid_meta_value', 'meta_value must be a scalar, object, or array.' );
	}

	private static function validate_post_meta_value( $value ) {
		$normalized = self::normalize_post_meta_value( $value );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$encoded = wp_json_encode( $normalized );
		if ( false === $encoded ) {
			return Webmastery_MCP_Response::local_error( 'invalid_meta_value', 'meta_value could not be encoded safely.' );
		}
		if ( strlen( $encoded ) > self::POST_META_VALUE_MAX_BYTES ) {
			return Webmastery_MCP_Response::local_error( 'invalid_meta_value', 'meta_value must encode to 100000 bytes or fewer.' );
		}

		return $normalized;
	}

	private static function prepare_post_meta_update_value( $key, $value ) {
		$protected_keys = self::writable_protected_meta_keys();

		if ( isset( $protected_keys[ $key ] ) ) {
			$normalized = self::normalize_meta_value( $value, $protected_keys[ $key ] );
			if ( null === $normalized ) {
				return Webmastery_MCP_Response::local_error( 'invalid_meta_value', 'meta_value is not valid for this protected meta key.' );
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

	private static function register_get_post_meta() {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Ability schema and response field names, not query arguments.
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-post-meta', [
			'label'               => 'Get Post Meta',
			'description'         => 'Get custom field values the caller can edit for this post. Listings omit unauthorized keys; explicit requests fail. Protected keys also require the plugin allowlist.',
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Post not found.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to read meta for this post.' );
				}

				$meta = [];

				if ( isset( $input['meta_key'] ) && '' !== (string) $input['meta_key'] ) {
					$key = self::validate_post_meta_key( $input['meta_key'] );
					if ( is_wp_error( $key ) ) {
						return Webmastery_MCP_Response::from_wp_error( $key );
					}
					if ( ! self::can_access_post_meta_key( $key ) ) {
						return Webmastery_MCP_Response::legacy_error( 'protected_meta_key', 'Protected meta keys are denied unless explicitly allowlisted.' );
					}
					if ( ! self::can_edit_post_meta_key( $post_id, $key ) ) {
						return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to read this meta key.' );
					}

					$meta[ $key ] = array_map( [ self::class, 'normalize_post_meta_response_value' ], get_post_meta( $post_id, $key, false ) );
				} else {
					foreach ( get_post_meta( $post_id ) as $key => $values ) {
						$key = (string) $key;
						if ( ! self::can_access_post_meta_key( $key ) || ! self::can_edit_post_meta_key( $post_id, $key ) ) {
							continue;
						}

						$meta[ $key ] = array_map( [ self::class, 'normalize_post_meta_response_value' ], (array) $values );
					}
				}

				return [
					'success' => true,
					'data'    => Webmastery_MCP_Untrusted::mark( [
						'post_id' => $post_id,
						'meta'    => $meta,
					], [ 'meta' ] ),
				];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::post_meta_permission(),
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Post not found.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to update meta for this post.' );
				}

				$key = self::validate_post_meta_key( $input['meta_key'] ?? '' );
				if ( is_wp_error( $key ) ) {
					return Webmastery_MCP_Response::from_wp_error( $key );
				}
				if ( ! self::can_access_post_meta_key( $key ) ) {
					return Webmastery_MCP_Response::legacy_error( 'protected_meta_key', 'Protected meta keys are denied unless explicitly allowlisted.' );
				}
				if ( ! self::can_edit_post_meta_key( $post_id, $key ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to update this meta key.' );
				}

				$value = self::prepare_post_meta_update_value( $key, $input['meta_value'] ?? null );
				if ( is_wp_error( $value ) ) {
					return Webmastery_MCP_Response::from_wp_error( $value );
				}

				$previous_value = self::normalize_post_meta_response_value( get_post_meta( $post_id, $key, true ) );
				$updated        = Webmastery_MCP_Post_Writes::meta( $post_id, $key, $value );
				$current_value  = self::normalize_post_meta_response_value( get_post_meta( $post_id, $key, true ) );

				return [
					'success' => true,
					'data'    => Webmastery_MCP_Untrusted::mark( [
						'post_id'        => $post_id,
						'meta_key'       => $key,
						'updated'        => (bool) $updated,
						'previous_value' => $previous_value,
						'current_value'  => $current_value,
					], [ 'meta_key', 'previous_value', 'current_value' ] ),
				];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::post_meta_permission(),
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Post not found.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to delete meta for this post.' );
				}

				$key = self::validate_post_meta_key( $input['meta_key'] ?? '' );
				if ( is_wp_error( $key ) ) {
					return Webmastery_MCP_Response::from_wp_error( $key );
				}
				if ( ! self::can_access_post_meta_key( $key ) ) {
					return Webmastery_MCP_Response::legacy_error( 'protected_meta_key', 'Protected meta keys are denied unless explicitly allowlisted.' );
				}
				if ( ! self::can_edit_post_meta_key( $post_id, $key, 'delete_post_meta' ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to delete this meta key.' );
				}

				$before_count  = count( get_post_meta( $post_id, $key, false ) );
				$deleted       = delete_post_meta( $post_id, $key );
				$deleted_count = $deleted ? $before_count : 0;

				return [
					'success' => true,
					'data'    => Webmastery_MCP_Untrusted::mark( [
						'post_id'       => $post_id,
						'meta_key'      => $key,
						'deleted_count' => $deleted_count,
					], [ 'meta_key' ] ),
				];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::post_meta_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}
}
