<?php

defined( 'ABSPATH' ) || exit;

/**
 * Plugin listing, audit, activation and deactivation abilities.
 *
 * @phpstan-type PluginRecord array{plugin: string, name: string, version: string, active: bool, network_active: bool, update_available: bool}
 * @phpstan-type PluginAuditRecord array{plugin: string, name: string, slug: string, version: string, active: bool, update_available: bool, new_version: string|null, tested_up_to: string|null, requires_wp: string|null, abandoned: bool, days_since_update: int|null, critical_update: bool}
 * @phpstan-type PluginCriticalUpdate array{plugin: string, name: string, slug: string, version: string, new_version: string|null}
 * @phpstan-type PluginDeprecation array{field: string, replacement: string, removal: string, reason: string}
 */
class Webmastery_MCP_Plugins {

	/**
	 * Basenames of the active plugins, including network-activated ones on multisite.
	 *
	 * @return list<string>
	 */
	public static function active_basenames() {
		$active_plugins = get_option( 'active_plugins', [] );
		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = [];
		}

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', [] );
			if ( is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		return array_values( array_unique( array_map( 'strval', $active_plugins ) ) );
	}

	/**
	 * Register the plugin management abilities.
	 *
	 * @return void
	 */
	public static function register() {
		self::load_plugin_api();
		self::register_list();
		self::register_audit();
		self::register_activate();
		self::register_deactivate();
	}

	/**
	 * Permission callback shared by the plugin abilities.
	 *
	 * @param array<string, mixed> $input Ability input; network_wide is checked when set.
	 * @return true|\WP_Error True when allowed, or an error describing the missing capability or context.
	 */
	public static function permission( $input = [] ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return Webmastery_MCP_Response::legacy_wp_error(
				'missing_capability',
				'Requires activate_plugins capability.',
				[ 'capability' => 'activate_plugins' ],
				403
			);
		}

		if ( ! empty( $input['network_wide'] ) ) {
			if ( ! is_multisite() ) {
				return Webmastery_MCP_Response::legacy_wp_error(
					'invalid_context',
					'Network-wide plugin management is only available on multisite installations.',
					[ 'network_wide' => true ],
					400
				);
			}

			if ( ! current_user_can( 'manage_network_plugins' ) ) {
				return Webmastery_MCP_Response::legacy_wp_error(
					'network_admin_required',
					'Network-wide plugin management requires manage_network_plugins capability.',
					[ 'capability' => 'manage_network_plugins' ],
					403
				);
			}
		}

