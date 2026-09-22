<?php

declare(strict_types=1);

namespace Wstm113Calibration;

use ReflectionMethod;
use RuntimeException;
use stdClass;
use Webmastery_MCP_Input;
use Webmastery_MCP_Response;
use WP_Error;

const BASELINE = '735d31df97ed91af34eec9439a3dbea22ea8f5d8';
const ORIGINAL_SHA256 = 'e9ced6e22fd1a4347565024a3a903a27d3184a86f2f674bb0d7723bcd2328ff3';
const LEDGER_SHA256 = '30cad4e6931b82edb1ccf63c2fc429b02a1e9ccb79306d38b37a09d497a0a8a5';

function json( $value ): string {
	return json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . "\n";
}

function normalized( string $source ): string {
	return str_replace( "\r\n", "\n", $source );
}

function root(): string {
	return dirname( __DIR__, 3 );
}

function assert_seal( stdClass $ledger ): void {
	if ( ! hash_equals( LEDGER_SHA256, hash( 'sha256', json( $ledger ) ) ) ) {
		throw new RuntimeException( 'Scheduling ledger canonical fingerprint mismatch.' );
	}
}

function load_ledger( ?string $source = null ): stdClass {
	$ledger = json_decode( $source ?? file_get_contents( __DIR__ . '/scheduling-calibration-ledger.json' ), false, 512, JSON_THROW_ON_ERROR );
	if ( ! $ledger instanceof stdClass ) {
		throw new RuntimeException( 'Scheduling ledger must be an object.' );
	}
	assert_seal( $ledger );
	return $ledger;
}

function assert_sources( stdClass $ledger ): void {
	assert_seal( $ledger );
	foreach ( $ledger->production as $path => $binding ) {
		$source = normalized( file_get_contents( root() . '/' . $path ) );
		if ( $binding->baseline_sha256 !== $binding->current_sha256
			|| ! hash_equals( $binding->current_sha256, hash( 'sha256', $source ) )
			|| ! hash_equals( $binding->git_blob, sha1( 'blob ' . strlen( $source ) . "\0" . $source ) ) ) {
			throw new RuntimeException( 'Scheduling production source binding changed: ' . $path );
		}
	}
}

function between( string $source, string $start, string $end ): string {
	$a = strpos( $source, $start );
	$b = false === $a ? false : strpos( $source, $end, $a );
	if ( false === $a || false === $b ) {
		throw new RuntimeException( 'Scheduling source anchor changed: ' . $start );
	}
	return substr( $source, $a, $b - $a );
}

final class Probe {
	public const NOW = 1798761600;
	public static bool $inventory = true;
	public static array $posts = array();
	public static array $meta = array();
	public static array $events = array();
	public static array $abilities = array();
	public static array $raw = array();
	public static array $hooks = array();
	public static array $observed = array();
	public static int $next_id = 1000;
	public static int $actor = 0;
	public static bool $allowed = true;
	public static string $timezone = 'UTC';

	public static function reset(): void {
		self::$inventory = true;
		self::$posts = self::$meta = self::$events = self::$hooks = self::$observed = array();
		self::$next_id = 1000;
		self::$actor = 0;
		self::$allowed = true;
		self::$timezone = 'UTC';
	}

	public static function snapshot(): string {
		return json( array( 'posts' => self::$posts, 'postmeta' => self::$meta, 'term_relationships' => array(), 'cron' => array() ) );
	}

	public static function fixture( stdClass $row ): void {
		self::reset();
		self::$inventory = false;
		self::$actor = $row->actor;
		self::$timezone = $row->timezone;
		self::$posts[ $row->id ] = (array) $row->post;
		self::$meta[ $row->id ] = (array) $row->metadata;
	}

