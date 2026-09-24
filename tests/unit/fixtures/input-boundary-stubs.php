<?php

namespace Wstm126Boundary;

use RuntimeException;
use Webmastery_MCP_Input;

require_once dirname( __DIR__, 3 ) . '/includes/class-input.php';
require_once dirname( __DIR__, 3 ) . '/includes/class-media.php';

foreach ( array( 'class-list-query.php', 'class-permissions.php' ) as $helper ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/' . $helper );
	eval( 'namespace Wstm126Boundary; use \Closure; use \WP_Error; use \Webmastery_MCP_Response; use \Webmastery_MCP_Untrusted; ' . substr( $source, 5 ) );
}

final class BoundaryReached extends RuntimeException {}

final class Probe {
	public static array $abilities = array();
	public static array $originals = array();
	public static array $events = array();
	public static bool $allowed = true;
	public static array $classes = array();

	public static function reset(): void {
		$GLOBALS['wpdb'] = (object) array( 'num_queries' => 0, 'last_error' => '' );
		self::$abilities = self::$originals = self::$events = array();
		self::$allowed = true;
		foreach ( self::$classes as $class ) {
			if ( __NAMESPACE__ . '\\Webmastery_MCP_Plugins' === $class ) {
				foreach ( array( 'register_list', 'register_audit', 'register_activate', 'register_deactivate' ) as $method ) {
					$register = new \ReflectionMethod( $class, $method );
					$register->setAccessible( true );
					$register->invoke( null );
				}
			} else {
				$class::register();
			}
		}
	}

	public static function reach( string $kind ): void {
		self::$events[] = $kind;
		throw new BoundaryReached( $kind );
	}
}

foreach ( glob( dirname( __DIR__, 3 ) . '/includes/class-*.php' ) as $file ) {
	$source = file_get_contents( $file );
	if ( ! str_contains( $source, 'public static function register()' ) ) {
		continue;
	}
	preg_match( '/class (Webmastery_MCP_\w+)/', $source, $matches );
	eval( 'namespace Wstm126Boundary; use \WP_Error; use \Webmastery_MCP_Response; use \Webmastery_MCP_Untrusted; use \Webmastery_MCP_Post_Parent; use \Webmastery_MCP_Post_Scheduling; ' . substr( $source, 5 ) );
	Probe::$classes[] = __NAMESPACE__ . '\\' . $matches[1];
}

function wp_register_ability( $name, $args ) {
	Probe::$originals[ $name ] = $args;
	Probe::$abilities[ $name ] = Webmastery_MCP_Input::register_args( $args, $name );
}

function get_post_types( $args = array(), $output = '' ) {
	$types = array();
	foreach ( array( 'mcp_book' => false, 'mcp_case_study' => false ) as $name => $hierarchical ) {
		$types[ $name ] = (object) array(
			'name' => $name, 'label' => $name, 'hierarchical' => $hierarchical, 'show_ui' => true,
			'labels' => (object) array( 'singular_name' => $name ),
			'cap' => (object) array( 'create_posts' => 'edit_posts', 'edit_post' => 'edit_post', 'publish_posts' => 'publish_posts' ),
		);
	}
	return $types;
}

function get_post_type_object( $name ) { return get_post_types()[ $name ]; }
function sanitize_title( $value ) { return strtolower( str_replace( ' ', '-', $value ) ); }
function current_user_can( $cap, ...$args ) {
	Probe::$events[] = 'capability:' . $cap;
	return Probe::$allowed;
}
function get_current_user_id() { return 7; }
function get_post( $id ) {
	Probe::$events[] = 'get_post';
	return (object) array( 'ID' => $id, 'post_type' => 'page', 'post_status' => 'draft', 'post_parent' => 0 );
}
function wp_kses_post( $value ) { return $value; }
function wp_insert_post( ...$args ) { Probe::reach( 'write:insert' ); }
function wp_update_post( ...$args ) { Probe::reach( 'write:update' ); }
function get_comments( ...$args ) { Probe::reach( 'query:comments' ); }
class WP_Query {
	public function __construct( ...$args ) { Probe::reach( 'query:posts' ); }
}
class WP_User_Query {
	public function __construct( ...$args ) { Probe::reach( 'query:users' ); }
}
