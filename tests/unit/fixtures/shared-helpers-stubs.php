<?php

namespace Wstm119Shared;

require_once __DIR__ . '/shared-helper-loader.php';
require_once __DIR__ . '/shared-helper-transition.php';

final class State {
	public static array $posts = array();
	public static array $calls = array();
	public static array $queries = array();
	public static array $ids = array();
	public static array $terms = array();
	public static array $writes = array();
	public static array $denied_ids = array();
	public static bool $allowed = true;
	public static $result = 42;
}

function current_user_can( $cap, ...$args ): bool {
	State::$calls[] = array( $cap, $args );
	return State::$allowed && ! in_array( $args[0] ?? null, State::$denied_ids, true );
}
function get_post( $post ) {
	return is_object( $post ) ? $post : ( State::$posts[ $post ] ?? null );
}
function get_current_user_id(): int { return 7; }
function get_permalink( $id ): string { return 'https://example.test/?p=' . $id; }
function get_the_author_meta( $field, $id ): string { return 'Author ' . $id; }
function get_post_thumbnail_id( $id ): int { return 99; }
function get_post_meta( $id, $key, $single = false ): string { return 'Alt C:\\path\\'; }
function wp_get_attachment_metadata( $id ): array { return array( 'width' => 640, 'height' => 480 ); }
function get_attached_file( $id ): string { return 'C:\\uploads\\image.jpg'; }
function wp_get_attachment_url( $id ): string { return 'https://example.test/image.jpg'; }
function wp_get_post_categories( $id, $args ): array { return array( 3 ); }
function wp_get_post_tags( $id, $args ): array { return array( 4 ); }
function get_object_taxonomies( $type, $output = 'names' ): array {
	return 'objects' === $output ? array( 'genre' => (object) array( 'name' => 'genre' ) ) : array( 'genre' );
}
function wp_get_object_terms( $id, $taxonomy, $args ) {
	return State::$terms[ $taxonomy ] ?? array( 5 );
}
function wp_slash( $value ) {
	State::$writes[] = array( 'slash', $value );
	return \wp_slash( $value );
}
function wp_insert_post( $args, $error ) {
	State::$writes[] = array( 'insert', $args, $error );
	return State::$result;
}
function wp_update_post( $args, $error ) {
	State::$writes[] = array( 'update', $args, $error );
	return State::$result;
}
function update_post_meta( $id, $key, $value ) {
	State::$writes[] = array( 'meta', $id, $key, $value );
	return State::$result;
}
class WP_Query {
	public array $posts;
	public function __construct( array $args ) {
		State::$queries[] = $args;
		$this->posts = State::$ids;
	}
}

\wstm_test_load_shared_helpers( __NAMESPACE__, true );
foreach ( array( 'posts', 'custom-post-types', 'media', 'seo', 'content-hygiene', 'users', 'comments', 'backup-status', 'performance-status', 'database-health' ) as $file ) {
	$path = 'includes/class-' . $file . '.php';
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/' . $path );
	eval( 'namespace ' . __NAMESPACE__ . '; use \\WP_Error; use \\Webmastery_MCP_Response; ' . substr( \Wstm167SourceTransition::restore( $path, $source ), 5 ) );
	eval( 'namespace Wstm119Before; use \\WP_Error; use \\Webmastery_MCP_Response; ' . substr( \Wstm119SourceTransition::restore( $path, $source ), 5 ) );
}

namespace Wstm119Before;

class WP_Query extends \Wstm119Shared\WP_Query {}

function get_post( $post ) { return \Wstm119Shared\get_post( $post ); }
function current_user_can( $cap, ...$args ): bool { return \Wstm119Shared\current_user_can( $cap, ...$args ); }
function get_permalink( $id ): string { return \Wstm119Shared\get_permalink( $id ); }
function get_the_author_meta( $field, $id ): string { return \Wstm119Shared\get_the_author_meta( $field, $id ); }
function get_post_thumbnail_id( $id ): int { return \Wstm119Shared\get_post_thumbnail_id( $id ); }
function get_post_meta( $id, $key, $single = false ): string { return \Wstm119Shared\get_post_meta( $id, $key, $single ); }
function wp_get_attachment_metadata( $id ): array { return \Wstm119Shared\wp_get_attachment_metadata( $id ); }
function get_attached_file( $id ): string { return \Wstm119Shared\get_attached_file( $id ); }
function wp_get_attachment_url( $id ): string { return \Wstm119Shared\wp_get_attachment_url( $id ); }
function wp_get_post_categories( $id, $args ): array { return \Wstm119Shared\wp_get_post_categories( $id, $args ); }
function wp_get_post_tags( $id, $args ): array { return \Wstm119Shared\wp_get_post_tags( $id, $args ); }
function get_object_taxonomies( $type, $output = 'names' ): array { return \Wstm119Shared\get_object_taxonomies( $type, $output ); }
function wp_get_object_terms( $id, $taxonomy, $args ) { return \Wstm119Shared\wp_get_object_terms( $id, $taxonomy, $args ); }
