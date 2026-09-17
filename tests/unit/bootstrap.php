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

/**
 * Minimal, test-controlled taxonomy stubs used only by CustomPostTypesHelpersTest.
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

function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['wstm_test_user_caps'] ?? array(), true );
}

require_once dirname(__DIR__, 2) . '/includes/class-post-scheduling.php';
require_once dirname(__DIR__, 2) . '/includes/class-posts.php';
require_once dirname(__DIR__, 2) . '/includes/class-custom-post-types.php';
