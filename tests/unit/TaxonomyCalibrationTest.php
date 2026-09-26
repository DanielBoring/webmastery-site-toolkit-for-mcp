<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Wstm117Calibration\Probe;

require_once __DIR__ . '/fixtures/taxonomy-calibration.php';
require_once dirname( __DIR__ ) . '/e2e/error-contract-assertions.php';

/**
 * Source proof with actual production callbacks and a constrained native double.
 * No WordPress bootstrap, Docker, HTTP, or runtime acceptance.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TaxonomyCalibrationTest extends TestCase {
	protected function setUp(): void {
		require_once __DIR__ . '/fixtures/native-input-ability.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-ability.php';
		Probe::load();
		Probe::reset();
	}

	private function source(): string {
		return str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__ ) . '/e2e/taxonomy-write-runner.php' ) );
	}

	private function ledger(): stdClass {
		return Wstm117Calibration\load_ledger();
	}

	private function typed( $value ): string {
		return Wstm117Calibration\json( $value );
	}

	public function test_pinned_ledger_derives_exact_156_to_164_classification_without_git_or_network(): void {
		$ledger = $this->ledger();
		self::assertSame( $this->typed( $ledger ), $this->typed( Wstm117Calibration\derive( $ledger ) ) );
		self::assertSame( array( 'original' => 156, 'unchanged' => 148, 'original_schema_controls' => 8, 'minimal_counterparts' => 8, 'new_total' => 164 ), (array) $ledger->counts );
		foreach ( $ledger->source_hashes as $files ) {
			foreach ( $files as $path => $hash ) {
				$source = 'tests/e2e/taxonomy-write-runner.php' === $path ? $ledger->original_source
					: str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
				self::assertSame( $hash, hash( 'sha256', $source ), $path );
			}
		}
		self::assertInstanceOf( stdClass::class, $ledger->calibration_pairs[0]->new_original_oracle->error->details );
		self::assertSame( "{\n    \"object\": {},\n    \"array\": [],\n    \"fraction\": 1.0\n}\n", $this->typed( array( 'object' => new stdClass(), 'array' => array(), 'fraction' => 1.0 ) ) );
	}

	public function test_all_156_original_typed_inputs_actors_paths_and_remaining_source_are_unchanged(): void {
		$source = $this->source();
		$ledger = $this->ledger();
		self::assertSame( $this->typed( $ledger->original_cases ), $this->typed( Wstm117Calibration\inventory( $source ) ) );
		$helper = Wstm117Calibration\between( $source, 'function wstm117_delete_missing_pair(', 'function wstm117_run_taxonomy_tests()' );
		$restored = str_replace( $helper, '', $source, $helper_count );
		$dispatch = "\t\t\t\t\t\t\tif ( 'delete' === \$action ) {\n"
			. "\t\t\t\t\t\t\t\twstm117_delete_missing_pair( \$ability, \$execute, \$input, \$taxonomy, \$slug, \$scenario, \$path, \$before, \$record );\n"
			. "\t\t\t\t\t\t\t\tcontinue;\n\t\t\t\t\t\t\t}\n";
		$restored = str_replace( $dispatch, '', $restored, $dispatch_count );
		self::assertSame( array( 1, 1 ), array( $helper_count, $dispatch_count ) );
		self::assertSame( $ledger->original_source, $restored, 'Every unrelated role, update, default-category, snapshot, capability and cleanup byte must remain unchanged.' );
	}

	private function assert_pairs( array $records ): void {
		self::assertCount( 16, $records );
		self::assertCount( 16, Probe::$calls );
		foreach ( $this->ledger()->calibration_pairs as $i => $pair ) {
			foreach ( array( 'original', 'minimal' ) as $offset => $kind ) {
				$row = $records[ $i * 2 + $offset ];
				$call = Probe::$calls[ $i * 2 + $offset ];
				$input_key = $kind . '_input';
				$oracle_key = 'new_' . $kind . '_oracle';
				self::assertSame( 'original' === $kind ? $pair->label : $pair->counterpart_label, $row['label'] );
				self::assertTrue( $row['passed'], $row['label'] );
				$evidence = $row['evidence'];
				self::assertSame( $this->typed( $pair->$input_key ), $this->typed( $evidence['input'] ) );
				self::assertSame( $this->typed( $pair->$input_key ), $this->typed( $call['input'] ) );
				self::assertSame( $this->typed( $pair->$oracle_key ), $this->typed( $evidence['result'] ) );
				self::assertSame( $pair->path, $call['path'] );
				self::assertSame( $pair->actor, $evidence['actor'] );
				self::assertSame( $pair->capability->name, $evidence['capability'] );
				self::assertTrue( $evidence['can_delete'] );
				self::assertTrue( $evidence['persisted_unchanged'] );
				self::assertSame( $evidence['before_sha256'], $evidence['after_sha256'] );
				self::assertSame( $call['before'], $call['after'] );
				if ( 'original' === $kind ) {
					self::assertSame( array(), $call['events'], 'No original callbacks, capability checks, taxonomy reads, or term queries.' );
					self::assertSame( 0, $evidence['capability_calls'] );
					self::assertSame( 0, $evidence['query_calls'] );
				} else {
					$field = 'category' === $pair->taxonomy ? 'category_id' : 'tag_id';
					$id = $pair->minimal_input->$field;
					$expected_events = array( 'execute_callback', array( 'query', $id, $pair->taxonomy ) );
					if ( 'wrapped' === $pair->path ) {
						$expected_events = array_merge( array(
							'permission_callback', array( 'taxonomy', $pair->taxonomy ),
							array( 'cap', 'manage_categories', array() ), array( 'query', $id, $pair->taxonomy ),
						), $expected_events );
					}
					self::assertSame( $expected_events, $call['events'], 'Minimal input must traverse actual registered callbacks, not fabricated not-found results.' );
					self::assertSame( 'wrapped' === $pair->path ? 2 : 1, $evidence['query_calls'] );
					self::assertSame( 'wrapped' === $pair->path ? 1 : 0, $evidence['capability_calls'] );
				}
			}
		}
	}

	public function test_all_eight_originals_run_before_actual_wrapped_and_direct_minimal_counterparts(): void {
		Wstm117Calibration\load_runner_helper();
		$records = Wstm117Calibration\run_pairs();
		$this->assert_pairs( $records );
		$labels = array();
		foreach ( $this->ledger()->original_cases as $row ) {
			$labels[] = $row->label;
			if ( $row->calibrated ) {
				$labels[] = $row->label . ' minimal counterpart';
			}
		}
		self::assertCount( 164, array_unique( $labels ) );
		self::assertCount( 148, array_filter( $this->ledger()->original_cases, static fn( $row ) => ! $row->calibrated ) );
	}

	public function test_old_source_reproduces_exact_eight_red_not_found_oracles(): void {
		$records = Wstm117Calibration\run_pairs( $this->ledger()->original_source );
		self::assertCount( 8, $records );
		foreach ( $records as $i => $row ) {
			self::assertFalse( $row['passed'] );
			self::assertSame( $this->ledger()->calibration_pairs[ $i ]->label, $row['label'] );
			self::assertSame( $this->typed( $this->ledger()->calibration_pairs[ $i ]->new_original_oracle ), $this->typed( $row['evidence'] ) );
			self::assertSame( array(), Probe::$calls[ $i ]['events'] );
			self::assertSame( Probe::$calls[ $i ]['before'], Probe::$calls[ $i ]['after'] );
		}
	}

	public function test_registered_permission_wrappers_reject_original_shapes_and_allow_minimal_not_found_execution(): void {
		foreach ( $this->ledger()->calibration_pairs as $pair ) {
			$slug = 'category' === $pair->taxonomy ? 'category' : 'tag';
			$args = Probe::$abilities[ "webmastery-site-toolkit-for-mcp/delete-{$slug}" ];
			self::assertFalse( $args['input_schema']['additionalProperties'] );
			Probe::$events = array();
			$error = $args['permission_callback']( (array) $pair->original_input );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'invalid_input', $error->get_error_code() );
			self::assertSame( $this->typed( $pair->new_original_oracle ), $this->typed( json_decode( $error->get_error_message(), false, 512, JSON_THROW_ON_ERROR ) ) );
			self::assertSame( array(), Probe::$events );
			self::assertTrue( $args['permission_callback']( (array) $pair->minimal_input ) );
			self::assertSame( $this->typed( $pair->new_minimal_oracle ), $this->typed( $args['execute_callback']( (array) $pair->minimal_input ) ) );
		}
	}

	public static function source_mutants(): array {
		return array(
			'drop original eight' => array( "array( 'original' => \$input, 'minimal' => \$minimal )", "array( 'minimal' => \$minimal )" ),
			'drop minimal counterparts' => array( "array( 'original' => \$input, 'minimal' => \$minimal )", "array( 'original' => \$input )" ),
			'reverse pair order' => array( "array( 'original' => \$input, 'minimal' => \$minimal )", "array( 'minimal' => \$minimal, 'original' => \$input )" ),
			'retarget minimal integer ID' => array( "unset( \$minimal['name'] );", "unset( \$minimal['name'] ); \$minimal[ \"{\$slug}_id\" ] = 987654320;" ),
			'coerce original ID' => array( "\$input = array( \"{\$slug}_id\" => \$id, 'name' => 'Must not write' );", "\$input = array( \"{\$slug}_id\" => (string) \$id, 'name' => 'Must not write' );" ),
			'remove confirmation' => array( "unset( \$minimal['name'] );", "unset( \$minimal['name'], \$minimal['confirm'] );" ),
			'change actual name initializer' => array( "'name' => 'Must not write'", "'name' => 'Changed original payload'" ),
			'change actual confirmation initializer' => array( "\$input['confirm'] = true;", "\$input['confirm'] = false;" ),
		);
	}

	/** @dataProvider source_mutants */
	public function test_source_and_payload_mutants_are_rejected( string $from, string $to ): void {
		$source = str_replace( $from, $to, $this->source(), $count );
		self::assertSame( 1, $count, 'The negative must target exactly one real statement.' );
		Wstm117Calibration\load_runner_helper( $source );
		$records = Wstm117Calibration\run_pairs( $source );
		$this->expectException( AssertionFailedError::class );
		$this->assert_pairs( $records );
	}

	public static function result_mutants(): array {
		return array(
			'wrong code only' => array( 'code', 'forbidden' ),
			'wrong reason only' => array( 'reason', 'invalid_input' ),
			'array details' => array( 'details', array() ),
			'changed message' => array( 'message', 'Changed schema message.' ),
		);
	}

	/** @dataProvider result_mutants */
	public function test_result_field_mutants_are_rejected( string $field, $value ): void {
		Wstm117Calibration\load_runner_helper();
		foreach ( array( 'category', 'tag' ) as $slug ) {
			$key = "webmastery-site-toolkit-for-mcp/delete-{$slug}";
			$callback = Probe::$abilities[ $key ]['execute_callback'];
			Probe::$abilities[ $key ]['execute_callback'] = static function ( $input ) use ( $callback, $field, $value ) {
				$result = $callback( $input );
				$result['error'][ $field ] = $value;
				return $result;
			};
		}
		try {
			$records = Wstm117Calibration\run_pairs();
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Noncanonical error envelope', $error->getMessage() );
			return;
		}
		$this->expectException( AssertionFailedError::class );
		$this->assert_pairs( $records );
	}

	public function test_actor_capability_denial_is_not_hidden_by_not_found(): void {
		Wstm117Calibration\load_runner_helper();
		Probe::$allowed = false;
		$records = Wstm117Calibration\run_pairs();
		$this->expectException( AssertionFailedError::class );
		$this->assert_pairs( $records );
	}

	public function test_weakened_capability_oracle_cannot_hide_denial(): void {
		$source = str_replace(
			'&& true === $can_delete && true === current_user_can( $capability )',
			'&& true',
			$this->source(),
			$count
		);
		self::assertSame( 1, $count );
		Wstm117Calibration\load_runner_helper( $source );
		Probe::$allowed = false;
		$records = Wstm117Calibration\run_pairs( $source );
		self::assertTrue( $records[0]['passed'], 'This mutant deliberately hides the missing capability in the runtime oracle.' );
		$this->expectException( AssertionFailedError::class );
		$this->assert_pairs( $records );
	}

	public function test_actor_change_is_rejected(): void {
		Wstm117Calibration\load_runner_helper();
		Probe::$actor = 3;
		$records = Wstm117Calibration\run_pairs();
		$this->expectException( AssertionFailedError::class );
		$this->assert_pairs( $records );
	}

	public function test_weakened_no_write_oracle_cannot_hide_mutation(): void {
		$source = str_replace(
			array( '&& $before === $after', "'persisted_unchanged' => \$before === \$after" ),
			array( '&& true', "'persisted_unchanged' => true" ),
			$this->source()
		);
		Wstm117Calibration\load_runner_helper( $source );
		$key = 'webmastery-site-toolkit-for-mcp/delete-category';
		$callback = Probe::$abilities[ $key ]['execute_callback'];
		Probe::$abilities[ $key ]['execute_callback'] = static function ( $input ) use ( $callback ) {
			$result = $callback( $input );
			Probe::$terms['category'][42]->metadata[] = 'Unexpected metadata write.';
			return $result;
		};
		$records = Wstm117Calibration\run_pairs( $source );
		$this->expectException( AssertionFailedError::class );
		$this->assert_pairs( $records );
	}

	public function test_original_schema_control_cannot_query_before_validation(): void {
		Wstm117Calibration\load_runner_helper();
		$key = 'webmastery-site-toolkit-for-mcp/delete-category';
		$callback = Probe::$abilities[ $key ]['execute_callback'];
		Probe::$abilities[ $key ]['execute_callback'] = static function ( $input ) use ( $callback ) {
			Wstm117Calibration\get_term( 42, 'category' );
			return $callback( $input );
		};
		$records = Wstm117Calibration\run_pairs();
		$this->expectException( AssertionFailedError::class );
		$this->assert_pairs( $records );
	}

	public static function provenance_mutants(): array {
		$cases = array();
		foreach ( array( 'hash', 'source', 'object', 'float', 'oracle' ) as $mutation ) {
			foreach ( array( 'load', 'derive' ) as $entry ) {
				$cases[ "{$mutation}:{$entry}" ] = array( $mutation, $entry );
			}
		}
		return $cases;
	}

	/** @dataProvider provenance_mutants */
	public function test_entire_typed_ledger_is_sealed_before_derivation( string $mutation, string $entry ): void {
		$ledger = $this->ledger();
		switch ( $mutation ) {
			case 'hash':
				$ledger->source_hashes->{$ledger->parent}->{'includes/class-input.php'} = str_repeat( 'a', 64 );
				break;
			case 'source':
				$ledger->original_source .= "\n// Forged historical source.\n";
				$ledger->source_hashes->{$ledger->head}->{'tests/e2e/taxonomy-write-runner.php'} = hash( 'sha256', $ledger->original_source );
				break;
			case 'object':
				$ledger->calibration_pairs[0]->new_original_oracle->error->details = array();
				break;
			case 'float':
				$ledger->calibration_pairs[0]->original_input->category_id = 987654321.0;
				break;
			case 'oracle':
				$ledger->calibration_pairs[0]->new_minimal_oracle->error->reason = 'invalid_input';
				break;
		}
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Taxonomy ledger canonical fingerprint mismatch.' );
		if ( 'load' === $entry ) {
			Wstm117Calibration\load_ledger( $this->typed( $ledger ) );
		} else {
			Wstm117Calibration\derive( $ledger );
		}
	}
}
