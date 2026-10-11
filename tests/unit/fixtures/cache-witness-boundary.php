<?php

namespace WstmCacheWitness;

use RuntimeException;
use WP_Error;
use Webmastery_MCP_Response;
use Wstm121QueryFailureWitness;

require_once dirname( __DIR__, 2 ) . '/e2e/query-failure-witness.php';
require_once __DIR__ . '/shared-helper-loader.php';
\wstm_test_load_shared_helpers( __NAMESPACE__ );

final class State {
	public static array $filters = array();
	public static array $records = array();
	public static int $calls = 0;
	public static int $generation = 0;
	public static int $prefix = 1;
	public static int $suffix = 2;
	public static string $fault = '';
}

function add_filter( $hook, $callback, $priority, $arguments = 1 ): void { State::$filters[$hook][$priority][] = $callback; }
function remove_filter( $hook, $callback, $priority ): void {
	foreach ( State::$filters[$hook][$priority] ?? array() as $key => $value ) {
		if ( $value === $callback ) { unset( State::$filters[$hook][$priority][$key] ); }
	}
}
function apply( string $hook, string $sql, $query = null ): string {
	$filters = State::$filters[$hook] ?? array();
	ksort( $filters );
	foreach ( $filters as $callbacks ) {
		foreach ( $callbacks as $callback ) { $sql = $callback( $sql, $query ); }
	}
	return $sql;
}
final class Database {
	public string $posts = 'owned_posts';
	public string $last_query = '';
	public string $last_error = '';
	public int $num_queries = 0;
	public $result = true;
	public bool $suppressed = false;

	public function suppress_errors( bool $value ): bool {
		$previous = $this->suppressed;
		$this->suppressed = $value;
		return $previous;
	}
	public function read( string $sql ): void {
		$sql = apply( 'query', $sql );
		if ( str_contains( $sql, 'wstm121_deliberate_missing_column' ) && 'nonexecuted' === State::$fault ) { return; }
		++$this->num_queries;
		$this->last_query = $sql;
		$this->last_error = '';
		$this->result = true;
		if ( str_contains( $sql, 'wstm121_deliberate_missing_column' ) && 'successful-injection' !== State::$fault ) {
			$this->last_error = 'Private native modeled database failure';
			$this->result = false;
			if ( 'foreign-query' === State::$fault ) { $this->last_query = 'SELECT foreign'; }
			if ( 'wrong-counter' === State::$fault ) { ++$this->num_queries; }
		}
	}
}
final class Ability {
	public function execute( array $input ): array {
		$db = $GLOBALS['wpdb'];
		$step = State::$calls++ % 3;
		$request = 'SELECT owned_posts.ID FROM owned_posts LIMIT 3';
		$query = new class {
			public function get( $key ) { return 'fields' === $key ? 'ids' : 3; }
		};
		if ( 2 !== $step ) { apply( 'posts_request', $request, $query ); }
		$method = new \ReflectionMethod( Webmastery_MCP_List_Query::class, 'database_read' );
		$method->setAccessible( true );
		$result = $method->invoke( null, static function () use ( $db, $request, $step ) {
			if ( 2 === $step ) { return; }
			if ( 1 === $step ) { $db->read( $request ); return; }
			if ( State::$calls <= 3 ) { $db->read( $request ); return; }
			for ( $i = 0; $i < State::$prefix; ++$i ) { $db->read( $request ); }
			$db->read( 'SELECT owned_posts.* FROM owned_posts WHERE ID IN (201,200)' );
			for ( $i = 0; $i < State::$suffix; ++$i ) { $db->read( 'SELECT * FROM owned_posts WHERE ID = 201 LIMIT 1' ); }
		} );
		if ( $result instanceof WP_Error ) { return Webmastery_MCP_Response::from_wp_error( $result ); }
		return array( 'success' => true, 'data' => array( 'items' => array( array( 'id' => 201 ), array( 'id' => 200 ) ), 'page' => 1, 'per_page' => 2, 'next_page' => 2 ) );
	}
}
function wp_get_ability( $name ) { return new Ability(); }
function wp_cache_delete( $id, $group ): void {}
function wp_cache_set_posts_last_changed(): void { ++State::$generation; }
function wp_cache_get_last_changed( $group ): string { return 'generation-' . State::$generation; }
function wstm121_snapshot(): array { return array( 'unchanged' => true ); }
function wstm121_require( $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function wp_json_encode( $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR ); }
function wstm121_json_write( $path, array $record ): void { State::$records[$path] = $record; }
final class Wstm121Verifier {
	public static int $calls = 0;
	public static function cache_probe( array $state, array $record ): void {
		++self::$calls;
		\Wstm121Verifier::cache_probe( $state, $record );
	}
}

$source = file_get_contents( dirname( __DIR__, 2 ) . '/e2e/bounded-list-benchmark.php' );
$start = strpos( $source, 'function wstm121_window_faults(' );
$end = strpos( $source, 'function wstm121_cleanup_readback(', $start );
$body = substr( $source, $start, $end - $start );
$body = str_replace( '__DIR__', var_export( dirname( __DIR__, 2 ) . '/e2e', true ), $body );
eval( 'namespace WstmCacheWitness; use \Throwable; use \Wstm121QueryFailureWitness; ' . $body );

function run( int $prefix = 1, int $suffix = 2, string $fault = '' ): array {
	State::$filters = State::$records = array();
	State::$calls = State::$generation = Wstm121Verifier::$calls = 0;
	State::$prefix = $prefix;
	State::$suffix = $suffix;
	State::$fault = $fault;
	$GLOBALS['wpdb'] = new Database();
	$state = array( 'catalog' => array( 'post' => array( 201,200,199 ) ) );
	wstm121_window_faults( $state, 'owned' );
	return State::$records['owned/window-cache-faults.json'];
}
