<?php
/**
 * Loads the production Security Audit ability (and the plugin helpers it calls) into a namespace
 * with scripted WordPress state, so the new checks can be tested without global function clashes.
 * State lives in $GLOBALS['wstm_test_*'] keys that DiagnosticsTest resets after each test.
 */

namespace WstmSecurityAudit;

function get_option( $name, $default = false ) {
	if ( 'active_plugins' === $name ) {
		return $GLOBALS['wstm_test_active_plugins'] ?? $default;
	}
	return $GLOBALS['wstm111_home'];
}

function is_multisite() {
	return ! empty( $GLOBALS['wstm_test_multisite'] );
}

function get_site_option( $name, $default = false ) {
	return $GLOBALS['wstm_test_site_options'][ $name ] ?? $default;
}

function force_ssl_admin() {
	return ! empty( $GLOBALS['wstm_test_force_ssl_admin'] );
}

function get_plugins() {
	return $GLOBALS['wstm_test_installed_plugins'] ?? array();
}

function wp_get_themes() {
	return $GLOBALS['wstm_test_installed_themes'] ?? array();
}

function get_stylesheet() {
	return $GLOBALS['wstm_test_stylesheet'] ?? 'child';
}

function get_template() {
	return $GLOBALS['wstm_test_template'] ?? 'parent';
}

function get_users( $args ) {
	$GLOBALS['wstm_test_get_users_args'] = $args;
	return $GLOBALS['wstm_test_admin_ids'] ?? array();
}

function get_user_meta( $id, $key, $single ) {
	return $GLOBALS['wstm_test_user_meta'][ $id ][ $key ] ?? '';
}

/**
 * Stand-in for the Two-Factor plugin's Two_Factor_Core. It lives in this namespace, never the global one,
 * so it cannot leak into other tests; the eval below points the plugin's class-name literal at it only
 * when a test sets $GLOBALS['wstm_test_two_factor_class'] (otherwise at a class that does not exist).
 */
class Two_Factor_Core {
	public static function is_user_using_two_factor( $user = null ) {
		if ( ( $GLOBALS['wstm_test_two_factor_throw'] ?? null ) === $user ) {
			throw new \RuntimeException( 'third-party failure for user ' . $user );
		}
		return in_array( $user, $GLOBALS['wstm_test_two_factor_using'] ?? array(), true );
	}
}

/** A Two_Factor_Core look-alike without the verified method, for the "method unavailable" fallback. */
class Two_Factor_Core_Without_Method {
}

foreach ( array( 'class-plugins.php', 'class-security.php' ) as $file ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/' . $file );
	$source = str_replace( "'Two_Factor_Core'", "( \$GLOBALS['wstm_test_two_factor_class'] ?? 'WstmSecurityAudit\\Missing_Two_Factor_Core' )", $source );
	eval( 'namespace WstmSecurityAudit; use \Webmastery_MCP_Permissions; ' . substr( $source, 5 ) );
}
