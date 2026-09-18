<?php

/**
 * Run using wp eval-file in a disposable installation. The same predicates
 * evaluate baseline and fixed production files; JSON retains every outcome.
 */
require_once __DIR__ . '/diagnostics-fixture.php';
global $wpdb;

function wstm111_finding( array $response, string $check ): array {
	$found = array();
	foreach ( array( 'pass', 'warn', 'fail' ) as $bucket ) {
		foreach ( $response['data'][ $bucket ] as $finding ) {
			if ( $check === $finding['check'] ) {
				$found[] = array( 'bucket' => $bucket, 'finding' => $finding );
			}
		}
	}
	if ( 1 !== count( $found ) ) {
		throw new RuntimeException( "Expected exactly one {$check} finding." );
	}
	return $found[0];
}

function wstm111_snapshot(): array {
	global $wpdb;
	$snapshot = array();
	foreach ( array( $wpdb->options, $wpdb->posts, $wpdb->postmeta, $wpdb->users, $wpdb->usermeta ) as $table ) {
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY 1", ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not snapshot fixture database.' );
		}
		$snapshot[ $table ] = hash( 'sha256', wp_json_encode( $rows ) );
	}
	return $snapshot;
}

function wstm111_record( string $id, array $predicates, $actual, array $extra = array() ): void {
	$GLOBALS['wstm111_results'][] = array_merge(
		array( 'id' => $id, 'predicates' => $predicates, 'passed' => ! in_array( false, $predicates, true ), 'actual' => $actual ),
		$extra
	);
}

function wstm111_invoke( string $ability, string $mode, array $scenario ): array {
	global $wpdb;
	$before = wstm111_snapshot();
	$suppressed_before = $wpdb->suppress_errors;
	$fixture = new Webmastery_MCP_Diagnostics_Fixture( $scenario );
	try {
		$result = 'wrapper' === $mode
			? wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $ability )->execute()
			: ( 'security-audit' === $ability ? Webmastery_MCP_Security::execute() : Webmastery_MCP_Database_Health::execute() );
		$request_ssl = is_ssl();
		$raw_error = $wpdb->last_error;
		$core_home_scheme = is_string( get_option( 'home' ) ) ? wp_parse_url( home_url(), PHP_URL_SCHEME ) : 'not called: non-string fixture';
	} finally {
		$fixture->restore();
	}
	$after = wstm111_snapshot();
	$mutations = array_values( array_filter( $fixture->queries, static fn( $query ) => (bool) preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $query ) ) );
	return array(
		'result' => $result,
		'invariants' => array(
			'no_outbound_probes' => 0 === $fixture->outbound,
			'no_write_queries' => array() === $mutations,
			'snapshot_unchanged' => $before === $after,
			'logging_restored' => $suppressed_before === $wpdb->suppress_errors,
		),
		'evidence' => array(
			'before' => $before, 'after' => $after,
			'queries' => $fixture->queries, 'outbound' => $fixture->outbound,
			'injected' => $fixture->injected, 'raw_error' => $raw_error,
			'simulated_request_ssl' => $request_ssl, 'core_home_url_scheme' => $core_home_scheme,
			'full_response' => is_array( $result ) ? $result : null,
		),
	);
}

wp_set_current_user( (int) get_user_by( 'login', 'admin' )->ID );
// Warm core caches before read-only snapshots, not during measured callbacks.
get_site_transient( 'update_core' );
get_site_transient( 'update_plugins' );
get_user_by( 'login', 'admin' );
$GLOBALS['wstm111_results'] = array();
$mode_arg = $args[0] ?? 'all';

