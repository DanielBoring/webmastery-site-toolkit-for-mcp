<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Wstm105Calibration\Probe;

require_once __DIR__ . '/fixtures/comments-calibration.php';
require_once dirname( __DIR__ ) . '/e2e/error-contract-assertions.php';

/**
 * Source/callback proof only: not WordPress, Docker, HTTP, or release acceptance.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CommentsCalibrationTest extends TestCase {
	private function ledger(): stdClass {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/comments-calibration-ledger.json' ), false, 512, JSON_THROW_ON_ERROR );
	}

	private function runner(): string {
		return file_get_contents( dirname( __DIR__ ) . '/e2e/comments-runner.php' );
	}

	private function fixture(): string {
		return file_get_contents( dirname( __DIR__ ) . '/e2e/comments-fixture.php' );
	}

	private function typed( $value ): string {
		return Wstm105Calibration\json( $value );
	}

	private function assert_error( $actual, $expected ): void {
		self::assertSame( $this->typed( (object) array( 'success' => false, 'error' => $expected ) ), $this->typed( $actual ) );
	}

	private function args( string $action ): array {
		Probe::load();
		return Probe::$abilities[ "webmastery-site-toolkit-for-mcp/{$action}-comment" ];
	}

	private function fixture_trace(): array {
		ob_start();
		try {
			$calls = Wstm105Calibration\run_fixture();
			self::assertSame( "PASS WSTM105 141 direct callback authorization checks\n", ob_get_contents() );
			return $calls;
		} finally {
			ob_end_clean();
		}
	}

	public function test_ledger_is_exact_pinned_typed_source_derivation(): void {
		$ledger = $this->ledger();
		self::assertSame( $this->typed( $ledger ), $this->typed( Wstm105Calibration\derive( $ledger ) ) );
		self::assertSame( array( 'direct' => 104, 'ability' => 104, 'http' => 100 ), (array) $ledger->counts->runner );
		self::assertSame( array( 'http' => 16, 'direct' => 32, 'ability' => 0 ), (array) $ledger->counts->changed );
		self::assertSame( 141, $ledger->counts->raw_total );
		foreach ( $ledger->source_hashes->{$ledger->head} as $path => $hash ) {
			if ( str_starts_with( $path, 'includes/' ) ) {
				self::assertSame( $hash, hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) ) ), 'Production drift: ' . $path );
			}
		}
		self::assertInstanceOf( stdClass::class, $ledger->raw_malformed_cases[4]->input );
		self::assertSame( array(), $ledger->raw_malformed_cases[5]->input );
		self::assertSame( 1.5, $ledger->raw_malformed_cases[10]->input->comment_id );
		self::assertSame( "{\n    \"fraction\": 1.0,\n    \"object\": {},\n    \"array\": []\n}\n", $this->typed( array( 'fraction' => 1.0, 'object' => new stdClass(), 'array' => array() ) ) );
	}

	private function assert_runner_inventory( string $source ): void {
		$actual = Wstm105Calibration\runner_inventory( $source );
		$ledger = $this->ledger();
		self::assertCount( 308, $actual );
		foreach ( $actual as $i => $row ) {
			$expected = $ledger->runner_cases[ $i ];
			foreach ( array( 'label', 'boundary', 'action', 'role', 'scope', 'invalid', 'input', 'http_parameters', 'capabilities', 'allowed', 'baseline', 'no_write' ) as $key ) {
				self::assertSame( $this->typed( $expected->$key ), $this->typed( $row[ $key ] ), $expected->label . ':' . $key );
			}
			self::assertSame( $this->typed( $expected->new ), $this->typed( $row['old'] ), $expected->label . ':current expectation' );
			$mode = 'fixed';
			$boundary = $row['boundary'];
			$invalid = $row['invalid'];
			$moderate = $row['capabilities']['moderate_comments'];
			$schema_invalid = in_array( $invalid, array( 'missing_id', 'string_id', 'array_id', 'null_id' ), true );
			$record = array();
			eval( Wstm105Calibration\between( $source, '$record[\'compatibility\'] =', '$record[\'passed\']' ) );
			self::assertSame( $expected->legacy_compatibility, $record['compatibility'], $expected->label . ':compatibility' );
		}
	}

	public function test_runner_changes_only_approved_48_expectations_and_preserves_308_typed_ordered_cases(): void {
		$this->assert_runner_inventory( $this->runner() );
		$source = $this->runner();
		self::assertStringContainsString( "if ( 'baseline' === \$mode && 'direct' === \$boundary && in_array( \$invalid, array( 'missing_id', 'string_id', 'array_id', 'null_id' ), true ) )", $source );
	}

	public function test_actual_registered_wrappers_match_all_44_raw_shapes_and_closed_schemas(): void {
		$ledger = $this->ledger();
		foreach ( $ledger->closed_registered_schemas as $action => $schema ) {
			$args = $this->args( $action );
			self::assertSame( $this->typed( $schema ), $this->typed( $args['input_schema'] ) );
			self::assertFalse( $args['input_schema']['additionalProperties'] );
			self::assertArrayNotHasKey( 'minimum', $args['input_schema']['properties']['comment_id'] );
		}
		$schema_rejected = 0;
		$deferred = 0;
		foreach ( $ledger->raw_malformed_cases as $row ) {
			// JSON objects are kept as objects until the documented PHP array input boundary.
			$input = $row->input instanceof stdClass && property_exists( $row->input, 'comment_id' ) ? (array) $row->input : $row->input;
			$args = $this->args( $row->action );
			Wstm105Calibration\seed();
			Probe::$role = 'editor';
			Probe::reset();
			$before = Probe::snapshot();
			$permission = $args['permission_callback']( $input );
			$result = $args['execute_callback']( $input );
			if ( $row->schema_invalid ) {
				self::assertInstanceOf( WP_Error::class, $permission );
				self::assertSame( 'invalid_input', $permission->get_error_code() );
				$this->assert_error( json_decode( $permission->get_error_message(), false, 512, JSON_THROW_ON_ERROR ), $row->new_permission->decoded );
				self::assertSame( array(), Probe::$events, $row->label . ':original callbacks/capabilities/queries' );
				++$schema_rejected;
			} else {
				self::assertTrue( $permission );
				self::assertSame( array( 'permission_callback', array( 'cap', 'moderate_comments', array() ), 'execute_callback', array( 'cap', 'moderate_comments', array() ) ), Probe::$events );
				++$deferred;
			}
			$this->assert_error( $result, $row->new_execute );
			self::assertSame( $before, Probe::snapshot(), $row->label . ':no write' );
		}
		self::assertSame( array( 41, 3 ), array( $schema_rejected, $deferred ) );
	}

	public function test_actual_wrapped_direct_32_and_http_permission_16_reject_before_original_callbacks(): void {
		Wstm105Calibration\load_fixture();
		$count = array( 'direct' => 0, 'http' => 0 );
		$rejected = $count;
		$old = $this->ledger()->original_sources->{'tests/e2e/comments-runner.php'};
		foreach ( $this->ledger()->runner_cases as $row ) {
			if ( ! $row->changed ) {
				continue;
			}
			$args = $this->args( $row->action );
			Probe::$role = $row->role;
			Wstm105Calibration\seed();
			Probe::reset();
			$before = Probe::snapshot();
			$result = $args[ 'http' === $row->boundary ? 'permission_callback' : 'execute_callback' ]( (array) $row->input );
			if ( 'http' === $row->boundary ) {
				self::assertSame( 'invalid_input', $result->get_error_code() );
				$decoded = json_decode( $result->get_error_message(), false, 512, JSON_THROW_ON_ERROR );
				$result = (array) $decoded;
				$result['error'] = (array) $decoded->error;
			}
			$this->assert_error( $result, $row->new );
			Wstm105Calibration\assert_runner_response( $this->runner(), $row, $result );
			try {
				Wstm105Calibration\assert_runner_response( $old, $row, $result );
				self::fail( 'Old expectation unexpectedly accepted the registered schema error: ' . $row->label );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'Wrong canonical failure reason', $error->getMessage() );
				++$rejected[ $row->boundary ];
			}
			self::assertSame( array(), Probe::$events );
			self::assertSame( $before, Probe::snapshot() );
			++$count[ $row->boundary ];
		}
		self::assertSame( array( 'direct' => 32, 'http' => 16 ), $count );
		self::assertSame( $count, $rejected );
	}

	public function test_native_32_schema_failures_remain_unchanged_with_existing_lifecycle_double(): void {
		require_once __DIR__ . '/fixtures/native-input-ability.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-ability.php';
		$without_descriptions = static function ( array $schema ) use ( &$without_descriptions ): array {
			// The existing constrained lifecycle double does not model annotations.
			unset( $schema['description'] );
			foreach ( $schema['properties'] ?? array() as $key => $property ) {
				$schema['properties'][ $key ] = $without_descriptions( $property );
			}
			return $schema;
		};
		$count = 0;
		foreach ( $this->ledger()->runner_cases as $row ) {
			if ( 'ability' !== $row->boundary || ! in_array( $row->invalid, array( 'missing_id', 'string_id', 'array_id', 'null_id' ), true ) ) {
				continue;
			}
			$args = $this->args( $row->action );
			$args['input_schema'] = $without_descriptions( $args['input_schema'] );
			$ability = new Webmastery_MCP_Ability( "webmastery-site-toolkit-for-mcp/{$row->action}-comment", $args );
			Probe::$role = $row->role;
			Wstm105Calibration\seed();
			Probe::reset();
			$before = Probe::snapshot();
			$this->assert_error( $ability->execute( (array) $row->input ), $row->old );
			self::assertFalse( $row->changed );
			self::assertSame( $this->typed( $row->old ), $this->typed( $row->new ) );
			self::assertSame( array( 1, 0, 0 ), array( $ability->parent_validations, $ability->permission_calls, $ability->execute_calls ) );
			self::assertSame( array(), Probe::$events );
			self::assertSame( $before, Probe::snapshot() );
			++$count;
		}
		self::assertSame( 32, $count );
	}

	public function test_all_104_direct_cases_preserve_roles_typed_ids_state_and_no_write_contracts(): void {
		$count = 0;
		foreach ( $this->ledger()->runner_cases as $row ) {
			if ( 'direct' !== $row->boundary ) {
				continue;
			}
			$args = $this->args( $row->action );
			Probe::$comments = array();
			Probe::$filters = array();
			Probe::$role = $row->role;
			Wstm105Calibration\seed( 42, $row->scope );
			Probe::$comments[42]->comment_content = 'WSTM105 original.';
			self::assertSame( $row->capabilities->moderate_comments, Wstm105Calibration\current_user_can( 'moderate_comments' ), $row->label );
			self::assertSame( $row->capabilities->edit_comment, Wstm105Calibration\current_user_can( 'edit_comment', 42 ), $row->label );
			$before = Probe::snapshot();
			Probe::reset();
			if ( 'global_zero' === $row->invalid ) {
				$GLOBALS['comment'] = Probe::$comments[42];
			}
			try {
				$result = $args['execute_callback']( (array) $row->input );
				if ( $row->allowed ) {
					self::assertTrue( $result['success'] );
					$status = array( 'update' => 'spam', 'approve' => 'approved', 'trash' => 'trash', 'spam' => 'spam' )[ $row->action ];
					self::assertSame( $status, Probe::$comments[42]->status );
					self::assertSame( 'update' === $row->action ? 'WSTM105 changed.' : 'WSTM105 original.', Probe::$comments[42]->comment_content );
					self::assertSame( 42, $result['data']['id'] );
					self::assertSame( $status, $result['data']['status'] );
				} else {
					$this->assert_error( $result, $row->new );
					self::assertSame( $before, Probe::snapshot(), $row->label );
				}
				if ( in_array( $row->invalid, array( 'zero', 'negative_missing', 'negative_existing', 'global_zero' ), true ) ) {
					self::assertSame( array( 'execute_callback', array( 'cap', 'moderate_comments', array() ) ), Probe::$events, 'Zero/negative integers must pass schema but never query a coerced/global target.' );
				}
			} finally {
				unset( $GLOBALS['comment'] );
			}
			++$count;
		}
		self::assertSame( 104, $count );
	}

	private function assert_legacy_trace( array $calls ): void {
		$originals = array();
		foreach ( $calls as $i => $call ) {
			if ( 'update' === $call['action'] || 'permission_callback' !== $call['callback'] || ! is_array( $call['input'] ) || ! isset( $call['input']['content'] ) ) {
				continue;
			}
			$originals[] = $i;
		}
		self::assertCount( 39, $originals, 'All 39 original payloads must actually execute before counterparts.' );
		foreach ( $originals as $j => $i ) {
			$expected = $this->ledger()->legacy_payload_pairs[ $j ];
			$permission = $calls[ $i ];
			$execute = $calls[ $i + 1 ];
			$minimal_permission = $calls[ $i + 2 ];
			$minimal_execute = $calls[ $i + 3 ];
			self::assertSame( $expected->action, $permission['action'] );
			self::assertSame( $expected->role, $permission['role'] );
			$original = $permission['input'];
			$original['comment_id'] = $original['comment_id'] > 0 && $original['comment_id'] < 2147483647 ? 42 : $original['comment_id'];
			self::assertSame( $this->typed( $expected->original_input ), $this->typed( $original ) );
			foreach ( array( $permission, $execute ) as $call ) {
				self::assertSame( array(), $call['events'], $expected->label . ':zero original/cap/query calls' );
				self::assertSame( $call['before'], $call['after'], $expected->label . ':no writes' );
			}
			self::assertSame( $permission['input'], $execute['input'] );
			self::assertSame( array( 'comment_id' => $permission['input']['comment_id'] ), $minimal_permission['input'] );
			self::assertSame( $minimal_permission['input'], $minimal_execute['input'] );
			self::assertSame( 'permission_callback', $minimal_permission['callback'] );
			self::assertSame( 'execute_callback', $minimal_execute['callback'] );
			self::assertSame( 'success' === $expected->new_minimal, true === ( $minimal_execute['result']['success'] ?? null ) );
			if ( 'success' !== $expected->new_minimal ) {
				self::assertSame( $expected->new_minimal, $minimal_execute['result']['error']['reason'] );
				self::assertSame( $minimal_execute['before'], $minimal_execute['after'] );
			}
		}
	}

	public function test_actual_raw_fixture_retains_102_checks_and_executes_39_originals_before_minimal_counterparts(): void {
		Wstm105Calibration\load_fixture();
		$calls = $this->fixture_trace();
		self::assertCount( 276, $calls, '141 checks, two callbacks except the six execute-only content/status controls.' );
		$this->assert_legacy_trace( $calls );
		$raw = $this->ledger()->raw_malformed_cases;
		foreach ( array( 'update', 'approve', 'trash', 'spam' ) as $action_index => $action ) {
			$action_calls = array_values( array_filter( $calls, static fn( $call ) => $action === $call['action'] ) );
			self::assertCount( 'update' === $action ? 54 : 74, $action_calls );
			$start = 'update' === $action ? 24 : 48;
			for ( $index = 0; $index < 11; $index++ ) {
				$expected = $raw[ $action_index * 11 + $index ];
				foreach ( array( 0, 1 ) as $offset ) {
					$call = $action_calls[ $start + $index * 2 + $offset ];
					$input = $call['input'];
					if ( is_array( $input ) && isset( $input['comment_id'] ) ) {
						if ( is_int( $input['comment_id'] ) && $input['comment_id'] < 0 ) {
							$input['comment_id'] = -42;
						} elseif ( is_array( $input['comment_id'] ) ) {
							$input['comment_id'] = array( 42 );
						}
					}
					self::assertSame( $this->typed( $expected->input ), $this->typed( $input ), $expected->label );
					self::assertSame( $call['before'], $call['after'] );
					if ( $expected->schema_invalid ) {
						self::assertSame( array(), $call['events'] );
					}
				}
			}
		}
		$updates = array_values( array_filter( $calls, static fn( $call ) => 'update' === $call['action'] && 'execute_callback' === $call['callback'] ) );
		$invalid_updates = array_slice( $updates, -6 );
		self::assertSame( array( 'ability_invalid_input', 'ability_invalid_input', 'invalid_content', 'ability_invalid_input', 'ability_invalid_input', 'invalid_status' ), array_map( static fn( $call ) => $call['result']['error']['reason'], $invalid_updates ) );
		foreach ( $invalid_updates as $i => $call ) {
			self::assertSame( $call['before'], $call['after'] );
			if ( in_array( $i, array( 2, 5 ), true ) ) {
				self::assertContains( 'execute_callback', $call['events'], 'Schema-valid strings must still reach callback validation.' );
			} else {
				self::assertSame( array(), $call['events'] );
			}
		}
	}

	public function test_baseline_http_precedence_stays_historically_red_and_old_direct_expectations_reject_current_guard(): void {
		$ledger = $this->ledger();
		$counts = array( 'http' => 0, 'direct' => 0 );
		foreach ( $ledger->runner_cases as $row ) {
			if ( ! $row->changed ) {
				continue;
			}
			$expected = 'http' === $row->boundary ? $row->baseline : $row->old;
			self::assertNotSame( $this->typed( $expected ), $this->typed( $row->new ) );
			if ( 'http' === $row->boundary ) {
				self::assertSame( 'forbidden', Wstm105Calibration\contract( $this->runner(), 'baseline', 'http', $row->invalid, false, false )['reason'] );
			} else {
				self::assertSame( 'historically_omitted', $row->baseline );
				self::assertSame( 'not_found', $row->old->reason );
			}
			++$counts[ $row->boundary ];
		}
		self::assertSame( array( 'http' => 16, 'direct' => 32 ), $counts );
	}

	public static function runner_mutants(): array {
		return array(
			'old HTTP precedence' => array( "'http' === \$boundary && ! \$moderate && ( 'baseline' === \$mode || ! \$schema_invalid )", "'http' === \$boundary && ! \$moderate" ),
			'old direct not_found' => array( "( 'fixed' === \$mode || 'direct' !== \$boundary ) &&", "'direct' !== \$boundary &&" ),
			'ID coercion' => array( "\$input['comment_id'] = 'not-an-id'", "\$input['comment_id'] = 0" ),
			'zero lost' => array( "\$input['comment_id'] = 0; break", "\$input['comment_id'] = 1; break" ),
			'negative lost' => array( "\$input['comment_id'] = -\$id", "\$input['comment_id'] = \$id" ),
			'capabilities changed' => array( "'author', 'author', false, true", "'author', 'author', true, true" ),
			'legacy compatibility mislabel' => array( "( \$schema_invalid && ! ( 'fixed' === \$mode && ( 'direct' === \$boundary || ( 'http' === \$boundary && ! \$moderate ) ) ) )", "( \$schema_invalid )" ),
		);
	}

	/** @dataProvider runner_mutants */
	public function test_runner_mutations_are_rejected( string $from, string $to ): void {
		$source = $this->runner();
		self::assertStringContainsString( $from, $source );
		$this->expectException( AssertionFailedError::class );
		$this->assert_runner_inventory( str_replace( $from, $to, $source ) );
	}

	public static function error_mutants(): array {
		return array(
			'wrong code only' => array( 'code', 'forbidden' ),
			'wrong reason only' => array( 'reason', 'invalid_input' ),
			'details array' => array( 'details', array() ),
			'changed message' => array( 'message', 'Changed message.' ),
		);
	}

	/** @dataProvider error_mutants */
	public function test_strict_error_checker_rejects_independent_field_mutants( string $field, $value ): void {
		Wstm105Calibration\load_fixture();
		$args = $this->args( 'update' );
		$result = $args['execute_callback']( array() );
		Wstm105Calibration\wstm105_assert_schema_error( $result );
		$result['error'][ $field ] = $value;
		$this->expectException( RuntimeException::class );
		Wstm105Calibration\wstm105_assert_schema_error( $result );
	}

	/** @dataProvider error_mutants */
	public function test_native_permission_checker_rejects_independent_field_mutants( string $field, $value ): void {
		Wstm105Calibration\load_fixture();
		$args = $this->args( 'update' );
		$permission = $args['permission_callback']( array() );
		Wstm105Calibration\wstm105_assert_schema_error( $permission, true );
		$result = $args['execute_callback']( array() );
		$result['error'][ $field ] = $value;
		$permission = new WP_Error( $result['error']['code'], wp_json_encode( $result ) );
		$this->expectException( RuntimeException::class );
		Wstm105Calibration\wstm105_assert_schema_error( $permission, true );
	}

	public function test_schema_capability_probe_mutant_is_rejected(): void {
		Wstm105Calibration\load_fixture();
		Probe::load();
		$key = 'webmastery-site-toolkit-for-mcp/update-comment';
		$callback = Probe::$abilities[ $key ]['execute_callback'];
		Probe::$abilities[ $key ]['execute_callback'] = static function ( $input ) use ( $callback ) {
			if ( null === $input ) {
				Wstm105Calibration\current_user_can( 'moderate_comments' );
			}
			return $callback( $input );
		};
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Schema rejection reached original capability/query work.' );
		$this->fixture_trace();
	}

	public function test_schema_query_probe_mutant_is_rejected(): void {
		Wstm105Calibration\load_fixture();
		Probe::load();
		$key = 'webmastery-site-toolkit-for-mcp/update-comment';
		$callback = Probe::$abilities[ $key ]['permission_callback'];
		Probe::$abilities[ $key ]['permission_callback'] = static function ( $input ) use ( $callback ) {
			if ( null === $input ) {
				Wstm105Calibration\get_comment( 42 );
			}
			return $callback( $input );
		};
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Schema rejection reached original capability/query work.' );
		$this->fixture_trace();
	}

	public function test_raw_permission_true_mutant_is_rejected(): void {
		Wstm105Calibration\load_fixture();
		Probe::load();
		$key = 'webmastery-site-toolkit-for-mcp/update-comment';
		$original = Probe::$abilities[ $key ]['permission_callback'];
		$schema = Probe::$abilities[ $key ]['input_schema'];
		Probe::$abilities[ $key ]['permission_callback'] = static fn( $input ) => null !== Webmastery_MCP_Input::validate( $input, $schema ) ? true : $original( $input );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Registered permission must return native invalid_input.' );
		$this->fixture_trace();
	}

	public function test_permission_denial_bypass_mutant_is_rejected(): void {
		Wstm105Calibration\load_fixture();
		Probe::load();
		Probe::$abilities['webmastery-site-toolkit-for-mcp/update-comment']['permission_callback'] = static fn() => true;
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'permission callback mismatch' );
		$this->fixture_trace();
	}

	public function test_no_write_mutant_is_rejected(): void {
		Wstm105Calibration\load_fixture();
		Probe::load();
		$key = 'webmastery-site-toolkit-for-mcp/update-comment';
		$callback = Probe::$abilities[ $key ]['execute_callback'];
		Probe::$abilities[ $key ]['execute_callback'] = static function ( $input ) use ( $callback ) {
			$result = $callback( $input );
			if ( null === $input ) {
				Probe::$comments[42]->comment_content = 'Unexpected write.';
			}
			return $result;
		};
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'changed persisted content/status' );
		$this->fixture_trace();
	}

	public function test_dropping_originals_even_with_counter_forgery_is_rejected(): void {
		$source = str_replace(
			'wstm105_schema_negative( $permission, $execute, $input, $comment_id );' . "\n\t" . '$checks++;',
			'$checks++;',
			$this->fixture()
		);
		self::assertNotSame( $source, $this->fixture() );
		Wstm105Calibration\load_fixture( $source );
		$calls = $this->fixture_trace();
		$this->expectException( AssertionFailedError::class );
		$this->assert_legacy_trace( $calls );
	}
}
