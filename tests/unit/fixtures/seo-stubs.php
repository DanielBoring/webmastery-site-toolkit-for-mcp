<?php

namespace Wstm108;

// Isolate WordPress boundaries using the repository's helper-test pattern.
$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-seo.php' );
eval( 'namespace Wstm108; use \WP_Error; ' . substr( $source, 5 ) );

function wp_strip_all_tags( $content ) {
	return strip_tags( $content );
}

function get_permalink( $id ) {
	return 'https://example.test/?p=' . $id;
}

function home_url() {
	return 'https://example.test';
}

function get_option( $name, $default = false ) {
	if ( 'active_plugins' !== $name ) {
		throw new \LogicException( 'Unexpected SEO option read.' );
	}
	return $default;
}
