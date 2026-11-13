<?php
/**
 * Loads the production navigation menu abilities into a namespace with a scripted menu store,
 * so they can be tested without colliding with global stubs from other suites.
 * State lives in $GLOBALS['wstm_menus']; MenusTest resets it before each test.
 *
 * wp_setup_nav_menu_item() and wp_update_nav_menu_item() follow core semantics closely enough to
 * prove the ability's contract: the update stub resets every omitted argument to core's default,
 * exactly like wp-includes/nav-menu.php, so a dropped argument shows up as a lost stored value.
 */

namespace WstmMenus;

require_once __DIR__ . '/shared-helper-loader.php';
\wstm_test_load_shared_helpers( __NAMESPACE__ );

/** Minimal WP_Post stand-in. */
class WP_Post {
	public $ID           = 0;
	public $post_type    = 'nav_menu_item';
	public $post_title   = '';
	public $post_content = '';
	public $post_excerpt = '';
	public $post_status  = 'publish';
	public $menu_order   = 0;
	public $post_date     = '2026-01-02 03:04:05';
	public $post_date_gmt = '2026-01-02 08:04:05';

	public function __construct( array $fields = array() ) {
		foreach ( $fields as $key => $value ) {
			$this->$key = $value;
		}
	}
}

/** Minimal WP_Term stand-in. */
class WP_Term {
	public $term_id  = 0;
	public $name     = '';
	public $slug     = '';
	public $taxonomy = 'nav_menu';
	public $count    = 0;

	public function __construct( array $fields = array() ) {
		foreach ( $fields as $key => $value ) {
			$this->$key = $value;
		}
	}
}

function wstm_menus_state(): array {
	return array(
		'caps'           => array( 'edit_theme_options' ),
		// Post IDs the current user cannot read (read_post denied).
		'unreadable'     => array(),
		// Menu items for which edit_post is denied.
		'uneditable'     => array(),
		'posts'          => array(),
		'meta'           => array(),
		'terms'          => array(),
		'object_terms'   => array(),
		'registered'     => array(),
		'locations'      => array(),
		'theme_supports' => true,
		'block_theme'    => false,
		'registered_abilities' => array(),
		'updates'        => array(),
		'menus_args'     => array(),
		'empty_url'      => false,
	);
}

function current_user_can( $capability, ...$args ) {
	$state = $GLOBALS['wstm_menus'];
	if ( 'read_post' === $capability ) {
		return ! in_array( (int) ( $args[0] ?? 0 ), $state['unreadable'], true );
	}
	if ( 'edit_post' === $capability ) {
		return in_array( 'edit_theme_options', $state['caps'], true ) && ! in_array( (int) ( $args[0] ?? 0 ), $state['uneditable'], true );
	}
	return in_array( $capability, $state['caps'], true );
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['wstm_menus']['registered_abilities'][ $name ] = $args;
}

function current_theme_supports( $feature ) {
	return 'menus' === $feature && $GLOBALS['wstm_menus']['theme_supports'];
}

function wp_is_block_theme() {
	return $GLOBALS['wstm_menus']['block_theme'];
}

function get_registered_nav_menus() {
	return $GLOBALS['wstm_menus']['registered'];
}

function get_nav_menu_locations() {
	return $GLOBALS['wstm_menus']['locations'];
}

function wp_get_nav_menus( $args = array() ) {
	$GLOBALS['wstm_menus']['menus_args'][] = $args;
	$menus = array_values( array_filter( $GLOBALS['wstm_menus']['terms'], static fn( $term ) => 'nav_menu' === $term->taxonomy ) );
	usort( $menus, static fn( $a, $b ) => strcmp( $a->name, $b->name ) );
	return isset( $args['number'] ) ? array_slice( $menus, 0, $args['number'] ) : $menus;
}

function get_term( $id, $taxonomy = '' ) {
	$term = $GLOBALS['wstm_menus']['terms'][ $id ] ?? null;
	return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? clone $term : null;
}

