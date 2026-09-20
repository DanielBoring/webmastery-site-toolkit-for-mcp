<?php

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-response-error.php';

/**
 * The public failure vocabulary, independent of WordPress and MCP carriers.
 */
final class Webmastery_MCP_Response {
	private const REASONS = [
		'forbidden'           => [ 'forbidden', 'missing_capability', 'network_admin_required', 'protected_meta_key', 'metadata_not_writable', 'ability_invalid_permissions' ],
		'not_found'           => [ 'not_found', 'plugin_not_found', 'target_not_found' ],
		'invalid_input'       => [ 'invalid_block_path', 'invalid_content', 'invalid_content_type', 'invalid_context', 'invalid_file', 'invalid_identifier', 'invalid_input', 'invalid_meta_key', 'invalid_meta_value', 'invalid_parent', 'invalid_replacement', 'invalid_scheduled_date', 'invalid_status', 'invalid_strategy', 'invalid_target_type', 'invalid_taxonomy', 'invalid_taxonomy_terms', 'invalid_url', 'file_too_large', 'missing_post_id', 'missing_scheduled_date', 'missing_target', 'scheduled_date_too_soon', 'ability_invalid_input', 'too_many_ids', 'metadata_requires_separate_call' ],
		'precondition_failed' => [ 'precondition_failed', 'content_hash_mismatch', 'block_hash_mismatch', 'content_type_mismatch', 'trash_disabled', 'dependency_failure', 'network_context_required', 'site_kit_unavailable', 'site_kit_module_unavailable', 'site_kit_module_inactive', 'site_kit_module_not_connected', 'invalid_upload_limit', 'missing_confirmation', 'media_in_use' ],
		'conflict'            => [ 'conflict', 'ambiguous_target' ],
		'unsupported'         => [ 'unsupported', 'unsupported_mime_type', 'unsupported_type', 'site_kit_unsupported' ],
		'upstream_failed'     => [ 'upstream_failed', 'external_error', 'content_hygiene_query_failed', 'database_health_query_failed', 'create_failed', 'delete_failed', 'download_failed', 'featured_image_failed', 'meta_write_failed', 'metadata_update_failed', 'restore_revision_failed', 'restore_failed', 'site_kit_invalid_response', 'site_kit_request_failed', 'status_update_failed', 'trash_failed', 'update_failed', 'upload_failed', 'ability_invalid_output', 'ability_callback_exception', 'ability_invalid_execute_callback', 'ability_invalid_permission_callback', 'ability_missing_input_schema' ],
	];

	public static function error( string $code, string $message, array $details = [], ?string $reason = null ): array {
		if ( ! isset( self::REASONS[ $code ] ) ) {
			throw new InvalidArgumentException( 'Unknown Webmastery error code.' );
		}
		return [
			'success' => false,
			'error'   => [
				'code'    => $code,
				'reason'  => $reason ?? $code,
				'message' => $message,
				'details' => (object) $details,
			],
		];
	}

	public static function ok( array $data ): array {
		return [ 'success' => true, 'data' => $data ];
	}

	public static function legacy_error( string $reason, string $message, array $details = [] ): array {
		foreach ( self::REASONS as $code => $reasons ) {
			if ( in_array( $reason, $reasons, true ) ) {
				return self::error( $code, $message, $details, $reason );
			}
		}
		return self::error( 'upstream_failed', 'An external operation failed.', [], 'external_error' );
	}

	public static function from_wp_error( WP_Error $error ): array {
		$data = $error->get_error_data();
		if ( $error instanceof Webmastery_MCP_Error && is_array( $data ) && isset( $data['webmastery_error'] ) && self::is_error( $data['webmastery_error'] ) ) {
			return $data['webmastery_error'];
		}
		$reason = (string) $error->get_error_code();
		// External database/HTTP/plugin errors must not expose SQL, paths, or credentials.
		$unsafe  = [ 'ability_callback_exception', 'ability_invalid_output', 'ability_invalid_execute_callback', 'ability_invalid_permission_callback', 'ability_missing_input_schema' ];
		$message = in_array( $reason, $unsafe, true ) ? 'The ability could not complete the operation.' : $error->get_error_message();
		$details = [];
		if ( is_array( $data ) ) {
			foreach ( [ 'context', 'detected_version', 'minimum_version', 'max_bytes' ] as $key ) {
				if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
					$details[ $key ] = $data[ $key ];
				}
			}
		}
		return self::legacy_error( $reason, $message, $details );
	}

	public static function item_error( int $id, string $reason, string $message, array $details = [] ): array {
		return [ 'id' => $id ] + self::legacy_error( $reason, $message, $details )['error'];
	}

	public static function is_error( $result ): bool {
		return is_array( $result ) && false === ( $result['success'] ?? null )
			&& is_array( $result['error'] ?? null )
			&& isset( self::REASONS[ $result['error']['code'] ?? '' ] )
			&& is_string( $result['error']['reason'] ?? null )
			&& is_string( $result['error']['message'] ?? null )
			&& is_object( $result['error']['details'] ?? null );
	}

	public static function permission_error( WP_Error $error ): WP_Error {
		$envelope = self::from_wp_error( $error );
		$data     = $error->get_error_data();
		$status   = is_array( $data ) && isset( $data['status'] ) && is_int( $data['status'] )
			? $data['status'] : self::http_status( $envelope['error']['code'] );
		return self::carrier( $envelope, $status );
	}

	public static function legacy_wp_error( string $reason, string $message, array $details = [], int $status = 400 ): WP_Error {
		return self::carrier( self::legacy_error( $reason, $message, $details ), $status );
	}

	private static function http_status( string $code ): int {
		$statuses = [ 'forbidden' => 403, 'not_found' => 404, 'invalid_input' => 400, 'precondition_failed' => 412, 'conflict' => 409, 'unsupported' => 422, 'upstream_failed' => 502 ];
		return $statuses[ $code ];
	}

	private static function carrier( array $envelope, int $status ): WP_Error {
		// Adapter 0.6.1 discards WP_Error data, including before its result filter.
		$message = wp_json_encode( $envelope, JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $message ) {
			throw new RuntimeException( 'Could not encode Webmastery error envelope.' );
		}
		return new Webmastery_MCP_Error( $envelope['error']['code'], $message, [ 'status' => $status, 'webmastery_error' => $envelope ] );
	}

	public static function owns( string $name ): bool {
		return 0 === strpos( $name, 'webmastery-site-toolkit-for-mcp/' );
	}

	public static function register_args( array $args, string $name ): array {
		if ( self::owns( $name ) ) {
			$args['ability_class'] = Webmastery_MCP_Ability::class;
		}
		return $args;
	}

	public static function mcp_result( $result, $args, $tool_name, $tool ) {
		$context = $tool->get_observability_context();
		$name    = $context['ability_name'] ?? '';
		$owned   = self::owns( $name );
		if ( 'mcp-adapter/execute-ability' === $name && is_array( $args ) && is_string( $args['ability_name'] ?? null )
			&& self::owns( $args['ability_name'] ) && wp_get_ability( $args['ability_name'] ) instanceof Webmastery_MCP_Ability ) {
			$owned = true;
			if ( is_array( $result ) && true === ( $result['success'] ?? null ) && self::is_error( $result['data'] ?? null ) ) {
				$result = $result['data'];
			}
		}
		if ( ! $owned ) {
			return $result;
		}
		if ( is_wp_error( $result ) ) {
			return self::permission_error( $result );
		}
		return self::is_error( $result ) ? self::carrier( $result, self::http_status( $result['error']['code'] ) ) : $result;
	}
}
