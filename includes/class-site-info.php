<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Site_Info {

	public static function register() {
		self::register_site_info();
		self::register_user_info();
		self::register_environment_info();
	}

	public static function permission() {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', 'Requires read capability.' );
		}

		return true;
	}

	public static function get_site_info() {
		$theme = wp_get_theme();

		return [
			'success' => true,
			'data'    => [
				'blog_name'           => get_bloginfo( 'name' ),
				'tagline'             => get_bloginfo( 'description' ),
				'site_url'            => site_url(),
				'home_url'            => home_url(),
				'language'            => get_bloginfo( 'language' ),
				'wordpress_version'   => get_bloginfo( 'version' ),
				'active_theme'        => [
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
				],
				'timezone'            => self::get_timezone(),
				'is_multisite'        => is_multisite(),
				'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
			],
		];
	}

	public static function get_user_info() {
		$user = wp_get_current_user();

		return [
			'success' => true,
			'data'    => [
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
				'capabilities' => self::get_capability_summary(),
			],
		];
	}

	public static function get_environment_info() {
		global $wpdb;

		return [
			'success' => true,
			'data'    => [
				'php_version'         => PHP_VERSION,
				'mysql_version'       => (string) $wpdb->db_version(),
				'wp_environment_type' => wp_get_environment_type(),
				'locale'              => get_locale(),
			],
		];
	}

	private static function register_site_info() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-site-info', [
			'label'               => 'Get Site Info',
			'description'         => 'Get stable, non-sensitive WordPress site details for MCP workflows.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'get_site_info' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_user_info() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-user-info', [
			'label'               => 'Get Current User Info',
			'description'         => 'Get stable profile and capability summary details for the authenticated WordPress user.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'get_user_info' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_environment_info() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-environment-info', [
			'label'               => 'Get Environment Info',
			'description'         => 'Get stable, non-sensitive WordPress runtime details without filesystem paths or secrets.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'get_environment_info' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function get_timezone() {
		$timezone_string = get_option( 'timezone_string' );
		if ( is_string( $timezone_string ) && '' !== $timezone_string ) {
			return $timezone_string;
		}

		$offset         = (float) get_option( 'gmt_offset', 0 );
		$sign           = $offset < 0 ? '-' : '+';
		$offset_minutes = (int) round( abs( $offset ) * 60 );
		$hours          = intdiv( $offset_minutes, 60 );
		$minutes        = $offset_minutes % 60;

		return sprintf( 'UTC%s%02d:%02d', $sign, $hours, $minutes );
	}

	private static function get_capability_summary() {
		$capabilities = [
			'read',
			'edit_posts',
			'edit_pages',
			'upload_files',
			'manage_categories',
			'moderate_comments',
			'list_users',
			'activate_plugins',
			'manage_options',
		];

		$summary = [];
		foreach ( $capabilities as $capability ) {
			$summary[ $capability ] = current_user_can( $capability );
		}

		return $summary;
	}
}
