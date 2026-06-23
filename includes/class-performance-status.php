<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Performance_Status {

	private const KNOWN_PAGE_CACHE_PLUGINS = array(
		'wp-super-cache/wp-cache.php'                       => 'WP Super Cache',
		'w3-total-cache/w3-total-cache.php'                 => 'W3 Total Cache',
		'wp-rocket/wp-rocket.php'                           => 'WP Rocket',
		'litespeed-cache/litespeed-cache.php'               => 'LiteSpeed Cache',
		'nginx-helper/nginx-helper.php'                     => 'Nginx Helper',
		'wp-fastest-cache/wpFastestCache.php'               => 'WP Fastest Cache',
		'wp-optimize/wp-optimize.php'                       => 'WP-Optimize',
		'cache-enabler/cache-enabler.php'                   => 'Cache Enabler',
		'autoptimize/autoptimize.php'                       => 'Autoptimize',
		'breeze/breeze.php'                                 => 'Breeze',
		'comet-cache/comet-cache.php'                       => 'Comet Cache',
		'hummingbird-performance/wp-hummingbird.php'        => 'Hummingbird',
		'sg-cachepress/sg-cachepress.php'                   => 'Speed Optimizer',
		'wp-cloudflare-page-cache/wp-cloudflare-super-page-cache.php' => 'Super Page Cache for Cloudflare',
	);

	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/performance-status', array(
			'label'               => 'Performance Status',
			'description'         => 'Inspect WordPress caching and performance-related configuration including object cache, page cache plugins, memory limits, revision limits, autosave interval, and script concatenation.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( self::class, 'permission' ),
			'meta'                => array(
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				'mcp'         => array( 'public' => true, 'type' => 'tool' ),
			),
		) );
	}

	public static function permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires manage_options capability.' );
		}

		return true;
	}

	public static function execute( $input = array() ) {
		return array(
			'success' => true,
			'data'    => array(
				'object_cache'        => self::get_object_cache_status(),
				'page_cache'          => self::get_page_cache_status(),
				'memory'              => self::get_memory_status(),
				'post_revisions'      => self::get_post_revisions_status(),
				'autosave'            => self::get_autosave_status(),
				'concatenate_scripts' => self::get_concatenate_scripts_status(),
			),
		);
	}

	private static function get_object_cache_status() {
		$dropin_path = WP_CONTENT_DIR . '/object-cache.php';

		return array(
			'external_object_cache_active' => wp_using_ext_object_cache(),
			'object_cache_dropin_present' => file_exists( $dropin_path ),
		);
	}

	private static function get_page_cache_status() {
		$active_plugins = self::get_active_plugin_basenames();
		$plugins        = array();

		foreach ( self::KNOWN_PAGE_CACHE_PLUGINS as $basename => $name ) {
			if ( in_array( $basename, $active_plugins, true ) ) {
				$plugins[] = array(
					'name'     => $name,
					'basename' => $basename,
				);
			}
		}

		return array(
			'known_page_cache_active'       => ! empty( $plugins ),
			'active_known_plugins'          => $plugins,
			'advanced_cache_dropin_present' => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
		);
	}

	private static function get_memory_status() {
		$server_memory_limit = ini_get( 'memory_limit' );

		return array(
			'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : null,
			'server_memory_limit' => false === $server_memory_limit ? null : (string) $server_memory_limit,
		);
	}

	private static function get_post_revisions_status() {
		$value = self::get_constant_value( 'WP_POST_REVISIONS' );

		return array(
			'defined' => defined( 'WP_POST_REVISIONS' ),
			'value'   => $value,
		);
	}

	private static function get_autosave_status() {
		return array(
			'defined'          => defined( 'AUTOSAVE_INTERVAL' ),
			'interval_seconds' => defined( 'AUTOSAVE_INTERVAL' ) ? absint( AUTOSAVE_INTERVAL ) : 60,
		);
	}

	private static function get_concatenate_scripts_status() {
		$value = self::get_constant_value( 'CONCATENATE_SCRIPTS' );

		return array(
			'defined'  => defined( 'CONCATENATE_SCRIPTS' ),
			'value'    => $value,
			'disabled' => defined( 'CONCATENATE_SCRIPTS' ) && ! (bool) CONCATENATE_SCRIPTS,
		);
	}

	private static function get_active_plugin_basenames() {
		$active_plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = array();
		}

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		return array_values( array_unique( array_map( 'strval', $active_plugins ) ) );
	}

	private static function get_constant_value( $constant ) {
		if ( ! defined( $constant ) ) {
			return null;
		}

		$value = constant( $constant );

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) || null === $value ) {
			return $value;
		}

		return (string) $value;
	}
}
