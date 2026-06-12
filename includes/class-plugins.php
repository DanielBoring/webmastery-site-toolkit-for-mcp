<?php

defined( 'ABSPATH' ) || exit;

class Unlock_MCP_Plugins {

	public static function register() {
		self::load_plugin_api();
		self::register_list();
		self::register_activate();
		self::register_deactivate();
	}

	public static function permission( $input = [] ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return self::error(
				'missing_capability',
				'Requires activate_plugins capability.',
				[ 'capability' => 'activate_plugins' ],
				403
			);
		}

		if ( ! empty( $input['network_wide'] ) ) {
			if ( ! is_multisite() ) {
				return self::error(
					'invalid_context',
					'Network-wide plugin management is only available on multisite installations.',
					[ 'network_wide' => true ],
					400
				);
			}

			if ( ! current_user_can( 'manage_network_plugins' ) ) {
				return self::error(
					'network_admin_required',
					'Network-wide plugin management requires manage_network_plugins capability.',
					[ 'capability' => 'manage_network_plugins' ],
					403
				);
			}
		}

		return true;
	}

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
			return self::error(
				'dependency_failure',
				$result->get_error_message(),
				[
					'plugin'              => $plugin,
					'network_wide'        => $network_wide,
					'original_error_code' => $result->get_error_code(),
				],
				409
			);
		}

		return [
			'success' => true,
			'data'    => self::normalize_plugin( $plugin, $plugins[ $plugin ] ),
		];
	}

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
			return self::error(
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
			return self::error(
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
			return self::error(
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

	private static function register_list() {
		wp_register_ability( 'unlock-mcp-potential/list-plugins', [
			'label'               => 'List Plugins',
			'description'         => 'List installed WordPress plugins and their activation state.',
			'category'            => 'unlock-mcp-potential',
			'execute_callback'    => [ self::class, 'list_plugins' ],
			'permission_callback' => '__return_true',
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_activate() {
		wp_register_ability( 'unlock-mcp-potential/activate-plugin', [
			'label'               => 'Activate Plugin',
			'description'         => 'Activate a WordPress plugin by canonical plugin basename.',
			'category'            => 'unlock-mcp-potential',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'plugin'       => [ 'type' => 'string', 'description' => 'Canonical plugin basename, for example akismet/akismet.php.' ],
					'slug'         => [ 'type' => 'string', 'description' => 'Optional plugin slug when it resolves to exactly one installed plugin.' ],
					'network_wide' => [ 'type' => 'boolean', 'default' => false ],
				],
			],
			'execute_callback'    => [ self::class, 'activate_plugin' ],
			'permission_callback' => '__return_true',
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_deactivate() {
		wp_register_ability( 'unlock-mcp-potential/deactivate-plugin', [
			'label'               => 'Deactivate Plugin',
			'description'         => 'Deactivate a WordPress plugin by canonical plugin basename.',
			'category'            => 'unlock-mcp-potential',
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
			'permission_callback' => '__return_true',
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function load_plugin_api() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	private static function get_plugins() {
		$plugins = get_plugins();
		ksort( $plugins );

		return $plugins;
	}

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

			return self::error(
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
				return self::error(
					'invalid_identifier',
					'Plugin slug is ambiguous. Use the canonical plugin basename instead.',
					[
						'slug'    => $slug,
						'matches' => $matches,
					],
					400
				);
			}

			return self::error(
				'plugin_not_found',
				'No installed plugin matches the provided plugin slug.',
				[ 'slug' => $slug ],
				404
			);
		}

		return self::error(
			'invalid_identifier',
			'Provide a plugin basename in plugin or an unambiguous slug in slug.',
			[ 'accepted_fields' => [ 'plugin', 'slug' ] ],
			400
		);
	}

	private static function plugin_matches_slug( $plugin, $slug ) {
		$parts    = explode( '/', $plugin );
		$filename = basename( $plugin, '.php' );

		return $slug === $filename || ( count( $parts ) > 1 && $slug === $parts[0] );
	}

	private static function normalize_plugin( $plugin, $plugin_data ) {
		// Read-only status reporting; this does not modify WordPress update routines.
		$updates        = get_site_transient( 'update_plugins' );
		$auto_updates   = (array) get_site_option( 'auto_update_plugins', [] );
		$network_active = is_multisite() && is_plugin_active_for_network( $plugin );

		return [
			'plugin'           => $plugin,
			'name'             => $plugin_data['Name'] ?? $plugin,
			'version'          => $plugin_data['Version'] ?? '',
			'active'           => is_plugin_active( $plugin ),
			'network_active'   => $network_active,
			'auto_update'      => in_array( $plugin, $auto_updates, true ),
			'update_available' => isset( $updates->response[ $plugin ] ),
		];
	}

	private static function is_protected_plugin( $plugin ) {
		return in_array( $plugin, self::protected_plugins(), true );
	}

	private static function protected_plugins() {
		return [
			plugin_basename( dirname( __DIR__ ) . '/unlock-mcp-potential.php' ),
			'mcp-adapter/mcp-adapter.php',
		];
	}

	private static function error( $code, $message, $details = [], $status = 400 ) {
		return new WP_Error(
			$code,
			$message,
			[
				'status'  => $status,
				'details' => $details,
			]
		);
	}
}
