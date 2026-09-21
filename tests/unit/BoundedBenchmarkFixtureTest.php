<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/bounded-list-assertions.php';

final class BoundedBenchmarkFixtureTest extends TestCase {
	private function record(): array {
		return array(
			'per_page' => 20, 'page' => 2, 'orphan' => false, 'candidate_count' => 21,
			'checked_ids' => range( 21, 40 ), 'window_ids' => range( 21, 40 ),
			'cap_calls' => 110, 'sql_count' => 72, 'mutations' => array(),
			'memory_upper_bound_bytes' => 64 * 1024 * 1024,
			'controlled_default_summary' => true, 'payload_bytes' => 64 * 1024,
			'item_ids' => range( 21, 40 ), 'expected_ids' => range( 21, 40 ),
			'next_page' => 3, 'expected_next_page' => 3,
			'candidate_ids' => range( 21, 41 ), 'expected_candidate_ids' => range( 21, 41 ),
			'queries' => array( array( 'fields' => 'ids', 'no_found_rows' => true, 'posts_per_page' => 21, 'offset' => 20 ) ),
			'reference_pattern_counts' => array(), 'thumbnail_sql' => array(),
		);
	}

	public function test_exact_acceptance_limits_pass(): void {
		wstm121_assert_operation( $this->record() );
		$this->addToAssertionCount( 1 );
	}

	public function test_public_window_response_never_exposes_internal_candidate_ids_or_totals(): void {
		$data = array( 'items' => array(), 'page' => 2, 'per_page' => 100, 'next_page' => 3 );
		wstm121_assert_page_envelope( $data, 2, 100 );
		foreach ( array( 'ids', 'total', 'total_pages' ) as $field ) {
			try {
				wstm121_assert_page_envelope( $data + array( $field => null ), 2, 100 );
				self::fail( "Accepted internal/total field {$field}." );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( $field, $error->getMessage() );
			}
		}
		$data['next_page'] = null;
		wstm121_assert_page_envelope( $data, 2, 100 );
		$this->addToAssertionCount( 1 );
	}

	public function test_php81_memory_bound_never_subtracts_a_historical_peak(): void {
		$start_current = 16 * 1024 * 1024;
		$historical_peak = 96 * 1024 * 1024;
		self::assertSame( 80 * 1024 * 1024, wstm121_peak_bound( $start_current, $historical_peak ) );
		$record = $this->record();
		$record['memory_upper_bound_bytes'] = wstm121_peak_bound( $start_current, $historical_peak );
		$this->expectExceptionMessage( 'Conservative incremental allocator peak exceeds' );
		wstm121_assert_operation( $record );
	}

	public function test_invalid_memory_evidence_fails_closed(): void {
		$this->expectException( RuntimeException::class );
		wstm121_peak_bound( 100, 99 );
	}

	public function test_sparse_oracle_includes_empty_intermediate_windows_then_eligible_rows(): void {
		self::assertTrue( wstm121_allowed( 'sparse', 0 ) );
		foreach ( range( 100, 199 ) as $rank ) {
			self::assertFalse( wstm121_allowed( 'sparse', $rank ) );
		}
		self::assertTrue( wstm121_allowed( 'sparse', 201 ) );
		self::assertFalse( wstm121_allowed( 'all-denied', 0 ) );
		self::assertTrue( wstm121_allowed( 'dense', 19999 ) );
		$record = $this->record();
		$record['item_ids'] = array();
		$record['expected_ids'] = array();
		wstm121_assert_operation( $record );
		$this->addToAssertionCount( 1 );
	}

	public function test_eof_is_distinct_from_empty_nonterminal_window(): void {
		$record = $this->record();
		foreach ( array( 'item_ids', 'expected_ids', 'candidate_ids', 'expected_candidate_ids', 'window_ids', 'checked_ids' ) as $key ) {
			$record[ $key ] = array();
		}
		$record['candidate_count'] = 0;
		$record['next_page'] = null;
		$record['expected_next_page'] = null;
		wstm121_assert_operation( $record );
		$record['next_page'] = 3;
		$this->expectExceptionMessage( 'Incorrect continuation' );
		wstm121_assert_operation( $record );
	}

