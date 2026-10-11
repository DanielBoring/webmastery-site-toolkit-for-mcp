<?php

use PHPUnit\Framework\TestCase;
use WstmCacheWitness\State;

require_once dirname( __DIR__ ) . '/e2e/bounded-list-verifier.php';
require_once __DIR__ . '/fixtures/cache-witness-boundary.php';

final class CacheWitnessBoundaryTest extends TestCase {
	public static function ordering(): array {
		return array( 'first' => array( 0,2 ), 'middle' => array( 1,1 ), 'last-final' => array( 2,0 ), 'sole-final' => array( 0,0 ) );
	}

	/** @dataProvider ordering */
	public function test_actual_producer_and_verifier_retain_exact_completed_failure( int $prefix, int $suffix ): void {
		$record = \WstmCacheWitness\run( $prefix, $suffix );
		self::assertSame( 1, \WstmCacheWitness\Wstm121Verifier::$calls );
		self::assertArrayNotHasKey( 'failure', $record );
		$phase = $record['phases']['priming'];
		$witness = $phase['failure_witness'];
		self::assertSame( $prefix, $witness['sql_index'] );
		self::assertSame( $phase['query_start'] + $prefix, $witness['query_before'] );
		self::assertSame( count( $phase['sql'] ), $phase['query_end'] - $phase['query_start'] );
		self::assertSame( $witness['query_before'] + 1, $witness['query_completed'] );
		self::assertSame( hash( 'sha256', $phase['injection']['replacement'] ), $witness['query_sha256'] );
		self::assertTrue( $witness['result_failed'] );
		self::assertTrue( $witness['error_present'] );
		self::assertSame( $suffix ? 'before-next-query' : 'final', $witness['boundary'] );
		self::assertSame( $suffix ? '' : 'Private native modeled database failure', $phase['private_database_error'] );
		self::assertFalse( $GLOBALS['wpdb']->suppressed );
		foreach ( State::$filters as $priorities ) {
			foreach ( $priorities as $callbacks ) { self::assertSame( array(), $callbacks ); }
		}
	}

	public static function faults(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'successful-injection', 'nonexecuted', 'foreign-query', 'wrong-counter' ) );
	}

	/** @dataProvider faults */
	public function test_actual_producer_refuses_unproved_or_foreign_execution( string $fault ): void {
		$this->expectException( RuntimeException::class );
		\WstmCacheWitness\run( 1, 2, $fault );
	}

	public function test_nonexecuted_stale_error_is_not_a_completed_witness(): void {
		$db = new \WstmCacheWitness\Database();
		$db->num_queries = 7;
		$db->last_error = 'stale';
		$db->last_query = 'SELECT deliberate';
		$db->result = false;
		$witness = new Wstm121QueryFailureWitness();
		$witness->injected( 'SELECT deliberate', 7, 0 );
		$witness->observe( $db, 'final' );
		self::assertNull( $witness->retained() );
	}

	public static function mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'missing', 'extra', 'type', 'stale', 'count', 'float', 'index', 'counter-association', 'hash', 'result', 'error', 'boundary', 'final', 'original', 'replacement', 'foreign-table', 'phase', 'injection-extra' ) );
	}

	/** @dataProvider mutations */
	public function test_actual_verifier_refuses_malformed_or_unassociated_witness( string $mutation ): void {
		$record = \WstmCacheWitness\run();
		$phase = &$record['phases']['priming'];
		$witness = &$phase['failure_witness'];
		switch ( $mutation ) {
			case 'missing': unset( $phase['failure_witness'] ); break;
			case 'extra': $witness['raw_error'] = 'private'; break;
			case 'type': $witness = 'witness'; break;
			case 'stale': $witness['query_before'] = $phase['query_start'] - 1; break;
			case 'count': ++$witness['query_completed']; break;
			case 'float': $witness['query_completed'] = (float) $witness['query_completed']; break;
			case 'index': $witness['sql_index'] = 0; break;
			case 'counter-association':
				++$phase['injection']['query_before'];
				++$witness['query_before'];
				++$witness['query_completed'];
				break;
			case 'hash': $witness['query_sha256'] = str_repeat( 'a', 64 ); break;
			case 'result': $witness['result_failed'] = false; break;
			case 'error': $witness['error_present'] = false; break;
			case 'boundary': $witness['boundary'] = 'after-clear'; break;
			case 'final': $witness['boundary'] = 'final'; break;
			case 'original': $phase['injection']['original'] .= ' '; break;
			case 'replacement': $phase['injection']['replacement'] .= ' '; break;
			case 'foreign-table': $phase['injection']['replacement'] = 'SELECT wstm121_deliberate_missing_column FROM foreign_posts LIMIT 1'; break;
			case 'phase': $phase['injection']['original'] = $phase['request']; break;
			case 'injection-extra': $phase['injection']['extra'] = true; break;
		}
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::cache_probe( array( 'catalog' => array( 'post' => array( 201,200,199 ) ) ), $record );
	}

	public function test_conflicting_injector_receipts_are_refused(): void {
		$witness = new Wstm121QueryFailureWitness();
		$witness->injected( 'SELECT deliberate', 7, 0 );
		$this->expectException( RuntimeException::class );
		$witness->injected( 'SELECT foreign', 7, 0 );
	}

	public function test_successful_completed_query_cannot_claim_failure_from_injection(): void {
		$db = new \WstmCacheWitness\Database();
		$witness = new Wstm121QueryFailureWitness();
		$witness->injected( 'SELECT successful', 0, 0 );
		$db->num_queries = 1;
		$db->last_query = 'SELECT successful';
		$db->last_error = '';
		$db->result = true;
		$witness->observe( $db, 'final' );
		self::assertFalse( $witness->retained()['result_failed'] );
		self::assertFalse( $witness->retained()['error_present'] );
	}
}
