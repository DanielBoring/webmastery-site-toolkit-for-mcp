<?php
/**
 * Loads the production Get Cron Status ability into a namespace with a scripted cron array,
 * so it can be tested without colliding with global stubs from other suites.
 * State lives in $GLOBALS['wstm_cron']; CronTest resets it before each test.
 */

namespace WstmCron;

require_once __DIR__ . '/shared-helper-loader.php';
\wstm_test_load_shared_helpers( __NAMESPACE__ );

function current_user_can( $capability, ...$args ) {
	return in_array( $capability, $GLOBALS['wstm_cron']['caps'], true );
}

function _get_cron_array() {
	$GLOBALS['wstm_cron']['reads'] = ( $GLOBALS['wstm_cron']['reads'] ?? 0 ) + 1;
	return $GLOBALS['wstm_cron']['cron'];
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['wstm_cron']['registered'][ $name ] = $args;
}

foreach ( array( 'spawn_cron', 'wp_cron', 'wp_schedule_event', 'wp_schedule_single_event', 'wp_unschedule_event', 'wp_unschedule_hook', 'wp_clear_scheduled_hook', 'wp_reschedule_event', '_set_cron_array', 'wp_remote_get', 'wp_remote_post', 'update_option' ) as $forbidden ) {
	eval( 'namespace ' . __NAMESPACE__ . '; function ' . $forbidden . '( ...$args ) { throw new \LogicException( "Cron status must not call ' . $forbidden . '." ); }' );
}

$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-cron.php' );
eval( 'namespace ' . __NAMESPACE__ . '; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) );
