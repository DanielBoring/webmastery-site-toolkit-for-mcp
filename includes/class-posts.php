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
			'date_created'      => $post->post_date,
			'date_modified'     => $post->post_modified,
			'type'              => $post->post_type,
			'featured_image_id' => (int) get_post_thumbnail_id( $post->ID ),
		];

		if ( 'post' === $post->post_type ) {
			$data['categories'] = wp_get_post_categories( $post->ID, [ 'fields' => 'ids' ] );
			$data['tags']       = wp_get_post_tags( $post->ID, [ 'fields' => 'ids' ] );
		}

		return Webmastery_MCP_Untrusted::mark( $data, [ 'title', 'content', 'excerpt', 'slug', 'url', 'author_name' ] );
	}

	private static function can_read_full_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}

		if ( 'private' === $post->post_status ) {
			return current_user_can( 'read_post', $post->ID );
		}

		if ( 'publish' === $post->post_status ) {
			return current_user_can( 'read_post', $post->ID );
		}

		if ( 'trash' === $post->post_status ) {
			return current_user_can( 'delete_post', $post->ID );
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	private static function filter_readable_post_ids( $ids ) {
		$readable = [];

		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post && self::can_read_full_post( $post ) ) {
				$readable[] = (int) $post->ID;
			}
		}

		return $readable;
	}

	private static function query_readable_posts( $args, $page, $per_page ) {
		$count_args = array_merge(
			$args,
			[
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'paged'          => 1,
				'no_found_rows'  => true,
			]
		);

		$query        = new WP_Query( $count_args );
		$readable_ids = self::filter_readable_post_ids( $query->posts );
		$total        = count( $readable_ids );
		$page_ids     = array_slice( $readable_ids, ( max( 1, (int) $page ) - 1 ) * $per_page, $per_page );

		return [
			'items'       => array_values( array_filter( array_map( [ self::class, 'normalize' ], array_map( 'get_post', $page_ids ) ) ) ),
			'total'       => $total,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		];
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

	private static function permission( $cap ) {
		return function () use ( $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', "Requires {$cap} capability." );
			}
			return true;
		};
	}

	private static function object_permission( $type, $input_key, $cap ) {
		return function ( $input = [] ) use ( $type, $input_key, $cap ) {
			$id   = absint( $input[ $input_key ] ?? 0 );
			$post = get_post( $id );

			if ( ! $post || $post->post_type !== $type ) {
				return Webmastery_MCP_Response::local_error( 'not_found', ucfirst( $type ) . ' not found.' );
			}
			if ( ! current_user_can( $cap, $id ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', "Requires {$cap} capability for this {$type}." );
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
				return Webmastery_MCP_Response::local_error( 'forbidden', "Requires {$edit_cap} capability." );
			}
			if ( in_array( $status, [ 'publish', 'private', 'future' ], true ) && ! current_user_can( $publish_cap ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', "Requires {$publish_cap} capability." );
			}
			if ( 'page' === $type && ! empty( $input['parent'] ) && ! current_user_can( 'edit_post', absint( $input['parent'] ) ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for the parent page.' );
			}
			return true;
		};
	}

	private static function restore_permission( $type, $input_key ) {
		return function ( $input = [] ) use ( $type, $input_key ) {
			$id   = absint( $input[ $input_key ] ?? 0 );
			$post = get_post( $id );

			if ( ! $post || $post->post_type !== $type ) {
				return Webmastery_MCP_Response::local_error( 'not_found', ucfirst( $type ) . ' not found.' );
			}
			if ( ! current_user_can( 'delete_post', $id ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', "Requires delete_post capability for this {$type}." );
			}

			return true;
		};
	}

	private static function featured_image_permission() {
		return function ( $input = [] ) {
			$id   = absint( $input['post_id'] ?? 0 );
			$post = get_post( $id );

			if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
				return Webmastery_MCP_Response::local_error( 'not_found', 'Post or page not found.' );
			}

			$cap = 'post' === $post->post_type ? 'edit_posts' : 'edit_pages';
			if ( ! current_user_can( $cap ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', "Requires {$cap} capability." );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this post or page.' );
			}

			return true;
		};
	}

	private static function post_meta_permission() {
		return function ( $input = [] ) {
			$id   = absint( $input['post_id'] ?? 0 );
			$post = get_post( $id );

			if ( ! $post ) {
				return Webmastery_MCP_Response::local_error( 'not_found', 'Post not found.' );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this post.' );
			}

			return true;
		};
	}

	private static function revision_target_permission( $input_key ) {
		return function ( $input = [] ) use ( $input_key ) {
			$id   = absint( $input[ $input_key ] ?? 0 );
			$post = get_post( $id );

			if ( ! $post ) {
				return Webmastery_MCP_Response::local_error( 'not_found', 'Post or revision not found.' );
			}

			$parent_id = 'revision' === $post->post_type ? (int) $post->post_parent : (int) $post->ID;
			$parent    = get_post( $parent_id );

			if ( ! $parent || ! in_array( $parent->post_type, [ 'post', 'page' ], true ) ) {
				return Webmastery_MCP_Response::local_error( 'not_found', 'Post or page not found.' );
			}
			if ( ! current_user_can( 'edit_posts' ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_posts capability.' );
			}
			if ( ! current_user_can( 'edit_post', $parent_id ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this post or page.' );
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
					'maxItems'    => 100,
				],
				'confirm' => [ 'type' => 'boolean', 'enum' => [ true ], 'description' => 'Must be exactly true, including for a dry run.' ],
				'dry_run' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Check eligibility without writing; successes describe posts that would be changed.' ],
			],
			'required'   => [ 'ids', 'confirm' ],
		];
	}

	private static function bulk_input_error( $input ) {
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return Webmastery_MCP_Response::legacy_error( 'missing_confirmation', 'Set confirm to true to acknowledge this operation, including a dry run.' );
		}
		if ( array_key_exists( 'dry_run', $input ) && ! is_bool( $input['dry_run'] ) ) {
			return Webmastery_MCP_Response::legacy_error( 'invalid_input', 'dry_run must be a boolean.' );
		}
		if ( ! isset( $input['ids'] ) || ! is_array( $input['ids'] ) || ! $input['ids'] ) {
			return Webmastery_MCP_Response::legacy_error( 'invalid_input', 'ids must be a non-empty array of integers.' );
		}
		// Bound the raw request before normalization or duplicate removal.
		if ( count( $input['ids'] ) > 100 ) {
			return Webmastery_MCP_Response::legacy_error( 'too_many_ids', 'A bulk operation accepts at most 100 IDs.', [ 'limit' => 100 ] );
		}
		foreach ( $input['ids'] as $id ) {
			if ( ! is_int( $id ) ) {
				return Webmastery_MCP_Response::legacy_error( 'invalid_input', 'ids must contain only integers.' );
			}
		}
		return null;
	}

	private static function bulk_post_summary( $ids, $successes, $failures, $dry_run ) {
		$result = [
			'success' => true,
			'data'    => [
				'requested'     => count( $ids ),
				'success_count' => count( $successes ),
				'failure_count' => count( $failures ),
				'successes'     => $successes,
				'failures'      => $failures,
			],
		];
		if ( $dry_run ) {
			$result['data']['dry_run'] = true;
		}
		return $result;
	}

	private static function register_bulk_trash_posts() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/bulk-trash-posts', [
			'label'               => 'Bulk Trash Posts',
			'description'         => 'Move up to 100 WordPress post IDs to trash with confirm:true. Use dry_run:true to check eligibility without writes. Duplicate IDs are processed once. Refuses to delete when site trash is disabled.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => self::bulk_post_ids_schema( 'Post IDs to move to trash.' ),
			'execute_callback'    => function ( $input ) {
				$error = self::bulk_input_error( $input );
				if ( null !== $error ) {
					return $error;
				}
				$ids       = array_values( array_unique( array_map( 'absint', $input['ids'] ) ) );
				$dry_run   = $input['dry_run'] ?? false;
				$successes = [];
				$failures  = [];

				foreach ( $ids as $id ) {
					$post = get_post( $id );

					if ( ! $id || ! $post || 'post' !== $post->post_type ) {
						$failures[] = Webmastery_MCP_Response::item_error( $id, 'not_found', 'Post not found.' );
						continue;
					}

					if ( ! current_user_can( 'delete_post', $id ) ) {
						$failures[] = Webmastery_MCP_Response::item_error( $id, 'forbidden', 'You do not have permission to delete this post.' );
						continue;
					}

					if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
						$failures[] = Webmastery_MCP_Response::item_error( $id, 'trash_disabled', 'Trash is disabled on this site; the post was not deleted.' );
						continue;
					}

					if ( 'trash' === $post->post_status || ( ! $dry_run && ! wp_trash_post( $id ) ) ) {
						$failures[] = Webmastery_MCP_Response::item_error( $id, 'trash_failed', 'Failed to trash post.' );
						continue;
					}

					$successes[] = [
						'id'     => $id,
						'status' => 'trash',
					];
				}

				return self::bulk_post_summary( $ids, $successes, $failures, $dry_run );
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
			'description'         => 'Publish up to 100 draft WordPress post IDs with confirm:true. Use dry_run:true to check eligibility without writes. Duplicate IDs are processed once.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => self::bulk_post_ids_schema( 'Draft post IDs to publish.' ),
			'execute_callback'    => function ( $input ) {
				$error = self::bulk_input_error( $input );
				if ( null !== $error ) {
					return $error;
				}
				$ids       = array_values( array_unique( array_map( 'absint', $input['ids'] ) ) );
				$dry_run   = $input['dry_run'] ?? false;
				$successes = [];
				$failures  = [];

				foreach ( $ids as $id ) {
					$post = get_post( $id );

					if ( ! $id || ! $post || 'post' !== $post->post_type ) {
						$failures[] = Webmastery_MCP_Response::item_error( $id, 'not_found', 'Post not found.' );
						continue;
					}

					if ( ! current_user_can( 'edit_post', $id ) ) {
						$failures[] = Webmastery_MCP_Response::item_error( $id, 'forbidden', 'You do not have permission to edit this post.' );
						continue;
					}
					if ( ! current_user_can( 'publish_posts' ) ) {
						$failures[] = Webmastery_MCP_Response::item_error( $id, 'forbidden', 'You do not have permission to publish posts.' );
						continue;
					}

					if ( 'draft' !== $post->post_status ) {
						$failures[] = Webmastery_MCP_Response::item_error( $id, 'invalid_status', 'Only draft posts can be bulk published.' );
						continue;
					}

					if ( ! $dry_run ) {
						$result = wp_update_post(
							wp_slash(
								[
									'ID'          => $id,
									'post_status' => 'publish',
								]
							),
							true
						);

						if ( is_wp_error( $result ) || ! $result ) {
							$failures[] = Webmastery_MCP_Response::item_error( $id, 'update_failed', 'Failed to publish post.' );
							continue;
						}
					}

					$successes[] = [
						'id'     => $id,
						'status' => 'publish',
					];
				}

				return self::bulk_post_summary( $ids, $successes, $failures, $dry_run );
			},
			'permission_callback' => self::permission( 'publish_posts' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function get_featured_image_target( $input ) {
		$id   = absint( $input['post_id'] ?? 0 );
		$post = get_post( $id );

		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return Webmastery_MCP_Response::local_error( 'not_found', 'Post or page not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'You do not have permission to update this post or page.' );
		}

		return $post;
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
			return Webmastery_MCP_Response::local_error( 'invalid_content_type', 'content_type must be post or page.' );
		}
		if ( ! $post || $post->post_type !== $content_type ) {
			return Webmastery_MCP_Response::local_error( 'not_found', ucfirst( $content_type ) . ' not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'You do not have permission to edit this ' . $content_type . '.' );
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

	private static function get_patch_content_target( $post_id, $content_type = '' ) {
		$id   = absint( $post_id );
		$post = get_post( $id );

		if ( ! $post ) {
			return Webmastery_MCP_Response::local_error( 'not_found', 'Content not found.' );
		}

		$content_type = sanitize_key( $content_type );
		if ( '' !== $content_type && $post->post_type !== $content_type ) {
			return Webmastery_MCP_Response::local_error( 'content_type_mismatch', 'content_type does not match the requested content ID.' );
		}

		$post_type_object = get_post_type_object( $post->post_type );
		$is_builtin       = in_array( $post->post_type, [ 'post', 'page' ], true );
		$is_eligible_cpt  = $post_type_object
			&& empty( $post_type_object->_builtin )
			&& ! empty( $post_type_object->public )
			&& ! empty( $post_type_object->show_ui );

		if ( ( ! $is_builtin && ! $is_eligible_cpt ) || ! post_type_supports( $post->post_type, 'editor' ) ) {
			return Webmastery_MCP_Response::local_error( 'unsupported_type', 'patch-post-content supports posts, pages, and public editor-enabled custom post types.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'You do not have permission to patch this content.' );
		}

		return $post;
	}

	private static function patch_post_content_permission() {
		return function ( $input = [] ) {
			$id   = absint( $input['post_id'] ?? 0 );
			$post = get_post( $id );

			if ( ! $post ) {
				return Webmastery_MCP_Response::local_error( 'not_found', 'Content not found.' );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', 'You do not have permission to patch this content.' );
			}

			return true;
		};
	}

	private static function normalize_block( $block, $path ) {
		return Webmastery_MCP_Untrusted::mark( [
			'path'              => $path,
			'block_name'        => $block['blockName'] ?? null,
			'text'              => self::block_text( $block ),
			'html'              => $block['innerHTML'] ?? '',
			'attrs'             => $block['attrs'] ?? [],
			'inner_block_count' => count( $block['innerBlocks'] ?? [] ),
			'hash'              => self::block_hash( $block ),
		], [ 'block_name', 'text', 'html', 'attrs' ] );
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
			return Webmastery_MCP_Response::local_error( 'invalid_block_path', 'block_path must be a dotted numeric path like 0 or 2.1.' );
		}

		return array_map( 'absint', explode( '.', $path ) );
	}

	private static function get_block_by_segments( $blocks, $segments ) {
		$current_blocks = $blocks;
		$current_block  = null;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $current_blocks ) || ! array_key_exists( $segment, $current_blocks ) ) {
				return Webmastery_MCP_Response::local_error( 'target_not_found', 'Block path not found.' );
			}

			$current_block  = $current_blocks[ $segment ];
			$current_blocks = $current_block['innerBlocks'] ?? [];
		}

		return $current_block;
	}

	private static function replace_block_by_segments( &$blocks, $segments, $replacement_block ) {
		$segment = array_shift( $segments );

		if ( ! array_key_exists( $segment, $blocks ) ) {
			return Webmastery_MCP_Response::local_error( 'target_not_found', 'Block path not found.' );
		}

		if ( empty( $segments ) ) {
			$blocks[ $segment ] = $replacement_block;
			return true;
		}

		if ( empty( $blocks[ $segment ]['innerBlocks'] ) || ! is_array( $blocks[ $segment ]['innerBlocks'] ) ) {
			return Webmastery_MCP_Response::local_error( 'target_not_found', 'Block path not found.' );
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
					return Webmastery_MCP_Response::from_wp_error( $post );
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
					return Webmastery_MCP_Response::from_wp_error( $post );
				}

				$current_content = $post->post_content;
				$before_hash     = self::content_hash( $current_content );
				$expected_hash   = sanitize_text_field( $input['expected_content_hash'] ?? '' );

				if ( '' !== $expected_hash && ! hash_equals( $before_hash, $expected_hash ) ) {
					return Webmastery_MCP_Response::legacy_error( 'content_hash_mismatch', 'Content hash did not match; reload before patching.' );
				}

				$blocks      = self::parse_editable_blocks( $current_content );
				$target_type = sanitize_key( $input['target_type'] );

				if ( 'block_path' === $target_type ) {
					$segments = self::parse_block_path( $input['block_path'] ?? '' );
				} elseif ( 'block_hash' === $target_type ) {
					$hash = sanitize_text_field( $input['block_hash'] ?? '' );
					if ( '' === $hash ) {
						return Webmastery_MCP_Response::legacy_error( 'missing_target', 'block_hash is required when target_type is block_hash.' );
					}

					$matches = self::find_block_paths_by_hash( $blocks, $hash );
					if ( 0 === count( $matches ) ) {
						return Webmastery_MCP_Response::legacy_error( 'target_not_found', 'Block hash target not found.' );
					}
					if ( count( $matches ) > 1 ) {
						return Webmastery_MCP_Response::legacy_error( 'ambiguous_target', 'Block hash target matched more than once.' );
					}

					$segments = self::parse_block_path( $matches[0] );
				} else {
					return Webmastery_MCP_Response::legacy_error( 'invalid_target_type', 'target_type must be block_path or block_hash.' );
				}

				if ( is_wp_error( $segments ) ) {
					return Webmastery_MCP_Response::from_wp_error( $segments );
				}

				$target_block = self::get_block_by_segments( $blocks, $segments );
				if ( is_wp_error( $target_block ) ) {
					return Webmastery_MCP_Response::from_wp_error( $target_block );
				}

				$before_block_hash = self::block_hash( $target_block );
				$expected_block    = sanitize_text_field( $input['expected_block_hash'] ?? '' );
				if ( '' !== $expected_block && ! hash_equals( $before_block_hash, $expected_block ) ) {
					return Webmastery_MCP_Response::legacy_error( 'block_hash_mismatch', 'Block hash did not match; reload before patching.' );
				}

				$replacement_blocks = self::parse_editable_blocks( wp_kses_post( $input['replacement_content'] ) );
				if ( 1 !== count( $replacement_blocks ) ) {
					return Webmastery_MCP_Response::legacy_error( 'invalid_replacement', 'replacement_content must parse to exactly one block.' );
				}

				$replacement_result = self::replace_block_by_segments( $blocks, $segments, $replacement_blocks[0] );
				if ( is_wp_error( $replacement_result ) ) {
					return Webmastery_MCP_Response::from_wp_error( $replacement_result );
				}

				$result = wp_update_post(
					wp_slash(
						[
							'ID'           => $post->ID,
							'post_content' => serialize_blocks( $blocks ),
						]
					),
					true
				);

				if ( is_wp_error( $result ) ) {
					return Webmastery_MCP_Response::legacy_error( 'update_failed', 'Failed to update content.' );
				}

				$updated_post = get_post( $post->ID );
				$path         = implode( '.', $segments );

				return [
					'success' => true,
					'data'    => Webmastery_MCP_Untrusted::mark( [
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
					], [ 'content' ] ),
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
			return Webmastery_MCP_Response::local_error( 'target_not_found', 'Heading target not found.' );
		}
		if ( count( $matches ) > 1 ) {
			return Webmastery_MCP_Response::local_error( 'ambiguous_target', 'Heading target matched more than once.' );
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
			return Webmastery_MCP_Response::local_error( 'target_not_found', 'Exact content target not found.' );
		}
		if ( $count > 1 ) {
			return Webmastery_MCP_Response::local_error( 'ambiguous_target', 'Exact content target matched more than once.' );
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
			'description'         => 'Safely update one targeted part of a post, page, or eligible custom post type body without replacing the full content.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'               => [
						'type'        => 'integer',
						'description' => 'Post, page, or custom post type ID to patch.',
					],
					'content_type'          => [
						'type'        => 'string',
						'description' => 'Optional expected content type slug, such as post, page, or an eligible custom post type.',
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
						'description' => 'Optional sha256 hash of the current post_content; fails if the content changed before patching.',
					],
				],
				'required'   => [ 'post_id', 'target_type', 'replacement_content' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = self::get_patch_content_target( $input['post_id'], $input['content_type'] ?? '' );

				if ( is_wp_error( $post ) ) {
					return Webmastery_MCP_Response::from_wp_error( $post );
				}

				$id              = $post->ID;
				$current_content = $post->post_content;
				$before_hash     = self::content_hash( $current_content );

				$expected_hash = sanitize_text_field( $input['expected_content_hash'] ?? '' );

				if ( '' !== $expected_hash && ! hash_equals( $before_hash, $expected_hash ) ) {
					return Webmastery_MCP_Response::legacy_error( 'content_hash_mismatch', 'Post content hash did not match; reload the post before patching.' );
				}

				$target_type         = sanitize_key( $input['target_type'] );
				$replacement_content = wp_kses_post( $input['replacement_content'] );

				if ( 'heading' === $target_type ) {
					if ( empty( $input['heading_text'] ) ) {
						return Webmastery_MCP_Response::legacy_error( 'missing_target', 'heading_text is required when target_type is heading.' );
					}

					$patch = self::patch_content_by_heading(
						$current_content,
						sanitize_text_field( $input['heading_text'] ),
						$replacement_content
					);
				} elseif ( 'exact' === $target_type ) {
					if ( ! isset( $input['old_content'] ) || '' === $input['old_content'] ) {
						return Webmastery_MCP_Response::legacy_error( 'missing_target', 'old_content is required when target_type is exact.' );
					}

					$patch = self::patch_content_by_exact_match(
						$current_content,
						$input['old_content'],
						$replacement_content
					);
				} else {
					return Webmastery_MCP_Response::legacy_error( 'invalid_target_type', 'target_type must be heading or exact.' );
				}

				if ( is_wp_error( $patch ) ) {
					return Webmastery_MCP_Response::from_wp_error( $patch );
				}

				$args = [
					'ID'           => $id,
					'post_content' => $patch['content'],
				];

				$result = wp_update_post( wp_slash( $args ), true );

				if ( is_wp_error( $result ) ) {
					return Webmastery_MCP_Response::legacy_error( 'update_failed', 'Failed to update content.' );
				}

				$updated_post = get_post( $id );
				$after_hash   = self::content_hash( $updated_post->post_content );

				return [
					'success' => true,
					'data'    => [
						'id'                  => $id,
						'type'                => $post->post_type,
						'target'              => Webmastery_MCP_Untrusted::mark( $patch['target'], [ 'heading_text' ] ),
						'replaced_blocks'     => $patch['replaced_blocks'],
						'content_hash_before' => $before_hash,
						'content_hash_after'  => $after_hash,
						'post'                => self::normalize( $id ),
					],
				];
			},
			'permission_callback' => self::patch_post_content_permission(),
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
					return Webmastery_MCP_Response::from_wp_error( $post );
				}

				$attachment_id = absint( $input['attachment_id'] );
				$attachment    = get_post( $attachment_id );

				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Attachment not found.' );
				}
				if ( ! wp_attachment_is_image( $attachment_id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'unsupported_mime_type', 'Attachment must be an image.' );
				}

				$result = set_post_thumbnail( $post->ID, $attachment_id );

				if ( ! $result ) {
					return Webmastery_MCP_Response::legacy_error( 'featured_image_failed', 'Failed to set featured image.' );
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
					return Webmastery_MCP_Response::from_wp_error( $post );
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

		return Webmastery_MCP_Untrusted::mark( [
			'id'            => (int) $revision->ID,
			'post_id'       => (int) $revision->post_parent,
			'author'        => (int) $revision->post_author,
			'author_name'   => get_the_author_meta( 'display_name', (int) $revision->post_author ),
			'title'         => $revision->post_title,
			'content'       => $revision->post_content,
			'excerpt'       => $revision->post_excerpt,
			'date_created'  => $revision->post_date,
			'date_modified' => $revision->post_modified,
		], [ 'author_name', 'title', 'content', 'excerpt' ] );
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Post or page not found.' );
				}
				if ( ! current_user_can( 'edit_posts' ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'Requires edit_posts capability.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to list revisions for this post or page.' );
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Revision not found.' );
				}

				$post_id = (int) $revision->post_parent;
				$post    = get_post( $post_id );

				if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Post or page not found.' );
				}
				if ( ! current_user_can( 'edit_posts' ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'Requires edit_posts capability.' );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to restore revisions for this post or page.' );
				}

				$result = wp_restore_post_revision( $revision_id );

				if ( ! $result || is_wp_error( $result ) ) {
					return Webmastery_MCP_Response::legacy_error( 'restore_revision_failed', 'Failed to restore revision.' );
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
				$updated        = update_post_meta( $post_id, $key, wp_slash( $value ) );
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

				$per_page = min( max( 1, (int) ( $input['per_page'] ?? 20 ) ), 100 );
				$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
				$data     = self::query_readable_posts( $args, $page, $per_page );

				return [
					'success' => true,
					'data'    => $data,
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', ucfirst( $type ) . ' not found.' );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to view this ' . $type . '.' );
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
			'status'         => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'pending', 'private', 'future' ], 'default' => 'draft' ],
			'scheduled_date' => [ 'type' => 'string', 'description' => 'Date to set post_date. New future schedules require a valid date at least 60 seconds ahead at validation. Prefer ISO 8601 with Z or an explicit offset; legacy relative and offset-less parsing is retained (normally UTC).' ],
			'excerpt'        => [ 'type' => 'string' ],
			'slug'           => [ 'type' => 'string' ],
		];

		if ( 'post' === $type ) {
			$create_props['category_ids'] = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
			$create_props['tag_ids']      = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
		}
		if ( 'page' === $type ) {
			$create_props['parent'] = [ 'type' => 'integer', 'description' => 'Parent page ID (0 for top-level)' ];
		}

		wp_register_ability( "webmastery-site-toolkit-for-mcp/create-{$type}", [
			'label'               => "Create {$label}",
			'description'         => "Create a new WordPress {$type} without metadata or SEO aliases. Create a draft, write metadata with update-post-meta, then publish; the steps are not atomic.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $create_props,
				'required'   => [ 'title', 'content' ],
			],
			'execute_callback'    => function ( $input ) use ( $type ) {
				$metadata_error = self::reject_combined_metadata( $input );
				if ( null !== $metadata_error ) {
					return $metadata_error;
				}
				$permission = self::create_permission( $type );
				$allowed    = $permission( $input );
				if ( is_wp_error( $allowed ) ) {
					return Webmastery_MCP_Response::from_wp_error( $allowed );
				}

				$args = [
					'post_type'    => $type,
					'post_title'   => sanitize_text_field( $input['title'] ),
					'post_content' => wp_kses_post( $input['content'] ),
					'post_status'  => in_array( $input['status'] ?? 'draft', [ 'draft', 'publish', 'pending', 'private', 'future' ], true )
										? ( $input['status'] ?? 'draft' )
										: 'draft',
				];

				if ( ! empty( $input['excerpt'] ) ) {
					$args['post_excerpt'] = sanitize_text_field( $input['excerpt'] );
				}
				if ( ! empty( $input['slug'] ) ) {
					$args['post_name'] = sanitize_title( $input['slug'] );
				}
				if ( 'page' === $type && isset( $input['parent'] ) ) {
					$args['post_parent'] = absint( $input['parent'] );
				}

				$schedule = Webmastery_MCP_Post_Scheduling::prepare( $input );
				if ( is_wp_error( $schedule ) ) {
					return Webmastery_MCP_Response::from_wp_error( $schedule );
				}
				$args = array_merge( $args, $schedule );

				if ( isset( $args['post_parent'] ) ) {
					$parent_valid = Webmastery_MCP_Post_Parent::validate( $type, $args['post_parent'] );
					if ( is_wp_error( $parent_valid ) ) {
						return Webmastery_MCP_Response::from_wp_error( $parent_valid );
					}
				}

				$id = wp_insert_post( wp_slash( $args ), true );

				if ( is_wp_error( $id ) ) {
					return Webmastery_MCP_Response::from_wp_error( $id );
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
			'scheduled_date' => [ 'type' => 'string', 'description' => 'Date to set post_date. New future schedules require a valid date at least 60 seconds ahead at validation; omit to retain a valid existing future schedule. Prefer ISO 8601 with an explicit offset; legacy parsing is retained.' ],
			'excerpt'        => [ 'type' => 'string' ],
			'slug'           => [ 'type' => 'string' ],
		];

		if ( 'post' === $type ) {
			$update_props['category_ids'] = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
			$update_props['tag_ids']      = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ];
		}
		if ( 'page' === $type ) {
			$update_props['parent'] = [ 'type' => 'integer', 'description' => 'Parent page ID (0 for top-level)' ];
		}

		wp_register_ability( "webmastery-site-toolkit-for-mcp/update-{$type}", [
			'label'               => "Update {$label}",
			'description'         => "Update an existing WordPress {$type} without metadata or SEO aliases. Use update-post-meta separately for each metadata key.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $update_props,
				'required'   => [ "{$type}_id" ],
			],
			'execute_callback'    => function ( $input ) use ( $type, $slug ) {
				$metadata_error = self::reject_combined_metadata( $input );
				if ( null !== $metadata_error ) {
					return $metadata_error;
				}
				$id   = absint( $input[ "{$type}_id" ] );
				$post = get_post( $id );

				if ( ! $post || $post->post_type !== $type ) {
					return Webmastery_MCP_Response::legacy_error( 'not_found', ucfirst( $type ) . ' not found.' );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to update this ' . $type . '.' );
				}
				if ( isset( $input['status'] ) && in_array( $input['status'], [ 'publish', 'private', 'future' ], true ) && ! current_user_can( 'publish_' . $slug ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to publish this ' . $type . '.' );
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
				if ( 'page' === $type && isset( $input['parent'] ) ) {
					$args['post_parent'] = absint( $input['parent'] );
				}

				$schedule = Webmastery_MCP_Post_Scheduling::prepare( $input, $post );
				if ( is_wp_error( $schedule ) ) {
					return Webmastery_MCP_Response::from_wp_error( $schedule );
				}
				$args = array_merge( $args, $schedule );

				if ( isset( $args['post_parent'] ) ) {
					$parent_valid = Webmastery_MCP_Post_Parent::validate( $type, $args['post_parent'], $id );
					if ( is_wp_error( $parent_valid ) ) {
						return Webmastery_MCP_Response::from_wp_error( $parent_valid );
					}
				}

				$result = wp_update_post( wp_slash( $args ), true );

				if ( is_wp_error( $result ) ) {
					return Webmastery_MCP_Response::from_wp_error( $result );
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
			'description'         => "Move a WordPress {$type} to trash. Refuses to delete when site trash is disabled.",
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', ucfirst( $type ) . ' not found.' );
				}
				if ( ! current_user_can( 'delete_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to delete this ' . $type . '.' );
				}

				if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
					return Webmastery_MCP_Response::legacy_error( 'trash_disabled', 'Trash is disabled on this site; the ' . $type . ' was not deleted.' );
				}

				$result = wp_trash_post( $id );

				if ( ! $result ) {
					return Webmastery_MCP_Response::legacy_error( 'trash_failed', 'Failed to trash ' . $type . '.' );
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', ucfirst( $type ) . ' not found.' );
				}
				if ( ! current_user_can( 'delete_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', "You do not have permission to restore this {$type}." );
				}

				$result = wp_untrash_post( $id );

				if ( ! $result ) {
					return Webmastery_MCP_Response::legacy_error( 'restore_failed', 'Failed to restore ' . $type . ' from trash.' );
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
