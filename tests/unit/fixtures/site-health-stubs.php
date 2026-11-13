<?php
/**
 * Loads the production Site Health ability into a namespace with a scripted WP_Site_Health.
 */

namespace WstmSiteHealth;

final class State {
	/** @var array<string, mixed> */
	public static array $tests = array();
	/** @var array<int, callable> */
	public static array $result_filters = array();
}

class WP_Site_Health {
	public static function get_instance(): self {
		return new self();
	}

	public static function get_tests(): array {
		return State::$tests;
	}

	public function get_test_php_version(): array {
		return array( 'status' => 'good', 'label' => '<strong>PHP</strong> is current', 'description' => '<p>Fine.</p>', 'actions' => '' );
	}

	public function get_test_plugin_theme_auto_updates(): array {
		return array( 'status' => 'recommended', 'label' => 'Auto-updates', 'description' => 'Review.', 'actions' => '<a href="x">Fix</a>' );
	}

	public function get_test_sql_server(): array {
		throw new \RuntimeException( 'SQL failure in /var/www/secret/wp-config.php: SELECT password' );
	}

	private function get_test_private_probe(): array {
		return array( 'status' => 'critical', 'label' => 'Private' );
	}
}

function class_exists( $name ) {
	return 'WP_Site_Health' === $name || \class_exists( $name );
}

function apply_filters( $name, $value ) {
	foreach ( State::$result_filters as $filter ) {
		$value = $filter( $value );
	}
	return $value;
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_register_ability( $name, $args ) {
}

$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-health.php' );
eval( 'namespace WstmSiteHealth; use \Webmastery_MCP_Untrusted; use \Webmastery_MCP_Permissions; use \Throwable; ' . substr( $source, 5 ) );
