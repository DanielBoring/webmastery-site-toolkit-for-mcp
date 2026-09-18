<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Post_Parent {

	public static function validate( $post_type, $parent_id, $post_id = 0, $edit_cap = 'edit_post' ) {
		if ( 0 === $parent_id ) {
			return true;
		}

		$parent = get_post( $parent_id );
		if ( ! is_post_type_hierarchical( $post_type ) || ! $parent || $parent->post_type !== $post_type ) {
			return new WP_Error( 'invalid_parent', 'Parent must be an existing item of the same hierarchical post type.' );
		}
		if ( ! current_user_can( $edit_cap, $parent_id ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to edit the requested parent.' );
		}

		$seen = [];
		while ( $parent ) {
			$ancestor_id = (int) $parent->ID;
			if ( $ancestor_id === $post_id || isset( $seen[ $ancestor_id ] ) ) {
				return new WP_Error( 'invalid_parent', 'Parent must not create a cycle or belong to an existing cyclic hierarchy.' );
			}
			$seen[ $ancestor_id ] = true;
			if ( ! $parent->post_parent ) {
				break;
			}
			// Do not call core's mutating hierarchy repair or require ancestor permissions.
			$parent = get_post( (int) $parent->post_parent );
		}

		return true;
	}
}
