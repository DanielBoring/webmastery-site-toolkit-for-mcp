<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_Post_Revisions {

	public static function register() {
		self::register_list_revisions();
		self::register_restore_revision();
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
			'description'         => 'List saved revisions for a WordPress post or page. Summary omits content; use fields full for stored revision content.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'  => [ 'type' => 'integer', 'description' => 'Post or page ID whose revisions should be listed.' ],
					'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
					'fields'   => Webmastery_MCP_List_Query::fields_schema(),
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
						'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
						'order'          => 'DESC',
					]
				);

				return [
					'success' => true,
					'data'    => [
						'post_id'   => $post_id,
						'type'      => $post->post_type,
						'revisions' => array_values( array_map(
							static fn( $revision ) => Webmastery_MCP_List_Query::project( $revision, $input['fields'] ?? 'summary' ),
							array_filter( array_map( [ self::class, 'normalize_revision' ], $revisions ) )
						) ),
					],
				];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::revision_target_permission( 'post_id' ),
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
						'post'     => Webmastery_MCP_Posts::normalize( $post_id ),
					],
				];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::revision_target_permission( 'revision_id' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
