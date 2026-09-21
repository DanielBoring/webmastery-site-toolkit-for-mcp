<?php

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
require_once __DIR__ . '/error-contract-assertions.php';


require_once dirname( __DIR__, 5 ) . '/wp-load.php';

function wstm113_snapshot() {
	global $wpdb;
	$state = [];
	foreach ( [ 'posts', 'postmeta', 'term_relationships' ] as $table ) {
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->$table}", ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( $wpdb->last_error );
		}
		$state[ $table ] = [ 'count' => count( $rows ), 'hash' => hash( 'sha256', wp_json_encode( $rows ) ) ];
	}
	$state['cron'] = hash( 'sha256', wp_json_encode( _get_cron_array() ) );
	return $state;
}

function wstm113_write( $path, $summary ) {
	$directory = dirname( $path );
	if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
		throw new RuntimeException( 'Cannot create scheduling evidence directory: ' . $directory );
	}
	$json = wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $json || file_put_contents( $path, $json . "\n" ) !== strlen( $json ) + 1 ) {
		throw new RuntimeException( 'Cannot write complete scheduling evidence: ' . $path );
	}
}

$artifact = getenv( 'WSTM113_ARTIFACT' ) ?: dirname( __DIR__, 2 ) . '/e2e-artifacts/scheduling-regression.json';
$summary = [ 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'runner_sha256' => hash_file( 'sha256', __FILE__ ), 'passed' => 0, 'failed' => 0, 'cases' => [] ];
$original_timezone = get_option( 'timezone_string' );
$hooks = [ 'pre_post_insert', 'pre_post_update', 'wp_insert_post', 'wp_after_insert_post', 'save_post', 'transition_post_status', 'publish_post', 'publish_page', 'publish_mcp_book', 'publish_mcp_case_study', 'added_post_meta', 'updated_post_meta', 'set_object_terms', 'schedule_event' ];
$observed = [];
$observer = function () use ( &$observed ) {
	$hook = current_filter();
	$observed[ $hook ] = ( $observed[ $hook ] ?? 0 ) + 1;
	// schedule_event is a filter, unlike the other observers.
	return func_get_arg( 0 );
};

