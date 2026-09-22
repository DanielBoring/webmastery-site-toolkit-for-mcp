<?php

declare(strict_types=1);

namespace Wstm105Calibration;

use RuntimeException;
use stdClass;
use Webmastery_MCP_Input;
use Webmastery_MCP_Response;

require_once dirname( __DIR__, 3 ) . '/includes/class-input.php';

/**
 * Isolated registration/callback recorder. Production comments and registration
 * guards execute unchanged; only WordPress storage/capability edges are doubles.
 */
final class Probe {
	public static array $abilities = array();
	public static array $originals = array();
	public static array $events = array();
	public static array $comments = array();
	public static array $filters = array();
	public static string $role = 'editor';
	public static array $calls = array();

	public static function load(): void {
		if ( class_exists( __NAMESPACE__ . '\\Webmastery_MCP_Comments', false ) ) {
			return;
		}
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-comments.php' );
		eval( 'namespace ' . __NAMESPACE__ . '; use \\Webmastery_MCP_Response; ' . substr( $source, 5 ) );
		Webmastery_MCP_Comments::register();
	}

	public static function reset(): void {
		self::$events = array();
		self::$calls = array();
	}

	public static function snapshot(): string {
		return serialize( self::$comments );
	}
}

function wp_register_ability( $name, $args ) {
	Probe::$originals[ $name ] = $args;
	foreach ( array( 'permission_callback', 'execute_callback' ) as $key ) {
		$callback = $args[ $key ];
		$args[ $key ] = static function ( $input = array() ) use ( $callback, $key ) {
			Probe::$events[] = $key;
			return $callback( $input );
		};
	}
	Probe::$abilities[ $name ] = Webmastery_MCP_Input::register_args(
		Webmastery_MCP_Response::register_args( $args, $name ), $name
	);
}

function current_user_can( $cap, ...$args ) {
	Probe::$events[] = array( 'cap', $cap, $args );
	foreach ( Probe::$filters['map_meta_cap'] ?? array() as $filter ) {
		if ( array( 'do_not_allow' ) === $filter( array( 'edit_posts' ), $cap, 1, $args ) ) {
			return false;
		}
	}
	if ( 'moderate_comments' === $cap ) {
		return ! in_array( Probe::$role, array( 'author', 'subscriber' ), true );
	}
	if ( 'edit_comment' !== $cap ) {
		throw new RuntimeException( 'Unexpected capability.' );
	}
	$scope = Probe::$comments[ $args[0] ]->scope ?? 'other';
	return match ( Probe::$role ) {
		'editor', 'admin', 'administrator' => 'cpt' !== $scope,
		'wstm105_mapped_moderator' => 'cpt' === $scope,
		'wstm105_own_editor' => in_array( $scope, array( 'own', 'orphan' ), true ),
		'author' => 'author' === $scope,
		default => false,
	};
}

function get_comment( $id ) {
	Probe::$events[] = array( 'query', $id );
	if ( isset( $GLOBALS['wpdb'] ) ) {
		++$GLOBALS['wpdb']->num_queries;
	}
	return 0 === $id ? ( $GLOBALS['comment'] ?? null ) : ( Probe::$comments[ $id ] ?? null );
}

function wp_update_comment( $input, $error = false ) {
	Probe::$events[] = array( 'write', $input );
	Probe::$comments[ $input['comment_ID'] ]->comment_content = stripslashes( $input['comment_content'] );
	return 1;
}

function clean_comment_cache( $id ) {}

function wp_set_current_user( $id ) {
	Probe::$role = $id;
}

function add_filter( $name, $callback, $priority = 10, $args = 1 ) {
	Probe::$filters[ $name ][ spl_object_id( $callback ) ] = $callback;
}

function remove_filter( $name, $callback, $priority = 10 ) {
	unset( Probe::$filters[ $name ][ spl_object_id( $callback ) ] );
}

function e2e_result_is_success( $result ) {
	return is_array( $result ) && true === ( $result['success'] ?? null );
}

function e2e_insert_comment( $scope, $label ) {
	$id = max( array_keys( Probe::$comments ) ) + 1;
	seed( $id, $scope );
	return $id;
}

