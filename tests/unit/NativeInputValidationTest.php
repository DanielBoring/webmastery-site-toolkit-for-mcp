<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Plugin integration with a constrained lifecycle double, not actual-core proof.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class NativeInputValidationTest extends TestCase {
	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/class-response.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-input.php';
		require_once __DIR__ . '/fixtures/native-input-ability.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-ability.php';
	}

	private function definition( array $schema, array $overrides = array() ): array {
		return array_replace( array(
			'input_schema' => $schema,
			'permission_callback' => static fn() => true,
			'execute_callback' => static fn( $input = null ) => $input,
		), $overrides );
	}

	private function ability( array $schema, array $overrides = array() ): Webmastery_MCP_Ability {
		return new Webmastery_MCP_Ability( 'webmastery-site-toolkit-for-mcp/native-input-probe', $this->definition( $schema, $overrides ) );
	}

	private function schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'confirm' => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'force' => array( 'type' => 'boolean' ),
				'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish' ) ),
			),
			'required' => array( 'confirm', 'post_id' ),
			'additionalProperties' => false,
		);
	}

	private function assert_pre_permission_rejection( array $result, WP_Ability $ability ): void {
		self::assertFalse( $result['success'] );
		self::assertSame( 'invalid_input', $result['error']['code'] );
		self::assertSame( 'ability_invalid_input', $result['error']['reason'] );
		self::assertSame( 1, $ability->parent_validations );
		self::assertSame( 0, $ability->permission_calls );
		self::assertSame( 0, $ability->execute_calls );
		self::assertSame( array(
			'wp_ability_invoked', 'wp_pre_execute_ability', 'normalize_input',
			'wp_ability_normalize_input', 'parent_validate_input', 'wp_ability_validate_input',
		), $ability->events, 'Early native hooks are honored; permission and before/after hooks must not run.' );
	}

	public function test_parent_runs_exactly_once_before_strict_validation_using_current_schema(): void {
		$ability = $this->ability( array( 'type' => 'boolean' ) );
		$ability->hooks['wp_ability_validate_input'] = static function ( $result, $input, $name ) use ( $ability ) {
			self::assertTrue( $result, 'The parent accepts this coercible boolean.' );
			self::assertSame( 'false', $input );
			self::assertSame( 'webmastery-site-toolkit-for-mcp/native-input-probe', $name );
			self::assertSame( 1, $ability->parent_validations );
			// A schema change at the parent filter must be visible to strict validation.
			$ability->input_schema = array( 'type' => 'string', 'enum' => array( 'false' ) );
			return $result;
		};
		self::assertTrue( $ability->validate_input( 'false' ) );
		self::assertSame( 1, $ability->parent_validations );
		self::assertSame( array( 'parent_validate_input', 'wp_ability_validate_input' ), $ability->events );
	}

	public function test_parent_error_identity_wins_even_when_strict_validation_would_fail(): void {
		$ability = $this->ability( array( 'type' => 'boolean' ) );
		$error = $ability->validate_input( array( 'invalid' ) );
		self::assertSame( $ability->last_parent_error, $error );
		self::assertSame( 'ability_invalid_input', $error->get_error_code() );
		self::assertSame( 1, $ability->parent_validations );
	}

	public function test_empty_schema_preserves_null_success_and_missing_schema_error(): void {
		$ability = $this->ability( array() );
		self::assertTrue( $ability->validate_input() );
		self::assertSame( array( 'parent_validate_input' ), $ability->events );
		$ability = $this->ability( array() );
		$error = $ability->validate_input( array() );
		self::assertSame( $ability->last_parent_error, $error );
		self::assertSame( 'ability_missing_input_schema', $error->get_error_code() );
		self::assertSame( 1, $ability->parent_validations );
		$ability = $this->ability( array(), array( 'execute_callback' => static fn( ...$args ) => $args ) );
		self::assertSame( array(), $ability->execute(), 'Schema-less native callbacks receive no input argument.' );
	}

	public static function core_only_constraints(): array {
		return array(
			'email format' => array( array( 'type' => 'string', 'format' => 'email' ), 'not-an-email' ),
			'oneOf' => array( array( 'type' => 'integer', 'oneOf' => array( array( 'type' => 'integer', 'enum' => array( 1 ) ), array( 'type' => 'integer', 'enum' => array( 2 ) ) ) ), 3 ),
			'anyOf' => array( array( 'type' => 'integer', 'anyOf' => array( array( 'type' => 'integer', 'minimum' => 10 ), array( 'type' => 'integer', 'maximum' => 0 ) ) ), 5 ),
			'ambiguous oneOf' => array( array( 'type' => 'integer', 'oneOf' => array( array( 'type' => 'integer' ), array( 'type' => 'number' ) ) ), 5 ),
		);
	}

	/** @dataProvider core_only_constraints */
	public function test_core_only_constraints_are_not_replaced_by_strict_validation( array $schema, $input ): void {
		self::assertNull( Webmastery_MCP_Input::validate( $input, $schema ), 'This constraint is deliberately delegated to the parent.' );
		$ability = $this->ability( $schema );
		$error = $ability->validate_input( $input );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( $ability->last_parent_error, $error );
		self::assertSame( 1, $ability->parent_validations );
	}

	public function test_validation_filter_runs_once_and_preserves_its_error_identity(): void {
		$error = new WP_Error( 'custom_validation', 'PRIVATE filter diagnostic', array( 'status' => 422 ) );
		$ability = $this->ability( array( 'type' => 'boolean' ) );
		$calls = 0;
		$ability->hooks['wp_ability_validate_input'] = static function ( $result, $input ) use ( &$calls, $error ) {
			++$calls;
			self::assertTrue( $result );
			self::assertSame( 'false', $input );
			return $error;
		};
		self::assertSame( $error, $ability->validate_input( 'false' ) );
		self::assertSame( 1, $calls );
		self::assertSame( 1, $ability->parent_validations );
	}

	public function test_parent_filter_cannot_waive_plugin_strict_types(): void {
		foreach ( array( 'false', array( 'invalid' ) ) as $input ) {
			$ability = $this->ability( array( 'type' => 'boolean' ) );
			$ability->hooks['wp_ability_validate_input'] = static fn() => true;
			$this->assert_pre_permission_rejection( $ability->execute( $input ), $ability );
		}
	}

	public function test_validation_filter_observes_parent_error_and_false_becomes_parent_error(): void {
		$ability = $this->ability( array( 'type' => 'boolean' ) );
		$ability->hooks['wp_ability_validate_input'] = static function ( $result ) use ( $ability ) {
			self::assertSame( $ability->last_parent_error, $result );
			return $result;
		};
		self::assertSame( $ability->validate_input( array() ), $ability->last_parent_error );
		self::assertSame( 1, $ability->parent_validations );
		$ability = $this->ability( array( 'type' => 'boolean' ) );
		$ability->hooks['wp_ability_validate_input'] = static fn() => false;
		$error = $ability->validate_input( true );
		self::assertSame( $ability->last_parent_error, $error );
		self::assertSame( 'ability_invalid_input', $error->get_error_code() );
		self::assertSame( 1, $ability->parent_validations );
	}

	public function test_public_validation_does_not_normalize_or_apply_defaults(): void {
		$ability = $this->ability( array( 'type' => 'boolean', 'default' => true ) );
		$ability->hooks['wp_ability_normalize_input'] = static function () {
			self::fail( 'Public validation must not invoke normalization.' );
		};
		$error = $ability->validate_input();
		self::assertSame( $ability->last_parent_error, $error );
		self::assertSame( array( null ), $ability->validated_inputs );
		self::assertSame( array( 'parent_validate_input', 'wp_ability_validate_input' ), $ability->events );
	}

	public function test_execute_applies_default_then_normalizer_before_validation(): void {
		$default = (object) array( 'confirm' => true, 'post_id' => 42 );
		$normalized = (object) array( 'confirm' => true, 'post_id' => 43, 'force' => false );
		$ability = $this->ability( $this->schema() + array( 'default' => $default ) );
		$ability->hooks['wp_ability_normalize_input'] = static function ( $input ) use ( $default, $normalized ) {
			self::assertSame( $default, $input );
			return $normalized;
		};
		self::assertSame( $normalized, $ability->execute() );
		self::assertSame( array( $normalized ), $ability->validated_inputs );
		self::assertSame( array( $normalized ), $ability->permission_inputs );
		self::assertSame( array( $normalized ), $ability->execute_inputs );
		self::assertSame( 1, $ability->parent_validations );
	}

	public function test_strict_validation_consumes_normalized_input_not_raw_input(): void {
		$ability = $this->ability( array( 'type' => 'boolean' ) );
		$ability->hooks['wp_ability_normalize_input'] = static fn() => true;
		self::assertTrue( $ability->execute( 'false' ) );
		self::assertSame( array( true ), $ability->validated_inputs );
		$ability = $this->ability( array( 'type' => 'boolean' ) );
		$ability->hooks['wp_ability_normalize_input'] = static fn() => 'false';
		$this->assert_pre_permission_rejection( $ability->execute( true ), $ability );
	}

	public function test_normalization_errors_stop_before_validation_and_permissions(): void {
		$error = new WP_Error( 'ability_invalid_input', 'PRIVATE normalizer' );
		$ability = $this->ability( array( 'type' => 'boolean' ) );
		$ability->hooks['wp_ability_normalize_input'] = static fn() => $error;
		self::assertSame( $error, $ability->normalize_input( true ) );
		$ability->events = array();
		$result = $ability->execute( true );
		self::assertSame( 'ability_invalid_input', $result['error']['reason'] );
		self::assertSame( 0, $ability->parent_validations );
		self::assertSame( 0, $ability->permission_calls );
		self::assertSame( 0, $ability->execute_calls );
		self::assertSame( array( 'wp_ability_invoked', 'wp_pre_execute_ability', 'normalize_input', 'wp_ability_normalize_input' ), $ability->events );
	}

	public static function invalid_inputs(): array {
		$cases = array();
		foreach ( array( 'false', 'true', '0', '1', 0, 1, array(), array( true ), null ) as $index => $value ) {
			$cases[ 'boolean-' . $index ] = array( array( 'confirm' => true, 'post_id' => 42, 'force' => $value ) );
		}
		$cases['enum-case'] = array( array( 'confirm' => true, 'post_id' => 42, 'status' => 'Draft' ) );
		$cases['enum-false'] = array( array( 'confirm' => false, 'post_id' => 42 ) );
		$cases['numeric-string-id'] = array( array( 'confirm' => true, 'post_id' => '42' ) );
		$cases['null-input'] = array( null );
		return $cases;
	}

	/** @dataProvider invalid_inputs */
	public function test_invalid_input_never_reaches_permission_callback_or_execution( $input ): void {
		$never = static function () {
			self::fail( 'Invalid input reached a registered callback.' );
		};
		$ability = $this->ability( $this->schema(), array( 'permission_callback' => $never, 'execute_callback' => $never ) );
		$this->assert_pre_permission_rejection( $ability->execute( $input ), $ability );
	}

	public function test_valid_true_optional_false_and_omission_preserve_payload_and_native_hook_order(): void {
		foreach ( array(
			array( 'confirm' => true, 'post_id' => 42 ),
			array( 'confirm' => true, 'post_id' => 42, 'force' => false ),
			array( 'confirm' => true, 'post_id' => 42, 'force' => true ),
			(object) array( 'confirm' => true, 'post_id' => 42, 'force' => false ),
		) as $input ) {
			$ability = $this->ability( $this->schema() );
			self::assertSame( $input, $ability->execute( $input ) );
			self::assertSame( array( $input ), $ability->validated_inputs );
			self::assertSame( array( $input ), $ability->permission_inputs );
			self::assertSame( array( $input ), $ability->execute_inputs );
			self::assertSame( 1, $ability->parent_validations );
			self::assertSame( 1, $ability->permission_calls );
			self::assertSame( 1, $ability->execute_calls );
			self::assertSame( array(
				'wp_ability_invoked', 'wp_pre_execute_ability', 'normalize_input',
				'wp_ability_normalize_input', 'parent_validate_input', 'wp_ability_validate_input',
				'check_permissions', 'wp_ability_permission_result', 'wp_before_execute_ability',
				'do_execute', 'wp_ability_execute_result', 'validate_output',
				'wp_ability_validate_output', 'wp_after_execute_ability',
			), $ability->events );
		}
	}

	public function test_valid_input_denial_remains_native_permission_masking(): void {
		foreach ( array( false, new WP_Error( 'private_denial', 'PRIVATE denial' ) ) as $denial ) {
			$ability = $this->ability( $this->schema(), array( 'permission_callback' => static fn() => $denial ) );
			$result = $ability->execute( array( 'confirm' => true, 'post_id' => 42 ) );
			self::assertSame( 'forbidden', $result['error']['code'] );
			self::assertSame( 'ability_invalid_permissions', $result['error']['reason'] );
			self::assertSame( 1, $ability->parent_validations );
			self::assertSame( 1, $ability->permission_calls );
			self::assertSame( 0, $ability->execute_calls );
			self::assertContains( 'permission_error_masked', $ability->events );
			self::assertNotContains( 'wp_before_execute_ability', $ability->events );
			self::assertNotContains( 'wp_after_execute_ability', $ability->events );
			self::assertStringNotContainsString( 'PRIVATE', json_encode( $result ) );
		}
	}

	public function test_raw_wrapped_permission_retains_strict_error_while_native_execute_stops_earlier(): void {
		$name = 'webmastery-site-toolkit-for-mcp/native-input-probe';
		$args = Webmastery_MCP_Input::register_args( $this->definition( $this->schema() ), $name );
		$input = array( 'confirm' => true, 'post_id' => 42, 'force' => 'false' );
		$raw = $args['permission_callback']( $input );
		self::assertInstanceOf( WP_Error::class, $raw );
		self::assertSame( 'ability_invalid_input', Webmastery_MCP_Response::from_wp_error( $raw )['error']['reason'] );
		$ability = new Webmastery_MCP_Ability( $name, $args );
		$this->assert_pre_permission_rejection( $ability->execute( $input ), $ability );
	}

	public function test_callback_exceptions_and_output_validation_still_use_existing_boundaries(): void {
		foreach ( array( false, true ) as $legacy ) {
			foreach ( array( new RuntimeException( 'PRIVATE callback' ), new Error( 'PRIVATE engine' ) ) as $error ) {
				$ability = $this->ability( array( 'type' => 'boolean' ), array( 'execute_callback' => static function () use ( $error ) {
					throw $error;
				} ) );
				$ability->legacy_callbacks = $legacy;
				$result = $ability->execute( true );
				self::assertSame( 'ability_callback_exception', $result['error']['reason'] );
				self::assertSame( 1, $ability->execute_calls );
				self::assertNotContains( 'validate_output', $ability->events );
				self::assertNotContains( 'wp_after_execute_ability', $ability->events );
				self::assertStringNotContainsString( 'PRIVATE', json_encode( $result ) );
			}
		}
		$ability = $this->ability( array( 'type' => 'boolean' ), array( 'output_schema' => array( 'type' => 'integer' ) ) );
		$result = $ability->execute( true );
		self::assertSame( 'ability_invalid_output', $result['error']['reason'] );
		self::assertSame( 1, $ability->execute_calls );
		self::assertContains( 'wp_ability_validate_output', $ability->events );
		self::assertNotContains( 'wp_after_execute_ability', $ability->events );
	}

	public function test_foreign_ability_keeps_coercible_input_and_native_error_objects(): void {
		$args = $this->definition( array( 'type' => 'boolean' ) );
		self::assertSame( $args, Webmastery_MCP_Response::register_args( $args, 'foreign/probe' ) );
		self::assertSame( $args, Webmastery_MCP_Input::register_args( $args, 'foreign/probe' ) );
		$ability = new WP_Ability( 'foreign/probe', $args );
		self::assertSame( 'false', $ability->execute( 'false' ) );
		self::assertSame( 1, $ability->permission_calls );
		$error = new WP_Error( 'foreign_error', 'Unmodified provider error' );
		$args['execute_callback'] = static fn() => $error;
		$ability = new WP_Ability( 'foreign/probe', $args );
		self::assertSame( $error, $ability->execute( true ) );
	}

	public function test_71_preexecute_short_circuits_honor_null_false_and_object_results(): void {
		foreach ( array( null, false, (object) array( 'cached' => true ) ) as $cached ) {
			$ability = $this->ability( array( 'type' => 'boolean' ) );
			$ability->hooks['wp_pre_execute_ability'] = static function ( $sentinel, $name, $raw, $instance ) use ( $cached, $ability ) {
				self::assertInstanceOf( stdClass::class, $sentinel );
				self::assertSame( 'invalid', $raw );
				self::assertSame( $ability, $instance );
				return $cached;
			};
			self::assertSame( $cached, $ability->execute( 'invalid' ) );
			self::assertSame( array( 'wp_ability_invoked', 'wp_pre_execute_ability' ), $ability->events );
			self::assertSame( 0, $ability->parent_validations );
			self::assertSame( 0, $ability->permission_calls );
			self::assertSame( 0, $ability->execute_calls );
		}
	}

	public function test_71_permission_execute_and_output_filters_remain_effective(): void {
		$ability = $this->ability( array( 'type' => 'boolean' ), array( 'permission_callback' => static fn() => false ) );
		$ability->hooks['wp_ability_permission_result'] = static fn() => true;
		$payload = (object) array( 'filtered' => true );
		$ability->hooks['wp_ability_execute_result'] = static fn() => $payload;
		self::assertSame( $payload, $ability->execute( true ) );
		$ability->hooks['wp_ability_validate_output'] = static fn() => false;
		self::assertSame( 'ability_invalid_output', $ability->execute( true )['error']['reason'] );
	}

	public function test_71_actions_receive_raw_then_normalized_input_and_final_result(): void {
		$ability = $this->ability( array( 'type' => 'boolean' ) );
		$observed = array();
		$ability->hooks['wp_ability_invoked'] = static function ( $name, $input, $instance ) use ( &$observed, $ability ) {
			self::assertSame( $ability, $instance );
			$observed[] = array( 'invoked', $name, $input );
		};
		$ability->hooks['wp_ability_normalize_input'] = static fn() => true;
		$ability->hooks['wp_before_execute_ability'] = static function ( $name, $input, $instance ) use ( &$observed, $ability ) {
			self::assertSame( $ability, $instance );
			$observed[] = array( 'before', $name, $input );
		};
		$payload = (object) array( 'filtered' => true );
		$ability->hooks['wp_ability_execute_result'] = static fn() => $payload;
		$ability->hooks['wp_after_execute_ability'] = static function ( $name, $input, $result, $instance ) use ( &$observed, $ability, $payload ) {
			self::assertSame( $ability, $instance );
			self::assertSame( $payload, $result );
			$observed[] = array( 'after', $name, $input );
		};
		self::assertSame( $payload, $ability->execute( 'false' ) );
		$name = 'webmastery-site-toolkit-for-mcp/native-input-probe';
		self::assertSame( array( array( 'invoked', $name, 'false' ), array( 'before', $name, true ), array( 'after', $name, true ) ), $observed );
	}

	public function test_double_rejects_unknown_schema_types_instead_of_faking_success(): void {
		$ability = $this->ability( array( 'type' => 'unsupported-test-type' ) );
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Schema type outside the lifecycle double boundary' );
		$ability->validate_input( true );
	}

	public function test_negative_control_detects_strict_call_removed_from_actual_production_source(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ability.php' );
		$mutated = preg_replace( '/Webmastery_MCP_Input::validate\(\s*\$input\s*,\s*\$\w+\s*\)/', 'null', $source, -1, $count );
		self::assertSame( 1, $count, 'Mutation must disable exactly the production strict validation call.' );
		$root = dirname( __DIR__, 2 );
		$script = 'require ' . var_export( __DIR__ . '/bootstrap.php', true ) . ';'
			. 'require ' . var_export( $root . '/includes/class-input.php', true ) . ';'
			. 'require ' . var_export( __DIR__ . '/fixtures/native-input-ability.php', true ) . ';'
			. 'eval(' . var_export( substr( $mutated, 5 ), true ) . ');'
			. '$name = "webmastery-site-toolkit-for-mcp/mutation-probe";'
			. '$args = Webmastery_MCP_Input::register_args(array('
			. '"input_schema" => array("type" => "boolean"),'
			. '"permission_callback" => static fn() => true,'
			. '"execute_callback" => static fn($input) => $input), $name);'
			. '$ability = new Webmastery_MCP_Ability($name, $args);'
			. '$result = $ability->execute("false");'
			. 'echo json_encode(array("result" => $result, "parent" => $ability->parent_validations,'
			. '"permission" => $ability->permission_calls, "execute" => $ability->execute_calls, "events" => $ability->events), JSON_THROW_ON_ERROR);';
		$process = proc_open( array( PHP_BINARY, '-r', $script ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $root );
		self::assertIsResource( $process );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $stderr );
		self::assertSame( '', $stderr );
		$probe = json_decode( $stdout, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 1, $probe['parent'] );
		self::assertSame( 1, $probe['permission'], 'Removing strict native validation must reach the wrapped permission stage.' );
		self::assertSame( 0, $probe['execute'] );
		self::assertSame( 'ability_invalid_permissions', $probe['result']['error']['reason'] );
		self::assertContains( 'permission_error_masked', $probe['events'] );
		self::assertNotContains( 'wp_before_execute_ability', $probe['events'] );
		self::assertNotContains( 'wp_after_execute_ability', $probe['events'] );
		// Feed the child's observations to the same oracle used by regression cases.
		$observed = $this->ability( array( 'type' => 'boolean' ) );
		$observed->parent_validations = $probe['parent'];
		$observed->permission_calls = $probe['permission'];
		$observed->execute_calls = $probe['execute'];
		$observed->events = $probe['events'];
		$detected = false;
		try {
			$this->assert_pre_permission_rejection( $probe['result'], $observed );
		} catch ( AssertionFailedError $failure ) {
			$detected = true;
		}
		self::assertTrue( $detected, 'The regression oracle must reject this mutant, not merely exercise it.' );
	}
}
