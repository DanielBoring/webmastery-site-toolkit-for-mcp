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

foreach ( array( 'class-plugins.php', 'class-security.php' ) as $file ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/' . $file );
	eval( 'namespace WstmSecurityAudit; use \Webmastery_MCP_Permissions; ' . substr( $source, 5 ) );
}