final class RegisteredAbility {
	private $execute_callback;
	private $permission_callback;

	public function __construct( string $action, array $args ) {
		foreach ( array( 'execute_callback', 'permission_callback' ) as $key ) {
			$callback = $args[ $key ];
			$this->$key = static function ( $input ) use ( $callback, $key, $action ) {
				$start = count( Probe::$events );
				$before = Probe::snapshot();
				$result = $callback( $input );
				Probe::$calls[] = array(
					'action' => $action, 'callback' => $key, 'role' => Probe::$role, 'input' => $input,
					'result' => $result, 'events' => array_slice( Probe::$events, $start ),
					'before' => $before, 'after' => Probe::snapshot(),
				);
				return $result;
			};
		}
	}
}

function wp_get_ability( $name ) {
	$action = str_replace( array( 'webmastery-site-toolkit-for-mcp/', '-comment' ), '', $name );
	return new RegisteredAbility( $action, Probe::$abilities[ $name ] );
}

function load_fixture( ?string $source = null ): void {
	$source = $source ?? file_get_contents( dirname( __DIR__, 2 ) . '/e2e/comments-fixture.php' );
	$source = str_replace( "require_once __DIR__ . '/error-contract-assertions.php';", '', $source );
	eval( 'namespace ' . __NAMESPACE__ . '; use \\ReflectionProperty; use \\stdClass; use \\RuntimeException; use \\Throwable; use ' . __NAMESPACE__ . '\\RegisteredAbility as WP_Ability; ' . substr( $source, 5 ) );
}

function run_fixture(): array {
	Probe::load();
	Probe::reset();
	Probe::$comments = array();
	Probe::$filters = array();
	$GLOBALS['wpdb'] = (object) array( 'num_queries' => 0 );
	$fixtures = array( 'missing_comment_id' => 2147483647 );
	$id = 42;
	foreach ( array( 'update', 'approve', 'trash', 'spam' ) as $action ) {
		foreach ( array( 'other', 'own', 'author', 'cpt', 'orphan' ) as $scope ) {
			seed( $id, $scope );
			$fixtures[ "wstm105_{$action}_{$scope}" ] = $id++;
		}
	}
	$roles = array();
	foreach ( array( 'wstm105_moderator', 'wstm105_own_editor', 'author', 'editor', 'admin', 'wstm105_mapped_moderator' ) as $role ) {
		$roles[ $role ] = $role;
	}
	wstm105_check_direct_callbacks( $roles, $fixtures );
	return Probe::$calls;
}

function wp_set_comment_status( $id, $status ) {
	Probe::$events[] = array( 'write', $id, $status );
	Probe::$comments[ $id ]->status = array( '1' => 'approved', 'approve' => 'approved', 'hold' => 'unapproved' )[ $status ] ?? $status;
	return true;
}

function wp_get_comment_status( $id ) {
	return Probe::$comments[ $id ]->status;
}

function wp_kses_post( $text ) {
	return $text;
}

function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}

function seed( int $id = 42, string $scope = 'other' ): void {
	Probe::$comments[ $id ] = (object) array(
		'comment_ID' => $id, 'comment_post_ID' => $scope, 'scope' => $scope,
		'comment_content' => 'Original.', 'status' => 'unapproved',
		'comment_author' => 'Author', 'comment_author_email' => 'author@example.test',
		'comment_author_url' => '', 'comment_date' => '2026-01-01', 'comment_parent' => 0,
	);
}

function json( $value ): string {
	return json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . "\n";
}

// Independently verified against both pinned Git trees and the original typed ledger.
const LEDGER_CANONICAL_SHA256 = 'c350dd9f4915c4b4f84940fa083ab46a807dac285d40007a536ddc041adf3b2d';

function assert_ledger_seal( stdClass $sealed ): void {
	if ( ! hash_equals( LEDGER_CANONICAL_SHA256, hash( 'sha256', json( $sealed ) ) ) ) {
		throw new RuntimeException( 'Calibration ledger canonical fingerprint mismatch.' );
	}
}

