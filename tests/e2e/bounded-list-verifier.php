<?php

declare(strict_types=1);

require_once __DIR__ . '/bounded-list-plan.php';

final class Wstm121Verifier {
	private static function read_only_probe( array $record ): void {
		wstm121_require( ! array_key_exists( 'failure', $record ) && isset( $record['before'], $record['after'] )
			&& $record['before'] === $record['after'] && array() === ( $record['mutations'] ?? null ), 'Probe failed or changed persisted state/hooks.' );
		wstm121_require( is_array( $record['sql'] ?? null ), 'Probe SQL observations are missing.' );
		foreach ( $record['sql'] as $sql ) {
			wstm121_require( is_string( $sql ) && 1 === preg_match( '/^\\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\\b/i', $sql ), 'Probe executed non-read-only SQL.' );
		}
	}

	public static function reference_probe( array $state, array $record ): void {
		self::read_only_probe( $record );
		$ids = array_slice( $state['catalog']['attachment'], 0, 100 );
		wstm121_require( $ids === ( $record['ids'] ?? null ), 'Reference parity must cover the complete first 100 attachment candidates.' );
		$expected = array();
		foreach ( $ids as $id ) {
			$expected[ $id ] = in_array( $id, $state['referenced'], true );
		}
		foreach ( array( 'legacy', 'single', 'batch' ) as $path ) {
			wstm121_require( $expected === ( $record[ $path ] ?? null ), 'Independent, single, and batched reference truth must agree exactly.' );
		}
		wstm121_require( array( 'thumbnail', 'content' ) === array_keys( $record['read_failures'] ?? array() ), 'Missing real reference-failure phase.' );
		foreach ( $record['read_failures'] as $phase => $failure ) {
			wstm121_require( 'content_hygiene_query_failed' === ( $failure['helper']['code'] ?? null ), 'Helper did not fail closed.' );
			wstm121_require( array( 'media_id' => $ids[6], 'confirm' => true, 'force' => true ) === ( $failure['forced_input'] ?? null ), 'Forced-deletion input was weakened.' );
			$response = $failure['forced_response'] ?? array();
			wstm121_require( false === ( $response['success'] ?? null ) && 'upstream_failed' === ( $response['error']['code'] ?? null )
				&& 'content_hygiene_query_failed' === ( $response['error']['reason'] ?? null ), 'Force bypassed the database failure.' );
			wstm121_require( is_array( $failure['injected'] ?? null ) && 2 === count( $failure['injected'] ), 'Both helper and force paths must execute the injected failing read.' );
			foreach ( $failure['injected'] as $injection ) {
				wstm121_require( is_string( $injection['original'] ?? null ) && in_array( $injection['original'], $record['sql'], true )
					&& 1 === preg_match( '/\\ASELECT wstm121_deliberate_missing_column FROM [A-Za-z0-9_]+ LIMIT 1\\z/D', $injection['replacement'] ?? '' ), 'Missing original/replacement reference SQL.' );
				wstm121_require( 'thumbnail' === $phase ? str_contains( $injection['original'], '_thumbnail_id' )
					: ( str_contains( $injection['original'], 'post_content' ) && str_contains( strtoupper( $injection['original'] ), 'LIKE' ) ), 'The wrong reference query was faulted.' );
			}
			wstm121_require( ! str_contains( json_encode( $response, JSON_THROW_ON_ERROR ), 'wstm121_deliberate_missing_column' ), 'SQL diagnostics escaped the public forced-deletion response.' );
		}
	}

