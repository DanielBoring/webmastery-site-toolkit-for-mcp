<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Cron {

	/** Events inspected per call; summary and items are computed over at most this many. */
	private const EVENT_LIMIT = 5000;

	/** An event counts as overdue only when it is more than this many seconds past due. */
	private const OVERDUE_GRACE = 60;

	private const TEXT_LIMIT = 200;

	/**
	 * Register the abilities.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-cron-status', [
			'label'               => 'Get Cron Status',
			'description'         => 'Read-only WP-Cron inspection: cron configuration constants, a summary of scheduled events (total, overdue, oldest overdue, seconds to the next event) and a paged list of scheduled event occurrences with hook, next run, schedule, interval and argument count. An event is overdue when it is more than 60 seconds past due. Event argument values are never returned. Nothing is run, rescheduled or written. At most 5000 events are inspected (summary.truncated reports a cut-off).',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'default'    => [],
				'properties' => [
					'per_page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
					'page'         => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
					'overdue_only' => [ 'type' => 'boolean', 'default' => false ],
				],
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => Webmastery_MCP_Permissions::cap( 'manage_options' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	/**
	 * Report scheduled event and cron configuration status.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = [] ) {
		return self::build( is_array( $input ) ? $input : [], time() );
	}

	/**
	 * Build the response for a given "now" (injectable for tests).
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @param int                  $now   Current Unix timestamp.
	 *
	 * @return array<string, mixed>
	 */
	private static function build( array $input, int $now ): array {
		$per_page     = Webmastery_MCP_Input::per_page( $input, 50 );
		$page         = Webmastery_MCP_Input::page( $input );
		$overdue_only = true === ( $input['overdue_only'] ?? false );

		$collected = self::collect_events( _get_cron_array(), $now );
		$events    = $collected['events'];

		$overdue        = 0;
		$oldest_overdue = null;
		$next_in        = null;
		foreach ( $events as $event ) {
			if ( $event['overdue'] ) {
				++$overdue;
				$late           = -$event['seconds_until'];
				$oldest_overdue = null === $oldest_overdue ? $late : max( $oldest_overdue, $late );
			} else {
				$until   = max( 0, $event['seconds_until'] );
				$next_in = null === $next_in ? $until : min( $next_in, $until );
			}
		}

		$listed = $overdue_only ? array_values( array_filter( $events, static fn( $event ) => $event['overdue'] ) ) : $events;
		$items  = [];
		foreach ( array_slice( $listed, ( $page - 1 ) * $per_page, $per_page ) as $event ) {
			$items[] = Webmastery_MCP_Untrusted::mark( $event, [ 'hook', 'schedule' ] );
		}

		return Webmastery_MCP_Response::ok( [
			'config'    => self::config_values(
				defined( 'DISABLE_WP_CRON' ) ? constant( 'DISABLE_WP_CRON' ) : null,
				defined( 'ALTERNATE_WP_CRON' ) ? constant( 'ALTERNATE_WP_CRON' ) : null,
				defined( 'WP_CRON_LOCK_TIMEOUT' ) ? constant( 'WP_CRON_LOCK_TIMEOUT' ) : null
			),
			'summary'   => [
				'total_events'           => count( $events ),
				'overdue_events'         => $overdue,
				'oldest_overdue_seconds' => $oldest_overdue,
				'next_event_in_seconds'  => $next_in,
				'truncated'              => $collected['truncated'],
			],
			'items'     => $items,
			'page'      => $page,
			'per_page'  => $per_page,
			'next_page' => count( $listed ) > $page * $per_page ? $page + 1 : null,
		] );
	}

	/**
	 * Interpret the cron constants the way core does (null means undefined).
	 *
	 * @param mixed $disabled  DISABLE_WP_CRON value.
	 * @param mixed $alternate ALTERNATE_WP_CRON value.
	 * @param mixed $lock      WP_CRON_LOCK_TIMEOUT value.
	 *
	 * @return array<string, mixed>
	 */
	private static function config_values( $disabled, $alternate, $lock ): array {
		return [
			'wp_cron_disabled'     => (bool) $disabled,
			'alternate_cron'       => (bool) $alternate,
			'lock_timeout_seconds' => is_numeric( $lock ) ? (int) $lock : 60,
		];
	}

	/**
	 * Flatten the cron array into sorted event records (never argument values).
	 *
	 * @param mixed $cron Raw _get_cron_array() result (may be corrupt).
	 * @param int   $now  Current Unix timestamp.
	 * @return array{events: array<int, array<string, mixed>>, truncated: bool}
	 */
	private static function collect_events( $cron, int $now ): array {
		$events    = [];
		$truncated = false;
		if ( ! is_array( $cron ) ) {
			return [ 'events' => $events, 'truncated' => $truncated ];
		}

		// Core stores the cron array sorted by timestamp; do not rely on it for the cut-off.
		ksort( $cron, SORT_NUMERIC );

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) || $timestamp <= 0 || ! is_array( $hooks ) ) {
				continue;
			}
			$seconds_until = $timestamp - $now;
			foreach ( $hooks as $hook => $signatures ) {
				if ( ! is_array( $signatures ) ) {
					continue;
				}
				foreach ( $signatures as $event ) {
					if ( ! is_array( $event ) ) {
						continue;
					}
					if ( count( $events ) >= self::EVENT_LIMIT ) {
						$truncated = true;
						break 3;
					}
					$args      = $event['args'] ?? [];
					$arg_count = is_array( $args ) ? count( $args ) : 0;
					$schedule  = self::text( $event['schedule'] ?? null );
					$interval  = $event['interval'] ?? null;
					$events[]  = [
						'hook'             => self::text( (string) $hook ) ?? '',
						'next_run'         => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
						'seconds_until'    => $seconds_until,
						'overdue'          => $seconds_until < -self::OVERDUE_GRACE,
						'schedule'         => $schedule,
						'interval_seconds' => null !== $schedule && is_int( $interval ) && $interval > 0 ? $interval : null,
						'has_args'         => $arg_count > 0,
						'arg_count'        => $arg_count,
						'timestamp'        => $timestamp,
					];
				}
			}
		}

		// Stable order: timestamp, then hook name (ties keep cron array order).
		usort(
			$events,
			static fn( $a, $b ) => [ $a['timestamp'], $a['hook'] ] <=> [ $b['timestamp'], $b['hook'] ]
		);
		foreach ( $events as &$event ) {
			unset( $event['timestamp'] );
		}
		unset( $event );

		return [ 'events' => $events, 'truncated' => $truncated ];
	}

	/**
	 * Plugin-supplied name to a bounded plain string, or null when empty or not a string.
	 *
	 * @param mixed $value Hook or schedule name.
	 */
	private static function text( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$text = trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', wp_strip_all_tags( $value ) ) ?? '' );

		return '' === $text ? null : mb_substr( $text, 0, self::TEXT_LIMIT );
	}
}
