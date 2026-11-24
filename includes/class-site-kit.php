<?php

defined( 'ABSPATH' ) || exit;

/**
 * Google Site Kit status, modules, permissions and PageSpeed abilities.
 *
 * @phpstan-type AbilityMeta array{annotations: array{readonly: bool, destructive: bool, idempotent: bool}, mcp: array{public: bool, type: string}}
 * @phpstan-type PluginStatus array{installed: bool, active: bool, version: string|null}
 * @phpstan-type SiteKitRoute array{endpoint: array<string, mixed>, url_params: array<string, string>}
 * @phpstan-type ModuleRecord array{slug: string, name: string, available: true, active: bool, connected: bool, shareable: bool, dependencies: list<string>, dependants: list<string>}
 * @phpstan-type PermissionsRecord array{permissions: array<string, bool>, modules: array<string, array{read_shared_data: bool, manage_sharing_options: bool, delegate_sharing_management: bool}>}
 * @phpstan-type PagespeedInput array{url: string, strategy: 'mobile'|'desktop'}
 * @phpstan-type FieldData array{overall_category: string|null, metrics: array<string, array{percentile: float|null, category: string}>}
 * @phpstan-type PagespeedRecord array{requested_url: string, strategy: string, id: string, analysis_timestamp: string, field_data: FieldData, categories: array<string, array{score: float|null}>, audits: array<string, array{score: float|null, numeric_value: float|null, numeric_unit: string, display_value: string}>}
 */
class Webmastery_MCP_Site_Kit {

	private const REST_ROOT = '/google-site-kit/v1';

	private const PLUGIN_FILE = 'google-site-kit/google-site-kit.php';

	private const PERMISSIONS_MINIMUM_VERSION = '1.82.0';

	// Fixed guidance: a denial alone does not establish this user's authentication or sharing state.
	private const FORBIDDEN_GUIDANCE = 'Google Site Kit denied this request. Possible causes include missing Dashboard sharing access or a Google account sign-in/reauthentication requirement. An authorized administrator can review access for this user\'s role to the relevant service using the sharing icon in the Site Kit dashboard header. Eligible users can sign in to Google in Site Kit as this WordPress user. For diagnosis, use get-site-kit-status (requires manage_options) and get-site-kit-permissions where permitted.';

	/**
	 * Register the Google Site Kit abilities.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_status();
		self::register_modules();
		self::register_permissions();
		self::register_pagespeed();
	}

	/**
	 * Register the get-site-kit-status ability.
	 *
	 * @return void
	 */
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

	/**
	 * Register the list-site-kit-modules ability.
	 *
	 * @return void
	 */
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

	/**
	 * Register the get-site-kit-permissions ability.
	 *
	 * @return void
	 */
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

