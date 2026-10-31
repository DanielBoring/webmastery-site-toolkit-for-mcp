<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_Featured_Image {

	public static function register() {
		self::register_set_featured_image();
		self::register_remove_featured_image();
	}

	private static function register_set_featured_image() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/set-featured-image', [
			'label'               => 'Set Featured Image',
			'description'         => 'Set the featured image for a WordPress post or page by attachment ID.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'         => [ 'type' => 'integer', 'description' => 'Post or page ID' ],
					'attachment_id'   => [ 'type' => 'integer', 'description' => 'Image attachment ID to use as the featured image' ],
					'response_format' => self::response_format_schema(),
				],
				'required'   => [ 'post_id', 'attachment_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = Webmastery_MCP_Post_Access::get_featured_image_target( $input );

				if ( is_wp_error( $post ) ) {
					return Webmastery_MCP_Response::from_wp_error( $post );
				}

				$attachment_id = absint( $input['attachment_id'] );
				$attachment    = get_post( $attachment_id );

				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return Webmastery_MCP_Response::legacy_error( 'not_found', 'Attachment not found.' );
				}
				if ( ! wp_attachment_is_image( $attachment_id ) ) {
					return Webmastery_MCP_Response::legacy_error( 'unsupported_mime_type', 'Attachment must be an image.' );
				}

				$format      = $input['response_format'] ?? 'full';
				$previous_id = 'compact' === $format ? (int) get_post_thumbnail_id( $post->ID ) : 0;
				$result      = set_post_thumbnail( $post->ID, $attachment_id );

				if ( ! $result ) {
					return Webmastery_MCP_Response::legacy_error( 'featured_image_failed', 'Failed to set featured image.' );
				}

				return self::response( (int) $post->ID, $format, $previous_id );
			},
			'permission_callback' => Webmastery_MCP_Post_Access::featured_image_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_remove_featured_image() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/remove-featured-image', [
			'label'               => 'Remove Featured Image',
			'description'         => 'Remove the featured image from a WordPress post or page.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'         => [ 'type' => 'integer', 'description' => 'Post or page ID' ],
					'response_format' => self::response_format_schema(),
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = Webmastery_MCP_Post_Access::get_featured_image_target( $input );

				if ( is_wp_error( $post ) ) {
					return Webmastery_MCP_Response::from_wp_error( $post );
				}

				$format      = $input['response_format'] ?? 'full';
				$previous_id = 'compact' === $format ? (int) get_post_thumbnail_id( $post->ID ) : 0;
				delete_post_thumbnail( $post->ID );

				return self::response( (int) $post->ID, $format, $previous_id );
			},
			'permission_callback' => Webmastery_MCP_Post_Access::featured_image_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function response_format_schema(): array {
		return [
			'type'        => 'string',
			'enum'        => [ 'full', 'compact' ],
			'default'     => 'full',
			'description' => 'Full returns the existing normalized post; compact returns only featured-image confirmation fields.',
		];
	}

	private static function response( int $post_id, string $format, int $previous_id ): array {
		if ( 'compact' !== $format ) {
			return [ 'success' => true, 'data' => Webmastery_MCP_Posts::normalize( $post_id ) ];
		}

		$current_id = (int) get_post_thumbnail_id( $post_id );
		// Match the media response's object gate without changing assignment eligibility.
		$url = $current_id && current_user_can( 'edit_post', $current_id ) ? wp_get_attachment_url( $current_id ) : false;
		return Webmastery_MCP_Response::ok( Webmastery_MCP_Untrusted::mark( [
			'post_id'                    => $post_id,
			'featured_image_id'          => $current_id,
			'featured_image_url'         => false === $url ? null : $url,
			'previous_featured_image_id' => $previous_id,
		], [ 'featured_image_url' ] ) );
	}
}
