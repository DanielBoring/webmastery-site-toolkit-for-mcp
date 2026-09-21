<?php

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM111_DISPOSABLE_SITE' ) ) {
	throw new RuntimeException( 'Database privacy fixtures require WSTM111_DISPOSABLE_SITE=1 on an owned disposable installation.' );
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
require_once __DIR__ . '/database-table-privacy-fixture.php';
define( 'WEBMASTERY_MCP_E2E_CLIENT_ONLY', true );
require_once __DIR__ . '/mcp-crud-runner.php';

function wstm111_privacy_check( string $id, array $predicates, $response ): void {
	$GLOBALS['wstm111_privacy_report']['cases'][] = array(
		'id' => $id, 'passed' => ! in_array( false, $predicates, true ),
		'predicates' => $predicates, 'response' => $response,
	);
}

function wstm111_privacy_pair( string $boundary, callable $execute, array $private_names ): array {
	global $wpdb;
	$default = $execute( array() );
	$explicit = $execute( array( 'include_table_names' => false ) );
	$raw = $execute( array( 'include_table_names' => true ) );
	$rows = $default['data']['table_sizes'] ?? array();
	$raw_rows = $raw['data']['table_sizes'] ?? array();
	$mapping = $wpdb->tables( 'all', true );
	$predicates = array(
		'default_success' => true === ( $default['success'] ?? false ),
		'explicit_false_success' => true === ( $explicit['success'] ?? false ),
		'opt_in_success' => true === ( $raw['success'] ?? false ),
		'nonempty_rows' => count( $rows ) > 0,
		'identical_row_count' => count( $rows ) === count( $raw_rows ),
		'explicit_false_identical' => $default === $explicit,
	);
	$normalized_raw = $raw;
	$custom_index = 0;
	$json = wp_json_encode( $default );
	foreach ( $raw_rows as $index => $row ) {
		$name = $row['table'];
		$logical = array_search( $name, $mapping, true );
		$is_core = false !== $logical;
		$expected_label = $is_core ? $logical : 'custom_table_' . ++$custom_index;
		$predicates[ "row_{$index}_classification" ] = $is_core === ( $row['is_core_table'] ?? null )
			&& $is_core === ( $rows[ $index ]['is_core_table'] ?? null );
		$predicates[ "row_{$index}_label" ] = $expected_label === ( $rows[ $index ]['table'] ?? null );
		$predicates[ "row_{$index}_scope" ] = str_starts_with( $name, $wpdb->prefix );
		$predicates[ "row_{$index}_physical_name_absent_entire_payload" ] = ! str_contains( $json, $name );
		$normalized_raw['data']['table_sizes'][ $index ]['table'] = $expected_label;
	}
	$predicates['all_metrics_other_fields_and_order_identical'] = $default === $normalized_raw;
	if ( str_starts_with( $wpdb->prefix, 'wstm111_private_' ) ) {
		$predicates['custom_sentinel_prefix_absent_entire_payload'] = ! str_contains( $json, $wpdb->prefix );
	}
	foreach ( $private_names as $index => $name ) {
		$predicates[ "owned_table_{$index}_absent_entire_payload" ] = ! str_contains( $json, $name )
			&& ! str_contains( $json, substr( $name, strlen( $wpdb->prefix ) ) );
		$predicates[ "owned_table_{$index}_opt_in_present" ] = in_array( $name, array_column( $raw_rows, 'table' ), true );
	}
	wstm111_privacy_check( "{$boundary}/default-explicit-opt-in", $predicates, array( 'default' => $default, 'explicit_false' => $explicit, 'raw_opt_in' => $raw ) );
	return array_column( $raw_rows, 'table' );
}

