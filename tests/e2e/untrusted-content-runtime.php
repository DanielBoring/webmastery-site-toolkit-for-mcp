<?php

declare(strict_types=1);

final class Wstm108_Runtime {
	public const OBSERVERS = array(
		'mcp_adapter_init', 'mcp_adapter_tool_name', 'mcp_adapter_tools_list', 'mcp_adapter_pre_tool_call',
		'mcp_adapter_post_tool_call', 'wp_before_execute_ability', 'wp_after_execute_ability', 'map_meta_cap', 'get_post_metadata',
		'notify_post_author', 'notify_moderator', 'rest_post_dispatch', 'init', 'wp_abilities_api_init', 'doing_it_wrong_trigger_error',
		'wpseo_head', 'wpseo_frontend_presenters', 'seopress_head', 'seopress_titles_title', 'seopress_titles_desc',
	);

	public static function additions(): array {
		$names = array( 'wstm118-probe', 'wstm118-bad-execute', 'wstm118-bad-permission', 'wstm118-missing-schema' );
		foreach ( array( 'list', 'get', 'create', 'update', 'delete' ) as $operation ) {
			$names[] = $operation . '-cpt-wstm108-record';
		}
		$names = array_map( static fn( $name ) => 'webmastery-site-toolkit-for-mcp/' . $name, $names );
		$names[] = 'wstm118-foreign/probe';
		sort( $names, SORT_STRING );
		return $names;
	}

	public static function hooks(): array {
		return array(
			'mcp_adapter_init' => array( 'error-contract-fixture.php', 10, 1 ),
			'mcp_adapter_tool_name' => array( 'error-contract-fixture.php', 10, 2 ),
			'wp_before_execute_ability' => array( 'error-contract-fixture.php', 10, 1 ),
			'wp_after_execute_ability' => array( 'error-contract-fixture.php', 10, 1 ),
			'wp_abilities_api_init' => array( 'error-contract-fixture.php', 100, 1 ),
			'doing_it_wrong_trigger_error' => array( 'error-contract-fixture.php', 10, 3 ),
			'map_meta_cap' => array( 'untrusted-content-fixture.php', PHP_INT_MAX, 4 ),
			'get_post_metadata' => array( 'untrusted-content-fixture.php', PHP_INT_MIN, 3 ),
			'notify_post_author' => array( 'untrusted-content-fixture.php', 10, 2 ),
			'notify_moderator' => array( 'untrusted-content-fixture.php', 10, 2 ),
			'rest_post_dispatch' => array( 'untrusted-content-fixture.php', 10, 1 ),
			'init' => array( 'untrusted-content-fixture.php', 10, 1 ),
			'wpseo_head' => array( 'untrusted-content-fixture.php', PHP_INT_MIN, 1 ),
			'wpseo_frontend_presenters' => array( 'untrusted-content-fixture.php', PHP_INT_MIN, 1 ),
			'seopress_head' => array( 'untrusted-content-fixture.php', PHP_INT_MIN, 1 ),
			'seopress_titles_title' => array( 'untrusted-content-fixture.php', PHP_INT_MIN, 1 ),
			'seopress_titles_desc' => array( 'untrusted-content-fixture.php', PHP_INT_MIN, 1 ),
		);
	}

	public static function validate_enabled( array $original, array $enabled, string $owner, string $plugin, array $sources ): void {
		$require = static function ( bool $condition, string $message ): void {
			if ( ! $condition ) {
				throw new RuntimeException( 'WSTM108 Runtime delta: ' . $message );
			}
		};
		$require( true === ( $enabled['optin_defined'] ?? null ) && true === ( $enabled['optin'] ?? null )
			&& true === ( $enabled['owner_defined'] ?? null ) && $owner === ( $enabled['owner'] ?? null ), 'foreign owned constants.' );
		$new = array_diff( array_keys( $enabled['abilities'] ), array_keys( $original['abilities'] ) );
		sort( $new, SORT_STRING );
		$require( self::additions() === $new, 'unexpected test-only ability set.' );
		foreach ( $new as $name ) {
			unset( $enabled['abilities'][ $name ] );
		}
		$individual = $enabled['servers']['wstm118-individual'] ?? null;
		$require( ! isset( $original['servers']['wstm118-individual'] ) && is_array( $individual ), 'individual server collision or absence.' );
		foreach ( array( 'namespace' => 'wstm118', 'route' => 'tools', 'name' => 'Error contract fixture',
			'description' => 'Disposable individual-tool proof', 'version' => '1.0.0',
			'observer_class' => 'WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
			'error_class' => 'WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler',
			'component_counts' => array( 'tools' => 95, 'resources' => 0, 'prompts' => 0 ) ) as $key => $value ) {
			$require( $value === ( $individual[ $key ] ?? null ), 'individual server differs: ' . $key );
		}
		unset( $enabled['servers']['wstm118-individual'] );
		$require( array_keys( $original['observers'] ) === self::OBSERVERS
			&& array_keys( $enabled['observers'] ) === self::OBSERVERS, 'incomplete observer inventory.' );
		$hooks = self::hooks();
		foreach ( $enabled['observers'] as $hook => &$callbacks ) {
			$retained = array();
			$added = 0;
			foreach ( $callbacks as $callback ) {
				$owned = false;
				foreach ( array( 'untrusted-content-fixture.php', 'error-contract-fixture.php' ) as $file ) {
					if ( $plugin . '/tests/e2e/' . $file === ( $callback['file'] ?? null ) ) {
						$owned = true;
						$require( isset( $hooks[ $hook ] ) && $hooks[ $hook ] === array( $file, $callback['priority'], $callback['accepted_args'] )
							&& $sources[ 'tests/e2e/' . $file ]['sha256'] === $callback['source_sha256'], 'unexpected fixture callback: ' . $hook );
						++$added;
					}
				}
				if ( ! $owned ) {
					$retained[] = $callback;
				}
			}
			$require( $added === ( isset( $hooks[ $hook ] ) ? 1 : 0 ), 'missing or duplicate fixture callback: ' . $hook );
			$callbacks = $retained;
		}
		unset( $callbacks );
		foreach ( array( 'optin_defined', 'optin', 'owner_defined', 'owner' ) as $key ) {
			$enabled[ $key ] = $original[ $key ];
		}
		$require( $enabled === $original, 'native schemas, servers, observers, providers or runtime changed.' );
	}
}
