<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Security {

	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/security-audit', [
			'label'               => 'Security Audit',
			'description'         => 'Run a security audit of the WordPress installation and return findings grouped by severity.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'forbidden', 'Requires manage_options capability.' );
				}
				return true;
			},
			'meta' => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute( $input = [] ) {
		$pass = [];
		$warn = [];
		$fail = [];

		// Debug mode
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$check = [ 'check' => 'debug_mode', 'label' => 'WP_DEBUG is enabled', 'detail' => 'Debug mode exposes errors to visitors. Disable on production.' ];
			if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
				$fail[] = $check;
			} else {
				$warn[] = $check;
			}
		} else {
			$pass[] = [ 'check' => 'debug_mode', 'label' => 'WP_DEBUG is disabled' ];
		}

		// Debug log
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$log_path         = is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
			$unprotected_hint = str_starts_with( $log_path, WP_CONTENT_DIR )
				&& ! file_exists( dirname( $log_path ) . '/.htaccess' );
			if ( $unprotected_hint ) {
				$warn[] = [ 'check' => 'debug_log', 'label' => 'Debug log may be publicly accessible', 'detail' => 'The configured log location appears to be inside wp-content without a neighboring .htaccess file. Verify server access rules or move the log outside the web root. Public access has not been tested.' ];
			} else {
				$warn[] = [ 'check' => 'debug_log', 'label' => 'Debug log access is unverified', 'detail' => 'A neighboring .htaccess file or a location outside wp-content does not prove that web access is denied. Verify server access rules and the log location relative to the web root.' ];
			}
		} else {
			$pass[] = [ 'check' => 'debug_log', 'label' => 'Debug logging is disabled' ];
		}

		// File editor
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			$pass[] = [ 'check' => 'file_editor', 'label' => 'Theme/plugin file editor is disabled (DISALLOW_FILE_EDIT)' ];
		} else {
			$warn[] = [ 'check' => 'file_editor', 'label' => 'Theme/plugin file editor is enabled', 'detail' => 'Add define(\'DISALLOW_FILE_EDIT\', true) to wp-config.php to prevent file editing via admin.' ];
		}

		// Read configuration directly: home_url() can change its scheme based on the current request.
		$home_url    = get_option( 'home' );
		$home_parts  = is_string( $home_url ) && ! preg_match( '/[\x00-\x20\x7f]/', $home_url ) ? wp_parse_url( $home_url ) : false;
		$home_scheme = is_array( $home_parts ) && ! empty( $home_parts['host'] ) ? strtolower( $home_parts['scheme'] ?? '' ) : '';
		if ( 'https' === $home_scheme ) {
			$pass[] = [ 'check' => 'ssl', 'label' => 'Public home URL is configured to use HTTPS' ];
		} elseif ( 'http' === $home_scheme ) {
			$fail[] = [ 'check' => 'ssl', 'label' => 'Public home URL is configured to use HTTP', 'detail' => 'Review the public home URL configuration. This check does not test certificates, reachability, or HTTPS redirects.' ];
		} else {
			$warn[] = [ 'check' => 'ssl', 'label' => 'Public home URL scheme is unknown', 'detail' => 'The home option is not a recognized absolute HTTP or HTTPS URL. Review its configuration; transport security has not been tested.' ];
		}

		// Admin username
		$admin_user = get_user_by( 'login', 'admin' );
		if ( $admin_user ) {
			$warn[] = [ 'check' => 'admin_username', 'label' => 'A user with the login "admin" exists', 'detail' => 'Predictable usernames make brute-force attacks easier. Consider renaming this account.' ];
		} else {
			$pass[] = [ 'check' => 'admin_username', 'label' => 'No account with the login "admin" found' ];
		}

		// WordPress version vs latest
		$core_updates = get_site_transient( 'update_core' );
		if ( $core_updates && ! empty( $core_updates->updates ) ) {
			foreach ( $core_updates->updates as $update ) {
				if ( 'upgrade' === $update->response ) {
					$warn[] = [
						'check'  => 'wp_version',
						'label'  => 'WordPress core update available',
						'detail' => sprintf( 'Current: %s — Latest: %s', get_bloginfo( 'version' ), $update->version ),
					];
					break;
				}
			}
		} else {
			$pass[] = [ 'check' => 'wp_version', 'label' => 'WordPress core is up to date', 'detail' => 'Version: ' . get_bloginfo( 'version' ) ];
		}

		// Plugin updates (requires update_plugins cap)
		if ( current_user_can( 'update_plugins' ) ) {
			$plugin_updates = get_site_transient( 'update_plugins' );
			$outdated_count = $plugin_updates ? count( $plugin_updates->response ?? [] ) : 0;
			if ( $outdated_count > 0 ) {
				$warn[] = [ 'check' => 'plugin_updates', 'label' => "{$outdated_count} plugin(s) have available updates", 'detail' => 'Outdated plugins are a common attack vector.' ];
			} else {
				$pass[] = [ 'check' => 'plugin_updates', 'label' => 'All plugins are up to date' ];
			}
		}

		// XMLRPC
		$xmlrpc_enabled = apply_filters( 'xmlrpc_enabled', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a core WordPress filter, not registering a custom hook.
		if ( $xmlrpc_enabled ) {
			$warn[] = [ 'check' => 'xmlrpc', 'label' => 'XML-RPC is enabled', 'detail' => 'XML-RPC can be exploited for brute-force and DDoS amplification. Disable it if not needed.' ];
		} else {
			$pass[] = [ 'check' => 'xmlrpc', 'label' => 'XML-RPC is disabled' ];
		}

		// Auth keys/salts — check for default placeholder
		$default_salt = 'put your unique phrase here';
		$constants    = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ];
		$weak_salts   = [];
		foreach ( $constants as $const ) {
			if ( defined( $const ) && strpos( constant( $const ), $default_salt ) !== false ) {
				$weak_salts[] = $const;
			}
		}
		if ( ! empty( $weak_salts ) ) {
			$fail[] = [ 'check' => 'auth_keys', 'label' => 'Default auth keys/salts detected: ' . implode( ', ', $weak_salts ), 'detail' => 'Generate new keys at https://api.wordpress.org/secret-key/1.1/salt/' ];
		} else {
			$pass[] = [ 'check' => 'auth_keys', 'label' => 'Auth keys and salts are set' ];
		}

		return [
			'success' => true,
			'data'    => [
				'summary' => [
					'fail' => count( $fail ),
					'warn' => count( $warn ),
					'pass' => count( $pass ),
				],
				'fail' => $fail,
				'warn' => $warn,
				'pass' => $pass,
			],
		];
	}
}
