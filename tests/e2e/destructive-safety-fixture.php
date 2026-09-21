<?php
/**
 * Opt-in, disposable runtime instrumentation. Not a production plugin.
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/metadata-batch-fixture.php';

function wstm116_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wstm116_hooks(): array {
	return array_unique( array_merge( wstm110_batch_mutation_hooks(), array(
		'pre_trash_post', 'wp_trash_post', 'trashed_post', 'pre_delete_attachment', 'delete_attachment',
		'pre_delete_term', 'delete_term', 'deleted_term', 'add_term_metadata', 'update_term_metadata', 'delete_term_metadata',
		'pre_clear_scheduled_hook', 'pre_unschedule_hook',
	) ) );
}

function wstm116_observe( callable $record ): Closure {
	$observer = static function ( $value = null ) use ( $record ) {
		$record( current_filter() );
		return $value;
	};
	foreach ( wstm116_hooks() as $hook ) {
		add_filter( $hook, $observer, PHP_INT_MIN );
	}
	return $observer;
}

function wstm116_unobserve( Closure $observer ): void {
	foreach ( wstm116_hooks() as $hook ) {
		remove_filter( $hook, $observer, PHP_INT_MIN );
	}
}

function wstm116_faults( array $config ): Closure {
	global $wpdb;
	$old_suppress = $wpdb->suppress_errors( true );
	$cap_filter = static function ( $caps, $cap, $user, $args ) use ( $config ) {
		if ( 'deny_object' === ( $config['mode'] ?? '' ) && in_array( $cap, array( 'edit_post', 'delete_post', 'delete_term' ), true )
			&& (int) ( $args[0] ?? 0 ) === (int) ( $config['id'] ?? 0 ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	};
	$query_filter = static function ( $query ) use ( $config ) {
		if ( 'query_failure' === ( $config['mode'] ?? '' ) && 0 === strpos( $query, 'SELECT COUNT(1)' )
			&& ( false !== strpos( $query, '_thumbnail_id' ) || false !== strpos( $query, 'post_content LIKE' ) ) ) {
			return 'SELECT WSTM116_PRIVATE_SQL_FAILURE FROM';
		}
		return $query;
	};
	add_filter( 'map_meta_cap', $cap_filter, PHP_INT_MAX, 4 );
	add_filter( 'query', $query_filter, PHP_INT_MAX );
	return static function () use ( $cap_filter, $query_filter, $old_suppress ): void {
		global $wpdb;
		remove_filter( 'map_meta_cap', $cap_filter, PHP_INT_MAX );
		remove_filter( 'query', $query_filter, PHP_INT_MAX );
		$wpdb->suppress_errors( $old_suppress );
	};
}

function wstm116_snapshot( array $files ): array {
	global $wpdb;
	$result = wstm110_batch_snapshot();
	$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->termmeta} ORDER BY meta_id", ARRAY_A );
	wstm116_require( '' === $wpdb->last_error && is_array( $rows ), 'Cannot snapshot term metadata.' );
	$result['termmeta'] = array( 'count' => count( $rows ), 'sha256' => hash( 'sha256', serialize( $rows ) ) );
	foreach ( $files as $file ) {
		$result['files'][ $file ] = is_file( $file ) ? hash_file( 'sha256', $file ) : null;
	}
	return $result;
}

function wstm116_http_observer( $result, $server, $request ) {
	if ( ! defined( 'WSTM116_DISPOSABLE_RUNTIME' ) || true !== WSTM116_DISPOSABLE_RUNTIME ) {
		return $result;
	}
	$config = get_option( 'wstm116_control', array() );
	if ( empty( $config['active'] ) || get_current_user_id() !== (int) ( $config['user_id'] ?? 0 )
		|| ! in_array( $request->get_route(), array( '/mcp/mcp-adapter-default-server', '/wstm118/tools' ), true )
		|| 'tools/call' !== ( $request->get_json_params()['method'] ?? null ) ) {
		return $result;
	}
	$events = array();
	$observer = wstm116_observe( static function ( $hook ) use ( &$events ): void { $events[] = $hook; } );
	$undo = wstm116_faults( $config );
	$finish = null;
	$finish = static function ( $response, $response_server, $response_request ) use ( $request, $config, &$events, $observer, $undo, &$finish ) {
		if ( $request === $response_request ) {
			$undo();
			wstm116_unobserve( $observer );
			remove_filter( 'rest_post_dispatch', $finish, PHP_INT_MAX );
			update_option( 'wstm116_control', array_merge( $config, array( 'active' => false, 'observed' => $config['nonce'], 'events' => $events, 'trash_days' => EMPTY_TRASH_DAYS, 'stage_owner' => WSTM116_STAGE_TOKEN ) ), false );
		}
		return $response;
	};
	add_filter( 'rest_post_dispatch', $finish, PHP_INT_MAX, 3 );
	return $result;
}
