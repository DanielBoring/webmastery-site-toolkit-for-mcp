<?php
/**
 * Disposable standalone metadata authorization fixtures, never a production plugin.
 */

defined( 'ABSPATH' ) || exit;

function wstm110_auth( $allowed, $key, $id, $user_id, $cap ) {
	$post = get_post( $id );
	if ( ! $id || ! $post ) {
		throw new RuntimeException( 'Metadata authorization requires a persisted object.' );
	}
	if ( 'wstm110_edit_only' === $key ) {
		return 'edit_post_meta' === $cap;
	}
	return 'draft' === $post->post_status && 'ready' === get_post_meta( $id, 'wstm110_gate', true )
		&& ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'wstm110_manage_meta' ) );
}

function wstm110_setup( $config = array() ) {
	global $wp_filter;
	$saved = array();
	$definitions = array(
		array( '', 'wstm110_global', 'wstm110_auth' ),
		array( 'page', 'wstm110_global', '__return_true' ),
	);
	foreach ( array( 'post', 'page' ) as $type ) {
		foreach ( array( 'wstm110_restricted', 'wstm110_absent', 'wstm110_edit_only', 'wstm110_open' ) as $key ) {
			$definitions[] = array( $type, $key, 'wstm110_open' === $key ? '__return_true' : 'wstm110_auth' );
		}
	}
	if ( ! empty( $config['provider_policy'] ) ) {
		foreach ( array( '', 'post', 'page' ) as $type ) {
			$definitions[] = array( $type, $config['key'], 'unregistered' === $config['provider_policy'] ? null : 'wstm110_auth' );
		}
	}
	foreach ( $definitions as list( $type, $key, $callback ) ) {
		$hooks = $type ? array( "auth_post_meta_{$key}_for_{$type}", "auth_post_{$type}_meta_{$key}" ) : array( "auth_post_meta_{$key}" );
		$entry = array( 'type' => $type, 'key' => $key, 'args' => get_registered_meta_keys( 'post', $type )[ $key ] ?? null, 'hooks' => array() );
		foreach ( $hooks as $hook ) {
			$entry['hooks'][ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
			remove_all_filters( $hook );
		}
		$saved[] = $entry;
		unset( $GLOBALS['wp_meta_keys']['post'][ $type ][ $key ] );
		if ( null !== $callback ) {
			register_post_meta( $type, $key, array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => $callback ) );
		}
	}
	return $saved;
}

function wstm110_restore( $saved ) {
	global $wp_filter, $wp_meta_keys;
	foreach ( array_reverse( $saved ) as $entry ) {
		unset( $wp_meta_keys['post'][ $entry['type'] ][ $entry['key'] ] );
		if ( null !== $entry['args'] ) {
			$wp_meta_keys['post'][ $entry['type'] ][ $entry['key'] ] = $entry['args'];
		}
		foreach ( $entry['hooks'] as $hook => $filter ) {
			remove_all_filters( $hook );
			if ( null !== $filter ) {
				$wp_filter[ $hook ] = $filter;
			}
		}
	}
}

function wstm110_rule_matches( $cap, $id, $key ) {
	$config = get_option( 'wstm110_policy', array() );
	return in_array( $cap, array( 'edit_post_meta', 'delete_post_meta', 'add_post_meta' ), true )
		&& (int) ( $config['id'] ?? 0 ) === (int) $id && ( $config['key'] ?? '' ) === $key;
}

add_filter( 'map_meta_cap', static function ( $caps, $cap, $user_id, $args ) {
	$config = get_option( 'wstm110_policy', array() );
	if ( ! empty( $config['deny_map'] ) && wstm110_rule_matches( $cap, $args[0] ?? 0, $args[1] ?? '' ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}, PHP_INT_MAX, 4 );

add_filter( 'user_has_cap', static function ( $allcaps, $caps, $args ) {
	$config = get_option( 'wstm110_policy', array() );
	if ( wstm110_rule_matches( $args[0], $args[2] ?? 0, $args[3] ?? '' ) ) {
		if ( ! empty( $config['throw'] ) ) {
			throw new RuntimeException( 'wstm110 expected capability exception' );
		}
		if ( ! empty( $config['deny_user'] ) ) {
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
	}
	return $allcaps;
}, PHP_INT_MAX, 3 );

foreach ( array( 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata' ) as $hook ) {
	add_filter( $hook, static function ( $value, $id, $key ) use ( $hook ) {
		$config = get_option( 'wstm110_policy', array() );
		if ( (int) ( $config['id'] ?? 0 ) === (int) $id ) {
			$events = get_option( 'wstm110_events', array() );
			$events[] = array( $hook, $id, $key );
			update_option( 'wstm110_events', $events, false );
		}
		return $value;
	}, 1, 3 );
}

add_action( 'init', static function () {
	wstm110_setup( get_option( 'wstm110_policy', array() ) );
}, PHP_INT_MAX );
