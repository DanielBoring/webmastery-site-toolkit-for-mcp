<?php

function wstm_test_product_binding( string $path ): array {
	$json = file_get_contents( __DIR__ . '/product-source-bindings.json' );
	if ( ! is_string( $json ) || '176a113c332949538d3528e07b876f95e30461d1fa5fe5afeea74744ae857ac5' !== hash( 'sha256', $json ) ) {
		throw new RuntimeException( 'Finite product calibration identities changed.' );
	}
	$bindings = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	if ( ! isset( $bindings[ $path ] ) ) {
		throw new RuntimeException( 'Unselected product calibration source.' );
	}
	return $bindings[ $path ];
}

function wstm_test_assert_product_source( string $path, string $source ): void {
	$source = str_replace( "\r\n", "\n", $source );
	$binding = wstm_test_product_binding( $path );
	if ( ! hash_equals( $binding[0], hash( 'sha256', $source ) )
		|| ! hash_equals( $binding[1], sha1( 'blob ' . strlen( $source ) . "\0" . $source ) ) ) {
		throw new RuntimeException( 'Product calibration source changed: ' . $path );
	}
}
