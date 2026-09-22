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
				return Webmastery_MCP_Response::local_error( 'forbidden', "Requires {$cap} capability." );
			}
			return true;
		};
	}

	private static function can_read_attachment( $attachment ) {
		$attachment = get_post( $attachment );
		return $attachment && 'attachment' === $attachment->post_type && current_user_can( 'edit_post', $attachment->ID );
	}

	private static function query_readable_attachments( $args, $page, $per_page ) {
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
		$readable_ids = [];

		foreach ( $query->posts as $id ) {
			if ( self::can_read_attachment( (int) $id ) ) {
				$readable_ids[] = (int) $id;
			}
		}

		$total    = count( $readable_ids );
		$page_ids = array_slice( $readable_ids, ( max( 1, (int) $page ) - 1 ) * $per_page, $per_page );

		return [
			'items'       => array_values( array_filter( array_map( [ self::class, 'normalize' ], array_map( 'get_post', $page_ids ) ) ) ),
			'total'       => $total,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		];
	}


	private static function is_private_ip( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		$packed = inet_pton( $ip );
		if ( false !== $packed && 16 === strlen( $packed ) ) {
			if ( "\xff" === $packed[0] || "\x20\x01\x0d\xb8" === substr( $packed, 0, 4 ) ) {
				return true;
			}
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
				return self::is_private_ip( inet_ntop( substr( $packed, 12 ) ) );
			}
		}

		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	protected static function resolve_image_ipv4( $host ) {
		return gethostbynamel( $host );
	}

	protected static function resolve_image_dns( $host ) {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return false;
		}
		// A resolver failure is distinct from a successful response without AAAA records.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Returned failures become explicit WP_Error responses.
		return @dns_get_record( $host, DNS_AAAA | DNS_CNAME );
	}

	private static function validate_image_dns( $host, $visited = [] ) {
		if ( isset( $visited[ $host ] ) || count( $visited ) >= 16 ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'Image hostname has a cyclic or excessive DNS alias chain.' );
		}
		$visited[ $host ] = true;
		$records          = static::resolve_image_dns( $host );
		if ( false === $records ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'Could not resolve image hostname IPv6 records.' );
		}

		foreach ( $records as $record ) {
			if ( 'AAAA' === $record['type'] && self::is_private_ip( $record['ipv6'] ) ) {
				return Webmastery_MCP_Response::local_error( 'invalid_url', 'Image URL must not resolve to a private or reserved address.' );
			}
			if ( 'CNAME' === $record['type'] ) {
				$result = static::validate_image_dns( strtolower( rtrim( $record['target'], '.' ) ), $visited );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
	}

	protected static function validate_public_image_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ), [ 'http', 'https' ] );

		if ( '' === $url ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'Image URL must be a valid http or https URL.' );
		}

		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), " \t\n\r\0\x0B." ) );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'Image URL must include an http or https scheme and host.' );
		}

		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'Image URL must not target a local host.' );
		}

		if ( false !== strpbrk( $host, ':[]' ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'Image URL must use a hostname or public IPv4 address supported by the safe HTTP API.' );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_private_ip( $host )
				? Webmastery_MCP_Response::local_error( 'invalid_url', 'Image URL must not target a private or reserved address.' )
				: $url;
		}

		$resolved_ips = static::resolve_image_ipv4( $host );
		if ( ! is_array( $resolved_ips ) || [] === $resolved_ips ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'Could not resolve image hostname IPv4 records.' );
		}
		foreach ( $resolved_ips as $resolved_ip ) {
			if ( self::is_private_ip( $resolved_ip ) ) {
				return Webmastery_MCP_Response::local_error( 'invalid_url', 'Image URL must not resolve to a private or reserved address.' );
			}
		}

		$result = static::validate_image_dns( $host );
		return is_wp_error( $result ) ? $result : $url;
	}

	private static function download_bounded_image( $url, $max_size ) {
		$filename         = null;
		$too_large        = false;
		$uses_curl        = false;
		$hooks            = null;
		$curl_handles     = [];
		$progress_option  = defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : ( defined( 'CURLOPT_PROGRESSFUNCTION' ) ? CURLOPT_PROGRESSFUNCTION : null );
		$existing_streams = get_resources( 'stream' );
		$owned_stream     = null;
		$identify_stream  = static function () use ( &$filename, &$existing_streams, &$owned_stream ) {
			if ( null === $filename || null !== $owned_stream ) {
				return;
			}
			foreach ( get_resources( 'stream' ) as $stream ) {
				$meta = stream_get_meta_data( $stream );
				if ( ! in_array( $stream, $existing_streams, true )
					&& ( $meta['uri'] ?? null ) === $filename && 'wb' === $meta['mode'] ) {
					$owned_stream = $stream;
					return;
				}
			}
		};
		$progress         = static function ( $bytes, $received ) use ( $max_size, &$too_large, &$uses_curl, $identify_stream ) {
			$identify_stream();
			if ( strlen( $bytes ) > $max_size - $received ) {
				$too_large = true;
				if ( ! $uses_curl ) {
					throw new \WpOrg\Requests\Exception( 'Downloaded image exceeds the maximum allowed upload size.', 'webmastery_image_size' );
				}
			}
		};
		$curl_progress    = static function ( $handle ) use ( &$too_large, &$uses_curl, &$curl_handles, $progress_option ) {
			$curl_handles[] = $handle;
			$uses_curl      = true;
			// Throwing from a PHP write callback alone does not stop all cURL versions.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Configure cancellation on core's existing transport, not a separate request.
			$enabled = curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Requests' own write callback and byte clamp are retained.
			$configured = curl_setopt( $handle, $progress_option, static function () use ( &$too_large ) {
				return $too_large ? 1 : 0;
			} );
			if ( ! $enabled || ! $configured ) {
				throw new \WpOrg\Requests\Exception( 'Could not configure bounded image transfer.', 'webmastery_image_transport' );
			}
		};
		$install_progress = static function ( &$request_url, &$headers, &$data, &$type, &$options ) use ( &$filename, &$hooks, &$uses_curl, &$existing_streams, &$owned_stream, $progress, $curl_progress ) {
			if ( null !== $filename && ( $options['filename'] ?? null ) === $filename ) {
				$uses_curl        = false;
				$existing_streams = get_resources( 'stream' );
				$owned_stream     = null;
			}
			if ( null !== $filename && ( $options['filename'] ?? null ) === $filename && $hooks !== $options['hooks'] ) {
				$hooks = $options['hooks'];
				$hooks->register( 'request.progress', $progress );
				$hooks->register( 'curl.before_send', $curl_progress );
				$hooks->register( 'requests.before_redirect', static function ( $location ) {
					$result = self::validate_public_image_url( $location );
					if ( is_wp_error( $result ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- HTTP errors are returned to the caller, not rendered as HTML.
						throw new \WpOrg\Requests\Exception( $result->get_error_message(), 'webmastery_image_url' );
					}
				} );
			}
		};
		$close_file       = static function () use ( &$owned_stream, $identify_stream ) {
			// Exceptions can skip Requests' fclose(), including timeouts before the first body chunk.
			$identify_stream();
			if ( is_resource( $owned_stream ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the new HTTP output resource, not pre-existing or read-only handles.
				fclose( $owned_stream );
			}
		};
		$close_stream     = static function ( $response, $context, $transport, $args, $request_url ) use ( $url, &$filename, $close_file ) {
			if ( $url === $request_url && null !== $filename && ( $args['filename'] ?? null ) === $filename ) {
				$close_file();
			}
		};
		$identify_request = static function ( $args, $request_url ) use ( $url, &$filename ) {
			if ( $url === $request_url && ! empty( $args['stream'] ) && ! empty( $args['filename'] ) && null === $filename ) {
				$filename = $args['filename'];
			}
			return $args;
		};
		$bound            = static function ( $args, $request_url ) use ( $url, $max_size, &$filename ) {
			if ( $url === $request_url && null !== $filename && ( $args['filename'] ?? null ) === $filename ) {
				$args['limit_response_size'] = $max_size + 1;
			}
			return $args;
		};
		$complete         = static function ( $response, $args, $request_url ) use ( $url, $max_size, &$filename ) {
			if ( $url !== $request_url || null === $filename || ( $args['filename'] ?? null ) !== $filename ) {
				return $response;
			}
			clearstatcache( true, $filename );
			$size = filesize( $filename );
			if ( false !== $size && $size > $max_size ) {
				return Webmastery_MCP_Response::local_error( 'file_too_large', 'Downloaded image exceeds the maximum allowed upload size.' );
			}

			// Compare identity bodies only: compressed wire length is not decoded file length.
			$encoding = wp_remote_retrieve_header( $response, 'content-encoding' );
			$length   = wp_remote_retrieve_header( $response, 'content-length' );
			if ( 200 === wp_remote_retrieve_response_code( $response ) && '' !== $length
				&& ( '' === $encoding || 'identity' === strtolower( $encoding ) )
				&& '' === wp_remote_retrieve_header( $response, 'transfer-encoding' )
				&& ( ! ctype_digit( (string) $length ) || ltrim( (string) $length, '0' ) !== (string) $size )
				&& ! ( 0 === $size && '0' === (string) $length ) ) {
				return Webmastery_MCP_Response::local_error( 'download_failed', 'Downloaded image length does not match the HTTP response.' );
			}
			return $response;
		};

		// Keep core's status, MD5, redirects, TLS, filename handling and error cleanup.
		add_filter( 'http_request_args', $identify_request, PHP_INT_MIN, 2 );
		add_filter( 'http_request_args', $bound, PHP_INT_MAX, 2 );
		add_filter( 'http_response', $complete, PHP_INT_MAX, 3 );
		add_action( 'requests-requests.before_request', $install_progress, PHP_INT_MAX, 5 );
		add_action( 'http_api_debug', $close_stream, PHP_INT_MAX, 5 );
		$result = null;
		try {
			$result = download_url( $url );
			if ( $too_large && is_string( $result ) ) {
				wp_delete_file( $result );
			}
			return $too_large ? Webmastery_MCP_Response::local_error( 'file_too_large', 'Downloaded image exceeds the maximum allowed upload size.' ) : $result;
		} finally {
			$close_file();
			if ( ( null === $result || is_wp_error( $result ) ) && null !== $filename && file_exists( $filename ) ) {
				wp_delete_file( $filename );
			}
			foreach ( $curl_handles as $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Do not leave cancellation state on a reused core handle.
				curl_setopt( $handle, CURLOPT_NOPROGRESS, true );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Release the request-local callback after success or failure.
				curl_setopt( $handle, $progress_option, null );
			}
			remove_filter( 'http_request_args', $identify_request, PHP_INT_MIN );
			remove_filter( 'http_request_args', $bound, PHP_INT_MAX );
			remove_filter( 'http_response', $complete, PHP_INT_MAX );
			remove_action( 'requests-requests.before_request', $install_progress, PHP_INT_MAX );
			remove_action( 'http_api_debug', $close_stream, PHP_INT_MAX );
		}
	}

	public static function upload_image_permission( $input = [] ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires upload_files capability.' );
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return true;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return Webmastery_MCP_Response::local_error( 'not_found', 'Post or page not found.' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this post or page.' );
		}

		return true;
	}

	private static function attachment_permission( $input_key, $cap ) {
		return function ( $input = [] ) use ( $input_key, $cap ) {
			$id         = absint( $input[ $input_key ] ?? 0 );
			$attachment = get_post( $id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return Webmastery_MCP_Response::local_error( 'not_found', 'Media item not found.' );
			}
			if ( ! current_user_can( $cap, $id ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', "Requires {$cap} capability for this media item." );
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

				$per_page = min( max( 1, (int) ( $input['per_page'] ?? 20 ) ), 100 );
				$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
				$data     = self::query_readable_attachments( $args, $page, $per_page );

				return [
					'success' => true,
					'data'    => $data,
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Media item not found.' );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to view this media item.' );
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
					return Webmastery_MCP_Response::from_wp_error( $permission );
				}

				$post_id      = absint( $input['post_id'] ?? 0 );
				$set_featured = ! empty( $input['set_featured'] );
				if ( $set_featured && ! $post_id ) {
					return Webmastery_MCP_Response::legacy_error( 'missing_post_id', 'post_id is required when set_featured is true.' );
				}

				$image_url = self::validate_public_image_url( $input['image_url'] ?? '' );
				if ( is_wp_error( $image_url ) ) {
					return Webmastery_MCP_Response::from_wp_error( $image_url );
				}

				$configured_size = wp_max_upload_size();
				$max_size        = is_int( $configured_size ) || is_string( $configured_size )
					? filter_var( $configured_size, FILTER_VALIDATE_INT, [
						'options' => [ 'min_range' => 1, 'max_range' => PHP_INT_MAX - 1 ],
					] )
					: false;
				if ( false === $max_size ) {
					return Webmastery_MCP_Response::legacy_error( 'invalid_upload_limit', 'The maximum upload size must be a positive integer below PHP_INT_MAX.' );
				}

				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';

				$tmp = self::download_bounded_image( $image_url, $max_size );
				if ( is_wp_error( $tmp ) ) {
					if ( 'file_too_large' === $tmp->get_error_code() ) {
						return Webmastery_MCP_Response::legacy_error( 'file_too_large', 'Image exceeds maximum upload size.', [ 'max_bytes' => $max_size ] );
					}
					return Webmastery_MCP_Response::legacy_error( 'download_failed', 'Failed to download image.' );
				}

				clearstatcache( true, $tmp );
				$file_size = filesize( $tmp );
				if ( false === $file_size || $file_size <= 0 ) {
					wp_delete_file( $tmp );
					return Webmastery_MCP_Response::legacy_error( 'invalid_file', 'Downloaded image file is empty.' );
				}
				if ( $file_size > $max_size ) {
					wp_delete_file( $tmp );
					return Webmastery_MCP_Response::legacy_error(
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
					return Webmastery_MCP_Response::legacy_error( 'unsupported_mime_type', 'Downloaded file must be an allowed image MIME type.' );
				}
				if ( in_array( $mime, [ 'image/png', 'image/jpeg', 'image/gif' ], true ) && false === wp_getimagesize( $tmp ) ) {
					wp_delete_file( $tmp );
					return Webmastery_MCP_Response::legacy_error( 'invalid_file', 'Downloaded image dimensions could not be read.' );
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
					return Webmastery_MCP_Response::legacy_error( 'upload_failed', 'Failed to create image attachment.' );
				}

				$post_update = [ 'ID' => $attachment_id ];
				if ( isset( $input['caption'] ) ) {
					$post_update['post_excerpt'] = wp_kses_post( $input['caption'] );
				}
				if ( isset( $input['title'] ) ) {
					$post_update['post_title'] = sanitize_text_field( $input['title'] );
				}

				if ( count( $post_update ) > 1 ) {
					$updated = wp_update_post( wp_slash( $post_update ), true );
					if ( is_wp_error( $updated ) ) {
						return Webmastery_MCP_Response::legacy_error( 'metadata_update_failed', 'Failed to update image attachment metadata.' );
					}
				}

				if ( isset( $input['alt_text'] ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $input['alt_text'] ) ) );
				}

				if ( $set_featured && ! set_post_thumbnail( $post_id, $attachment_id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'featured_image_failed', 'Failed to set uploaded image as the featured image.' );
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
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Media item not found.' );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to update this media item.' );
				}

				$args = [ 'ID' => $id ];

				if ( isset( $input['title'] ) ) {
					$args['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['caption'] ) ) {
					$args['post_excerpt'] = wp_kses_post( $input['caption'] );
				}

				if ( count( $args ) > 1 ) {
					$result = wp_update_post( wp_slash( $args ), true );

					if ( is_wp_error( $result ) ) {
						return Webmastery_MCP_Response::from_wp_error( $result );
					}
				}

				if ( isset( $input['alt_text'] ) ) {
					update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $input['alt_text'] ) ) );
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
			'description'         => 'Permanently delete a WordPress media item with confirm:true. Known featured-image or literal content URL/GUID references block deletion unless force:true. A successful scan is required even with force.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'media_id' => [ 'type' => 'integer', 'description' => 'Media attachment ID to permanently delete' ],
					'confirm'  => [ 'type' => 'boolean', 'enum' => [ true ], 'description' => 'Must be exactly true to acknowledge permanent deletion.' ],
					'force'    => [ 'type' => 'boolean', 'default' => false, 'description' => 'Override a known positive reference, never permissions or a failed reference scan.' ],
				],
				'required'   => [ 'media_id', 'confirm' ],
			],
			'execute_callback'    => function ( $input ) {
				if ( true !== ( $input['confirm'] ?? null ) ) {
					return Webmastery_MCP_Response::legacy_error( 'missing_confirmation', 'Set confirm to true to acknowledge permanent deletion.' );
				}
				if ( array_key_exists( 'force', $input ) && ! is_bool( $input['force'] ) ) {
					return Webmastery_MCP_Response::legacy_error( 'invalid_input', 'force must be a boolean.' );
				}
				$id         = absint( $input['media_id'] );
				$attachment = get_post( $id );

				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Media item not found.' );
				}
				if ( ! current_user_can( 'delete_post', $id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You do not have permission to delete this media item.' );
				}

				$in_use = Webmastery_MCP_Content_Hygiene::is_attachment_referenced( $id );
				if ( is_wp_error( $in_use ) ) {
					return Webmastery_MCP_Response::from_wp_error( $in_use );
				}
				if ( $in_use && true !== ( $input['force'] ?? false ) ) {
					return Webmastery_MCP_Response::legacy_error( 'media_in_use', 'Media has known references. Set force to true only if deleting referenced media is intended.' );
				}

				$result = wp_delete_attachment( $id, true );

				if ( ! $result ) {
					return Webmastery_MCP_Response::legacy_error( 'delete_failed', 'Failed to delete media item.' );
				}

				return [ 'success' => true, 'data' => [ 'id' => $id, 'deleted' => true, 'in_use' => $in_use ] ];
			},
			'permission_callback' => self::attachment_permission( 'media_id', 'delete_post' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
