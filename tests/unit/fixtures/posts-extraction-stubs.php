<?php

namespace Wstm127Proof;

require_once __DIR__ . '/shared-helper-loader.php';
require_once __DIR__ . '/posts-extraction-transition.php';
require_once __DIR__ . '/shared-helpers-stubs.php';

final class Registrations {
	public static array $before = array();
	public static array $after = array();
	public static array $reads = array();
}

foreach ( array( 'Wstm127Before', 'Wstm127After' ) as $namespace ) {
	\wstm_test_load_shared_helpers( $namespace, true );
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-posts.php' );
	if ( 'Wstm127Before' === $namespace ) {
		$source = \Wstm127SourceTransition::restore( 'includes/class-posts.php', $source );
	} else {
		$source = \Wstm167SourceTransition::restore( 'includes/class-posts.php', $source );
	}
	eval( 'namespace ' . $namespace . '; use \\WP_Error; use \\Webmastery_MCP_Response; use \\Webmastery_MCP_Post_Parent; use \\Webmastery_MCP_Post_Scheduling; ' . substr( $source, 5 ) );
}

namespace Wstm127Before;

function wp_register_ability( $name, $args ): void { \Wstm127Proof\Registrations::$before[ $name ] = $args; }
function get_post( $post ) {
	\Wstm127Proof\Registrations::$reads[] = $post;
	return \Wstm119Shared\get_post( $post );
}
function current_user_can( $cap, ...$args ): bool { return \Wstm119Shared\current_user_can( $cap, ...$args ); }
function get_current_user_id(): int { return \Wstm119Shared\get_current_user_id(); }

namespace Wstm127After;

function wp_register_ability( $name, $args ): void { \Wstm127Proof\Registrations::$after[ $name ] = $args; }
function get_post( $post ) {
	\Wstm127Proof\Registrations::$reads[] = $post;
	return \Wstm119Shared\get_post( $post );
}
function current_user_can( $cap, ...$args ): bool { return \Wstm119Shared\current_user_can( $cap, ...$args ); }
function get_current_user_id(): int { return \Wstm119Shared\get_current_user_id(); }
