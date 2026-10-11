<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WstmReferenceBoundary\State;

require_once dirname( __DIR__ ) . '/e2e/bounded-list-verifier.php';
require_once __DIR__ . '/fixtures/reference-probe-boundary.php';

final class ReferenceProbeBoundaryTest extends TestCase {
	private $previous_db;

	protected function setUp(): void {
		$this->previous_db = $GLOBALS['wpdb'] ?? null;
		State::$filters = State::$early = State::$executed = State::$writes = State::$verified = array();
		State::$observer_registrations = State::$verifier_calls = 0;
		State::$fault = '';
		State::$state = array( 'catalog' => array( 'attachment' => range( 100, 1 ) ), 'referenced' => array( 100, 99, 98 ) );
		$GLOBALS['wpdb'] = new \WstmReferenceBoundary\Database();
	}

	protected function tearDown(): void { $GLOBALS['wpdb'] = $this->previous_db; }

	private function run_probe(): array {
		\WstmReferenceBoundary\wstm121_parity( State::$state, '/owned-test' );
		return State::$writes['/owned-test/reference-parity.json'];
	}

	public function test_actual_parity_runs_strict_verifier_after_native_placeholder_phase_without_duplicate_sql(): void {
		$record = $this->run_probe();
		self::assertSame( 1, State::$verifier_calls );
		self::assertSame( $record, State::$verified );
		self::assertArrayNotHasKey( 'failure', $record );
		self::assertSame( $record['before'], $record['after'] );
		self::assertSame( array(), $record['mutations'] );
		self::assertCount( count( State::$executed ), $record['sql'] );
		self::assertCount( count( State::$executed ), State::$early );
		self::assertStringContainsString( '{' . str_repeat( 'a', 64 ) . '}', State::$early[0] );
		self::assertStringContainsString( "'%literal%'", $record['sql'][0] );
		self::assertNotSame( State::$early[0], $record['sql'][0] );
		foreach ( $record['read_failures'] as $failure ) {
			self::assertCount( 2, $failure['injected'] );
			self::assertFalse( $failure['forced_response']['success'] );
			foreach ( $failure['injected'] as $injection ) {
				self::assertContains( $injection['original'], $record['sql'] );
				self::assertContains( $injection['replacement'], State::$executed );
				self::assertStringNotContainsString( 'wstm121_deliberate_missing_column', json_encode( $failure['forced_response'] ) );
			}
		}
		self::assertSame( 4, count( array_filter( State::$executed, static fn( $sql ) => false !== strpos( $sql, 'wstm121_deliberate_missing_column' ) ) ) );
		self::assertCount( 208, State::$executed );
		self::assertFalse( $GLOBALS['wpdb']->suppressed );
		self::assertSame( array(), array_values( State::$filters['query'][PHP_INT_MAX] ) );
	}

	public function test_original_early_observation_reproduces_exact_placeholder_membership_failure(): void {
		try {
			\WstmReferenceBoundary\wstm121_parity_early( State::$state, '/owned-test' );
			self::fail( 'Early placeholder observation unexpectedly accepted late original.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Missing original/replacement reference SQL.', $error->getMessage() );
			self::assertSame( 1, State::$verifier_calls );
			$record = State::$writes['/owned-test/reference-parity.json'];
			self::assertSame( 'RuntimeException: Missing original/replacement reference SQL.', $record['failure'] );
			self::assertNotContains( $record['read_failures']['content']['injected'][0]['original'], $record['sql'] );
			self::assertFalse( $GLOBALS['wpdb']->suppressed );
		}
	}

	public static function boundary_failures(): array {
		return array( array( 'missing-observer' ), array( 'conflicting-boundary' ), array( 'forced-success' ) );
	}

	/** @dataProvider boundary_failures */
	public function test_missing_conflicting_observation_and_force_success_remain_failed_and_retained( string $fault ): void {
		State::$fault = $fault;
		try {
			$this->run_probe();
			self::fail( 'Invalid reference boundary accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'forced-success' === $fault ? 'Force bypassed' : 'boundary observation', $error->getMessage() );
			self::assertArrayHasKey( 'failure', State::$writes['/owned-test/reference-parity.json'] );
			self::assertSame( 0, State::$verifier_calls );
			self::assertFalse( $GLOBALS['wpdb']->suppressed );
		}
	}

	public static function ledger_mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array(
			'missing-original', 'foreign-original', 'wrong-replacement', 'missing-injection', 'failure', 'mutation', 'snapshot',
		) );
	}

	/** @dataProvider ledger_mutations */
	public function test_actual_parity_record_cannot_accept_missing_foreign_replacement_or_failure_evidence( string $kind ): void {
		$record = $this->run_probe();
		switch ( $kind ) {
			case 'missing-original':
				$original = $record['read_failures']['content']['injected'][0]['original'];
				$record['sql'] = array_values( array_filter( $record['sql'], static fn( $sql ) => $sql !== $original ) );
				break;
			case 'foreign-original': $record['read_failures']['content']['injected'][0]['original'] .= ' /* foreign */'; break;
			case 'wrong-replacement': $record['read_failures']['content']['injected'][0]['replacement'] .= ' /* foreign */'; break;
			case 'missing-injection': array_pop( $record['read_failures']['content']['injected'] ); break;
			case 'failure': $record['failure'] = 'Original failed probe'; break;
			case 'mutation': $record['mutations'][] = 'save_post'; break;
			case 'snapshot': $record['after']['changed'] = true; break;
		}
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::reference_probe( State::$state, $record );
	}
}
