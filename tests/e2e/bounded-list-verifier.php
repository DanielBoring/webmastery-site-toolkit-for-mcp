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
		foreach ( $record['phases'] as $phase ) {
			wstm121_require( 1 === ( $phase['injected'] ?? null ) && is_string( $phase['request'] ?? null )
				&& is_array( $phase['sql'] ?? null ) && count( $phase['sql'] ) > 0, 'Original injected-query evidence is missing.' );
			wstm121_require( Wstm121Plan::unsigned( $phase['query_end'] ?? null, 'failed query end' ) > Wstm121Plan::unsigned( $phase['query_start'] ?? null, 'failed query start' )
				&& is_string( $phase['private_database_error'] ?? null ) && '' !== $phase['private_database_error'], 'Failure did not execute actual failing SQL.' );
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

	private static function original_operation( string $path ): array {
		$record = Wstm121Plan::original( $path );
		$typed = json_decode( (string) file_get_contents( $path ), false, 512, JSON_THROW_ON_ERROR );
		$items = $typed->response->data->items ?? null;
		wstm121_require( is_array( $items ), 'Original items must be a JSON array, not an object.' );
		foreach ( $items as $item ) {
			wstm121_require( is_object( $item ) && is_array( $item->untrusted_fields ?? null ), 'Original record marker must be a JSON array, including when empty.' );
		}
		return $record;
	}

	private static function original_projection( string $path ): array {
		$record = Wstm121Plan::original( $path );
		$typed = json_decode( (string) file_get_contents( $path ), false, 512, JSON_THROW_ON_ERROR );
		wstm121_require( is_object( $typed->responses ?? null ), 'Projection responses must be a JSON object.' );
		foreach ( $typed->responses as $name => $response ) {
			$data = $response->data ?? null;
			wstm121_require( is_object( $data ), 'Projection data must be a JSON object.' );
			if ( str_starts_with( $name, 'revisions-' ) ) {
				wstm121_require( is_array( $data->revisions ?? null ), 'Original revisions must be a JSON array.' );
				$records = $data->revisions;
			} else {
				$records = array( $data );
			}
			foreach ( $records as $item ) {
				wstm121_require( is_object( $item ) && is_array( $item->untrusted_fields ?? null ), 'Original projection markers must be JSON arrays.' );
			}
		}
		return $record;
	}

	public static function storage( string $directory, array $execution ): array {
		$control = $directory . '.controller';
		$reported = $execution['namespace_storage'] ?? array();
		$retained = 0;
		$controller = 0;
		$php_retained = 0;
		$control_names = array( 'job.json', 'terminal.json', 'receipt.json', 'stdout.log', 'stderr.log', 'stdout.overflow.bin', 'stderr.overflow.bin' );
		foreach ( array( 'data' => $directory, 'control' => $control ) as $area => $root ) {
			wstm121_require( is_dir( $root ) && ! is_link( $root ), 'Evidence root is missing or linked.' );
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $iterator as $file ) {
				wstm121_require( ! $file->isLink(), 'Linked evidence entry is not owned.' );
				if ( $file->isDir() ) {
					continue;
				}
				wstm121_require( $file->isFile(), 'Non-file evidence entry cannot be accounted for.' );
				$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
				$bytes = Wstm121Plan::unsigned( $file->getSize(), 'retained file bytes' );
				$retained += $bytes;
				if ( 'control' === $area ) {
					if ( 'php-write-bytes.log' !== $relative ) {
						$controller += $bytes;
					}
				} elseif ( 'execution.json' === $relative || ( preg_match( '~\\Aoperation-[1-9][0-9]*/~', $relative ) && in_array( basename( $relative ), $control_names, true ) ) ) {
					$controller += $bytes;
				} else {
					wstm121_require( str_ends_with( $relative, '.json' ) || in_array( $relative, array( 'run.lock', 'workers.lock' ), true ), 'Unexpected/unpublished evidence file.' );
					$php_retained += $bytes;
				}
			}
		}
		$journal_path = $control . '/php-write-bytes.log';
		wstm121_require( is_file( $journal_path ) && ! is_link( $journal_path ) && filesize( $journal_path ) <= 16 * 1024 * 1024, 'Missing/oversized original PHP reservation journal.' );
		$journal = (string) file_get_contents( $journal_path );
		wstm121_require( '' !== $journal && str_ends_with( $journal, "\n" ), 'Incomplete PHP reservation journal.' );
		$reserved = 0;
		foreach ( explode( "\n", substr( $journal, 0, -1 ) ) as $line ) {
			wstm121_require( 1 === preg_match( '/\\A[1-9][0-9]{0,7}\\z/D', $line ) && (int) $line <= Wstm121Plan::JSON_BYTES, 'Malformed PHP byte reservation.' );
			$reserved += (int) $line;
			wstm121_require( $reserved <= Wstm121Plan::NAMESPACE_BYTES, 'Cumulative PHP reservations exceed the namespace bound.' );
		}
		$final_bytes = filesize( $control . '/outcome.json' ) + filesize( $directory . '/execution.json' );
		$custody_bytes = filesize( $control . '/custody.json' );
		$all_final_bytes = $final_bytes + $custody_bytes;
		wstm121_require( 34 * 1024 * 1024 === ( $reported['final_record_reserve_bytes'] ?? null )
			&& $all_final_bytes <= $reported['final_record_reserve_bytes'], 'Final records were not reserved.' );
		wstm121_require( $reserved >= $php_retained && $reserved === ( $reported['php_reserved_bytes'] ?? null )
			&& strlen( $journal ) === ( $reported['php_journal_bytes'] ?? null ), 'PHP retained/reserved bytes disagree.' );
		wstm121_require( $controller - $all_final_bytes === ( $reported['controller_written_bytes'] ?? null )
			&& $retained - $all_final_bytes === ( $reported['retained_bytes'] ?? null ), 'Original retained/controller bytes disagree with the prepublication counters.' );
		$cumulative = $reserved + strlen( $journal ) + $controller;
		wstm121_require( $cumulative - $all_final_bytes === ( $reported['cumulative_write_bytes'] ?? null )
			&& $cumulative <= Wstm121Plan::NAMESPACE_BYTES && $retained <= Wstm121Plan::NAMESPACE_BYTES, 'Original namespace write/retention bound failed.' );
		$whole_job = Wstm121Plan::unsigned( $reported['whole_job_observed_bytes'] ?? null, 'whole-job bytes' );
		$whole_limit = Wstm121Plan::unsigned( $reported['whole_job_limit_bytes'] ?? null, 'whole-job limit' );
		wstm121_require( $whole_job + $reported['final_record_reserve_bytes'] <= $whole_limit, 'Whole-job reserve was exhausted.' );
		return array( 'retained_bytes' => $retained, 'cumulative_reserved_and_written_bytes' => $cumulative, 'final_record_bytes' => $all_final_bytes );
	}

	public static function custody( string $directory, string $expected ): array {
		Wstm121Plan::digest( $expected );
		$path = $directory . '.controller/custody.json';
		$record = Wstm121Plan::original( $path );
		wstm121_require( hash_file( 'sha256', $path ) === $expected, 'Custody original does not match its externally pinned identity.' );
		$files = $record['files'] ?? null;
		wstm121_require( is_array( $files ) && count( $files ) > 0, 'Custody contains no original files.' );
		$observed = array();
		foreach ( array( 'data' => $directory, 'control' => $directory . '.controller' ) as $area => $root ) {
			wstm121_require( is_dir( $root ) && ! is_link( $root ), 'Missing/linked custody root.' );
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $iterator as $file ) {
				wstm121_require( ! $file->isLink(), 'Linked custody artifact.' );
				if ( $file->isDir() ) {
					continue;
				}
				wstm121_require( $file->isFile(), 'Unsupported custody artifact kind.' );
				$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
				if ( 'control' === $area && 'custody.json' === $relative ) {
					continue;
				}
				$observed[ $area . '/' . $relative ] = array( 'bytes' => $file->getSize(), 'sha256' => hash_file( 'sha256', $file->getPathname() ) );
			}
		}
		wstm121_require( count( $files ) === count( $observed ), 'Original artifact inventory changed.' );
		$seen = array();
		foreach ( $files as $file ) {
			wstm121_require( in_array( $file['area'] ?? null, array( 'data', 'control' ), true )
				&& is_string( $file['path'] ?? null ) && ! str_contains( $file['path'], '\\' )
				&& 1 === preg_match( '~\\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\\z~D', $file['path'] )
				&& ! in_array( '..', explode( '/', $file['path'] ), true ), 'Invalid custody relative path.' );
			$key = $file['area'] . '/' . $file['path'];
			wstm121_require( ! isset( $seen[ $key ] ) && isset( $observed[ $key ] ), 'Duplicate/missing custody artifact.' );
			$seen[ $key ] = true;
			wstm121_require( array( 'bytes' => Wstm121Plan::unsigned( $file['bytes'] ?? null, 'custody bytes' ),
				'sha256' => Wstm121Plan::digest( $file['sha256'] ?? null ) ) === $observed[ $key ], 'Original artifact bytes changed.' );
		}
		return $record;
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
		$expected = array_values( array_filter( $window, static fn( $id ) => wstm121_allowed( $job['mode'], $ranks[ $id ] )
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
				self::markers( $item, array( 'title', 'url', 'slug', 'yoast_meta_description', 'seopress_meta_description', 'yoast_focus_keyword', 'seopress_focus_keywords' ) );
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
					$content = 'post' === $job['type'] && $ranks[ end( $ids ) ] < 100 ? wstm121_large_content() : 'Controlled short body.';
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

	public static function receipt( array $receipt, int $seconds ): void {
		$start = Wstm121Plan::unsigned( $receipt['start_ns'] ?? null, 'process start' );
		$end = Wstm121Plan::unsigned( $receipt['end_ns'] ?? null, 'process end' );
		wstm121_require( $end >= $start && $end - $start <= $seconds * 1000000000, 'Process deadline exceeded or clock invalid.' );
		wstm121_require( 0 === ( $receipt['exit_code'] ?? null ) && true === ( $receipt['root_exit_observed'] ?? null )
			&& true === ( $receipt['stdout_eof'] ?? null ) && true === ( $receipt['stderr_eof'] ?? null )
			&& false === ( $receipt['truncated'] ?? null ) && array_key_exists( 'failure', $receipt ) && null === $receipt['failure'], 'Incomplete or failed original process receipt.' );
		wstm121_require( array() === ( $receipt['secondary_errors'] ?? null ), 'Process cleanup/flush errors invalidate the capture.' );
		foreach ( array( 'stdout', 'stderr' ) as $stream ) {
			$bytes = Wstm121Plan::unsigned( $receipt[ $stream . '_bytes' ] ?? null, $stream . ' bytes' );
			wstm121_require( $bytes <= Wstm121Plan::STREAM_BYTES, 'Process capture exceeds its declared byte limit.' );
			wstm121_require( $bytes === ( $receipt[ $stream . '_received' ] ?? null )
				&& 0 === ( $receipt[ $stream . '_diagnostic_bytes' ] ?? null )
				&& 0 === ( $receipt[ $stream . '_observed_not_retained' ] ?? null ), 'Process capture discarded or hid original bytes.' );
			Wstm121Plan::digest( $receipt[ $stream . '_sha256' ] ?? null );
		}
	}

	public static function shard( string $directory, string $shard, string $custody_digest ): array {
		$custody = self::custody( $directory, $custody_digest );
		$environment = Wstm121Plan::original( $directory . '.controller/environment.json' );
		foreach ( array( 'storage_receipt', 'private_durability_receipt' ) as $name ) {
			$path = $directory . '.controller/' . $name . '.original.json';
			Wstm121Plan::digest( $environment[ $name ]['sha256'] ?? null );
			wstm121_require( is_file( $path ) && filesize( $path ) <= 1024 * 1024
				&& hash_file( 'sha256', $path ) === $environment[ $name ]['sha256'], 'Original externally approved receipt is missing or changed.' );
		}
		$state = Wstm121Plan::original( $directory . '/state.json' );
		wstm121_require( $custody['namespace'] === $state['namespace'] && $custody['shard'] === $shard, 'Custody belongs to another shard.' );
		wstm121_require( $environment['namespace'] === $state['namespace'] && $environment['shard'] === $shard
			&& $environment['source_sha'] === $custody['source_sha'], 'Retained environment identity disagrees with custody.' );
		$jobs = Wstm121Plan::jobs( $state, $shard );
		$provenance = Wstm121Plan::original( $directory . '/provenance.json' );
		wstm121_require( $custody['source_sha'] === $provenance['source_sha'] && $state['sources'] === $provenance['sources'], 'State/custody/provenance source identities disagree.' );
		$seed = Wstm121Plan::original( $directory . '/seed-measurements.json' );
		wstm121_require( array_map( 'count', $state['catalog'] ) === ( $seed['catalog_counts'] ?? null )
			&& 100 === ( $seed['large_posts'] ?? null ) && 55 * 1024 === ( $seed['large_body_bytes'] ?? null )
			&& is_string( $seed['large_count_sql'] ?? null ) && str_contains( $seed['large_count_sql'], 'OCTET_LENGTH(post_content)' ), 'Missing actual full-size seed measurements.' );
		$referenced = array();
		foreach ( $state['catalog']['attachment'] as $rank => $id ) {
			if ( 0 === $rank % 5 ) {
				$referenced[] = $id;
			}
		}
		array_push( $referenced, ...array_slice( $state['catalog']['attachment'], 1, 4 ) );
		wstm121_require( array_values( array_unique( $referenced ) ) === $state['referenced'], 'Seed reference truth does not match the controlled featured/literal fixtures.' );
		self::reference_probe( $state, Wstm121Plan::original( $directory . '/reference-parity.json' ) );
		self::cache_probe( $state, Wstm121Plan::original( $directory . '/window-cache-faults.json' ) );
		self::projection_probe( $state, self::original_projection( $directory . '/projection-probes.json' ) );
		$plan = Wstm121Plan::original( $directory . '/plan.json' );
		wstm121_require( $plan['shard'] === $shard && $plan['jobs'] === $jobs, 'Frozen plan differs from independent generation.' );
		$before = Wstm121Plan::original( $directory . '/before.json' );
		$after = Wstm121Plan::original( $directory . '/after.json' );
		wstm121_require( $before === $after, 'Original database snapshots differ.' );
		$setup = Wstm121Plan::original( $directory . '/setup-before.json' );
		$restored = Wstm121Plan::original( $directory . '/cleanup-readback.json' );
		wstm121_require( $restored['snapshot'] === $setup, 'Cleanup did not restore the original persisted state.' );
		wstm121_require( array_keys( $restored['remaining'] ) === array( 'posts', 'actor', 'actor_meta', 'control' ), 'Incomplete owned-resource readback.' );
		foreach ( $restored['remaining'] as $kind => $count ) {
			wstm121_require( 0 === Wstm121Plan::unsigned( $count, 'remaining ' . $kind ), 'Owned fixture resources remain.' );
		}
		$execution = Wstm121Plan::original( $directory . '/execution.json' );
		wstm121_require( $execution === Wstm121Plan::original( $directory . '.controller/outcome.json' )
			&& array_key_exists( 'failure', $execution ) && null === $execution['failure'], 'Final execution/outcome originals differ or report failure.' );
		foreach ( array( 'cleanup_failure', 'storage_failure', 'deadline_failure' ) as $failure ) {
			wstm121_require( ! array_key_exists( $failure, $execution ), 'Execution contains a terminal ' . $failure );
		}
		$controller_start = Wstm121Plan::unsigned( $execution['controller_start_ns'] ?? null, 'controller start' );
		$controller_end = Wstm121Plan::unsigned( $execution['controller_end_ns'] ?? null, 'controller end' );
		wstm121_require( $controller_end >= $controller_start && $controller_end - $controller_start <= Wstm121Plan::NATIVE_SECONDS * 1000000000, 'Whole controller lifetime exceeded its native bound.' );
		$storage = self::storage( $directory, $execution );
		$custody_end = Wstm121Plan::unsigned( $custody['custody_ready_ns'] ?? null, 'custody ready' );
		wstm121_require( $custody['controller_start_ns'] === $controller_start && $custody_end >= $controller_end
			&& $custody_end - $controller_start <= Wstm121Plan::NATIVE_SECONDS * 1000000000, 'Custody finalization exceeded the native budget.' );
		wstm121_require( array_keys( $execution['phases'] ) === array_keys( Wstm121Plan::PHASE_SECONDS ), 'Phase accounting is incomplete or reordered.' );
		$previous = 0;
		foreach ( Wstm121Plan::PHASE_SECONDS as $phase => $seconds ) {
			$receipt = $execution['phases'][ $phase ];
			if ( 'traversal' === $phase ) {
				$start = Wstm121Plan::unsigned( $receipt['start_ns'] ?? null, 'traversal start' );
				$end = Wstm121Plan::unsigned( $receipt['end_ns'] ?? null, 'traversal end' );
				wstm121_require( 'worker-sequence' === ( $receipt['kind'] ?? null ) && count( $jobs ) === ( $receipt['workers'] ?? null )
					&& $end >= $start && $end - $start <= $seconds * 1000000000, 'Traversal accounting is invalid.' );
			} else {
				self::receipt( $receipt, $seconds );
				$phase_directory = $directory . '.controller/' . $phase;
				$original = Wstm121Plan::original( $phase_directory . '/receipt.json' );
				wstm121_require( $original === $receipt, 'Phase summary differs from its original receipt.' );
				self::captures( $phase_directory, $receipt );
			}
			wstm121_require( $receipt['start_ns'] >= $previous, 'Phase lifetimes overlap or move backwards.' );
			$previous = $receipt['end_ns'];
		}
		$first = reset( $execution['phases'] );
		wstm121_require( $first['start_ns'] >= $controller_start && $previous <= $controller_end, 'Phase receipts escaped the controller lifetime.' );
		wstm121_require( $previous - $first['start_ns'] <= Wstm121Plan::NATIVE_SECONDS * 1000000000, 'Whole native budget exhausted.' );
		foreach ( array( 'retained_bytes', 'cumulative_write_bytes' ) as $counter ) {
			wstm121_require( Wstm121Plan::unsigned( $execution['namespace_storage'][ $counter ] ?? null, $counter ) <= Wstm121Plan::NAMESPACE_BYTES, 'Namespace storage budget exceeded.' );
		}
		$seen = array();
		$completed = array();
		$case_hashes = array();
		foreach ( $jobs as $index => $job ) {
			$operation = $directory . '/operation-' . ( $index + 1 );
			$receipt = Wstm121Plan::original( $operation . '/receipt.json' );
			self::receipt( $receipt, Wstm121Plan::WORKER_SECONDS );
			self::captures( $operation, $receipt );
			$record = self::original_operation( $operation . '/result.json' );
			$result = self::operation( $state, $job, $record );
			wstm121_require( $receipt['start_ns'] >= $execution['phases']['traversal']['start_ns']
				&& $receipt['end_ns'] <= $execution['phases']['traversal']['end_ns'], 'Worker escaped its complete traversal sequence.' );
			wstm121_require( $record['operation_start']['time_ns'] >= $receipt['start_ns'] && $record['operation_end']['time_ns'] <= $receipt['end_ns'], 'Operation escaped its owned process lifetime.' );
			$case_hashes[ $job['coverage_key'] ] = hash_file( 'sha256', $operation . '/result.json' );
			if ( 'traversal' === $job['kind'] ) {
				$key = $job['sequence_key'];
				$seen[ $key ] = array_merge( $seen[ $key ] ?? array(), $result['item_ids'] );
				if ( $job['page'] === $job['eof_page'] ) {
					$expected = array();
					foreach ( $state['catalog'][ $job['type'] ] as $rank => $id ) {
						if ( wstm121_allowed( $job['mode'], $rank ) && ( ! $job['orphan'] || ! in_array( $id, $state['referenced'], true ) ) ) {
							$expected[] = $id;
						}
					}
					wstm121_require( $seen[ $key ] === $expected, 'Original traversal duplicates, skips, or reorders eligible IDs.' );
					$completed[ $key ] = hash( 'sha256', json_encode( $seen[ $key ], JSON_THROW_ON_ERROR ) );
				}
			}
		}
		wstm121_require( 6 === count( $completed ), 'Missing complete stateful traversal.' );
		return array( 'shard' => $shard, 'namespace' => $state['namespace'], 'case_hashes' => $case_hashes, 'traversals' => $completed, 'storage' => $storage );
	}

	private static function captures( string $directory, array $receipt ): void {
		$terminal = Wstm121Plan::original( $directory . '/terminal.json' );
		$without_hashes = $receipt;
		unset( $without_hashes['stdout_sha256'], $without_hashes['stderr_sha256'] );
		wstm121_require( $terminal === $without_hashes, 'Terminal facts changed during postprocessing.' );
		foreach ( array( 'stdout', 'stderr' ) as $stream ) {
			$path = $directory . '/' . $stream . '.log';
			$overflow = $directory . '/' . $stream . '.overflow.bin';
			wstm121_require( is_file( $path ) && ! is_link( $path ) && filesize( $path ) === $receipt[ $stream . '_bytes' ]
				&& hash_file( 'sha256', $path ) === $receipt[ $stream . '_sha256' ], 'Original process capture is absent or altered.' );
			wstm121_require( is_file( $overflow ) && ! is_link( $overflow ) && 0 === filesize( $overflow ), 'Overflow diagnostics indicate incomplete original output.' );
		}
	}

	public static function aggregate( array $directories, array $custody_hashes = array() ): array {
		wstm121_require( array_keys( self::keyed_directories( $directories ) ) === array_keys( Wstm121Plan::WORKERS ), 'Missing, duplicate, or reordered shards.' );
		wstm121_require( array_keys( $custody_hashes ) === array_keys( $directories ), 'Every shard requires an independently pinned custody hash.' );
		$results = array();
		$namespaces = array();
		$platform = null;
		$identities = null;
		foreach ( $directories as $shard => $directory ) {
			$provenance = Wstm121Plan::original( $directory . '/provenance.json' );
			$actual_platform = array_intersect_key( $provenance, array_flip( array( 'php', 'wordpress', 'yoast' ) ) );
			wstm121_require( 3 === count( $actual_platform ), 'Missing runtime identity.' );
			$actual_identities = array( $provenance['source_sha'], $provenance['sources']['sha256'] );
			wstm121_require( is_string( $actual_identities[0] ) && 1 === preg_match( '/\\A[a-f0-9]{40}\\z/D', $actual_identities[0] ), 'Invalid source commit identity.' );
			wstm121_require( is_array( $actual_identities[1] ) && count( $actual_identities[1] ) > 0, 'Missing source-file identities.' );
			foreach ( $actual_identities[1] as $digest ) {
				Wstm121Plan::digest( $digest );
			}
			wstm121_require( null === $platform || $actual_platform === $platform, 'Runtime platforms cannot be combined into one acceptance result.' );
			wstm121_require( null === $identities || $actual_identities === $identities, 'Source identities differ between shards.' );
			$platform = $actual_platform;
			$identities = $actual_identities;
			$result = self::shard( $directory, $shard, $custody_hashes[ $shard ] );
			wstm121_require( ! isset( $namespaces[ $result['namespace'] ] ), 'Shard namespace reused.' );
			$namespaces[ $result['namespace'] ] = true;
			$results[] = $result;
		}

		return $results;
	}

	private static function keyed_directories( array $directories ): array {
		$seen = array();
		foreach ( $directories as $name => $directory ) {
			wstm121_require( is_string( $name ) && is_string( $directory ) && is_dir( $directory ) && ! is_link( $directory ), 'Invalid shard directory mapping.' );
			$canonical = realpath( $directory );
			wstm121_require( false !== $canonical && ! isset( $seen[ $canonical ] ), 'Shard directories must be distinct.' );
			$seen[ $canonical ] = true;
		}
		return $directories;
	}
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	try {
		wstm121_require( 'cli' === PHP_SAPI && 3 === count( $argv ), 'Usage: php bounded-list-verifier.php <shard-map.json> <exclusive-result.json>' );
		$map = Wstm121Plan::original( $argv[1] );
		$directories = array();
		$hashes = array();
		foreach ( $map as $shard => $entry ) {
			wstm121_require( is_array( $entry ) && is_string( $entry['directory'] ?? null ), 'Invalid shard-map entry.' );
			$directories[ $shard ] = $entry['directory'];
			$hashes[ $shard ] = Wstm121Plan::digest( $entry['custody_sha256'] ?? null );
		}
		$result = Wstm121Verifier::aggregate( $directories, $hashes );
		$json = json_encode( array( 'shards' => $result, 'scope' => 'Numeric/raw-record verification; host, original-package, private durability, HTTP/MCP and platform attestation are separate.' ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR );
		wstm121_require( strlen( $json ) <= Wstm121Plan::JSON_BYTES, 'Verifier result exceeds its byte limit.' );
		$output = fopen( $argv[2], 'xb' );
		wstm121_require( is_resource( $output ), 'Verifier result already exists or cannot be created.' );
		try {
			wstm121_require( strlen( $json ) === fwrite( $output, $json ) && fflush( $output ), 'Incomplete verifier result write.' );
		} finally {
			fclose( $output );
		}
	} catch ( Throwable $error ) {
		fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
