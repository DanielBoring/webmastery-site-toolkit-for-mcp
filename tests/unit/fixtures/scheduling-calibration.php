<?php

namespace Wstm113Calibration;

// Execute only the runner's case sections against scoped doubles, never its WordPress bootstrap.
use LogicException;
use Webmastery_MCP_Input;
use Webmastery_MCP_Response;
use function Wstm121Runtime\section;

require_once __DIR__ . '/runtime-calibration.php';
require_once dirname( __DIR__, 3 ) . '/includes/class-input.php';

const ARRAY_A = 'ARRAY_A';

final class State {
	public static array $post = array();
	public static string $sentinel = '';
	public static ?string $sentinel_read = null;
	public static array $terms = array();
	public static array $cron = array();
	public static array $calls = array();
	public static array $phases = array();
	public static array $post_reads = array();
	public static string $fault_layer = '';
	public static string $fault = '';
}

function source(): string {
	return str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/e2e/scheduling-runner.php' ) );
}

function cases( string $operation ): array {
	eval( section( source(), "\t\t\t\$cases = [", "\t\t\tforeach ( \$cases as \$case ) {" ) );
	return $cases;
}

function fault( string $layer, $result ) {
	State::$phases[] = $layer;
	State::$calls[ $layer ]['observing'] = ! empty( \Wstm121Runtime\State::$filters['save_post'][PHP_INT_MAX] );
	if ( $layer !== State::$fault_layer ) {
		return $result;
	}
	switch ( State::$fault ) {
		case 'wrong-reason':
			return Webmastery_MCP_Response::legacy_error( 'invalid_scheduled_date', 'Controlled wrong reason.' );
		case 'wrong-code':
			$result['error']['code'] = 'forbidden';
			return $result;
		case 'success':
			return Webmastery_MCP_Response::ok( array() );
		case 'raw-object':
			return (object) array( 'success' => false, 'data' => (object) array( 'id' => 999 ) );
		case 'object-data':
			$result['data'] = (object) array( 'id' => 999 );
			return $result;
		case 'redirect-id':
			$result['data'] = array( 'id' => 999 );
			return $result;
		case 'native-error':
			return new \WP_Error( 'scheduled_date_too_soon', 'Controlled native callback error.' );
		case 'post':
			State::$post['post_content'] = 'Unexpected write';
			break;
		case 'metadata':
			State::$sentinel = 'Unexpected metadata write';
			break;
		case 'sentinel-read':
			State::$sentinel_read = 'Unexpected cached sentinel';
			break;
		case 'terms':
			State::$terms[] = 999;
			break;
		case 'cron':
			State::$cron[] = 123;
			break;
		case 'hook':
			\Wstm121Runtime\apply( 'save_post', State::$post['ID'] );
			break;
		default:
			throw new LogicException( 'Unconfigured scheduling fault.' );
	}
	return $result;
}

final class RegisteredAbility {
	protected $execute_callback;

	public function __construct( string $base ) {
		$properties = array(
			'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ) ),
		);
		foreach ( array( 'title', 'content', 'slug' ) as $key ) {
			$properties[$key] = array( 'type' => 'string' );
		}
		foreach ( array( 'id', 'post_id', 'page_id', 'parent' ) as $key ) {
			$properties[$key] = array( 'type' => 'integer' );
		}
		$properties['category_ids'] = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		$properties['taxonomy_terms'] = array( 'type' => 'object' );
		$args = Webmastery_MCP_Input::register_args( array(
			'input_schema' => array( 'type' => 'object', 'properties' => $properties ),
			'execute_callback' => static function () {
				throw new LogicException( 'Invalid status escaped the production input guard.' );
			},
		), 'webmastery-site-toolkit-for-mcp/update-' . $base );
		$this->execute_callback = static function ( $input ) use ( $args ) {
			State::$calls['registered_callback']['input'] = $input;
			$result = fault( 'registered_callback', $args['execute_callback']( $input ) );
			State::$calls['registered_callback']['result'] = $result;
			return $result;
		};
	}

	public function execute( $input ) {
		throw new LogicException( 'The registered-callback probe used the ability lifecycle instead.' );
	}
}

