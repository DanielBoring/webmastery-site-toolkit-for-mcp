<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Media {

	public static function register() {
		self::register_list();
		self::register_get();
		self::register_upload_image();
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

	private static function is_private_ip( $ip ) {
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	private static function validate_public_image_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ), [ 'http', 'https' ] );

		if ( '' === $url ) {
			return new WP_Error( 'invalid_url', 'Image URL must be a valid http or https URL.' );
		}

		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), " \t\n\r\0\x0B." ) );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
			return new WP_Error( 'invalid_url', 'Image URL must include an http or https scheme and host.' );
		}

		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) {
			return new WP_Error( 'invalid_url', 'Image URL must not target a local host.' );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) && self::is_private_ip( $host ) ) {
			return new WP_Error( 'invalid_url', 'Image URL must not target a private or reserved address.' );
		}

		$resolved_ips = gethostbynamel( $host );
		if ( is_array( $resolved_ips ) ) {
			foreach ( $resolved_ips as $resolved_ip ) {
				if ( self::is_private_ip( $resolved_ip ) ) {
					return new WP_Error( 'invalid_url', 'Image URL must not resolve to a private or reserved address.' );
				}
			}
		}

		return $url;
	}

	public static function upload_image_permission( $input = [] ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'forbidden', 'Requires upload_files capability.' );
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return true;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return new WP_Error( 'not_found', 'Post or page not found.' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Requires edit_post capability for this post or page.' );
		}

		return true;
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
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-media', [
			'label'               => 'List Media',
			'description'         => 'List WordPress media items with optional filters.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
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
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-media', [
			'label'               => 'Get Media',
			'description'         => 'Get a single WordPress media item by ID.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
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

	private static function register_upload_image() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/upload-image', [
			'label'               => 'Upload Image from URL',
			'description'         => 'Download a public image URL into the WordPress media library and optionally set it as a featured image.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'image_url'    => [ 'type' => 'string', 'description' => 'Public http or https image URL to sideload' ],
					'post_id'      => [ 'type' => 'integer', 'description' => 'Optional post or page ID to attach the image to' ],
					'set_featured' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Set the uploaded image as the featured image for post_id' ],
					'title'        => [ 'type' => 'string' ],
					'alt_text'     => [ 'type' => 'string' ],
					'caption'      => [ 'type' => 'string' ],
				],
				'required'   => [ 'image_url' ],
			],
			'execute_callback'    => function ( $input ) {
				$permission = self::upload_image_permission( $input );
				if ( is_wp_error( $permission ) ) {
					return self::error_response( $permission->get_error_code(), $permission->get_error_message() );
				}

				$image_url = self::validate_public_image_url( $input['image_url'] ?? '' );
				if ( is_wp_error( $image_url ) ) {
					return self::error_response( $image_url->get_error_code(), $image_url->get_error_message() );
				}

				$post_id      = absint( $input['post_id'] ?? 0 );
				$set_featured = ! empty( $input['set_featured'] );
				if ( $set_featured && ! $post_id ) {
					return self::error_response( 'missing_post_id', 'post_id is required when set_featured is true.' );
				}

				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';

				$tmp = download_url( $image_url );
				if ( is_wp_error( $tmp ) ) {
					return self::error_response( 'download_failed', $tmp->get_error_message() );
				}

				$file_size = filesize( $tmp );
				$max_size  = wp_max_upload_size();
				if ( false === $file_size || $file_size <= 0 ) {
					wp_delete_file( $tmp );
					return self::error_response( 'invalid_file', 'Downloaded image file is empty.' );
				}
				if ( $file_size > $max_size ) {
					wp_delete_file( $tmp );
					return self::error_response(
						'file_too_large',
						'Downloaded image exceeds the maximum allowed upload size.',
						[ 'max_bytes' => (int) $max_size ]
					);
				}

				$path     = wp_parse_url( $image_url, PHP_URL_PATH );
				$path     = is_string( $path ) ? $path : '';
				$filename = sanitize_file_name( wp_basename( $path ) );
				if ( '' === $filename ) {
					$filename = 'uploaded-image';
				}

				$filetype = wp_check_filetype_and_ext( $tmp, $filename, get_allowed_mime_types() );
				$mime     = (string) ( $filetype['type'] ?? '' );
				if ( '' === $mime || ! str_starts_with( $mime, 'image/' ) ) {
					wp_delete_file( $tmp );
					return self::error_response( 'unsupported_mime_type', 'Downloaded file must be an allowed image MIME type.' );
				}
				if ( ! empty( $filetype['proper_filename'] ) ) {
					$filename = sanitize_file_name( $filetype['proper_filename'] );
				}

				$file_array = [
					'name'     => $filename,
					'tmp_name' => $tmp,
					'type'     => $mime,
					'size'     => $file_size,
				];

				if ( isset( $input['title'] ) ) {
					$file_array['post_data'] = [ 'post_title' => sanitize_text_field( $input['title'] ) ];
				}

				$attachment_id = media_handle_sideload( $file_array, $post_id );
				if ( is_wp_error( $attachment_id ) ) {
					wp_delete_file( $tmp );
					return self::error_response( 'upload_failed', $attachment_id->get_error_message() );
				}

				$post_update = [ 'ID' => $attachment_id ];
				if ( isset( $input['caption'] ) ) {
					$post_update['post_excerpt'] = wp_kses_post( $input['caption'] );
				}
				if ( isset( $input['title'] ) ) {
					$post_update['post_title'] = sanitize_text_field( $input['title'] );
				}

				if ( count( $post_update ) > 1 ) {
					$updated = wp_update_post( $post_update, true );
					if ( is_wp_error( $updated ) ) {
						return self::error_response( 'metadata_update_failed', $updated->get_error_message() );
					}
				}

				if ( isset( $input['alt_text'] ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
				}

				if ( $set_featured && ! set_post_thumbnail( $post_id, $attachment_id ) ) {
					return self::error_response( 'featured_image_failed', 'Failed to set uploaded image as the featured image.' );
				}

				return [ 'success' => true, 'data' => self::normalize( $attachment_id ) ];
			},
			'permission_callback' => [ self::class, 'upload_image_permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_update() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/update-media', [
			'label'               => 'Update Media',
			'description'         => 'Update media alt text, title, and caption.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
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
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/delete-media', [
			'label'               => 'Delete Media',
			'description'         => 'Permanently delete a WordPress media item by ID.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
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