if ( 'home' === $mode_arg ) {
	foreach ( array( 'direct', 'wrapper' ) as $mode ) {
		$call = wstm111_invoke( 'security-audit', $mode, array() );
		$actual = wstm111_finding( $call['result'], 'ssl' );
		wstm111_record( "wp-home/{$mode}", array(
			'wp_home_constant' => defined( 'WP_HOME' ) && get_option( 'home' ) === WP_HOME,
			'expected_bucket' => 'pass' === $actual['bucket'],
			'configuration_label' => 'Public home URL is configured to use HTTPS' === $actual['finding']['label'],
		) + $call['invariants'], $actual, $call['evidence'] );
	}
} elseif ( 'debug' === $mode_arg ) {
	foreach ( array( 'direct', 'wrapper' ) as $mode ) {
		$call = wstm111_invoke( 'security-audit', $mode, array() );
		$actual = wstm111_finding( $call['result'], 'debug_log' );
		$json = wp_json_encode( $actual, JSON_UNESCAPED_SLASHES );
		$enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		$predicates = array(
			'expected_bucket' => ( $enabled ? 'warn' : 'pass' ) === $actual['bucket'],
			'no_absolute_content_path' => ! str_contains( $json, WP_CONTENT_DIR ),
			'no_private_sentinel' => ! str_contains( $json, 'wstm111-private' ),
			'no_html_entities' => ! preg_match( '/&(?:amp|lt|gt|quot|#0?39);/', $json ),
			'json_roundtrip' => json_decode( $json, true ) === $actual,
			'no_protection_claim' => ! str_contains( $json, 'is protected or outside web root' ),
		);
		wstm111_record( "debug/{$mode}", $predicates + $call['invariants'], $actual, $call['evidence'] );
	}
} else {
	$cases = array(
		'https-internal-http' => array( 'home' => 'https://wstm111.example.test', 'https' => false, 'bucket' => 'pass' ),
		'http-https-request' => array( 'home' => 'http://wstm111.example.test', 'https' => true, 'bucket' => 'fail' ),
		'http-admin-only-tls' => array( 'home' => 'http://wstm111.example.test', 'https' => false, 'admin_ssl' => true, 'bucket' => 'fail' ),
		'https-matching' => array( 'home' => 'https://wstm111.example.test', 'https' => true, 'bucket' => 'pass' ),
		'http-matching' => array( 'home' => 'http://wstm111.example.test', 'https' => false, 'bucket' => 'fail' ),
		'https-case-credentials' => array( 'home' => 'HtTpS://private:secret@wstm111.example.test', 'https' => false, 'bucket' => 'pass' ),
		'http-case' => array( 'home' => 'HTTP://wstm111.example.test', 'https' => true, 'bucket' => 'fail' ),
		'invalid-port' => array( 'home' => 'https://wstm111.example.test:99999', 'https' => true, 'bucket' => 'warn' ),
		'unknown-scheme' => array( 'home' => 'ftp://wstm111.example.test', 'https' => true, 'bucket' => 'warn' ),
		'no-host' => array( 'home' => 'https:/path', 'https' => true, 'bucket' => 'warn' ),
		'non-string' => array( 'home' => array( 'https://wstm111.example.test' ), 'https' => true, 'bucket' => 'warn' ),
		'null' => array( 'home' => null, 'https' => true, 'bucket' => 'warn' ),
		'false' => array( 'home' => false, 'https' => true, 'bucket' => 'warn' ),
		'host-space' => array( 'home' => 'https://bad host.test', 'https' => true, 'bucket' => 'warn' ),
		'empty' => array( 'home' => '', 'https' => false, 'bucket' => 'warn' ),
	);
	foreach ( array( 'direct', 'wrapper' ) as $mode ) {
		foreach ( $cases as $id => $scenario ) {
			// home_url() itself cannot accept a non-string fixture; measure production only.
			$call = wstm111_invoke( 'security-audit', $mode, $scenario );
			$actual = wstm111_finding( $call['result'], 'ssl' );
			$json = wp_json_encode( $actual );
			wstm111_record( "ssl/{$mode}/{$id}", array(
				'expected_bucket' => $scenario['bucket'] === $actual['bucket'],
				'configuration_label' => str_contains( $actual['finding']['label'], 'Public home URL' ),
				'no_private_url' => ! str_contains( $json, 'wstm111.example.test' ) && ! str_contains( $json, 'secret' ),
			) + $call['invariants'], $actual, $call['evidence'] );
		}
		foreach ( Webmastery_MCP_Diagnostics_Fixture::ERROR_CONTEXTS as $context => $pattern ) {
			$call = wstm111_invoke( 'database-health', $mode, array( 'db_failure' => $context ) );
			$error = $call['result'];
			$actual = is_wp_error( $error ) ? array( 'type' => get_class( $error ), 'code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'data' => $error->get_error_data() ) : $error;
			wstm111_record( "database/{$mode}/{$context}", array(
				'wp_error' => $error instanceof WP_Error,
				'same_code' => is_wp_error( $error ) && 'database_health_query_failed' === $error->get_error_code(),
				'sanitized_context' => is_wp_error( $error ) && "Database health query failed while reading {$context}." === $error->get_error_message(),
				'no_raw_sentinel' => ! str_contains( wp_json_encode( $actual ), 'wstm111_RAW_SQL_PRIVATE_SENTINEL' ),
				'real_query_failure' => 1 === $call['evidence']['injected'] && str_contains( $call['evidence']['raw_error'], 'wstm111_RAW_SQL_PRIVATE_SENTINEL' ),
			) + $call['invariants'], $actual, $call['evidence'] );
		}
		$call = wstm111_invoke( 'database-health', $mode, array() );
		$data = $call['result']['data'];
		$tables = array_column( $data['table_sizes'], 'table' );
		$expected_revisions = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$expected_orphans = (int) $wpdb->get_var( "SELECT COUNT(pm.meta_id) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
		$expected_expired = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) );
		$autoload_values = wp_autoload_values_to_autoload();
		$expected_autoload = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(option_name) + LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload IN (" . implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) ) . ')', ...$autoload_values ) );
		$table_shape = true;
		foreach ( $data['table_sizes'] as $row ) {
			$table_shape = $table_shape && array( 'table', 'rows', 'data_bytes', 'index_bytes', 'total_bytes' ) === array_keys( $row )
				&& is_string( $row['table'] ) && is_int( $row['rows'] ) && is_int( $row['data_bytes'] )
				&& is_int( $row['index_bytes'] ) && is_int( $row['total_bytes'] )
				&& $row['data_bytes'] + $row['index_bytes'] === $row['total_bytes'];
		}
		wstm111_record( "database/{$mode}/success", array(
			'success_shape' => true === $call['result']['success'] && 5 === count( $data ),
			'prefixed_posts_present' => in_array( $wpdb->posts, $tables, true ),
			'prefixed_plugin_table_present' => in_array( $wpdb->prefix . 'wstm111_plugin_data', $tables, true ),
			'revision_counter' => $expected_revisions === $data['post_revisions']['count'],
			'orphan_counter' => $expected_orphans === $data['orphaned_post_meta']['count'],
			'expired_counter' => $expected_expired === $data['expired_transients']['count'],
			'autoload_counter' => $expected_autoload === $data['autoloaded_options']['total_bytes'],
			'table_keys_types_arithmetic' => $table_shape,
			'threshold_unchanged' => 921600 === $data['autoloaded_options']['threshold_bytes'],
		) + $call['invariants'], $call['result'], $call['evidence'] );
	}
	foreach ( array( 'admin', 'editor_test', 'subscriber_test' ) as $login ) {
		wp_set_current_user( (int) get_user_by( 'login', $login )->ID );
		foreach ( array( 'security-audit', 'database-health' ) as $ability ) {
			$registered = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $ability );
			$permission = $registered->check_permissions();
			$call = wstm111_invoke( $ability, 'wrapper', array() );
			$result = $call['result'];
			wstm111_record( "permission/{$login}/{$ability}", array(
				'permission_unchanged' => 'admin' === $login ? ! is_wp_error( $result ) : is_wp_error( $result ) && 'ability_invalid_permissions' === $result->get_error_code(),
				'callback_permission_unchanged' => 'admin' === $login ? true === $permission : is_wp_error( $permission ) && 'forbidden' === $permission->get_error_code(),
				'readonly_annotation' => true === $registered->get_meta()['annotations']['readonly'],
			) + $call['invariants'], is_wp_error( $result ) ? array( 'type' => get_class( $result ), 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) : $result );
		}
	}
}

$report = array(
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'transport_note' => 'CLI direct/wrapper calls with simulated request variables; not TLS/certificate tests.',
	'runner_sha256' => hash_file( 'sha256', __DIR__ . '/diagnostics-runner.php' ),
	'fixture_sha256' => hash_file( 'sha256', __DIR__ . '/diagnostics-fixture.php' ),
	'source_sha256' => array(
		'security' => hash_file( 'sha256', WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/includes/class-security.php' ),
		'database' => hash_file( 'sha256', WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/includes/class-database-health.php' ),
	),
	'results' => $GLOBALS['wstm111_results'],
);
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( in_array( false, array_column( $report['results'], 'passed' ), true ) ) {
	exit( 1 );
}
