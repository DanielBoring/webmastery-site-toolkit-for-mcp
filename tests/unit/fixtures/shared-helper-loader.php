<?php

function wstm_test_load_shared_helpers( string $namespace, bool $historical = false ): void {
	if ( ! class_exists( $namespace . '\\Webmastery_MCP_Input', false ) ) {
		class_alias( Webmastery_MCP_Input::class, $namespace . '\\Webmastery_MCP_Input' );
	}
	foreach ( array( 'untrusted', 'list-query', 'permissions', 'post-access', 'post-content', 'post-meta', 'post-writes', 'bulk-posts', 'post-revisions', 'featured-image', 'content-patch' ) as $file ) {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-' . $file . '.php' );
		if ( $historical ) {
			$source = Wstm167SourceTransition::restore( 'includes/class-' . $file . '.php', $source );
		}
		preg_match( '/final class (Webmastery_MCP_\w+)/', $source, $matches );
		if ( ! class_exists( $namespace . '\\' . $matches[1], false ) ) {
			eval( 'namespace ' . $namespace . '; use \\Closure; use \\WP_Error; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) );
		}
	}
}
