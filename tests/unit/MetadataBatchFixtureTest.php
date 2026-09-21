<?php

declare(strict_types=1);

namespace Wstm110Oracle;

use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

const ARRAY_A = 'ARRAY_A';

$source = file_get_contents( dirname( __DIR__ ) . '/e2e/metadata-batch-fixture.php' );
eval( 'namespace Wstm110Oracle; use \Closure; use \RuntimeException; ' . substr( $source, 5 ) );

final class HookRegistry {
	public static array $hooks = array();
	public static string $current = '';
}

function add_filter( $hook, $callback, $priority, $arguments ) {
	HookRegistry::$hooks[ $hook ][] = array( $callback, $priority, $arguments );
}

function remove_filter( $hook, $callback, $priority ) {
	HookRegistry::$hooks[ $hook ] = array_values( array_filter(
		HookRegistry::$hooks[ $hook ],
		static fn( $entry ) => $entry[0] !== $callback || $entry[1] !== $priority
	) );
}

function current_filter() {
	return HookRegistry::$current;
}

final class SnapshotDatabase {
	public string $last_error = '';
	public string $posts = 'fixture_posts';
	public string $postmeta = 'fixture_postmeta';
	public string $terms = 'fixture_terms';
	public string $term_taxonomy = 'fixture_term_taxonomy';
	public string $term_relationships = 'fixture_term_relationships';
	public string $options = 'fixture_options';
	public array $queries = array();
	public array $rows = array();
	public string $cron = 'original cron';
	public string $error_table = '';

	public function get_results( string $sql, string $mode ) {
		$this->queries[] = $sql;
		if ( 'ARRAY_A' !== $mode || ! preg_match( '/^SELECT \\* FROM fixture_(posts|postmeta|terms|term_taxonomy|term_relationships) ORDER BY /', $sql, $matches ) ) {
			throw new LogicException( 'Unexpected snapshot SQL or result mode.' );
		}
		$this->last_error = $this->error_table === $matches[1] ? 'Injected read failure' : '';
		return $this->rows[ $matches[1] ] ?? array();
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		if ( "SELECT option_value FROM fixture_options WHERE option_name = 'cron'" !== $sql ) {
			throw new LogicException( 'Unexpected cron SQL.' );
		}
		$this->last_error = 'cron' === $this->error_table ? 'Injected cron failure' : '';
		return $this->cron;
	}
}

final class MetadataBatchFixtureTest extends TestCase {
	protected function tearDown(): void {
		HookRegistry::$hooks = array();
		HookRegistry::$current = '';
	}

	public function test_observer_preserves_all_filter_values_and_removes_only_its_own_callback(): void {
		$existing = static fn( $value ) => $value;
		foreach ( wstm110_batch_mutation_hooks() as $hook ) {
			add_filter( $hook, $existing, PHP_INT_MIN, 1 );
		}
		$before = HookRegistry::$hooks;
		$events = array();
		$observer = wstm110_batch_observe( static function ( $hook ) use ( &$events ): void { $events[] = $hook; } );
		try {
			foreach ( wstm110_batch_mutation_hooks() as $hook ) {
				self::assertCount( 2, HookRegistry::$hooks[ $hook ] );
				self::assertSame( array( $observer, PHP_INT_MIN, 1 ), HookRegistry::$hooks[ $hook ][1] );
				HookRegistry::$current = $hook;
				foreach ( array( null, false, true, 0, '', array( 'unchanged' => 'value' ), (object) array( 'id' => 42 ) ) as $value ) {
					self::assertSame( $value, $observer( $value ) );
				}
			}
			self::assertCount( 7 * count( wstm110_batch_mutation_hooks() ), $events );
		} finally {
			wstm110_batch_unobserve( $observer );
		}
		self::assertSame( $before, HookRegistry::$hooks );
	}

	public function test_observer_does_not_suppress_evidence_write_errors_and_can_be_removed_after_throw(): void {
		$observer = wstm110_batch_observe( static function (): void { throw new RuntimeException( 'Cannot persist hook evidence.' ); } );
		try {
			$observer();
			self::fail( 'Evidence failure must not be hidden.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Cannot persist hook evidence.', $error->getMessage() );
		} finally {
			wstm110_batch_unobserve( $observer );
		}
		foreach ( HookRegistry::$hooks as $callbacks ) {
			self::assertSame( array(), $callbacks );
		}
	}

	private function with_database( callable $test ): void {
		$existed = array_key_exists( 'wpdb', $GLOBALS );
		$previous = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new SnapshotDatabase();
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

	public function test_snapshot_covers_all_post_rows_revisions_metadata_terms_relationships_and_cron(): void {
		$this->with_database( function ( SnapshotDatabase $db ): void {
			$before = wstm110_batch_snapshot();
			self::assertSame( array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'cron' ), array_keys( $before ) );
			self::assertCount( 6, $db->queries );
			self::assertStringNotContainsString( 'WHERE', $db->queries[0], 'All rows including new objects and revisions must be covered.' );
			foreach ( array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships' ) as $table ) {
				$db->rows[ $table ] = array( array( 'ID' => 42, 'value' => 'changed' ) );
				$after = wstm110_batch_snapshot();
				self::assertNotSame( $before[ $table ], $after[ $table ] );
				self::assertSame( 1, $after[ $table ]['count'] );
				$db->rows[ $table ] = array();
			}
			$db->cron = 'scheduled side effect';
			self::assertNotSame( $before['cron'], wstm110_batch_snapshot()['cron'] );
		} );
	}

	public function test_snapshot_query_failure_never_becomes_successful_empty_evidence(): void {
		$this->with_database( function ( SnapshotDatabase $db ): void {
			foreach ( array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'cron' ) as $table ) {
				$db->error_table = $table;
				try {
					wstm110_batch_snapshot();
					self::fail( "Read failure in {$table} must abort the snapshot." );
				} catch ( RuntimeException $error ) {
					self::assertStringContainsString( $table, $error->getMessage() );
				}
			}
		} );
	}
}
