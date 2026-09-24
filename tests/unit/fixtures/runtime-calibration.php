<?php

namespace Wstm121Runtime;

use RuntimeException;

require_once dirname( __DIR__, 2 ) . '/e2e/error-contract-assertions.php';

final class State {
	public static array $filters = array();
	public static int $actor = 2;
	public static string $hook = '';
	public static string $owner = 'owned-fixture';
	public static array $persisted = array( 'retained' => true );
	public static $ability;
	public static array $abilities = array();
	public static array $deleted = array();
}

function section( string $source, string $start, string $end ): string {
	$first = strpos( $source, $start );
	$last = strpos( $source, $end, false === $first ? 0 : $first );
	if ( false === $first || false === $last || $last <= $first ) {
		throw new RuntimeException( 'Missing runtime fixture source section.' );
	}
	return substr( $source, $first, $last - $first );
}

function load_taxonomy_helper( ?string $source = null ): void {
	$source = $source ?? file_get_contents( dirname( __DIR__, 2 ) . '/e2e/taxonomy-write-runner.php' );
	eval( 'namespace Wstm121Runtime; ' . section( $source, 'function wstm117_delete_missing_pair(', 'function wstm117_run_taxonomy_tests(' ) );
}

function taxonomy_matrix( $ability, $execute, $taxonomy, $slug, $wrong_id ): array {
	$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/e2e/taxonomy-write-runner.php' ) );
	$body = section( $source, "\t\t\t\t\tforeach ( array( 'missing' =>", "\n\t\t\t\t}\n\t\t\t}\n" );
	$action = 'delete';
	$records = array();
	$record = static function ( $label, $passed, $evidence ) use ( &$records ): void { $records[] = compact( 'label', 'passed', 'evidence' ); };
	eval( 'namespace Wstm121Runtime; ' . $body );
	return $records;
}

function load_fault_helper( ?string $source = null ): void {
	$source = $source ?? file_get_contents( dirname( __DIR__, 2 ) . '/e2e/destructive-safety-fixture.php' );
	eval( 'namespace Wstm121Runtime; use \Closure; ' . section( $source, 'function wstm116_reference_fault_sql(', 'function wstm116_snapshot(' ) );
}

function load_media(): void {
	foreach ( array( 'media', 'content-hygiene' ) as $name ) {
		$source = file_get_contents( dirname( __DIR__, 3 ) . "/includes/class-$name.php" );
		eval( 'namespace Wstm121Runtime; use \Webmastery_MCP_Response; ' . substr( $source, 5 ) );
	}
	$register = new \ReflectionMethod( Webmastery_MCP_Media::class, 'register_delete' );
	$register->setAccessible( true );
	$register->invoke( null );
}

function wp_register_ability( $name, $args ) {
	State::$abilities[$name] = \Webmastery_MCP_Input::register_args( \Webmastery_MCP_Response::register_args( $args, $name ), $name );
}
function wp_delete_attachment( $id, $force ) {
	State::$deleted[] = $id;
	State::$persisted['deleted'] = $id;
	apply( 'pre_delete_attachment', null );
	return (object) array( 'ID' => $id );
}

function invoker( $ability, $evidence ): \Closure {
	State::$ability = $ability;
	$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/e2e/destructive-safety-runner.php' ) );
	$body = section( $source, "\t\$invoke = static function", "\t\$inputs = array(" );
	$boundary = 'ability';
	$run = State::$owner;
	$users = array( 'author' => 2 );
	$files = $transports = array();
	eval( 'namespace Wstm121Runtime; use \Webmastery_MCP_Response; use \ReflectionProperty; use \WP_Ability; ' . $body );
	return $invoke;
}

function wp_get_ability( $name ) { return State::$ability; }
function wstm116_snapshot( $files ) { return State::$persisted; }
function wstm116_observe( $callback ) {
	$observer = static function ( $value ) use ( $callback ) { $callback( current_filter() ); return $value; };
	add_filter( 'pre_delete_attachment', $observer, PHP_INT_MIN );
	return $observer;
}
function wstm116_unobserve( $observer ) { remove_filter( 'pre_delete_attachment', $observer, PHP_INT_MIN ); }

function wstm116_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { State::$filters[ $name ][ $priority ][] = $callback; }
function remove_filter( $name, $callback, $priority = 10 ) {
	foreach ( State::$filters[ $name ][ $priority ] ?? array() as $key => $value ) {
		if ( $callback === $value ) { unset( State::$filters[ $name ][ $priority ][ $key ] ); }
	}
}
function add_action( $name, $callback, $priority = 10 ) { add_filter( $name, $callback, $priority ); }
function remove_action( $name, $callback, $priority = 10 ) { remove_filter( $name, $callback, $priority ); }
function apply( $name, $value, ...$args ) {
	State::$hook = $name;
	$priorities = State::$filters[ $name ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) { $value = $callback( $value, ...$args ); }
	}
	return $value;
}
function current_filter() { return State::$hook; }
function get_current_user_id() { return State::$actor; }
function wp_set_current_user( $id ) { State::$actor = $id; }
function get_taxonomy( $taxonomy ) { return (object) array( 'cap' => (object) array( 'delete_terms' => 'manage_categories' ) ); }
function current_user_can( $capability, ...$args ) {
	$caps = apply( 'map_meta_cap', array( $capability ), $capability, State::$actor, $args );
	return \Wstm126Destructive\Probe::$allowed && ! in_array( 'do_not_allow', $caps, true );
}
function wstm117_term_snapshot() { return array( 'state' => \Wstm126Destructive\Probe::snapshot() ); }
function get_post( $id ) {
	$id = is_object( $id ) ? $id->ID : $id;
	return in_array( $id, State::$deleted, true ) ? null : (object) array( 'ID' => $id, 'post_type' => 'attachment', 'guid' => "https://old.test/{$id}_%.png" );
}
function get_post_meta( $id, $key, $single ) { return 'wstm116_sentinel' === $key ? State::$owner : null; }
function wp_get_attachment_url( $id ) { return "https://current.test/{$id}_%.png"; }
function update_post_meta( ...$args ) {}
function wp_update_post( ...$args ) {}

