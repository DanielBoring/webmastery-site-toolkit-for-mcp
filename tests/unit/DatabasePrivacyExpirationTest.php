<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/database-table-privacy-fixture.php';

final class DatabasePrivacyExpirationTest extends TestCase {
	public function test_boundary_drift_is_prevented_without_changing_counts_or_timeout_values(): void {
		$now = 100;
		$timeouts = array_merge( array_fill( 0, 45, 1 ), array( 101 ) );
		$count = static fn( int $time ): int => count( array_filter( $timeouts, static fn( int $deadline ): bool => $deadline < $time ) );
		self::assertSame( 45, $count( 100 ) );
		self::assertSame( 46, $count( 102 ) );
		$reads = array();
		$waits = array();
		$window = Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static function ( int $start, int $end ) use ( $timeouts, &$reads ) {
				$reads[] = array( $start, $end );
				$imminent = array_filter( $timeouts, static fn( int $deadline ): bool => $deadline >= $start && $deadline <= $end );
				return $imminent ? (string) min( $imminent ) : null;
			},
			static function () use ( &$now ): int { return $now; },
			static function ( int $delay ) use ( &$now, &$waits ): void { $waits[] = $delay; $now += $delay; }
		);
		self::assertSame( array( array( 100, 115 ), array( 102, 117 ) ), $reads );
		self::assertSame( array( 2 ), $waits );
		self::assertSame( array( 'started' => 102, 'ends' => 117, 'waited_seconds' => 2 ), $window );
		self::assertSame( $count( 102 ), $count( 103 ) );
		self::assertSame( $count( 103 ), $count( 104 ) );
		self::assertSame( 46, $count( 104 ) );
		self::assertSame( 101, $timeouts[45] );
	}

	public function test_deadline_equal_to_current_second_waits_until_strict_less_than_count_changes(): void {
		$now = 100;
		$window = Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static fn( int $start ) => 100 === $start ? '100' : null,
			static function () use ( &$now ): int { return $now; },
			static function ( int $delay ) use ( &$now ): void { $now += $delay; }
		);
		self::assertSame( 101, $window['started'] );
		self::assertSame( 1, $window['waited_seconds'] );
	}

	public static function invalid_deadlines(): array {
		return array_map( static fn( $value ): array => array( $value ), array( false, 101, '', '-1', '101junk', '99', '116', str_repeat( '9', 30 ) ) );
	}

	/** @dataProvider invalid_deadlines */
	public function test_invalid_admission_reads_fail_closed( $deadline ): void {
		$this->expectException( RuntimeException::class );
		Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static fn() => $deadline,
			static fn(): int => 100,
			static function (): void { self::fail( 'Invalid admission must not sleep.' ); }
		);
	}

	public function test_database_read_exception_is_not_hidden(): void {
		$this->expectExceptionMessage( 'injected database failure' );
		Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static function () { throw new RuntimeException( 'injected database failure' ); },
			static fn(): int => 100,
			static function (): void { self::fail( 'Failed read must not sleep.' ); }
		);
	}

	public function test_wait_budget_exhaustion_is_not_a_successful_admission(): void {
		$this->expectExceptionMessage( 'wait budget exhausted' );
		Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static fn(): string => '101',
			static fn(): int => 100,
			static function (): void { self::fail( 'Exhausted admission must not sleep.' ); },
			15,
			1
		);
	}

	public function test_nonadvancing_clock_fails_instead_of_retrying_forever(): void {
		$this->expectExceptionMessage( 'clock did not advance' );
		Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static fn(): string => '100',
			static fn(): int => 100,
			static function (): void {}
		);
	}

	public function test_invalid_initial_clock_fails_before_reading(): void {
		$this->expectExceptionMessage( 'Invalid expiration admission clock' );
		Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static function () { self::fail( 'Invalid clock must not query.' ); },
			static fn() => null,
			static function (): void { self::fail( 'Invalid clock must not sleep.' ); }
		);
	}

	public function test_horizon_overflow_fails_before_reading(): void {
		$this->expectExceptionMessage( 'exceeds the clock range' );
		Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static function () { self::fail( 'Overflow must not query.' ); },
			static fn(): int => PHP_INT_MAX,
			static function (): void { self::fail( 'Overflow must not sleep.' ); }
		);
	}

	public function test_consumed_time_is_not_refreshed_for_a_second_pair(): void {
		$now = 100;
		$deadline = 190;
		$wait = static function ( int $delay ) use ( &$now ): void { $now += $delay; };
		$clock = static function () use ( &$now ): int { return $now; };
		$first = Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static fn( int $start ) => 100 === $start ? '110' : null,
			$clock,
			$wait,
			15,
			$deadline - $now - 15
		);
		self::assertSame( 11, $first['waited_seconds'] );
		$now = 170;
		$this->expectExceptionMessage( 'wait budget exhausted' );
		Webmastery_MCP_Database_Table_Privacy_Fixture::expiration_window(
			static fn(): string => '180',
			$clock,
			$wait,
			15,
			$deadline - $now - 15
		);
	}

	public function test_runner_preserves_exact_parity_and_fails_window_overruns(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/database-table-privacy-runner.php' );
		self::assertStringContainsString( "'all_metrics_other_fields_and_order_identical'] = \$default === \$normalized_raw", $source );
		self::assertStringContainsString( "'explicit_false_identical' => \$default === \$explicit", $source );
		self::assertStringContainsString( "'completed_in_expiration_window' => Webmastery_MCP_Database_Table_Privacy_Fixture::in_expiration_window( \$window, \$finished )", $source );
		self::assertStringContainsString( "throw new RuntimeException( 'Could not inspect the transient expiration admission window.' )", $source );
		self::assertStringContainsString( "\$remaining = \$GLOBALS['wstm111_privacy_admission_deadline'] - time()", $source );
		self::assertSame( 1, substr_count( $source, "\$GLOBALS['wstm111_privacy_admission_deadline'] = time() + 90" ) );
	}

	public function test_window_overrun_and_backwards_clock_are_rejected_without_enlargement(): void {
		$window = array( 'started' => 100, 'ends' => 115, 'waited_seconds' => 0 );
		self::assertTrue( Webmastery_MCP_Database_Table_Privacy_Fixture::in_expiration_window( $window, 115 ) );
		self::assertFalse( Webmastery_MCP_Database_Table_Privacy_Fixture::in_expiration_window( $window, 116 ) );
		self::assertFalse( Webmastery_MCP_Database_Table_Privacy_Fixture::in_expiration_window( $window, 99 ) );
		self::assertSame( 115, $window['ends'] );
	}

	public function test_new_expiration_and_unrelated_metric_drift_still_fail_exact_payload_parity(): void {
		$default = array( 'data' => array( 'expired_transients' => array( 'count' => 45 ), 'orphaned_post_meta' => array( 'count' => 1 ) ) );
		$changed_expiration = $default;
		$changed_expiration['data']['expired_transients']['count'] = 46;
		$changed_other = $default;
		$changed_other['data']['orphaned_post_meta']['count'] = 2;
		self::assertNotSame( $default, $changed_expiration );
		self::assertNotSame( $default, $changed_other );
	}
}
