<?php

defined( 'ABSPATH' ) || exit;

/**
 * Persistence boundaries accept already sanitized, unslashed values.
 */
final class Webmastery_MCP_Post_Writes {
	/**
	 * Insert unslashed fields at the WordPress persistence boundary.
	 *
	 * @param array<string, mixed> $args Sanitized, unslashed post fields.
	 * @return int|WP_Error
	 */
	public static function insert( array $args ) {
		return wp_insert_post( wp_slash( $args ), true );
	}

	/**
	 * Update unslashed fields at the WordPress persistence boundary.
	 *
	 * @param array<string, mixed> $args Sanitized, unslashed post fields.
	 * @return int|WP_Error
	 */
	public static function update( array $args ) {
		return wp_update_post( wp_slash( $args ), true );
	}

	public static function meta( int $id, string $key, $value ) {
		return update_post_meta( $id, $key, wp_slash( $value ) );
	}
}