function load_ledger( ?string $source = null ): stdClass {
	$source = $source ?? file_get_contents( __DIR__ . '/comments-calibration-ledger.json' );
	$sealed = json_decode( $source, false, 512, JSON_THROW_ON_ERROR );
	if ( ! $sealed instanceof stdClass ) {
		throw new RuntimeException( 'Calibration ledger must be a JSON object.' );
	}
	assert_ledger_seal( $sealed );
	return $sealed;
}

function between( string $source, string $start, string $end ): string {
	$a = strpos( $source, $start );
	$b = false === $a ? false : strpos( $source, $end, $a );
	if ( false === $a || false === $b ) {
		throw new RuntimeException( 'Calibration source anchor changed: ' . $start );
	}
	return substr( $source, $a, $b - $a );
}

function pinned( string $commit, string $path ): string {
	$source = shell_exec( 'git --no-pager show ' . escapeshellarg( $commit . ':' . $path ) );
	if ( ! is_string( $source ) || '' === $source ) {
		throw new RuntimeException( 'Required pinned source unavailable: ' . $commit . ':' . $path );
	}
	// Git and PHP source readers on Windows may expose different line endings.
	return str_replace( "\r\n", "\n", $source );
}

function contract( string $source, string $mode, string $boundary, string $invalid, bool $moderate, bool $edit ): array {
	eval( between( $source, '$schema_invalid =', '$allowed =' ) );
	eval( between( $source, '$permission_denied =', 'wstm105_assert( $reason ===' ) );
	return array( 'code' => \wstm118_expected_code( $reason ), 'reason' => $reason, 'message' => $message, 'details' => new stdClass() );
}

function assert_runner_response( string $source, object $row, array $result ): void {
	$mode = 'fixed';
	$boundary = $row->boundary;
	$action = $row->action;
	$invalid = $row->invalid;
	$moderate = $row->capabilities->moderate_comments;
	$edit = $row->capabilities->edit_comment;
	$before = array( 'unchanged' );
	$after = $before;
	$record = array();
	$raw = array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => \wp_json_encode( $result ) ) ) );
	if ( 'http' === $boundary ) {
		\wstm118_wire_error( $raw );
	}
	eval( 'namespace ' . __NAMESPACE__ . '; ' . between( $source, '$schema_invalid =', '$record[\'passed\']' ) );
}

function runner_inventory( string $source ): array {
	$rows = array();
	$id = 42;
	foreach ( array( 'direct', 'ability', 'http' ) as $boundary ) {
		foreach ( array( 'update', 'approve', 'trash', 'spam' ) as $action ) {
			eval( between( $source, '$scenarios = array(', 'foreach ( $scenarios as $scenario' ) );
			foreach ( $scenarios as $scenario => list( $role, $scope, $moderate, $edit ) ) {
				$invalid = $scenarios[ $scenario ][4] ?? '';
				// Evaluate the entire actual input construction, except its global-comment lookup.
				$construction = between( $source, '$input = array(', '$record[\'input\']' );
				$construction = str_replace( '$GLOBALS[\'comment\'] = get_comment( $id );', '', $construction );
				eval( $construction );
				$mode = 'baseline';
				eval( between( $source, '$schema_invalid =', 'wstm105_assert( $allowed ===' ) );
				$baseline = $allowed ? array( 'success' => true ) : contract( $source, 'baseline', $boundary, $invalid, $moderate, $edit );
				$allowed = '' === $invalid && $moderate && $edit;
				$rows[] = array(
					'label' => "{$boundary}:{$action}:{$scenario}",
					'boundary' => $boundary, 'action' => $action, 'role' => $role, 'scope' => $scope,
					'invalid' => $invalid, 'input' => $input,
					'http_parameters' => 'http' === $boundary ? (object) $input : null,
					'capabilities' => array( 'moderate_comments' => $moderate, 'edit_comment' => $edit ),
					'allowed' => $allowed,
					'old' => $allowed ? array( 'success' => true ) : contract( $source, 'fixed', $boundary, $invalid, $moderate, $edit ),
					'baseline' => 'direct' === $boundary && in_array( $invalid, array( 'missing_id', 'string_id', 'array_id', 'null_id' ), true )
						? 'historically_omitted' : $baseline,
					'no_write' => ! $allowed,
				);
			}
		}
	}
	return $rows;
}