	/**
	 * Register the get-site-kit-pagespeed ability.
	 *
	 * @return void
	 */
	private static function register_pagespeed() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed', [
			'label'               => 'Google Site Kit: PageSpeed',
			'description'         => 'Fetch a safe PageSpeed Insights summary through Google Site Kit for a URL on the current site.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'default'    => [],
				'properties' => [
					'url'      => [
						'type'        => 'string',
						'format'      => 'uri',
						'description' => 'Same-site URL to analyze. Defaults to the site home URL.',
					],
					'strategy' => [
						'type'        => 'string',
						'enum'        => [ 'mobile', 'desktop' ],
						'default'     => 'mobile',
						'description' => 'PageSpeed device strategy. Defaults to mobile.',
					],
				],
			],
			'execute_callback'    => [ self::class, 'execute_pagespeed' ],
			'permission_callback' => [ self::class, 'permission_pagespeed' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	/**
	 * Ability meta shared by the read-only, idempotent Site Kit abilities.
	 *
	 * @return AbilityMeta
	 */
	private static function readonly_meta() {
		return [
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			'mcp'         => [ 'public' => true, 'type' => 'tool' ],
		];
	}

	/**
	 * Permission callback for get-site-kit-status.
	 *
	 * @return true|\WP_Error True when allowed, or an error describing why not.
	 */
	public static function permission_status() {
		$permission = Webmastery_MCP_Permissions::check( 'manage_options' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$plugin = self::plugin_status();
		if ( ! $plugin['active'] ) {
			return true;
		}

		return self::check_route_permission( self::REST_ROOT . '/core/site/data/connection' );
	}

	/**
	 * Permission callback for list-site-kit-modules.
	 *
	 * @return true|\WP_Error True when allowed, or an error describing why not.
	 */
	public static function permission_modules() {
		$permission = Webmastery_MCP_Permissions::check( 'read' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		return self::check_route_permission( self::REST_ROOT . '/core/modules/data/list' );
	}

	/**
	 * Permission callback for get-site-kit-permissions.
	 *
	 * @return true|\WP_Error True when allowed, or an error describing why not.
	 */
	public static function permission_permissions() {
		$permission = Webmastery_MCP_Permissions::check( 'read' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		return self::check_route_permission(
			self::REST_ROOT . '/core/user/data/permissions',
			[],
			self::PERMISSIONS_MINIMUM_VERSION
		);
	}

	/**
	 * Permission callback for get-site-kit-pagespeed.
	 *
	 * @param array<string, mixed> $input Ability input: optional url and strategy.
	 * @return true|\WP_Error True when allowed, or an error describing why not.
	 */
	public static function permission_pagespeed( $input = [] ) {
		$permission = Webmastery_MCP_Permissions::check( 'read' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$validated = self::normalize_pagespeed_input( $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return self::check_route_permission(
			self::REST_ROOT . '/modules/pagespeed-insights/data/pagespeed',
			$validated
		);
	}

	/**
	 * Report Site Kit availability, connection and authentication status.
	 *
	 * @return array{success: true, data: array{plugin: PluginStatus, connection: array{connected: bool, setup_completed: bool}|null, authentication: array{authenticated: bool, needs_reauthentication: bool}|null, compatibility: array{status: string, note: string}}}|\WP_Error
	 */
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

	/**
	 * List Site Kit modules with their safe states.
	 *
	 * @return array{success: true, data: array{items: list<ModuleRecord>, total: int}}|\WP_Error
	 */
	public static function execute_modules() {
		$permission = Webmastery_MCP_Permissions::check( 'read' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
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

	/**
	 * Return the current user's Site Kit permission matrix.
	 *
	 * @return array{success: true, data: PermissionsRecord}|\WP_Error
	 */
	public static function execute_permissions() {
		$permission = Webmastery_MCP_Permissions::check( 'read' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
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

	/**
	 * Fetch a PageSpeed Insights summary through Site Kit.
	 *
	 * @param array<string, mixed> $input Ability input: optional url and strategy.
	 * @return array{success: true, data: PagespeedRecord}|\WP_Error
	 */
	public static function execute_pagespeed( $input = [] ) {
		$permission = Webmastery_MCP_Permissions::check( 'read' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
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

	/**
	 * Installation, activation and version state of Google Site Kit.
	 *
	 * @return PluginStatus
	 */
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

	/**
	 * Apply defaults to the PageSpeed input and validate the strategy.
	 *
	 * @param array<string, mixed> $input Ability input: optional url and strategy.
	 * @return PagespeedInput|\WP_Error
	 */
	private static function normalize_pagespeed_input( $input ) {
		$strategy = array_key_exists( 'strategy', $input ) ? $input['strategy'] : 'mobile';
		if ( ! in_array( $strategy, [ 'mobile', 'desktop' ], true ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_strategy', 'Strategy must be mobile or desktop.' );
		}

		return [
			'url'      => empty( $input['url'] ) ? home_url( '/' ) : esc_url_raw( $input['url'] ),
			'strategy' => $strategy,
		];
	}

	/**
	 * Normalize the PageSpeed input and require a same-site URL.
	 *
	 * @param array<string, mixed> $input Ability input: optional url and strategy.
	 * @return PagespeedInput|\WP_Error
	 */
	private static function validate_pagespeed_input( $input ) {
		$validated = self::normalize_pagespeed_input( $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		// Keep domain validation in execution so core does not mask invalid_url as a permission denial.
		if ( '' === $validated['url'] || ! self::is_same_site_url( $validated['url'] ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'PageSpeed URL must be an HTTP or HTTPS URL on the current site.' );
		}
		return $validated;
	}

	/**
	 * Whether a URL is an HTTP(S) URL on the current site without credentials or a fragment.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
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

	/**
	 * Port of a parsed URL, defaulting from its scheme.
	 *
	 * @param array<string, string|int> $parts Parsed URL, as returned by wp_parse_url().
	 * @return int
	 */
	private static function effective_port( $parts ) {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		return 'https' === strtolower( $parts['scheme'] ?? '' ) ? 443 : 80;
	}

	/**
	 * Run the permission callback of a Site Kit REST route for the current user.
	 *
	 * @param string               $path            REST route path.
	 * @param array<string, mixed> $params          Query parameters for the request.
	 * @param string               $minimum_version Site Kit version the route needs; empty for any.
	 * @return true|\WP_Error True when allowed, or an error describing why not.
	 */
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
			return Webmastery_MCP_Response::local_error( 'forbidden', self::FORBIDDEN_GUIDANCE );
		}

		return true;
	}

	/**
	 * Dispatch a GET request to a Site Kit REST route and return its decoded data.
	 *
	 * @param string               $path                        REST route path.
	 * @param array<string, mixed> $params                      Query parameters for the request.
	 * @param string               $minimum_version             Site Kit version the route needs; empty for any.
	 * @param bool                 $require_permission_callback Whether the route must define a permission callback.
	 * @return array<mixed>|\WP_Error Decoded response data, or an error.
	 */
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

			$message = 'forbidden' === $code ? self::FORBIDDEN_GUIDANCE : 'Google Site Kit could not complete the request.';
			return Webmastery_MCP_Response::local_error( $code, $message, $data );
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

	/**
	 * Build a GET REST request for a matched route.
	 *
	 * @param string                $path       REST route path.
	 * @param array<string, mixed>  $params     Query parameters for the request.
	 * @param array<string, string> $url_params URL parameters captured from the route pattern.
	 * @return \WP_REST_Request
	 */
	private static function build_request( $path, $params, $url_params ) {
		$request = new WP_REST_Request( 'GET', $path );
		$request->set_query_params( $params );
		$request->set_url_params( $url_params );
		return $request;
	}

	/**
	 * Find the registered REST route and endpoint matching a path and method.
	 *
	 * @param string $path            REST route path.
	 * @param string $method          HTTP method.
	 * @param string $minimum_version Site Kit version the route needs; empty for any.
	 * @return SiteKitRoute|\WP_Error The matched route, or an error when Site Kit is inactive or lacks the route.
	 */
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

	/**
	 * Whether a REST endpoint definition allows an HTTP method.
	 *
	 * @param array<string, mixed> $endpoint REST endpoint definition.
	 * @param string               $method   HTTP method.
	 * @return bool
	 */
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

	/**
	 * Reduce the Site Kit module list to safe module records.
	 *
	 * @param array<mixed> $modules Decoded module list from Site Kit.
	 * @return list<ModuleRecord>
	 */
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

	/**
	 * Sanitize a list of module slugs, dropping empty and duplicate entries.
	 *
	 * @param mixed $values Raw slug list; anything but an array yields an empty list.
	 * @return list<string>
	 */
	private static function normalize_slugs( $values ) {
		if ( ! is_array( $values ) ) {
			return [];
		}

		$slugs = array_filter( array_map( 'sanitize_key', $values ) );
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Group Site Kit capability results into base and per-module permissions.
	 *
	 * @param array<mixed> $permissions Capability results keyed by capability name.
	 * @return PermissionsRecord
	 */
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

	/**
	 * Reduce a PageSpeed Insights result to a safe summary.
	 *
	 * @param array<mixed>   $result Decoded PageSpeed response from Site Kit.
	 * @param PagespeedInput $input  Validated PageSpeed input.
	 * @return PagespeedRecord
	 */
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

	/**
	 * Reduce PageSpeed field data to a safe summary.
	 *
	 * @param mixed $experience Raw loadingExperience data; anything but an array yields an empty summary.
	 * @return FieldData
	 */
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
