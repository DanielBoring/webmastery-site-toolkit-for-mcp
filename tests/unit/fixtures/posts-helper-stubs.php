<?php

namespace Wstm120;

// Isolate controlled parser/encoder boundaries without changing the class body.
$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-posts.php' );
eval( 'namespace Wstm120; use \WP_Error; use \Webmastery_MCP_Response; use \Webmastery_MCP_Untrusted; ' . substr( $source, 5 ) );

function parse_blocks( $content ) {
	if ( ! array_key_exists( $content, $GLOBALS['wstm120_blocks'] ) ) {
		throw new \LogicException( 'Unconfigured unit parser input.' );
	}
	return $GLOBALS['wstm120_blocks'][ $content ];
}

function serialize_blocks( $blocks ) {
	$GLOBALS['wstm120_serialized'] = $blocks;
	return implode( '', array_column( $blocks, 'innerHTML' ) );
}

function wp_strip_all_tags( $content ) {
	return strip_tags( $content );
}

function get_bloginfo( $field ) {
	if ( 'charset' !== $field ) {
		throw new \LogicException( 'Unexpected bloginfo field.' );
	}
	return 'UTF-8';
}

function wp_json_encode( $value ) {
	// No WordPress invalid-UTF8 repair emulation; INF/NAN fail in both encoders.
	return json_encode( $value );
}
