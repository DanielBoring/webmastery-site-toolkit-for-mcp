<?php

declare(strict_types=1);

namespace Wstm117Calibration;

use RuntimeException;
use stdClass;
use Webmastery_MCP_Input;
use Webmastery_MCP_Response;

require_once dirname( __DIR__, 3 ) . '/includes/class-input.php';

final class Probe {
	public static array $abilities = array();
	public static array $events = array();
	public static array $terms = array();
	public static array $filters = array();
	public static int $actor = 2;
	public static bool $allowed = true;
	public static array $calls = array();

	public static function load(): void {
		if ( class_exists( __NAMESPACE__ . '\\Webmastery_MCP_Taxonomy', false ) ) {
			return;
		}
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-taxonomy.php' );
		eval( 'namespace ' . __NAMESPACE__ . '; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) );
		Webmastery_MCP_Taxonomy::register();
	}

	public static function reset(): void {
		self::$events = self::$filters = self::$calls = array();
		self::$terms = array(
			'category' => array( 42 => (object) array( 'term_id' => 42, 'taxonomy' => 'category', 'metadata' => array( 'original' ), 'relationships' => array( 17 ) ) ),
			'post_tag' => array( 84 => (object) array( 'term_id' => 84, 'taxonomy' => 'post_tag', 'metadata' => array( 'original' ), 'relationships' => array( 17 ) ) ),
		);
		self::$actor = 2;
		self::$allowed = true;
		$GLOBALS['wpdb'] = (object) array( 'num_queries' => 0 );
	}

	public static function snapshot(): array {
		return array( 'persisted_terms' => serialize( self::$terms ) );
	}
}

function wp_register_ability( $name, $args ) {
	foreach ( array( 'permission_callback', 'execute_callback' ) as $key ) {
		$callback = $args[ $key ];
		$args[ $key ] = static function ( $input = array() ) use ( $callback, $key ) {
			Probe::$events[] = $key;
			return $callback( $input );
		};
	}
	Probe::$abilities[ $name ] = Webmastery_MCP_Input::register_args( Webmastery_MCP_Response::register_args( $args, $name ), $name );
}

function get_current_user_id() {
	return Probe::$actor;
}

function get_taxonomy( $taxonomy ) {
	Probe::$events[] = array( 'taxonomy', $taxonomy );
	return (object) array( 'cap' => (object) array( 'delete_terms' => 'manage_categories', 'edit_terms' => 'manage_categories' ) );
}

function current_user_can( $cap, ...$args ) {
	Probe::$events[] = array( 'cap', $cap, $args );
	foreach ( Probe::$filters['map_meta_cap'] ?? array() as $filter ) {
		$filter( array( $cap ), $cap, Probe::$actor, $args );
	}
	return 2 === Probe::$actor && Probe::$allowed;
}

function get_term( $id, $taxonomy ) {
	Probe::$events[] = array( 'query', $id, $taxonomy );
	++$GLOBALS['wpdb']->num_queries;
	return Probe::$terms[ $taxonomy ][ $id ] ?? null;
}

function wp_delete_term( $id, $taxonomy ) {
	Probe::$events[] = array( 'write', $id, $taxonomy );
	throw new RuntimeException( 'Missing/wrong-taxonomy calibration must never reach deletion.' );
}

function add_filter( $name, $callback, $priority = 10, $args = 1 ) {
	Probe::$filters[ $name ][ spl_object_id( $callback ) ] = $callback;
}

function remove_filter( $name, $callback, $priority = 10 ) {
	unset( Probe::$filters[ $name ][ spl_object_id( $callback ) ] );
}

function wp_generate_uuid4() {
	return '00000000-0000-4000-8000-000000000042';
}

function wstm117_term_snapshot() {
	return Probe::snapshot();
}

function json( $value ): string {
	return json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . "\n";
}

const LEDGER_SHA256 = 'e389a6346d111c7ac72e83083b4eeee8d396a4aa33b11ce6ca1942e80734022c';

function assert_seal( stdClass $ledger ): void {
	if ( ! hash_equals( LEDGER_SHA256, hash( 'sha256', json( $ledger ) ) ) ) {
		throw new RuntimeException( 'Taxonomy ledger canonical fingerprint mismatch.' );
	}
}

function load_ledger( ?string $source = null ): stdClass {
	$ledger = json_decode( $source ?? file_get_contents( __DIR__ . '/taxonomy-calibration-ledger.json' ), false, 512, JSON_THROW_ON_ERROR );
	if ( ! $ledger instanceof stdClass ) {
		throw new RuntimeException( 'Taxonomy ledger must be an object.' );
	}
	assert_seal( $ledger );
	return $ledger;
}

function derive( stdClass $ledger ): array {
	assert_seal( $ledger );
	$hashes = array();
	foreach ( $ledger->source_hashes as $commit => $files ) {
		$hashes[ $commit ] = (array) $files;
		if ( hash( 'sha256', $ledger->original_source ) !== $files->{'tests/e2e/taxonomy-write-runner.php'} ) {
			throw new RuntimeException( 'Pinned taxonomy runner hash mismatch.' );
		}
	}
	$result = build( $ledger->original_source, $hashes );
	assert_seal( (object) $result );
	return $result;
}

function between( string $source, string $start, string $end ): string {
	$a = strpos( $source, $start );
	$b = false === $a ? false : strpos( $source, $end, $a );
	if ( false === $a || false === $b ) {
		throw new RuntimeException( 'Taxonomy calibration source anchor changed: ' . $start );
	}
	return substr( $source, $a, $b - $a );
}

function error( string $reason, string $message ): array {
	return array( 'success' => false, 'error' => array(
		'code' => 'ability_invalid_input' === $reason ? 'invalid_input' : $reason,
		'reason' => $reason, 'message' => $message, 'details' => new stdClass(),
	) );
}

function inventory( string $source ): array {
	$admin = (object) array( 'ID' => 1 );
	$editor = (object) array( 'ID' => 2 );
	$subscriber = (object) array( 'ID' => 3 );
	$rows = array();
	foreach ( array( 'category' => 'category', 'post_tag' => 'tag' ) as $taxonomy => $slug ) {
		foreach ( array( 'create', 'update', 'delete' ) as $action ) {
			eval( between( $source, '$scenarios = array(', 'foreach ( $scenarios as $scenario' ) );
			foreach ( $scenarios as $scenario => [ $user_id, $remap, $grants, $allowed ] ) {
				foreach ( array( 'wrapped', 'direct' ) as $path ) {
					$id = 'category' === $taxonomy ? 42 : 84;
					eval( 'namespace ' . __NAMESPACE__ . '; ' . between( $source, '$input = array( \'name\'', 'if ( $remap )' ) );
					$rows[] = array(
						'label' => "{$action}-{$slug}: {$scenario} {$path}", 'input' => $input,
						'actor' => $user_id, 'remap' => $remap, 'grants' => $grants, 'allowed' => $allowed,
						'action' => $action, 'taxonomy' => $taxonomy, 'path' => $path, 'calibrated' => false,
					);
				}
			}
			if ( 'create' === $action ) {
				continue;
			}
			$wrong_id = 'category' === $taxonomy ? 84 : 42;
			$missing_loop = between( $source, "foreach ( array( 'missing' =>", '$before = wstm117_term_snapshot();' );
			// Execute the actual loop declarations and entire actual input construction.
			eval( $missing_loop . '
				$rows[] = array(
					"label" => "{$action}-{$slug}: {$scenario} {$path}", "input" => $input,
					"actor" => $editor->ID, "action" => $action, "taxonomy" => $taxonomy,
					"path" => $path, "scenario" => $scenario, "calibrated" => "delete" === $action,
				);
			} }' );
		}
	}
	$tail = between( $source, "\$id = (int) get_option( 'default_category' );", '} finally {' );
	$id = 1;
	preg_match_all( '/\$record\( ([\'"][^\n]*?[\'"]),/', $tail, $labels );
	if ( 3 !== count( $labels[1] ) ) {
		throw new RuntimeException( 'Default category control inventory changed.' );
	}
	$literal = between( $tail, '$ability->execute( array(', ' ) : $execute(' );
	eval( '$input = ' . substr( $literal, strlen( '$ability->execute( ' ) ) . ';' );
	foreach ( $labels[1] as $index => $expression ) {
		foreach ( 1 === $index ? array( 'wrapped', 'direct' ) : array( 'wrapped' ) as $path ) {
			eval( '$label = ' . $expression . ';' );
			$rows[] = array( 'label' => $label, 'input' => 0 === $index ? $id : $input, 'actor' => 2, 'calibrated' => false );
		}
	}
	return $rows;
}

