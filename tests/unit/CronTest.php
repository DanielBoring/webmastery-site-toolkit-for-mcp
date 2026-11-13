<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WstmCron\Webmastery_MCP_Cron as Cron;

require_once __DIR__ . '/fixtures/cron-stubs.php';

final class CronTest extends TestCase {
	private const NAME = 'webmastery-site-toolkit-for-mcp/get-cron-status';
	private const NOW  = 1700000000;

	protected function setUp(): void {
		$GLOBALS['wstm_cron'] = array( 'caps' => array( 'manage_options' ), 'cron' => array(), 'registered' => array(), 'reads' => 0 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm_cron'] );
		parent::tearDown();
	}

	private static function cron( $value ): void {
		$GLOBALS['wstm_cron']['cron'] = $value;
	}

	private static function event( string $schedule = '', array $args = array(), int $interval = 0 ): array {
		return array( 'schedule' => '' === $schedule ? false : $schedule, 'args' => $args ) + ( $interval ? array( 'interval' => $interval ) : array() );
	}

	private static function call( string $method, ...$args ) {
		$reflection = new ReflectionMethod( Cron::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}

	private static function status( array $input = array(), int $now = self::NOW ): array {
		return self::call( 'build', $input, $now );
	}

	public function test_registration_is_read_only_closed_and_manage_options(): void {
		Cron::register();
		$args = $GLOBALS['wstm_cron']['registered'][ self::NAME ];
		self::assertSame( array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), $args['meta']['annotations'] );
		self::assertSame( array( 'public' => true, 'type' => 'tool' ), $args['meta']['mcp'] );
		self::assertSame( array( 'per_page', 'page', 'overdue_only' ), array_keys( $args['input_schema']['properties'] ) );
		self::assertSame( array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ), $args['input_schema']['properties']['per_page'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => false ), $args['input_schema']['properties']['overdue_only'] );
		self::assertTrue( ( $args['permission_callback'] )() );
		$GLOBALS['wstm_cron']['caps'] = array( 'edit_posts', 'manage_categories' );
		self::assertInstanceOf( WP_Error::class, ( $args['permission_callback'] )() );
	}

	public function test_empty_and_corrupt_cron_arrays_yield_an_empty_status(): void {
		foreach ( array( array(), false, null, 'garbage', 7, array( 'version' => 2 ), array( 'x' => 'y' ), array( 1700000100 => 'broken', 1700000200 => array( 'hook' => 'broken' ), -5 => array( 'h' => array( 'k' => self::event() ) ), 0 => array( 'h' => array( 'k' => self::event() ) ) ) ) as $value ) {
			self::cron( $value );
			$data = self::status()['data'];
			self::assertSame( array( 'total_events' => 0, 'overdue_events' => 0, 'oldest_overdue_seconds' => null, 'next_event_in_seconds' => null, 'truncated' => false ), $data['summary'], var_export( $value, true ) );
			self::assertSame( array(), $data['items'] );
			self::assertNull( $data['next_page'] );
		}
	}

	public function test_single_and_recurring_events_and_ordering(): void {
		self::cron( array(
			self::NOW + 3600 => array(
				'zz_hook' => array( 'a' => self::event( 'hourly', array(), 3600 ) ),
				'aa_hook' => array( 'b' => self::event() ),
			),
			self::NOW + 100  => array( 'mid_hook' => array( 'c' => self::event( 'weird', array( 'x' ), 0 ) ) ),
			'version'        => 2,
		) );
		$result = self::status();
		self::assertTrue( $result['success'] );
		$data = $result['data'];
		self::assertSame( array( 'config', 'summary', 'items', 'page', 'per_page', 'next_page' ), array_keys( $data ) );
		self::assertSame( array( 'mid_hook', 'aa_hook', 'zz_hook' ), array_column( $data['items'], 'hook' ) );
		self::assertSame( array(
			'hook'             => 'mid_hook',
			'next_run'         => '2023-11-14T22:15:00Z',
			'seconds_until'    => 100,
			'overdue'          => false,
			'schedule'         => 'weird',
			'interval_seconds' => null,
			'has_args'         => true,
			'arg_count'        => 1,
			'untrusted_fields' => array( 'hook', 'schedule' ),
		), $data['items'][0] );
		self::assertSame( array( null, null, false, 0 ), array( $data['items'][1]['schedule'], $data['items'][1]['interval_seconds'], $data['items'][1]['has_args'], $data['items'][1]['arg_count'] ) );
		self::assertSame( array( 'hourly', 3600 ), array( $data['items'][2]['schedule'], $data['items'][2]['interval_seconds'] ) );
		self::assertSame( array( 'total_events' => 3, 'overdue_events' => 0, 'oldest_overdue_seconds' => null, 'next_event_in_seconds' => 100, 'truncated' => false ), $data['summary'] );
		self::assertSame( array( 1, 50, null ), array( $data['page'], $data['per_page'], $data['next_page'] ) );
	}

