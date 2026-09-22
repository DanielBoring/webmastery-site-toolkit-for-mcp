<?php

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM118_DISPOSABLE' ) ) {
	fwrite( STDERR, "Error-contract proof requires WSTM118_DISPOSABLE=1 on an owned disposable site.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
define( 'WEBMASTERY_MCP_E2E_CLIENT_ONLY', true );
require_once __DIR__ . '/mcp-crud-runner.php';

$summary = array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'passed' => 0, 'failed' => 0, 'cases' => array(), 'cleanup_errors' => array() );
$clients = array();
$password = null;
$owned_posts = array();
$admin = get_user_by( 'login', 'admin' );
$record = static function ( $label, $check ) use ( &$summary ) {
	try {
		$evidence = $check();
		$summary['passed']++;
		$summary['cases'][] = array( 'label' => $label, 'passed' => true, 'evidence' => $evidence );
	} catch ( Throwable $error ) {
		$summary['failed']++;
		$summary['cases'][] = array( 'label' => $label, 'passed' => false, 'failure' => $error->getMessage() );
		fwrite( STDERR, "FAIL {$label}: {$error->getMessage()}\n" );
	}
};
$assert_reason = static function ( $result, $reason ) {
	webmastery_mcp_e2e_assert( $reason === wstm118_error_reason( $result ), 'Wrong canonical reason: ' . wp_json_encode( $result ) );
	webmastery_mcp_e2e_assert( false === strpos( wp_json_encode( $result ), 'WSTM118_PRIVATE_SENTINEL' ), 'Private diagnostic escaped.' );
	return $result;
};