final class Webmastery_MCP_Post_Scheduling {
	public static function prepare( $input, $post ) {
		State::$calls['scheduling_helper'] = array( 'input' => $input, 'post' => (array) $post );
		$result = \Webmastery_MCP_Post_Scheduling::prepare( $input, $post, 1798761600 );
		$canonical = is_wp_error( $result ) ? Webmastery_MCP_Response::from_wp_error( $result ) : $result;
		$injected = fault( 'scheduling_helper', $canonical );
		State::$calls['scheduling_helper']['result'] = $injected;
		State::$calls['scheduling_helper']['native_result'] = $injected === $canonical ? $result : $injected;
		return State::$calls['scheduling_helper']['native_result'];
	}
}

final class Database {
	public string $posts = 'probe_posts';
	public string $postmeta = 'probe_meta';
	public string $term_relationships = 'probe_terms';
	public string $last_error = '';

	public function get_results( string $sql, $format ): array {
		switch ( $sql ) {
			case 'SELECT * FROM probe_posts':
				return array( State::$post );
			case 'SELECT * FROM probe_meta':
				return array( array( 'value' => State::$sentinel ) );
			case 'SELECT * FROM probe_terms':
				return State::$terms;
			default:
				throw new LogicException( 'Unexpected scheduling snapshot query.' );
		}
	}
}

function _get_cron_array(): array {
	State::$phases[] = 'snapshot';
	return State::$cron;
}
function get_post( $id, $format = null ) {
	State::$post_reads[] = $id;
	if ( $id !== State::$post['ID'] ) { throw new LogicException( 'Wrong scheduling fixture ID.' ); }
	return ARRAY_A === $format ? State::$post : (object) State::$post;
}
function update_post_meta( $id, $key, $value ) { State::$sentinel = $value; }
function get_post_meta( $id, $key, $single ) { return State::$sentinel_read ?? State::$sentinel; }
function wp_next_scheduled( $hook, $args ) { return State::$cron[0]; }

function run( string $type, string $base, string $id_key, string $fault_layer = '', string $fault_name = '' ): array {
	State::$post = array( 'ID' => 42, 'post_type' => $type, 'post_status' => 'future', 'post_date' => '2030-06-01 08:00:00', 'post_date_gmt' => '2001-01-01 00:00:00', 'post_content' => 'Original content' );
	State::$sentinel = 'Scheduling metadata sentinel';
	State::$sentinel_read = null;
	State::$terms = array( 7 );
	State::$cron = array( 1906545600 );
	State::$calls = State::$phases = State::$post_reads = array();
	State::$fault_layer = $fault_layer;
	State::$fault = $fault_name;
	\Wstm121Runtime\State::$filters = array();
	$GLOBALS['wpdb'] = new Database();
	$source = source();
	if ( ! function_exists( __NAMESPACE__ . '\\wstm113_snapshot' ) ) {
		eval( 'namespace Wstm113Calibration; use \RuntimeException; ' . section( $source, 'function wstm113_snapshot()', 'function wstm113_write(' ) );
	}
	eval( 'namespace Wstm113Calibration; use function \Wstm121Runtime\current_filter; ' . section( $source, '$hooks = [', "\ntry {" ) );
	$target = array_values( array_filter( cases( 'update' ), static fn( $case ) => 'direct-invalid-status-overdue' === $case[0] ) );
	if ( 1 !== count( $target ) ) { throw new LogicException( 'Missing or repeated two-layer scheduling case.' ); }
	[ $label, $input, $error ] = $target[0];
	$id = State::$post['ID'];
	$input[$id_key] = $id;
	$terms = array( 7 );
	$parent_id = 12;
	$operation = 'update';
	$ability = new RegisteredAbility( $base );
	$summary = array( 'passed' => 0, 'failed' => 0, 'cases' => array() );
	try {
		eval( 'namespace Wstm113Calibration; use \ReflectionProperty; use \RuntimeException; use \Webmastery_MCP_Response; use function \Wstm121Runtime\add_filter; use function \Wstm121Runtime\remove_filter; '
			. section( $source, "\t\t\t\t\$input = array_merge(", "\t\t\t\tif ( 'timezone-change' === \$fixture ) {" ) );
		return $summary;
	} finally {
		unset( $GLOBALS['wpdb'] );
		\Wstm121Runtime\State::$filters = array();
	}
}
