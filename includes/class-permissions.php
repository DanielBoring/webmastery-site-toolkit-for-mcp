<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_Permissions {
	/**
	 * Check the current user's effective capability without caching.
	 *
	 * @param string $cap WordPress capability.
	 * @return true|WP_Error
	 */
	public static function check( string $cap ): bool|WP_Error {
		if ( ! current_user_can( $cap ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', "Requires {$cap} capability." );
		}

		return true;
	}

	public static function cap( string $cap ): Closure {
		return static function () use ( $cap ): bool|WP_Error {
			return self::check( $cap );
		};
	}

	public static function admin(): Closure {
		return self::cap( 'manage_options' );
	}

	public static function object( string $type, string $input_key, string $cap, string $not_found, string $forbidden ): Closure {
		return static function ( $input = [] ) use ( $type, $input_key, $cap, $not_found, $forbidden ): bool|WP_Error {
			$id   = absint( $input[ $input_key ] ?? 0 );
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== $type ) {
				return Webmastery_MCP_Response::local_error( 'not_found', $not_found );
			}
			if ( ! current_user_can( $cap, $id ) ) {
				return Webmastery_MCP_Response::local_error( 'forbidden', $forbidden );
			}
			return true;
		};
	}
}