	public static function load( stdClass $ledger ): void {
		assert_sources( $ledger );
		require_once root() . '/includes/class-input.php';
		require_once root() . '/includes/class-post-scheduling.php';
		if ( class_exists( __NAMESPACE__ . '\\Webmastery_MCP_Posts', false ) ) {
			return;
		}
		foreach ( array( 'class-posts.php', 'class-custom-post-types.php' ) as $file ) {
			$source = normalized( file_get_contents( root() . '/includes/' . $file ) );
			eval( 'namespace ' . __NAMESPACE__ . '; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) );
		}
		$method = new ReflectionMethod( Webmastery_MCP_Posts::class, 'register_post_type' );
		foreach ( array( 'post', 'page' ) as $type ) {
			$method->invoke( null, $type );
		}
		$method = new ReflectionMethod( Webmastery_MCP_Custom_Post_Types::class, 'register_custom_post_type' );
		foreach ( array( 'mcp_book', 'mcp_case_study' ) as $type ) {
			$method->invoke( null, get_post_type_object( $type ), str_replace( '_', '-', $type ) );
		}
	}
}

final class Database {
	public string $posts = 'posts';

	public function update( $table, $values, $where ): void {
		if ( ! Probe::$inventory || 'posts' !== $table ) {
			throw new RuntimeException( 'Unexpected scheduling database write.' );
		}
		Probe::$posts[ $where['ID'] ] = array_replace( Probe::$posts[ $where['ID'] ], $values );
	}
}

function time(): int {
	return Probe::NOW;
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function get_user_by( $field, $login ) {
	$users = array( 'editor_test' => 21, 'book_manager_test' => 22, 'case_manager_test' => 23 );
	if ( 'login' !== $field || ! isset( $users[ $login ] ) ) {
		throw new RuntimeException( 'Unexpected scheduling actor: ' . $login );
	}
	return (object) array( 'ID' => $users[ $login ], 'user_login' => $login );
}

function wp_set_current_user( $id ): void {
	Probe::$actor = $id;
}

function get_current_user_id(): int {
	return Probe::$actor;
}

function update_option( $key, $value ): void {
	if ( 'timezone_string' !== $key ) {
		throw new RuntimeException( 'Unexpected scheduling option.' );
	}
	Probe::$timezone = $value;
}

function get_terms( $args ): array {
	if ( ! in_array( $args['taxonomy'], array( 'category', 'mcp_genre' ), true ) ) {
		throw new RuntimeException( 'Unexpected scheduling taxonomy.' );
	}
	return array( '17' );
}

function wp_insert_post( $args, $error = false ): int {
	if ( ! Probe::$inventory ) {
		throw new RuntimeException( 'Scheduling calibration attempted a post insert.' );
	}
	$id = Probe::$next_id++;
	Probe::$posts[ $id ] = array_merge( array( 'ID' => $id ), $args );
	return $id;
}

function clean_post_cache( $id ): void {
	if ( ! isset( Probe::$posts[ $id ] ) ) {
		throw new RuntimeException( 'Unknown fixture cache target.' );
	}
}

function update_post_meta( $id, $key, $value ): void {
	if ( ! Probe::$inventory ) {
		throw new RuntimeException( 'Scheduling calibration attempted a metadata write.' );
	}
	Probe::$meta[ $id ][ $key ] = $value;
}

function get_post_meta( $id, $key, $single = false ) {
	return Probe::$meta[ $id ][ $key ] ?? '';
}

function get_post( $id ) {
	Probe::$events[] = array( 'query', $id );
	return isset( Probe::$posts[ $id ] ) ? (object) Probe::$posts[ $id ] : null;
}

function get_post_type_object( $type ): stdClass {
	// Match the explicit case-study capability map in custom-post-types-fixture.php.
	$cap_type = 'mcp_case_study' === $type ? 'mcp_case' : $type;
	return (object) array(
		'name' => $type, 'label' => $type, 'labels' => (object) array( 'singular_name' => $type ),
		'hierarchical' => false,
		'cap' => (object) array(
			'edit_post' => 'edit_' . $cap_type, 'edit_posts' => 'edit_' . $cap_type . 's',
			'publish_posts' => 'publish_' . $cap_type . 's', 'read_post' => 'read_' . $cap_type,
			'delete_post' => 'delete_' . $cap_type,
			'create_posts' => ( 'mcp_case_study' === $type ? 'create_' : 'edit_' ) . $cap_type . 's',
		),
	);
}

function get_taxonomy( $taxonomy ) {
	Probe::$events[] = array( 'taxonomy', $taxonomy );
	return 'mcp_genre' === $taxonomy
		? (object) array( 'cap' => (object) array( 'assign_terms' => 'assign_mcp_genres' ) )
		: false;
}

function is_object_in_taxonomy( $type, $taxonomy ): bool {
	return 'mcp_book' === $type && 'mcp_genre' === $taxonomy;
}

function current_user_can( $cap, ...$args ): bool {
	Probe::$events[] = array( 'cap', $cap, $args );
	$type = $args ? ( Probe::$posts[ $args[0] ]['post_type'] ?? null ) : null;
	return Probe::$allowed && (
		( 21 === Probe::$actor && 'edit_post' === $cap && in_array( $type, array( 'post', 'page' ), true ) )
		|| ( 21 === Probe::$actor && in_array( $cap, array( 'publish_posts', 'publish_pages' ), true ) )
		|| ( 22 === Probe::$actor && ( ( 'edit_mcp_book' === $cap && 'mcp_book' === $type ) || 'assign_mcp_genres' === $cap ) )
		|| ( 22 === Probe::$actor && 'publish_mcp_books' === $cap )
		|| ( 23 === Probe::$actor && 'edit_mcp_case' === $cap && 'mcp_case_study' === $type )
		|| ( 23 === Probe::$actor && 'publish_mcp_cases' === $cap )
	);
}

function wp_kses_post( $value ) {
	return $value;
}

function sanitize_title( $value ): string {
	return strtolower( str_replace( ' ', '-', $value ) );
}

function wp_update_post( $args, $error = false ) {
	throw new RuntimeException( 'Scheduling calibration attempted a post update.' );
}

final class Webmastery_MCP_Post_Scheduling {
	public static function prepare( $input, $post = null ) {
		Probe::$events[] = array( 'schedule', $post->ID ?? null );
		return \Webmastery_MCP_Post_Scheduling::prepare( $input, $post, Probe::NOW );
	}
}

function wp_get_abilities(): array {
	return Probe::$abilities;
}

function wp_get_ability( $name ) {
	return Probe::$inventory ? $name : ( Probe::$abilities[ $name ] ?? null );
}

function wp_register_ability( $name, $args ): void {
	Probe::$raw[ $name ] = $args;
	foreach ( array( 'permission_callback', 'execute_callback' ) as $key ) {
		$callback = $args[ $key ];
		$args[ $key ] = static function ( $input = array() ) use ( $callback, $key ) {
			Probe::$events[] = $key;
			return $callback( $input );
		};
	}
	Probe::$abilities[ $name ] = Webmastery_MCP_Input::register_args( Webmastery_MCP_Response::register_args( $args, $name ), $name );
}

function inventory( string $source ): array {
	Probe::reset();
	$wpdb = new Database();
	$rows = array();
	eval( between( $source, '$hooks = [', '$observed = [];' ) );
	$construction = between( $source, "update_option( 'timezone_string', 'America/New_York' );", '} catch ( Throwable $error ) {' );
	$start = strpos( $construction, '$before_post = ' );
	$record = strpos( $construction, '$summary[\'cases\'][] = ' );
	$end = false === $record ? false : strpos( $construction, "\n", $record );
	if ( false === $start || false === $end ) {
		throw new RuntimeException( 'Scheduling record boundary changed.' );
	}
	// Identify the actual reflection branch, rather than manufacture its routing condition.
	if ( 1 !== preg_match( '/if \( ([^\n]+) \) \{\n\s*\/\/[^\n]*\n\s*\$property = new ReflectionProperty/', $source, $match ) ) {
		throw new RuntimeException( 'Scheduling dispatch anchor changed.' );
	}
	$dispatch = $match[1];
	$capture = '$rows[] = array(
		"ability" => $operation . "-" . $base, "registered_name" => $ability, "label" => $label, "type" => $type,
		"operation" => $operation, "login" => $login, "actor" => Probe::$actor,
		"id_key" => $id_key, "id" => $id, "input" => (object) $input,
		"error" => $error, "path" => (' . $dispatch . ') ? "direct" : "ability",
		"fixture" => $fixture, "post" => $id ? (object) Probe::$posts[$id] : null,
		"metadata" => (object) (Probe::$meta[$id] ?? array()),
		"timezone" => Probe::$timezone, "hooks" => $hooks
	);';
	$construction = substr( $construction, 0, $start ) . $capture . substr( $construction, $end );
	eval( 'namespace ' . __NAMESPACE__ . '; use \\RuntimeException; ' . $construction );
	return $rows;
}

function envelope( string $reason ): array {
	return array(
		'success' => false,
		'error' => array(
			'code' => 'invalid_input', 'reason' => $reason,
			'message' => 'ability_invalid_input' === $reason
				? 'Ability input does not match its schema.'
				: 'The scheduled date must be at least 60 seconds in the future when validated.',
			'details' => new stdClass(),
		),
	);
}

function build( string $original, string $current, stdClass $production ): stdClass {
	if ( ORIGINAL_SHA256 !== hash( 'sha256', $original ) ) {
		throw new RuntimeException( 'Immutable scheduling runner mismatch.' );
	}
	$old = inventory( $original );
	$new = inventory( $current );
	$pairs = array();
	$cursor = 0;
	$unchanged = 0;
	foreach ( $old as $index => $row ) {
		$actual = $new[ $cursor ] ?? null;
		if ( 'direct-invalid-status-overdue' !== $row['label'] ) {
			if ( json( $row ) !== json( $actual ) || 'ability' !== $row['path'] ) {
				throw new RuntimeException( 'Unrelated scheduling row changed: ' . $index );
			}
			++$unchanged;
			++$cursor;
			continue;
		}
		$expected = $row;
		$expected['error'] = 'ability_invalid_input';
		$minimal = $expected;
		$minimal['input'] = clone $expected['input'];
		unset( $minimal['input']->status );
		$minimal['label'] = 'direct-existing-future-overdue';
		$minimal['error'] = 'scheduled_date_too_soon';
		if ( json( $expected ) !== json( $actual ) || json( $minimal ) !== json( $new[ $cursor + 1 ] ?? null ) ) {
			throw new RuntimeException( 'Scheduling original/minimal pair changed: ' . $index );
		}
		$pairs[] = array(
			'old_position' => $index + 1, 'new_position' => $cursor + 1, 'minimal_position' => $cursor + 2,
			'old' => $row, 'new' => $actual, 'minimal' => $new[ $cursor + 1 ],
			'old_oracle' => envelope( 'scheduled_date_too_soon' ),
			'new_oracle' => envelope( 'ability_invalid_input' ),
			'minimal_oracle' => envelope( 'scheduled_date_too_soon' ),
			'original_activity' => array( 'callbacks' => 0, 'queries' => 0, 'capabilities' => 0, 'scheduling' => 0 ),
			'no_write' => true, 'order' => array( 'original', 'minimal' ),
		);
		$cursor += 2;
	}
	$counts = array( 'old' => count( $old ), 'unchanged_native' => $unchanged, 'changed_direct' => count( $pairs ), 'added_direct' => count( $new ) - count( $old ), 'new' => count( $new ) );
	if ( array( 'old' => 264, 'unchanged_native' => 260, 'changed_direct' => 4, 'added_direct' => 4, 'new' => 268 ) !== $counts || $cursor !== count( $new ) ) {
		throw new RuntimeException( 'STOP: scheduling classification mismatch: ' . json( $counts ) );
	}
	return (object) array(
		'purpose' => 'Source-only scheduling calibration; not WordPress runtime acceptance.',
		'baseline' => BASELINE, 'baseline_tree' => '935a4144fd0d4cc43e8fd9c6e673fd36b6117e4b',
		'platform' => array( 'now' => Probe::NOW, 'first_id' => 1000, 'term_ids' => array( '17' ), 'actors' => array( 'editor_test' => 21, 'book_manager_test' => 22, 'case_manager_test' => 23 ) ),
		'original_sha256' => hash( 'sha256', $original ), 'current_sha256' => hash( 'sha256', $current ),
		'original_source' => $original, 'current_source' => $current, 'production' => $production,
		'counts' => $counts, 'old_inventory' => $old, 'new_inventory' => $new, 'pairs' => $pairs,
	);
}

function derive( stdClass $ledger ): stdClass {
	assert_sources( $ledger );
	$current = normalized( file_get_contents( root() . '/tests/e2e/scheduling-runner.php' ) );
	if ( $ledger->current_sha256 !== hash( 'sha256', $current ) ) {
		throw new RuntimeException( 'Current scheduling runner fingerprint mismatch.' );
	}
	$result = build( $ledger->original_source, $current, $ledger->production );
	assert_seal( $result );
	return $result;
}

function predicate( string $source, stdClass $row, $result, string $before, string $after, array $observed ): bool {
	$input = (array) $row->input;
	$id = $row->id;
	$error = $row->error;
	$direct = 'direct' === $row->path;
	eval( 'namespace ' . __NAMESPACE__ . '; ' . between( $source, '$code = wstm118_error_reason', '$expected = [];' ) );
	return $passed;
}

function wstm118_error_envelope( $result ): array {
	return \wstm118_error_envelope( $result );
}

function wstm118_error_reason( $result ) {
	return \wstm118_error_reason( $result );
}

final class RegisteredAbility {
	private $execute_callback;