function derive( ?stdClass $sealed = null ): array {
	if ( null !== $sealed ) {
		assert_ledger_seal( $sealed );
	}
	Probe::load();
	$main = '2feed8d18d0721a4c7ca2e0187005c8cfae76322';
	$head = '5b39f6a373b35bdb85f52916d4bb5b2f51ebc410';
	$hashes = array();
	foreach ( array( $main, $head ) as $commit ) {
		foreach ( array( 'tests/e2e/comments-runner.php', 'tests/e2e/comments-fixture.php', 'includes/class-comments.php', 'includes/class-input.php', 'includes/class-response.php', 'includes/class-ability.php' ) as $path ) {
			if ( null !== $sealed ) {
				$hashes[ $commit ][ $path ] = $sealed->source_hashes->$commit->$path;
			} else {
				$exists = trim( (string) shell_exec( 'git --no-pager ls-tree ' . escapeshellarg( $commit ) . ' -- ' . escapeshellarg( $path ) ) );
				$hashes[ $commit ][ $path ] = '' === $exists ? null : hash( 'sha256', pinned( $commit, $path ) );
			}
		}
	}
	$originals = array();
	foreach ( array( 'tests/e2e/comments-runner.php', 'tests/e2e/comments-fixture.php' ) as $path ) {
		$originals[ $path ] = null === $sealed ? pinned( $head, $path ) : $sealed->original_sources->$path;
		if ( hash( 'sha256', $originals[ $path ] ) !== $hashes[ $head ][ $path ] ) {
			throw new RuntimeException( 'Pinned original source hash mismatch: ' . $path );
		}
	}
	$runner = $originals['tests/e2e/comments-runner.php'];
	$fixture = $originals['tests/e2e/comments-fixture.php'];
	$rows = runner_inventory( $runner );
	$changed = array( 'http' => 0, 'direct' => 0, 'ability' => 0 );
	$schema_error = array( 'code' => 'invalid_input', 'reason' => 'ability_invalid_input', 'message' => 'Ability input does not match its schema.', 'details' => new stdClass() );
	foreach ( $rows as &$row ) {
		$row['new'] = $row['old'];
		$malformed = in_array( $row['invalid'], array( 'missing_id', 'string_id', 'array_id', 'null_id' ), true );
		$change = $malformed && ( 'direct' === $row['boundary'] || ( 'http' === $row['boundary'] && 'subscriber' === $row['role'] ) );
		if ( $change ) {
			$row['new'] = $schema_error;
			++$changed[ $row['boundary'] ];
		}
		$row['changed'] = $change;
		$row['legacy_compatibility'] = ! $change && ( in_array( $row['invalid'], array( 'missing', 'zero', 'negative_missing' ), true )
			|| ( 'direct' !== $row['boundary'] && $malformed )
			|| ( '' === $row['invalid'] && ! $row['capabilities']['moderate_comments'] && 'direct' !== $row['boundary'] ) );
	}
	unset( $row );
	$raw = array();
	$legacy = array();
	$schemas = array();
	$comment_id = 42;
	eval( between( $fixture, '$invalid_inputs =', 'foreach ( $invalid_inputs as $input )' ) );
	eval( between( $fixture, '$scenarios = array(', 'foreach ( $scenarios as list(' ) );
	$schema_count = 0;
	foreach ( array( 'update', 'approve', 'trash', 'spam' ) as $action ) {
		$args = Probe::$abilities[ "webmastery-site-toolkit-for-mcp/{$action}-comment" ];
		$schemas[ $action ] = $args['input_schema'];
		foreach ( $invalid_inputs as $index => $input ) {
			$invalid = null !== Webmastery_MCP_Input::validate( $input, $args['input_schema'] );
			$schema_count += (int) $invalid;
			$raw[] = array(
				'label' => "raw:{$action}:malformed:{$index}", 'action' => $action, 'input' => $input,
				'old_permission' => true, 'old_execute' => 'explicit_failure_with_nonempty_error',
				'new_permission' => $invalid ? array( 'native_code' => 'invalid_input', 'decoded' => $schema_error ) : true,
				'new_execute' => $invalid ? $schema_error : array( 'code' => 'not_found', 'reason' => 'not_found', 'message' => 'Comment not found.', 'details' => new stdClass() ),
				'no_write' => true, 'schema_invalid' => $invalid,
			);
		}
		if ( 'update' === $action ) {
			continue;
		}
		$controls = array();
		foreach ( $scenarios as list( $role, $scope, $moderate, $edit ) ) {
			$controls[] = array( "{$role}:{$scope}", $role, $scope, array( 'comment_id' => 42, 'content' => 'Updated directly.', 'status' => 'spam' ), $moderate && $edit ? 'success' : 'forbidden' );
		}
		$controls[] = array( 'missing', 'editor', 'missing', array( 'comment_id' => 2147483647, 'content' => 'Missing.' ), 'not_found' );
		$controls[] = array( 'global_zero', 'editor', 'other', array( 'comment_id' => 0, 'content' => 'Must not target the global comment.', 'status' => 'spam' ), 'not_found' );
		$controls[] = array( 'map_meta_cap', 'editor', 'other', array( 'comment_id' => 42, 'content' => 'Filtered out.', 'status' => 'trash' ), 'forbidden' );
		foreach ( $controls as list( $label, $role, $scope, $input, $outcome ) ) {
			if ( null === Webmastery_MCP_Input::validate( $input, $args['input_schema'] )
				|| null !== Webmastery_MCP_Input::validate( array( 'comment_id' => $input['comment_id'] ), $args['input_schema'] ) ) {
				throw new RuntimeException( 'Unexpected legacy/minimal schema classification.' );
			}
			$legacy[] = array(
				'label' => "raw:{$action}:{$label}", 'action' => $action, 'role' => $role, 'scope' => $scope,
				'original_input' => $input, 'minimal_input' => array( 'comment_id' => $input['comment_id'] ),
				'old_outcome' => $outcome, 'new_original' => $schema_error, 'new_minimal' => $outcome,
				'original_no_write' => true, 'original_callback_cap_query_counts' => array( 0, 0, 0 ),
				'order' => array( 'original_schema_negative', 'minimal_valid_counterpart' ),
			);
		}
	}
	$counts = array(
		'runner' => array_count_values( array_column( $rows, 'boundary' ) ),
		'changed' => $changed, 'raw_original' => 4 * ( count( $scenarios ) + 1 + 1 + count( $invalid_inputs ) + 1 ) + 6,
		'raw_malformed' => count( $raw ), 'raw_schema_rejections' => $schema_count,
		'raw_negative_integer_deferrals' => count( $raw ) - $schema_count,
		'legacy_originals' => count( $legacy ), 'minimal_supplements' => count( $legacy ),
		'raw_total' => 4 * ( count( $scenarios ) + 1 + 1 + count( $invalid_inputs ) + 1 ) + 6 + count( $legacy ),
	);
	if ( array( 'direct' => 104, 'ability' => 104, 'http' => 100 ) !== $counts['runner']
		|| array( 'http' => 16, 'direct' => 32, 'ability' => 0 ) !== $changed
		|| 102 !== $counts['raw_original'] || 44 !== count( $raw ) || 41 !== $schema_count
		|| 39 !== count( $legacy ) || 141 !== $counts['raw_total'] ) {
		throw new RuntimeException( 'STOP: approved A-D classification mismatch: ' . json( $counts ) );
	}
	$derived = array(
		'purpose' => 'TEST-ONLY draft168 WSTM105 source calibration; not runtime acceptance',
		'main' => $main, 'head' => $head, 'source_hash_algorithm' => 'sha256 LF-normalized source bytes',
		'source_hashes' => $hashes,
		'original_sources' => $originals,
		'symbolic_ids' => array( 'existing_comment_id' => 42, 'missing_comment_id' => 2147483647 ),
		'counts' => $counts, 'closed_registered_schemas' => $schemas, 'runner_cases' => $rows,
		'raw_malformed_cases' => $raw, 'legacy_payload_pairs' => $legacy,
	);
	assert_ledger_seal( (object) $derived );
	return $derived;
}
