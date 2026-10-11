<?php

function wstm_test_load_shared_helpers( string $namespace ): void {
	if ( ! class_exists( $namespace . '\\Webmastery_MCP_Input', false ) ) {
		class_alias( Webmastery_MCP_Input::class, $namespace . '\\Webmastery_MCP_Input' );
	}
	foreach ( array( 'untrusted', 'list-query', 'permissions', 'post-access', 'post-content', 'post-meta', 'post-writes', 'bulk-posts', 'post-revisions', 'featured-image', 'content-patch' ) as $file ) {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-' . $file . '.php' );
		preg_match( '/final class (Webmastery_MCP_\w+)/', $source, $matches );
		if ( ! class_exists( $namespace . '\\' . $matches[1], false ) ) {
			eval( 'namespace ' . $namespace . '; use \\Closure; use \\WP_Error; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) );
		}
	}
}

function wstm_test_main_baseline( ?string $json = null ): array {
	$json = $json ?? file_get_contents( __DIR__ . '/product-main-baseline.json' );
	if ( ! is_string( $json ) || ! hash_equals( '81c8867e792974605d18bb39ec9f7ad1596601fff1c25a8e2ad341104814d12e', hash( 'sha256', $json ) ) ) {
		throw new RuntimeException( 'Independent main semantic baseline changed.' );
	}
	$baseline = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	if ( 'wstm-product-main-baseline-v1' !== $baseline['schema']
		|| 'c135420c10615616a49e27eb703f955f1ea1f228' !== $baseline['main_commit'] || 10 !== count( $baseline['files'] ) ) {
		throw new RuntimeException( 'Independent main semantic baseline binding failed.' );
	}
	foreach ( $baseline['files'] as $file ) {
		if ( ! hash_equals( $file['class_sha256'], hash( 'sha256', $file['class_source'] ) ) ) {
			throw new RuntimeException( 'Independent main semantic class changed.' );
		}
	}
	return $baseline;
}

function wstm_test_load_main_baseline( string $namespace, string $path ): void {
	$baseline = wstm_test_main_baseline();
	if ( ! isset( $baseline['files'][ $path ] ) ) {
		throw new RuntimeException( 'Unselected main semantic class.' );
	}
	eval( 'namespace ' . $namespace . '; use \\WP_Error; use \\Webmastery_MCP_Response; use \\Webmastery_MCP_Post_Parent; use \\Webmastery_MCP_Post_Scheduling; ' . $baseline['files'][ $path ]['class_source'] );
}