	public static function cache_probe( array $state, array $record ): void {
		$expected = array_slice( $state['catalog']['post'], 0, 2 );
		wstm121_require( ! array_key_exists( 'failure', $record ) && isset( $record['before'], $record['after'] )
			&& $record['before'] === $record['after'], 'Cache failure/retry probes changed state or failed.' );
		wstm121_require( array( 'page' => 1, 'per_page' => 2, 'orderby' => 'id', 'order' => 'DESC' ) === ( $record['input'] ?? null )
			&& $expected === ( $record['expected_ids'] ?? null ), 'Cache probe window changed.' );
		wstm121_require( array( 'candidate', 'priming' ) === array_keys( $record['phases'] ?? array() ), 'Missing candidate/priming failure proof.' );
		foreach ( $record['phases'] as $name => $phase ) {
			wstm121_require( 1 === ( $phase['injected'] ?? null ) && is_string( $phase['request'] ?? null )
				&& is_array( $phase['sql'] ?? null ) && count( $phase['sql'] ) > 0, 'Original injected-query evidence is missing.' );
			wstm121_require( Wstm121Plan::unsigned( $phase['query_end'] ?? null, 'failed query end' ) > Wstm121Plan::unsigned( $phase['query_start'] ?? null, 'failed query start' )
				&& is_string( $phase['private_database_error'] ?? null ), 'Final query error observation is malformed.' );
			self::failure_witness( $name, $phase );
			wstm121_require( is_string( $phase['generation_before'] ?? null ) && is_string( $phase['generation_after'] ?? null )
				&& $phase['generation_before'] !== $phase['generation_after'], 'Failed cache generation was not invalidated.' );
			$failed = $phase['failed_response'] ?? array();
			wstm121_require( false === ( $failed['success'] ?? null ) && 'upstream_failed' === ( $failed['error']['code'] ?? null )
				&& 'The list query could not be completed.' === ( $failed['error']['message'] ?? null )
				&& ! str_contains( json_encode( $failed, JSON_THROW_ON_ERROR ), 'wstm121_deliberate_missing_column' ), 'Failed query returned EOF or disclosed SQL diagnostics.' );
			foreach ( array( 'retry', 'warm_stale' ) as $name ) {
				$observation = $phase[ $name ] ?? array();
				$start = Wstm121Plan::unsigned( $observation['query_start'] ?? null, $name . ' start' );
				$end = Wstm121Plan::unsigned( $observation['query_end'] ?? null, $name . ' end' );
				wstm121_require( 'retry' === $name ? $end > $start : $end === $start, 'Retry must read SQL; the stale-error warm request must not.' );
				$response = $observation['response'] ?? array();
				wstm121_require( true === ( $response['success'] ?? null ) && $expected === array_column( $response['data']['items'] ?? array(), 'id' )
					&& 2 === ( $response['data']['next_page'] ?? null ), 'Failure recovery changed/skipped the requested window.' );
				wstm121_assert_page_envelope( $response['data'], 1, 2 );
				if ( 'warm_stale' === $name ) {
					wstm121_require( 'wstm121_stale_error_without_new_sql' === ( $observation['last_error'] ?? null ), 'Warm-cache probe did not retain the intentionally stale error.' );
				}

			}
		}
	}

	private static function failure_witness( string $name, array $phase ): void {
		$injection = $phase['injection'] ?? null;
		$witness = $phase['failure_witness'] ?? null;
		wstm121_require( is_array( $injection ) && array( 'original', 'replacement', 'query_before', 'sql_index' ) === array_keys( $injection )
			&& is_array( $witness ) && array( 'sql_index', 'query_before', 'query_completed', 'query_sha256', 'result_failed', 'error_present', 'boundary' ) === array_keys( $witness ),
			'Missing or malformed execution-time failure witness.' );
		$index = Wstm121Plan::unsigned( $injection['sql_index'], 'injected SQL index' );
		$before = Wstm121Plan::unsigned( $injection['query_before'], 'injected query before' );
		$completed = Wstm121Plan::unsigned( $witness['query_completed'], 'injected query completed' );
		wstm121_require( $index < count( $phase['sql'] ) && is_string( $injection['original'] )
			&& $phase['sql'][ $index ] === $injection['original'] && is_string( $injection['replacement'] )
			&& 1 === preg_match( '/^SELECT wstm121_deliberate_missing_column FROM ([a-zA-Z0-9_]+) LIMIT 1$/D', $injection['replacement'], $table ),
			'Injected-query bytes or SQL association changed.' );
		$prime = '~^\\s*SELECT\\s+(?:' . preg_quote( $table[1], '~' ) . '\\.)?\\*\\s+FROM\\s+' . preg_quote( $table[1], '~' )
			. '\\s+WHERE\\s+(?:' . preg_quote( $table[1], '~' ) . '\\.)?ID\\s+IN\\s*\\(~i';
		wstm121_require( 1 === preg_match( '~\\bFROM\\s+' . preg_quote( $table[1], '~' ) . '\\b~i', $injection['original'] )
			&& ( 'candidate' === $name ? $injection['original'] === $phase['request']
				: 1 === preg_match( $prime, $injection['original'] ) ), 'Injected query does not belong to the intended phase.' );
		wstm121_require( $before < PHP_INT_MAX && $before >= $phase['query_start'] && $before - $phase['query_start'] === $index
			&& $completed === $before + 1
			&& $completed <= $phase['query_end'] && $witness['query_before'] === $before && $witness['sql_index'] === $index
			&& is_string( $witness['query_sha256'] ) && hash( 'sha256', $injection['replacement'] ) === $witness['query_sha256']
			&& true === $witness['result_failed'] && true === $witness['error_present']
			&& ( 'final' === $witness['boundary'] ? $completed === $phase['query_end']
				: 'before-next-query' === $witness['boundary'] && $completed < $phase['query_end'] ),
			'Failure did not execute the exact injected SQL at the retained boundary.' );
	}

