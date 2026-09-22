<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Actual plugin callbacks with the existing constrained lifecycle double.
 * Official-core/Adapter library probes are separate evidence, not this double.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PermissionDefaultTest extends TestCase {
	private array $permissions = array();
	private array $executions = array();

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/class-input.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-database-health.php';
		require_once __DIR__ . '/fixtures/native-input-ability.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-ability.php';
		$GLOBALS['wstm_test_user_caps'] = array( 'manage_options' );
		$GLOBALS['wstm_test_cap_calls'] = array();
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'private_';
			public string $dbname = 'private_database';
			public string $posts = 'private_posts';
			public string $postmeta = 'private_postmeta';
			public string $options = 'private_options';
			public string $last_error = '';
			public array $queries = array();
			public array $prepared = array();
			public function prepare( $query, ...$args ): string {
				$this->prepared[] = array( $query, $args );
				return $query;
			}
			public function esc_like( $value ): string { return addcslashes( $value, '_%\\' ); }
			public function get_var( $query ): string {
				$this->queries[] = $query;
				return '7';
			}
			public function get_results( $query, $output ): array {
				$this->queries[] = $query;
				return array_map( static fn( $name ) => array(
					'table_name' => $name, 'row_count' => '7', 'data_bytes' => '14', 'index_bytes' => '3', 'total_bytes' => '17',
				), array( 'private_posts', 'private_secret_plugin' ) );
			}
			public function tables( $scope, $prefix ): array { return array( 'posts' => $this->posts ); }
		};
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
	}

	private function schema(): array {
		return array(
			'type' => 'object', 'default' => array(),
			'properties' => array( 'include_table_names' => array( 'type' => 'boolean', 'default' => false ) ),
		);
	}

	private function definition( array $schema, ?callable $permission = null, ?callable $execute = null ): array {
		return array(
			'input_schema' => $schema,
			'permission_callback' => function ( $input = null ) use ( $permission ) {
				$this->permissions[] = $input;
				return null === $permission ? true : $permission( $input );
			},
			'execute_callback' => function ( $input = null ) use ( $execute ) {
				$this->executions[] = $input;
				return null === $execute ? $input : $execute( $input );
			},
		);
	}

	private function wrapped( array $args, string $name ): array {
		return Webmastery_MCP_Input::register_args( Webmastery_MCP_Response::register_args( $args, $name ), $name );
	}

	private function ability( array $schema, ?callable $permission = null, string $class = Webmastery_MCP_Ability::class ): WP_Ability {
		$name = 'webmastery-site-toolkit-for-mcp/permission-default-probe';
		return new $class( $name, $this->wrapped( $this->definition( $schema, $permission ), $name ) );
	}

	private function database_ability(): array {
		Webmastery_MCP_Database_Health::register();
		$name = 'webmastery-site-toolkit-for-mcp/database-health';
		$registered = $GLOBALS['wstm_test_abilities'][ $name ];
		$args = $this->wrapped( array_replace(
			$registered,
			$this->definition( $registered['input_schema'], $registered['permission_callback'], $registered['execute_callback'] )
		), $name );
		return array( new Webmastery_MCP_Ability( $name, $args ), $args );
	}

	private function assert_error( $result, string $code = 'invalid_input', string $reason = 'ability_invalid_input', array $details = array() ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
		$envelope = Webmastery_MCP_Response::from_wp_error( $result );
		self::assertFalse( $envelope['success'] );
		self::assertSame( $code, $envelope['error']['code'] );
		self::assertSame( $reason, $envelope['error']['reason'] );
		self::assertInstanceOf( stdClass::class, $envelope['error']['details'] );
		self::assertSame( $details, (array) $envelope['error']['details'] );
		if ( 'ability_invalid_input' === $reason ) {
			self::assertSame( 'Ability input does not match its schema.', $envelope['error']['message'] );
		}
		self::assertSame( wp_json_encode( $envelope, JSON_INVALID_UTF8_SUBSTITUTE ), $result->get_error_message(), 'Carrier payload remains canonical.' );
	}

	private function assert_preflight_only( WP_Ability $ability ): void {
		self::assertSame( 1, $ability->permission_calls );
		self::assertSame( 0, $ability->parent_validations );
		self::assertSame( 0, $ability->execute_calls );
		self::assertSame( array( 'check_permissions', 'wp_ability_permission_result' ), $ability->events );
		self::assertSame( array(), $GLOBALS['wpdb']->queries );
		self::assertSame( array(), $GLOBALS['wpdb']->prepared );
	}

	public static function default_roles(): array {
		return array(
			'admin omitted' => array( true, true ), 'admin null' => array( true, false ),
			'subscriber omitted' => array( false, true ), 'subscriber null' => array( false, false ),
		);
	}

	/** @dataProvider default_roles */
	public function test_actual_database_registration_defaults_before_permission_without_normalization( bool $admin, bool $omitted ): void {
		$GLOBALS['wstm_test_user_caps'] = $admin ? array( 'manage_options' ) : array( 'read' );
		list( $ability ) = $this->database_ability();
		$ability->hooks['wp_ability_normalize_input'] = static function () { self::fail( 'Standalone preflight must not normalize.' ); };
		$result = $omitted ? $ability->check_permissions() : $ability->check_permissions( null );
		if ( $admin ) {
			self::assertTrue( $result );
		} else {
			$this->assert_error( $result, 'forbidden', 'forbidden' );
			self::assertSame( 'Requires manage_options capability.', Webmastery_MCP_Response::from_wp_error( $result )['error']['message'] );
		}
		self::assertSame( array( array() ), $this->permissions );
		self::assertSame( array(), $this->executions );
		self::assertSame( array( array( 'manage_options' ) ), $GLOBALS['wstm_test_cap_calls'] );
		$this->assert_preflight_only( $ability );
	}

	public function test_actual_default_redaction_and_opt_in_metrics_survive_preflight_and_native_execution(): void {
		$results = array();
		foreach ( array( null, array(), (object) array(), array( 'include_table_names' => false ), array( 'include_table_names' => true ) ) as $input ) {
			list( $ability ) = $this->database_ability();
			self::assertTrue( $ability->check_permissions( $input ) );
			$ability->events = array();
			$result = $ability->execute( $input );
			self::assertTrue( $result['success'] );
			self::assertSame( 1, array_count_values( $ability->events )['wp_ability_normalize_input'] );
			self::assertSame( 1, $ability->parent_validations );
			self::assertSame( 2, $ability->permission_calls );
			self::assertSame( 1, $ability->execute_calls );
			$results[] = $result;
		}
		foreach ( array_slice( $results, 0, 4 ) as $result ) {
			self::assertSame( $results[0], $result );
			self::assertSame( array( 'posts', 'custom_table_1' ), array_column( $result['data']['table_sizes'], 'table' ) );
			self::assertStringNotContainsString( 'private_', json_encode( $result, JSON_THROW_ON_ERROR ) );
			self::assertStringNotContainsString( 'secret_plugin', json_encode( $result, JSON_THROW_ON_ERROR ) );
		}
		self::assertSame( array( 'private_posts', 'private_secret_plugin' ), array_column( $results[4]['data']['table_sizes'], 'table' ) );
		foreach ( $results[4]['data']['table_sizes'] as $index => &$row ) {
			$row['table'] = $results[0]['data']['table_sizes'][ $index ]['table'];
		}
		unset( $row );
		self::assertSame( $results[0], $results[4] );
	}

	public function test_raw_callbacks_and_public_validation_do_not_materialize_database_default(): void {
		list( $ability, $args ) = $this->database_ability();
		$this->assert_error( $args['permission_callback']( null ) );
		$execute = $args['execute_callback']( null );
		self::assertSame( 'invalid_input', $execute['error']['code'] );
		self::assertSame( 'ability_invalid_input', $execute['error']['reason'] );
		$validation = $ability->validate_input();
		self::assertSame( $ability->last_parent_error, $validation );
		self::assertSame( array(), $this->permissions );
		self::assertSame( array(), $this->executions );
		self::assertSame( array(), $GLOBALS['wstm_test_cap_calls'] );
		self::assertSame( array(), $GLOBALS['wpdb']->queries );
		self::assertSame( array( 'parent_validate_input', 'wp_ability_validate_input' ), $ability->events );
	}

	public function test_lifecycle_double_accepts_description_annotation_but_rejects_unknown_constraints(): void {
		$schema = $this->schema();
		$schema['properties']['include_table_names']['description'] = 'Annotation, not a validation rule.';
		self::assertTrue( $this->ability( $schema )->validate_input( array( 'include_table_names' => true ) ) );
		$schema['properties']['include_table_names']['unmodeled_constraint'] = true;
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Schema keyword outside the lifecycle double boundary.' );
		$this->ability( $schema )->validate_input( array( 'include_table_names' => true ) );
	}

	public static function malformed_flags(): array {
		return array_map( static fn( $value ) => array( $value ), array( null, 'true', 'false', 0, 1, 0.0, 1.0, array(), array( true ), (object) array() ) );
	}

	/** @dataProvider malformed_flags */
	public function test_non_null_malformed_input_is_not_replaced_or_coerced( $value ): void {
		foreach ( array( true, false ) as $admin ) {
			$GLOBALS['wstm_test_user_caps'] = $admin ? array( 'manage_options' ) : array( 'read' );
			list( $ability ) = $this->database_ability();
			$input = array( 'include_table_names' => $value );
			$this->assert_error( $ability->check_permissions( $input ) );
			self::assertSame( array( $input ), $ability->permission_inputs );
			$this->assert_preflight_only( $ability );
			self::assertSame( array(), $this->permissions );
			self::assertSame( array(), $this->executions );
			self::assertSame( array(), $GLOBALS['wstm_test_cap_calls'] );
		}
	}

	public static function invalid_defaults(): array {
		return array_map( static fn( $value ) => array( $value ), array(
			null, false, true, 0, 1, '', 'object', array( true ),
			array( 'unknown' => true ), array( 'include_table_names' => null ), array( 'include_table_names' => 'true' ),
		) );
	}

	/** @dataProvider invalid_defaults */
	public function test_declared_invalid_defaults_remain_strictly_rejected( $default ): void {
		$schema = $this->schema();
		$schema['default'] = $default;
		$ability = $this->ability( $schema );
		$this->assert_error( $ability->check_permissions() );
		self::assertSame( array( $default ), $ability->permission_inputs );
		self::assertSame( array(), $this->permissions );
		self::assertSame( array(), $GLOBALS['wstm_test_cap_calls'] );
		$this->assert_preflight_only( $ability );
	}

	public function test_required_default_is_checked_not_assumed_valid(): void {
		$schema = $this->schema() + array( 'required' => array( 'include_table_names' ) );
		$this->assert_error( $this->ability( $schema )->check_permissions() );
		self::assertSame( array(), $this->permissions );
		$schema['default'] = (object) array( 'include_table_names' => true );
		$ability = $this->ability( $schema );
		self::assertTrue( $ability->check_permissions() );
		self::assertSame( array( array( 'include_table_names' => true ) ), $this->permissions );
		self::assertSame( array( $schema['default'] ), $ability->permission_inputs );
		$this->assert_preflight_only( $ability );
	}

	public static function excluded_schemas(): array {
		return array(
			'object no default' => array( array( 'type' => 'object', 'properties' => array() ), false ),
			'boolean default' => array( array( 'type' => 'boolean', 'default' => true ), false ),
			'integer zero default' => array( array( 'type' => 'integer', 'default' => 0 ), false ),
			'string empty default' => array( array( 'type' => 'string', 'default' => '' ), false ),
			'array default' => array( array( 'type' => 'array', 'default' => array() ), false ),
			'nullable object' => array( array( 'type' => array( 'object', 'null' ), 'default' => array() ), true ),
			'object union without null' => array( array( 'type' => array( 'object' ), 'default' => array() ), false ),
			'untyped default' => array( array( 'default' => array( 'untouched' => true ) ), true ),
		);
	}

	/** @dataProvider excluded_schemas */
	public function test_only_exact_object_root_with_declared_default_changes( array $schema, bool $allowed ): void {
		$ability = $this->ability( $schema );
		$result = $ability->check_permissions();
		if ( $allowed ) {
			self::assertTrue( $result );
			self::assertSame( array( null ), $this->permissions );
		} else {
			$this->assert_error( $result );
			self::assertSame( array(), $this->permissions );
		}
		self::assertSame( array( null ), $ability->permission_inputs );
		$this->assert_preflight_only( $ability );
	}

	public function test_original_empty_schema_synthetic_default_and_foreign_namespace_stay_unchanged(): void {
		$name = 'webmastery-site-toolkit-for-mcp/no-input-probe';
		$args = $this->wrapped( $this->definition( array() ), $name );
		self::assertTrue( $args['permission_callback']( null ), 'Existing no-input raw exception is preserved.' );
		self::assertTrue( ( new Webmastery_MCP_Ability( $name, $args ) )->check_permissions() );
		self::assertSame( array( null, null ), $this->permissions );
		$foreign = $this->definition( $this->schema() );
		self::assertSame( $foreign, $this->wrapped( $foreign, 'foreign/default-probe' ) );
		$ability = new WP_Ability( 'foreign/default-probe', $foreign );
		self::assertTrue( $ability->check_permissions() );
		self::assertSame( array( null ), $ability->permission_inputs );
		$ability = new Webmastery_MCP_Ability( $name, $this->definition( array() ) );
		self::assertTrue( $ability->check_permissions() );
		self::assertSame( array( null ), $ability->permission_inputs );
		$this->assert_preflight_only( $ability );
	}

	public function test_metadata_default_preserves_presence_first_rejection(): void {
		$name = 'webmastery-site-toolkit-for-mcp/update-post';
		$schema = array( 'type' => 'object', 'properties' => array(), 'default' => array( 'meta_input' => null ) );
		$ability = new Webmastery_MCP_Ability( $name, $this->wrapped( $this->definition( $schema ), $name ) );
		$this->assert_error( $ability->check_permissions(), 'invalid_input', 'metadata_requires_separate_call', array( 'fields' => array( 'meta_input' ) ) );
		self::assertSame( array(), $this->permissions );
		self::assertSame( array(), $this->executions );
		self::assertSame( array(), $GLOBALS['wstm_test_cap_calls'] );
		$this->assert_preflight_only( $ability );
	}

	public function test_normalization_filter_runs_once_only_in_native_execute_and_can_change_default(): void {
		$ability = $this->ability( $this->schema() );
		$count = 0;
		$ability->hooks['wp_ability_normalize_input'] = static function ( $input ) use ( &$count ) {
			++$count;
			self::assertSame( array(), $input );
			return array( 'include_table_names' => true );
		};
		self::assertTrue( $ability->check_permissions() );
		self::assertSame( 0, $count );
		self::assertSame( array( 'include_table_names' => true ), $ability->execute() );
		self::assertSame( 1, $count );
		self::assertSame( array( array(), array( 'include_table_names' => true ) ), $this->permissions );
		self::assertSame( array( array( 'include_table_names' => true ) ), $this->executions );
		self::assertSame( 1, $ability->parent_validations );
	}

	public function test_normalizer_null_or_error_cannot_be_repaired_by_permission_default(): void {
		foreach ( array( null, new WP_Error( 'ability_invalid_input', 'PRIVATE filter' ) ) as $normalized ) {
			$ability = $this->ability( $this->schema() );
			$ability->hooks['wp_ability_normalize_input'] = static fn() => $normalized;
			$ability->hooks['wp_ability_validate_input'] = static fn() => true;
			$result = $ability->execute();
			self::assertSame( 'ability_invalid_input', $result['error']['reason'] );
			self::assertSame( 0, $ability->permission_calls );
			self::assertSame( 0, $ability->execute_calls );
			self::assertSame( array(), $this->permissions );
			self::assertSame( array(), $this->executions );
			self::assertSame( 1, array_count_values( $ability->events )['wp_ability_normalize_input'] );
		}
		$schema = array( 'type' => array( 'object', 'null' ), 'default' => array() );
		$ability = $this->ability( $schema );
		$ability->hooks['wp_ability_normalize_input'] = static fn() => null;
		self::assertNull( $ability->execute() );
		self::assertSame( array( null ), $this->permissions );
		self::assertSame( array( null ), $this->executions );
	}

	public function test_false_error_and_throwing_permissions_never_become_truthy_carriers(): void {
		foreach ( array( false, true ) as $legacy ) {
			foreach ( array( false, new WP_Error( 'private_denial', 'PRIVATE denial' ), new RuntimeException( 'PRIVATE exception' ), new Error( 'PRIVATE engine' ) ) as $denial ) {
				$ability = $this->ability( $this->schema(), static function () use ( $denial ) {
					if ( $denial instanceof Throwable ) {
						throw $denial;
					}
					return $denial;
				} );
				$ability->legacy_callbacks = $legacy;
				$result = $ability->check_permissions();
				$reason = $denial instanceof Throwable ? 'ability_callback_exception' : ( false === $denial ? 'forbidden' : 'external_error' );
				$this->assert_error( $result, false === $denial ? 'forbidden' : 'upstream_failed', $reason );
				self::assertStringNotContainsString( 'PRIVATE', $result->get_error_message() );
				$this->assert_preflight_only( $ability );
				$native = $ability->execute();
				self::assertSame( 'forbidden', $native['error']['code'] );
				self::assertSame( 'ability_invalid_permissions', $native['error']['reason'] );
				self::assertSame( 0, $ability->execute_calls );
			}
		}
	}

	public function test_permission_and_output_filters_short_circuit_and_reentrancy_keep_core_lifecycle(): void {
		$ability = $this->ability( $this->schema(), static fn() => false );
		$ability->hooks['wp_ability_permission_result'] = static fn() => true;
		self::assertTrue( $ability->check_permissions() );
		$ability->hooks['wp_ability_execute_result'] = static fn() => array( 'filtered' => true );
		self::assertSame( array( 'filtered' => true ), $ability->execute() );
		$ability->hooks['wp_ability_validate_output'] = static fn() => false;
		self::assertSame( 'ability_invalid_output', $ability->execute()['error']['reason'] );
		foreach ( array( null, false, (object) array( 'cached' => true ) ) as $cached ) {
			$ability = $this->ability( $this->schema() );
			$ability->hooks['wp_pre_execute_ability'] = static fn() => $cached;
			self::assertSame( $cached, $ability->execute() );
			self::assertSame( array( 'wp_ability_invoked', 'wp_pre_execute_ability' ), $ability->events );
		}
		$this->permissions = $this->executions = array();
		$ability = $this->ability( $this->schema() );
		$ability->hooks['wp_ability_normalize_input'] = static function ( $input ) use ( $ability ) {
			self::assertTrue( $ability->check_permissions(), 'Reentrant standalone check must not rerun this normalizer.' );
			return $input;
		};
		self::assertSame( array(), $ability->execute() );
		self::assertSame( array( array(), array() ), $this->permissions );
		self::assertSame( array( array() ), $this->executions );
		self::assertSame( 1, array_count_values( $ability->events )['wp_ability_normalize_input'] );
	}

	private function assert_default_contract( string $class ): void {
		$schema = $this->schema();
		$schema['default'] = array( 'include_table_names' => true );
		$ability = $this->ability( $schema, null, $class );
		self::assertTrue( $ability->check_permissions() );
		self::assertSame( array( $schema['default'] ), $ability->permission_inputs );
		$this->assert_preflight_only( $ability );
		$ability = $this->ability( $schema, null, $class );
		self::assertTrue( $ability->check_permissions( array() ) );
		self::assertSame( array( array() ), $ability->permission_inputs );
		$this->assert_preflight_only( $ability );
		$ability = $this->ability( $this->schema(), null, $class );
		self::assertTrue( $ability->check_permissions() );
		self::assertSame( array( array() ), $ability->permission_inputs );
		$ability = $this->ability( array( 'type' => 'boolean', 'default' => true ), null, $class );
		$this->assert_error( $ability->check_permissions() );
		$ability = $this->ability( array( 'type' => array( 'object', 'null' ), 'default' => array() ), null, $class );
		self::assertTrue( $ability->check_permissions() );
		self::assertSame( array( null ), $ability->permission_inputs );
	}

	public static function default_mutations(): array {
		return array(
			'old missing materialization' => array( '$input = $schema[\'default\'];', '$input = $input;' ),
			'replayed normalization' => array( '$input = $schema[\'default\'];', '$input = $this->normalize_input( $input );' ),
			'ignore declared value' => array( '$input = $schema[\'default\'];', '$input = [];' ),
			'coerce null instead' => array( '$input = $schema[\'default\'];', '$input = (array) $input;' ),
			'loose null comparison' => array( 'null === $input &&', 'null == $input &&' ),
			'expand nullable root' => array( "'object' === ( \$schema['type'] ?? null )", "in_array( 'object', (array) ( \$schema['type'] ?? null ), true )" ),
			'expand scalar root' => array( "'object' === ( \$schema['type'] ?? null )", "true" ),
			'ignore empty default' => array( "array_key_exists( 'default', \$schema )", "! empty( \$schema['default'] )" ),
			'duplicate parent permission' => array( 'parent::check_permissions( $input )', '( parent::check_permissions( $input ) && parent::check_permissions( $input ) )' ),
			'truthy error authorization' => array( 'true === $result', '$result' ),
		);
	}

	/** @dataProvider default_mutations */
	public function test_mutations_of_actual_permission_entry_fail_the_same_contract( string $before, string $after ): void {
		$this->assert_default_contract( Webmastery_MCP_Ability::class );
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ability.php' );
		$start = strpos( $source, 'public function check_permissions(' );
		self::assertNotFalse( $start );
		$tail = str_replace( $before, $after, substr( $source, $start ), $count );
		self::assertSame( 1, $count, 'Mutation must affect exactly the actual permission method.' );
		$source = substr( $source, 0, $start ) . $tail;
		$source = str_replace( 'final class Webmastery_MCP_Ability', 'final class Wstm_Permission_Default_Mutant', $source, $count );
		self::assertSame( 1, $count );
		eval( substr( $source, 5 ) );
		$this->expectException( AssertionFailedError::class );
		$this->assert_default_contract( Wstm_Permission_Default_Mutant::class );
	}
}
