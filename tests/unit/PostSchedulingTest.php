<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostSchedulingTest extends TestCase {
	private const NOW = 1798761600;
	private string $timezone;

	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set( 'UTC' );
		$GLOBALS['wstm_test_timezone'] = 'America/New_York';
	}

	protected function tearDown(): void {
		date_default_timezone_set( $this->timezone );
		unset( $GLOBALS['wstm_test_timezone'] );
	}

	/** @dataProvider boundaries */
	public function test_exact_core_boundary( int $delta, bool $safe ): void {
		$date = gmdate( 'c', self::NOW + $delta );
		$result = Webmastery_MCP_Post_Scheduling::prepare( [ 'status' => 'future', 'scheduled_date' => $date ], null, self::NOW );
		if ( $safe ) {
			$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + $delta ), $result['post_date_gmt'] );
		} else {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'scheduled_date_too_soon', $result->get_error_code() );
		}
	}

	public static function boundaries(): array {
		return [ [ -1, false ], [ 0, false ], [ 1, false ], [ 59, false ], [ 60, true ], [ 61, true ] ];
	}

	/** @dataProvider invalidDates */
	public function test_invalid_dates_fail( $date ): void {
		$result = Webmastery_MCP_Post_Scheduling::prepare( [ 'status' => 'future', 'scheduled_date' => $date ], null, self::NOW );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_scheduled_date', $result->get_error_code() );
	}

	public static function invalidDates(): array {
		return array_map( static fn( $date ) => [ $date ], [
			'not a date', '0', '2030-02-29', '2030-02-30', '2030-13-01', '2030-01-00',
			'2030-01-01T24:00:00Z', '2030-01-01T12:60:00Z', '2030-01-01T12:00:60Z',
			'0000-01-01', '0999-01-01', '+10000-01-01T00:00:00Z', [], null, 123,
		] );
	}

	/** @dataProvider validDates */
	public function test_legacy_date_families_preserve_instant( string $date, string $gmt ): void {
		$result = Webmastery_MCP_Post_Scheduling::prepare( [ 'scheduled_date' => $date ], null, self::NOW );
		$this->assertIsArray( $result );
		$this->assertSame( $gmt, $result['post_date_gmt'] );
		$this->assertSame( wp_date( 'Y-m-d H:i:s', strtotime( $gmt . ' GMT' ) ), $result['post_date'] );
		$this->assertArrayNotHasKey( 'edit_date', $result );
	}

	public static function validDates(): array {
		return [
			[ '2030-01-01T12:00:00Z', '2030-01-01 12:00:00' ],
			[ '2030-01-01T12:00:00+05:30', '2030-01-01 06:30:00' ],
			[ '2030-01-01T12:00:00-05:00', '2030-01-01 17:00:00' ],
			[ '2030-01-01T12:00:00', '2030-01-01 12:00:00' ],
			[ '2030-01-01', '2030-01-01 00:00:00' ],
			[ '12:00:00', '2027-01-01 12:00:00' ],
			[ '+2 days', '2027-01-03 00:00:00' ],
			[ 'next Tuesday', '2027-01-05 00:00:00' ],
			[ '2030-01-31 +1 month', '2030-03-03 00:00:00' ],
			[ '2030-11-03T01:30:00-04:00', '2030-11-03 05:30:00' ],
			[ '2030-11-03T01:30:00-05:00', '2030-11-03 06:30:00' ],
			[ '2030-03-10 02:30 America/New_York', '2030-03-10 07:30:00' ],
			[ '2001-01-01T00:00:00Z', '2001-01-01 00:00:00' ],
		];
	}

	public function test_existing_future_pair_survives_timezone_change(): void {
		$post = (object) [ 'post_status' => 'future', 'post_date' => '2030-06-01 08:00:00', 'post_date_gmt' => '2030-06-01 12:00:00' ];
		$GLOBALS['wstm_test_timezone'] = 'Pacific/Auckland';
		$this->assertSame( [], Webmastery_MCP_Post_Scheduling::prepare( [], $post, self::NOW ) );
		$this->assertSame( [], Webmastery_MCP_Post_Scheduling::prepare( [ 'status' => 'future' ], $post, self::NOW ) );
		$this->assertSame( '2030-06-01 08:00:00', $post->post_date );
	}

	public function test_missing_and_empty_do_not_reuse_unrelated_dates(): void {
		$post = (object) [ 'post_status' => 'draft', 'post_date' => '2030-06-01 08:00:00', 'post_date_gmt' => '2030-06-01 12:00:00' ];
		foreach ( [ null, $post ] as $existing ) {
			foreach ( [ [ 'status' => 'future' ], [ 'status' => 'future', 'scheduled_date' => '' ] ] as $input ) {
				$this->assertSame( 'missing_scheduled_date', Webmastery_MCP_Post_Scheduling::prepare( $input, $existing, self::NOW )->get_error_code() );
			}
		}
		$post->post_status = 'future';
		$this->assertSame( 'missing_scheduled_date', Webmastery_MCP_Post_Scheduling::prepare( [ 'scheduled_date' => ' ' ], $post, self::NOW )->get_error_code() );
	}

	public function test_existing_future_uses_gmt_horizon_and_individual_date_validity(): void {
		$post = (object) [ 'post_status' => 'future', 'post_date' => '2030-06-01 08:00:00', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', self::NOW + 59 ) ];
		$this->assertSame( 'scheduled_date_too_soon', Webmastery_MCP_Post_Scheduling::prepare( [], $post, self::NOW )->get_error_code() );
		$post->post_date_gmt = gmdate( 'Y-m-d H:i:s', self::NOW + 60 );
		$this->assertSame( [], Webmastery_MCP_Post_Scheduling::prepare( [], $post, self::NOW ) );
		foreach ( [ 'post_date', 'post_date_gmt' ] as $field ) {
			$bad = clone $post;
			$bad->$field = '2030-02-30 12:00:00';
			$this->assertSame( 'invalid_scheduled_date', Webmastery_MCP_Post_Scheduling::prepare( [], $bad, self::NOW )->get_error_code() );
		}
	}

	public function test_invalid_supplied_status_uses_actual_persistence_status(): void {
		$post = (object) [ 'post_status' => 'future', 'post_date' => '2001-01-01 00:00:00', 'post_date_gmt' => '2001-01-01 00:00:00' ];
		$result = Webmastery_MCP_Post_Scheduling::prepare( [ 'status' => 'not-a-status' ], $post, self::NOW );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'scheduled_date_too_soon', $result->get_error_code() );
		$this->assertSame( [], Webmastery_MCP_Post_Scheduling::prepare( [ 'status' => 'not-a-status' ], null, self::NOW ) );
	}

	public function test_edit_date_is_only_added_for_supplied_effective_future_updates(): void {
		$post = (object) [ 'post_status' => 'draft' ];
		$input = [ 'scheduled_date' => '2030-01-01T12:00:00Z' ];
		$this->assertArrayNotHasKey( 'edit_date', Webmastery_MCP_Post_Scheduling::prepare( $input, $post, self::NOW ) );
		$input['status'] = 'future';
		$this->assertTrue( Webmastery_MCP_Post_Scheduling::prepare( $input, $post, self::NOW )['edit_date'] );
		$this->assertArrayNotHasKey( 'edit_date', Webmastery_MCP_Post_Scheduling::prepare( $input, null, self::NOW ) );
	}

	public function test_nonfuture_missing_date_and_explicit_unschedule_remain_ordinary_updates(): void {
		$this->assertSame( [], Webmastery_MCP_Post_Scheduling::prepare( [], null, self::NOW ) );
		$this->assertSame( [], Webmastery_MCP_Post_Scheduling::prepare( [ 'scheduled_date' => '' ], null, self::NOW ) );
		$this->assertSame( [], Webmastery_MCP_Post_Scheduling::prepare( [ 'status' => 'draft' ], (object) [ 'post_status' => 'future', 'post_date_gmt' => 'bad date' ], self::NOW ) );
	}

	public function test_cli_guard_precedes_runner_bootstrap(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/scheduling-runner.php' );
		$this->assertLessThan( strpos( $source, 'require_once' ), strpos( $source, "PHP_SAPI !== 'cli'" ) );
	}
}
