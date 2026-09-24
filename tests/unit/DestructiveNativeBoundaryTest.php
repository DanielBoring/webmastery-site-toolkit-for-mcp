<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm126Destructive\Probe;

/**
 * Exact manifest inputs through production classes and a limited lifecycle double.
 * This is not WordPress core, Adapter or HTTP runtime acceptance.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DestructiveNativeBoundaryTest extends TestCase {
	protected function setUp(): void {
		require_once __DIR__ . '/fixtures/native-input-ability.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-ability.php';
		require_once __DIR__ . '/fixtures/destructive-native-boundary.php';
		Probe::reset();
		$GLOBALS['wpdb'] = new Wstm126Destructive\Database();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public static function manifest_inputs(): array {
		$root = dirname( __DIR__ ) . '/e2e/';
		$history = json_decode( file_get_contents( $root . 'destructive-safety-boolean-ledger.json' ), true, 512, JSON_THROW_ON_ERROR );
		$manifest = json_decode( file_get_contents( $root . 'abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		$cases = array();
		foreach ( $history['cases'] as $row ) {
			$matches = array_values( array_filter( $manifest, static fn( array $case ): bool => $case['label'] === $row['label'] ) );
			if ( count( $matches ) !== 1 || isset( $cases[ $row['label'] ] ) ) {
				throw new RuntimeException( 'Missing or duplicated exact boolean manifest case.' );
			}
			$cases[ $row['label'] ] = array( $matches[0], $row );
		}
		if ( count( $cases ) !== 16 ) {
			throw new RuntimeException( 'Expected the exact sixteen historical boolean cases.' );
		}
		return $cases;
	}

	private function resolve_fixture_ids( array $input ): array {
		$fixtures = array(
			'__bulk_trash_post_id__' => 42, '__bulk_publish_post_id__' => 42,
			'__delete_media_id__' => 46, '__delete_category_id__' => 51, '__delete_tag_id__' => 52,
		);
		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) ) {
				$input[ $key ] = $this->resolve_fixture_ids( $value );
			} elseif ( is_string( $value ) && str_starts_with( $value, '__' ) ) {
				self::assertArrayHasKey( $value, $fixtures );
				$input[ $key ] = $fixtures[ $value ];
			}
		}
		return $input;
	}

	private function assert_error( $result, string $code, string $reason, string $message ): void {
		self::assertIsArray( $result );
		self::assertSame( array( 'success', 'error' ), array_keys( $result ) );
		self::assertFalse( $result['success'] );
		self::assertSame( array( 'code', 'reason', 'message', 'details' ), array_keys( $result['error'] ) );
		self::assertSame( array( $code, $reason, $message ), array_values( array_diff_key( $result['error'], array( 'details' => true ) ) ) );
		self::assertInstanceOf( stdClass::class, $result['error']['details'] );
		self::assertSame( array(), get_object_vars( $result['error']['details'] ) );
	}

	/** @dataProvider manifest_inputs */
	public function test_exact_boolean_inputs_keep_native_and_direct_error_layers_distinct( array $case, array $history ): void {
		self::assertSame( 'editor', $case['role'] );
		self::assertSame( 'failure', $case['expect'] );
		self::assertSame( 'canonical', $case['expect_error_shape'] );
		self::assertTrue( $case['assert_unchanged'] );
		self::assertSame( $history['value'], $case['input'][ $history['flag'] ] );
		self::assertSame( 'invalid_input', $case['expect_error_code'] );
		self::assertSame( 'ability_invalid_input', $case['expect_error_reason'] );
		$input = $this->resolve_fixture_ids( $case['input'] );
		self::assertSame( $history['value'], $input[ $history['flag'] ], 'Only fixture IDs are replaced; malformed flag types are retained.' );
		$name = $case['ability'];
		$args = Probe::$wrapped[ $name ];
		self::assertSame( Webmastery_MCP_Ability::class, $args['ability_class'] );
		self::assertFalse( $args['input_schema']['additionalProperties'] );
		$before = Probe::snapshot();
		foreach ( array( true, false ) as $allowed ) {
			Probe::$allowed = $allowed;
			Probe::$events = array();
			$ability = new Webmastery_MCP_Ability( $name, $args );
			$result = $ability->execute( $input );
			$this->assert_error( $result, 'invalid_input', 'ability_invalid_input', 'Ability input does not match its schema.' );
			self::assertSame( array( 1, 0, 0 ), array( $ability->parent_validations, $ability->permission_calls, $ability->execute_calls ) );
			self::assertSame( array(
				'wp_ability_invoked', 'wp_pre_execute_ability', 'normalize_input',
				'wp_ability_normalize_input', 'parent_validate_input', 'wp_ability_validate_input',
			), $ability->events );
			self::assertSame( array(), Probe::$events, 'No original callback, capability, read, query or write before native rejection.' );
			self::assertSame( $before, Probe::snapshot() );

			$permission = $ability->check_permissions( $input );
			self::assertInstanceOf( WP_Error::class, $permission );
			self::assertSame( 'invalid_input', $permission->get_error_code() );
			$this->assert_error( Webmastery_MCP_Response::from_wp_error( $permission ), 'invalid_input', 'ability_invalid_input', 'Ability input does not match its schema.' );
			self::assertSame( array(), Probe::$events, 'Raw permission rejection must not invoke original authorization.' );
			self::assertSame( $before, Probe::snapshot() );

			$message = 'confirm' === $history['flag']
				? ( str_contains( $name, '/bulk-' ) ? 'Set confirm to true to acknowledge this operation, including a dry run.' : 'Set confirm to true to acknowledge permanent deletion.' )
				: $history['flag'] . ' must be a boolean.';
			foreach ( array( 'originals', 'wrapped' ) as $boundary ) {
				Probe::$events = array();
				$definitions = 'originals' === $boundary ? Probe::$originals : Probe::$wrapped;
				$direct = $definitions[ $name ]['execute_callback']( $input );
				$this->assert_error( $direct, $history['after']['expect_error_code'], $history['after']['expect_error_reason'], $message );
				self::assertSame( 'originals' === $boundary ? array( array( 'execute_callback', array( $input ) ) ) : array(), Probe::$events );
				self::assertSame( $before, Probe::snapshot(), 'Direct interlock must also precede reads, capabilities and writes.' );
			}
		}
	}

	public static function valid_bulk_inputs(): array {
		return array( array( 'bulk-trash-posts' ), array( 'bulk-publish-posts' ) );
	}

	/** @dataProvider valid_bulk_inputs */
	public function test_valid_preview_reaches_real_callbacks_and_denied_actor_stops_before_execution( string $slug ): void {
		$name = 'webmastery-site-toolkit-for-mcp/' . $slug;
		$input = array( 'ids' => array( 42 ), 'confirm' => true, 'dry_run' => true );
		$before = Probe::snapshot();
		$ability = new Webmastery_MCP_Ability( $name, Probe::$wrapped[ $name ] );
		$result = $ability->execute( $input );
		self::assertTrue( $result['success'] );
		self::assertSame( 1, $result['data']['success_count'] );
		self::assertSame( 0, $result['data']['failure_count'] );
		self::assertTrue( $result['data']['dry_run'] );
		self::assertSame( array( 1, 1, 1 ), array( $ability->parent_validations, $ability->permission_calls, $ability->execute_calls ) );
		self::assertContains( array( 'read:post', 42 ), Probe::$events );
		self::assertContains( array( 'execute_callback', array( $input ) ), Probe::$events );
		self::assertSame( $before, Probe::snapshot() );

		Probe::$allowed = false;
		Probe::$events = array();
		$ability = new Webmastery_MCP_Ability( $name, Probe::$wrapped[ $name ] );
		$this->assert_error( $ability->execute( $input ), 'forbidden', 'ability_invalid_permissions', 'You do not have permission to execute this ability.' );
		self::assertSame( array( 1, 1, 0 ), array( $ability->parent_validations, $ability->permission_calls, $ability->execute_calls ) );
		self::assertNotEmpty( Probe::$events );
		self::assertNotContains( array( 'execute_callback', array( $input ) ), Probe::$events );
		self::assertSame( $before, Probe::snapshot() );
	}

	public function test_lifecycle_double_enforces_actual_bulk_item_bounds_without_schema_stripping(): void {
		$args = Probe::$wrapped['webmastery-site-toolkit-for-mcp/bulk-trash-posts'];
		$ability = new WP_Ability( 'test/bulk-schema', $args );
		self::assertSame( 1, $args['input_schema']['properties']['ids']['minItems'] );
		self::assertSame( 100, $args['input_schema']['properties']['ids']['maxItems'] );
		foreach ( array( 0, 1, 100, 101 ) as $count ) {
			$result = $ability->validate_input( array( 'ids' => array_fill( 0, $count, 42 ), 'confirm' => true ) );
			if ( 0 === $count || 101 === $count ) {
				self::assertInstanceOf( WP_Error::class, $result );
				self::assertSame( 'ability_invalid_input', $result->get_error_code() );
			} else {
				self::assertTrue( $result );
			}
		}
		self::assertSame( array(), Probe::$events );
	}

	public function test_omitting_plugin_strict_validation_reaches_permission_for_coercible_optional_flag(): void {
		$name = 'webmastery-site-toolkit-for-mcp/bulk-trash-posts';
		$input = array( 'ids' => array( 42 ), 'confirm' => true, 'dry_run' => 'true' );
		$without_subclass = new WP_Ability( $name, Probe::$wrapped[ $name ] );
		self::assertTrue( $without_subclass->validate_input( $input ), 'The limited parent accepts this coercible boolean.' );
		$before = Probe::snapshot();
		$result = $without_subclass->execute( $input );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		self::assertSame( 1, $without_subclass->permission_calls, 'Raw wrapper protection alone is not pre-permission native validation.' );
		self::assertSame( 0, $without_subclass->execute_calls );
		self::assertSame( array(), Probe::$events );
		self::assertSame( $before, Probe::snapshot() );
	}
}
