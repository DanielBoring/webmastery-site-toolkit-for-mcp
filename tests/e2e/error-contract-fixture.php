<?php

// Installed only in the disposable suite, after the normal registration audit.
defined( 'ABSPATH' ) || exit;

function wstm118_probe( $input ) {
	$GLOBALS['wstm118_counts']['execute']++;
	$mode = $input['mode'] ?? 'success';
	$reasons = array(
		'forbidden' => 'missing_capability',
		'not_found' => 'target_not_found',
		'invalid_input' => 'invalid_meta_key',
		'precondition_failed' => 'content_hash_mismatch',
		'conflict' => 'ambiguous_target',
		'unsupported' => 'unsupported_type',
		'upstream_failed' => 'update_failed',
	);
	if ( isset( $reasons[ $mode ] ) ) {
		return Webmastery_MCP_Response::legacy_error( $reasons[ $mode ], 'WSTM118 safe diagnostic.' );
	}
	if ( 'provider-known' === $mode || 'provider-unknown' === $mode ) {
		return new WP_Error( 'provider-known' === $mode ? 'invalid_input' : 'provider_private_name', 'WSTM118_PRIVATE_SENTINEL SQL /private/path', array( 'context' => 'WSTM118_PRIVATE_SENTINEL context' ) );
	}
	if ( 'exception' === $mode ) {
		throw new RuntimeException( 'WSTM118_PRIVATE_SENTINEL exception' );
	}
	if ( 'invalid-output' === $mode ) {
		return array( 'success' => true, 'data' => array( 'typed_value' => 'WSTM118_PRIVATE_SENTINEL output' ) );
	}
	return array( 'success' => true, 'data' => array(
		'typed_value' => 7,
		'nested' => array( 'success' => false, 'error' => array( 'code' => 'forbidden', 'reason' => 'forbidden', 'message' => 'Legitimate stored data.', 'details' => (object) array() ) ),
	) );
}

function wstm118_permission( $input ) {
	$GLOBALS['wstm118_counts']['permission']++;
	$mode = $input['mode'] ?? 'success';
	if ( 'deny' === $mode ) {
		return Webmastery_MCP_Response::local_error( 'missing_capability', 'WSTM118 permission denied.', array( 'status' => 403 ) );
	}
	if ( 'provider-deny' === $mode ) {
		return new WP_Error( 'forbidden', 'WSTM118_PRIVATE_SENTINEL permission', array( 'status' => 403, 'context' => 'WSTM118_PRIVATE_SENTINEL context' ) );
	}
	if ( 'false-permission' === $mode ) {
		return false;
	}
	return true;
}

function wstm118_foreign_result() {
	return array( 'success' => false, 'error' => array( 'code' => 'forbidden', 'reason' => 'forbidden', 'message' => 'Foreign unchanged.', 'details' => (object) array() ) );
}

add_action( 'wp_abilities_api_init', static function () {
	$GLOBALS['wstm118_counts'] = array( 'permission' => 0, 'execute' => 0, 'before' => 0, 'after' => 0 );
	$args = array(
		'label' => 'Disposable error-contract probe',
		'description' => 'Controlled non-production failure fixture.',
		'category' => 'webmastery-site-toolkit-for-mcp',
		'input_schema' => array( 'type' => 'object', 'properties' => array( 'mode' => array( 'type' => 'string' ), 'probe' => array( 'type' => 'integer' ) ) ),
		'output_schema' => array( 'type' => 'object', 'properties' => array( 'data' => array( 'type' => 'object', 'properties' => array( 'typed_value' => array( 'type' => 'integer' ) ) ) ) ),
		'execute_callback' => 'wstm118_probe',
		'permission_callback' => 'wstm118_permission',
		'meta' => array( 'mcp' => array( 'public' => true, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	);
	wp_register_ability( 'webmastery-site-toolkit-for-mcp/wstm118-probe', $args );
	wp_register_ability( 'webmastery-site-toolkit-for-mcp/wstm118-bad-execute', array_replace( $args, array( 'execute_callback' => 'wstm118_nonexistent_callback' ) ) );
	wp_register_ability( 'webmastery-site-toolkit-for-mcp/wstm118-bad-permission', array_replace( $args, array( 'permission_callback' => 'wstm118_nonexistent_callback' ) ) );
	wp_register_ability( 'webmastery-site-toolkit-for-mcp/wstm118-missing-schema', array_replace( $args, array( 'input_schema' => array() ) ) );
	wp_register_ability( 'wstm118-foreign/probe', array_replace( $args, array( 'execute_callback' => 'wstm118_foreign_result', 'permission_callback' => '__return_true' ) ) );
}, 100 );

foreach ( array( 'before', 'after' ) as $stage ) {
	add_action( "wp_{$stage}_execute_ability", static function ( $name ) use ( $stage ) {
		if ( 'webmastery-site-toolkit-for-mcp/wstm118-probe' === $name ) {
			$GLOBALS['wstm118_counts'][ $stage ]++;
		}
	} );
}

add_filter( 'doing_it_wrong_trigger_error', static function ( $trigger, $function, $message ) {
	if ( 'WP_Ability::execute' !== $function ) {
		return $trigger;
	}
	$expected = array(
		Webmastery_MCP_Response::permission_error( Webmastery_MCP_Response::local_error( 'missing_capability', 'WSTM118 permission denied.', array( 'status' => 403 ) ) )->get_error_message(),
		Webmastery_MCP_Response::permission_error( new WP_Error( 'forbidden', 'WSTM118_PRIVATE_SENTINEL permission' ) )->get_error_message(),
		Webmastery_MCP_Response::permission_error( Webmastery_MCP_Response::local_error( 'forbidden', 'You do not have permission to execute this ability.' ) )->get_error_message(),
		Webmastery_MCP_Response::permission_error( new WP_Error( 'ability_invalid_permission_callback', 'fixture' ) )->get_error_message(),
	);
	foreach ( $expected as $text ) {
		if ( $message === esc_html( $text ) ) {
			return false;
		}
	}
	return $trigger;
}, 10, 3 );

add_action( 'mcp_adapter_init', static function ( $adapter ) {
	$names = array();
	foreach ( wp_get_abilities() as $ability ) {
		if ( 0 === strpos( $ability->get_name(), 'webmastery-site-toolkit-for-mcp/' ) || 'wstm118-foreign/probe' === $ability->get_name() ) {
			$names[] = $ability->get_name();
		}
	}
	$adapter->create_server(
		'wstm118-individual', 'wstm118', 'tools', 'Error contract fixture', 'Disposable individual-tool proof', '1.0.0',
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		$names
	);
} );
