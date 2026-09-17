<?php

function wstm110_meta_fixtures( $author_id, $editor_id ) {
	$fixtures = array();
	foreach ( array( 'post' => $author_id, 'page' => $editor_id ) as $type => $author ) {
		$id = e2e_insert_post( $type, "WSTM110 {$type}", 'Original metadata authorization content.', $author, 'draft' );
		foreach ( array( 'wstm110_restricted', 'wstm110_open', 'wstm110_edit_only', 'wstm110_global', '_yoast_wpseo_title', '_seopress_titles_title', '_wstm110_hidden' ) as $key ) {
			update_post_meta( $id, $key, 'original' );
		}
		update_post_meta( $id, 'wstm110_gate', 'ready' );
		$fixtures[ "wstm110_{$type}_id" ] = $id;
	}
	return $fixtures;
}

function wstm110_meta_auth( $allowed, $key, $object_id, $user_id, $cap ) {
	$post = get_post( $object_id );
	if ( ! $object_id || ! $post ) {
		throw new RuntimeException( 'Metadata authorization must not evaluate a fabricated object.' );
	}
	return 'draft' === $post->post_status && 'ready' === get_post_meta( $object_id, 'wstm110_gate', true )
		&& ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'wstm110_manage_meta' ) );
}

function wstm110_meta_register( $type, $key, $args, &$registrations ) {
	global $wp_filter;
	$hook            = $type ? "auth_post_meta_{$key}_for_{$type}" : "auth_post_meta_{$key}";
	$registrations[] = array(
		'type' => $type,
		'key' => $key,
		'hook' => $hook,
		'args' => get_registered_meta_keys( 'post', $type )[ $key ] ?? null,
		'filter' => isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null,
	);
	// Isolate this fixture's key policy from the provider callback it temporarily replaces.
	remove_all_filters( $hook );
	if ( null === $args ) {
		unset( $GLOBALS['wp_meta_keys']['post'][ $type ][ $key ] );
	} else {
		register_post_meta( $type, $key, $args );
	}
}

function wstm110_meta_setup( $case ) {
	if ( empty( $case['setup']['wstm110_meta_auth'] ) && empty( $case['setup']['wstm110_state'] ) ) {
		return null;
	}

	$registrations = array();
	$yoast_grant   = false;
	if ( ! empty( $case['setup']['wstm110_meta_auth'] ) ) {
		foreach ( array( 'post', 'page' ) as $type ) {
			foreach ( array( 'wstm110_restricted', 'wstm110_absent', '_yoast_wpseo_title', '_seopress_titles_title' ) as $key ) {
				wstm110_meta_register( $type, $key, array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => 'wstm110_meta_auth' ), $registrations );
			}
			wstm110_meta_register( $type, 'wstm110_open', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ), $registrations );
			wstm110_meta_register(
				$type,
				'wstm110_edit_only',
				array(
					'type' => 'string',
					'single' => true,
					'show_in_rest' => true,
					'auth_callback' => static function ( $allowed, $key, $id, $user_id, $cap ) {
						return 'edit_post_meta' === $cap;
					},
				),
				$registrations
			);
		}
		wstm110_meta_register( '', 'wstm110_global', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => 'wstm110_meta_auth' ), $registrations );
		wstm110_meta_register( 'page', 'wstm110_global', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => '__return_true' ), $registrations );

		if ( ! empty( $case['setup']['wstm110_unregistered_seo'] ) ) {
			foreach ( array( '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw' ) as $key ) {
				foreach ( array( '', 'post', 'page' ) as $type ) {
					wstm110_meta_register( $type, $key, null, $registrations );
				}
			}
		}
		if ( ! empty( $case['setup']['wstm110_extra_auth'] ) ) {
			add_filter( 'auth_post_meta_wstm110_open_for_post', '__return_false', 20 );
		}
		if ( ! empty( $case['setup']['wstm110_map_deny'] ) ) {
			add_filter( 'map_meta_cap', 'wstm110_deny_meta_cap', 100, 4 );
		}
		// Yoast grants the primitive edit_post_meta cap, which core permits to override a false auth result.
		$yoast_grant = has_filter( 'user_has_cap', 'allow_custom_field_edits' );
		if ( false !== $yoast_grant && empty( $case['setup']['wstm110_keep_yoast_grant'] ) ) {
			remove_filter( 'user_has_cap', 'allow_custom_field_edits', $yoast_grant );
		} else {
			$yoast_grant = false;
		}
	}

	$user  = wp_get_current_user();
	$grant = $case['setup']['wstm110_grant'] ?? '';
	if ( $grant ) {
		$user->add_cap( $grant );
	}
	$id = $case['input']['post_id'] ?? $case['input']['page_id'] ?? 0;
	return array(
		'registrations' => $registrations,
		'yoast_grant' => $yoast_grant,
		'map_deny' => ! empty( $case['setup']['wstm110_map_deny'] ),
		'user' => $user,
		'grant' => $grant,
		'id' => $id,
		'post' => $id ? get_post( $id, ARRAY_A ) : null,
		'meta' => $id ? get_post_meta( $id ) : null,
		'ids' => wstm110_post_ids(),
	);
}

function wstm110_post_ids() {
	return get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => array_keys( get_post_stati() ), 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
}

function wstm110_deny_meta_cap( $caps, $cap, $user_id, $args ) {
	if ( in_array( $cap, array( 'edit_post_meta', 'delete_post_meta', 'add_post_meta' ), true ) && '_yoast_wpseo_focuskw' === ( $args[1] ?? '' ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}

function wstm110_meta_finish( $state, $case, $result, &$fixtures ) {
	global $wp_meta_keys, $wp_filter;

	if ( null === $state ) {
		return true;
	}
	$passed = true;
	if ( 'failure' === $case['expect'] ) {
		$id     = $state['id'];
		$passed = $state['ids'] === wstm110_post_ids()
			&& ( ! $id || ( $state['post'] === get_post( $id, ARRAY_A ) && $state['meta'] === get_post_meta( $id ) ) );
		if ( ! $passed ) {
			echo "FAIL metadata denial changed persisted post fields, metadata, or created an object\n";
		}
	}
	if ( 'success' === $case['expect'] && is_array( $result ) && isset( $result['data']['meta']['written'] ) ) {
		foreach ( $result['data']['meta']['written'] as $key => $value ) {
			$passed = $passed && $value === get_post_meta( $result['data']['id'], $key, true );
		}
	}
	if ( ! empty( $case['setup']['wstm110_capture_created'] ) && is_array( $result ) && ! empty( $result['success'] ) ) {
		$fixtures[ $case['setup']['wstm110_capture_created'] ] = $result['data']['id'];
	}
	foreach ( $state['registrations'] as $registration ) {
		$type = $registration['type'];
		$key  = $registration['key'];
		unset( $wp_meta_keys['post'][ $type ][ $key ] );
		remove_all_filters( $registration['hook'] );
		if ( null !== $registration['args'] ) {
			$wp_meta_keys['post'][ $type ][ $key ] = $registration['args'];
		}
		if ( null !== $registration['filter'] ) {
			$wp_filter[ $registration['hook'] ] = $registration['filter'];
		}
	}
	if ( false !== $state['yoast_grant'] ) {
		add_filter( 'user_has_cap', 'allow_custom_field_edits', $state['yoast_grant'], 3 );
	}
	if ( $state['map_deny'] ) {
		remove_filter( 'map_meta_cap', 'wstm110_deny_meta_cap', 100 );
	}
	if ( $state['grant'] ) {
		$state['user']->remove_cap( $state['grant'] );
	}
	return $passed;
}
