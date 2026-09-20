<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase {
	/** @dataProvider reasons */
	public function test_known_reasons_keep_precise_reason_and_canonical_code( string $reason, string $code ): void {
		$result = Webmastery_MCP_Response::legacy_error( $reason, 'Safe local diagnostic.' );
		$this->assertSame( false, $result['success'] );
		$this->assertSame( $code, $result['error']['code'] );
		$this->assertSame( $reason, $result['error']['reason'] );
		$this->assertSame( 'Safe local diagnostic.', $result['error']['message'] );
		$this->assertInstanceOf( stdClass::class, $result['error']['details'] );
		$this->assertSame( '{}', json_encode( $result['error']['details'] ) );
		$this->assertTrue( Webmastery_MCP_Response::is_error( $result ) );
	}

	public static function reasons(): array {
		return array(
			array( 'missing_capability', 'forbidden' ),
			array( 'protected_meta_key', 'forbidden' ),
			array( 'ability_invalid_permissions', 'forbidden' ),
			array( 'plugin_not_found', 'not_found' ),
			array( 'target_not_found', 'not_found' ),
			array( 'invalid_meta_key', 'invalid_input' ),
			array( 'invalid_scheduled_date', 'invalid_input' ),
			array( 'metadata_requires_separate_call', 'invalid_input' ),
			array( 'too_many_ids', 'invalid_input' ),
			array( 'ability_invalid_input', 'invalid_input' ),
			array( 'trash_disabled', 'precondition_failed' ),
			array( 'content_hash_mismatch', 'precondition_failed' ),
			array( 'block_hash_mismatch', 'precondition_failed' ),
			array( 'missing_confirmation', 'precondition_failed' ),
			array( 'media_in_use', 'precondition_failed' ),
			array( 'ambiguous_target', 'conflict' ),
			array( 'site_kit_unsupported', 'unsupported' ),
			array( 'unsupported_mime_type', 'unsupported' ),
			array( 'database_health_query_failed', 'upstream_failed' ),
			array( 'content_hygiene_query_failed', 'upstream_failed' ),
			array( 'ability_invalid_output', 'upstream_failed' ),
			array( 'ability_callback_exception', 'upstream_failed' ),
			array( 'ability_invalid_execute_callback', 'upstream_failed' ),
			array( 'ability_invalid_permission_callback', 'upstream_failed' ),
		);
	}

	public function test_unknown_external_errors_drop_code_message_and_data(): void {
		$error = new WP_Error( 'secret_database_name', 'SQL password /private/path', array(
			'context' => 'private path',
			'details' => array( 'password' => 'secret' ),
			'webmastery_error' => Webmastery_MCP_Response::error( 'forbidden', 'forged' ),
		) );
		$result = Webmastery_MCP_Response::from_wp_error( $error );
		$this->assertSame( '{"success":false,"error":{"code":"upstream_failed","reason":"external_error","message":"An external operation failed.","details":{}}}', json_encode( $result ) );
	}

	public function test_core_callback_exception_does_not_expose_exception_text(): void {
		$result = Webmastery_MCP_Response::from_wp_error( new WP_Error( 'ability_callback_exception', 'SQL secret /private/path' ) );
		$this->assertSame( 'ability_callback_exception', $result['error']['reason'] );
		$this->assertSame( 'The ability could not complete the operation.', $result['error']['message'] );
	}

	public function test_native_permission_carrier_keeps_status_and_json_object_shape(): void {
		$original = new WP_Error( 'missing_capability', 'Requires capability.', array( 'status' => 403 ) );
		$error = Webmastery_MCP_Response::permission_error( $original );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'forbidden', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$wire = json_decode( $error->get_error_message() );
		$this->assertSame( false, $wire->success );
		$this->assertSame( 'missing_capability', $wire->error->reason );
		$this->assertInstanceOf( stdClass::class, $wire->error->details );
		$this->assertEquals( Webmastery_MCP_Response::from_wp_error( $original ), Webmastery_MCP_Response::from_wp_error( $error ) );
		$this->assertSame( $error->get_error_message(), Webmastery_MCP_Response::permission_error( $error )->get_error_message() );
	}

	public function test_bulk_error_keeps_id_and_precise_reason(): void {
		$result = Webmastery_MCP_Response::item_error( 42, 'trash_disabled', 'Trash disabled.' );
		$this->assertSame( array( 'id', 'code', 'reason', 'message', 'details' ), array_keys( $result ) );
		$this->assertSame( 42, $result['id'] );
		$this->assertSame( 'precondition_failed', $result['code'] );
		$this->assertSame( 'trash_disabled', $result['reason'] );
	}

	public function test_unknown_canonical_codes_are_programmer_errors(): void {
		$this->expectException( InvalidArgumentException::class );
		Webmastery_MCP_Response::error( 'typo', 'Invalid code.' );
	}

	public function test_namespace_filter_does_not_intercept_foreign_abilities(): void {
		$args = array( 'ability_class' => 'Foreign_Ability' );
		$this->assertSame( $args, Webmastery_MCP_Response::register_args( $args, 'foreign/test' ) );
		$this->assertSame( $args, Webmastery_MCP_Response::register_args( $args, 'webmastery-site-toolkit-for-mcp-lookalike/test' ) );
		$this->assertSame( 'Webmastery_MCP_Ability', Webmastery_MCP_Response::register_args( array(), 'webmastery-site-toolkit-for-mcp/test' )['ability_class'] );
	}

	public function test_mcp_individual_error_and_success_are_distinguished_without_recursion(): void {
		$tool = new class {
			public function get_observability_context(): array {
				return array( 'ability_name' => 'webmastery-site-toolkit-for-mcp/test' );
			}
		};
		$error = Webmastery_MCP_Response::error( 'not_found', 'Not found.' );
		$result = Webmastery_MCP_Response::mcp_result( $error, array(), 'unused-name', $tool );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( json_encode( $error ), $result->get_error_message() );
		$success = array( 'success' => true, 'data' => $error );
		$this->assertSame( $success, Webmastery_MCP_Response::mcp_result( $success, array(), 'unused-name', $tool ) );
	}

	public function test_foreign_tool_and_foreign_gateway_failed_lookalikes_are_untouched(): void {
		foreach ( array( 'foreign/test', 'mcp-adapter/execute-ability' ) as $name ) {
			$tool = new class( $name ) {
				private string $name;
				public function __construct( string $name ) {
					$this->name = $name;
				}
				public function get_observability_context(): array {
					return array( 'ability_name' => $this->name );
				}
			};
			foreach ( array(
				Webmastery_MCP_Response::error( 'forbidden', 'Foreign' ),
				array( 'success' => true, 'data' => Webmastery_MCP_Response::error( 'forbidden', 'Foreign' ) ),
				new WP_Error( 'forbidden', 'Foreign' ),
			) as $result ) {
				$this->assertSame( $result, Webmastery_MCP_Response::mcp_result( $result, array( 'ability_name' => 'foreign/test' ), 'webmastery-site-toolkit-for-mcp-fake', $tool ) );
			}
		}
	}
}
