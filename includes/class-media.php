<?php

defined( 'ABSPATH' ) || exit;

class Unlock_MCP_Media {

	public static function register() {
		self::register_list();
		self::register_get();
		self::register_update();
		self::register_delete();
	}

	private static function normalize( $attachment ) {
		$attachment = get_post( $attachment );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		$metadata = wp_get_attachment_metadata( $attachment->ID );
		$file     = get_attached_file( $attachment->ID );
		$data     = [
			'id'            => (int) $attachment->ID,
			'title'         => $attachment->post_title,
			'caption'       => $attachment->post_excerpt,
			'alt_text'      => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'mime_type'     => $attachment->post_mime_type,
			'url'           => wp_get_attachment_url( $attachment->ID ),
			'filename'      => $file ? basename( $file ) : '',
			'author'        => (int) $attachment->post_author,
			'parent_id'     => (int) $attachment->post_parent,
			'date_created'  => $attachment->post_date,
			'date_modified' => $attachment->post_modified,
		];

		if ( is_array( $metadata ) ) {
			if ( isset( $metadata['width'] ) ) {
				$data['width'] = (int) $metadata['width'];
			}
			if ( isset( $metadata['height'] ) ) {
				$data['height'] = (int) $metadata['height'];
			}
		}

		return $data;
	}

	private static function permission( $cap ) {
		return function () use ( $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability." );
			}
			return true;
		};
	}

	private static function attachment_permission( $input_key, $cap ) {
		return function ( $input = [] ) use ( $input_key, $cap ) {
			$id         = absint( $input[ $input_key ] ?? 0 );
			$attachment = get_post( $id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new WP_Error( 'not_found', 'Media item not found.' );
			}
			if ( ! current_user_can( $cap, $id ) ) {
				return new WP_Error( 'forbidden', "Requires {$cap} capability for this media item." );
			}
			return true;
		};
	}

	private static function register_list() {
		wp_register_ability( 'wp-mcp/list-media', [
			'label'               => 'List Media',
			'description'         => 'List WordPress media items with optional filters.',
			'category'            => 'wp-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'mime_type' => [ 'type' => 'string', 'description' => 'Filter by MIME type, such as image/jpeg or application/pdf' ],
					'search'    => [ 'type' => 'string' ],
					'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
					'page'      => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$args = [
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => min( (int) ( $input['per_page'] ?? 20 ), 100 ),
					'paged'          => max( 1, (int) ( $input['page'] ?? 1 ) ),
					'orderby'        => 'date',
					'order'          => 'DESC',
				];

				if ( ! empty( $input['mime_type'] ) ) {
					$args['post_mime_type'] = sanitize_text_field( $input['mime_type'] );
				}
				if ( ! empty( $input['search'] ) ) {
					$args['s'] = sanitize_text_field( $input['search'] );
				}
				if ( ! current_user_can( 'edit_others_posts' ) ) {
					$args['author'] = get_current_user_id();
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
			'permission_callback' => self::permission( 'upload_files' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_get() {
		wp_register_ability( 'wp-mcp/get-media', [
			'label'               => 'Get Media',
			'description'         => 'Get a single WordPress media item by ID.',
			'category'            => 'wp-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'media_id' => [ 'type' => 'integer', 'description' => 'Media attachment ID' ],
				],
				'required'   => [ 'media_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$id         = absint( $input['media_id'] );
				$attachment = get_post( $id );

				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return [ 'success' => false, 'error' => 'Media item not found.' ];
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to view this media item.' ];
				}

				return [ 'success' => true, 'data' => self::normalize( $attachment ) ];
			},
			'permission_callback' => self::attachment_permission( 'media_id', 'edit_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_update() {
		wp_register_ability( 'wp-mcp/update-media', [
			'label'               => 'Update Media',
			'description'         => 'Update media alt text, title, and caption.',
			'category'            => 'wp-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'media_id' => [ 'type' => 'integer', 'description' => 'Media attachment ID to update' ],
					'alt_text' => [ 'type' => 'string' ],
					'title'    => [ 'type' => 'string' ],
					'caption'  => [ 'type' => 'string' ],
				],
				'required'   => [ 'media_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$id         = absint( $input['media_id'] );
				$attachment = get_post( $id );

				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return [ 'success' => false, 'error' => 'Media item not found.' ];
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to update this media item.' ];
				}

				$args = [ 'ID' => $id ];

				if ( isset( $input['title'] ) ) {
					$args['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['caption'] ) ) {
					$args['post_excerpt'] = wp_kses_post( $input['caption'] );
				}

				if ( count( $args ) > 1 ) {
					$result = wp_update_post( $args, true );

					if ( is_wp_error( $result ) ) {
						return [ 'success' => false, 'error' => $result->get_error_message() ];
					}
				}

				if ( isset( $input['alt_text'] ) ) {
					update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
				}

				return [ 'success' => true, 'data' => self::normalize( $id ) ];
			},
			'permission_callback' => self::attachment_permission( 'media_id', 'edit_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_delete() {
		wp_register_ability( 'wp-mcp/delete-media', [
			'label'               => 'Delete Media',
			'description'         => 'Permanently delete a WordPress media item by ID.',
			'category'            => 'wp-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'media_id' => [ 'type' => 'integer', 'description' => 'Media attachment ID to permanently delete' ],
				],
				'required'   => [ 'media_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$id         = absint( $input['media_id'] );
				$attachment = get_post( $id );

				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return [ 'success' => false, 'error' => 'Media item not found.' ];
				}
				if ( ! current_user_can( 'delete_post', $id ) ) {
					return [ 'success' => false, 'error' => 'You do not have permission to delete this media item.' ];
				}

				$result = wp_delete_attachment( $id, true );

				if ( ! $result ) {
					return [ 'success' => false, 'error' => 'Failed to delete media item.' ];
				}

				return [ 'success' => true, 'data' => [ 'id' => $id, 'deleted' => true ] ];
			},
			'permission_callback' => self::attachment_permission( 'media_id', 'delete_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
