<?php

namespace Wstm108Content;

// Keep production class bodies intact while controlling their WordPress boundaries.
foreach ( array( 'posts', 'custom-post-types', 'comments', 'media', 'users', 'content-hygiene' ) as $module ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-' . $module . '.php' );
	eval( 'namespace Wstm108Content; use \WP_Error; use \Webmastery_MCP_Response; use \Webmastery_MCP_Untrusted; use \Webmastery_MCP_Post_Scheduling; use \Webmastery_MCP_Post_Parent; ' . substr( $source, 5 ) );
}

class WP_User extends \stdClass {
	public function exists() {
		return true;
	}
}

function get_post( $post ) {
	return is_object( $post ) ? $post : ( $GLOBALS['wstm_test_posts'][ $post ] ?? null );
}

function get_permalink( $id ) {
	return $GLOBALS['wstm108']['url'];
}

function get_the_author_meta( $field, $id ) {
	return $GLOBALS['wstm108']['author'];
}

function get_post_thumbnail_id( $id ) {
	return '91';
}

function wp_get_post_categories( $id, $args ) {
	return array( 4, 7 );
}

function wp_get_post_tags( $id, $args ) {
	return array( 8 );
}

function get_object_taxonomies( $type ) {
	return array( 'topic' );
}

function wp_get_object_terms( $id, $taxonomy, $args ) {
	return array( 12, 14 );
}

function wp_get_comment_status( $id ) {
	return 'approved';
}

function get_userdata( $id ) {
	return $GLOBALS['wstm108']['users'][ $id ] ?? false;
}

function get_user_by( $field, $id ) {
	return get_userdata( $id );
}

function current_user_can( $cap, ...$args ) {
	return ! in_array( $cap, $GLOBALS['wstm108']['denied'] ?? array(), true );
}

function get_user_meta( $id, $key, $single ) {
	return $GLOBALS['wstm108']['user_meta'][ $key ] ?? '';
}

function wp_get_attachment_metadata( $id ) {
	return $GLOBALS['wstm108']['attachment_metadata'];
}

function get_attached_file( $id ) {
	return $GLOBALS['wstm108']['file'];
}

function wp_filesize( $path ) {
	$GLOBALS['wstm108']['file_size_paths'][] = $path;
	return '1234';
}

function get_current_user_id() {
	return 9;
}

function current_time( $type, $gmt = false ) {
	return '2026-02-04 00:00:00';
}

function wp_get_attachment_url( $id ) {
	return $GLOBALS['wstm108']['url'];
}

function get_post_meta( $id, $key, $single ) {
	return $GLOBALS['wstm108']['alt'];
}

function wp_strip_all_tags( $content ) {
	return strip_tags( $content );
}

function get_bloginfo( $field ) {
	return 'UTF-8';
}

function serialize_block( $block ) {
	$GLOBALS['wstm108']['serialized_blocks'][] = $block;
	return $GLOBALS['wstm108']['serialized'];
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['wstm108']['abilities'][ $name ] = $args;
}

class WP_Query {
	public $posts;
	public $found_posts;
	public $max_num_pages;

	public function __construct( $args ) {
		$GLOBALS['wstm108']['queries'][] = $args;
		$this->posts = array();
		foreach ( $GLOBALS['wstm_test_posts'] as $post ) {
			if ( $post->post_type === $args['post_type'] ) {
				$this->posts[] = 'ids' === ( $args['fields'] ?? '' ) ? $post->ID : $post;
			}
		}
		$this->found_posts = count( $this->posts );
		$per_page = $args['posts_per_page'] ?? 20;
		$this->max_num_pages = $per_page > 0 ? (int) ceil( $this->found_posts / $per_page ) : 1;
	}
}

class ReferenceDatabase {
	public $postmeta = 'fixture_postmeta';
	public $posts = 'fixture_posts';
	public $last_error = '';
	public $results = array();
	public $queries = array();

	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }

	public function get_var( $query ) {
		if ( array() === $this->results ) { throw new \RuntimeException( 'Unexpected reference query.' ); }
		$this->queries[] = $query;
		$result = array_shift( $this->results );
		$this->last_error = null === $result ? 'PRIVATE fixture database error' : '';
		return $result;
	}
}

class WP_User_Query {
	public function __construct( $args ) {}

	public function get_results() {
		return array_values( $GLOBALS['wstm108']['users'] );
	}

	public function get_total() {
		return count( $this->get_results() );
	}
}

function class_exists( $name ) {
	return 'WP_Application_Passwords' === $name || \class_exists( $name );
}

class WP_Application_Passwords {
	public static function get_user_application_passwords( $id ) {
		return $GLOBALS['wstm108']['application_passwords'];
	}
}

function wp_kses_post( $content ) {
	return $content;
}

// Writes return a configured persisted record, not an echo of request fields.
function wp_insert_post( $args, $error ) {
	$GLOBALS['wstm108']['writes'][] = $args;
	return $GLOBALS['wstm108']['write_id'];
}

function wp_update_post( $args, $error ) {
	$GLOBALS['wstm108']['writes'][] = $args;
	return $args['ID'];
}

function wp_trash_post( $id ) {
	$GLOBALS['wstm108']['trashed'][] = $id;
	$GLOBALS['wstm_test_posts'][ $id ]->post_status = 'trash';
	return $GLOBALS['wstm_test_posts'][ $id ];
}

function wp_get_post_revisions( $id, $args ) {
	return array( get_post( 43 ) );
}

function parse_blocks( $content ) {
	return $GLOBALS['wstm108']['blocks'];
}

function get_comment( $id ) {
	return $GLOBALS['wstm108']['comment'];
}

function get_comments( $args ) {
	return empty( $args['count'] ) ? array( $GLOBALS['wstm108']['comment'] ) : 1;
}

function wp_get_current_user() {
	return get_userdata( 9 );
}

function wp_new_comment( $args, $error ) {
	$GLOBALS['wstm108']['writes'][] = $args;
	return 51;
}

function wp_update_comment( $args, $error ) {
	$GLOBALS['wstm108']['writes'][] = $args;
	return 1;
}
