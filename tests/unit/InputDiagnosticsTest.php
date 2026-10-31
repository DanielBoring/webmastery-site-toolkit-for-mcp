<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Boundary composition with the existing lifecycle double, not real HTTP proof.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class InputDiagnosticsTest extends TestCase {
	protected function setUp(): void {
		require_once __DIR__ . '/fixtures/native-input-ability.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-ability.php';
		if ( ! function_exists( 'wp_get_ability' ) ) {
			function wp_get_ability( $name ) {
				return $GLOBALS['wstm203_abilities'][ $name ] ?? null;
			}
		}
	}

	public static function invalid_inputs(): array {
		$integer = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 );
		$object = array( 'type' => 'object', 'properties' => array( 'post_id' => $integer ), 'required' => array( 'post_id' ), 'additionalProperties' => false );
		$array = array( 'type' => 'array', 'items' => $object, 'minItems' => 1, 'maxItems' => 2 );
		$nested = array( 'type' => 'object', 'properties' => array( 'rows' => $array ), 'additionalProperties' => false );
		return array(
			'missing public field before unknown key' => array( $object, array( 'id' => 2447 ), '$.post_id', 'required' ),
			'unknown hostile key' => array( $object, array( 'post_id' => 1, 'PRIVATE password /path' => 'PRIVATE value' ), '$.*', 'unknown_property' ),
			'unknown nested key' => array( $nested, array( 'rows' => array( array( 'post_id' => 1, 'PRIVATE' => null ) ) ), '$.rows[].*', 'unknown_property' ),
			'strict numeric string' => array( $object, array( 'post_id' => '1' ), '$.post_id', 'type' ),
			'explicit null' => array( $object, array( 'post_id' => null ), '$.post_id', 'type' ),
			'root type' => array( $object, 'PRIVATE', '$', 'type' ),
			'root enum' => array( array( 'type' => 'string', 'enum' => array( 'mobile', 'desktop' ) ), 'PRIVATE', '$', 'enum' ),
			'numeric lower bound' => array( $object, array( 'post_id' => 0 ), '$.post_id', 'bounds' ),
			'numeric upper bound' => array( $object, array( 'post_id' => 6 ), '$.post_id', 'bounds' ),
			'array lower bound' => array( $nested, array( 'rows' => array() ), '$.rows', 'bounds' ),
			'array upper bound' => array( $nested, array( 'rows' => array_fill( 0, 3, array( 'post_id' => 1 ) ) ), '$.rows', 'bounds' ),
			'array nested required' => array( $nested, array( 'rows' => array( array() ) ), '$.rows[].post_id', 'required' ),
			'array nested type' => array( $nested, (object) array( 'rows' => array( (object) array( 'post_id' => 'PRIVATE' ) ) ), '$.rows[].post_id', 'type' ),
			'dynamic map hides key' => array( array( 'type' => 'object', 'additionalProperties' => $array ), array( 'PRIVATE key' => array( array( 'post_id' => 'PRIVATE' ) ) ), '$.*[].post_id', 'type' ),
			'nonpublic required name' => array( array( 'type' => 'object', 'required' => array( 'PRIVATE' ) ), array(), '$.*', 'required' ),
			'unsafe schema name' => array( array( 'type' => 'object', 'properties' => array( 'PRIVATE\nfield' => $integer ) ), array( 'PRIVATE\nfield' => null ), '$.*', 'type' ),
		);
	}

	/** @dataProvider invalid_inputs */
	public function test_diagnostics_survive_every_owned_boundary_without_running_callbacks( array $schema, $input, string $field, string $reason ): void {
		$name = 'webmastery-site-toolkit-for-mcp/diagnostic-probe';
		$args = Webmastery_MCP_Input::register_args( array(
			'input_schema' => $schema,
			'permission_callback' => static function () { self::fail( 'Original permission/capability boundary reached.' ); },
			'execute_callback' => static function () { self::fail( 'Original query/write boundary reached.' ); },
		), $name );
		$before = serialize( $input );
		$ability = new Webmastery_MCP_Ability( $name, $args );
		$GLOBALS['wstm203_abilities'][ $name ] = $ability;
		$native = $ability->validate_input( $input );
		self::assertInstanceOf( WP_Error::class, $native );
		if ( null !== $ability->last_parent_error ) {
			self::assertSame( $ability->last_parent_error, $native );
			self::assertSame( 'PRIVATE core diagnostic', $native->get_error_message() );
			self::assertSame( array( 'private' => 'not public' ), $native->get_error_data() );
		}
		self::assertSame( 1, $ability->parent_validations );
		$results = array(
			$args['execute_callback']( $input ),
			Webmastery_MCP_Response::from_wp_error( $args['permission_callback']( $input ) ),
			Webmastery_MCP_Response::from_wp_error( $native ),
			$ability->execute( $input ),
		);
		foreach ( array( $name, 'mcp-adapter/execute-ability' ) as $tool_name ) {
			$tool = new class( $tool_name ) {
				private string $name;
				public function __construct( string $name ) { $this->name = $name; }
				public function get_observability_context(): array { return array( 'ability_name' => $this->name ); }
			};
			$payload = $ability->execute( $input );
			if ( 'mcp-adapter/execute-ability' === $tool_name ) {
				$payload = array( 'success' => true, 'data' => $payload );
			}
			$carrier = Webmastery_MCP_Response::mcp_result( $payload, array( 'ability_name' => $name ), 'unused', $tool );
			self::assertInstanceOf( WP_Error::class, $carrier );
			$results[] = json_decode( $carrier->get_error_message(), true, 512, JSON_THROW_ON_ERROR );
		}
		foreach ( $results as $result ) {
			self::assertFalse( $result['success'] );
			self::assertSame( 'invalid_input', $result['error']['code'] );
			self::assertSame( 'ability_invalid_input', $result['error']['reason'] );
			self::assertSame( array( 'field' => $field, 'reason' => $reason ), (array) $result['error']['details'] );
			self::assertStringNotContainsString( 'PRIVATE', json_encode( $result ) );
		}
		self::assertSame( 0, $ability->permission_calls );
		self::assertSame( 0, $ability->execute_calls );
		self::assertSame( $before, serialize( $input ), 'Diagnostics must never mutate caller input.' );
	}

	public function test_provider_data_cannot_forge_provenance_and_clones_do_not_inherit_it(): void {
		$forged = array( 'field' => 'PRIVATE', 'reason' => 'required', 'webmastery_error' => Webmastery_MCP_Response::error( 'invalid_input', 'PRIVATE' ) );
		foreach ( array( new WP_Error( 'ability_invalid_input', 'PRIVATE', $forged ), Webmastery_MCP_Response::local_error( 'ability_invalid_input', 'Safe.', $forged ) ) as $error ) {
			self::assertSame( array(), (array) Webmastery_MCP_Response::from_wp_error( $error )['error']['details'] );
		}
		$error = Webmastery_MCP_Input::validate( 'PRIVATE', array( 'type' => 'integer' ) );
		self::assertSame( array(), Webmastery_MCP_Input::error_details( clone $error ) );
		$reference = WeakReference::create( $error );
		unset( $error );
		self::assertNull( $reference->get(), 'Diagnostics must not retain errors or caller data.' );
	}

	public function test_core_only_errors_and_reused_filter_errors_have_no_stale_diagnostics(): void {
		$error = new WP_Error( 'ability_invalid_input', 'PRIVATE filter', array( 'field' => 'PRIVATE' ) );
		$ability = new Webmastery_MCP_Ability( 'webmastery-site-toolkit-for-mcp/probe', array(
			'input_schema' => array( 'type' => 'integer' ),
			'permission_callback' => static fn() => true,
			'execute_callback' => static fn( $input ) => $input,
		) );
		$ability->hooks['wp_ability_validate_input'] = static fn() => $error;
		self::assertSame( $error, $ability->validate_input( 'PRIVATE' ) );
		self::assertSame( 'type', Webmastery_MCP_Input::error_details( $error )['reason'] );
		self::assertSame( $error, $ability->validate_input( 1 ) );
		self::assertSame( array(), Webmastery_MCP_Input::error_details( $error ) );
		foreach ( array(
			array( array( 'type' => 'string', 'format' => 'email' ), 'PRIVATE' ),
			array( array( 'type' => 'integer', 'oneOf' => array( array( 'enum' => array( 1 ) ) ) ), 2 ),
		) as [ $schema, $input ] ) {
			$ability->input_schema = $schema;
			$ability->hooks = array();
			$native = $ability->validate_input( $input );
			self::assertSame( $ability->last_parent_error, $native );
			self::assertSame( array(), (array) Webmastery_MCP_Response::from_wp_error( $native )['error']['details'] );
		}
		$custom = new WP_Error( 'custom_validation', 'PRIVATE' );
		Webmastery_MCP_Input::annotate_error( $custom, null, array( 'type' => 'integer' ) );
		self::assertSame( array(), Webmastery_MCP_Input::error_details( $custom ) );
	}

	public function test_deep_paths_are_bounded_while_validation_remains_strict(): void {
		$schema = array( 'type' => 'integer' );
		$invalid = 'PRIVATE';
		$valid = 1;
		for ( $i = 0; $i < 100; ++$i ) {
			$schema = array( 'type' => 'object', 'properties' => array( 'nested' => $schema ) );
			$invalid = array( 'nested' => $invalid );
			$valid = array( 'nested' => $valid );
		}
		$details = Webmastery_MCP_Input::error_details( Webmastery_MCP_Input::validate( $invalid, $schema ) );
		self::assertLessThanOrEqual( 243, strlen( $details['field'] ) );
		self::assertStringEndsWith( '...', $details['field'] );
		self::assertSame( 'type', $details['reason'] );
		self::assertNull( Webmastery_MCP_Input::validate( $valid, $schema ) );
	}
}