	public static function projection_probe( array $state, array $record ): void {
		self::read_only_probe( $record );
		$keys = array( 'get-post', 'get-page', 'get-cpt-' . str_replace( '_', '-', $state['cpt'] ),
			'revisions-default', 'revisions-summary', 'revisions-full' );
		wstm121_require( $keys === array_keys( $record['responses'] ?? array() ), 'Missing or unexpected projection probe.' );
		foreach ( $record['responses'] as $name => $response ) {
			wstm121_require( true === ( $response['success'] ?? null ) && is_array( $response['data'] ?? null ), 'Projection response failed.' );
			if ( str_starts_with( $name, 'get-' ) ) {
				$data = $response['data'];
				self::markers( $data, array( 'title', 'content', 'excerpt', 'slug', 'url', 'author_name' ) );
				wstm121_require( array_key_exists( 'content', $data ) && $data['content'] === ( $record['stored_content'][ $name ] ?? null ), 'Get changed stored content bytes/types.' );
			} else {
				$revisions = $response['data']['revisions'] ?? null;
				wstm121_require( is_array( $revisions ) && 1 === count( $revisions ) && isset( $revisions[0] )
					&& $state['revision'] === ( $revisions[0]['id'] ?? null ), 'Revision identity/shape changed.' );
				$data = $revisions[0];
				self::markers( $data, array( 'author_name', 'title', 'content', 'excerpt' ) );
				wstm121_require( $state['catalog']['post'][0] === ( $response['data']['post_id'] ?? null )
					&& 'post' === ( $response['data']['type'] ?? null ), 'Revision parent/type envelope changed.' );
				wstm121_require( ( 'revisions-full' === $name ) === array_key_exists( 'content', $data ), 'Revision projection changed.' );
				if ( 'revisions-full' === $name ) {
					wstm121_require( $data['content'] === ( $record['stored_content']['revision'] ?? null ), 'Revision content bytes/types changed.' );
				}
			}
			wstm121_require( 'Controlled unchanged excerpt.' === ( $data['excerpt'] ?? null ), 'Projection changed stored excerpt.' );
		}
	}

	public static function markers( array $record, array $allowlist ): void {
		wstm121_require( isset( $record['untrusted_fields'] ) && is_array( $record['untrusted_fields'] )
			&& array_values( $record['untrusted_fields'] ) === $record['untrusted_fields'], 'Missing record-relative marker JSON array.' );
		$actual = $record['untrusted_fields'];
		foreach ( $actual as $field ) {
			wstm121_require( is_string( $field ), 'Marker names must be strings.' );
		}
		wstm121_require( count( $actual ) === count( array_unique( $actual ) ), 'Duplicate record marker.' );
		$expected = array_values( array_filter( $allowlist, static fn( $field ) => array_key_exists( $field, $record ) ) );
		sort( $actual );
		sort( $expected );
		wstm121_require( $expected === $actual, 'Markers must name exactly the retained allowlisted record fields.' );
	}

