<?php

declare(strict_types=1);

namespace WstmReferenceBoundary;

use RuntimeException;
use Throwable;

final class State {
	public static array $filters = array();
	public static array $early = array();
	public static array $executed = array();
	public static array $writes = array();
	public static array $state = array();
	public static string $fault = '';
	public static int $observer_registrations = 0;
	public static int $verifier_calls = 0;
	public static array $verified = array();
}

function add_filter( string $hook, callable $callback, int $priority ): void {
	if ( 'query' === $hook && PHP_INT_MAX === $priority ) {
		++State::$observer_registrations;
		if ( 1 === State::$observer_registrations && 'missing-observer' === State::$fault ) { return; }
		if ( 2 === State::$observer_registrations && 'conflicting-boundary' === State::$fault ) {
			State::$filters[$hook][$priority][] = static fn( $sql ) => $sql . ' /* foreign */';
		}
	}
	State::$filters[$hook][$priority][] = $callback;
}

function remove_filter( string $hook, callable $callback, int $priority ): void {
	foreach ( State::$filters[$hook][$priority] ?? array() as $index => $candidate ) {
		if ( $candidate === $callback ) { unset( State::$filters[$hook][$priority][$index] ); }
	}
}

function apply_query( string $sql ): string {
	$filters = State::$filters['query'] ?? array();
	ksort( $filters, SORT_NUMERIC );
	foreach ( $filters as $callbacks ) {
		foreach ( $callbacks as $callback ) { $sql = $callback( $sql ); }
	}
	State::$executed[] = $sql;
	return $sql;
}

final class Database {
	public string $posts = 'owned_posts';
	public bool $suppressed = false;
	private string $placeholder;

	public function __construct() {
		$this->placeholder = '{' . str_repeat( 'a', 64 ) . '}';
		add_filter( 'query', function ( $sql ) { State::$early[] = $sql; return $sql; }, PHP_INT_MIN );
		// Native placeholder prior art removes its own token at priority0;
		// this is a native API double, not evidence normalization.
		add_filter( 'query', array( $this, 'remove_placeholder_escape' ), 0 );
	}

	public function remove_placeholder_escape( string $sql ): string {
		return str_replace( $this->placeholder, '%', $sql );
	}

	public function suppress_errors( bool $value ): bool {
		$previous = $this->suppressed;
		$this->suppressed = $value;
		return $previous;
	}

	public function read( string $phase ): bool {
		$sql = 'thumbnail' === $phase
			? "SELECT meta_value FROM owned_postmeta WHERE meta_key='_thumbnail_id'"
			: "SELECT MAX(post_content LIKE '" . $this->placeholder . "literal" . $this->placeholder . "') FROM owned_posts";
		$actual = apply_query( $sql );
		return false === strpos( $actual, 'wstm121_deliberate_missing_column' );
	}
}

final class Error {
	public function get_error_code(): string { return 'content_hygiene_query_failed'; }
	public function get_error_message(): string { return 'Owned reference read failed.'; }
	public function get_error_data(): array { return array(); }
}

function is_wp_error( $value ): bool { return $value instanceof Error; }
function current_filter(): string { return 'query'; }
function wp_json_encode( $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR ); }
function wstm121_require( $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function wstm121_snapshot(): array { return array( 'unchanged' => true ); }
function wstm121_mutation_hooks(): array { return array( 'save_post', 'deleted_post' ); }
function wstm121_json_write( string $path, array $record ): void { State::$writes[$path] = $record; }
function truth( array $ids ): array {
	$result = array();
	foreach ( $ids as $id ) { $result[$id] = in_array( $id, State::$state['referenced'], true ); }
	return $result;
}
function wstm121_legacy_reference( int $id ): bool {
	$GLOBALS['wpdb']->read( 'content' );
	return truth( array( $id ) )[$id];
}

final class Webmastery_MCP_Content_Hygiene {
	public static function is_attachment_referenced( int $id ): bool {
		$GLOBALS['wpdb']->read( 'content' );
		return truth( array( $id ) )[$id];
	}
	public static function attachments_referenced( array $ids ) {
		foreach ( array( 'thumbnail', 'content' ) as $phase ) {
			if ( ! $GLOBALS['wpdb']->read( $phase ) ) { return new Error(); }
		}
		return truth( $ids );
	}
}

function wp_get_ability( string $name ): object {
	if ( 'webmastery-site-toolkit-for-mcp/delete-media' !== $name ) { throw new RuntimeException( 'Unexpected ability.' ); }
	return new class() {
		public function execute( array $input ): array {
			$result = Webmastery_MCP_Content_Hygiene::attachments_referenced( array( $input['media_id'] ) );
			if ( ! is_wp_error( $result ) || 'forced-success' === State::$fault ) { return array( 'success' => true ); }
			return array( 'success' => false, 'error' => array( 'code' => 'upstream_failed', 'reason' => $result->get_error_code() ) );
		}
	};
}

final class Wstm121Verifier {
	public static function reference_probe( array $state, array $record ): void {
		++State::$verifier_calls;
		\Wstm121Verifier::reference_probe( $state, $record );
		State::$verified = $record;
	}
}

$source = file_get_contents( dirname( __DIR__, 2 ) . '/e2e/bounded-list-benchmark.php' );
$start = strpos( $source, 'function wstm121_parity(' );
$end = strpos( $source, "\nfunction ", $start + 1 );
if ( false === $start || false === $end ) { throw new RuntimeException( 'Actual reference-probe function is unavailable.' ); }
$body = substr( $source, $start, $end - $start );
$body = str_replace( "require_once __DIR__ . '/bounded-list-verifier.php';", '', $body );
eval( 'namespace WstmReferenceBoundary; use \RuntimeException; use \Throwable;' . $body );
$early = str_replace(
	array( 'function wstm121_parity(', "add_filter( 'query', \$queries, PHP_INT_MAX );",
		"remove_filter( 'query', \$queries, PHP_INT_MAX );",
		"\t\t\t\t\twstm121_require( \$sql === end( \$evidence['sql'] ), 'Reference injector original differs from its boundary observation.' );\n" ),
	array( 'function wstm121_parity_early(', "add_filter( 'query', \$queries, PHP_INT_MIN );",
		"remove_filter( 'query', \$queries, PHP_INT_MIN );", '' ),
	$body
);
eval( 'namespace WstmReferenceBoundary; use \RuntimeException; use \Throwable;' . $early );
unset( $source, $start, $end, $body, $early );
