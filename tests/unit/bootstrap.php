<?php

declare(strict_types=1);

define( 'ABSPATH', dirname(__DIR__, 2) . '/' );

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

require_once dirname(__DIR__, 2) . '/includes/class-posts.php';