function wstm111_privacy_http( array $private_names, array $actors ): void {
	require_once __DIR__ . '/error-contract-assertions.php';
	foreach ( array( 'gateway', 'individual' ) as $transport ) {
		foreach ( $actors as $role => $user ) {
			$login = $user->user_login;
			$credential = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'wstm111 owned privacy proof' ) );
			if ( is_wp_error( $credential ) ) {
				throw new RuntimeException( 'Could not create owned transport credential.' );
			}
			$endpoint = 'gateway' === $transport ? '/wp-json/mcp/mcp-adapter-default-server' : '/wp-json/wstm118/tools';
			$client = new Webmastery_MCP_E2E_Client( 'http://localhost' . $endpoint, $login, $credential[0] );
			$wire_results = array();
			try {
				$client->initialize();
				$tool = 'mcp-adapter-execute-ability';
				if ( 'individual' === $transport ) {
					$listing = $client->call( 'tools/list' );
					$GLOBALS['wstm111_privacy_report']['catalog'][ $transport ][ $role ] = $listing;
					if ( isset( $listing['nextCursor'] ) || ! isset( $listing['tools'] ) ) {
						throw new RuntimeException( 'Individual tool catalog was incomplete.' );
					}
					$description = trim( wp_get_ability( 'webmastery-site-toolkit-for-mcp/database-health' )->get_description() );
					$matches = array_values( array_filter( $listing['tools'], static fn( $entry ) => $description === ( $entry['description'] ?? null ) ) );
					if ( 1 !== count( $matches ) ) {
						throw new RuntimeException( 'Expected exactly one individual database-health tool.' );
					}
					$tool = $matches[0]['name'];
					wstm111_privacy_check( "http/{$transport}/{$login}/schema", array(
						'boolean_flag' => 'boolean' === ( $matches[0]['inputSchema']['properties']['include_table_names']['type'] ?? null ),
						'private_default' => false === ( $matches[0]['inputSchema']['properties']['include_table_names']['default'] ?? null ),
					), $matches[0] );
				}
				$execute = static function ( array $input ) use ( $client, $transport, $tool, $private_names, &$wire_results ): array {
					global $wpdb;
					$arguments = 'gateway' === $transport
						? array( 'ability_name' => 'webmastery-site-toolkit-for-mcp/database-health', 'parameters' => (object) $input )
						: (object) $input;
					$wire = $client->call( 'tools/call', array( 'name' => $tool, 'arguments' => $arguments ) );
					$wire_results[] = $wire;
					if ( true !== ( $input['include_table_names'] ?? false ) ) {
						$json = wp_json_encode( $wire );
						$private_tokens = array_merge( $private_names, array_values( $wpdb->tables( 'all', true ) ), array( 'plugin_fingerprint' ) );
						foreach ( $private_tokens as $token ) {
							if ( str_contains( $json, $token ) ) {
								throw new RuntimeException( 'Raw MCP result disclosed a private table identifier without opt-in.' );
							}
						}
					}
					if ( true === ( $wire['isError'] ?? false ) ) {
						return wstm118_wire_error( $wire );
					}
					$payload = webmastery_mcp_e2e_extract_tool_payload( $wire, 'database table privacy' );
					if ( 'gateway' === $transport && true === ( $payload['success'] ?? null ) && isset( $payload['data']['success'] ) ) {
						$payload = $payload['data'];
					}
					return $payload;
				};
				if ( 'admin' === $role ) {
					$physical_names = wstm111_privacy_pair( "http/{$transport}/admin", $execute, $private_names );
					foreach ( array_slice( $wire_results, 0, 2 ) as $index => $wire ) {
						$predicates = array();
						foreach ( $physical_names as $row_index => $name ) {
							$predicates[ "row_{$row_index}_physical_name_absent" ] = ! str_contains( wp_json_encode( $wire ), $name );
						}
						wstm111_privacy_check( "http/{$transport}/admin/default-wire-{$index}", $predicates, $wire );
					}
					$result = $execute( array( 'include_table_names' => 'true' ) );
					wstm111_privacy_check( "http/{$transport}/admin/invalid-flag", array(
						'invalid_input' => 'invalid_input' === ( $result['error']['code'] ?? null ),
						'object_details' => ( $result['error']['details'] ?? null ) instanceof stdClass,
						'no_table_data' => ! isset( $result['data'] ),
					), $result );
				} else {
					foreach ( array( array(), array( 'include_table_names' => true ) ) as $index => $input ) {
						$result = $execute( $input );
						wstm111_privacy_check( "http/{$transport}/subscriber/{$index}", array(
							'forbidden' => 'forbidden' === ( $result['error']['code'] ?? null ),
							'object_details' => ( $result['error']['details'] ?? null ) instanceof stdClass,
							'no_table_data' => ! isset( $result['data'] ),
						), $result );
					}
				}
			} finally {
				$GLOBALS['wstm111_privacy_report']['wire'][ $transport ][ $login ] = $wire_results ?? array();
				try {
					$client->close();
					wstm111_privacy_check( "cleanup/{$transport}/{$login}/session", array( 'closed' => true ), null );
				} catch ( Throwable $error ) {
					wstm111_privacy_check( "cleanup/{$transport}/{$login}/session", array( 'closed' => false ), $error->getMessage() );
				}
				try {
					$deleted = WP_Application_Passwords::delete_application_password( $user->ID, $credential[1]['uuid'] );
					$absent = null === WP_Application_Passwords::get_user_application_password( $user->ID, $credential[1]['uuid'] );
					wstm111_privacy_check( "cleanup/{$transport}/{$login}/credential", array( 'deleted' => true === $deleted, 'absent' => $absent ), null );
				} catch ( Throwable $error ) {
					wstm111_privacy_check( "cleanup/{$transport}/{$login}/credential", array( 'deleted_and_verified' => false ), $error->getMessage() );
				}
			}
		}
	}
}

