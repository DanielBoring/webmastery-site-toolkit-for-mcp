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
					'post_id'       => [ 'type' => 'integer', 'description' => 'Post or page ID' ],
					'attachment_id' => [ 'type' => 'integer', 'description' => 'Image attachment ID to use as the featured image' ],
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

				$result = set_post_thumbnail( $post->ID, $attachment_id );

				if ( ! $result ) {
					return Webmastery_MCP_Response::legacy_error( 'featured_image_failed', 'Failed to set featured image.' );
				}

				return [ 'success' => true, 'data' => Webmastery_MCP_Posts::normalize( $post->ID ) ];
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
					'post_id' => [ 'type' => 'integer', 'description' => 'Post or page ID' ],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$post = Webmastery_MCP_Post_Access::get_featured_image_target( $input );

				if ( is_wp_error( $post ) ) {
					return Webmastery_MCP_Response::from_wp_error( $post );
				}

				delete_post_thumbnail( $post->ID );

				return [ 'success' => true, 'data' => Webmastery_MCP_Posts::normalize( $post->ID ) ];
			},
			'permission_callback' => Webmastery_MCP_Post_Access::featured_image_permission(),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
