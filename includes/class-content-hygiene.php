<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Content_Hygiene {

	public static function register() {
		self::register_list_orphaned_media();
		self::register_list_posts_no_featured_image();
		self::register_list_stuck_scheduled();
	}

	private static function pagination_schema( $default_per_page = 20 ) {
		return [
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => $default_per_page ],
			'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
		];
	}

	private static function per_page( $input, $default_per_page = 20 ) {
		return min( max( 1, (int) ( $input['per_page'] ?? $default_per_page ) ), 100 );
	}

	private static function page( $input ) {
		return max( 1, (int) ( $input['page'] ?? 1 ) );
	}

	private static function permission( $cap ) {
		return function () use ( $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', "Requires {$cap} capability." );
			}

			return true;
		};
	}

	public static function posts_no_featured_image_permission( $input = [] ) {
		$post_type = sanitize_key( $input['post_type'] ?? 'post' );
		if ( 'page' === $post_type && ! current_user_can( 'edit_pages' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_pages capability.' );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_posts capability.' );
		}

		return true;
	}

	private static function register_list_orphaned_media() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-orphaned-media', [
			'label'               => 'List Orphaned Media',
			'description'         => 'List unattached media without known featured-image or literal URL/GUID references in a bounded candidate window. Follow next_page even for empty items; no exact totals.',
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
			'orderby'        => [
				'date' => 'DESC',
				'ID'   => 'DESC',
			],
			'order'          => 'DESC',
		];

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$window      = Webmastery_MCP_List_Query::window( $args, self::page( $input ), self::per_page( $input ) );
		$attachments = [];
		foreach ( $window['ids'] as $id ) {
			$attachment = get_post( $id );
			if ( $attachment && current_user_can( 'edit_post', $id ) ) {
				$attachments[] = $attachment;
			}
		}
		$referenced = self::attachments_referenced( $attachments );
		if ( is_wp_error( $referenced ) ) {
			return $referenced;
		}
		$items = [];
		foreach ( $attachments as $attachment ) {
			if ( ! $referenced[ $attachment->ID ] ) {
				$items[] = self::normalize_orphaned_media( $attachment );
			}
		}

		return [
			'success' => true,
			'data'    => Webmastery_MCP_List_Query::result( $window, $items ),
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

	public static function is_attachment_referenced( $attachment ) {
		$attachment = get_post( $attachment );
		if ( ! $attachment ) {
			return false;
		}
		$result = self::attachments_referenced( [ $attachment ] );
		return is_wp_error( $result ) ? $result : $result[ $attachment->ID ];
	}

	public static function attachments_referenced( array $attachments ) {
		global $wpdb;

		if ( [] === $attachments ) {
			return [];
		}
		if ( count( $attachments ) > 100 ) {
			return Webmastery_MCP_Response::local_error( 'invalid_input', 'At most 100 attachment candidates may be checked.' );
		}
		$by_id = [];
		foreach ( $attachments as $attachment ) {
			$attachment = get_post( $attachment );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return Webmastery_MCP_Response::local_error( 'invalid_input', 'Reference checks require existing attachments.' );
			}
			$by_id[ (int) $attachment->ID ] = $attachment;
		}
		$hits         = array_fill_keys( array_keys( $by_id ), false );
		$placeholders = implode( ', ', array_fill( 0, count( $by_id ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Bounded candidate IDs and literal patterns are prepared; no sitewide metadata materialization.
		$thumbnail_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ($placeholders)",
				array_merge( [ '_thumbnail_id' ], array_map( 'strval', array_keys( $by_id ) ) )
			)
		);
		if ( null === $thumbnail_ids || '' !== $wpdb->last_error ) {
			return self::database_error( 'attachment thumbnail references' );
		}
		foreach ( $thumbnail_ids as $id ) {
			if ( array_key_exists( (int) $id, $hits ) ) {
				$hits[ (int) $id ] = true;
			}
		}

		$patterns = [];
		foreach ( $by_id as $id => $attachment ) {
			if ( $hits[ $id ] ) {
				continue;
			}
			$references = array_filter( array_unique( [ wp_get_attachment_url( $id ), $attachment->guid ] ) );
			foreach ( $references as $reference ) {
				$patterns[] = [ 'id' => $id, 'like' => '%' . $wpdb->esc_like( $reference ) . '%' ];
			}
		}
		foreach ( array_chunk( $patterns, 50 ) as $chunk ) {
			$columns = [];
			foreach ( array_keys( $chunk ) as $index ) {
				$columns[] = "COALESCE(MAX(post_content LIKE %s), 0) AS ref_{$index}";
			}
			$sql = 'SELECT ' . implode( ', ', $columns ) . " FROM {$wpdb->posts}";
			$row = $wpdb->get_row( $wpdb->prepare( $sql, array_column( $chunk, 'like' ) ), 'ARRAY_A' );
			if ( null === $row || '' !== $wpdb->last_error ) {
				return self::database_error( 'attachment content references' );
			}
			foreach ( $chunk as $index => $pattern ) {
				if ( ! isset( $row[ "ref_{$index}" ] ) ) {
					return self::database_error( 'attachment content references' );
				}
				if ( (int) $row[ "ref_{$index}" ] > 0 ) {
					$hits[ $pattern['id'] ] = true;
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $hits;
	}

	private static function database_error( $context ) {
		return Webmastery_MCP_Response::local_error(
			'content_hygiene_query_failed',
			sprintf(
				'Content hygiene query failed while reading %s.',
				$context
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
			return Webmastery_MCP_Response::legacy_error( 'invalid_content_type', 'post_type must be post or page.' );
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
		];
	}
}
