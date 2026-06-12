<?php

defined( 'ABSPATH' ) || exit;

class Unlock_MCP_Comments {

	public static function register() {
		self::register_list();
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

	private static function register_list() {
		wp_register_ability( 'wp-mcp/list-comments', [
			'label'               => 'List Comments',
			'description'         => 'List WordPress comments with optional filters.',
			'category'            => 'wp-mcp',
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

	private static function register_set_status( $action, $label, $wp_status ) {
		wp_register_ability( "wp-mcp/{$action}-comment", [
			'label'               => $label,
			'description'         => "{$label} by ID.",
			'category'            => 'wp-mcp',
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
			'permission_callback' => function () {
				if ( ! current_user_can( 'moderate_comments' ) ) {
					return new WP_Error( 'forbidden', 'Requires moderate_comments capability.' );
				}
				return true;
			},
			'meta' => [
				'annotations' => [ 'readonly' => false, 'destructive' => 'trash' === $wp_status || 'spam' === $wp_status, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
