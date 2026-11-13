<?php
/**
 * Loads the production List Themes ability into a namespace with scripted WordPress theme state,
 * so it can be tested without colliding with global stubs from other suites.
 * State lives in $GLOBALS['wstm_themes']; ThemesTest resets it before each test.
 */

namespace WstmThemes;

require_once __DIR__ . '/shared-helper-loader.php';
\wstm_test_load_shared_helpers( __NAMESPACE__ );

/** Minimal WP_Theme stand-in with only the methods the ability may call. */
final class WP_Theme {
	public function __construct( private string $stylesheet, private array $headers, private string $template = '', private bool $block = false, private bool $broken = false ) {
		$this->template = '' === $template ? $stylesheet : $template;
	}

	public function errors() {
		return $this->broken ? new \WP_Error( 'theme_no_parent', 'SECRET /var/www/html/wp-content/themes/broken error text' ) : false;
	}

	public function get( $header ) {
		$GLOBALS['wstm_themes']['header_reads'][] = array( $this->stylesheet, $header );
		return $this->headers[ $header ] ?? false;
	}

	public function get_template() {
		return $this->template;
	}

	public function is_block_theme() {
		return $this->block;
	}

	public function get_stylesheet_directory() {
		throw new \LogicException( 'List Themes must not read theme paths.' );
	}

	public function get_theme_root() {
		throw new \LogicException( 'List Themes must not read the theme root.' );
	}

	public function get_screenshot( $uri = 'uri' ) {
		throw new \LogicException( 'List Themes must not read screenshots.' );
	}
}

function wp_get_themes( $args = array() ) {
	$GLOBALS['wstm_themes']['theme_args'][] = $args;
	return $GLOBALS['wstm_themes']['themes'];
}

function get_stylesheet() {
	return $GLOBALS['wstm_themes']['stylesheet'];
}

function get_template() {
	return $GLOBALS['wstm_themes']['template'];
}

function current_user_can( $capability, ...$args ) {
	$GLOBALS['wstm_themes']['cap_calls'][] = $capability;
	return in_array( $capability, $GLOBALS['wstm_themes']['caps'], true );
}

function get_site_transient( $name ) {
	$GLOBALS['wstm_themes']['transient_reads'][] = $name;
	return $GLOBALS['wstm_themes']['transients'][ $name ] ?? false;
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['wstm_themes']['registered'][ $name ] = $args;
}

function wp_remote_get( ...$args ) {
	throw new \LogicException( 'List Themes must not make remote requests.' );
}

function wp_update_themes( ...$args ) {
	throw new \LogicException( 'List Themes must not refresh update data.' );
}

$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-themes.php' );
eval( 'namespace ' . __NAMESPACE__ . '; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) );
