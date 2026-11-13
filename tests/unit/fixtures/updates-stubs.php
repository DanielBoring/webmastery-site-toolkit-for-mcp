<?php
/**
 * Loads the production Get Updates Overview ability into a namespace with scripted WordPress update state,
 * so it can be tested without colliding with global stubs from other suites.
 * State lives in $GLOBALS['wstm_updates']; UpdatesTest resets it before each test.
 */

namespace WstmUpdates;

require_once __DIR__ . '/shared-helper-loader.php';
\wstm_test_load_shared_helpers( __NAMESPACE__ );

/** Minimal WP_Theme stand-in with only the methods the ability may call. */
final class WP_Theme {
	public function __construct( private array $headers, private bool $exists = true ) {}

	public function exists() {
		return $this->exists;
	}

	public function get( $header ) {
		return $this->headers[ $header ] ?? false;
	}

	public function get_stylesheet_directory() {
		throw new \LogicException( 'Updates overview must not read theme paths.' );
	}
}

function current_user_can( $capability, ...$args ) {
	$GLOBALS['wstm_updates']['cap_calls'][] = $capability;
	return in_array( $capability, $GLOBALS['wstm_updates']['caps'], true );
}

function get_site_transient( $name ) {
	$GLOBALS['wstm_updates']['transient_reads'][] = $name;
	return $GLOBALS['wstm_updates']['transients'][ $name ] ?? false;
}

function get_bloginfo( $show = '' ) {
	return $GLOBALS['wstm_updates']['wp_version'];
}

function get_plugins() {
	return $GLOBALS['wstm_updates']['plugins'];
}

function wp_get_theme( $stylesheet = '' ) {
	return $GLOBALS['wstm_updates']['themes'][ $stylesheet ] ?? new WP_Theme( array(), false );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['wstm_updates']['registered'][ $name ] = $args;
}

function wp_remote_get( ...$args ) {
	throw new \LogicException( 'Updates overview must not make remote requests.' );
}

function wp_update_plugins( ...$args ) {
	throw new \LogicException( 'Updates overview must not refresh update data.' );
}

function wp_update_themes( ...$args ) {
	throw new \LogicException( 'Updates overview must not refresh update data.' );
}

function wp_version_check( ...$args ) {
	throw new \LogicException( 'Updates overview must not refresh update data.' );
}

function set_site_transient( ...$args ) {
	throw new \LogicException( 'Updates overview must not write transients.' );
}

$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-updates.php' );
eval( 'namespace ' . __NAMESPACE__ . '; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) );
