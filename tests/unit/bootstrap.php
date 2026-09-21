<?php

declare(strict_types=1);

define( 'ABSPATH', dirname(__DIR__, 2) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

function wp_timezone(): DateTimeZone {
	return new DateTimeZone( $GLOBALS['wstm_test_timezone'] ?? 'UTC' );
}

function wp_date( $format, $timestamp ): string {
	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( $format );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $data;

		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

require_once dirname(__DIR__, 2) . '/includes/class-response.php';

function sanitize_key( $key ): string {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
}

function sanitize_text_field( $value ): string {
	$value = (string) $value;
	$value = strip_tags( $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? '';
	return trim( $value );
}

function sanitize_textarea_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function absint( $value ): int {
	return max( 0, abs( (int) $value ) );
}

function rest_sanitize_boolean( $value ): bool {
	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( is_string( $value ) ) {
		return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	return (bool) $value;
}

function esc_url_raw( $url ): string {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}

	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}

	return is_string( $value ) ? addslashes( $value ) : $value;
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['wstm_test_meta_writes'][] = array( $post_id, $key, $value );
	return true;
}

function get_post_meta( $post_id, $key, $single = false ) {
	return $GLOBALS['wstm_test_stored_meta'][ $post_id ][ $key ] ?? '';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

/**
 * Minimal, test-controlled taxonomy stubs for CPT helpers and taxonomy writes.
 *
 * $GLOBALS['wstm_test_taxonomies'] maps a taxonomy name to either `false` (not
 * registered) or an object with an `object_types` array and a `cap->assign_terms`
 * capability name, mirroring the subset of `get_taxonomy()`/`is_object_in_taxonomy()`
 * behavior that `validate_taxonomy_terms()` relies on.
 *
 * $GLOBALS['wstm_test_user_caps'] lists the capabilities the fake current user has.
 */
function get_taxonomy( $taxonomy ) {
	return $GLOBALS['wstm_test_taxonomies'][ $taxonomy ] ?? false;
}

function is_object_in_taxonomy( $post_type, $taxonomy ) {
	$taxonomy_object = $GLOBALS['wstm_test_taxonomies'][ $taxonomy ] ?? false;
	if ( ! $taxonomy_object ) {
		return false;
	}

	return in_array( $post_type, $taxonomy_object->object_types ?? array(), true );
}

function current_user_can( $capability, ...$args ) {
	if ( isset( $GLOBALS['wstm_test_cap_calls'] ) ) {
		$GLOBALS['wstm_test_cap_calls'][] = array_merge( array( $capability ), $args );
	}
	if ( isset( $GLOBALS['wstm_test_object_capability'] ) ) {
		return ( $GLOBALS['wstm_test_object_capability'] )( $capability, ...$args );
	}
	return in_array( $capability, $GLOBALS['wstm_test_user_caps'] ?? array(), true );
}

function get_post( $id ) {
	return $GLOBALS['wstm_test_posts'][ $id ] ?? null;
}

function is_post_type_hierarchical( $type ): bool {
	return in_array( $type, $GLOBALS['wstm_test_hierarchical_types'] ?? array(), true );
}

require_once dirname(__DIR__, 2) . '/includes/class-post-parent.php';

function wp_register_ability( $name, $args ) {
	$GLOBALS['wstm_test_abilities'][ $name ] = $args;
}

function get_term( $id, $taxonomy = '' ) {
	return $GLOBALS['wstm_test_terms'][ $taxonomy ][ $id ] ?? null;
}

// Controlled return values only; real deletion and meta-cap mapping are covered in Docker.
function wp_delete_term( $id, $taxonomy ) {
	$GLOBALS['wstm_test_delete_calls'][] = array( $id, $taxonomy );
	return $GLOBALS['wstm_test_delete_result'];
}

require_once dirname(__DIR__, 2) . '/includes/class-post-scheduling.php';
require_once dirname(__DIR__, 2) . '/includes/class-posts.php';
require_once dirname(__DIR__, 2) . '/includes/class-custom-post-types.php';
require_once dirname(__DIR__, 2) . '/includes/class-taxonomy.php';
