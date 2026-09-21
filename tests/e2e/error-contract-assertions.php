<?php

/**
 * Independent expected vocabulary for the regression runners.
 */
function wstm118_expected_code( $reason ) {
	$groups = array(
		'forbidden' => array( 'forbidden', 'missing_capability', 'network_admin_required', 'protected_meta_key', 'metadata_not_writable', 'ability_invalid_permissions' ),
		'not_found' => array( 'not_found', 'plugin_not_found', 'target_not_found' ),
		'invalid_input' => array( 'invalid_block_path', 'invalid_content', 'invalid_content_type', 'invalid_context', 'invalid_file', 'invalid_identifier', 'invalid_input', 'invalid_meta_key', 'invalid_meta_value', 'invalid_parent', 'invalid_replacement', 'invalid_scheduled_date', 'invalid_status', 'invalid_strategy', 'invalid_target_type', 'invalid_taxonomy', 'invalid_taxonomy_terms', 'invalid_url', 'file_too_large', 'missing_post_id', 'missing_scheduled_date', 'missing_target', 'scheduled_date_too_soon', 'ability_invalid_input', 'too_many_ids', 'metadata_requires_separate_call' ),
		'precondition_failed' => array( 'precondition_failed', 'content_hash_mismatch', 'block_hash_mismatch', 'content_type_mismatch', 'trash_disabled', 'dependency_failure', 'network_context_required', 'site_kit_unavailable', 'site_kit_module_unavailable', 'site_kit_module_inactive', 'site_kit_module_not_connected', 'invalid_upload_limit', 'missing_confirmation', 'media_in_use' ),
		'conflict' => array( 'conflict', 'ambiguous_target' ),
		'unsupported' => array( 'unsupported', 'unsupported_mime_type', 'unsupported_type', 'site_kit_unsupported', 'generated_head_key_authorization_unavailable', 'key_authorization_unavailable' ),
		'upstream_failed' => array( 'upstream_failed', 'external_error', 'content_hygiene_query_failed', 'database_health_query_failed', 'create_failed', 'delete_failed', 'download_failed', 'featured_image_failed', 'meta_write_failed', 'metadata_update_failed', 'restore_revision_failed', 'restore_failed', 'site_kit_invalid_response', 'site_kit_request_failed', 'status_update_failed', 'trash_failed', 'update_failed', 'upload_failed', 'ability_invalid_output', 'ability_callback_exception', 'ability_invalid_execute_callback', 'ability_invalid_permission_callback', 'ability_missing_input_schema' ),
	);
	foreach ( $groups as $code => $reasons ) {
		if ( in_array( $reason, $reasons, true ) ) {
			return $code;
		}
	}
	throw new RuntimeException( 'Unspecified expected error reason: ' . $reason );
}

function wstm118_error_envelope( $result ) {
	if ( $result instanceof Webmastery_MCP_Error ) {
		$result = $result->get_error_data()['webmastery_error'] ?? null;
	}
	if ( ! is_array( $result ) || array( 'success', 'error' ) !== array_keys( $result ) || false !== $result['success']
		|| ! is_array( $result['error'] ) || array( 'code', 'reason', 'message', 'details' ) !== array_keys( $result['error'] )
		|| ! is_string( $result['error']['reason'] ) || ! is_string( $result['error']['message'] )
		|| '' === $result['error']['message'] || ! $result['error']['details'] instanceof stdClass
		|| wstm118_expected_code( $result['error']['reason'] ) !== $result['error']['code'] ) {
		throw new RuntimeException( 'Noncanonical error envelope: ' . json_encode( $result ) );
	}
	return $result;
}

function wstm118_error_reason( $result ) {
	if ( is_array( $result ) && true === ( $result['success'] ?? null ) ) {
		return null;
	}
	return wstm118_error_envelope( $result )['error']['reason'];
}

function wstm118_wire_error( array $result ) {
	if ( true !== ( $result['isError'] ?? null ) || null !== ( $result['structuredContent'] ?? null )
		|| 'text' !== ( $result['content'][0]['type'] ?? null ) || 1 !== count( $result['content'] ) ) {
		throw new RuntimeException( 'Expected actual MCP error signaling: ' . json_encode( $result ) );
	}
	$decoded = json_decode( $result['content'][0]['text'] );
	if ( ! $decoded instanceof stdClass || ! ( $decoded->error->details ?? null ) instanceof stdClass ) {
		throw new RuntimeException( 'MCP error details must encode as a JSON object.' );
	}
	$envelope = (array) $decoded;
	$envelope['error'] = (array) $decoded->error;
	return wstm118_error_envelope( $envelope );
}
