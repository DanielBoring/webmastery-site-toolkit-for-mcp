<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_Post_Access {
	/**
	 * Whether the current user may read a post, using the capability that fits its status.
	 *
	 * @param WP_Post|int|null $post       Post object or ID.
	 * @param string           $read_cap   Capability checked for published and private posts.
	 * @param string           $edit_cap   Capability checked for other statuses.
	 * @param string           $delete_cap Capability checked for trashed posts.
	 * @return bool
	 */
	public static function can_read( $post, string $read_cap = 'read_post', string $edit_cap = 'edit_post', string $delete_cap = 'delete_post' ): bool {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		if ( in_array( $post->post_status, [ 'private', 'publish' ], true ) ) {
			return current_user_can( $read_cap, $post->ID );
		}
		return current_user_can( 'trash' === $post->post_status ? $delete_cap : $edit_cap, $post->ID );
	}

	/**
	 * Keep only the IDs the callback allows.
	 *
	 * @param array<int, int|string> $ids      Post IDs.
	 * @param callable               $can_read Receives an integer ID and returns a boolean.
	 * @return list<int>
	 */
	public static function filter_ids( array $ids, callable $can_read ): array {
		$readable = [];
		foreach ( $ids as $id ) {
			if ( $can_read( (int) $id ) ) {
				$readable[] = (int) $id;
			}
		}
		return $readable;
	}

	/**
	 * Run a bounded list query and return the readable, normalized items.
	 *
	 * @param array<string, mixed> $args     WP_Query arguments.
	 * @param int                  $page     Page number.
	 * @param int                  $per_page Page size.
	 * @param callable             $can_read Receives an integer ID and returns a boolean.
	 * @param callable             $normalize Receives the post and returns a record, or null to skip it.
	 * @return array{items: list<array<string, mixed>>, page: int, per_page: int, next_page: int|null}|WP_Error
	 */
	public static function query_readable( array $args, int $page, int $per_page, callable $can_read, callable $normalize ): array|WP_Error {
		$window = Webmastery_MCP_List_Query::window( $args, $page, $per_page );
		if ( is_wp_error( $window ) ) {
			return $window;
		}
		$items = [];
		foreach ( self::filter_ids( $window['ids'], $can_read ) as $id ) {
			$item = $normalize( get_post( $id ) );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		return Webmastery_MCP_List_Query::result( $window, $items );
	}

	/**
	 * Slice a list of post IDs into a normalized page.
	 *
	 * @param array<int, int> $ids       Post IDs.
	 * @param int             $page      Page number.
	 * @param int             $per_page  Page size.
	 * @param callable        $normalize Receives the post and returns a record.
	 * @return array{items: list<array<string, mixed>>, total: int, total_pages: int}
	 */
	public static function paginate( array $ids, int $page, int $per_page, callable $normalize ): array {
		$total    = count( $ids );
		$page_ids = array_slice( $ids, ( max( 1, $page ) - 1 ) * $per_page, $per_page );
		return [
			'items'       => array_values( array_filter( array_map( $normalize, array_map( static fn( $id ) => get_post( $id ), $page_ids ) ) ) ),
			'total'       => $total,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		];
	}

	/**
	 * Limit a query to the current user's posts unless they may edit others' posts.
	 *
	 * @param array<string, mixed> $args            WP_Query arguments.
	 * @param string               $edit_others_cap Capability that lifts the restriction.
	 * @return array<string, mixed>
	 */
	public static function restrict_author( array $args, string $edit_others_cap ): array {
		if ( ! current_user_can( $edit_others_cap ) ) {
			$args['author'] = get_current_user_id();
		}
		return $args;
	}

	/**
	 * Build WP_Query arguments for a post listing from validated input.
	 *
	 * @param array<string, mixed> $input           Ability input.
	 * @param string               $type            Post type.
	 * @param string               $edit_others_cap Capability that lifts the author restriction.
	 * @return array<string, mixed>
	 */
	public static function list_args( array $input, string $type, string $edit_others_cap ): array {
		$args = [
			'post_type'      => $type,
			'post_status'    => $input['status'] ?? 'any',
			'posts_per_page' => Webmastery_MCP_Input::per_page( $input, 20, 100, null ),
			'paged'          => Webmastery_MCP_Input::page( $input ),
			'orderby'        => $input['orderby'] ?? 'date',
			'order'          => strtoupper( $input['order'] ?? 'DESC' ),
		];
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}
		if ( ! empty( $input['author'] ) ) {
			$args['author'] = absint( $input['author'] );
		}
		return self::restrict_author( $args, $edit_others_cap );
	}

	/**
	 * Permission callback requiring a capability.
	 *
	 * @param string $cap WordPress capability.
	 * @return Closure
	 */
	public static function permission( $cap ) {
		return Webmastery_MCP_Permissions::cap( $cap );
	}

	/**
	 * Permission callback requiring a capability on the post named by the input.
	 *
	 * @param string $type      Content type, "post" or "page".
	 * @param string $input_key Input key holding the post ID.
	 * @param string $cap       Meta capability checked against the post.
	 * @return Closure
	 */
	public static function object_permission( $type, $input_key, $cap ) {
		return Webmastery_MCP_Permissions::object( $type, $input_key, $cap, ucfirst( $type ) . ' not found.', "Requires {$cap} capability for this {$type}." );
	}

	/**
	 * Permission callback for creating a post or page with the requested status.
	 *
	 * @param string $type Content type, "post" or "page".
	 * @return Closure(array<string, mixed>=): (true|WP_Error)
	 */
	public static function create_permission( $type ) {
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

	/**
	 * Permission callback for restoring a trashed post or page.
	 *
	 * @param string $type      Content type, "post" or "page".
	 * @param string $input_key Input key holding the post ID.
	 * @return Closure
	 */
	public static function restore_permission( $type, $input_key ) {
		return self::object_permission( $type, $input_key, 'delete_post' );
	}

	/**
	 * Permission callback for featured image writes.
	 *
	 * @return Closure(array<string, mixed>=): (true|WP_Error)
	 */
	public static function featured_image_permission() {
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

	/**
	 * Permission callback for post meta writes.
	 *
	 * @return Closure(array<string, mixed>=): (true|WP_Error)
	 */
	public static function post_meta_permission() {
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

	/**
	 * Permission callback for a post or revision named by the input.
	 *
	 * @param string $input_key Input key holding the post or revision ID.
	 * @return Closure(array<string, mixed>=): (true|WP_Error)
	 */
	public static function revision_target_permission( $input_key ) {
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

	/**
	 * Resolve the post or page whose featured image will change.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	public static function get_featured_image_target( $input ) {
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

	/**
	 * Resolve an editable post or page of the stated content type.
	 *
	 * @param mixed $content_id   Requested content ID.
	 * @param mixed $content_type Requested content type.
	 * @return WP_Post|WP_Error
	 */
	public static function get_content_target( $content_id, $content_type ) {
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

	/**
	 * Permission callback for content writes.
	 *
	 * @return Closure(array<string, mixed>=): (true|WP_Error)
	 */
	public static function content_permission() {
		return function ( $input = [] ) {
			$post = self::get_content_target( $input['content_id'] ?? 0, $input['content_type'] ?? '' );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			return true;
		};
	}

	/**
	 * Resolve an editable post, page or public editor-enabled custom post type entry.
	 *
	 * @param mixed $post_id      Requested content ID.
	 * @param mixed $content_type Requested content type, or an empty string for any.
	 * @return WP_Post|WP_Error
	 */
	public static function get_patch_content_target( $post_id, $content_type = '' ) {
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

	/**
	 * Permission callback for patching post content.
	 *
	 * @return Closure(array<string, mixed>=): (true|WP_Error)
	 */
	public static function patch_post_content_permission() {
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
}