	public function test_every_budget_is_enforced_without_threshold_weakening(): void {
		$violations = array(
			'candidate IDs' => array( 'candidate_count', 22 ),
			'actual cap calls' => array( 'cap_calls', 111 ),
			'actual SQL' => array( 'sql_count', 73 ),
			'peak' => array( 'memory_upper_bound_bytes', 64 * 1024 * 1024 + 1 ),
			'controlled payload' => array( 'payload_bytes', 64 * 1024 + 1 ),
			'mutations' => array( 'mutations', array( array( 'hook' => 'save_post' ) ) ),
			'lookahead authorization' => array( 'checked_ids', array( 41 ) ),
			'too many authorizations' => array( 'checked_ids', range( 21, 41 ) ),
			'skipped eligible ID' => array( 'item_ids', range( 22, 40 ) ),
			'tie ordering' => array( 'candidate_ids', array_reverse( range( 21, 41 ) ) ),
			'wrong continuation' => array( 'next_page', null ),
			'oversized reference batch' => array( 'reference_pattern_counts', array( 51 ) ),
		);
		foreach ( $violations as $label => $change ) {
			$record = $this->record();
			$record[ $change[0] ] = $change[1];
			try {
				wstm121_assert_operation( $record );
				self::fail( 'Accepted invalid ' . $label );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( '', $error->getMessage(), $label );
			}
		}
	}

	public function test_query_shape_is_enforced(): void {
		foreach ( array( 'fields' => 'all', 'no_found_rows' => false, 'posts_per_page' => -1, 'offset' => 0 ) as $key => $value ) {
			$record = $this->record();
			$record['queries'][0][ $key ] = $value;
			try {
				wstm121_assert_operation( $record );
				self::fail( "Accepted invalid WP_Query {$key}" );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'Candidate WP_Query', $error->getMessage() );
			}
		}
	}

	public function test_orphan_budget_and_batch_shape_are_enforced(): void {
		$record = $this->record();
		$record['orphan'] = true;
		$record['sql_count'] = 73;
		$record['reference_pattern_counts'] = array( 40 );
		$record['thumbnail_sql'] = array( "SELECT meta_value FROM postmeta WHERE meta_key='_thumbnail_id' AND meta_value IN ('1','2')" );
		wstm121_assert_operation( $record );
		foreach ( array(
			array( 'sql_count', 74 ),
			array( 'reference_pattern_counts', array( 20, 20 ) ),
			array( 'thumbnail_sql', array( 'SELECT meta_value FROM postmeta WHERE meta_value=1' ) ),
			array( 'thumbnail_sql', array( 'meta_value IN (1)', 'meta_value IN (2)' ) ),
		) as $change ) {
			$bad = $record;
			$bad[ $change[0] ] = $change[1];
			try {
				wstm121_assert_operation( $bad );
				self::fail( 'Accepted non-batched orphan check.' );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}

	public function test_payload_assertion_is_not_a_universal_content_size_claim(): void {
		$record = $this->record();
		$record['controlled_default_summary'] = false;
		$record['payload_bytes'] = 5 * 1024 * 1024;
		wstm121_assert_operation( $record );
		$this->addToAssertionCount( 1 );
	}

	public function test_runner_has_both_optins_and_does_not_execute_when_included(): void {
		// Loading functions is safe: no WordPress runtime or subprocess is started.
		require_once dirname( __DIR__ ) . '/e2e/bounded-list-benchmark.php';
		self::assertTrue( function_exists( 'wstm121_main' ) );
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/bounded-list-benchmark.php' );
		self::assertStringContainsString( "'1' === getenv( 'WSTM_BOUNDED_BENCHMARK' )", $source );
		self::assertStringContainsString( "true === WSTM_BOUNDED_BENCHMARK", $source );
		self::assertStringContainsString( "'posts' => 20000, 'attachments' => 10000, 'large_posts' => 100", $source );
		self::assertStringNotContainsString( 'memory_reset_peak_usage', $source );
	}
}