function wp_get_nav_menu_object( $menu ) {
	throw new \LogicException( 'Menus must resolve menus by term ID, never by name or slug.' );
}

function get_post( $id ) {
	$post = $GLOBALS['wstm_menus']['posts'][ (int) $id ] ?? null;
	return $post ? clone $post : null;
}

function get_post_field( $field, $id ) {
	$post = get_post( $id );
	return $post ? $post->$field : '';
}

function get_post_meta( $post_id, $key, $single = false ) {
	return $GLOBALS['wstm_menus']['meta'][ (int) $post_id ][ $key ] ?? '';
}

function wp_get_object_terms( $object_ids, $taxonomies, $args = array() ) {
	return $GLOBALS['wstm_menus']['object_terms'][ (int) $object_ids ] ?? array();
}

function get_permalink( $id ) {
	return 'https://example.test/?p=' . (int) $id;
}

function wp_setup_nav_menu_item( $menu_item ) {
	$item                   = clone $menu_item;
	$id                     = (int) $item->ID;
	$item->db_id            = $id;
	$item->menu_item_parent = (string) get_post_meta( $id, '_menu_item_menu_item_parent', true );
	$item->object_id        = (string) get_post_meta( $id, '_menu_item_object_id', true );
	$item->object           = (string) get_post_meta( $id, '_menu_item_object', true );
	$item->type             = (string) get_post_meta( $id, '_menu_item_type', true );
	if ( 'post_type' === $item->type ) {
		$object      = get_post( (int) $item->object_id );
		$item->url   = get_permalink( (int) $item->object_id );
		$item->title = '' === $item->post_title && $object ? $object->post_title : $item->post_title;
	} elseif ( 'taxonomy' === $item->type ) {
		$term        = get_term( (int) $item->object_id );
		$item->url   = 'https://example.test/?cat=' . (int) $item->object_id;
		$item->title = '' === $item->post_title && $term ? $term->name : $item->post_title;
	} else {
		$item->url   = (string) get_post_meta( $id, '_menu_item_url', true );
		$item->title = $item->post_title;
	}
	$item->target      = (string) get_post_meta( $id, '_menu_item_target', true );
	$item->attr_title  = $item->post_excerpt;
	$item->description = $item->post_content;
	$item->classes     = (array) get_post_meta( $id, '_menu_item_classes', true );
	$item->xfn         = (string) get_post_meta( $id, '_menu_item_xfn', true );
	return $item;
}

function wp_get_nav_menu_items( $menu, $args = array() ) {
	$term_id = $menu instanceof WP_Term ? (int) $menu->term_id : (int) $menu;
	$items   = array();
	foreach ( $GLOBALS['wstm_menus']['posts'] as $id => $post ) {
		if ( 'nav_menu_item' === $post->post_type && 'publish' === $post->post_status
			&& in_array( $term_id, $GLOBALS['wstm_menus']['object_terms'][ $id ] ?? array(), true ) ) {
			$items[] = wp_setup_nav_menu_item( get_post( $id ) );
		}
	}
	usort( $items, static fn( $a, $b ) => array( $a->menu_order, $a->ID ) <=> array( $b->menu_order, $b->ID ) );
	// Core renumbers menu_order 1..n on the returned objects (ARRAY_A output).
	foreach ( $items as $index => $item ) {
		$item->menu_order = $index + 1;
	}
	return $items;
}

