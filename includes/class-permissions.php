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
}