		return true;
	}

	/**
	 * List installed plugins and their activation state.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{success: true, data: array{items: list<PluginRecord>}}|\WP_Error
	 */
	public static function list_plugins( $input = [] ) {
		$permission = self::permission( $input );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$plugins = self::get_plugins();
		$items   = [];

		foreach ( $plugins as $plugin => $plugin_data ) {
			$items[] = self::normalize_plugin( $plugin, $plugin_data );
		}

		return [
			'success' => true,
			'data'    => [
				'items' => $items,
			],
		];
	}

	/**
	 * Audit installed plugins for inactive, outdated and potentially abandoned entries.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{success: true, data: array{inactive_count: int, updates_available_count: int, potentially_abandoned_count: int, critical_updates: list<PluginCriticalUpdate>, deprecations: list<PluginDeprecation>, items: list<PluginAuditRecord>}}|\WP_Error
	 */
	public static function plugin_audit( $input = [] ) {
		$permission = self::permission( $input );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$plugins        = self::get_plugins();
		$updates        = self::get_update_plugins_transient();
		$items          = [];
		$critical_items = [];

		foreach ( $plugins as $plugin => $plugin_data ) {
			$item    = self::normalize_plugin_audit( $plugin, $plugin_data, $updates );
			$items[] = $item;

			if ( $item['critical_update'] ) {
				$critical_items[] = [
					'plugin'      => $item['plugin'],
					'name'        => $item['name'],
					'slug'        => $item['slug'],
					'version'     => $item['version'],
					'new_version' => $item['new_version'],
				];
			}
		}

		return [
			'success' => true,
			'data'    => [
				'inactive_count'                => count(
					array_filter(
						$items,
						static function ( $item ) {
							return ! $item['active'];
						}
					)
				),
				'updates_available_count'       => count(
					array_filter(
						$items,
						static function ( $item ) {
							return $item['update_available'];
						}
					)
				),
				'potentially_abandoned_count'   => count(
					array_filter(
						$items,
						static function ( $item ) {
							return $item['abandoned'];
						}
					)
				),
				'critical_updates'              => $critical_items,
				'deprecations'                  => self::plugin_audit_deprecations(),
				'items'                         => $items,
			],
		];
	}

	/**
	 * Fields of the plugin audit response that are deprecated, for the response's `deprecations` list.
	 *
	 * @return list<PluginDeprecation>
	 */
	private static function plugin_audit_deprecations() {
		$reason = 'WordPress.org update data does not identify security releases, so this is false or empty unless a third-party update source sets a security flag. Do not read it as "no security updates pending".';

		return [
			[
				'field'       => 'critical_updates',
				'replacement' => 'updates_available_count and items[].update_available',
				'removal'     => '4.0.0',
				'reason'      => $reason,
			],
			[
				'field'       => 'items[].critical_update',
				'replacement' => 'items[].update_available',
				'removal'     => '4.0.0',
				'reason'      => $reason,
			],
		];
	}

	/**
	 * Activate a plugin resolved from the input.
	 *
	 * @param array<string, mixed> $input Ability input: plugin or slug, and optionally network_wide.
	 * @return array{success: true, data: PluginRecord}|\WP_Error
	 */
	public static function activate_plugin( $input = [] ) {
		$permission = self::permission( $input );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$plugin = self::resolve_plugin_basename( $input );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		$plugins      = self::get_plugins();
		$network_wide = ! empty( $input['network_wide'] );

		if ( ( $network_wide && is_plugin_active_for_network( $plugin ) ) || ( ! $network_wide && is_plugin_active( $plugin ) ) ) {
			return [
				'success' => true,
				'data'    => self::normalize_plugin( $plugin, $plugins[ $plugin ] ),
			];
		}

		$result = activate_plugin( $plugin, '', $network_wide, false );

		if ( is_wp_error( $result ) ) {
			return Webmastery_MCP_Response::legacy_wp_error(
				'dependency_failure',
				'The plugin could not be activated because a dependency or activation check failed.',
				[
					'plugin'              => $plugin,
					'network_wide'        => $network_wide,
				],
				409
			);
		}

		return [
			'success' => true,
			'data'    => self::normalize_plugin( $plugin, $plugins[ $plugin ] ),
		];
	}

	/**
	 * Deactivate a plugin resolved from the input.
	 *
	 * @param array<string, mixed> $input Ability input: plugin or slug, and optionally force and network_wide.
	 * @return array{success: true, data: PluginRecord}|\WP_Error
	 */
	public static function deactivate_plugin( $input = [] ) {
		$permission = self::permission( $input );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$plugin = self::resolve_plugin_basename( $input );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		if ( self::is_protected_plugin( $plugin ) && empty( $input['force'] ) ) {
			return Webmastery_MCP_Response::legacy_wp_error(
				'precondition_failed',
				'Deactivation is blocked for this protected plugin unless force is true.',
				[
					'plugin'         => $plugin,
					'force_required' => true,
				],
				409
			);
		}

		$plugins      = self::get_plugins();
		$network_wide = ! empty( $input['network_wide'] );

		if ( is_multisite() && ! $network_wide && is_plugin_active_for_network( $plugin ) ) {
			return Webmastery_MCP_Response::legacy_wp_error(
				'network_context_required',
				'This plugin is network active. Deactivate it with network_wide=true from a network admin context.',
				[ 'plugin' => $plugin ],
				409
			);
		}

		$is_active = $network_wide ? is_plugin_active_for_network( $plugin ) : is_plugin_active( $plugin );

		if ( ! $is_active ) {
			return [
				'success' => true,
				'data'    => self::normalize_plugin( $plugin, $plugins[ $plugin ] ),
			];
		}

		deactivate_plugins( $plugin, false, $network_wide );

		if ( $network_wide ? is_plugin_active_for_network( $plugin ) : is_plugin_active( $plugin ) ) {
			return Webmastery_MCP_Response::legacy_wp_error(
				'precondition_failed',
				'Plugin could not be deactivated.',
				[
					'plugin'       => $plugin,
					'network_wide' => $network_wide,
				],
				409
			);
		}

		return [
			'success' => true,
			'data'    => self::normalize_plugin( $plugin, $plugins[ $plugin ] ),
		];
	}

	/**
	 * Register the list-plugins ability.
	 *
	 * @return void
	 */
	private static function register_list() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-plugins', [
			'label'               => 'List Plugins',
			'description'         => 'List installed WordPress plugins and their activation state.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'list_plugins' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	/**
	 * Register the plugin-audit ability.
	 *
	 * @return void
	 */
	private static function register_audit() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/plugin-audit', [
			'label'               => 'Plugin Audit',
			'description'         => 'Audit installed WordPress plugins for inactive plugins, cached update availability, compatibility metadata, and potentially abandoned plugins.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'plugin_audit' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	/**
	 * Register the activate-plugin ability.
	 *
	 * @return void
	 */
	private static function register_activate() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/activate-plugin', [
			'label'               => 'Activate Plugin',
			'description'         => 'Activate a WordPress plugin by canonical plugin basename.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'plugin'       => [ 'type' => 'string', 'description' => 'Canonical plugin basename, for example akismet/akismet.php.' ],
					'slug'         => [ 'type' => 'string', 'description' => 'Optional plugin slug when it resolves to exactly one installed plugin.' ],
					'network_wide' => [ 'type' => 'boolean', 'default' => false ],
				],
			],
			'execute_callback'    => [ self::class, 'activate_plugin' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	/**
	 * Register the deactivate-plugin ability.
	 *
	 * @return void
	 */
	private static function register_deactivate() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/deactivate-plugin', [
			'label'               => 'Deactivate Plugin',
			'description'         => 'Deactivate a WordPress plugin by canonical plugin basename.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'plugin'       => [ 'type' => 'string', 'description' => 'Canonical plugin basename, for example akismet/akismet.php.' ],
					'slug'         => [ 'type' => 'string', 'description' => 'Optional plugin slug when it resolves to exactly one installed plugin.' ],
					'force'        => [ 'type' => 'boolean', 'default' => false, 'description' => 'Allow deactivation of protected plugins when the caller is permitted to manage plugins.' ],
					'network_wide' => [ 'type' => 'boolean', 'default' => false ],
				],
			],
			'execute_callback'    => [ self::class, 'deactivate_plugin' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	/**
	 * Load the WordPress plugin API functions.
	 *
	 * @return void
	 */
	private static function load_plugin_api() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	/**
	 * Installed plugins sorted by basename.
	 *
	 * @return array<string, array<string, mixed>> Plugin header data keyed by plugin basename.
	 */
	private static function get_plugins() {
		$plugins = get_plugins();
		ksort( $plugins );

		return $plugins;
	}

	/**
	 * Resolve the plugin basename from the plugin or slug input.
	 *
	 * @param array<string, mixed> $input Ability input: plugin or slug.
	 * @return string|\WP_Error Plugin basename, or an error when none or several match.
	 */
	private static function resolve_plugin_basename( $input ) {
		$plugins = self::get_plugins();

		if ( ! empty( $input['plugin'] ) ) {
			$identifier = trim( wp_normalize_path( sanitize_text_field( $input['plugin'] ) ) );
			$plugin     = plugin_basename( $identifier );

			if ( isset( $plugins[ $identifier ] ) ) {
				return $identifier;
			}

			if ( isset( $plugins[ $plugin ] ) ) {
				return $plugin;
			}

			return Webmastery_MCP_Response::legacy_wp_error(
				'plugin_not_found',
				'No installed plugin matches the provided plugin basename.',
				[ 'plugin' => $identifier ],
				404
			);
		}

		if ( ! empty( $input['slug'] ) ) {
			$slug    = sanitize_key( $input['slug'] );
			$matches = [];

			foreach ( array_keys( $plugins ) as $plugin ) {
				if ( self::plugin_matches_slug( $plugin, $slug ) ) {
					$matches[] = $plugin;
				}
			}

			if ( 1 === count( $matches ) ) {
				return $matches[0];
			}

			if ( $matches ) {
				return Webmastery_MCP_Response::legacy_wp_error(
					'invalid_identifier',
					'Plugin slug is ambiguous. Use the canonical plugin basename instead.',
					[
						'slug'    => $slug,
						'matches' => $matches,
					],
					400
				);
			}

			return Webmastery_MCP_Response::legacy_wp_error(
				'plugin_not_found',
				'No installed plugin matches the provided plugin slug.',
				[ 'slug' => $slug ],
				404
			);
		}

		return Webmastery_MCP_Response::legacy_wp_error(
			'invalid_identifier',
			'Provide a plugin basename in plugin or an unambiguous slug in slug.',
			[ 'accepted_fields' => [ 'plugin', 'slug' ] ],
			400
		);
	}

	/**
	 * Whether a plugin basename matches a slug by directory or file name.
	 *
	 * @param string $plugin Plugin basename.
	 * @param string $slug   Sanitized plugin slug.
	 * @return bool
	 */
	private static function plugin_matches_slug( $plugin, $slug ) {
		$parts    = explode( '/', $plugin );
		$filename = basename( $plugin, '.php' );

		return $slug === $filename || ( count( $parts ) > 1 && $slug === $parts[0] );
	}

	/**
	 * Build the list record for one installed plugin.
	 *
	 * @param string               $plugin      Plugin basename.
	 * @param array<string, mixed> $plugin_data Plugin header data.
	 * @return PluginRecord
	 */
	private static function normalize_plugin( $plugin, $plugin_data ) {
		$updates        = self::get_update_plugins_transient();
		$network_active = is_multisite() && is_plugin_active_for_network( $plugin );

		return [
			'plugin'           => $plugin,
			'name'             => $plugin_data['Name'] ?? $plugin,
			'version'          => $plugin_data['Version'] ?? '',
			'active'           => is_plugin_active( $plugin ),
			'network_active'   => $network_active,
			'update_available' => isset( $updates->response[ $plugin ] ),
		];
	}

	/**
	 * Build the audit record for one installed plugin.
	 *
	 * @param string               $plugin      Plugin basename.
	 * @param array<string, mixed> $plugin_data Plugin header data.
	 * @param object               $updates     Update data from get_update_plugins_transient().
	 * @return PluginAuditRecord
	 */
	private static function normalize_plugin_audit( $plugin, $plugin_data, $updates ) {
		$update          = $updates->response[ $plugin ] ?? null;
		$no_update       = self::get_no_update_record( $updates, $plugin );
		$tested_up_to    = self::get_plugin_tested_up_to( $plugin, $update, $no_update );
		$requires_wp     = self::get_plugin_requires_wp( $plugin_data, $update, $no_update );
		$critical_update = self::is_security_update( $update );

		return [
			'plugin'            => $plugin,
			'name'              => $plugin_data['Name'] ?? $plugin,
			'slug'              => self::get_plugin_slug( $plugin, $update, $no_update ),
			'version'           => $plugin_data['Version'] ?? '',
			'active'            => is_plugin_active( $plugin ),
			'update_available'  => null !== $update,
			'new_version'       => null !== $update && isset( $update->new_version ) ? (string) $update->new_version : null,
			'tested_up_to'      => $tested_up_to,
			'requires_wp'       => $requires_wp,
			'abandoned'         => self::is_potentially_abandoned( $tested_up_to ),
			'days_since_update' => self::get_days_since_update( $plugin ),
			'critical_update'   => $critical_update,
		];
	}

	/**
	 * Cached plugin update data, with an empty response list when none is stored.
	 *
	 * @return object Update data whose response property is an array.
	 */
	private static function get_update_plugins_transient() {
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) ) {
			$updates = (object) [];
		}

		if ( ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
			$updates->response = [];
		}

		return $updates;
	}

	/**
	 * Cached no-update record for a plugin.
	 *
	 * @param object $updates Update data from get_update_plugins_transient().
	 * @param string $plugin  Plugin basename.
	 * @return object|null
	 */
	private static function get_no_update_record( $updates, $plugin ) {
		if ( ! isset( $updates->no_update ) || ! is_array( $updates->no_update ) || ! isset( $updates->no_update[ $plugin ] ) ) {
			return null;
		}

		$record = $updates->no_update[ $plugin ];
		if ( is_array( $record ) ) {
			$record = (object) $record;
		}

		return is_object( $record ) ? $record : null;
	}

	/**
	 * Slug of a plugin, from its update records or its basename.
	 *
	 * @param string      $plugin    Plugin basename.
	 * @param object|null $update    Cached update record.
	 * @param object|null $no_update Cached no-update record.
	 * @return string
	 */
	private static function get_plugin_slug( $plugin, $update = null, $no_update = null ) {
		foreach ( [ $update, $no_update ] as $record ) {
			if ( is_object( $record ) && ! empty( $record->slug ) && is_scalar( $record->slug ) ) {
				return sanitize_key( (string) $record->slug );
			}
		}

		$parts = explode( '/', $plugin );
		if ( count( $parts ) > 1 ) {
			return sanitize_key( $parts[0] );
		}

		return sanitize_key( basename( $plugin, '.php' ) );
	}

	/**
	 * Highest WordPress version a plugin declares compatibility with.
	 *
	 * @param string      $plugin    Plugin basename.
	 * @param object|null $update    Cached update record.
	 * @param object|null $no_update Cached no-update record.
	 * @return string|null Version string, or null when none is declared.
	 */
	private static function get_plugin_tested_up_to( $plugin, $update = null, $no_update = null ) {
		foreach ( [ $update, $no_update ] as $record ) {
			if ( ! is_object( $record ) ) {
				continue;
			}

			foreach ( [ 'tested', 'tested_up_to' ] as $property ) {
				$version = isset( $record->{$property} ) ? self::sanitize_version_string( $record->{$property} ) : null;
				if ( null !== $version ) {
					return $version;
				}
			}
		}

		$version = self::sanitize_version_string( self::get_readme_tested_up_to( $plugin ) );
		if ( null !== $version ) {
			return $version;
		}

		$headers = self::get_extra_plugin_headers( $plugin );
		return self::sanitize_version_string( $headers['TestedUpTo'] );
	}

	/**
	 * Accept only short dotted numeric version strings.
	 *
	 * @param mixed $value Raw version value.
	 * @return string|null
	 */
	private static function sanitize_version_string( $value ) {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		if ( strlen( $value ) > 20 || 1 !== preg_match( '/^\d+(?:\.\d+){0,3}$/D', $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Tested up to version from a plugin readme file.
	 *
	 * @param string $plugin Plugin basename.
	 * @return string|null
	 */
	private static function get_readme_tested_up_to( $plugin ) {
		$directory = dirname( (string) $plugin );
		if ( '.' === $directory || '' === $directory || '/' === $directory || '\\' === $directory ) {
			return null;
		}

		$plugins_root = realpath( WP_PLUGIN_DIR );
		if ( false === $plugins_root ) {
			return null;
		}

		$plugin_root = realpath( $plugins_root . '/' . $directory );
		if ( false === $plugin_root || ! self::is_path_inside( $plugin_root, $plugins_root ) ) {
			return null;
		}

		foreach ( [ 'readme.txt', 'README.txt', 'Readme.txt', 'README.TXT' ] as $filename ) {
			$readme = realpath( $plugin_root . '/' . $filename );
			if ( false === $readme || ! is_file( $readme ) || ! self::is_path_inside( $readme, $plugin_root ) ) {
				continue;
			}

			// get_file_data() reads only the first 8 KB of the file.
			$headers = get_file_data( $readme, [ 'TestedUpTo' => 'Tested up to' ] );
			return isset( $headers['TestedUpTo'] ) && '' !== $headers['TestedUpTo'] ? (string) $headers['TestedUpTo'] : null;
		}

		return null;
	}

	/**
	 * Whether a path lies inside a root directory.
	 *
	 * @param string $path Path to check.
	 * @param string $root Root directory.
	 * @return bool
	 */
	private static function is_path_inside( $path, $root ) {
		$root = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;
		return 0 === strpos( $path, $root );
	}

	/**
	 * Minimum WordPress version a plugin requires.
	 *
	 * @param array<string, mixed> $plugin_data Plugin header data.
	 * @param object|null          $update      Cached update record.
	 * @param object|null          $no_update   Cached no-update record.
	 * @return string|null
	 */
	private static function get_plugin_requires_wp( $plugin_data, $update = null, $no_update = null ) {
		foreach ( [ $update, $no_update ] as $record ) {
			if ( is_object( $record ) && ! empty( $record->requires ) && is_scalar( $record->requires ) ) {
				return sanitize_text_field( (string) $record->requires );
			}
		}

		if ( ! empty( $plugin_data['RequiresWP'] ) ) {
			return sanitize_text_field( (string) $plugin_data['RequiresWP'] );
		}

		return null;
	}

	/**
	 * Read extra headers from a plugin file.
	 *
	 * @param string $plugin Plugin basename.
	 * @return array{TestedUpTo: string|null}
	 */
	private static function get_extra_plugin_headers( $plugin ) {
		$path = self::plugin_file_path( $plugin );
		if ( ! file_exists( $path ) ) {
			return [
				'TestedUpTo' => null,
			];
		}

		$headers = get_file_data(
			$path,
			[
				'TestedUpTo' => 'Tested up to',
			],
			'plugin'
		);

		return [
			'TestedUpTo' => isset( $headers['TestedUpTo'] ) ? sanitize_text_field( $headers['TestedUpTo'] ) : null,
		];
	}

	/**
	 * Absolute path of a plugin file.
	 *
	 * @param string $plugin Plugin basename.
	 * @return string
	 */
	private static function plugin_file_path( $plugin ) {
		return WP_PLUGIN_DIR . '/' . $plugin;
	}

	/**
	 * Whether the tested-up-to version is two or more WordPress releases behind.
	 *
	 * @param string|null $tested_up_to Tested up to version.
	 * @return bool
	 */
	private static function is_potentially_abandoned( $tested_up_to ) {
		if ( empty( $tested_up_to ) ) {
			return false;
		}

		$current = self::major_minor_version_index( get_bloginfo( 'version' ) );
		$tested  = self::major_minor_version_index( $tested_up_to );

		if ( null === $current || null === $tested ) {
			return false;
		}

		return ( $current - $tested ) >= 2;
	}

	/**
	 * Numeric index of a version string, as major times ten plus minor.
	 *
	 * @param string $version Version string.
	 * @return int|null
	 */
	private static function major_minor_version_index( $version ) {
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?/', (string) $version, $matches ) ) {
			return null;
		}

		$major = absint( $matches[1] );
		$minor = isset( $matches[2] ) ? absint( $matches[2] ) : 0;

		return ( $major * 10 ) + $minor;
	}

	/**
	 * Days since a plugin file was last modified.
	 *
	 * @param string $plugin Plugin basename.
	 * @return int|null
	 */
	private static function get_days_since_update( $plugin ) {
		$path = self::plugin_file_path( $plugin );
		if ( ! file_exists( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime -- Read-only audit of an installed plugin's local file timestamp.
		$modified = filemtime( $path );
		if ( false === $modified ) {
			return null;
		}

		return max( 0, (int) floor( ( time() - $modified ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Whether a cached plugin update record carries a security flag.
	 *
	 * @deprecated 3.2.0 Scheduled for removal in 4.0.0 together with the `critical_update` and `critical_updates` audit fields. WordPress.org update data does not provide these properties.
	 *
	 * @param mixed $update Cached update record.
	 * @return bool
	 */
	private static function is_security_update( $update ) {
		if ( ! is_object( $update ) ) {
			return false;
		}

		foreach ( [ 'security', 'security_update', 'is_security_update' ] as $property ) {
			if ( ! empty( $update->{$property} ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a plugin is protected from deactivation without force.
	 *
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private static function is_protected_plugin( $plugin ) {
		return in_array( $plugin, self::protected_plugins(), true );
	}

	/**
	 * Basenames of the protected plugins.
	 *
	 * @return list<string>
	 */
	private static function protected_plugins() {
		return [
			plugin_basename( dirname( __DIR__ ) . '/webmastery-site-toolkit-for-mcp.php' ),
			'mcp-adapter/mcp-adapter.php',
		];
	}
}