/** Core-like update: omitted arguments fall back to core's defaults and overwrite stored values. */
function wp_update_nav_menu_item( $menu_id = 0, $menu_item_db_id = 0, $menu_item_data = array(), $fire_after_hooks = true ) {
	$GLOBALS['wstm_menus']['updates'][] = array( $menu_id, $menu_item_db_id, $menu_item_data );
	$defaults = array(
		'menu-item-db-id'       => $menu_item_db_id,
		'menu-item-object-id'   => 0,
		'menu-item-object'      => '',
		'menu-item-parent-id'   => 0,
		'menu-item-position'    => 0,
		'menu-item-type'        => 'custom',
		'menu-item-title'       => '',
		'menu-item-url'         => '',
		'menu-item-description' => '',
		'menu-item-attr-title'  => '',
		'menu-item-target'      => '',
		'menu-item-classes'     => '',
		'menu-item-xfn'         => '',
		'menu-item-status'      => '',
	);
	$args = array_map( static fn( $value ) => is_string( $value ) ? stripslashes( $value ) : $value, array_merge( $defaults, $menu_item_data ) );
	if ( isset( $GLOBALS['wstm_menus']['update_error'] ) ) {
		return $GLOBALS['wstm_menus']['update_error'];
	}
	$id   = (int) $menu_item_db_id;
	$post = $GLOBALS['wstm_menus']['posts'][ $id ];
	if ( 'custom' === $args['menu-item-type'] ) {
		$args['menu-item-object-id'] = $id;
		$args['menu-item-object']    = 'custom';
	} else {
		$args['menu-item-url'] = '';
	}
	$post->post_title   = $args['menu-item-title'];
	$post->post_content = $args['menu-item-description'];
	$post->post_excerpt = $args['menu-item-attr-title'];
	$post->menu_order   = (int) $args['menu-item-position'];
	$post->post_status  = 'draft' === $args['menu-item-status'] ? 'draft' : 'publish';
	$GLOBALS['wstm_menus']['meta'][ $id ] = array(
		'_menu_item_type'             => $args['menu-item-type'],
		'_menu_item_menu_item_parent' => (string) (int) $args['menu-item-parent-id'],
		'_menu_item_object_id'        => (string) (int) $args['menu-item-object-id'],
		'_menu_item_object'           => $args['menu-item-object'],
		'_menu_item_target'           => $args['menu-item-target'],
		'_menu_item_classes'          => explode( ' ', $args['menu-item-classes'] ),
		'_menu_item_xfn'              => $args['menu-item-xfn'],
		'_menu_item_url'              => $args['menu-item-url'],
	);
	$GLOBALS['wstm_menus']['object_terms'][ $id ] = array( (int) $menu_id );
	return $id;
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function sanitize_html_class( $classname, $fallback = '' ) {
	$sanitized = preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $classname );
	$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $sanitized );
	return '' === $sanitized && $fallback ? sanitize_html_class( $fallback ) : $sanitized;
}

/** Core-like esc_url_raw(): protocol allowlist plus character stripping; scripted to return '' on demand. */
function esc_url_raw( $url, $protocols = null ) {
	$GLOBALS['wstm_menus']['esc_url_raw'][] = array( $url, $protocols );
	if ( $GLOBALS['wstm_menus']['empty_url'] ) {
		return '';
	}
	$url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', (string) $url );
	if ( 0 !== stripos( $url, 'mailto:' ) ) {
		// Core's _deep_replace() of %0d/%0a.
		do {
			$before = $url;
			$url    = str_ireplace( array( '%0d', '%0a' ), '', $url );
		} while ( $before !== $url );
	}
	$url = str_replace( ';//', '://', $url );
	if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $url, $matches ) && ! in_array( strtolower( $matches[1] ), (array) $protocols, true ) ) {
		return '';
	}
	return $url;
}

foreach ( array( 'wp_delete_post', 'wp_insert_post', 'wp_update_post', 'wp_set_object_terms', 'wp_delete_nav_menu', 'wp_update_nav_menu_object', 'wp_create_nav_menu', 'set_theme_mod', 'update_option', 'update_post_meta', 'delete_post_meta' ) as $forbidden ) {
	eval( 'namespace ' . __NAMESPACE__ . '; function ' . $forbidden . '( ...$args ) { throw new \LogicException( "Menus must not call ' . $forbidden . '." ); }' );
}

$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-menus.php' );
eval( 'namespace ' . __NAMESPACE__ . '; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) );
