<?php

namespace Wstm206;

use \Webmastery_MCP_Response;

final class State {
	public static array $abilities = array();
	public static array $posts = array();
	public static array $calls = array();
	public static array $images = array();
	public static array $caps = array();
	public static array $denied_ids = array();
	public static bool $fail_set = false;
	public static bool $fail_remove = false;
	public static bool $missing_url = false;
}

function wp_register_ability( $name, $args ): void {
	State::$abilities[ basename( $name ) ] = \Webmastery_MCP_Input::register_args( $args, $name );
}
function get_post( $id ) {
	State::$calls[] = array( 'post', $id );
	return State::$posts[ $id ] ?? null;
}
function current_user_can( $cap, ...$args ): bool {
	State::$calls[] = array( 'cap', $cap, $args );
	return ( State::$caps[ $cap ] ?? true ) && ! in_array( $args[0] ?? null, State::$denied_ids, true );
}
function wp_attachment_is_image( $id ): bool {
	State::$calls[] = array( 'image', $id );
	return 'image/png' === State::$posts[ $id ]->post_mime_type;
}
function get_post_thumbnail_id( $id ): int {
	State::$calls[] = array( 'thumbnail', $id );
	return State::$images[ $id ] ?? 0;
}
function wp_get_attachment_url( $id ) {
	State::$calls[] = array( 'url', $id );
	return State::$missing_url ? false : 'https://example.test/' . $id . '.png';
}
function set_post_thumbnail( $id, $attachment ) {
	State::$calls[] = array( 'set', $id, $attachment );
	if ( State::$fail_set || ( State::$images[ $id ] ?? 0 ) === $attachment ) {
		return false;
	}
	State::$images[ $id ] = $attachment;
	return 123;
}
function delete_post_thumbnail( $id ): bool {
	State::$calls[] = array( 'remove', $id );
	if ( State::$fail_remove ) {
		return false;
	}
	$previous = State::$images[ $id ] ?? 0;
	unset( State::$images[ $id ] );
	return 0 !== $previous;
}
class Webmastery_MCP_Posts {
	public static function normalize( $id ): array {
		State::$calls[] = array( 'normalize', $id );
		return array( 'id' => $id, 'content' => str_repeat( 'Body', 15000 ), 'featured_image_id' => State::$images[ $id ] ?? 0 );
	}
}
foreach ( array( 'post-access', 'untrusted', 'featured-image' ) as $file ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-' . $file . '.php' );
	eval( 'namespace ' . __NAMESPACE__ . '; use \\Webmastery_MCP_Response; use \\WP_Error; ' . substr( $source, 5 ) );
}
