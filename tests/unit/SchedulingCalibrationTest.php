<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm113Calibration\Probe;

require_once __DIR__ . '/fixtures/scheduling-calibration.php';
require_once dirname( __DIR__ ) . '/e2e/error-contract-assertions.php';

/**
 * Source calibration and real registered callbacks over inert platform edges.
 * No WordPress bootstrap, external runtime, or fabricated native success.
 */
final class SchedulingCalibrationTest extends TestCase {
	private function ledger(): stdClass {
		return Wstm113Calibration\load_ledger();
	}

	private function typed( $value ): string {
		return Wstm113Calibration\json( $value );
	}

	private function source(): string {
		return Wstm113Calibration\normalized( file_get_contents( dirname( __DIR__ ) . '/e2e/scheduling-runner.php' ) );
	}

	private function reject( callable $operation, string $message ): void {
		try {
			$operation();
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( $message, $error->getMessage() );
			return;
		}
		self::fail( 'Mutation was accepted: ' . $message );
	}

	public function test_bounded_source_bridge_preserves_sealed_history_and_rejects_source_or_binding_drift(): void {
		$ledger = $this->ledger();
		$historical = array(
			'includes/class-posts.php' => array( '698c1dace3460fd49332a94d85313780fd4b26195410ef8979056609e1609976', '32cb16faee498e28d130820128f130f56aa70a35' ),
			'includes/class-custom-post-types.php' => array( '84f5ec24a7a5faf472b9735acbdc13bdfdcca70d896aefbd0fc5d6139648cdfb', 'c025ffdca479c87d89d84b69f36f8e39995bf469' ),
		);
		foreach ( $historical as $path => [ $sha256, $blob ] ) {
			self::assertSame( $sha256, $ledger->production->$path->baseline_sha256 );
			self::assertSame( $sha256, $ledger->production->$path->current_sha256 );
			self::assertSame( $blob, $ledger->production->$path->git_blob );
		}
		foreach ( Wstm113Calibration\bounded_source_bindings() as $path => $binding ) {
			$source = Wstm113Calibration\normalized( file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
			Wstm113Calibration\assert_source_binding( $path, $source, $binding );
			Wstm113Calibration\assert_source_binding( $path, str_replace( "\n", "\r\n", $source ), $binding );
			$message = 'Scheduling production source binding changed: ' . $path;
			$this->reject( static fn() => Wstm113Calibration\assert_source_binding( $path, $source . "\n", $binding ), $message );
			$this->reject( static fn() => Wstm113Calibration\assert_source_binding( $path, $source, array( str_repeat( 'a', 64 ), $binding[1] ) ), $message );
			$this->reject( static fn() => Wstm113Calibration\assert_source_binding( $path, $source, array( $binding[0], str_repeat( 'a', 40 ) ) ), $message );
		}
		Wstm113Calibration\assert_sources( $ledger );
	}

	public function test_sealed_whole_source_derives_264_to_268_offline_with_exact_typed_pairs(): void {
		$ledger = $this->ledger();
		self::assertSame( $this->typed( $ledger ), $this->typed( Wstm113Calibration\derive( $ledger ) ) );
		self::assertSame( array( 'old' => 264, 'unchanged_native' => 260, 'changed_direct' => 4, 'added_direct' => 4, 'new' => 268 ), (array) $ledger->counts );
		self::assertSame( array( 58, 124, 190, 256 ), array_column( $ledger->pairs, 'old_position' ) );
		self::assertSame( array( 58, 125, 192, 259 ), array_column( $ledger->pairs, 'new_position' ) );
		self::assertSame( array( 59, 126, 193, 260 ), array_column( $ledger->pairs, 'minimal_position' ) );
		self::assertSame( "{\n    \"object\": {},\n    \"array\": [],\n    \"fraction\": 1.0\n}\n", $this->typed( array( 'object' => new stdClass(), 'array' => array(), 'fraction' => 1.0 ) ) );
		foreach ( $ledger->pairs as $pair ) {
			self::assertSame( $pair->new->id, $pair->minimal->id );
			self::assertIsInt( $pair->new->input->{$pair->new->id_key} );
			self::assertSame( $this->typed( $pair->new->post ), $this->typed( $pair->minimal->post ) );
			self::assertSame( $this->typed( $pair->new->metadata ), $this->typed( $pair->minimal->metadata ) );
			$input = clone $pair->new->input;
			unset( $input->status );
			self::assertSame( $this->typed( $input ), $this->typed( $pair->minimal->input ) );
			self::assertSame( '2030-06-01 08:00:00', $pair->new->post->post_date );
			self::assertSame( gmdate( 'Y-m-d H:i:s', Probe::NOW - 3600 ), $pair->new->post->post_date_gmt );
			self::assertSame( 'future', $pair->new->post->post_status );
			self::assertCount( 14, $pair->new->hooks );
			self::assertInstanceOf( stdClass::class, $pair->new_oracle->error->details );
		}
	}

	private function expected_events( stdClass $row ): array {
		$cap = array( 'post' => 'edit_post', 'page' => 'edit_post', 'mcp_book' => 'edit_mcp_book', 'mcp_case_study' => 'edit_mcp_case' )[ $row->type ];
		$events = array( 'execute_callback', array( 'query', $row->id ), array( 'cap', $cap, array( $row->id ) ) );
		if ( 'mcp_book' === $row->type ) {
			$events[] = array( 'taxonomy', 'mcp_genre' );
			$events[] = array( 'cap', 'assign_mcp_genres', array() );
		}
		$events[] = array( 'schedule', $row->id );
		return $events;
	}

	public function test_only_approved_loop_dispatch_and_four_oracle_changes_separate_sources(): void {
		$ledger = $this->ledger();
		$source = $this->source();
		$attempts = Wstm113Calibration\between( $source, "\t\t\t\t\$direct = ", "\t\t\t\t\t\$before_post = " );
		$source = str_replace( $attempts, '', $source, $count );
		self::assertSame( 1, $count );
		$body = Wstm113Calibration\between( $source, "\t\t\t\t\t\$before_post = ", "\t\t\t\tif ( 'timezone-change' === \$fixture ) {" );
		self::assertStringEndsWith( "\t\t\t\t}\n", $body );
		$unwrapped = preg_replace( '/^\t/m', '', substr( $body, 0, -strlen( "\t\t\t\t}\n" ) ) );
		$source = str_replace( $body, $unwrapped, $source, $count );
		self::assertSame( 1, $count );
		$strict = Wstm113Calibration\between( $source, "\t\t\t\tif ( \$direct ) {\n\t\t\t\t\t\$envelope = ", "\t\t\t\tif ( \$id ) {" );
		$source = str_replace( $strict, '', $source, $count );
		self::assertSame( 1, $count );
		$source = str_replace(
			"if ( \$direct ) {\n\t\t\t\t\t// Exercise the registered guard, then the schema-valid scheduling path.",
			"if ( 'direct-invalid-status-overdue' === \$label ) {\n\t\t\t\t\t// The API schema rejects this enum; also verify callback defense.",
			$source,
			$count
		);
		self::assertSame( 1, $count );
		$source = str_replace(
			"[ 'direct-invalid-status-overdue', [ 'status' => 'not-a-status' ], 'ability_invalid_input', 'overdue' ]",
			"[ 'direct-invalid-status-overdue', [ 'status' => 'not-a-status' ], 'scheduled_date_too_soon', 'overdue' ]",
			$source,
			$count
		);
		self::assertSame( 1, $count );
		self::assertSame( $ledger->original_source, $source, 'All unrelated predicates, hooks, snapshots, fixture setup and cleanup must remain byte-identical.' );
	}

	public function test_original_then_minimal_execute_real_registered_guards_on_same_fixture(): void {
		$ledger = $this->ledger();
		Probe::load( $ledger );
		$records = array();
		foreach ( $ledger->pairs as $pair ) {
			Probe::fixture( $pair->new );
			$fixture = Probe::snapshot();
			$args = Probe::$abilities[ $pair->new->registered_name ];
			self::assertFalse( $args['input_schema']['additionalProperties'] );
			self::assertInstanceOf( WP_Error::class, Webmastery_MCP_Input::validate( $pair->new->input, $args['input_schema'] ) );
			self::assertNull( Webmastery_MCP_Input::validate( $pair->minimal->input, $args['input_schema'] ) );
			foreach ( array( 'new' => 'new_oracle', 'minimal' => 'minimal_oracle' ) as $kind => $oracle ) {
				$row = $pair->$kind;
				$record = Wstm113Calibration\execute( $this->source(), $row );
				$records[] = $row->label;
				self::assertTrue( $record['passed'], $row->registered_name . ':' . $row->label );
				self::assertSame( $this->typed( $pair->$oracle ), $this->typed( $record['result'] ) );
				self::assertSame( $fixture, $record['before'] );
				self::assertSame( $fixture, $record['after'] );
				self::assertSame( array(), $record['observed'] );
				self::assertSame( $row->hooks, $record['observers'] );
				self::assertSame( array(), $record['remaining_hooks'] );
				self::assertSame( 'new' === $kind ? array() : $this->expected_events( $row ), $record['events'] );
				self::assertSame( 'Scheduling metadata sentinel', Wstm113Calibration\get_post_meta( $row->id, '_yoast_wpseo_metadesc', true ) );
			}
		}
		self::assertSame( array_merge( ...array_fill( 0, 4, array( 'direct-invalid-status-overdue', 'direct-existing-future-overdue' ) ) ), $records );
	}

	private function assert_same_input_dual_layer_trace( stdClass $row, array $record ): void {
		self::assertSame( array( 'registered_callback', 'scheduling_helper' ), array_keys( $record['layers'] ) );
		self::assertSame( array( 'snapshot', 'registered_callback', 'snapshot', 'scheduling_helper', 'snapshot' ), $record['phases'] );
		self::assertSame( array( array( 'query', $row->id ), array( 'schedule', $row->id ), array( 'query', $row->id ) ), $record['events'] );
		self::assertSame( $row->id, $record['post']['ID'] );
		self::assertSame( array(), Probe::$hooks, 'Both calls share one observation window, then remove every observer.' );
		foreach ( $record['layers'] as $layer ) {
			self::assertSame( $this->typed( $row->input ), $this->typed( $layer['input'] ) );
			self::assertSame( $this->typed( $row->post ), $this->typed( $layer['precall_post'] ) );
			self::assertSame( $record['before'], $layer['before'] );
			self::assertTrue( $layer['observing'] );
		}
		$native = $record['layers']['scheduling_helper']['native_result'];
		self::assertInstanceOf( WP_Error::class, $native );
		self::assertSame( 'scheduled_date_too_soon', wstm118_error_reason( Webmastery_MCP_Response::from_wp_error( $native ) ) );
		self::assertSame( $record['after'], $record['layers']['scheduling_helper']['after'] );
		self::assertSame( $record['hooks'], $record['layers']['scheduling_helper']['hooks'] );
	}

	public function test_original_invalid_input_and_precall_post_also_reach_internal_helper_in_one_observation_window(): void {
		$ledger = $this->ledger();
		Probe::load( $ledger );
		foreach ( $ledger->pairs as $pair ) {
			$record = Wstm113Calibration\dual_layer( $this->source(), $pair->new );
			$this->assert_same_input_dual_layer_trace( $pair->new, $record );
			self::assertTrue( $record['passed'] );
			self::assertSame( $this->typed( $pair->new_oracle ), $this->typed( $record['layers']['registered_callback']['result'] ) );
			self::assertSame( $this->typed( $pair->minimal_oracle ), $this->typed( $record['layers']['scheduling_helper']['result'] ) );
			foreach ( $record['layers'] as $layer ) {
				self::assertTrue( $layer['passed'] );
				self::assertTrue( $layer['error_matches'] );
				self::assertNull( $layer['oracle_failure'] );
				self::assertSame( $record['before'], $layer['after'] );
				self::assertSame( array(), $layer['hooks'] );
			}
			$before = json_decode( $record['before'], true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( array( 7 ), $before['terms'] );
			self::assertSame( array( 1906545600 ), $before['cron'] );
		}
	}

	public static function dual_layer_faults(): array {
		$cases = array();
		foreach ( array( 'registered_callback', 'scheduling_helper' ) as $layer ) {
			$faults = array( 'wrong-reason', 'wrong-code', 'success', 'raw-object', 'object-data', 'redirect-id', 'post', 'metadata', 'sentinel-read', 'terms', 'cron', 'hook' );
			if ( 'registered_callback' === $layer ) {
				$faults[] = 'native-error';
			}
			foreach ( $faults as $fault ) {
				$cases[ $layer . ':' . $fault ] = array( $layer, $fault );
			}
		}
		return $cases;
	}

	/** @dataProvider dual_layer_faults */
	public function test_same_input_dual_layer_faults_remain_red_without_skipping_helper_or_retargeting_inspection( string $layer, string $fault ): void {
		$ledger = $this->ledger();
		Probe::load( $ledger );
		foreach ( $ledger->pairs as $pair ) {
			$record = Wstm113Calibration\dual_layer( $this->source(), $pair->new, $layer, $fault );
			$this->assert_same_input_dual_layer_trace( $pair->new, $record );
			self::assertFalse( $record['passed'] );
			$evidence = $record['layers'][ $layer ];
			self::assertFalse( $evidence['passed'] );
			if ( in_array( $fault, array( 'wrong-reason', 'wrong-code', 'success', 'raw-object', 'object-data', 'redirect-id', 'native-error' ), true ) ) {
				self::assertFalse( $evidence['error_matches'] );
				self::assertSame( $record['before'], $record['after'] );
				self::assertSame( array(), $record['hooks'] );
				if ( 'wrong-reason' === $fault ) {
					self::assertNull( $evidence['oracle_failure'] );
				} else {
					self::assertStringContainsString( 'Noncanonical error envelope:', $evidence['oracle_failure'] );
				}
				if ( 'raw-object' === $fault ) {
					self::assertInstanceOf( stdClass::class, $evidence['result'] );
					self::assertSame( 999, $evidence['result']->data->id );
				} elseif ( 'object-data' === $fault ) {
					self::assertInstanceOf( stdClass::class, $evidence['result']['data'] );
					self::assertSame( 999, $evidence['result']['data']->id );
				} elseif ( 'redirect-id' === $fault ) {
					self::assertSame( 999, $evidence['result']['data']['id'] );
				} elseif ( 'native-error' === $fault ) {
					self::assertInstanceOf( WP_Error::class, $evidence['result'] );
				}
			} elseif ( in_array( $fault, array( 'hook', 'sentinel-read' ), true ) ) {
				self::assertSame( $record['before'], $record['after'] );
				self::assertSame( 'hook' === $fault ? array( 'save_post' => 1 ) : array(), $record['hooks'] );
			} else {
				self::assertNotSame( $record['before'], $record['after'] );
				self::assertNotSame( $record['before'], $evidence['after'] );
			}
			if ( 'scheduling_helper' === $layer ) {
				self::assertTrue( $record['layers']['registered_callback']['passed'] );
				self::assertSame( $record['before'], $record['layers']['registered_callback']['after'] );
			}
		}
	}

	public function test_historical_four_red_oracles_remain_red_with_actual_schema_errors(): void {
		$ledger = $this->ledger();
		Probe::load( $ledger );
		foreach ( $ledger->pairs as $pair ) {
			Probe::fixture( $pair->old );
			$record = Wstm113Calibration\execute( $ledger->original_source, $pair->old );
			self::assertFalse( $record['passed'] );
			self::assertSame( $this->typed( $pair->new_oracle ), $this->typed( $record['result'] ) );
			self::assertSame( array(), $record['events'] );
			self::assertSame( $record['before'], $record['after'] );
			self::assertSame( array(), $record['observed'] );
		}
	}

	public function test_permission_wrappers_reject_invalid_input_without_callback_or_capability_work(): void {
		$ledger = $this->ledger();
		Probe::load( $ledger );
		foreach ( $ledger->pairs as $pair ) {
			Probe::fixture( $pair->new );
			$before = Probe::snapshot();
			$args = Probe::$abilities[ $pair->new->registered_name ];
			$result = $args['permission_callback']( $pair->new->input );
			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'invalid_input', $result->get_error_code() );
			self::assertSame( $this->typed( $pair->new_oracle ), $this->typed( wstm118_error_envelope( $result ) ) );
			self::assertSame( array(), Probe::$events );
			self::assertSame( $before, Probe::snapshot() );
			self::assertTrue( $args['permission_callback']( $pair->minimal->input ) );
			self::assertSame( 'permission_callback', Probe::$events[0] );
			Probe::$events = array();
			Probe::$allowed = false;
			$denied = $args['execute_callback']( $pair->minimal->input );
			self::assertSame( 'forbidden', wstm118_error_reason( $denied ) );
			self::assertFalse( Wstm113Calibration\predicate( $this->source(), $pair->minimal, $denied, $before, Probe::snapshot(), array() ) );
			self::assertSame( array_slice( $this->expected_events( $pair->minimal ), 0, 3 ), Probe::$events );
			self::assertSame( $before, Probe::snapshot() );
			Probe::$events = array();
			$denied = $args['permission_callback']( $pair->minimal->input );
			self::assertInstanceOf( WP_Error::class, $denied );
			self::assertSame( 'forbidden', $denied->get_error_code() );
			self::assertSame( 'permission_callback', Probe::$events[0] );
		}
	}

	public function test_status_coercion_and_wrapper_bypass_cannot_satisfy_original_schema_oracle(): void {
		$ledger = $this->ledger();
		Probe::load( $ledger );
		foreach ( $ledger->pairs as $pair ) {
			Probe::fixture( $pair->new );
			$before = Probe::snapshot();
			$raw = Probe::$raw[ $pair->new->registered_name ]['execute_callback'];
			$result = $raw( json_decode( $this->typed( $pair->new->input ), true, 512, JSON_THROW_ON_ERROR ) );
			self::assertSame( 'scheduled_date_too_soon', wstm118_error_reason( $result ) );
			self::assertFalse( Wstm113Calibration\predicate( $this->source(), $pair->new, $result, $before, Probe::snapshot(), array() ) );
			self::assertNotEmpty( Probe::$events );
			$coerced = clone $pair->new->input;
			$coerced->status = 'future';
			$result = Probe::$abilities[ $pair->new->registered_name ]['execute_callback']( $coerced );
			self::assertSame( 'scheduled_date_too_soon', wstm118_error_reason( $result ) );
			self::assertFalse( Wstm113Calibration\predicate( $this->source(), $pair->new, $result, $before, Probe::snapshot(), array() ) );
			self::assertSame( $before, Probe::snapshot() );
		}
	}

	public function test_minimal_ID_types_are_not_coerced_by_registered_wrappers(): void {
		$ledger = $this->ledger();
		Probe::load( $ledger );
		foreach ( $ledger->pairs as $pair ) {
			foreach ( array( (string) $pair->minimal->id, (float) $pair->minimal->id, array( $pair->minimal->id ), null ) as $value ) {
				Probe::fixture( $pair->minimal );
				$input = clone $pair->minimal->input;
				$input->{$pair->minimal->id_key} = $value;
				$result = Probe::$abilities[ $pair->minimal->registered_name ]['execute_callback']( $input );
				self::assertSame( $this->typed( $pair->new_oracle ), $this->typed( $result ) );
				self::assertSame( array(), Probe::$events );
			}
		}
	}

	/** @dataProvider source_mutants */
	public function test_actual_whole_construction_mutants_change_typed_inventory( string $old, string $new ): void {
		$source = str_replace( $old, $new, $this->source(), $count );
		self::assertSame( 1, $count, $old );
		self::assertNotSame( $this->typed( $this->ledger()->new_inventory ), $this->typed( Wstm113Calibration\inventory( $source ) ) );
	}

	public static function source_mutants(): array {
		return array(
			'old reason' => array( "[ 'direct-invalid-status-overdue', [ 'status' => 'not-a-status' ], 'ability_invalid_input', 'overdue' ]", "[ 'direct-invalid-status-overdue', [ 'status' => 'not-a-status' ], 'scheduled_date_too_soon', 'overdue' ]" ),
			'drop originals' => array( '$attempts = [ [ $label, $input, $error ] ];', '$attempts = $direct ? [] : [ [ $label, $input, $error ] ];' ),
			'drop minimals' => array( "\$attempts[] = [ 'direct-existing-future-overdue', \$minimal, 'scheduled_date_too_soon' ];", '' ),
			'reverse pairs' => array( "\$attempts[] = [ 'direct-existing-future-overdue', \$minimal, 'scheduled_date_too_soon' ];", "array_unshift( \$attempts, [ 'direct-existing-future-overdue', \$minimal, 'scheduled_date_too_soon' ] );" ),
			'actual title initializer' => array( "'title' => 'WSTM113 ' . \$label", "'title' => 'Changed ' . \$label" ),
			'actual content initializer' => array( "'content' => '<p>Changed content</p>'", "'content' => '<p>Different content</p>'" ),
			'actual slug initializer' => array( "'slug' => 'wstm113-' . \$label", "'slug' => 'changed-' . \$label" ),
			'actual status initializer' => array( "[ 'status' => 'not-a-status' ]", "[ 'status' => 'future' ]" ),
			'ID string coercion' => array( '$input[ $id_key ] = $id;', '$input[ $id_key ] = (string) $id;' ),
			'ID float coercion' => array( '$input[ $id_key ] = $id;', '$input[ $id_key ] = (float) $id;' ),
			'ID retarget' => array( '$input[ $id_key ] = $id;', '$input[ $id_key ] = $id + 1;' ),
			'zero ID' => array( '$input[ $id_key ] = $id;', '$input[ $id_key ] = 0;' ),
			'negative ID' => array( '$input[ $id_key ] = $id;', '$input[ $id_key ] = -$id;' ),
			'actor substitution' => array( "[ 'mcp_book', 'cpt-mcp-book', 'id', 'book_manager_test' ]", "[ 'mcp_book', 'cpt-mcp-book', 'id', 'editor_test' ]" ),
			'target fixture changed' => array( "'Original content'", "'Different fixture content'" ),
			'overdue GMT changed' => array( "30 : -3600", "30 : -7200" ),
			'local date changed' => array( ": '2030-06-01 08:00:00'", ": '2030-06-01 09:00:00'" ),
			'metadata sentinel changed' => array( "update_post_meta( \$id, '_yoast_wpseo_metadesc', 'Scheduling metadata sentinel' );", "update_post_meta( \$id, '_yoast_wpseo_metadesc', 'Changed sentinel' );" ),
			'category item types changed' => array( "\$input['category_ids'] = array_map( 'intval', \$terms );", "\$input['category_ids'] = \$terms;" ),
			'book term types changed' => array( "[ 'mcp_genre' => array_map( 'intval', \$terms ) ]", "[ 'mcp_genre' => \$terms ]" ),
			'page parent type changed' => array( "\$input['parent'] = \$parent_id;", "\$input['parent'] = (string) \$parent_id;" ),
			'status not removed' => array( "unset( \$minimal['status'] );", '' ),
			'dispatch bypass' => array( "\$direct = 'direct-invalid-status-overdue' === \$label;", '$direct = false;' ),
			'hook coverage weakened' => array( "'save_post', 'transition_post_status'", "'transition_post_status'" ),
		);
	}

	/** @dataProvider envelope_mutants */
	public function test_exact_paired_envelopes_reject_mutants( string $field, $value ): void {
		foreach ( $this->ledger()->pairs as $pair ) {
			foreach ( array( 'new' => 'new_oracle', 'minimal' => 'minimal_oracle' ) as $kind => $oracle ) {
				Probe::fixture( $pair->$kind );
				$result = (array) $pair->$oracle;
				$result['error'] = (array) $result['error'];
				$result['error'][ $field ] = $value;
				$state = Probe::snapshot();
				try {
					$accepted = Wstm113Calibration\predicate( $this->source(), $pair->$kind, $result, $state, $state, array() );
				} catch ( RuntimeException $error ) {
					self::assertStringContainsString( 'Noncanonical error envelope:', $error->getMessage() );
					continue;
				}
				self::assertFalse( $accepted );
			}
		}
	}

	public static function envelope_mutants(): array {
		return array(
			'wrong code only' => array( 'code', 'not_found' ),
			'wrong reason only' => array( 'reason', 'invalid_scheduled_date' ),
			'wrong message only' => array( 'message', 'Different diagnostic.' ),
			'details array' => array( 'details', array() ),
			'nonempty details' => array( 'details', (object) array( 'unexpected' => true ) ),
		);
	}

	/** @dataProvider predicate_mutants */
	public function test_no_write_hook_and_sentinel_checks_reject_evidence_and_detect_bypass( string $old, string $new, string $damage ): void {
		$ledger = $this->ledger();
		Probe::load( $ledger );
		$source = str_replace( $old, $new, $this->source(), $count );
		self::assertSame( 1, $count );
		foreach ( $ledger->pairs as $pair ) {
			foreach ( array( $pair->new, $pair->minimal ) as $row ) {
				Probe::fixture( $row );
				$result = Probe::$abilities[ $row->registered_name ]['execute_callback']( $row->input );
				$before = Probe::snapshot();
				$after = 'state' === $damage ? $before . 'changed' : $before;
				$hooks = 'hooks' === $damage ? array( 'save_post' => 1 ) : array();
				if ( 'sentinel' === $damage ) {
					Probe::$meta[ $row->id ]['_yoast_wpseo_metadesc'] = 'Changed sentinel';
				}
				self::assertFalse( Wstm113Calibration\predicate( $this->source(), $row, $result, $before, $after, $hooks ) );
				self::assertTrue( Wstm113Calibration\predicate( $source, $row, $result, $before, $after, $hooks ), 'Negative proof: removing the specific guard wrongly accepts damaged evidence.' );
			}
		}
	}

	public static function predicate_mutants(): array {
		return array(
			'before-after bypass' => array( '$before === $after', 'true', 'state' ),
			'hook bypass' => array( '[] === $observed', 'true', 'hooks' ),
			'sentinel bypass' => array( "'Scheduling metadata sentinel' === get_post_meta( \$id, '_yoast_wpseo_metadesc', true )", 'true', 'sentinel' ),
		);
	}

	/** @dataProvider seal_mutants */
	public function test_seal_rejects_forged_sources_bindings_and_typed_oracles_before_eval( string $kind ): void {
		$ledger = $this->ledger();
		switch ( $kind ) {
			case 'source':
				$ledger->original_source .= "\nthrow new \\LogicException('Forged historical source executed');\n";
				$ledger->original_sha256 = hash( 'sha256', $ledger->original_source );
				break;
			case 'current source':
				$ledger->current_source .= "\nthrow new \\LogicException('Forged current source executed');\n";
				$ledger->current_sha256 = hash( 'sha256', $ledger->current_source );
				break;
			case 'production hash':
				$ledger->production->{'includes/class-input.php'}->baseline_sha256 = str_repeat( 'a', 64 );
				break;
			case 'production blob':
				$ledger->production->{'includes/class-input.php'}->git_blob = str_repeat( 'a', 40 );
				break;
			case 'float ID':
				$ledger->pairs[0]->new->input->post_id = (float) $ledger->pairs[0]->new->input->post_id;
				break;
			case 'details type':
				$ledger->pairs[0]->new_oracle->error->details = array();
				break;
			case 'reason':
				$ledger->pairs[0]->new_oracle->error->reason = 'scheduled_date_too_soon';
				break;
			case 'role':
				$ledger->pairs[0]->new->actor = 23;
				break;
			case 'no-write':
				$ledger->pairs[0]->no_write = false;
				break;
			default:
				throw new LogicException( 'Unknown seal mutant.' );
		}
		$this->reject( static fn() => Wstm113Calibration\load_ledger( Wstm113Calibration\json( $ledger ) ), 'canonical fingerprint mismatch' );
		$this->reject( static fn() => Wstm113Calibration\derive( $ledger ), 'canonical fingerprint mismatch' );
		$this->reject( static fn() => Probe::load( $ledger ), 'canonical fingerprint mismatch' );
	}

	public static function seal_mutants(): array {
		$rows = array();
		foreach ( array( 'source', 'current source', 'production hash', 'production blob', 'float ID', 'details type', 'reason', 'role', 'no-write' ) as $kind ) {
			$rows[ $kind ] = array( $kind );
		}
		return $rows;
	}
}