global $wpdb;
$fixture = new Webmastery_MCP_Database_Table_Privacy_Fixture();
$original_user = get_current_user_id();
$GLOBALS['wstm111_privacy_report'] = array(
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'multisite' => is_multisite(), 'blog_id' => get_current_blog_id(),
	'source_sha256' => hash_file( 'sha256', dirname( __DIR__, 2 ) . '/includes/class-database-health.php' ),
	'runner_sha256' => hash_file( 'sha256', __FILE__ ),
	'cases' => array(), 'cleanup' => array(),
);
$queries = array();
$actors = array();
$run = 'wstm111-' . wp_generate_uuid4();
$observe = static function ( $query ) use ( &$queries ) {
	$queries[] = $query;
	return $query;
};
try {
	$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/database-health' );
	if ( ! $ability instanceof Webmastery_MCP_Ability ) {
		throw new RuntimeException( 'Registered database-health ability is unavailable.' );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array( 'admin' => 'administrator', 'subscriber' => 'subscriber' ) as $label => $role ) {
		$login = $run . '-' . $label;
		$id = wp_create_user( $login, wp_generate_password( 40 ), $login . '@example.test' );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Could not create exclusively owned privacy actor.' );
		}
		$actors[ $label ] = new WP_User( $id );
		$actors[ $label ]->set_role( $role );
	}
	$admin = $actors['admin'];
	$subscriber = $actors['subscriber'];
	wp_set_current_user( $admin->ID );
	$fixture->create();
	$native_boundaries = '1' === getenv( 'WSTM111_PRIVACY_HTTP' ) ? array() : array( 'direct', 'registered' );
	foreach ( $native_boundaries as $boundary ) {
		$execute = 'direct' === $boundary
			? array( Webmastery_MCP_Database_Health::class, 'execute' )
			: array( $ability, 'execute' );
		$omitted = $execute();
		wstm111_privacy_check( "{$boundary}/omitted-input", array(
			'success' => true === ( $omitted['success'] ?? false ),
			'physical_posts_absent_entire_payload' => ! str_contains( wp_json_encode( $omitted ), $wpdb->posts ),
			'owned_fingerprint_absent_entire_payload' => ! str_contains( wp_json_encode( $omitted ), 'plugin_fingerprint' ),
		), $omitted );
		wstm111_privacy_pair( $boundary, $execute, $fixture->names );
		foreach ( array( null, 'true', 'false', 0, 1, array() ) as $index => $flag ) {
			$queries = array();
			add_filter( 'query', $observe, PHP_INT_MAX );
			try {
				$result = $execute( array( 'include_table_names' => $flag ) );
			} finally {
				remove_filter( 'query', $observe, PHP_INT_MAX );
			}
			$error = is_wp_error( $result ) ? Webmastery_MCP_Response::from_wp_error( $result ) : $result;
			wstm111_privacy_check( "{$boundary}/invalid-flag-{$index}", array(
				'invalid_input' => false === ( $error['success'] ?? null ) && 'invalid_input' === ( $error['error']['code'] ?? null ),
				'no_queries' => array() === $queries,
				'no_table_data' => ! isset( $error['data'] ),
			), array( 'result' => $error, 'queries' => $queries ) );
		}
		wp_set_current_user( $subscriber->ID );
		foreach ( array( array(), array( 'include_table_names' => true ) ) as $index => $input ) {
			$queries = array();
			add_filter( 'query', $observe, PHP_INT_MAX );
			try {
				$result = $execute( $input );
			} finally {
				remove_filter( 'query', $observe, PHP_INT_MAX );
			}
			$error = is_wp_error( $result ) ? Webmastery_MCP_Response::from_wp_error( $result ) : $result;
			wstm111_privacy_check( "{$boundary}/subscriber-{$index}", array(
				'forbidden' => false === ( $error['success'] ?? null ) && 'forbidden' === ( $error['error']['code'] ?? null ),
				'no_diagnostic_queries' => ! array_filter( $queries, static fn( $query ) => str_contains( $query, 'information_schema' ) || str_contains( $query, 'COUNT(' ) || str_contains( $query, 'SUM(' ) ),
				'no_table_data' => ! isset( $error['data'] ),
			), array( 'result' => $error, 'queries' => $queries ) );
		}
		wp_set_current_user( $admin->ID );
	}
	if ( '1' === getenv( 'WSTM111_PRIVACY_HTTP' ) ) {
		wstm111_privacy_http( $fixture->names, $actors );
	}
} catch ( Throwable $error ) {
	wstm111_privacy_check( 'runner-error', array( 'no_exception' => false ), array( 'message' => $error->getMessage() ) );
} finally {
	remove_filter( 'query', $observe, PHP_INT_MAX );
	$fixture->restore();
	$GLOBALS['wstm111_privacy_report']['cleanup'] = $fixture->cleanup;
	foreach ( $fixture->cleanup as $index => $cleanup ) {
		wstm111_privacy_check( "cleanup/table-{$index}", array( 'dropped' => $cleanup['dropped'], 'absent' => $cleanup['absent'] ), $cleanup );
	}
	wp_set_current_user( $original_user );
	foreach ( $actors as $role => $actor ) {
		try {
			$deleted = is_multisite() ? wpmu_delete_user( $actor->ID ) : wp_delete_user( $actor->ID );
			wstm111_privacy_check( "cleanup/actor/{$role}", array(
				'deleted' => true === $deleted, 'absent' => false === get_user_by( 'id', $actor->ID ),
			), null );
		} catch ( Throwable $error ) {
			wstm111_privacy_check( "cleanup/actor/{$role}", array( 'deleted_and_verified' => false ), $error->getMessage() );
		}
	}
}
$report = $GLOBALS['wstm111_privacy_report'];
webmastery_mcp_e2e_write_summary(
	getenv( 'WSTM111_PRIVACY_ARTIFACT' ) ?: dirname( __DIR__, 2 ) . '/e2e-artifacts/database-table-privacy.json',
	$report
);
if ( in_array( false, array_column( $report['cases'], 'passed' ), true ) ) {
	throw new RuntimeException( 'Database table privacy regression failed; inspect per-case evidence.' );
}
echo 'PASS database table privacy: ' . count( $report['cases'] ) . " cases\n";
