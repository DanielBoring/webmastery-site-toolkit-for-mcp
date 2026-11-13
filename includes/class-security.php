<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Security {

	/**
	 * PHP branch => last day of security support (UTC). Hardcoded on purpose: the audit makes no
	 * remote request. Update this table from https://www.php.net/supported-versions.php when
	 * a new PHP branch is released or a support date changes.
	 */
	private const PHP_SECURITY_SUPPORT_END = [
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
		'8.5' => '2029-12-31',
	];

	/** Basenames of active plugins that can provide two-factor authentication (best-effort list). */
	private const TWO_FACTOR_PLUGINS = [
		'two-factor/two-factor.php',
		'wp-2fa/wp-2fa.php',
		'wordfence/wordfence.php',
		'wordfence-login-security/wordfence-login-security.php',
		'better-wp-security/better-wp-security.php',
		'ithemes-security-pro/ithemes-security-pro.php',
		'google-authenticator/google-authenticator.php',
		'two-factor-authentication/two-factor-login.php',
		'miniorange-2-factor-authentication/miniorange_2_factor_settings.php',
	];

	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/security-audit', [
			'label'               => 'Security Audit',
			'description'         => 'Run a security audit of the WordPress installation and return findings grouped by severity.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => Webmastery_MCP_Permissions::admin(),
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
		$home_scheme = self::configured_home_scheme( get_option( 'home' ) );
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

		// WordPress version vs latest, from cached update data only (no remote request).
		$core_updates = get_site_transient( 'update_core' );
		$entries      = is_object( $core_updates ) && isset( $core_updates->updates ) && is_array( $core_updates->updates ) ? $core_updates->updates : [];
		$upgrade      = null;
		$known        = false;
		foreach ( $entries as $update ) {
			if ( ! is_object( $update ) || ! isset( $update->response ) || ! is_string( $update->response ) ) {
				continue;
			}
			$known = true;
			if ( 'upgrade' === $update->response ) {
				$upgrade = $update;
				break;
			}
		}
		if ( null !== $upgrade ) {
			$latest = isset( $upgrade->version ) && is_scalar( $upgrade->version ) ? (string) $upgrade->version : 'unknown';
			$warn[] = [
				'check'  => 'wp_version',
				'label'  => 'WordPress core update available',
				'detail' => sprintf( 'Current: %s — Latest: %s', get_bloginfo( 'version' ), $latest ),
			];
		} elseif ( $known ) {
			$pass[] = [ 'check' => 'wp_version', 'label' => 'WordPress core is up to date', 'detail' => 'Version: ' . get_bloginfo( 'version' ) ];
		} else {
			$warn[] = [ 'check' => 'wp_version', 'label' => 'WordPress core update status unknown', 'detail' => 'Cached update data is unavailable. Check for updates in Dashboard > Updates, then run the audit again. Version: ' . get_bloginfo( 'version' ) ];
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

		// Auth keys/salts — check all eight wp-config.php constants without exposing their values.
		$default_salt = 'put your unique phrase here';
		$constants    = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ];
		$weak_salts   = [];
		$unset_salts  = [];
		foreach ( $constants as $const ) {
			$value = defined( $const ) ? constant( $const ) : null;
			if ( ! is_string( $value ) || '' === $value ) {
				$unset_salts[] = $const;
			} elseif ( strpos( $value, $default_salt ) !== false ) {
				$weak_salts[] = $const;
			}
		}
		$missing_note = empty( $unset_salts ) ? '' : 'Missing or empty in wp-config.php: ' . implode( ', ', $unset_salts ) . '. WordPress falls back to values stored in the database. ';
		if ( ! empty( $weak_salts ) ) {
			$fail[] = [ 'check' => 'auth_keys', 'label' => 'Default auth keys/salts detected: ' . implode( ', ', $weak_salts ), 'detail' => $missing_note . 'Generate new keys at https://api.wordpress.org/secret-key/1.1/salt/' ];
		} elseif ( ! empty( $unset_salts ) ) {
			$warn[] = [ 'check' => 'auth_keys', 'label' => 'Auth keys/salts missing or empty in wp-config.php: ' . implode( ', ', $unset_salts ), 'detail' => 'WordPress falls back to values stored in the database. Define unique values in wp-config.php; generate them at https://api.wordpress.org/secret-key/1.1/salt/' ];
		} else {
			$pass[] = [ 'check' => 'auth_keys', 'label' => 'Auth keys and salts are set' ];
		}

		// PHP branch security support, from a hardcoded table (no remote request).
		$php_branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$php_status = self::php_support_status( $php_branch, time() );
		if ( 'fail' === $php_status['state'] ) {
			$fail[] = [ 'check' => 'php_version', 'label' => "PHP {$php_branch} no longer receives security fixes", 'detail' => 'Upgrade to a PHP branch that still receives security fixes. Ask your host if you are unsure how.' ];
		} elseif ( 'warn' === $php_status['state'] ) {
			$warn[] = [ 'check' => 'php_version', 'label' => "PHP {$php_branch} security support ends soon", 'detail' => "Security support for PHP {$php_branch} ends on {$php_status['end']}. Plan an upgrade to a newer PHP branch." ];
		} elseif ( 'pass' === $php_status['state'] ) {
			$pass[] = [ 'check' => 'php_version', 'label' => "PHP {$php_branch} still receives security fixes", 'detail' => "Security support ends on {$php_status['end']}." ];
		} else {
			$pass[] = [ 'check' => 'php_version', 'label' => "PHP {$php_branch} support window not known to this plugin version", 'detail' => 'This PHP branch is newer than the support table in this plugin version.' ];
		}

		// Admin screens over SSL.
		if ( force_ssl_admin() ) {
			$pass[] = [ 'check' => 'force_ssl_admin', 'label' => 'Admin and login screens are forced to use SSL (FORCE_SSL_ADMIN)' ];
		} elseif ( 'https' === $home_scheme ) {
			$warn[] = [ 'check' => 'force_ssl_admin', 'label' => 'FORCE_SSL_ADMIN is not enabled', 'detail' => 'The public home URL uses HTTPS, but admin and login screens are not forced to use SSL. Add define(\'FORCE_SSL_ADMIN\', true) to wp-config.php.' ];
		} else {
			$warn[] = [ 'check' => 'force_ssl_admin', 'label' => 'FORCE_SSL_ADMIN is not enabled', 'detail' => 'Admin and login screens are not forced to use SSL. Serve the site over HTTPS first, then add define(\'FORCE_SSL_ADMIN\', true) to wp-config.php.' ];
		}

		// Default database table prefix. The prefix itself is never returned.
		global $wpdb;
		$db_prefix = is_object( $wpdb ) ? ( is_multisite() ? ( $wpdb->base_prefix ?? null ) : ( $wpdb->prefix ?? null ) ) : null;
		if ( ! is_string( $db_prefix ) || '' === $db_prefix ) {
			$warn[] = [ 'check' => 'db_prefix', 'label' => 'Database table prefix status unknown', 'detail' => 'The configured table prefix could not be read.' ];
		} elseif ( 'wp_' === $db_prefix ) {
			$warn[] = [ 'check' => 'db_prefix', 'label' => 'The default database table prefix is in use', 'detail' => 'This is low-value hardening: a custom prefix only hinders generic automated SQL injection payloads that assume the default. It is not a vulnerability by itself, and changing it on an existing site is risky without a backup.' ];
		} else {
			$pass[] = [ 'check' => 'db_prefix', 'label' => 'A custom database table prefix is in use' ];
		}

		// Inactive plugins (counts only; requires activate_plugins cap).
		if ( current_user_can( 'activate_plugins' ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed = array_map( 'strval', array_keys( (array) get_plugins() ) );
			$inactive  = count( array_diff( $installed, Webmastery_MCP_Plugins::active_basenames() ) );
			if ( $inactive > 0 ) {
				$warn[] = [ 'check' => 'inactive_plugins', 'label' => "{$inactive} inactive plugin(s) are installed", 'detail' => 'Inactive plugins can still contain exploitable code. Delete the ones you do not need.' ];
			} else {
				$pass[] = [ 'check' => 'inactive_plugins', 'label' => 'No inactive plugins are installed' ];
			}
		}

		// Inactive themes (counts only; the active theme and its parent are not counted).
		if ( current_user_can( 'switch_themes' ) ) {
			$extra = count( array_diff( array_map( 'strval', array_keys( (array) wp_get_themes() ) ), [ (string) get_stylesheet(), (string) get_template() ] ) );
			if ( $extra > 1 ) {
				$warn[] = [ 'check' => 'inactive_themes', 'label' => "{$extra} inactive theme(s) are installed", 'detail' => 'Keeping one default theme as a fallback is recommended; delete other themes you do not need.' ];
			} else {
				$pass[] = [ 'check' => 'inactive_themes', 'label' => 'No unnecessary inactive themes are installed' ];
			}
		}

		// Theme updates, from cached update data only (requires update_themes cap).
		if ( current_user_can( 'update_themes' ) ) {
			$theme_updates = get_site_transient( 'update_themes' );
			if ( ! is_object( $theme_updates ) || ! isset( $theme_updates->response ) || ! is_array( $theme_updates->response ) ) {
				$warn[] = [ 'check' => 'theme_updates', 'label' => 'Theme update status unknown', 'detail' => 'Cached update data is unavailable. Check for updates in Dashboard > Updates, then run the audit again.' ];
			} elseif ( count( $theme_updates->response ) > 0 ) {
				$theme_count = count( $theme_updates->response );
				$warn[]      = [ 'check' => 'theme_updates', 'label' => "{$theme_count} theme(s) have available updates", 'detail' => 'Outdated themes are a common attack vector.' ];
			} else {
				$pass[] = [ 'check' => 'theme_updates', 'label' => 'All themes are up to date' ];
			}
		}

		// Two-factor authentication: best-effort, from the active plugin list only.
		$active_plugins = Webmastery_MCP_Plugins::active_basenames();
		if ( in_array( 'two-factor/two-factor.php', $active_plugins, true ) ) {
			$enrollment = self::two_factor_enrollment();
			if ( $enrollment['verified'] ) {
				$noun     = 'has a usable second factor';
				$missing  = 'have no usable two-factor method (no enabled provider that is currently available) or could not be verified.';
				$fallback = '';
			} else {
				$noun     = 'has a provider enabled';
				$missing  = 'have no two-factor provider enabled.';
				$fallback = ' Provider usability was not verified because the Two-Factor plugin API was unavailable; an account whose only provider is exhausted recovery codes would still count as enrolled.';
			}
			if ( $enrollment['missing'] > 0 ) {
				$detail = "{$enrollment['missing']} of {$enrollment['checked']} administrator account(s) checked {$missing}";
				if ( $enrollment['checked'] >= 100 ) {
					$detail .= ' Only the first 100 administrators are checked.';
				}
				$warn[] = [ 'check' => 'two_factor', 'label' => 'Some administrators have not enabled two-factor authentication', 'detail' => $detail . $fallback ];
			} else {
				$finding = [ 'check' => 'two_factor', 'label' => "The Two-Factor plugin is active and every administrator checked {$noun}" ];
				if ( '' !== $fallback ) {
					$finding['detail'] = trim( $fallback );
				}
				$pass[] = $finding;
			}
		} elseif ( array_intersect( self::TWO_FACTOR_PLUGINS, $active_plugins ) ) {
			$pass[] = [ 'check' => 'two_factor', 'label' => 'A two-factor capable plugin is active', 'detail' => 'Per-administrator enrollment was not verified; confirm in the plugin that every administrator has a second factor.' ];
		} else {
			$warn[] = [ 'check' => 'two_factor', 'label' => 'No known two-factor plugin detected', 'detail' => 'Detection is limited to a list of known plugins. Two-factor authentication may be provided another way, for example at the host or identity provider.' ];
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

	/**
	 * Classify a PHP branch against the support table.
	 *
	 * @param string $branch Major.minor, for example "8.2".
	 * @param int    $now    Current Unix timestamp.
	 * @return array{state: string, end: string} state is fail, warn, pass or unknown (branch newer than the table).
	 */
	private static function php_support_status( $branch, $now ) {
		if ( isset( self::PHP_SECURITY_SUPPORT_END[ $branch ] ) ) {
			$end    = self::PHP_SECURITY_SUPPORT_END[ $branch ];
			$end_ts = strtotime( $end . ' 23:59:59 UTC' );
			$state  = 'pass';
			if ( $now > $end_ts ) {
				$state = 'fail';
			} elseif ( $end_ts - $now <= 90 * 86400 ) {
				$state = 'warn';
			}
			return [ 'state' => $state, 'end' => $end ];
		}

		// Branches older than the first table entry are unsupported; anything else is newer than the table.
		if ( version_compare( $branch . '.0', '8.0.0', '<' ) ) {
			return [ 'state' => 'fail', 'end' => '' ];
		}

		return [ 'state' => 'unknown', 'end' => '' ];
	}

	/**
	 * Count administrators (first 100) without a usable second factor.
	 *
	 * Uses the Two-Factor plugin's Two_Factor_Core::is_user_using_two_factor() (since 0.2.0), which
	 * counts only providers that are both enabled and available (for example, recovery codes with no
	 * codes left do not count). A Throwable from that code counts the administrator as not verified.
	 * Without the class or method, falls back to the raw enabled-providers user meta, which does not
	 * prove a provider is usable.
	 *
	 * @return array{missing: int, checked: int, verified: bool} verified is true when the plugin API was used.
	 */
	private static function two_factor_enrollment() {
		$ids     = get_users( [ 'role' => 'administrator', 'number' => 100, 'orderby' => 'ID', 'fields' => 'ID' ] );
		$ids     = is_array( $ids ) ? $ids : [];
		$core    = 'Two_Factor_Core';
		$api     = class_exists( $core ) && is_callable( [ $core, 'is_user_using_two_factor' ] );
		$missing = 0;
		foreach ( $ids as $id ) {
			if ( $api ) {
				try {
					$enrolled = (bool) $core::is_user_using_two_factor( (int) $id );
				} catch ( \Throwable $e ) {
					$enrolled = false;
				}
			} else {
				$enrolled = ! empty( get_user_meta( (int) $id, '_two_factor_enabled_providers', true ) );
			}
			if ( ! $enrolled ) {
				++$missing;
			}
		}

		return [ 'missing' => $missing, 'checked' => count( $ids ), 'verified' => $api ];
	}

	private static function configured_home_scheme( $home_url ) {
		if ( ! is_string( $home_url ) || preg_match( '/[\x00-\x20\x7f]|%(?![0-9a-f]{2})/i', $home_url ) ) {
			return '';
		}

		$parts      = wp_parse_url( $home_url );
		$name_chars = 'a-z0-9._~!$&\'()*+,;=%\x80-\xff\-';
		$authority  = '#\Ahttps?://(?:[' . $name_chars . ':]*@)?(\[[^\]]+\]|[' . $name_chars . ']+)(?::[0-9]*)?(?:[/?\#]|\z)#i';
		if ( ! is_array( $parts ) || ! preg_match( $authority, $home_url, $matches ) ) {
			return '';
		}

		$host = $matches[1];
		if ( '[' === $host[0] ) {
			$literal = substr( $host, 1, -1 );
			// Scoped IPv6 uses an escaped zone delimiter; validate syntax, not address routability.
			$ipv6      = preg_replace( '/%25[a-z0-9._~%\-]+\z/i', '', $literal );
			$ipvfuture = preg_match( '#\Av[0-9a-f]+\.[a-z0-9._~!$&\'()*+,;=:\-]+\z#i', $literal );
			if ( ! filter_var( $ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && ! $ipvfuture ) {
				return '';
			}
		}

		return strtolower( $parts['scheme'] );
	}
}