	public static function operation( array $state, array $job, array $record ): array {
		wstm121_require( ( $record['job'] ?? null ) === $job, 'Original operation belongs to a different logical case.' );
		$catalog = $state['catalog'][ $job['type'] ];
		$ranks = array_flip( $catalog );
		if ( 'ASC' === ( $job['input']['order'] ?? 'DESC' ) ) {
			$catalog = array_reverse( $catalog );
		}
		$p = $job['per_page'];
		$candidates = array_slice( $catalog, ( $job['page'] - 1 ) * $p, $p + 1 );
		$window = array_slice( $candidates, 0, $p );
		$allowed = static function ( int $rank ) use ( $state, $job ): bool {
			if ( true === ( $state['small_catalog'] ?? false ) ) {
				return 'dense' === $job['mode'] || ( 'sparse' === $job['mode'] && 0 === intdiv( $rank, 20 ) % 2 && 0 === $rank % 3 );
			}
			return wstm121_allowed( $job['mode'], $rank );
		};
		$expected = array_values( array_filter( $window, static fn( $id ) => $allowed( $ranks[ $id ] )
			&& ( ! $job['orphan'] || ! in_array( $id, $state['referenced'], true ) ) ) );
		$response = $record['response'] ?? null;
		wstm121_require( is_array( $response ) && true === ( $response['success'] ?? null ) && is_array( $response['data'] ?? null ), 'Original response is not a successful list result.' );
		$data = $response['data'];
		wstm121_assert_page_envelope( $data, $job['page'], $p );
		$ids = array();
		foreach ( $data['items'] as $item ) {
			wstm121_require( is_array( $item ), 'Invalid original list item.' );
			$ids[] = Wstm121Plan::unsigned( $item['id'] ?? $item['post_id'] ?? null, 'response item ID' );
			if ( 'get-seo-scores' === $job['ability'] ) {
				self::markers( $item, array( 'title', 'url', 'score' ) );
			} elseif ( 'get-readability-scores' === $job['ability'] ) {
				self::markers( $item, array( 'title', 'url', 'score' ) );
			} elseif ( 'list-orphaned-media' === $job['ability'] ) {
				self::markers( $item, array( 'title', 'url' ) );
			} elseif ( 'list-media' === $job['ability'] ) {
				self::markers( $item, array( 'title', 'caption', 'alt_text', 'url', 'filename' ) );
			}
			if ( in_array( $job['type'], array( 'post', 'page', $state['cpt'] ), true ) && ! str_contains( $job['ability'], 'scores' ) ) {
				self::markers( $item, array( 'title', 'content', 'excerpt', 'slug', 'url', 'author_name' ) );
				wstm121_require( ( 'full' === ( $job['input']['fields'] ?? 'summary' ) ) === array_key_exists( 'content', $item ), 'Original content projection is wrong.' );
				wstm121_require( 'Controlled unchanged excerpt.' === ( $item['excerpt'] ?? null ), 'Original excerpt changed.' );
				if ( array_key_exists( 'content', $item ) ) {
					$content = true !== ( $state['small_catalog'] ?? false ) && 'post' === $job['type'] && $ranks[ end( $ids ) ] < 100 ? wstm121_large_content() : 'Controlled short body.';
					wstm121_require( $content === $item['content'], 'Full projection changed the controlled stored content bytes.' );
				} elseif ( isset( $item['untrusted_fields'] ) ) {
					wstm121_require( is_array( $item['untrusted_fields'] ) && ! in_array( 'content', $item['untrusted_fields'], true ), 'Summary marked omitted content.' );
				}
			}
		}
		$start = $record['operation_start'] ?? array();
		$end = $record['operation_end'] ?? array();
		foreach ( array( $start, $end ) as $marker ) {
			Wstm121Plan::unsigned( $marker['time_ns'] ?? null, 'operation clock' );
			Wstm121Plan::unsigned( $marker['wpdb_num_queries'] ?? null, 'wpdb query counter' );
			Wstm121Plan::unsigned( $marker['current_allocated_bytes'] ?? null, 'current allocated bytes' );
		}
		wstm121_require( $end['time_ns'] >= $start['time_ns'] && $end['wpdb_num_queries'] >= $start['wpdb_num_queries'], 'Operation clocks or SQL counters moved backwards.' );
		Wstm121Plan::unsigned( $start['historical_peak_bytes'] ?? null, 'historical peak' );
		$peak = Wstm121Plan::unsigned( $end['peak_allocated_bytes'] ?? null, 'ending peak' );
		wstm121_require( $start['historical_peak_bytes'] >= $start['current_allocated_bytes']
			&& $peak >= $start['historical_peak_bytes'] && $peak >= $end['current_allocated_bytes'], 'Allocator peak evidence is inconsistent or was reset.' );
		foreach ( array( 'sql', 'raw_cap_calls', 'queries', 'mutations' ) as $field ) {
			wstm121_require( is_array( $record[ $field ] ?? null ), 'Missing original observation: ' . $field );
		}
		$checked = array();
		foreach ( $record['raw_cap_calls'] as $call ) {
			wstm121_require( is_array( $call['requested'] ?? null ) && is_array( $call['mapped'] ?? null ), 'Malformed capability observation.' );
			$args = $call['requested'];
			if ( in_array( $args[0] ?? null, array( 'edit_post', 'read_post', 'edit_post_meta', 'read_post_meta' ), true ) ) {
				$id = Wstm121Plan::unsigned( $args[2] ?? null, 'capability object ID' );
				$checked[ $id ] = $id;
			}
		}
		$patterns = array();
		$thumbnails = array();
		foreach ( $record['sql'] as $sql ) {
			wstm121_require( is_string( $sql ) && 1 === preg_match( '/^\\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\\b/i', $sql ), 'Original SQL is not read-only.' );
			if ( str_contains( $sql, 'post_content' ) && preg_match( '/\\bLIKE\\b/i', $sql ) ) {
				$patterns[] = preg_match_all( '/\\bLIKE\\b/i', $sql );
			}
			if ( str_contains( $sql, '_thumbnail_id' ) && str_contains( $sql, 'meta_value' ) ) {
				$thumbnails[] = $sql;
			}
		}
		$observed_ids = array();
		foreach ( $record['queries'] as $query ) {
			wstm121_require( is_array( $query['returned_ids'] ?? null ) && is_array( $query['orderby'] ?? null ), 'Missing original query/order observations.' );
			foreach ( $query['returned_ids'] as $id ) {
				$observed_ids[] = Wstm121Plan::unsigned( $id, 'candidate ID' );
			}
			wstm121_require( ( $query['orderby']['ID'] ?? null ) === ( $query['order'] ?? null ), 'Deterministic same-direction ID tie is absent.' );
		}
		$derived = array(
			'page' => $job['page'], 'per_page' => $p, 'orphan' => $job['orphan'],
			'controlled_default_summary' => $job['controlled_default_summary'],
			'expected_ids' => $expected, 'window_ids' => $window, 'expected_candidate_ids' => $candidates,
			'expected_next_page' => count( $candidates ) > $p ? $job['page'] + 1 : null,
			'item_ids' => $ids, 'next_page' => $data['next_page'],
			'cap_calls' => count( $record['raw_cap_calls'] ), 'checked_ids' => array_values( $checked ),
			'candidate_ids' => $observed_ids, 'candidate_count' => count( $observed_ids ),
			'sql_count' => $end['wpdb_num_queries'] - $start['wpdb_num_queries'],
			'memory_start_current_bytes' => $start['current_allocated_bytes'],
			'memory_upper_bound_bytes' => wstm121_peak_bound( $start['current_allocated_bytes'], $peak ),
			'payload_bytes' => strlen( json_encode( $response, JSON_THROW_ON_ERROR ) ),
			'reference_pattern_counts' => $patterns, 'thumbnail_sql' => $thumbnails,
		);
		foreach ( $derived as $field => $value ) {
			wstm121_require( array_key_exists( $field, $record ) && $value === $record[ $field ], 'Summary does not match original evidence: ' . $field );
		}
		wstm121_require( count( $record['sql'] ) === $derived['sql_count'], 'Raw SQL stream and operation counters disagree.' );
		wstm121_assert_operation( array_merge( $record, $derived ) );
		return $derived;
	}

}
