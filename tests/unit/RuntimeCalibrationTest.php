<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm126Destructive\Probe;
use Wstm121Runtime\State;

require_once __DIR__ . '/fixtures/runtime-calibration.php';

/**
 * Production callbacks and actual runner case construction with scoped doubles.
 * No real SQL, WordPress bootstrap, HTTP or runtime acceptance.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RuntimeCalibrationTest extends TestCase {
	protected function setUp(): void {
		require_once __DIR__ . '/fixtures/native-input-ability.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-ability.php';
		require_once __DIR__ . '/fixtures/destructive-native-boundary.php';
		Probe::reset();
		State::$filters = array();
		State::$actor = 2;
		State::$persisted = array( 'retained' => true );
		State::$deleted = State::$abilities = array();
		$GLOBALS['wpdb'] = new Wstm121Runtime\Database();
	}

	protected function tearDown(): void { unset( $GLOBALS['wpdb'] ); }

	private function strict_cases(): array {
		$rows = array();
		$bases = array(
			'bulk-trash-posts' => array( 'ids' => array( 42 ) ),
			'bulk-publish-posts' => array( 'ids' => array( 42 ) ),
			'delete-media' => array( 'media_id' => 46 ),
			'delete-category' => array( 'category_id' => 51 ),
			'delete-tag' => array( 'tag_id' => 52 ),
		);
		foreach ( $bases as $slug => $base ) {
			foreach ( array( 'missing' => array(), 'false' => array( 'confirm' => false ), 'string' => array( 'confirm' => 'true' ), 'number' => array( 'confirm' => 1 ), 'null' => array( 'confirm' => null ) ) as $shape => $flag ) {
				$before = in_array( $shape, array( 'string', 'number' ), true ) ? 'missing_confirmation' : 'ability_invalid_input';
				foreach ( array( 'editor' => '', 'administrator' => 'administrator ' ) as $role => $prefix ) {
					$rows["$slug {$prefix}confirmation $shape"] = array( $slug, $base + $flag, $role, 'missing_confirmation', $before );
				}
				if ( str_starts_with( $slug, 'bulk-' ) ) {
					$rows["$slug preview confirmation $shape"] = array( $slug, $base + $flag + array( 'dry_run' => true ), 'editor', 'missing_confirmation', $before );
				}
			}
		}
		foreach ( array( 'bulk-trash-posts' => 'dry_run', 'bulk-publish-posts' => 'dry_run', 'delete-media' => 'force' ) as $slug => $flag ) {
			foreach ( array( 'true', 'false', 0, 1, null, array() ) as $index => $value ) {
				$rows["$slug strict $flag $index"] = array( $slug, $bases[$slug] + array( 'confirm' => true, $flag => $value ), 'editor', 'invalid_input', $index < 4 ? 'invalid_input' : 'ability_invalid_input' );
			}
		}
		foreach ( array( 'bulk-trash-posts', 'bulk-publish-posts' ) as $slug ) {
			foreach ( array( range( 10000001, 10000101 ), array_fill( 0, 101, 42 ) ) as $raw => $ids ) {
				foreach ( array( false, true ) as $preview ) {
					$rows["$slug 101 raw $raw preview " . (int) $preview] = array( $slug, array( 'ids' => $ids, 'confirm' => true, 'dry_run' => $preview ), 'editor', 'too_many_ids', 'ability_invalid_input' );
				}
			}
		}
		return $rows;
	}

	public static function boundaries(): array {
		return array( array( 'direct' ), array( 'ability' ), array( 'http' ), array( 'individual' ) );
	}

	/** @dataProvider boundaries */
	public function test_actual_matrix_preserves_86_typed_inputs_and_exact_36_native_migrations( string $boundary ): void {
		$invoke = static function ( $slug, $input, $role, $fault, &$entry, $unchanged ) use ( $boundary ) {
			Probe::$events = array();
			$before = Probe::snapshot();
			$name = 'webmastery-site-toolkit-for-mcp/' . $slug;
			$args = Probe::$wrapped[ $name ];
			$ability = new Webmastery_MCP_Ability( $name, $args );
			$permission = $ability->check_permissions( $input );
			$entry = array(
				'input' => $input, 'role' => $role, 'fault' => $fault, 'unchanged' => $unchanged,
				'permission_is_wp_error' => is_wp_error( $permission ),
				'permission_error_code' => $permission->get_error_code(),
				'permission' => Webmastery_MCP_Response::from_wp_error( $permission ),
			);
			// HTTP-labelled construction uses the native lifecycle double, not a transport.
			$result = 'direct' === $boundary ? $args['execute_callback']( $input ) : $ability->execute( $input );
			self::assertSame( array(), Probe::$events );
			self::assertSame( $before, Probe::snapshot() );
			$entry['result'] = $result;
			return $result;
		};
		$records = Wstm121Runtime\matrix( $boundary, $invoke );
		$rows = $this->strict_cases();
		$ledger = json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-integration-ledger.json' ), true, 512, JSON_THROW_ON_ERROR )['boundary_calibration']['safety_runtime_per_boundary_per_boot'];
		self::assertCount( $ledger['strict_raw_permission_observations_changed'], $rows );
		$changed = 0;
		foreach ( $rows as $label => [ $slug, $input, $role, $direct, $prior_native ] ) {
			self::assertArrayHasKey( $label, $records );
			$entry = array();
			$records[$label]( $entry );
			self::assertSame( $input, $entry['input'], $label );
			self::assertSame( $role, $entry['role'] );
			self::assertSame( array(), $entry['fault'] );
			self::assertTrue( $entry['unchanged'] );
			self::assertSame( 'invalid_input', $entry['permission_error_code'] );
			self::assertSame( 'ability_invalid_input', $entry['permission']['error']['reason'] );
			$reason = $entry['result']['error']['reason'];
			self::assertSame( 'direct' === $boundary ? $direct : 'ability_invalid_input', $reason );
			$changed += (int) ( 'direct' !== $boundary && $prior_native !== $reason );
		}
		self::assertSame( 'direct' === $boundary ? 0 : $ledger['confirmation']['native_gateway_individual_changed'] + $ledger['optional_flags']['native_gateway_individual_changed'], $changed );
	}

	public function test_wrong_raw_permission_envelope_is_not_accepted_as_a_generic_denial(): void {
		$records = Wstm121Runtime\matrix( 'ability', static function ( $slug, $input, $role, $fault, &$entry ) {
			$result = Webmastery_MCP_Response::error( 'invalid_input', 'Ability input does not match its schema.', array(), 'ability_invalid_input' );
			$entry = array( 'permission_is_wp_error' => true, 'permission_error_code' => 'forbidden',
				'permission' => $result );
			return $result;
		} );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Strict permission rejection' );
		$entry = array();
		$records['delete-media confirmation missing']( $entry );
	}

	public function test_all_eight_taxonomy_originals_and_minimal_counterparts_keep_typed_identity_and_state(): void {
		Wstm121Runtime\load_taxonomy_helper();
		$records = array();
		foreach ( array( 'category' => 'category', 'post_tag' => 'tag' ) as $taxonomy => $slug ) {
			$name = "webmastery-site-toolkit-for-mcp/delete-$slug";
			$args = Probe::$wrapped[$name];
			$ability = new Webmastery_MCP_Ability( $name, $args );
			$observed = Wstm121Runtime\taxonomy_matrix( $ability, $args['execute_callback'], $taxonomy, $slug, 'category' === $taxonomy ? 52 : 51 );
			self::assertCount( 8, $observed );
			$records = array_merge( $records, $observed );
			$offset = 0;
			foreach ( array( 'missing' => 987654321, 'wrong taxonomy' => 'category' === $taxonomy ? 52 : 51 ) as $scenario => $id ) {
				foreach ( array( 'wrapped', 'direct' ) as $path ) {
					$input = array( "{$slug}_id" => $id, 'name' => 'Must not write', 'confirm' => true );
					$pair = array_slice( $observed, $offset, 2 );
					$offset += 2;
					self::assertSame( "delete-$slug: $scenario $path", $pair[0]['label'] );
					self::assertSame( $pair[0]['label'] . ' minimal counterpart', $pair[1]['label'] );
					self::assertSame( $input, $pair[0]['evidence']['input'] );
					unset( $input['name'] );
					self::assertSame( $input, $pair[1]['evidence']['input'] );
					foreach ( $pair as $index => $row ) {
						self::assertTrue( $row['passed'], $row['label'] );
						self::assertSame( 2, $row['evidence']['actor'] );
						self::assertSame( 'manage_categories', $row['evidence']['capability'] );
						self::assertTrue( $row['evidence']['can_delete'] );
						self::assertSame( $row['evidence']['before_sha256'], $row['evidence']['after_sha256'] );
						self::assertSame( array(), $row['evidence']['write_hooks'] );
						self::assertSame( 0 === $index ? 'ability_invalid_input' : 'not_found', $row['evidence']['result']['error']['reason'] );
					}
				}
			}
		}
		self::assertCount( 16, $records );
	}

	public static function taxonomy_failures(): array {
		return array( array( 'wrong-reason' ), array( 'write' ), array( 'hook' ), array( 'capability' ), array( 'query' ), array( 'canonical-wrong-reason' ) );
	}

	/** @dataProvider taxonomy_failures */
	public function test_taxonomy_after_state_and_hooks_survive_failed_oracles( string $fault ): void {
		Wstm121Runtime\load_taxonomy_helper();
		$before = Wstm121Runtime\wstm117_term_snapshot();
		$args = Probe::$wrapped['webmastery-site-toolkit-for-mcp/delete-category'];
		$returned = array();
		$execute = static function ( $input ) use ( $args, $fault, &$returned ) {
			$result = $args['execute_callback']( $input );
			if ( 'wrong-reason' === $fault ) { $result['error']['reason'] = 'invalid_input'; }
			if ( 'canonical-wrong-reason' === $fault ) {
				$result['error']['reason'] = 'invalid_input' === $result['error']['code'] ? 'invalid_input' : 'target_not_found';
			}
			if ( 'write' === $fault ) { Probe::$terms['category'][51]->name = 'Changed'; }
			if ( 'hook' === $fault ) { Wstm121Runtime\apply( 'pre_delete_term', null ); }
			if ( 'capability' === $fault ) { Wstm121Runtime\current_user_can( 'manage_categories' ); }
			if ( 'query' === $fault ) { $GLOBALS['wpdb']->num_queries++; }
			$returned[] = $result;
			return $result;
		};
		$records = array();
		Wstm121Runtime\wstm117_delete_missing_pair( null, $execute, array( 'category_id' => 987654321, 'name' => 'Must not write', 'confirm' => true ), 'category', 'category', 'missing', 'direct', $before,
			static function ( $label, $passed, $evidence ) use ( &$records ): void { $records[] = compact( 'passed', 'evidence' ); } );
		self::assertCount( 2, $records );
		self::assertFalse( $records[0]['passed'] );
		foreach ( $records as $index => $row ) {
			self::assertArrayHasKey( 'after_sha256', $row['evidence'] );
			self::assertArrayHasKey( 'write_hooks', $row['evidence'] );
			self::assertArrayHasKey( 'capability_calls', $row['evidence'] );
			self::assertArrayHasKey( 'query_calls', $row['evidence'] );
			self::assertArrayHasKey( 'oracle_failure', $row['evidence'] );
			self::assertSame( $returned[$index], $row['evidence']['result'] );
			self::assertSame( $records[0]['evidence']['before_sha256'], $row['evidence']['before_sha256'] );
			if ( in_array( $fault, array( 'wrong-reason', 'canonical-wrong-reason' ), true ) ) {
				self::assertFalse( $row['passed'] );
				self::assertSame( $row['evidence']['before_sha256'], $row['evidence']['after_sha256'] );
				self::assertSame( array(), $row['evidence']['write_hooks'] );
			}
		}
		if ( 'wrong-reason' === $fault ) {
			self::assertNull( $records[0]['evidence']['oracle_failure'] );
			self::assertSame( 'not_found', $returned[1]['error']['code'] );
			self::assertSame( 'invalid_input', $returned[1]['error']['reason'] );
			self::assertSame(
				array( 'classification' => 'error_contract_assertion_failed', 'message' => 'Noncanonical error envelope: ' . json_encode( $returned[1] ) ),
				$records[1]['evidence']['oracle_failure']
			);
		}
		if ( 'canonical-wrong-reason' === $fault ) {
			foreach ( $records as $index => $row ) {
				self::assertNull( $row['evidence']['oracle_failure'] );
				self::assertSame( $row['evidence']['result'], wstm118_error_envelope( $row['evidence']['result'] ) );
				self::assertSame( 0 === $index ? 'invalid_input' : 'target_not_found', $row['evidence']['result']['error']['reason'] );
			}
		}
		if ( 'write' === $fault ) {
			self::assertFalse( $records[1]['passed'], 'The original mutation must not become the counterpart baseline.' );
			self::assertNotSame( $records[0]['evidence']['before_sha256'], $records[0]['evidence']['after_sha256'] );
		}
		if ( 'hook' === $fault ) { self::assertSame( array( 'pre_delete_term' ), $records[0]['evidence']['write_hooks'] ); }
	}

	public static function forbidden_effects(): array { return array( array( 'write' ), array( 'hook' ) ); }

	/** @dataProvider forbidden_effects */
	public function test_invocation_guard_retains_evidence_then_rejects_writes_or_hooks( string $effect ): void {
		Wstm121Runtime\load_fault_helper();
		$ability = new class( $effect ) {
			private string $effect;
			public function __construct( $effect ) { $this->effect = $effect; }
			public function check_permissions( $input ) { return true; }
			public function execute( $input ) {
				if ( 'write' === $this->effect ) { State::$persisted['unexpected'] = true; }
				else { Wstm121Runtime\apply( 'pre_delete_attachment', null ); }
				return array( 'success' => true, 'data' => array() );
			}
		};
		$evidence = new class {
			public array $records = array();
			public function append( $record ): void { $this->records[] = $record; }
		};
		$invoke = Wstm121Runtime\invoker( $ability, $evidence );
		$entry = array();
		try {
			$invoke( 'delete-media', array(), 'author', array(), $entry, true );
			self::fail( 'Guard must reject the effect.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'write' === $effect ? 'changed persisted state' : 'mutation hook', $error->getMessage() );
		}
		self::assertSame( $entry, $evidence->records[0]['evidence'] );
		self::assertSame( 'write' !== $effect, $entry['before'] === $entry['after'] );
		self::assertSame( 'hook' === $effect ? array( 'pre_delete_attachment' ) : array(), $entry['hooks'] );
	}

	private function media_invoker( ?string $fault_source = null ): array {
		Wstm121Runtime\load_fault_helper( $fault_source );
		Wstm121Runtime\load_media();
		$name = 'webmastery-site-toolkit-for-mcp/delete-media';
		$ability = new Webmastery_MCP_Ability( $name, State::$abilities[$name] );
		$evidence = new class {
			public array $records = array();
			public function append( $record ): void { $this->records[] = $record; }
		};
		return array( Wstm121Runtime\invoker( $ability, $evidence ), $evidence );
	}

	public static function reference_faults(): array {
		$rows = array();
		foreach ( array( 'thumbnail', 'content' ) as $phase ) {
			foreach ( array( false, true ) as $force ) {
				foreach ( array( false, true ) as $known ) { $rows[] = array( $phase, $force, $known ); }
			}
		}
		return $rows;
	}

	/** @dataProvider reference_faults */
	public function test_production_query_shapes_hit_only_the_selected_phase_before_force( string $phase, bool $force, bool $known ): void {
		[ $invoke, $evidence ] = $this->media_invoker();
		$GLOBALS['wpdb']->content_hit = $known;
		$entry = array();
		$result = $invoke( 'delete-media', array( 'media_id' => 46, 'confirm' => true, 'force' => $force ), 'author',
			array( 'mode' => 'query_failure', 'id' => 46, 'owner' => State::$owner, 'phase' => $phase ), $entry, true );
		self::assertSame( 'content_hygiene_query_failed', $result['error']['reason'] );
		self::assertSame( 'upstream_failed', $result['error']['code'] );
		self::assertStringNotContainsString( 'WSTM116_PRIVATE_SQL_FAILURE', json_encode( $result ) );
		self::assertSame( 1, $entry['fault_query_hits'] );
		self::assertSame( array(), $entry['permission_fault_queries'] );
		self::assertSame( array(), $entry['hooks'] );
		self::assertSame( array(), State::$deleted );
		self::assertSame( $entry['before'], $entry['after'] );
		self::assertSame( $entry, $evidence->records[0]['evidence'] );
		$fault = $entry['fault_queries'][0];
		self::assertSame( array( $phase, 46 ), array( $fault['phase'], $fault['candidate_id'] ) );
		self::assertSame( 'SELECT WSTM116_PRIVATE_SQL_FAILURE FROM', $fault['replacement'] );
		self::assertNotSame( $fault['original'], $fault['replacement'] );
		self::assertCount( 'thumbnail' === $phase ? 1 : 2, $GLOBALS['wpdb']->queries );
		if ( 'content' === $phase ) {
			self::assertSame( $GLOBALS['wpdb']->queries[0][0], $GLOBALS['wpdb']->queries[0][1], 'Content failure must not be substituted by a thumbnail failure.' );
			self::assertStringContainsString( '\\\\_', $fault['original'] );
			self::assertStringContainsString( '\\\\%', $fault['original'] );
		}
		self::assertFalse( $GLOBALS['wpdb']->suppressed, 'Fault teardown restores the previous suppression policy.' );
	}

	public function test_zero_hit_failure_claim_rejects_and_retains_actual_premature_mutation(): void {
		[ $invoke, $evidence ] = $this->media_invoker();
		$GLOBALS['wpdb']->thumbnail_hits = array( '46' );
		$entry = array();
		try {
			$invoke( 'delete-media', array( 'media_id' => 46, 'confirm' => true, 'force' => true ), 'author',
				array( 'mode' => 'query_failure', 'id' => 46, 'owner' => State::$owner, 'phase' => 'content' ), $entry, true );
			self::fail( 'A never-reached content phase must not pass a fault claim.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'exactly one execution query', $error->getMessage() );
		}
		self::assertSame( 0, $entry['fault_query_hits'] );
		self::assertSame( array( 46 ), State::$deleted );
		self::assertNotSame( $entry['before'], $entry['after'] );
		self::assertSame( array( 'pre_delete_attachment' ), $entry['hooks'] );
		self::assertSame( $entry, $evidence->records[0]['evidence'], 'Never reset or discard evidence after premature mutation.' );
	}

	public function test_query_filter_rejects_unowned_candidates_and_does_not_match_other_tables_or_ids(): void {
		Wstm121Runtime\load_fault_helper();
		$config = array( 'mode' => 'query_failure', 'phase' => 'thumbnail', 'id' => 46, 'owner' => State::$owner );
		$sql = Wstm121Runtime\wstm116_reference_fault_sql( $config );
		$queries = array();
		$undo = Wstm121Runtime\wstm116_faults( $config, $queries );
		try {
			foreach ( array( str_replace( "'46'", "'47'", $sql ), str_replace( 'owned_postmeta', 'foreign_postmeta', $sql ), "SELECT COUNT(1) FROM owned_postmeta WHERE meta_key='_thumbnail_id'", 'SELECT unrelated' ) as $unrelated ) {
				self::assertSame( $unrelated, Wstm121Runtime\apply( 'query', $unrelated ) );
			}
			self::assertSame( array(), $queries );
			self::assertSame( 'SELECT WSTM116_PRIVATE_SQL_FAILURE FROM', Wstm121Runtime\apply( 'query', $sql ) );
			self::assertSame( array( array( 'phase' => 'thumbnail', 'candidate_id' => 46, 'original' => $sql, 'replacement' => 'SELECT WSTM116_PRIVATE_SQL_FAILURE FROM' ) ), $queries );
		} finally {
			$undo();
		}
		self::assertSame( $sql, Wstm121Runtime\apply( 'query', $sql ) );
		$config['owner'] = 'foreign-owner';
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not owned' );
		Wstm121Runtime\wstm116_faults( $config );
	}

	public static function placeholder_boundaries(): array {
		return array( 'old escaped comparison' => array( false ), 'normalized late comparison' => array( true ) );
	}

	/** @dataProvider placeholder_boundaries */
	public function test_core_percent_unescape_precedes_late_fault_matching( bool $normalize ): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__ ) . '/e2e/destructive-safety-fixture.php' ) );
		if ( ! $normalize ) {
			$original = $source;
			$source = str_replace( "\t\t\$expected = \$wpdb->remove_placeholder_escape( \$expected );\n", '', $source, $count );
			self::assertSame( 1, $count, 'Reproduce exactly the prior comparison boundary, not a no-op mutant.' );
			self::assertNotSame( $original, $source );
		}
		[ $invoke, $evidence ] = $this->media_invoker( $source );
		$entry = array();
		try {
			$result = $invoke( 'delete-media', array( 'media_id' => 46, 'confirm' => true, 'force' => true ), 'author',
				array( 'mode' => 'query_failure', 'id' => 46, 'owner' => State::$owner, 'phase' => 'content' ), $entry, true );
			self::assertTrue( $normalize, 'The old matcher must miss content and fail its fault claim.' );
			self::assertSame( 'content_hygiene_query_failed', $result['error']['reason'] );
		} catch ( RuntimeException $error ) {
			if ( $normalize ) { throw $error; }
			self::assertStringContainsString( 'exactly one execution query', $error->getMessage() );
		}
		$db = $GLOBALS['wpdb'];
		self::assertCount( 2, $db->prepared_queries );
		self::assertCount( 2, $db->queries );
		$prepared = $db->prepared_queries[1];
		$observed = $db->queries[1][0];
		self::assertStringContainsString( $db->placeholder_escape(), $prepared );
		self::assertStringNotContainsString( '%', $prepared, 'prepare escapes every percent, including literal LIKE percents.' );
		self::assertStringNotContainsString( $db->placeholder_escape(), $observed );
		self::assertStringContainsString( '\\\\%', $observed );
		self::assertStringContainsString( '\\\\_', $observed );
		self::assertSame( $db->remove_placeholder_escape( $prepared ), $observed );
		self::assertSame( $normalize ? 1 : 0, $entry['fault_query_hits'] );
		self::assertSame( $normalize ? array() : array( 46 ), State::$deleted );
		self::assertSame( $normalize, $entry['before'] === $entry['after'] );
		self::assertSame( $entry, $evidence->records[0]['evidence'] );
		if ( $normalize ) {
			self::assertSame( $observed, $entry['fault_queries'][0]['original'] );
			self::assertSame( 'SELECT WSTM116_PRIVATE_SQL_FAILURE FROM', $entry['fault_queries'][0]['replacement'] );
		} else {
			self::assertSame( $observed, $db->queries[1][1], 'Priority-zero unescaping alone is not a simulated query failure.' );
		}
	}
}
