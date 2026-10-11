<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/bounded-list-verifier.php';

require_once __DIR__ . '/fixtures/bounded-product-record-model.php';

final class BoundedVerifierTest extends TestCase {
	private function evidence(): array {
		$state = array( 'cpt' => 'w121_0123456789ab', 'catalog' => array( 'post' => range( 10, 1 ) ), 'referenced' => array() );
		$job = array( 'type' => 'post', 'ability' => 'list-posts', 'mode' => 'dense', 'input' => array(), 'page' => 1, 'per_page' => 1, 'orphan' => false, 'controlled_default_summary' => false );
		$response = array( 'success' => true, 'data' => array( 'items' => array( array( 'id' => 10, 'excerpt' => 'Controlled unchanged excerpt.', 'untrusted_fields' => array( 'excerpt' ) ) ), 'page' => 1, 'per_page' => 1, 'next_page' => 2 ) );
		$record = array(
			'job' => $job, 'response' => $response, 'page' => 1, 'per_page' => 1, 'orphan' => false, 'controlled_default_summary' => false,
			'operation_start' => array( 'time_ns' => 10, 'wpdb_num_queries' => 5, 'current_allocated_bytes' => 1048576, 'historical_peak_bytes' => 2097152 ),
			'operation_end' => array( 'time_ns' => 20, 'wpdb_num_queries' => 5, 'current_allocated_bytes' => 1048576, 'peak_allocated_bytes' => 2097152 ),
			'sql' => array(), 'raw_cap_calls' => array( array( 'requested' => array( 'read_post', 42, 10 ), 'mapped' => array( 'read' ) ) ), 'mutations' => array(),
			'queries' => array( array( 'returned_ids' => array( 10, 9 ), 'fields' => 'ids', 'no_found_rows' => true, 'posts_per_page' => 2, 'offset' => 0, 'order' => 'DESC', 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ) ) ),
			'expected_ids' => array( 10 ), 'window_ids' => array( 10 ), 'expected_candidate_ids' => array( 10, 9 ), 'expected_next_page' => 2,
			'item_ids' => array( 10 ), 'next_page' => 2, 'cap_calls' => 1, 'checked_ids' => array( 10 ),
			'candidate_ids' => array( 10, 9 ), 'candidate_count' => 2, 'sql_count' => 0,
			'memory_start_current_bytes' => 1048576, 'memory_upper_bound_bytes' => 1048576,
			'payload_bytes' => strlen( json_encode( $response ) ), 'reference_pattern_counts' => array(), 'thumbnail_sql' => array(),
			'assertions_passed' => true,
		);
		return array( $state, $job, $record );
	}

	public function test_original_observations_are_recomputed_not_trusted_as_pass_booleans(): void {
		list( $state, $job, $record ) = $this->evidence();
		$record['assertions_passed'] = false;
		$result = Wstm121Verifier::operation( $state, $job, $record );
		self::assertSame( array( 10 ), $result['item_ids'] );
		self::assertSame( 1, $result['cap_calls'] );
		self::assertSame( 1048576, $result['memory_upper_bound_bytes'] );
	}

	private function score_evidence( ?int $score = 73 ): array {
		list( $state, $job, $record ) = $this->evidence();
		$state['small_catalog'] = true;
		$job['ability'] = 'get-seo-scores';
		$job['input'] = array( 'page' => 1, 'per_page' => 1, 'post_type' => 'post' );
		$job['coverage_key'] = 'small:seo:dense:1:1:summary';
		$record['job'] = $job;
		$record['response']['data']['items'] = array( array(
			'post_id' => 10, 'title' => 'Stored \\title "value"', 'url' => 'http://localhost/owned/10',
			'post_type' => 'post', 'modified_gmt' => '2026-10-09 00:00:00', 'score' => $score,
			'untrusted_fields' => array( 'title', 'url', 'score' ),
		) );
		$record['payload_bytes'] = strlen( json_encode( $record['response'] ) );
		return array( $state, $job, $record );
	}

	public function test_actual_score_case_preserves_authorized_values_and_record_relative_markers(): void {
		foreach ( array( null, 0, 73 ) as $score ) {
			list( $state, $job, $record ) = $this->score_evidence( $score );
			$original = $record;
			self::assertSame( array( 10 ), Wstm121Verifier::operation( $state, $job, $record )['item_ids'] );
			self::assertSame( $original, $record );
			self::assertSame( $score, $record['response']['data']['items'][0]['score'] );
			self::assertSame( array( 'title', 'url', 'score' ), $record['response']['data']['items'][0]['untrusted_fields'] );
		}
	}

	public static function score_marker_mutations(): array {
		return array_map( static fn( $kind ) => array( $kind ), array(
			'missing', 'extra', 'wrong-type', 'associative', 'duplicate', 'absent-key', 'other-record', 'envelope',
		) );
	}

	/** @dataProvider score_marker_mutations */
	public function test_score_case_refuses_missing_extra_typed_or_cross_record_markers( string $kind ): void {
		list( $state, $job, $record ) = $this->score_evidence();
		$item = &$record['response']['data']['items'][0];
		switch ( $kind ) {
			case 'missing': $item['untrusted_fields'] = array( 'title', 'url' ); break;
			case 'extra': $item['untrusted_fields'][] = 'slug'; break;
			case 'wrong-type': $item['untrusted_fields'][2] = 73; break;
			case 'associative': $item['untrusted_fields'] = array( 'title' => 'title', 'url' => 'url', 'score' => 'score' ); break;
			case 'duplicate': $item['untrusted_fields'][] = 'score'; break;
			case 'absent-key': unset( $item['score'] ); break;
			case 'other-record':
				$record['response']['data']['other_record'] = array( 'untrusted_fields' => $item['untrusted_fields'] );
				unset( $item['untrusted_fields'] );
				break;
			case 'envelope':
				$record['response']['data']['untrusted_fields'] = $item['untrusted_fields'];
				unset( $item['untrusted_fields'] );
				break;
		}
		$record['payload_bytes'] = strlen( json_encode( $record['response'] ) );
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::operation( $state, $job, $record );
	}

	public function testSmallSparseIntermediateWindowIsEmptyWithoutLosingContinuation(): void {
		list( $state, $job, $record ) = $this->evidence();
		$state['small_catalog'] = true;
		$state['catalog']['post'] = range( 43, 1 );
		$job = array_replace( $job, array( 'mode' => 'sparse', 'page' => 2, 'per_page' => 20 ) );
		$candidates = array_slice( $state['catalog']['post'], 20, 21 );
		$response = array( 'success' => true, 'data' => array( 'items' => array(), 'page' => 2, 'per_page' => 20, 'next_page' => 3 ) );
		$record = array_replace( $record, array(
			'job' => $job, 'response' => $response, 'page' => 2, 'per_page' => 20,
			'expected_ids' => array(), 'item_ids' => array(), 'next_page' => 3, 'expected_next_page' => 3,
			'window_ids' => array_slice( $candidates, 0, 20 ), 'expected_candidate_ids' => $candidates,
			'candidate_ids' => $candidates, 'candidate_count' => 21, 'raw_cap_calls' => array(),
			'checked_ids' => array(), 'cap_calls' => 0, 'payload_bytes' => strlen( json_encode( $response ) ),
		) );
		$record['queries'][0] = array_replace( $record['queries'][0], array( 'returned_ids' => $candidates, 'posts_per_page' => 21, 'offset' => 20 ) );
		self::assertSame( array(), Wstm121Verifier::operation( $state, $job, $record )['item_ids'] );
		self::assertSame( 3, $record['next_page'] );
		$record['response']['data']['next_page'] = null;
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::operation( $state, $job, $record );
	}

	public static function mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'job', 'counter', 'raw-cap', 'raw-sql', 'raw-response', 'raw-candidate', 'memory', 'clock', 'summary', 'tie', 'mutations' ) );
	}

	/** @dataProvider mutations */
	public function test_tampering_cannot_be_hidden_by_a_success_flag( string $mutation ): void {
		list( $state, $job, $record ) = $this->evidence();
		switch ( $mutation ) {
			case 'job': $record['job']['mode'] = 'all-denied'; break;
			case 'counter': $record['operation_start']['wpdb_num_queries'] = 5.0; break;
			case 'raw-cap': $record['raw_cap_calls'][0]['requested'][2] = 9; break;
			case 'raw-sql': $record['sql'][] = 'DELETE FROM posts'; break;
			case 'raw-response': $record['response']['data']['items'][0]['id'] = 9; break;
			case 'raw-candidate': $record['queries'][0]['returned_ids'] = array( 9, 10 ); break;
			case 'memory': $record['operation_end']['peak_allocated_bytes'] = 1048576; break;
			case 'clock': $record['operation_end']['time_ns'] = 9; break;
			case 'summary': $record['response']['data']['items'][0]['content'] = ''; break;
			case 'tie': unset( $record['queries'][0]['orderby']['ID'] ); break;
			case 'mutations': $record['mutations'][] = array( 'hook' => 'save_post' ); break;
		}
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::operation( $state, $job, $record );
	}

	public function test_timeout_incomplete_drain_and_failed_cleanup_receipts_are_not_success(): void {
		$receipt = array( 'start_ns' => 0, 'end_ns' => 1, 'exit_code' => 0, 'exit_observed' => true,
			'stdout_eof' => true, 'stderr_eof' => true, 'truncated' => false, 'failure' => null,
			'secondary_errors' => array(), 'stdout_received' => 0, 'stderr_received' => 0,
			'stdout_diagnostic_bytes' => 0, 'stderr_diagnostic_bytes' => 0, 'stdout_observed_not_retained' => 0, 'stderr_observed_not_retained' => 0,
			'stdout_bytes' => 0, 'stderr_bytes' => 0, 'stdout_sha256' => hash( 'sha256', '' ), 'stderr_sha256' => hash( 'sha256', '' ) );
		Wstm121ProductRecordModel::receipt( $receipt, 60 );
		foreach ( array( 'end_ns' => 60000000001, 'exit_code' => 1, 'exit_observed' => false, 'stdout_eof' => false,
			'stderr_eof' => false, 'truncated' => true, 'failure' => 'Cleanup refused ownership mismatch.', 'stdout_bytes' => -1, 'stderr_bytes' => 0.0,
			'secondary_errors' => array( 'flush failed' ), 'stdout_received' => 1, 'stderr_observed_not_retained' => 1 ) as $key => $value ) {
			try {
				Wstm121ProductRecordModel::receipt( array_replace( $receipt, array( $key => $value ) ), 60 );
				self::fail( 'Invalid receipt accepted: ' . $key );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}

	public function test_markers_are_exact_record_relative_present_keys_not_truthy_values(): void {
		$record = array( 'title' => null, 'content' => '', 'excerpt' => false, 'stored' => array( 'untrusted_fields' => 'untouched' ),
			'untrusted_fields' => array( 'excerpt', 'title', 'content' ) );
		Wstm121Verifier::markers( $record, array( 'title', 'content', 'excerpt', 'url' ) );
		self::assertSame( 'untouched', $record['stored']['untrusted_fields'] );
		foreach ( array( array( 'title' ), array( 'title', 'content', 'excerpt', 'url' ), array( 'title', 'content', 'excerpt', 'excerpt' ),
			array( 'title', 'content', 0 ), array( 'named' => 'title' ) ) as $markers ) {
			try {
				Wstm121Verifier::markers( array_replace( $record, array( 'untrusted_fields' => $markers ) ), array( 'title', 'content', 'excerpt', 'url' ) );
				self::fail( 'Incorrect marker set accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
		self::assertSame( 55 * 1024, strlen( wstm121_large_content() ) );
		self::assertStringContainsString( '<!-- wp:paragraph -->', wstm121_large_content() );
		self::assertStringContainsString( '"Quoted" \\\\server\\share\\file', wstm121_large_content() );
	}

	public function test_missing_duplicate_or_reordered_shard_mapping_is_rejected_before_records(): void {
		foreach ( array( array(), array( 'posts' => __DIR__ ), array_fill_keys( array_reverse( Wstm121ProductRecordModel::SURFACES ), __DIR__ ), array( __DIR__, __DIR__ ) ) as $directories ) {
			try {
				Wstm121ProductRecordModel::directories( $directories );
				self::fail( 'Incomplete/reordered shard mapping accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}
}
