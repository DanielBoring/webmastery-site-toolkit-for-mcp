<?php
/**
 * Pure assertions shared by the opt-in #121 benchmark and its unit tests.
 */

declare(strict_types=1);

function wstm121_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wstm121_allowed( string $mode, int $rank ): bool {
	return 'dense' === $mode || ( 'sparse' === $mode && 0 === intdiv( $rank, 100 ) % 2 && 0 === $rank % 3 );
}

function wstm121_large_content(): string {
	$prefix = "<!-- wp:paragraph -->\n<p>\"Quoted\" \\\\server\\share\\file; <strong>stored &amp; exact</strong>.</p>\n<!-- /wp:paragraph -->\n";
	return str_pad( $prefix, 55 * 1024, 'x' );
}

/**
 * On PHP 8.1 an old process peak cannot be reset. Subtract CURRENT start usage,
 * never the historical start peak: the result is a conservative upper bound,
 * potentially including a bootstrap/warmup peak. A pass proves the bound; a
 * failure may be inconclusive about the operation, but NEVER becomes a pass.
 * Both values use memory_get_* (true): PHP allocator memory, not process RSS.
 */
function wstm121_peak_bound( int $start_usage, int $end_peak ): int {
	wstm121_require( $end_peak >= $start_usage, 'Peak is below start usage; invalid memory evidence.' );
	return $end_peak - $start_usage;
}

function wstm121_assert_page_envelope( array $data, int $page, int $per_page ): void {
	wstm121_require( is_array( $data['items'] ?? null ), 'Missing data.items.' );
	wstm121_require( $page === ( $data['page'] ?? null ) && $per_page === ( $data['per_page'] ?? null ), 'Missing/incorrect integer page/per_page.' );
	wstm121_require( array_key_exists( 'next_page', $data ) && ( null === $data['next_page'] || is_int( $data['next_page'] ) ), 'Missing/invalid next_page.' );
	foreach ( array( 'ids', 'total', 'total_pages' ) as $private_field ) {
		wstm121_require( ! array_key_exists( $private_field, $data ), "Public list response must not expose {$private_field}." );
	}
}

function wstm121_assert_operation( array $record ): void {
	$p = $record['per_page'];
	wstm121_require( is_int( $p ) && $p >= 1 && $p <= 100, 'Invalid measured page size.' );
	wstm121_require( $record['candidate_count'] <= $p + 1, 'Candidate IDs exceed P+1.' );
	wstm121_require( count( $record['checked_ids'] ) <= $p, 'Distinct object authorization candidates exceed P.' );
	wstm121_require( array() === array_diff( $record['checked_ids'], $record['window_ids'] ), 'Lookahead or non-window object was authorized.' );
	wstm121_require( $record['cap_calls'] <= 5 * $p + 10, 'Actual user_has_cap calls exceed 5P+10.' );
	$limit = 3 * $p + 12 + ( $record['orphan'] ? (int) ceil( 2 * $p / 50 ) : 0 );
	wstm121_require( $record['sql_count'] <= $limit, 'Actual operation SQL exceeds its fixed budget.' );
	wstm121_require( array() === $record['mutations'], 'Operation reached mutation SQL/hooks.' );
	wstm121_require( $record['memory_upper_bound_bytes'] <= 64 * 1024 * 1024, 'Conservative incremental allocator peak exceeds 64 MiB (not accepted).' );
	if ( $record['controlled_default_summary'] ) {
		wstm121_require( $record['payload_bytes'] <= 64 * 1024, 'Controlled default-20 summary JSON exceeds 64 KiB.' );
	}
	wstm121_require( $record['item_ids'] === $record['expected_ids'], 'Output IDs differ from independently computed candidate-window eligibility/order.' );
	wstm121_require( count( $record['item_ids'] ) === count( array_unique( $record['item_ids'] ) ), 'Duplicate output ID.' );
	wstm121_require( $record['next_page'] === $record['expected_next_page'], 'Incorrect continuation, including empty windows or EOF.' );
	foreach ( $record['queries'] as $query ) {
		wstm121_require( 'ids' === $query['fields'], 'Candidate WP_Query did not request fields=ids.' );
		wstm121_require( true === $query['no_found_rows'], 'Candidate WP_Query did not disable found rows.' );
		wstm121_require( $p + 1 === $query['posts_per_page'], 'Candidate WP_Query did not request P+1.' );
		wstm121_require( ( $record['page'] - 1 ) * $p === $query['offset'], 'Candidate WP_Query offset is not (page-1)*P.' );
	}
	wstm121_require( 1 === count( $record['queries'] ), 'Expected exactly one candidate WP_Query.' );
	wstm121_require( $record['candidate_ids'] === $record['expected_candidate_ids'], 'Candidate order/window differs from immutable fixture oracle.' );
	foreach ( $record['reference_pattern_counts'] as $count ) {
		wstm121_require( $count <= 50, 'Reference content query exceeds 50 URL/GUID patterns.' );
	}
	if ( $record['orphan'] ) {
		wstm121_require( count( $record['reference_pattern_counts'] ) <= (int) ceil( 2 * $p / 50 ), 'Reference content checks were not batched.' );
		wstm121_require( count( $record['thumbnail_sql'] ) <= 1, 'Thumbnail references were not batched.' );
		foreach ( $record['thumbnail_sql'] as $sql ) {
			wstm121_require( 1 === preg_match( '/\\bmeta_value\\s+IN\\s*\\(/i', $sql ), 'Thumbnail reference query must use an ID IN batch.' );
		}
	}
}