function build( string $source, array $hashes ): array {
	Probe::load();
	$rows = inventory( $source );
	$pairs = array();
	foreach ( $rows as $row ) {
		if ( ! $row['calibrated'] ) {
			continue;
		}
		$input = $row['input'];
		$minimal = $input;
		unset( $minimal['name'] );
		$schema = Probe::$abilities[ 'webmastery-site-toolkit-for-mcp/delete-' . ( 'category' === $row['taxonomy'] ? 'category' : 'tag' ) ]['input_schema'];
		if ( null === Webmastery_MCP_Input::validate( $input, $schema ) || null !== Webmastery_MCP_Input::validate( $minimal, $schema ) ) {
			throw new RuntimeException( 'STOP: unexpected original/minimal schema classification.' );
		}
		$not_found = error( 'not_found', ( 'category' === $row['taxonomy'] ? 'Category' : 'Tag' ) . ' not found.' );
		$pairs[] = array(
			'label' => $row['label'], 'counterpart_label' => $row['label'] . ' minimal counterpart',
			'taxonomy' => $row['taxonomy'], 'path' => $row['path'], 'actor' => $row['actor'],
			'original_input' => $input, 'minimal_input' => $minimal,
			'old_oracle' => $not_found,
			'new_original_oracle' => error( 'ability_invalid_input', 'Ability input does not match its schema.' ),
			'new_minimal_oracle' => $not_found,
			'capability' => array( 'name' => 'manage_categories', 'allowed' => true ),
			'original_counts' => array( 'callbacks' => 0, 'capabilities' => 0, 'queries' => 0 ),
			'no_write' => true, 'order' => array( 'original', 'minimal' ),
		);
	}

	$counts = array( 'original' => count( $rows ), 'unchanged' => count( $rows ) - count( $pairs ), 'original_schema_controls' => count( $pairs ), 'minimal_counterparts' => count( $pairs ), 'new_total' => count( $rows ) + count( $pairs ) );
	if ( array( 'original' => 156, 'unchanged' => 148, 'original_schema_controls' => 8, 'minimal_counterparts' => 8, 'new_total' => 164 ) !== $counts ) {
		throw new RuntimeException( 'STOP: taxonomy classification mismatch: ' . json( $counts ) );
	}
	return array(
		'purpose' => 'Test-only taxonomy closed-schema calibration; no runtime acceptance',
		'parent' => '3f6e8e055a664b0f21d93c79cf25c239f36fd93f',
		'head' => '436191f9ce83281de2a4a036eb338627e52680e9',
		'source_hashes' => $hashes, 'original_source' => $source,
		'symbolic_values' => array( 'category_id' => 42, 'tag_id' => 84, 'default_category_id' => 1, 'admin_id' => 1, 'editor_id' => 2, 'subscriber_id' => 3, 'uuid' => wp_generate_uuid4() ),
		'counts' => $counts, 'original_cases' => $rows, 'calibration_pairs' => $pairs,
	);
}