	public function test_overdue_threshold_boundary_is_more_than_sixty_seconds(): void {
		self::cron( array(
			self::NOW - 61  => array( 'late' => array( 'a' => self::event() ) ),
			self::NOW - 60  => array( 'edge' => array( 'a' => self::event() ) ),
			self::NOW       => array( 'now' => array( 'a' => self::event() ) ),
			self::NOW - 600 => array( 'older' => array( 'a' => self::event() ) ),
			self::NOW + 30  => array( 'soon' => array( 'a' => self::event() ) ),
		) );
		$data = self::status()['data'];
		$flags = array_column( $data['items'], 'overdue', 'hook' );
		self::assertSame( array( 'older' => true, 'late' => true, 'edge' => false, 'now' => false, 'soon' => false ), $flags );
		self::assertSame( -600, $data['items'][0]['seconds_until'] );
		self::assertSame( array( 'total_events' => 5, 'overdue_events' => 2, 'oldest_overdue_seconds' => 600, 'next_event_in_seconds' => 0, 'truncated' => false ), $data['summary'] );
	}

	public function test_overdue_only_filters_items_but_summary_covers_all_events(): void {
		self::cron( array(
			self::NOW - 500 => array( 'a_late' => array( 'x' => self::event() ), 'b_late' => array( 'x' => self::event() ) ),
			self::NOW + 500 => array( 'future' => array( 'x' => self::event() ) ),
		) );
		$data = self::status( array( 'overdue_only' => true, 'per_page' => 1 ) )['data'];
		self::assertSame( array( 'a_late' ), array_column( $data['items'], 'hook' ) );
		self::assertSame( 2, $data['next_page'] );
		self::assertSame( 3, $data['summary']['total_events'] );
		self::assertSame( 2, $data['summary']['overdue_events'] );
		self::assertSame( 500, $data['summary']['next_event_in_seconds'] );
		$page2 = self::status( array( 'overdue_only' => true, 'per_page' => 1, 'page' => 2 ) )['data'];
		self::assertSame( array( 'b_late' ), array_column( $page2['items'], 'hook' ) );
		self::assertNull( $page2['next_page'] );
		self::assertSame( array(), self::status( array( 'overdue_only' => false, 'page' => 3, 'per_page' => 2 ) )['data']['items'] );
		self::assertCount( 3, self::status( array( 'overdue_only' => false ) )['data']['items'] );
	}

	public function test_paging(): void {
		$cron = array();
		for ( $i = 1; $i <= 5; ++$i ) {
			$cron[ self::NOW + $i ] = array( 'hook_' . $i => array( 'k' => self::event() ) );
		}
		self::cron( $cron );
		$one = self::status( array( 'per_page' => 2, 'page' => 1 ) )['data'];
		self::assertSame( array( 'hook_1', 'hook_2' ), array_column( $one['items'], 'hook' ) );
		self::assertSame( array( 1, 2, 2 ), array( $one['page'], $one['per_page'], $one['next_page'] ) );
		$last = self::status( array( 'per_page' => 2, 'page' => 3 ) )['data'];
		self::assertSame( array( 'hook_5' ), array_column( $last['items'], 'hook' ) );
		self::assertNull( $last['next_page'] );
		$past = self::status( array( 'per_page' => 2, 'page' => 9 ) )['data'];
		self::assertSame( array(), $past['items'] );
		self::assertNull( $past['next_page'] );
		self::assertSame( 5, $past['summary']['total_events'] );
	}