try {
	webmastery_mcp_e2e_assert( $admin instanceof WP_User && function_exists( 'wstm118_probe' ), 'Install the controlled error fixture after the normal ability audit.' );
	wp_set_current_user( $admin->ID );
	$adapter = new ReflectionClass( \WP\MCP\Handlers\Tools\ToolsHandler::class );
	$handler_source = file_get_contents( $adapter->getFileName() );
	$plugin_data = get_file_data( WP_PLUGIN_DIR . '/mcp-adapter/mcp-adapter.php', array( 'version' => 'Version' ) );
	$summary['adapter'] = array( 'version' => $plugin_data['version'], 'handler_sha256' => hash( 'sha256', $handler_source ), 'result_hook_offset' => strpos( $handler_source, "'mcp_adapter_tool_call_result'" ), 'permission_offset' => strpos( $handler_source, 'check_permission' ) );
	webmastery_mcp_e2e_assert( '0.6.1' === $plugin_data['version'], 'Proof requires the pinned installed Adapter 0.6.1.' );
	webmastery_mcp_e2e_assert( false !== $summary['adapter']['result_hook_offset'] && false !== $summary['adapter']['permission_offset'] && $summary['adapter']['permission_offset'] < $summary['adapter']['result_hook_offset'], 'Installed Adapter permission/result-hook order differs.' );
	$password = WP_Application_Passwords::create_new_application_password( $admin->ID, array( 'name' => 'wstm118 owned error proof' ) );
	webmastery_mcp_e2e_assert( ! is_wp_error( $password ), 'Cannot create fixture application password.' );
	foreach ( array( 'gateway' => 'mcp/mcp-adapter-default-server', 'individual' => 'wstm118/tools' ) as $boundary => $route ) {
		$clients[ $boundary ] = new Webmastery_MCP_E2E_Client( 'http://localhost/wp-json/' . $route, $admin->user_login, $password[0] );
		$clients[ $boundary ]->initialize();
	}
	$listing = $clients['individual']->call( 'tools/list' );
	webmastery_mcp_e2e_assert( isset( $listing['tools'] ) && ! isset( $listing['nextCursor'] ), 'Individual fixture discovery is incomplete.' );
	$summary['individual_catalog'] = $listing;
	$summary['discovered_mapping'] = array();
	$summary['error_wire_keys'] = array();
	$call = static function ( $boundary, $name, $input ) use ( &$clients, $listing, &$summary ) {
		$tool_name = 'mcp-adapter-execute-ability';
		if ( 'individual' === $boundary ) {
			$description = trim( wp_get_ability( $name )->get_description() );
			$matches = array_values( array_filter( $listing['tools'], static fn( $tool ) => $description === ( $tool['description'] ?? null ) ) );
			webmastery_mcp_e2e_assert( 1 === count( $matches ) && is_string( $matches[0]['name'] ?? null ), 'Individual ability descriptor was missing or ambiguous: ' . $name );
			webmastery_mcp_e2e_assert( 'object' === ( $matches[0]['inputSchema']['type'] ?? null ), 'Discovered tool lacks an object input schema.' );
			$tool_name = $matches[0]['name'];
			$summary['discovered_mapping'][ $name ] = $tool_name;
		}
		$result = $clients[ $boundary ]->call( 'tools/call', array(
			'name' => $tool_name,
			'arguments' => 'gateway' === $boundary ? array( 'ability_name' => $name, 'parameters' => (object) $input ) : (object) $input,
		) );
		if ( true === ( $result['isError'] ?? null ) ) {
			$summary['error_wire_keys'][] = array( 'boundary' => $boundary, 'ability' => $name, 'keys' => array_keys( $result ), 'has_structured_content' => array_key_exists( 'structuredContent', $result ) );
		}
		return $result;
	};
	$name = 'webmastery-site-toolkit-for-mcp/wstm118-probe';
	$ability = wp_get_ability( $name );
	webmastery_mcp_e2e_assert( $ability instanceof Webmastery_MCP_Ability, 'Owned ability class was not installed.' );
	webmastery_mcp_e2e_assert( ! wp_get_ability( 'wstm118-foreign/probe' ) instanceof Webmastery_MCP_Ability, 'Foreign ability class was intercepted.' );
	foreach ( array( 'execute', 'permission' ) as $kind ) {
		$record( "registration/{$kind}-callback", static function () use ( $kind ) {
			$evidence = $GLOBALS['wstm118_registration'][ $kind ];
			$floor = 0 === strpos( get_bloginfo( 'version' ), '6.9' );
			webmastery_mcp_e2e_assert( $floor === $evidence['rejected'], 'Unexpected core invalid-callback registration behavior.' );
			webmastery_mcp_e2e_assert( ( $floor ? 1 : 0 ) === count( $evidence['notices'] ), 'Unexpected registry diagnostic count.' );
			return array( 'registration' => $evidence, 'execution_fixture' => 'Non-callable callback injected after successful registration.' );
		} );
	}
	$cases = array(
		'forbidden' => 'missing_capability', 'not_found' => 'target_not_found', 'invalid_input' => 'invalid_meta_key',
		'precondition_failed' => 'content_hash_mismatch', 'conflict' => 'ambiguous_target', 'unsupported' => 'unsupported_type', 'upstream_failed' => 'update_failed',
		'provider-known' => 'external_error', 'provider-unknown' => 'external_error', 'exception' => 'ability_callback_exception', 'invalid-output' => 'ability_invalid_output',
		'deny' => 'missing_capability', 'provider-deny' => 'external_error', 'false-permission' => 'forbidden', 'permission-exception' => 'ability_callback_exception',
	);
	foreach ( $cases as $mode => $reason ) {
		$input = array( 'mode' => $mode );
		$denied = in_array( $mode, array( 'deny', 'provider-deny', 'false-permission', 'permission-exception' ), true );
		$record( "ability/{$mode}", static function () use ( $ability, $input, $denied, $mode, $reason, $assert_reason ) {
			$GLOBALS['wstm118_counts'] = array_fill_keys( array( 'permission', 'execute', 'before', 'after' ), 0 );
			$result = $assert_reason( $ability->execute( $input ), $denied ? 'ability_invalid_permissions' : $reason );
			$counts = $GLOBALS['wstm118_counts'];
			webmastery_mcp_e2e_assert( 1 === $counts['permission'] && ( $denied ? 0 : 1 ) === $counts['execute'], 'Permission or execution was bypassed/replayed.' );
			webmastery_mcp_e2e_assert( ( $denied ? 0 : 1 ) === $counts['before'] && ( ! $denied && ! in_array( $mode, array( 'provider-known', 'provider-unknown', 'exception', 'invalid-output' ), true ) ? 1 : 0 ) === $counts['after'], 'Core action sequence changed.' );
			return array( 'result' => $result, 'counts' => $counts );
		} );
		if ( $denied ) {
			$record( "permission/{$mode}", static function () use ( $ability, $input, $reason, $assert_reason ) {
				$native = $ability->check_permissions( $input );
				$status = 'permission-exception' === $input['mode'] ? 502 : 403;
				webmastery_mcp_e2e_assert( $native instanceof WP_Error && $status === $native->get_error_data()['status'], 'Native carrier or status changed.' );
				return $assert_reason( $native, $reason );
			} );
		} elseif ( in_array( $mode, array( 'forbidden', 'not_found', 'invalid_input', 'precondition_failed', 'conflict', 'unsupported', 'upstream_failed' ), true ) ) {
			$record( "direct/{$mode}", static function () use ( $input, $reason, $assert_reason ) {
				return $assert_reason( wstm118_probe( $input ), $reason );
			} );
		}
		foreach ( array_keys( $clients ) as $boundary ) {
			$record( "{$boundary}/{$mode}", static function () use ( $call, $boundary, $name, $input, $reason, $assert_reason ) {
				$raw = $call( $boundary, $name, $input );
				$assert_reason( wstm118_wire_error( $raw ), $reason );
				return $raw;
			} );
		}
	}
	foreach ( array(
		array( 'wstm118-probe', array( 'probe' => 'WSTM118_PRIVATE_SENTINEL input' ), 'ability_invalid_input', 'ability_invalid_input' ),
		array( 'wstm118-bad-execute', array(), 'ability_invalid_execute_callback', 'ability_invalid_execute_callback' ),
		array( 'wstm118-bad-permission', array(), 'ability_invalid_permissions', 'ability_invalid_permission_callback' ),
		array( 'wstm118-missing-schema', array( 'probe' => 1 ), 'ability_missing_input_schema', 'ability_missing_input_schema' ),
	) as list( $slug, $input, $direct_reason, $wire_reason ) ) {
		$target = 'webmastery-site-toolkit-for-mcp/' . $slug;
		$record( "ability/{$slug}/schema-callback", static function () use ( $target, $input, $direct_reason, $assert_reason ) {
			return $assert_reason( wp_get_ability( $target )->execute( $input ), $direct_reason );
		} );
		foreach ( array_keys( $clients ) as $boundary ) {
			$record( "{$boundary}/{$slug}/schema-callback", static function () use ( $call, $boundary, $target, $input, $wire_reason, $assert_reason ) {
				$raw = $call( $boundary, $target, $input );
				$assert_reason( wstm118_wire_error( $raw ), $wire_reason );
				return $raw;
			} );
		}
	}
	foreach ( array_keys( $clients ) as $boundary ) {
		$record( "{$boundary}/nested-success-isolation", static function () use ( $call, $boundary, $name ) {
			$raw = $call( $boundary, $name, array( 'mode' => 'success' ) );
			webmastery_mcp_e2e_assert( false === $raw['isError'], 'Nested data became an error.' );
			$expected = wstm118_probe( array( 'mode' => 'success' ) );
			$expected = 'gateway' === $boundary ? array( 'success' => true, 'data' => $expected ) : $expected;
			webmastery_mcp_e2e_assert( json_decode( wp_json_encode( $expected ), true ) === $raw['structuredContent'] && wp_json_encode( $expected ) === $raw['content'][0]['text'], 'Success wrapper or nested payload changed.' );
			return $raw;
		} );
		$record( "{$boundary}/foreign-isolation", static function () use ( $call, $boundary ) {
			$raw = $call( $boundary, 'wstm118-foreign/probe', array() );
			$expected = wstm118_foreign_result();
			$expected = 'gateway' === $boundary ? array( 'success' => true, 'data' => $expected ) : $expected;
			webmastery_mcp_e2e_assert( false === $raw['isError'] && json_decode( wp_json_encode( $expected ), true ) === $raw['structuredContent'] && wp_json_encode( $expected ) === $raw['content'][0]['text'], 'Foreign result changed.' );
			return $raw;
		} );
	}
	foreach ( array_keys( $clients ) as $boundary ) {
		foreach ( array( 'mixed', 'all-failed' ) as $batch ) {
			$id = wp_insert_post( array( 'post_title' => 'WSTM118 owned bulk fixture', 'post_status' => 'draft', 'post_author' => $admin->ID ), true );
			webmastery_mcp_e2e_assert( ! is_wp_error( $id ) && $id > 0, 'Cannot create bulk fixture.' );
			$owned_posts[] = $id;
			$record( "{$boundary}/bulk/{$batch}", static function () use ( $call, $boundary, $batch, $id ) {
				$ids = 'mixed' === $batch ? array( $id, PHP_INT_MAX ) : array( PHP_INT_MAX );
				$raw = $call( $boundary, 'webmastery-site-toolkit-for-mcp/bulk-publish-posts', array( 'ids' => $ids, 'confirm' => true ) );
				webmastery_mcp_e2e_assert( false === $raw['isError'], 'Non-atomic batch became a tool error.' );
				$result = webmastery_mcp_e2e_extract_tool_payload( $raw, 'bulk' );
				$result = 'gateway' === $boundary ? $result['data'] : $result;
				$data = $result['data'];
				webmastery_mcp_e2e_assert( true === $result['success'] && count( $ids ) === $data['requested'] && 1 === $data['failure_count'] && ( 'mixed' === $batch ? 1 : 0 ) === $data['success_count'], 'Bulk summary semantics changed.' );
				$failure = $data['failures'][0];
				webmastery_mcp_e2e_assert( PHP_INT_MAX === $failure['id'] && 'not_found' === $failure['code'] && 'not_found' === $failure['reason'] && 'Post not found.' === $failure['message'], 'Bulk failure identity/diagnostic changed.' );
				$text = json_decode( $raw['content'][0]['text'] );
				$text = 'gateway' === $boundary ? $text->data : $text;
				webmastery_mcp_e2e_assert( $text->data->failures[0]->details instanceof stdClass, 'Bulk details are not an object.' );
				clean_post_cache( $id );
				webmastery_mcp_e2e_assert( ( 'mixed' === $batch ? 'publish' : 'draft' ) === get_post_status( $id ), 'Bulk stored state differs.' );
				return $raw;
			} );
		}
	}
} catch ( Throwable $error ) {
	$summary['failed']++;
	$summary['fatal'] = $error->getMessage();
	fwrite( STDERR, $error->getMessage() . "\n" );
} finally {
	foreach ( $clients as $boundary => $client ) {
		try {
			$client->close();
		} catch ( Throwable $error ) {
			$summary['failed']++;
			$summary['cleanup_errors'][] = array( 'resource' => $boundary, 'message' => $error->getMessage() );
		}
	}
	if ( is_array( $password ) ) {
		$deleted = WP_Application_Passwords::delete_application_password( $admin->ID, $password[1]['uuid'] );
		$remaining = array_filter( WP_Application_Passwords::get_user_application_passwords( $admin->ID ), static fn( $item ) => $password[1]['uuid'] === $item['uuid'] );
		$summary['application_password_absent_after_cleanup'] = array() === $remaining;
		if ( true !== $deleted || array() !== $remaining ) {
			$summary['failed']++;
			$summary['cleanup_errors'][] = array( 'resource' => 'application password', 'message' => 'Revocation failed.' );
		}
	}
	foreach ( $owned_posts as $id ) {
		if ( ! wp_delete_post( $id, true ) || null !== get_post( $id ) ) {
			$summary['failed']++;
			$summary['cleanup_errors'][] = array( 'resource' => 'post ' . $id, 'message' => 'Owned fixture deletion failed.' );
		}
	}
	webmastery_mcp_e2e_write_summary( __DIR__ . '/../../e2e-artifacts/error-contract.json', $summary );
}
echo "SUMMARY error contract {$summary['passed']} passed, {$summary['failed']} failed\n";
exit( $summary['failed'] ? 1 : 0 );
