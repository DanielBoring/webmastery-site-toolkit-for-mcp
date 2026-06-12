<?php

defined( 'ABSPATH' ) || exit;

class Unlock_MCP_Health {

	public static function register() {
		wp_register_ability( 'wp-mcp/site-health-check', [
			'label'               => 'Site Health Check',
			'description'         => 'Run WordPress site health tests and return results grouped by severity.',
			'category'            => 'wp-mcp',
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'forbidden', 'Requires manage_options capability.' );
				}
				return true;
			},
			'meta' => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute( $input = [] ) {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		$health  = WP_Site_Health::get_instance();
		$results = [
			'critical'    => [],
			'recommended' => [],
			'good'        => [],
		];

		$tests = [
			'wordpress_version'    => [ $health, 'get_test_wordpress_version' ],
			'plugin_version'       => [ $health, 'get_test_plugin_version' ],
			'theme_version'        => [ $health, 'get_test_theme_version' ],
			'php_version'          => [ $health, 'get_test_php_version' ],
			'sql_server'           => [ $health, 'get_test_sql_server' ],
			'ssl_support'          => [ $health, 'get_test_ssl_support' ],
			'scheduled_events'     => [ $health, 'get_test_scheduled_events' ],
			'http_requests'        => [ $health, 'get_test_http_requests' ],
			'rest_availability'    => [ $health, 'get_test_rest_availability' ],
			'debug_enabled'        => [ $health, 'get_test_is_in_debug_mode' ],
			'file_uploads'         => [ $health, 'get_test_file_uploads' ],
			'plugin_theme_auto_update' => [ $health, 'get_test_plugin_theme_auto_updates' ],
		];

		foreach ( $tests as $key => $callable ) {
			if ( ! is_callable( $callable ) ) {
				continue;
			}

			try {
				$result = call_user_func( $callable );
			} catch ( Throwable $e ) {
				continue;
			}

			if ( empty( $result ) || empty( $result['status'] ) ) {
				continue;
			}

			$bucket = $result['status'];
			if ( ! isset( $results[ $bucket ] ) ) {
				$bucket = 'recommended';
			}

			$results[ $bucket ][] = [
				'test'        => $key,
				'label'       => wp_strip_all_tags( $result['label'] ?? $key ),
				'description' => wp_strip_all_tags( $result['description'] ?? '' ),
				'actions'     => wp_strip_all_tags( $result['actions'] ?? '' ),
			];
		}

		return [
			'success' => true,
			'data'    => [
				'summary' => [
					'critical'    => count( $results['critical'] ),
					'recommended' => count( $results['recommended'] ),
					'good'        => count( $results['good'] ),
				],
				'results' => $results,
			],
		];
	}
}
