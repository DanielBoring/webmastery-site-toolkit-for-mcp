<?php

function wstm_test_product_binding( string $path ): array {
	$json = file_get_contents( __DIR__ . '/product-source-bindings.json' );
	if ( ! is_string( $json ) || 'e7c28b01b49bf53688451d1caf8e40b544c83aec9fe04c970851fcc996e8c71d' !== hash( 'sha256', $json ) ) {
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