	public function __construct( callable $callback ) {
		$this->execute_callback = $callback;
	}

	public function execute( $input ) {
		throw new RuntimeException( 'Only the eight direct calibration callbacks may execute in this proof.' );
	}
}

function add_filter( $hook, $observer, $priority ): void {
	Probe::$hooks[ $hook ] = $observer;
	Probe::$observed[] = $hook;
}

function remove_filter( $hook, $observer, $priority ): void {
	if ( ( Probe::$hooks[ $hook ] ?? null ) !== $observer ) {
		throw new RuntimeException( 'Scheduling hook cleanup mismatch.' );
	}
	unset( Probe::$hooks[ $hook ] );
}

function execute( string $source, stdClass $row ): array {
	$ability = new RegisteredAbility( Probe::$abilities[ $row->registered_name ]['execute_callback'] );
	$input = (array) $row->input;
	$label = $row->label;
	$direct = 'direct' === $row->path;
	$hooks = $row->hooks;
	$observed = array();
	Probe::$events = Probe::$observed = array();
	$before = Probe::snapshot();
	eval( between( $source, '$observer = function', "\ntry {" ) );
	eval( 'namespace ' . __NAMESPACE__ . '; use \\ReflectionProperty; '
		. between( $source, 'foreach ( $hooks as $hook ) {', '$after = wstm113_snapshot();' ) );
	$after = Probe::snapshot();
	return array(
		'result' => $result, 'before' => $before, 'after' => $after, 'observed' => $observed,
		'events' => Probe::$events, 'observers' => Probe::$observed, 'remaining_hooks' => array_keys( Probe::$hooks ),
		'passed' => predicate( $source, $row, $result, $before, $after, $observed ),
	);
}
