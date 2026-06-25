<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Comments {

	public static function register() {
		self::register_list();
		self::register_reply();
		self::register_update();
		self::register_set_status( 'approve', 'Approve Comment', '1' );
		self::register_set_status( 'trash', 'Trash Comment', 'trash' );
		self::register_set_status( 'spam', 'Spam Comment', 'spam' );
	}

	private static function normalize( $comment ) {
		return [
			'id'         => (int) $comment->comment_ID,
			'post_id'    => (int) $comment->comment_post_ID,
			'author'     => $comment->comment_author,
			'author_email' => $comment->comment_author_email,
			'author_url' => $comment->comment_author_url,
			'content'    => $comment->comment_content,
			'status'     => wp_get_comment_status( $comment->comment_ID ),
			'date'       => $comment->comment_date,
			'parent'     => (int) $comment->comment_parent,
		];
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

	private static function get_comment_or_error( $comment_id ) {
		$comment = get_comment( absint( $comment_id ) );

		if ( ! $comment ) {
			return new WP_Error( 'not_found', 'Comment not found.' );
		}

		return $comment;
	}

	private static function get_comment_post_or_error( $comment ) {
		$post = get_post( (int) $comment->comment_post_ID );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Comment post not found.' );
		}

		return $post;
	}

	private static function allowed_update_statuses() {
		return [
			'approve' => 'approve',
			'hold'    => 'hold',
			'spam'    => 'spam',
			'trash'   => 'trash',
		];
	}

	private static function reply_permission() {
		return function () {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error( 'forbidden', 'Requires edit_posts capability.' );
			}

			return true;
		};
	}

	private static function moderate_permission() {
		return function () {
			if ( ! current_user_can( 'moderate_comments' ) ) {
				return new WP_Error( 'forbidden', 'Requires moderate_comments capability.' );
			}

			return true;
		};
	}

	private static function register_list() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-comments', [
			'label'               => 'List Comments',
			'description'         => 'List WordPress comments with optional filters.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'  => [ 'type' => 'integer', 'description' => 'Filter by post ID' ],
					'status'   => [ 'type' => 'string', 'enum' => [ 'approve', 'hold', 'spam', 'trash', 'all' ], 'default' => 'all' ],
					'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
					'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
					'search'   => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = [
					'number' => min( (int) ( $input['per_page'] ?? 20 ), 100 ),
					'offset' => ( max( 1, (int) ( $input['page'] ?? 1 ) ) - 1 ) * min( (int) ( $input['per_page'] ?? 20 ), 100 ),
					'status' => $input['status'] ?? 'all',
				];

				if ( ! empty( $input['post_id'] ) ) {
					$args['post_id'] = absint( $input['post_id'] );
				}
				if ( ! empty( $input['search'] ) ) {
					$args['search'] = sanitize_text_field( $input['search'] );
				}

				$comments = get_comments( $args );
				$total    = (int) get_comments( array_merge( $args, [ 'count' => true, 'number' => 0, 'offset' => 0 ] ) );

				return [
					'success' => true,
					'data'    => [
						'items'       => array_map( [ self::class, 'normalize' ], $comments ),
						'total'       => $total,
						'total_pages' => $args['number'] > 0 ? (int) ceil( $total / $args['number'] ) : 1,
					],
				];
			},
			'permission_callback' => function () {
				if ( ! current_user_can( 'moderate_comments' ) ) {
					return new WP_Error( 'forbidden', 'Requires moderate_comments capability.' );
				}
				return true;
			},
			'meta' => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_reply() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/reply-comment', [
			'label'               => 'Reply Comment',
			'description'         => 'Create a threaded reply under an existing WordPress comment as the authenticated user.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'comment_id' => [ 'type' => 'integer', 'description' => 'Parent comment ID' ],
					'content'    => [ 'type' => 'string', 'description' => 'Reply comment content' ],
				],
				'required'   => [ 'comment_id', 'content' ],
			],
			'execute_callback'    => function ( $input ) {
				$parent = self::get_comment_or_error( $input['comment_id'] ?? 0 );

				if ( is_wp_error( $parent ) ) {
					return self::error_response( $parent->get_error_code(), $parent->get_error_message() );
				}

				$post = self::get_comment_post_or_error( $parent );

				if ( is_wp_error( $post ) ) {
					return self::error_response( $post->get_error_code(), $post->get_error_message() );
				}

				if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
					return self::error_response( 'forbidden', 'Requires edit_post capability for the related post.' );
				}

				$content = wp_kses_post( (string) ( $input['content'] ?? '' ) );

				if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
					return self::error_response( 'invalid_content', 'Comment content is required.' );
				}

				$user = wp_get_current_user();
				if ( ! $user || ! $user->exists() ) {
					return self::error_response( 'forbidden', 'Authenticated user is required to reply to a comment.' );
				}

				$result = wp_new_comment(
					wp_slash(
						[
							'comment_post_ID'      => (int) $post->ID,
							'comment_parent'       => (int) $parent->comment_ID,
							'comment_content'      => $content,
							'user_id'              => (int) $user->ID,
							'comment_author'       => $user->display_name,
							'comment_author_email' => $user->user_email,
							'comment_author_url'   => $user->user_url,
						]
					),
					true
				);

				if ( is_wp_error( $result ) ) {
					return self::error_response( $result->get_error_code(), $result->get_error_message() );
				}

				$comment = get_comment( (int) $result );

				if ( ! $comment ) {
					return self::error_response( 'create_failed', 'Failed to create comment reply.' );
				}

				return [
					'success' => true,
					'data'    => self::normalize( $comment ),
				];
			},
			'permission_callback' => self::reply_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_update() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/update-comment', [
			'label'               => 'Update Comment',
			'description'         => 'Update WordPress comment content and optionally apply a moderated status.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'comment_id' => [ 'type' => 'integer', 'description' => 'Comment ID' ],
					'content'    => [ 'type' => 'string', 'description' => 'Updated comment content' ],
					'status'     => [ 'type' => 'string', 'description' => 'Optional moderation status to apply: approve, hold, spam, or trash' ],
				],
				'required'   => [ 'comment_id', 'content' ],
			],
			'execute_callback'    => function ( $input ) {
				$comment = self::get_comment_or_error( $input['comment_id'] ?? 0 );

				if ( is_wp_error( $comment ) ) {
					return self::error_response( $comment->get_error_code(), $comment->get_error_message() );
				}

				$content = wp_kses_post( (string) ( $input['content'] ?? '' ) );

				if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
					return self::error_response( 'invalid_content', 'Comment content is required.' );
				}

				$status = null;
				if ( array_key_exists( 'status', $input ) && null !== $input['status'] && '' !== $input['status'] ) {
					$status   = sanitize_key( (string) $input['status'] );
					$statuses = self::allowed_update_statuses();

					if ( ! isset( $statuses[ $status ] ) ) {
						return self::error_response(
							'invalid_status',
							'Comment status must be one of approve, hold, spam, or trash.',
							[ 'allowed_statuses' => array_keys( $statuses ) ]
						);
					}
				}

				$result = wp_update_comment(
					wp_slash(
						[
							'comment_ID'      => (int) $comment->comment_ID,
							'comment_content' => $content,
						]
					),
					true
				);

				if ( is_wp_error( $result ) ) {
					return self::error_response( $result->get_error_code(), $result->get_error_message() );
				}

				if ( false === $result ) {
					return self::error_response( 'update_failed', 'Failed to update comment.' );
				}

				if ( null !== $status ) {
					$status_result = wp_set_comment_status( (int) $comment->comment_ID, self::allowed_update_statuses()[ $status ] );

					if ( ! $status_result ) {
						return self::error_response( 'status_update_failed', 'Failed to update comment status.' );
					}
				}

				$updated = get_comment( (int) $comment->comment_ID );

				if ( ! $updated ) {
					return self::error_response( 'not_found', 'Comment not found after update.' );
				}

				return [
					'success' => true,
					'data'    => self::normalize( $updated ),
				];
			},
			'permission_callback' => self::moderate_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_set_status( $action, $label, $wp_status ) {
		wp_register_ability( "webmastery-site-toolkit-for-mcp/{$action}-comment", [
			'label'               => $label,
			'description'         => "{$label} by ID.",
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'comment_id' => [ 'type' => 'integer', 'description' => 'Comment ID' ],
				],
				'required'   => [ 'comment_id' ],
			],
			'execute_callback'    => function ( $input ) use ( $wp_status, $label ) {
				$id      = absint( $input['comment_id'] );
				$comment = get_comment( $id );

				if ( ! $comment ) {
					return [ 'success' => false, 'error' => 'Comment not found.' ];
				}

				$result = wp_set_comment_status( $id, $wp_status );

				if ( ! $result ) {
					return [ 'success' => false, 'error' => "Failed to {$label}." ];
				}

				return [ 'success' => true, 'data' => [ 'id' => $id, 'status' => wp_get_comment_status( $id ) ] ];
			},
			'permission_callback' => self::moderate_permission(),
			'meta' => [
				'annotations' => [ 'readonly' => false, 'destructive' => 'trash' === $wp_status || 'spam' === $wp_status, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
