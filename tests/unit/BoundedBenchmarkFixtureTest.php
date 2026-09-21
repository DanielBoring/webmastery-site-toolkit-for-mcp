<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/bounded-list-assertions.php';

// Isolate WordPress function doubles from the shared unit bootstrap. Execute the
// actual fixture functions, not a second implementation of cleanup.
$cleanup_source = file_get_contents( dirname( __DIR__ ) . '/e2e/bounded-list-benchmark.php' );
$cleanup_source = str_replace(
	array( 'declare(strict_types=1);', "require_once __DIR__ . '/bounded-list-assertions.php';" ),
	'',
	substr( $cleanup_source, 5 )
);
eval( 'namespace Wstm121CleanupOracle; use \RuntimeException; use \Throwable; use \ReflectionClass;
function username_exists( $name ) { return $GLOBALS["wpdb"]->find_actor( $name ); }
function delete_option( $name ) { return $GLOBALS["wpdb"]->remove_control( $name ); }
' . $cleanup_source );
unset( $cleanup_source );

final class BoundedBenchmarkCleanupDatabase {
	public string $last_error = '';
	public string $posts = 'fixture_posts';
	public string $postmeta = 'fixture_postmeta';
	public string $users = 'fixture_users';
	public string $usermeta = 'fixture_usermeta';
	public string $options = 'fixture_options';
	public ?string $control = 'owned-token';
	public array $actors = array( 42 => 'w121_0123456789ab', 99 => 'unrelated' );
	public array $post_rows = array( 7 => array( 42, 'owned-token' ), 8 => array( 99, 'unrelated-token' ) );
	public array $post_meta = array( 7 => 'owned', 8 => 'unrelated' );
	public array $user_meta = array( 42 => 'owned', 99 => 'unrelated' );
	public array $mutations = array();
	public string $failure_phase = '';
	public bool $fail_actor_lookup = false;
	private array $prepared = array();

	public function prepare( string $sql, ...$args ): string {
		$key = 'prepared-' . count( $this->prepared );
		$this->prepared[ $key ] = array( $sql, $args );
		return $key;
	}

	public function find_actor( string $name ) {
		return array_search( $name, $this->actors, true );
	}

	public function get_var( string $prepared ) {
		list( $sql, $args ) = $this->prepared[ $prepared ];
		$this->last_error = '';
		if ( 'SELECT option_value FROM fixture_options WHERE option_name=%s' === $sql ) {
			if ( 'w121_0123456789ab_control' !== $args[0] ) {
				throw new LogicException( 'Wrong control option.' );
			}
			return $this->control;
		}
		if ( 'SELECT user_login FROM fixture_users WHERE ID=%d' === $sql ) {
			if ( $this->fail_actor_lookup ) {
				$this->last_error = 'Injected actor lookup failure';
				return null;
			}
			return $this->actors[ $args[0] ] ?? null;
		}
		if ( 'SELECT COUNT(*) FROM fixture_users WHERE user_login=%s AND ID<>%d' === $sql ) {
			return count( array_filter( $this->actors, static fn( $login, $id ) => $login === $args[0] && $id !== $args[1], ARRAY_FILTER_USE_BOTH ) );
		}
		if ( 'SELECT COUNT(*) FROM fixture_posts WHERE post_author=%d AND post_content_filtered<>%s' === $sql ) {
			return count( array_filter( $this->post_rows, static fn( $post ) => $post[0] === $args[0] && $post[1] !== $args[1] ) );
		}
		throw new LogicException( 'Unexpected cleanup read: ' . $sql );
	}

	private function crash_after( string $phase ): void {
		if ( $this->failure_phase === $phase ) {
			$this->failure_phase = '';
			throw new RuntimeException( 'Injected interruption after ' . $phase );
		}
	}

	public function query( string $prepared ): int {
		list( $sql, $args ) = $this->prepared[ $prepared ];
		$is_meta = 'DELETE m FROM fixture_postmeta m INNER JOIN fixture_posts p ON p.ID=m.post_id WHERE p.post_author=%d AND p.post_content_filtered=%s' === $sql;
		if ( ! $is_meta && 'DELETE FROM fixture_posts WHERE post_author=%d AND post_content_filtered=%s' !== $sql ) {
			throw new LogicException( 'Unexpected or unscoped cleanup delete: ' . $sql );
		}
		$this->mutations[] = $sql;
		foreach ( $this->post_rows as $id => $post ) {
			if ( $post[0] === $args[0] && $post[1] === $args[1] ) {
				if ( $is_meta ) {
					unset( $this->post_meta[ $id ] );
				} else {
					unset( $this->post_rows[ $id ] );
				}
			}
		}
		$this->crash_after( $is_meta ? 'postmeta' : 'posts' );
		return 1;
	}

	public function delete( string $table, array $where ): int {
		$this->mutations[] = array( $table, $where );
		if ( 'fixture_usermeta' === $table && array( 'user_id' => 42 ) === $where ) {
			unset( $this->user_meta[42] );
			$this->crash_after( 'usermeta' );
		} elseif ( 'fixture_users' === $table && array( 'ID' => 42 ) === $where ) {
			unset( $this->actors[42] );
			$this->crash_after( 'actor' );
		} else {
			throw new LogicException( 'Unexpected or unscoped actor cleanup.' );
		}
		return 1;
	}

	public function remove_control( string $name ): bool {
		if ( 'w121_0123456789ab_control' !== $name ) {
			throw new LogicException( 'Wrong cleanup option.' );
		}
		$this->mutations[] = 'delete-control';
		if ( 'control-delete' === $this->failure_phase ) {
			$this->failure_phase = '';
			return false;
		}
		$this->control = null;
		return true;
	}
}

final class BoundedBenchmarkFixtureTest extends TestCase {
	private function cleanup_state(): array {
		return array( 'owns_control' => true, 'namespace' => 'w121_0123456789ab', 'token' => 'owned-token', 'user' => 42 );
	}

	private function with_cleanup_database( callable $test ): void {
		$existed = array_key_exists( 'wpdb', $GLOBALS );
		$previous = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new BoundedBenchmarkCleanupDatabase();
		try {
			$test( $GLOBALS['wpdb'] );
		} finally {
			if ( $existed ) {
				$GLOBALS['wpdb'] = $previous;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}

	public function test_cleanup_retries_each_partial_deletion_phase_without_broadening_ownership(): void {
		foreach ( array( 'postmeta', 'posts', 'usermeta', 'actor', 'control-delete' ) as $phase ) {
			$this->with_cleanup_database( function ( BoundedBenchmarkCleanupDatabase $db ) use ( $phase ): void {
				$db->failure_phase = $phase;
				$state = $this->cleanup_state();
				try {
					\Wstm121CleanupOracle\wstm121_cleanup( $state );
					self::fail( 'Expected injected cleanup interruption: ' . $phase );
				} catch ( RuntimeException $error ) {
					self::assertStringContainsString( 'control-delete' === $phase ? 'Cannot remove fixture control marker' : 'Injected interruption', $error->getMessage() );
				}
				self::assertSame( 'owned-token', $db->control );
				if ( in_array( $phase, array( 'actor', 'control-delete' ), true ) ) {
					self::assertArrayNotHasKey( 42, $db->actors, 'Retry must actually exercise the absent original actor.' );
				}
				\Wstm121CleanupOracle\wstm121_cleanup( $state );
				self::assertNull( $db->control );
				self::assertSame( array( 99 => 'unrelated' ), $db->actors );
				self::assertSame( array( 8 => array( 99, 'unrelated-token' ) ), $db->post_rows );
				self::assertSame( array( 8 => 'unrelated' ), $db->post_meta );
				self::assertSame( array( 99 => 'unrelated' ), $db->user_meta );
			} );
		}
	}

	public function test_cleanup_retry_refuses_mismatched_token_actor_id_namespace_or_post_marker(): void {
		foreach ( array( 'token', 'missing-token', 'actor-id', 'namespace-id', 'post-marker', 'actor-read-error' ) as $case ) {
			$this->with_cleanup_database( function ( BoundedBenchmarkCleanupDatabase $db ) use ( $case ): void {
				unset( $db->actors[42] );
				if ( 'token' === $case ) {
					$db->control = 'different-token';
				} elseif ( 'missing-token' === $case ) {
					$db->control = null;
				} elseif ( 'actor-id' === $case ) {
					$db->actors[42] = 'different-actor';
				} elseif ( 'namespace-id' === $case ) {
					$db->actors[43] = 'w121_0123456789ab';
				} elseif ( 'post-marker' === $case ) {
					$db->post_rows[7][1] = 'different-marker';
				} else {
					$db->fail_actor_lookup = true;
				}
				$before = array( $db->control, $db->actors, $db->post_rows, $db->post_meta, $db->user_meta );
				try {
					\Wstm121CleanupOracle\wstm121_cleanup( $this->cleanup_state() );
					self::fail( 'Unsafe cleanup accepted: ' . $case );
				} catch ( RuntimeException $error ) {
					self::assertNotSame( '', $error->getMessage() );
				}
				self::assertSame( array(), $db->mutations, $case );
				self::assertSame( $before, array( $db->control, $db->actors, $db->post_rows, $db->post_meta, $db->user_meta ), $case );
			} );
		}
	}

	public function test_cleanup_still_recovers_actor_inserted_before_id_was_journaled(): void {
		$this->with_cleanup_database( function ( BoundedBenchmarkCleanupDatabase $db ): void {
			$state = $this->cleanup_state();
			unset( $state['user'] );
			\Wstm121CleanupOracle\wstm121_cleanup( $state );
			self::assertNull( $db->control );
			self::assertSame( array( 99 => 'unrelated' ), $db->actors );
		} );
	}

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
