<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-content-wire.php';
require_once __DIR__ . '/destructive-safety-boot.php';
require_once __DIR__ . '/untrusted-content-runtime.php';
require_once __DIR__ . '/untrusted-content-provenance.php';

function wstm108_runtime_snapshot(): array {
	if ( ! class_exists( \WP\MCP\Core\McpAdapter::class ) || '0.6.1' !== \WP\MCP\Core\McpAdapter::VERSION ) {
		throw new RuntimeException( 'WSTM108 Runtime requires the installed Adapter 0.6.1.' );
	}
	// Both the CLI and HTTP probe initialize the same real REST/Adapter registries.
	rest_get_server();
	$abilities = array();
	foreach ( wp_get_abilities() as $ability ) {
		$abilities[ $ability->get_name() ] = array(
			'label' => $ability->get_label(), 'description' => $ability->get_description(),
			'input' => $ability->get_input_schema(), 'output' => $ability->get_output_schema(), 'meta' => $ability->get_meta(),
		);
	}
	ksort( $abilities, SORT_STRING );
	$servers = array();
	foreach ( \WP\MCP\Core\McpAdapter::instance()->get_servers() as $id => $server ) {
		$components = array();
		foreach ( array( 'tools' => $server->get_tools(), 'resources' => $server->get_resources(), 'prompts' => $server->get_prompts() ) as $kind => $entries ) {
			$components[ $kind ] = array_map( static fn( $entry ) => $entry->toArray(), $entries );
			ksort( $components[ $kind ], SORT_STRING );
		}
		$servers[ $id ] = array(
			'namespace' => $server->get_server_route_namespace(), 'route' => $server->get_server_route(),
			'name' => $server->get_server_name(), 'description' => $server->get_server_description(),
			'version' => $server->get_server_version(), 'validation' => $server->is_mcp_validation_enabled(),
			'components_sha256' => hash( 'sha256', wstm108_wire_canonical( $components ) ),
			'component_counts' => array_map( 'count', $components ),
			'observer_class' => get_class( $server->get_observability_handler() ),
			'observer_state_sha256' => hash( 'sha256', serialize( $server->get_observability_handler() ) ),
			'error_class' => get_class( $server->get_error_handler() ),
			'error_state_sha256' => hash( 'sha256', serialize( $server->get_error_handler() ) ),
		);
	}
	ksort( $servers, SORT_STRING );
	$observers = array();
	foreach ( Wstm108_Runtime::OBSERVERS as $hook ) {
		$observers[ $hook ] = array();
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( is_array( $callback ) ) {
					$function = new ReflectionMethod( $callback[0], $callback[1] );
					$name = ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) . '::' . $callback[1];
				} elseif ( is_object( $callback ) && ! $callback instanceof Closure ) {
					$function = new ReflectionMethod( $callback, '__invoke' );
					$name = get_class( $callback ) . '::__invoke';
				} else {
					$function = new ReflectionFunction( $callback );
					$name = $function->getName();
				}
				$file = $function->getFileName();
				$observers[ $hook ][] = array(
					'priority' => $priority, 'accepted_args' => $entry['accepted_args'], 'callback' => $name,
					'file' => $file, 'line' => $function->getStartLine(),
					'source_sha256' => false === $file ? null : hash_file( 'sha256', $file ),
				);
			}
		}
	}
	return array(
		'optin_defined' => defined( 'WSTM108_ALLOW_DISPOSABLE' ),
		'optin' => defined( 'WSTM108_ALLOW_DISPOSABLE' ) ? WSTM108_ALLOW_DISPOSABLE : null,
		'owner_defined' => defined( 'WSTM108_OWNER' ), 'owner' => defined( 'WSTM108_OWNER' ) ? WSTM108_OWNER : null,
		'abilities' => array_map( static fn( $schema ) => hash( 'sha256', wstm108_wire_canonical( $schema ) ), $abilities ),
		'servers' => $servers, 'observers' => $observers,
		'wordpress' => get_bloginfo( 'version' ), 'adapter' => \WP\MCP\Core\McpAdapter::VERSION,
		'loaded_classes' => Wstm108_Provenance::loaded_root( str_replace( '\\', '/', realpath( dirname( __DIR__, 2 ) ) ) ),
		'active_plugins' => get_option( 'active_plugins' ),
	);
}
