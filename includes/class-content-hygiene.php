<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Content_Hygiene {

	public static function register() {
		self::register_list_orphaned_media();
		self::register_list_posts_no_featured_image();
		self::register_list_stuck_scheduled();
	}

	private static function pagination_schema( $default = 20 ) {
		return [
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => $default ],
			'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
		];
	}

	private static function per_page( $input, $default = 20 ) {
		return min( max( 1, (int) ( $input['per_page'] ?? $default ) ), 100 );
	}

	private static function page( $input ) {
		return max( 1, (int) ( $input['page'] ?? 1 ) );
	}

	private static function permission( $cap ) {
		return function () use ( $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability." );
			}

			return true;
		};
	}

	public static function posts_no_featured_image_permission( $input = [] ) {
		$post_type = sanitize_key( $input['post_type'] ?? 'post' );
		if ( 'page' === $post_type && ! current_user_can( 'edit_pages' ) ) {
			return new WP_Error( 'forbidden', 'Requires edit_pages capability.' );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'Requires edit_posts capability.' );
		}

		return true;
	}

	private static function register_list_orphaned_media() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-orphaned-media', [
			'label'               => 'List Orphaned Media',
			'description'         => 'List unattached media items that are not used as featured images or referenced in post content.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => self::pagination_schema(),
			],
			'execute_callback'    => [ self::class, 'execute_list_orphaned_media' ],
			'permission_callback' => self::permission( 'upload_files' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute_list_orphaned_media( $input = [] ) {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_parent'    => 0,
			'posts_per_page' => -1,
			'orderby'        => [
				'date' => 'DESC',
				'ID'   => 'DESC',
			],
			'order'          => 'DESC',
		];

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );
		$items = [];

		foreach ( $query->posts as $attachment ) {
			if ( ! current_user_can( 'edit_post', $attachment->ID ) ) {
				continue;
			}
			$referenced = self::is_attachment_referenced( $attachment );
			if ( is_wp_error( $referenced ) ) {
				return $referenced;
			}
			if ( $referenced ) {
				continue;
			}

			$items[] = self::normalize_orphaned_media( $attachment );
		}

		$per_page = self::per_page( $input );
		$page     = self::page( $input );
		$total    = count( $items );
		$offset   = ( $page - 1 ) * $per_page;

		return [
			'success' => true,
			'data'    => [
				'items'       => array_slice( $items, $offset, $per_page ),
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
			],
		];
	}

	private static function normalize_orphaned_media( $attachment ) {
		$attachment = get_post( $attachment );
		$file       = get_attached_file( $attachment->ID );

		return [
			'id'        => (int) $attachment->ID,
			'title'     => $attachment->post_title,
			'url'       => wp_get_attachment_url( $attachment->ID ),
			'mime_type' => $attachment->post_mime_type,
			'file_size' => $file ? (int) wp_filesize( $file ) : 0,
		];
	}

	private static function is_attachment_referenced( $attachment ) {
		global $wpdb;

		$attachment = get_post( $attachment );
		if ( ! $attachment ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Explicit audit query for attachment thumbnail references.
		$thumbnail_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_thumbnail_id',
				(string) $attachment->ID
			)
		);
		if ( '' !== $wpdb->last_error ) {
			return self::database_error( 'attachment thumbnail references' );
		}

		if ( absint( $thumbnail_count ) > 0 ) {
			return true;
		}

		$references = array_filter(
			array_unique(
				[
					wp_get_attachment_url( $attachment->ID ),
					$attachment->guid,
				]
			)
		);

		foreach ( $references as $reference ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Explicit audit query for attachment URL usage in post content.
			$content_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_content LIKE %s",
					'%' . $wpdb->esc_like( $reference ) . '%'
				)
			);
			if ( '' !== $wpdb->last_error ) {
				return self::database_error( 'attachment content references' );
			}

			if ( absint( $content_count ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	private static function database_error( $context ) {
		global $wpdb;

		return new WP_Error(
			'content_hygiene_query_failed',
			sprintf(
				'Content hygiene query failed while reading %1$s: %2$s',
				$context,
				$wpdb->last_error
			)
		);
	}

	private static function register_list_posts_no_featured_image() {
		$properties              = self::pagination_schema();
		$properties['post_type'] = [ 'type' => 'string', 'enum' => [ 'post', 'page' ], 'default' => 'post' ];

		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-posts-no-featured-image', [
			'label'               => 'List Posts Without Featured Image',
			'description'         => 'List published posts or pages that do not have a featured image assigned.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => $properties,
			],
			'execute_callback'    => [ self::class, 'execute_list_posts_no_featured_image' ],
			'permission_callback' => [ self::class, 'posts_no_featured_image_permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute_list_posts_no_featured_image( $input = [] ) {
		$post_type = sanitize_key( $input['post_type'] ?? 'post' );
		if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
			return [ 'success' => false, 'error' => 'post_type must be post or page.' ];
		}

		$permission = self::posts_no_featured_image_permission( [ 'post_type' => $post_type ] );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Explicit content hygiene audit for missing featured image meta.
		$args = [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => self::per_page( $input ),
			'paged'          => self::page( $input ),
			'orderby'        => [
				'date' => 'DESC',
				'ID'   => 'DESC',
			],
			'order'          => 'DESC',
			'meta_query'     => [
				[
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				],
			],
		];

		if ( 'page' === $post_type && ! current_user_can( 'edit_others_pages' ) ) {
			$args['author'] = get_current_user_id();
		} elseif ( 'post' === $post_type && ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return [
			'success' => true,
			'data'    => [
				'items'       => array_values( array_map( [ self::class, 'normalize_post_summary' ], $query->posts ) ),
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			],
		];
	}

	private static function normalize_post_summary( $post ) {
		$post = get_post( $post );

		return [
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'url'            => get_permalink( $post->ID ),
			'post_type'      => $post->post_type,
			'published_date' => $post->post_date,
		];
	}

	private static function register_list_stuck_scheduled() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-stuck-scheduled', [
			'label'               => 'List Stuck Scheduled Posts',
			'description'         => 'List scheduled posts whose scheduled publish time is already in the past.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => self::pagination_schema(),
			],
			'execute_callback'    => [ self::class, 'execute_list_stuck_scheduled' ],
			'permission_callback' => self::permission( 'edit_posts' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute_list_stuck_scheduled( $input = [] ) {
		$args = [
			'post_type'      => 'post',
			'post_status'    => 'future',
			'posts_per_page' => self::per_page( $input ),
			'paged'          => self::page( $input ),
			'orderby'        => 'date',
			'order'          => 'ASC',
			'date_query'     => [
				[
					'column'    => 'post_date_gmt',
					'before'    => current_time( 'mysql', true ),
					'inclusive' => false,
				],
			],
		];

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );

		return [
			'success' => true,
			'data'    => [
				'items'       => array_values( array_map( [ self::class, 'normalize_stuck_scheduled_post' ], $query->posts ) ),
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			],
		];
	}

	private static function normalize_stuck_scheduled_post( $post ) {
		$post = get_post( $post );

		return [
			'id'                 => (int) $post->ID,
			'title'              => $post->post_title,
			'url'                => get_permalink( $post->ID ),
			'scheduled_date'     => $post->post_date,
			'scheduled_date_gmt' => $post->post_date_gmt,
			'author'             => (int) $post->post_author,
			'author_name'        => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'author_login'       => get_the_author_meta( 'user_login', (int) $post->post_author ),
		];
	}
}
