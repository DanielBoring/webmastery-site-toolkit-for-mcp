<?php
/**
 * Issue #106 fixture-only types, capability filters, and write observation.
 * Installed temporarily as an MU plugin only in disposable parent QA runtimes.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'doing_it_wrong_trigger_error',
	static function ( $trigger, $function, $message ) {
		if ( 'WP_Ability::execute' === $function && str_starts_with( $message, 'Requires ' ) ) {
			$GLOBALS['wstm106_permission_notices'][] = $message;
			return false;
		}
		return $trigger;
	},
	10,
	3
);

add_action(
	'init',
	static function () {
		foreach ( array( 'default', 'mapped', 'explicit', 'primitive' ) as $mode ) {
			$args = array(
				'public'       => true,
				'show_ui'      => true,
				'hierarchical' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'revisions' ),
				'map_meta_cap' => 'primitive' !== $mode,
			);
			if ( 'default' !== $mode ) {
				$args['capability_type'] = array( 'wstm106_item', 'wstm106_items' );
			}
			if ( in_array( $mode, array( 'explicit', 'primitive' ), true ) ) {
				$args['capabilities'] = array(
					'edit_post'    => 'wstm106_edit_' . $mode,
					'create_posts' => 'wstm106_create_' . $mode,
				);
			}
			register_post_type( 'wstm106_' . $mode, $args );
			register_taxonomy(
				'wstm106_' . $mode . '_tag',
				array( 'wstm106_' . $mode ),
				array( 'public' => false, 'capabilities' => array( 'assign_terms' => 'read' ) )
			);
		}
	},
	0
);

add_filter(
	'user_has_cap',
	static function ( $allcaps, $caps, $args ) {
		$rule = get_option( 'wstm106_cap_rule', array() );
		if ( $rule && $args[0] === $rule['cap'] && (int) ( $args[2] ?? 0 ) === $rule['id'] ) {
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = $rule['allow'];
			}
		}
		return $allcaps;
	},
	PHP_INT_MAX,
	3
);

function wstm106_observe( $value = null, ...$args ) {
	if ( ! empty( $GLOBALS['wstm106_observing'] ) ) {
		$GLOBALS['wstm106_hooks'][] = array( 'hook' => current_filter(), 'args' => array_merge( array( $value ), $args ) );
	}
	return $value;
}

foreach ( array(
	'wp_insert_post_parent',
	'wp_insert_post_data',
	'pre_post_update',
	'post_updated',
	'save_post',
	'wp_after_insert_post',
	'add_post_metadata',
	'update_post_metadata',
	'delete_post_metadata',
	'added_post_meta',
	'updated_post_meta',
	'deleted_post_meta',
	'add_term_relationship',
	'added_term_relationship',
	'delete_term_relationships',
	'deleted_term_relationships',
	'set_object_terms',
	'pre_schedule_event',
	'pre_unschedule_event',
) as $hook ) {
	add_filter( $hook, 'wstm106_observe', 1, 5 );
}

// A per-run token scopes observation to actual QA requests, not other traffic.
$wstm106_token = get_option( 'wstm106_trace_token', '' );
if ( $wstm106_token && hash_equals( $wstm106_token, $_SERVER['HTTP_X_WSTM106_TRACE'] ?? '' ) ) {
	$GLOBALS['wstm106_observing'] = true;
	$GLOBALS['wstm106_hooks']     = array();
	register_shutdown_function(
		static function () {
			$json = wp_json_encode( $GLOBALS['wstm106_hooks'], JSON_THROW_ON_ERROR );
			if ( false === file_put_contents( '/tmp/wstm106-http-hooks.json', $json ) ) {
				throw new RuntimeException( 'Required issue #106 HTTP hook evidence could not be written.' );
			}
		}
	);
}