function load_runner_helper( ?string $source = null ): void {
	$source = $source ?? file_get_contents( dirname( __DIR__, 2 ) . '/e2e/taxonomy-write-runner.php' );
	eval( 'namespace ' . __NAMESPACE__ . '; ' . between( $source, 'function wstm117_delete_missing_pair(', 'function wstm117_run_taxonomy_tests()' ) );
}

function trace( callable $callback, string $path ): \Closure {
	return static function ( $input ) use ( $callback, $path ) {
		$start = count( Probe::$events );
		$before = Probe::snapshot();
		$result = $callback( $input );
		Probe::$calls[] = array(
			'path' => $path, 'input' => $input, 'result' => $result,
			'events' => array_slice( Probe::$events, $start ), 'before' => $before, 'after' => Probe::snapshot(),
		);
		return $result;
	};
}

final class TracedAbility {
	private \Closure $execute;

	public function __construct( \Webmastery_MCP_Ability $ability ) {
		$this->execute = trace( array( $ability, 'execute' ), 'wrapped' );
	}

	public function execute( $input ) {
		return ( $this->execute )( $input );
	}
}

function native_schema( array $schema ): array {
	// The existing lifecycle double omits descriptive annotations, not validation.
	unset( $schema['description'] );
	foreach ( $schema['properties'] ?? array() as $key => $property ) {
		$schema['properties'][ $key ] = native_schema( $property );
	}
	return $schema;
}

function run_pairs( ?string $source = null ): array {
	$source = $source ?? file_get_contents( dirname( __DIR__, 2 ) . '/e2e/taxonomy-write-runner.php' );
	Probe::load();
	$records = array();
	$record = static function ( $label, $passed, $evidence ) use ( &$records ) {
		$records[] = array( 'label' => $label, 'passed' => $passed, 'evidence' => $evidence );
	};
	$editor = (object) array( 'ID' => 2 );
	foreach ( array( 'category' => 'category', 'post_tag' => 'tag' ) as $taxonomy => $slug ) {
		$action = 'delete';
		$args = Probe::$abilities[ "webmastery-site-toolkit-for-mcp/delete-{$slug}" ];
		$args['input_schema'] = native_schema( $args['input_schema'] );
		$ability = new TracedAbility( new \Webmastery_MCP_Ability( "webmastery-site-toolkit-for-mcp/delete-{$slug}", $args ) );
		$execute = trace( $args['execute_callback'], 'direct' );
		$wrong_id = 'category' === $taxonomy ? 84 : 42;
		// Execute the actual two scenario/path loops, full input construction and dispatch.
		$body = between( $source, "foreach ( array( 'missing' =>", "\n\t\t\t\t}\n\t\t\t}" );
		eval( 'namespace ' . __NAMESPACE__ . '; ' . $body );
	}
	return $records;
}
