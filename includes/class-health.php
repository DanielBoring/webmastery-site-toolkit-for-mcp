<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Health {

	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/site-health-check', [
			'label'               => 'Site Health Check',
			'description'         => 'Run WordPress site health tests and return results grouped by severity.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => Webmastery_MCP_Permissions::admin(),
			'meta' => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	/**
	 * Most direct tests run per call; bounds work when plugins register many tests.
	 */
	private const MAX_TESTS = 60;

	/**
	 * Result keys kept from the former fixed list where core's key differs.
	 */
	private const LEGACY_KEYS = [ 'plugin_theme_auto_updates' => 'plugin_theme_auto_update' ];

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
		$errors  = [];

		// Direct tests only, including core's newer tests and plugin tests added by site_status_tests.
		// Async tests need loopback or REST round trips and are not run.
		$registered = WP_Site_Health::get_tests();
		$direct     = is_array( $registered['direct'] ?? null ) ? $registered['direct'] : [];
		$skipped    = max( 0, count( $direct ) - self::MAX_TESTS );

		foreach ( array_slice( $direct, 0, self::MAX_TESTS, true ) as $name => $test ) {
			$key = substr( sanitize_key( (string) $name ), 0, 100 );
			$key = self::LEGACY_KEYS[ $key ] ?? ( '' === $key ? 'unnamed_test' : $key );

			try {
				$callable = self::test_callable( $health, $test );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own result filter, applied as WP_Site_Health does.
				$result = null === $callable ? null : apply_filters( 'site_status_test_result', call_user_func( $callable ) );
			} catch ( Throwable $e ) {
				// Never return exception text: it can contain paths or SQL.
				$result = null;
			}

			$status = is_array( $result ) && is_string( $result['status'] ?? null ) ? $result['status'] : '';
			if ( '' === $status ) {
				$errors[] = [ 'test' => $key ];
				continue;
			}

			$bucket = isset( $results[ $status ] ) ? $status : 'recommended';

			$results[ $bucket ][] = Webmastery_MCP_Untrusted::mark(
				[
					'test'        => $key,
					'label'       => wp_strip_all_tags( self::text( $result['label'] ?? $key ) ),
					'description' => wp_strip_all_tags( self::text( $result['description'] ?? '' ) ),
					'actions'     => wp_strip_all_tags( self::text( $result['actions'] ?? '' ) ),
				],
				[ 'label', 'description', 'actions' ]
			);
		}

		return [
			'success' => true,
			'data'    => [
				'summary' => [
					'critical'    => count( $results['critical'] ),
					'recommended' => count( $results['recommended'] ),
					'good'        => count( $results['good'] ),
					'errors'      => count( $errors ),
					'skipped'     => $skipped,
				],
				'results' => $results,
				'errors'  => $errors,
			],
		];
	}

	/**
	 * Resolve a direct test like core: a method name on the instance first, then a callable.
	 *
	 * @param WP_Site_Health $health Site Health instance.
	 * @param mixed          $test   Registered direct test definition.
	 * @return callable|null
	 */
	private static function test_callable( $health, $test ) {
		$test = is_array( $test ) ? ( $test['test'] ?? null ) : null;
		if ( is_string( $test ) ) {
			$method = 'get_test_' . $test;
			if ( method_exists( $health, $method ) && is_callable( [ $health, $method ] ) ) {
				return [ $health, $method ];
			}
		}

		return is_callable( $test ) ? $test : null;
	}

	private static function text( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
