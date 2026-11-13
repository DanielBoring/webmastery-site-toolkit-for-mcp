<?php

declare(strict_types=1);

namespace WstmAuditAccuracy;

define( __NAMESPACE__ . '\WP_PLUGIN_DIR', sys_get_temp_dir() . '/wstm-audit-accuracy-' . getmypid() . '/plugins' );
const DAY_IN_SECONDS = 86400;

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wstm_audit_accuracy']['options'] ) ? $GLOBALS['wstm_audit_accuracy']['options'][ $name ] : $default;
}

function is_multisite() {
	return false;
}

function is_plugin_active( $plugin ) {
	return false;
}

function current_user_can( $capability ) {
	return true;
}

function get_plugins() {
	return $GLOBALS['wstm_audit_accuracy']['plugins'] ?? array();
}

function get_site_transient( $name ) {
	return $GLOBALS['wstm_audit_accuracy']['transients'][ $name ] ?? false;
}

function get_bloginfo( $show ) {
	return 'version' === $show ? $GLOBALS['wstm_audit_accuracy']['wp_version'] : '';
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
}

function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

// Mirrors WordPress core: reads only the first 8 KB and matches headers case-insensitively.
function get_file_data( $file, $default_headers, $context = '' ) {
	$GLOBALS['wstm_audit_accuracy']['file_data_reads'][] = $file;
	$data    = str_replace( "\r", "\n", (string) file_get_contents( $file, false, null, 0, 8192 ) );
	$headers = array();
	foreach ( $default_headers as $field => $regex ) {
		if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $data, $match ) && $match[1] ) {
			$headers[ $field ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
		} else {
			$headers[ $field ] = '';
		}
	}
	return $headers;
}

class_alias( \Webmastery_MCP_Response::class, __NAMESPACE__ . '\Webmastery_MCP_Response' );
foreach ( array( 'plugins', 'backup-status' ) as $file ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-' . $file . '.php' );
	eval( 'namespace ' . __NAMESPACE__ . ';' . substr( $source, strlen( '<?php' ) ) );
}