	public function test_event_cap_sets_truncated_and_bounds_the_scan(): void {
		$cron = array();
		for ( $i = 0; $i < 5010; ++$i ) {
			$cron[ self::NOW + 1000 + $i ] = array( 'h' . $i => array( 'k' => self::event() ) );
		}
		// Unsorted late entry must not escape the cut-off: it sorts first and is kept.
		$cron = array( self::NOW - 1000 => array( 'earliest' => array( 'k' => self::event() ) ) ) + $cron;
		self::cron( array_reverse( $cron, true ) );
		$data = self::status( array( 'per_page' => 100 ) )['data'];
		self::assertSame( 5000, $data['summary']['total_events'] );
		self::assertTrue( $data['summary']['truncated'] );
		self::assertSame( 1, $data['summary']['overdue_events'] );
		self::assertSame( 'earliest', $data['items'][0]['hook'] );

		$cron_exact = array_slice( $cron, 0, 5000, true );
		self::cron( $cron_exact );
		$exact = self::status()['data'];
		self::assertSame( 5000, $exact['summary']['total_events'] );
		self::assertFalse( $exact['summary']['truncated'] );
	}

	public function test_config_constants_follow_core_interpretation(): void {
		self::assertSame( array( 'wp_cron_disabled' => false, 'alternate_cron' => false, 'lock_timeout_seconds' => 60 ), self::call( 'config_values', null, null, null ) );
		self::assertSame( array( 'wp_cron_disabled' => true, 'alternate_cron' => true, 'lock_timeout_seconds' => 120 ), self::call( 'config_values', true, 1, '120' ) );
		self::assertSame( array( 'wp_cron_disabled' => false, 'alternate_cron' => false, 'lock_timeout_seconds' => 60 ), self::call( 'config_values', 0, '', 'abc' ) );
		$config = self::status()['data']['config'];
		self::assertSame( array( 'wp_cron_disabled', 'alternate_cron', 'lock_timeout_seconds' ), array_keys( $config ) );
		self::assertIsBool( $config['wp_cron_disabled'] );
		self::assertIsInt( $config['lock_timeout_seconds'] );
	}

	public function test_hook_and_schedule_are_sanitized_bounded_and_marked(): void {
		self::cron( array( self::NOW + 5 => array(
			"<b>evil</b>\nhook" . str_repeat( 'x', 300 ) => array( 'k' => self::event( '<i>sched</i>' . str_repeat( 'y', 300 ), array(), 10 ) ),
			'12345'                                      => array( 'k' => self::event( '   ' ) ),
		) ) );
		$items = self::status()['data']['items'];
		self::assertSame( array( '12345', 200 ), array( $items[0]['hook'], mb_strlen( $items[1]['hook'] ) ) );
		self::assertStringStartsWith( 'evil hookxxx', $items[1]['hook'] );
		self::assertSame( 200, mb_strlen( $items[1]['schedule'] ) );
		self::assertStringStartsWith( 'schedyyy', $items[1]['schedule'] );
		self::assertNull( $items[0]['schedule'] );
		foreach ( $items as $item ) {
			self::assertSame( array( 'hook', 'schedule' ), $item['untrusted_fields'] );
		}
	}

	public function test_argument_values_never_appear_in_the_response(): void {
		self::cron( array( self::NOW - 200 => array(
			'send_report' => array(
				'sig1' => self::event( 'daily', array( 'admin@example.test', array( 'token' => 'SECRET-TOKEN-123', 'url' => 'https://hooks.example.test/?key=SECRET' ), 42 ), 86400 ),
			),
		) ) );
		foreach ( array( array(), array( 'overdue_only' => true ) ) as $input ) {
			$encoded = json_encode( self::status( $input ) );
			foreach ( array( 'SECRET', 'admin@example.test', 'example.test', 'token', '42', 'hooks.' ) as $needle ) {
				self::assertStringNotContainsStringIgnoringCase( $needle, str_replace( array( '"seconds_until":-200', '"interval_seconds":86400' ), '', $encoded ), $needle );
			}
			self::assertStringContainsString( '"has_args":true', $encoded );
			self::assertStringContainsString( '"arg_count":3', $encoded );
		}
	}

	public function test_execute_reads_only_the_cron_array_and_tolerates_odd_input(): void {
		self::cron( array( self::NOW + 10 => array( 'h' => array( 'k' => self::event() ) ) ) );
		self::assertTrue( Cron::execute( array() )['success'] );
		self::assertTrue( Cron::execute( null )['success'] );
		self::assertSame( 2, $GLOBALS['wstm_cron']['reads'] );
	}
}