try {
	// Fail before fixtures if evidence cannot be persisted.
	wstm113_write( $artifact, $summary );
	update_option( 'timezone_string', 'America/New_York' );
	wp_get_abilities();
	$variants = [
		[ 'post', 'post', 'post_id', 'editor_test' ],
		[ 'page', 'page', 'page_id', 'editor_test' ],
		[ 'mcp_book', 'cpt-mcp-book', 'id', 'book_manager_test' ],
		[ 'mcp_case_study', 'cpt-mcp-case-study', 'id', 'case_manager_test' ],
	];
	foreach ( $variants as [ $type, $base, $id_key, $login ] ) {
		$actor = get_user_by( 'login', $login );
		if ( ! $actor ) {
			throw new RuntimeException( 'Missing contract fixture actor: ' . $login );
		}
		wp_set_current_user( $actor->ID );
		$parent_id = 'page' === $type ? wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'WSTM113 parent', 'post_status' => 'draft' ], true ) : 0;
		if ( is_wp_error( $parent_id ) ) {
			throw new RuntimeException( $parent_id->get_error_message() );
		}
		$taxonomy = 'mcp_book' === $type ? 'mcp_genre' : 'category';
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids', 'number' => 1 ] );
		if ( is_wp_error( $terms ) || ! $terms ) {
			throw new RuntimeException( 'Missing scheduling taxonomy fixture: ' . $taxonomy );
		}
		foreach ( [ 'create', 'update' ] as $operation ) {
			$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $operation . '-' . $base );
			$cases = [
				[ 'unparseable', [ 'status' => 'future', 'scheduled_date' => 'next tuesday at nine-ish' ], 'invalid_scheduled_date' ],
				[ 'missing', [ 'status' => 'future' ], 'missing_scheduled_date' ],
				[ 'empty', [ 'status' => 'future', 'scheduled_date' => '' ], 'missing_scheduled_date' ],
				[ 'whitespace', [ 'status' => 'future', 'scheduled_date' => '   ' ], 'missing_scheduled_date' ],
				[ 'zero-string', [ 'status' => 'future', 'scheduled_date' => '0' ], 'invalid_scheduled_date' ],
				[ 'overflow-day', [ 'status' => 'future', 'scheduled_date' => '2030-02-30T12:00:00Z' ], 'invalid_scheduled_date' ],
				[ 'nonleap', [ 'status' => 'future', 'scheduled_date' => '2030-02-29T12:00:00Z' ], 'invalid_scheduled_date' ],
				[ 'overflow-month', [ 'status' => 'future', 'scheduled_date' => '2030-13-01T12:00:00Z' ], 'invalid_scheduled_date' ],
				[ 'overflow-hour', [ 'status' => 'future', 'scheduled_date' => '2030-01-01T24:00:00Z' ], 'invalid_scheduled_date' ],
				[ 'past', [ 'status' => 'future', 'scheduled_date' => '2001-01-01T00:00:00Z' ], 'scheduled_date_too_soon' ],
				[ 'now', [ 'status' => 'future', 'scheduled_date' => 'now' ], 'scheduled_date_too_soon' ],
				[ 'near-now', [ 'status' => 'future', 'scheduled_date' => '+30 seconds' ], 'scheduled_date_too_soon' ],
				[ 'invalid-nonfuture', [ 'status' => 'draft', 'scheduled_date' => 'bad date' ], 'invalid_scheduled_date' ],
				[ 'utc', [ 'status' => 'future', 'scheduled_date' => '2030-01-01T12:00:00Z' ], null ],
				[ 'positive-offset', [ 'status' => 'future', 'scheduled_date' => '2030-01-01T12:00:00+05:30' ], null ],
				[ 'negative-offset', [ 'status' => 'future', 'scheduled_date' => '2030-01-01T12:00:00-05:00' ], null ],
				[ 'offsetless', [ 'status' => 'future', 'scheduled_date' => '2030-01-01T12:00:00' ], null ],
				[ 'relative', [ 'status' => 'future', 'scheduled_date' => 'next Tuesday +2 weeks' ], null ],
				[ 'date-only', [ 'status' => 'future', 'scheduled_date' => '2030-01-01' ], null ],
				[ 'fold-first', [ 'status' => 'future', 'scheduled_date' => '2030-11-03T01:30:00-04:00' ], null ],
				[ 'fold-second', [ 'status' => 'future', 'scheduled_date' => '2030-11-03T01:30:00-05:00' ], null ],
				[ 'named-gap', [ 'status' => 'future', 'scheduled_date' => '2030-03-10 02:30:00 America/New_York' ], null ],
				[ 'nonfuture-date', [ 'status' => 'draft', 'scheduled_date' => '2001-01-01T00:00:00Z' ], null ],
				[ 'ordinary', [ 'status' => 'draft' ], null ],
				[ 'omitted-status', [], null ],
				[ 'nonfuture-omitted-status-date', [ 'scheduled_date' => '2001-01-01T00:00:00Z' ], null ],
			];
			if ( 'update' === $operation ) {
				$cases = array_merge( $cases, [
					[ 'existing-future-omitted', [], null, 'future' ],
					[ 'existing-future-explicit', [ 'status' => 'future' ], null, 'future' ],
					[ 'existing-future-empty', [ 'scheduled_date' => '' ], 'missing_scheduled_date', 'future' ],
					[ 'existing-future-near', [], 'scheduled_date_too_soon', 'near' ],
					[ 'existing-future-overdue', [], 'scheduled_date_too_soon', 'overdue' ],
					[ 'direct-invalid-status-overdue', [ 'status' => 'not-a-status' ], 'scheduled_date_too_soon', 'overdue' ],
					[ 'existing-future-invalid', [], 'invalid_scheduled_date', 'invalid' ],
					[ 'existing-timezone-change', [], null, 'timezone-change' ],
					[ 'explicit-unschedule', [ 'status' => 'draft' ], null, 'future' ],
					[ 'explicit-publish-overdue', [ 'status' => 'publish' ], null, 'overdue' ],
					[ 'pending-zero-gmt', [ 'status' => 'future', 'scheduled_date' => '2030-01-01T12:00:00Z' ], null, 'pending' ],
					[ 'private-transition', [ 'status' => 'future', 'scheduled_date' => '2030-01-01T12:00:00Z' ], null, 'private' ],
					[ 'publish-transition', [ 'status' => 'future', 'scheduled_date' => '2030-01-01T12:00:00Z' ], null, 'publish' ],
					[ 'nonfuture-zero-gmt', [ 'scheduled_date' => '2001-01-01T00:00:00Z' ], null ],
				] );
			}
			foreach ( $cases as $case ) {
				[ $label, $input, $error ] = $case;
				$fixture = $case[3] ?? 'draft';
				$id = 0;
				if ( 'update' === $operation ) {
					$id = wp_insert_post( [ 'post_type' => $type, 'post_title' => 'WSTM113 fixture', 'post_content' => 'Original content', 'post_status' => in_array( $fixture, [ 'draft', 'pending', 'private', 'publish' ], true ) ? $fixture : 'future', 'post_date' => 'publish' === $fixture ? '2020-06-01 08:00:00' : '2030-06-01 08:00:00', 'post_date_gmt' => in_array( $fixture, [ 'draft', 'pending' ], true ) ? '0000-00-00 00:00:00' : ( 'publish' === $fixture ? '2020-06-01 12:00:00' : '2030-06-01 12:00:00' ) ], true );
					if ( is_wp_error( $id ) ) {
						throw new RuntimeException( $id->get_error_message() );
					}
					if ( in_array( $fixture, [ 'near', 'overdue', 'invalid' ], true ) ) {
						$date = 'invalid' === $fixture ? '0000-00-00 00:00:00' : gmdate( 'Y-m-d H:i:s', time() + ( 'near' === $fixture ? 30 : -3600 ) );
						$wpdb->update( $wpdb->posts, [ 'post_status' => 'future', 'post_date_gmt' => $date ], [ 'ID' => $id ] );
						clean_post_cache( $id );
					}
					if ( 'timezone-change' === $fixture ) {
						update_option( 'timezone_string', 'Pacific/Auckland' );
					}
					$input[ $id_key ] = $id;
				}
				$input = array_merge( [ 'title' => 'WSTM113 ' . $label, 'content' => '<p>Changed content</p>', 'slug' => 'wstm113-' . $label ], $input );
				if ( $id ) {
					update_post_meta( $id, '_yoast_wpseo_metadesc', 'Scheduling metadata sentinel' );
				}
				if ( 'page' === $type ) {
					$input['parent'] = $parent_id;
				}
				if ( 'post' === $type ) {
					$input['category_ids'] = array_map( 'intval', $terms );
				}
				if ( 'mcp_book' === $type ) {
					$input['taxonomy_terms'] = [ 'mcp_genre' => array_map( 'intval', $terms ) ];
				}
				$before_post = $id ? get_post( $id, ARRAY_A ) : null;
				$before = wstm113_snapshot();
				$observed = [];
				foreach ( $hooks as $hook ) {
					add_filter( $hook, $observer, PHP_INT_MAX );
				}
				if ( 'direct-invalid-status-overdue' === $label ) {
					// The API schema rejects this enum; also verify callback defense.
					$property = new ReflectionProperty( $ability, 'execute_callback' );
					$property->setAccessible( true );
					$callback = $property->getValue( $ability );
					$result = $callback( $input );
				} else {
					$result = $ability->execute( $input );
				}
				foreach ( $hooks as $hook ) {
					remove_filter( $hook, $observer, PHP_INT_MAX );
				}
				$after = wstm113_snapshot();
				$result_id = $result['data']['id'] ?? $id;
				$post = $result_id ? get_post( $result_id ) : null;
				$initial_cron = $post ? wp_next_scheduled( 'publish_future_post', [ $post->ID ] ) : null;
				$code = wstm118_error_reason( $result );
				$passed = $error ? $code === $error && $before === $after && [] === $observed : true === ( $result['success'] ?? false ) && ! empty( $observed['save_post'] );
				if ( $id ) {
					$passed = $passed && 'Scheduling metadata sentinel' === get_post_meta( $id, '_yoast_wpseo_metadesc', true );
				}
				$expected = [];
				if ( ! $error && $post ) {
					$effective = $input['status'] ?? ( $before_post['post_status'] ?? 'draft' );
					if ( 'future' === $effective ) {
						$timestamp = isset( $input['scheduled_date'] ) ? strtotime( $input['scheduled_date'] ) : strtotime( $before_post['post_date_gmt'] . ' GMT' );
						$expected = [ 'status' => 'future', 'gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ), 'local' => isset( $input['scheduled_date'] ) ? wp_date( 'Y-m-d H:i:s', $timestamp ) : $before_post['post_date'] ];
						$passed = $passed && $post->post_status === $expected['status'] && $post->post_date_gmt === $expected['gmt'] && $post->post_date === $expected['local'];
						// An early fold/timezone event must not publish ahead of authoritative GMT.
						check_and_publish_future_post( $post->ID );
						$passed = $passed && 'future' === get_post_status( $post->ID ) && wp_next_scheduled( 'publish_future_post', [ $post->ID ] ) >= $timestamp;
					} else {
						$passed = $passed && $post->post_status === $effective;
						if ( 'create' === $operation && isset( $input['scheduled_date'] ) ) {
							$passed = $passed && $post->post_date === wp_date( 'Y-m-d H:i:s', strtotime( $input['scheduled_date'] ) );
						}
						if ( in_array( $label, [ 'nonfuture-date', 'nonfuture-zero-gmt', 'nonfuture-omitted-status-date' ], true ) && 'update' === $operation ) {
							$passed = $passed && '0000-00-00 00:00:00' === $post->post_date_gmt && str_starts_with( $post->post_date, wp_date( 'Y-m-d' ) );
						}
					}
				}
				$summary[ $passed ? 'passed' : 'failed' ]++;
				$summary['cases'][] = [ 'ability' => $operation . '-' . $base, 'label' => $label, 'passed' => $passed, 'expected_error' => $error, 'result' => $result, 'before' => $before, 'after' => $after, 'hooks' => $observed, 'expected_date' => $expected, 'stored' => $post ? [ 'id' => $post->ID, 'status' => $post->post_status, 'local' => $post->post_date, 'gmt' => $post->post_date_gmt, 'initial_cron' => $initial_cron, 'cron_after_early_guard' => wp_next_scheduled( 'publish_future_post', [ $post->ID ] ) ] : null ];
				if ( 'timezone-change' === $fixture ) {
					update_option( 'timezone_string', 'America/New_York' );
				}
			}
		}
	}
} catch ( Throwable $error ) {
	$summary['failed']++;
	$summary['fatal'] = $error->getMessage();
} finally {
	foreach ( $hooks as $hook ) {
		remove_filter( $hook, $observer, PHP_INT_MAX );
	}
	update_option( 'timezone_string', $original_timezone );
	wstm113_write( $artifact, $summary );
}
echo wp_json_encode( [ 'passed' => $summary['passed'], 'failed' => $summary['failed'], 'fatal' => $summary['fatal'] ?? null ] ) . "\n";
exit( $summary['failed'] ? 1 : 0 );
