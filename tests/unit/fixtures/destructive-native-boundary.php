<?php

namespace Wstm126Destructive;

use RuntimeException;
use Webmastery_MCP_Input;
use Webmastery_MCP_Response;

require_once dirname( __DIR__, 3 ) . '/includes/class-input.php';
require_once dirname( __DIR__, 3 ) . '/includes/class-media.php';

/**
 * Unmodified production method bodies with namespace-local boundary spies.
 * No WordPress bootstrap, database, filesystem, HTTP or actual-core lifecycle.
 */
final class Probe {
	public static array $originals = array();
	public static array $wrapped = array();
	public static array $events = array();
	public static array $posts = array();
	public static array $terms = array();
	public static bool $allowed = true;

	public static function reset(): void {
		self::$originals = self::$wrapped = self::$events = array();
		self::$allowed = true;
		self::$posts = array(
			42 => (object) array( 'ID' => 42, 'post_type' => 'post', 'post_status' => 'draft' ),
			46 => (object) array( 'ID' => 46, 'post_type' => 'attachment', 'post_status' => 'inherit' ),
		);
		self::$terms = array(
			'category' => array( 51 => (object) array( 'term_id' => 51, 'name' => 'Retained category' ) ),
			'post_tag' => array( 52 => (object) array( 'term_id' => 52, 'name' => 'Retained tag' ) ),
		);
		foreach ( array(
			array( Webmastery_MCP_Posts::class, 'register_bulk_trash_posts', array() ),
			array( Webmastery_MCP_Posts::class, 'register_bulk_publish_posts', array() ),
			array( Webmastery_MCP_Media::class, 'register_delete', array() ),
			array( Webmastery_MCP_Taxonomy::class, 'register_delete', array( 'category' ) ),
			array( Webmastery_MCP_Taxonomy::class, 'register_delete', array( 'post_tag' ) ),
		) as [ $class, $method, $args ] ) {
			$register = new \ReflectionMethod( $class, $method );
			$register->setAccessible( true );
			$register->invokeArgs( null, $args );
		}
	}

	public static function snapshot(): string {
		return serialize( array( self::$posts, self::$terms ) );
	}

	public static function unexpected( string $kind, array $args ): void {
		self::$events[] = array( $kind, $args );
		throw new RuntimeException( 'Unexpected destructive boundary: ' . $kind );
	}
}

foreach ( array( 'permissions', 'posts', 'media', 'taxonomy' ) as $class ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-' . $class . '.php' );
	eval( 'namespace Wstm126Destructive; use \Closure; use \WP_Error; use \Webmastery_MCP_Response; use \Webmastery_MCP_Post_Parent; use \Webmastery_MCP_Post_Scheduling; ' . substr( $source, 5 ) );
}

function wp_register_ability( $name, $args ) {
	foreach ( array( 'execute_callback', 'permission_callback' ) as $key ) {
		$callback = $args[ $key ];
		$args[ $key ] = static function ( ...$values ) use ( $callback, $key ) {
			Probe::$events[] = array( $key, $values );
			return $callback( ...$values );
		};
	}
	Probe::$originals[ $name ] = $args;
	Probe::$wrapped[ $name ] = Webmastery_MCP_Input::register_args(
		Webmastery_MCP_Response::register_args( $args, $name ), $name
	);
}

function current_user_can( $cap, ...$args ) {
	Probe::$events[] = array( 'capability', $cap, $args );
	return Probe::$allowed;
}

function get_post( $id ) {
	Probe::$events[] = array( 'read:post', $id );
	return Probe::$posts[ $id ] ?? null;
}

function get_term( $id, $taxonomy = '' ) {
	Probe::$events[] = array( 'read:term', $id, $taxonomy );
	return Probe::$terms[ $taxonomy ][ $id ] ?? null;
}

function get_taxonomy( $taxonomy ) {
	Probe::$events[] = array( 'read:taxonomy', $taxonomy );
	return (object) array( 'cap' => (object) array( 'delete_terms' => 'manage_categories', 'edit_terms' => 'manage_categories' ) );
}

function wp_trash_post( ...$args ) { Probe::unexpected( 'write:trash', $args ); }
function wp_update_post( ...$args ) { Probe::unexpected( 'write:update', $args ); }
function wp_delete_attachment( ...$args ) { Probe::unexpected( 'write:attachment', $args ); }
function wp_delete_term( ...$args ) { Probe::unexpected( 'write:term', $args ); }

final class Database {
	public function __call( $method, $args ) {
		Probe::unexpected( 'query:' . $method, $args );
	}
}
