<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Site_Kit {

	private const REST_ROOT = '/google-site-kit/v1';

	private const PLUGIN_FILE = 'google-site-kit/google-site-kit.php';

	private const PERMISSIONS_MINIMUM_VERSION = '1.82.0';

	public static function register() {
		self::register_status();
		self::register_modules();
		self::register_permissions();
		self::register_pagespeed();
	}

	private static function register_status() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-site-kit-status', [
			'label'               => 'Google Site Kit: Status',
			'description'         => 'Inspect Google Site Kit availability, version, site connection, setup, and current-user authentication status.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute_status' ],
			'permission_callback' => [ self::class, 'permission_status' ],
			'meta'                => self::readonly_meta(),
		] );
	}

	private static function register_modules() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-site-kit-modules', [
			'label'               => 'Google Site Kit: Modules',
			'description'         => 'List Google Site Kit modules with safe availability, activation, connection, sharing, and dependency states.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute_modules' ],
			'permission_callback' => [ self::class, 'permission_modules' ],
			'meta'                => self::readonly_meta(),
		] );
	}

	private static function register_permissions() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-site-kit-permissions', [
			'label'               => 'Google Site Kit: Permissions',
			'description'         => 'Return the current user\'s effective Google Site Kit permission matrix, including module dashboard sharing.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute_permissions' ],
			'permission_callback' => [ self::class, 'permission_permissions' ],
			'meta'                => self::readonly_meta(),
		] );
	}

	private static function register_pagespeed() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed', [
			'label'               => 'Google Site Kit: PageSpeed',
			'description'         => 'Fetch a safe PageSpeed Insights summary through Google Site Kit for a URL on the current site.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'url'      => [
						'type'        => 'string',
						'format'      => 'uri',
						'description' => 'Same-site URL to analyze. Defaults to the site home URL.',
					],
					'strategy' => [
						'type'        => 'string',
						'enum'        => [ 'mobile', 'desktop' ],
						'description' => 'PageSpeed device strategy.',
					],
				],
				'required'   => [ 'strategy' ],
			],
			'execute_callback'    => [ self::class, 'execute_pagespeed' ],
			'permission_callback' => [ self::class, 'permission_pagespeed' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function readonly_meta() {
		return [
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			'mcp'         => [ 'public' => true, 'type' => 'tool' ],
		];
	}

	public static function permission_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires manage_options capability.' );
		}

		$plugin = self::plugin_status();
		if ( ! $plugin['active'] ) {
			return true;
		}

		return self::check_route_permission( self::REST_ROOT . '/core/site/data/connection' );
	}

	public static function permission_modules() {
		if ( ! current_user_can( 'read' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires read capability.' );
		}

		return self::check_route_permission( self::REST_ROOT . '/core/modules/data/list' );
	}

	public static function permission_permissions() {
		if ( ! current_user_can( 'read' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires read capability.' );
		}

		return self::check_route_permission(
			self::REST_ROOT . '/core/user/data/permissions',
			[],
			self::PERMISSIONS_MINIMUM_VERSION
		);
	}

	public static function permission_pagespeed( $input = [] ) {
		if ( ! current_user_can( 'read' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires read capability.' );
		}

		return self::check_route_permission(
			self::REST_ROOT . '/modules/pagespeed-insights/data/pagespeed',
			[
				'url'      => esc_url_raw( $input['url'] ?? '' ),
				'strategy' => sanitize_key( $input['strategy'] ?? '' ),
			]
		);
	}

	public static function execute_status() {
		$plugin = self::plugin_status();
		if ( ! $plugin['active'] ) {
			return [
				'success' => true,
				'data'    => [
					'plugin'         => $plugin,
					'connection'     => null,
					'authentication' => null,
					'compatibility'  => [
						'status' => 'unavailable',
						'note'   => 'Google Site Kit must be active to inspect connection and authentication status.',
					],
				],
			];
		}

		$connection = self::dispatch_route( self::REST_ROOT . '/core/site/data/connection' );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$authentication = self::dispatch_route( self::REST_ROOT . '/core/user/data/authentication' );
		if ( is_wp_error( $authentication ) ) {
			return $authentication;
		}

		return [
			'success' => true,
			'data'    => [
				'plugin'         => $plugin,
				'connection'     => [
					'connected'       => (bool) ( $connection['connected'] ?? false ),
					'setup_completed' => (bool) ( $connection['setupCompleted'] ?? false ),
				],
				'authentication' => [
					'authenticated'          => (bool) ( $authentication['authenticated'] ?? false ),
					'needs_reauthentication' => (bool) ( $authentication['needsReauthentication'] ?? false ),
				],
				'compatibility'  => [
					'status' => 'supported',
					'note'   => 'Uses Google Site Kit internal REST routes as a compatibility adapter.',
				],
			],
		];
	}

	public static function execute_modules() {
		if ( ! current_user_can( 'read' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires read capability.' );
		}

		$modules = self::dispatch_route( self::REST_ROOT . '/core/modules/data/list', [], '', true );
		if ( is_wp_error( $modules ) ) {
			return $modules;
		}

		$items = self::normalize_modules( $modules );

		return [
			'success' => true,
			'data'    => [
				'items' => $items,
				'total' => count( $items ),
			],
		];
	}

	public static function execute_permissions() {
		if ( ! current_user_can( 'read' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires read capability.' );
		}

		$permissions = self::dispatch_route(
			self::REST_ROOT . '/core/user/data/permissions',
			[],
			self::PERMISSIONS_MINIMUM_VERSION,
			true
		);
		if ( is_wp_error( $permissions ) ) {
			return $permissions;
		}

		return [
			'success' => true,
			'data'    => self::normalize_permissions( $permissions ),
		];
	}

	public static function execute_pagespeed( $input = [] ) {
		if ( ! current_user_can( 'read' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires read capability.' );
		}

		$validated = self::validate_pagespeed_input( $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$modules = self::dispatch_route( self::REST_ROOT . '/core/modules/data/list', [], '', true );
		if ( is_wp_error( $modules ) ) {
			return $modules;
		}

		$pagespeed_module = null;
		foreach ( self::normalize_modules( $modules ) as $module ) {
			if ( 'pagespeed-insights' === $module['slug'] ) {
				$pagespeed_module = $module;
				break;
			}
		}

		if ( null === $pagespeed_module ) {
			return Webmastery_MCP_Response::local_error( 'site_kit_module_unavailable', 'The Site Kit PageSpeed Insights module is unavailable.' );
		}
		if ( ! $pagespeed_module['active'] ) {
			return Webmastery_MCP_Response::local_error( 'site_kit_module_inactive', 'The Site Kit PageSpeed Insights module is not active.' );
		}
		if ( ! $pagespeed_module['connected'] ) {
			return Webmastery_MCP_Response::local_error( 'site_kit_module_not_connected', 'The Site Kit PageSpeed Insights module is not connected.' );
		}

		$result = self::dispatch_route(
			self::REST_ROOT . '/modules/pagespeed-insights/data/pagespeed',
			$validated,
			'',
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'success' => true,
			'data'    => self::normalize_pagespeed( $result, $validated ),
		];
	}

	private static function plugin_status() {
		$plugins = [];
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			$plugins = get_plugins();
		}

		$active  = ( function_exists( 'is_plugin_active' ) && is_plugin_active( self::PLUGIN_FILE ) )
			|| ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( self::PLUGIN_FILE ) )
			|| defined( 'GOOGLESITEKIT_VERSION' )
			|| class_exists( 'Google\\Site_Kit\\Plugin' );
		$version = defined( 'GOOGLESITEKIT_VERSION' )
			? (string) GOOGLESITEKIT_VERSION
			: (string) ( $plugins[ self::PLUGIN_FILE ]['Version'] ?? '' );

		return [
			'installed' => isset( $plugins[ self::PLUGIN_FILE ] ) || $active,
			'active'    => $active,
			'version'   => '' !== $version ? sanitize_text_field( $version ) : null,
		];
	}

	private static function validate_pagespeed_input( $input ) {
		$strategy = sanitize_key( $input['strategy'] ?? '' );
		if ( ! in_array( $strategy, [ 'mobile', 'desktop' ], true ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_strategy', 'Strategy must be mobile or desktop.' );
		}

		$url = empty( $input['url'] ) ? home_url( '/' ) : esc_url_raw( $input['url'] );
		if ( '' === $url || ! self::is_same_site_url( $url ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'PageSpeed URL must be an HTTP or HTTPS URL on the current site.' );
		}

		return [
			'url'      => $url,
			'strategy' => $strategy,
		];
	}

	private static function is_same_site_url( $url ) {
		$url_parts  = wp_parse_url( $url );
		$home_parts = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $url_parts ) || ! is_array( $home_parts ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $url_parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) ) {
			return false;
		}
		if ( isset( $url_parts['user'] ) || isset( $url_parts['pass'] ) || isset( $url_parts['fragment'] ) ) {
			return false;
		}
		if ( strtolower( $url_parts['host'] ?? '' ) !== strtolower( $home_parts['host'] ?? '' ) ) {
			return false;
		}

		return self::effective_port( $url_parts ) === self::effective_port( $home_parts );
	}

	private static function effective_port( $parts ) {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		return 'https' === strtolower( $parts['scheme'] ?? '' ) ? 443 : 80;
	}

	private static function check_route_permission( $path, $params = [], $minimum_version = '' ) {
		$route = self::find_route( $path, 'GET', $minimum_version );
		if ( is_wp_error( $route ) ) {
			return $route;
		}

		$request  = self::build_request( $path, $params, $route['url_params'] );
		$callback = $route['endpoint']['permission_callback'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return Webmastery_MCP_Response::local_error( 'site_kit_unsupported', 'The required Site Kit route has no usable permission check.' );
		}

		$allowed = call_user_func( $callback, $request );
		if ( is_wp_error( $allowed ) || false === $allowed || null === $allowed ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Google Site Kit does not permit the current user to access this data.' );
		}

		return true;
	}

	private static function dispatch_route( $path, $params = [], $minimum_version = '', $require_permission_callback = false ) {
		$route = self::find_route( $path, 'GET', $minimum_version );
		if ( is_wp_error( $route ) ) {
			return $route;
		}

		// REST dispatch skips absent permission callbacks; delegated reads must fail closed.
		if ( $require_permission_callback && ! is_callable( $route['endpoint']['permission_callback'] ?? null ) ) {
			return Webmastery_MCP_Response::local_error( 'site_kit_unsupported', 'The required Site Kit route has no usable permission check.' );
		}

		$request  = self::build_request( $path, $params, $route['url_params'] );
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return Webmastery_MCP_Response::local_error( 'site_kit_request_failed', 'Google Site Kit could not complete the request.' );
		}
		if ( $response->is_error() ) {
			$error  = $response->as_error();
			$status = (int) $response->get_status();
			$code   = in_array( $status, [ 401, 403 ], true ) ? 'forbidden' : 'site_kit_request_failed';
			$data   = [ 'status' => $status ];
			if ( is_wp_error( $error ) && $error->get_error_code() ) {
				$data['upstream_code'] = sanitize_key( $error->get_error_code() );
			}

			return Webmastery_MCP_Response::local_error( $code, 'Google Site Kit could not complete the request.', $data );
		}

		$data = $response->get_data();
		if ( is_object( $data ) ) {
			$encoded = wp_json_encode( $data );
			$data    = false !== $encoded ? json_decode( $encoded, true ) : null;
		}
		if ( ! is_array( $data ) ) {
			return Webmastery_MCP_Response::local_error( 'site_kit_invalid_response', 'Google Site Kit returned an unexpected response.' );
		}

		return $data;
	}

	private static function build_request( $path, $params, $url_params ) {
		$request = new WP_REST_Request( 'GET', $path );
		$request->set_query_params( $params );
		$request->set_url_params( $url_params );
		return $request;
	}

	private static function find_route( $path, $method, $minimum_version = '' ) {
		$plugin = self::plugin_status();
		if ( ! $plugin['active'] ) {
			return Webmastery_MCP_Response::local_error( 'site_kit_unavailable', 'Google Site Kit is not active.' );
		}
		if ( ! function_exists( 'rest_get_server' ) ) {
			return Webmastery_MCP_Response::local_error( 'site_kit_unavailable', 'The WordPress REST server is unavailable.' );
		}

		foreach ( rest_get_server()->get_routes() as $route_pattern => $endpoints ) {
			$matched = preg_match( '@^' . $route_pattern . '$@i', $path, $matches );
			if ( 1 !== $matched ) {
				continue;
			}

			$url_params = [];
			foreach ( $matches as $key => $value ) {
				if ( is_string( $key ) ) {
					$url_params[ $key ] = $value;
				}
			}

			foreach ( $endpoints as $endpoint ) {
				if ( self::endpoint_allows_method( $endpoint, $method ) ) {
					return [
						'endpoint'   => $endpoint,
						'url_params' => $url_params,
					];
				}
			}
		}

		$version = $plugin['version'];
		if ( $minimum_version && $version && version_compare( $version, $minimum_version, '<' ) ) {
			return Webmastery_MCP_Response::local_error(
				'site_kit_unsupported',
				"Google Site Kit {$minimum_version} or later is required.",
				[ 'detected_version' => $version, 'minimum_version' => $minimum_version ]
			);
		}

		return Webmastery_MCP_Response::local_error(
			'site_kit_unsupported',
			'The active Google Site Kit version does not expose a required compatibility route.',
			[ 'detected_version' => $version ]
		);
	}

	private static function endpoint_allows_method( $endpoint, $method ) {
		$methods = $endpoint['methods'] ?? [];
		if ( is_string( $methods ) ) {
			return in_array( $method, array_map( 'trim', explode( ',', strtoupper( $methods ) ) ), true );
		}
		if ( ! is_array( $methods ) ) {
			return false;
		}

		return ! empty( $methods[ $method ] ) || in_array( $method, $methods, true );
	}

	private static function normalize_modules( $modules ) {
		$items = [];
		foreach ( $modules as $module ) {
			if ( ! is_array( $module ) ) {
				continue;
			}

			$slug = sanitize_key( $module['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$items[] = [
				'slug'         => $slug,
				'name'         => sanitize_text_field( $module['name'] ?? $slug ),
				'available'    => true,
				'active'       => (bool) ( $module['active'] ?? false ),
				'connected'    => (bool) ( $module['connected'] ?? false ),
				'shareable'    => (bool) ( $module['shareable'] ?? false ),
				'dependencies' => self::normalize_slugs( $module['dependencies'] ?? [] ),
				'dependants'   => self::normalize_slugs( $module['dependants'] ?? [] ),
			];
		}

		return $items;
	}

	private static function normalize_slugs( $values ) {
		if ( ! is_array( $values ) ) {
			return [];
		}

		$slugs = array_filter( array_map( 'sanitize_key', $values ) );
		return array_values( array_unique( $slugs ) );
	}

	private static function normalize_permissions( $permissions ) {
		$base_map = [
			'googlesitekit_authenticate'                 => 'authenticate',
			'googlesitekit_setup'                        => 'setup',
			'googlesitekit_view_posts_insights'          => 'view_posts_insights',
			'googlesitekit_view_dashboard'               => 'view_dashboard',
			'googlesitekit_manage_options'               => 'manage_options',
			'googlesitekit_update_plugins'               => 'update_plugins',
			'googlesitekit_view_splash'                  => 'view_splash',
			'googlesitekit_view_authenticated_dashboard' => 'view_authenticated_dashboard',
			'googlesitekit_view_wp_dashboard_widget'     => 'view_wp_dashboard_widget',
			'googlesitekit_view_admin_bar_menu'          => 'view_admin_bar_menu',
			'googlesitekit_view_shared_dashboard'        => 'view_shared_dashboard',
		];
		$base     = array_fill_keys( array_values( $base_map ), false );
		$modules  = [];
		$meta_map = [
			'read_shared_module_data'              => 'read_shared_data',
			'manage_module_sharing_options'        => 'manage_sharing_options',
			'delegate_module_sharing_management'   => 'delegate_sharing_management',
		];

		foreach ( $permissions as $capability => $allowed ) {
			if ( isset( $base_map[ $capability ] ) ) {
				$base[ $base_map[ $capability ] ] = (bool) $allowed;
				continue;
			}

			if ( ! preg_match( '/^googlesitekit_([a-z_]+)::(.+)$/', $capability, $matches ) ) {
				continue;
			}
			if ( ! isset( $meta_map[ $matches[1] ] ) ) {
				continue;
			}

			$args = json_decode( $matches[2], true );
			$slug = sanitize_key( $args[0] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			if ( ! isset( $modules[ $slug ] ) ) {
				$modules[ $slug ] = [
					'read_shared_data'              => false,
					'manage_sharing_options'        => false,
					'delegate_sharing_management'   => false,
				];
			}
			$modules[ $slug ][ $meta_map[ $matches[1] ] ] = (bool) $allowed;
		}

		ksort( $modules );

		return [
			'permissions' => $base,
			'modules'     => $modules,
		];
	}

	private static function normalize_pagespeed( $result, $input ) {
		$lighthouse = is_array( $result['lighthouseResult'] ?? null ) ? $result['lighthouseResult'] : [];
		$categories = [];
		foreach ( [ 'performance', 'accessibility', 'best-practices', 'seo' ] as $category ) {
			$value = $lighthouse['categories'][ $category ] ?? null;
			if ( is_array( $value ) ) {
				$categories[ $category ] = [
					'score' => isset( $value['score'] ) ? (float) $value['score'] : null,
				];
			}
		}

		$audits = [];
		foreach ( [ 'first-contentful-paint', 'largest-contentful-paint', 'cumulative-layout-shift', 'total-blocking-time', 'speed-index', 'interaction-to-next-paint' ] as $audit ) {
			$value = $lighthouse['audits'][ $audit ] ?? null;
			if ( ! is_array( $value ) ) {
				continue;
			}

			$audits[ $audit ] = [
				'score'         => isset( $value['score'] ) ? (float) $value['score'] : null,
				'numeric_value' => isset( $value['numericValue'] ) ? (float) $value['numericValue'] : null,
				'numeric_unit'  => sanitize_key( $value['numericUnit'] ?? '' ),
				'display_value' => sanitize_text_field( $value['displayValue'] ?? '' ),
			];
		}

		return [
			'requested_url'     => $input['url'],
			'strategy'          => $input['strategy'],
			'id'                => esc_url_raw( $result['id'] ?? $input['url'] ),
			'analysis_timestamp' => sanitize_text_field( $result['analysisUTCTimestamp'] ?? '' ),
			'field_data'        => self::normalize_field_data( $result['loadingExperience'] ?? [] ),
			'categories'        => $categories,
			'audits'            => $audits,
		];
	}

	private static function normalize_field_data( $experience ) {
		if ( ! is_array( $experience ) ) {
			return [
				'overall_category' => null,
				'metrics'          => [],
			];
		}

		$metrics = [];
		foreach ( [ 'LARGEST_CONTENTFUL_PAINT_MS', 'FIRST_CONTENTFUL_PAINT_MS', 'CUMULATIVE_LAYOUT_SHIFT_SCORE', 'INTERACTION_TO_NEXT_PAINT', 'EXPERIMENTAL_TIME_TO_FIRST_BYTE' ] as $metric ) {
			$value = $experience['metrics'][ $metric ] ?? null;
			if ( ! is_array( $value ) ) {
				continue;
			}
			$metrics[ $metric ] = [
				'percentile' => isset( $value['percentile'] ) ? (float) $value['percentile'] : null,
				'category'   => sanitize_key( $value['category'] ?? '' ),
			];
		}

		return [
			'overall_category' => sanitize_key( $experience['overall_category'] ?? '' ),
			'metrics'          => $metrics,
		];
	}
}