/** Evaluate only the runner's case construction, never its bootstrap or cleanup. */
function matrix( string $boundary, ?callable $invoke = null, ?string $source = null ): array {
	$source = str_replace( "\r\n", "\n", $source ?? file_get_contents( dirname( __DIR__, 2 ) . '/e2e/destructive-safety-runner.php' ) );
	$body = section( $source, "\t\$inputs = array(", "\n} catch ( Throwable \$error ) {" );
	$records = array();
	$record = static function ( $label, $callback ) use ( &$records ): void {
		wstm116_require( ! isset( $records[ $label ] ), 'Duplicate runtime label.' );
		$records[ $label ] = $callback;
	};
	$draft = 42;
	$page = 44;
	$media = 46;
	$terms = array( 'category' => 51, 'post_tag' => 52 );
	$users = array( 'administrator' => 1 );
	$run = State::$owner;
	$make_post = static fn( ...$args ) => 42;
	$make_media = static fn() => 46;
	eval( 'namespace Wstm121Runtime; ' . $body );
	return $records;
}

/** Independent original label inventory: no outcome-derived golden regeneration. */
function original_labels( string $boundary ): array {
	$labels = array();
	$bulk = array( 'bulk-trash-posts', 'bulk-publish-posts' );
	foreach ( array_merge( $bulk, array( 'delete-media', 'delete-category', 'delete-tag' ) ) as $slug ) {
		foreach ( array( 'missing', 'false', 'string', 'number', 'null' ) as $value ) {
			$labels[] = "$slug confirmation $value";
			$labels[] = "$slug administrator confirmation $value";
			if ( in_array( $slug, $bulk, true ) ) { $labels[] = "$slug preview confirmation $value"; }
		}
		$labels[] = $slug . ( 'direct' === $boundary && in_array( $slug, $bulk, true ) ? ' direct subscriber inline denial' : ' subscriber permission' );
	}
	foreach ( array( 'bulk-trash-posts' => 'dry_run', 'bulk-publish-posts' => 'dry_run', 'delete-media' => 'force' ) as $slug => $flag ) {
		foreach ( range( 0, 5 ) as $index ) { $labels[] = "$slug strict $flag $index"; }
	}
	foreach ( $bulk as $slug ) {
		foreach ( array( 0, 1 ) as $raw ) {
			foreach ( array( 0, 1 ) as $preview ) { $labels[] = "$slug 101 raw $raw preview $preview"; }
		}
		foreach ( array( 'accepts 100 unique', 'accepts 100 duplicate', 'object permission preview', 'all failed preview equals execution', 'mixed preview equals execution' ) as $suffix ) {
			$labels[] = "$slug $suffix";
		}
	}
	foreach ( array( 'featured', 'url', 'guid', 'unused' ) as $reference ) {
		if ( 'unused' !== $reference ) { $labels[] = "media $reference refuses known reference"; }
		$labels[] = "media $reference force cannot bypass object denial";
		foreach ( array( 0, 1 ) as $force ) { $labels[] = "media $reference scan failure force $force"; }
		$labels[] = "media $reference truthful deletion";
	}
	foreach ( array( 'category', 'tag' ) as $slug ) {
		$labels[] = "delete $slug final term denial";
		$labels[] = "delete $slug confirmed success";
	}
	return $labels;
}

final class Database {
	public string $postmeta = 'owned_postmeta';
	public string $posts = 'owned_posts';
	public int $num_queries = 0;
	public bool $suppressed = false;
	public string $last_error = '';
	public array $queries = array();
	public array $prepared_queries = array();
	public array $thumbnail_hits = array();
	public bool $content_hit = false;
	public function suppress_errors( $value ) { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function placeholder_escape(): string {
		$callback = array( $this, 'remove_placeholder_escape' );
		if ( ! in_array( $callback, State::$filters['query'][0] ?? array(), true ) ) {
			add_filter( 'query', $callback, 0 );
		}
		return '{wstm121-percent-placeholder}';
	}
	public function add_placeholder_escape( $sql ): string {
		return str_replace( '%', $this->placeholder_escape(), $sql );
	}
	public function remove_placeholder_escape( $sql ): string {
		return str_replace( $this->placeholder_escape(), '%', $sql );
	}
	public function prepare( $sql, $args ) {
		foreach ( $args as $value ) {
			$sql = preg_replace_callback( '/%s/', static fn() => "'" . addslashes( $value ) . "'", $sql, 1 );
		}
		return $this->add_placeholder_escape( $sql );
	}
	private function query( $sql ): bool {
		$this->num_queries++;
		$this->prepared_queries[] = $sql;
		$unescaped = $this->remove_placeholder_escape( $sql );
		$filtered = apply( 'query', $sql );
		$this->queries[] = array( $unescaped, $filtered );
		$this->last_error = $unescaped === $filtered ? '' : 'WSTM116_PRIVATE_SQL_FAILURE';
		return '' === $this->last_error;
	}
	public function get_col( $sql ) {
		return $this->query( $sql ) ? $this->thumbnail_hits : null;
	}
	public function get_row( $sql, $format ) {
		if ( ! $this->query( $sql ) ) { return null; }
		$row = array();
		foreach ( range( 0, substr_count( $sql, ' AS ref_' ) - 1 ) as $index ) {
			$row["ref_$index"] = (int) $this->content_hit;
		}
		return $row;
	}
}
