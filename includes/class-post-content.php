<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_Post_Content {
	public static function normalize( $post, string $fields = 'full' ): ?array {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}
		$data = [
			'id'                => $post->ID,
			'title'             => $post->post_title,
			'content'           => $post->post_content,
			'excerpt'           => $post->post_excerpt,
			'status'            => $post->post_status,
			'slug'              => $post->post_name,
			'url'               => get_permalink( $post->ID ),
			'author'            => (int) $post->post_author,
			'author_name'       => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'date_created'      => $post->post_date,
			'date_modified'     => $post->post_modified,
			'type'              => $post->post_type,
			'featured_image_id' => (int) get_post_thumbnail_id( $post->ID ),
		];
		$data = Webmastery_MCP_Untrusted::mark( $data, [ 'title', 'content', 'excerpt', 'slug', 'url', 'author_name' ] );
		return Webmastery_MCP_List_Query::project( $data, $fields );
	}
}
