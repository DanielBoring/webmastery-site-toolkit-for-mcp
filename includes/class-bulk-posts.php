<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_Bulk_Posts {

	public static function register() {
		self::register_bulk_trash_posts();
		self::register_bulk_publish_posts();
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

	public static function bulk_input_error( $input ) {
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
			'permission_callback' => Webmastery_MCP_Post_Access::permission( 'delete_posts' ),
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
						$result = Webmastery_MCP_Post_Writes::update( [
							'ID'          => $id,
							'post_status' => 'publish',
						] );

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
			'permission_callback' => Webmastery_MCP_Post_Access::permission( 'publish_posts' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
