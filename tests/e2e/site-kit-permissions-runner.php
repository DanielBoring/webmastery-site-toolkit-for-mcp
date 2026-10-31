<?php
require_once __DIR__ . '/legacy-artifacts.php';

// Run with wp eval-file on the disposable Site Kit fixture installation.
if ( ! function_exists( 'webmastery_mcp_e2e_site_kit_dashboard_permission' ) ) {
	throw new RuntimeException( 'Site Kit permission regressions require the controlled MU-plugin fixture.' );
}

$wstm125_original_user = get_current_user_id();
$wstm125_results = array();
$wstm125_counts = array();
$wstm125_mode = 'allow';
$wstm125_target = '';
$wstm125_routes = static function ( $routes ) use ( &$wstm125_counts, &$wstm125_mode, &$wstm125_target ) {
	++$wstm125_counts['routes'];
	foreach ( $routes as $path => &$endpoints ) {
		if ( 0 !== strpos( $path, '/google-site-kit/v1/' ) ) {
			continue;
		}
		if ( $path === $wstm125_target && 'missing-route' === $wstm125_mode ) {
			unset( $routes[ $path ] );
			continue;
		}
		foreach ( $endpoints as &$endpoint ) {
			if ( ! is_array( $endpoint ) || ! isset( $endpoint['callback'] ) ) {
				continue;
			}
			$callback = $endpoint['callback'];
			$endpoint['callback'] = static function ( $request ) use ( $callback, &$wstm125_counts, $path, &$wstm125_target ) {
				++$wstm125_counts['data'];
				if ( $path === $wstm125_target ) {
					++$wstm125_counts['target_data'];
				}
				return call_user_func( $callback, $request );
			};
			if ( $path === $wstm125_target && 'missing-callback' === $wstm125_mode ) {
				unset( $endpoint['permission_callback'] );
			} elseif ( $path === $wstm125_target && 'uncallable-callback' === $wstm125_mode ) {
				$endpoint['permission_callback'] = 'wstm125_nonexistent_callback';
			} else {
				$endpoint['permission_callback'] = static function () use ( &$wstm125_counts, &$wstm125_mode, &$wstm125_target, $path ) {
					++$wstm125_counts['permission'];
					if ( $path !== $wstm125_target ) {
						return true;
					}
					++$wstm125_counts['target_permission'];
					switch ( $wstm125_mode ) {
						case 'false':
							return false;
						case 'null':
							return null;
						case 'error':
							return new WP_Error( 'wstm125_denied', 'PRIVATE provider message', array( 'status' => 403, 'context' => 'PRIVATE provider data' ) );
						default:
							return true;
					}
				};
			}
		}
		unset( $endpoint );
	}
	unset( $endpoints );
	return $routes;
};
$wstm125_http = static function () use ( &$wstm125_counts ) {
	++$wstm125_counts['http'];
	return new WP_Error( 'wstm125_http_blocked', 'No external requests are allowed in Site Kit fixture tests.' );
};

add_filter( 'rest_endpoints', $wstm125_routes );
add_filter( 'pre_http_request', $wstm125_http );
try {
	foreach ( array(
		'modules' => '/core/modules/data/list',
		'permissions' => '/core/user/data/permissions',
		'pagespeed' => '/modules/pagespeed-insights/data/pagespeed',
	) as $method => $path ) {
		$wstm125_target = '/google-site-kit/v1' . $path;
		foreach ( array( 'wstm125_no_read', 'wstm125_read', 'subscriber_test' ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( ! $user ) {
				throw new RuntimeException( "Missing fixture user {$login}; run ability-runner.php first." );
			}
			wp_set_current_user( $user->ID );
			foreach ( array( 'allow', 'false', 'null', 'error', 'missing-route', 'missing-callback', 'uncallable-callback' ) as $wstm125_mode ) {
				foreach ( array( 'permission', 'execute' ) as $prefix ) {
					$wstm125_counts = array( 'routes' => 0, 'permission' => 0, 'target_permission' => 0, 'data' => 0, 'target_data' => 0, 'http' => 0 );
					$result = call_user_func(
						array( Webmastery_MCP_Site_Kit::class, "{$prefix}_{$method}" ),
						array( 'strategy' => 'mobile', 'url' => home_url( '/sample-page/' ) )
					);
					$no_read = 'wstm125_no_read' === $login;
					$allowed = ! $no_read && 'allow' === $wstm125_mode;
					$passed = $allowed
						? ( 'permission' === $prefix ? true === $result : is_array( $result ) && true === $result['success'] )
						: is_wp_error( $result );
					if ( ! $allowed && is_wp_error( $result ) ) {
						$expected = $no_read || in_array( $wstm125_mode, array( 'false', 'null', 'error' ), true ) ? 'forbidden' : 'site_kit_unsupported';
						$passed = $passed && $expected === $result->get_error_code() && 0 === $wstm125_counts['target_data'];
						$public = Webmastery_MCP_Response::from_wp_error( $result );
						$message = $public['error']['message'];
						$passed = $passed && false === strpos( wp_json_encode( $public ), 'PRIVATE' );
						if ( ! $no_read && 'forbidden' === $expected ) {
							foreach ( array( 'Possible causes', 'Dashboard sharing', 'sign-in/reauthentication', 'sharing icon', 'dashboard header', 'get-site-kit-status', 'get-site-kit-permissions' ) as $text ) {
								$passed = $passed && false !== strpos( $message, $text );
							}
							// Guidance must not dispatch status/permission diagnostic reads after denial.
							$expected_data = 'pagespeed' === $method && 'execute' === $prefix ? 1 : 0;
							$passed = $passed && 1 === $wstm125_counts['target_permission'] && $expected_data === $wstm125_counts['data'];
						} else {
							$passed = $passed && false === strpos( $message, 'Dashboard sharing' );
						}
					}
					if ( $no_read ) {
						$passed = $passed && 0 === array_sum( $wstm125_counts );
					}
					if ( $allowed ) {
						$passed = $passed && 1 === $wstm125_counts['target_permission'];
					}
					$passed = $passed && 0 === $wstm125_counts['http'];
					$label = "{$prefix}_{$method} {$login} upstream={$wstm125_mode}";
					$wstm125_results[] = array( 'label' => $label, 'passed' => $passed, 'calls' => $wstm125_counts );
					echo ( $passed ? 'PASS ' : 'FAIL ' ) . "wstm125 {$label}\n";
				}
			}
		}
	}
} finally {
	remove_filter( 'rest_endpoints', $wstm125_routes );
	remove_filter( 'pre_http_request', $wstm125_http );
	wp_set_current_user( $wstm125_original_user );
}
$wstm125_failed = count( array_filter( $wstm125_results, static function ( $case ) { return ! $case['passed']; } ) );
$wstm125_summary_path = Wstm_E2E_Artifacts::path( WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/e2e-artifacts/issue125-permission-summary.json' );
$wstm125_summary_directory = dirname( $wstm125_summary_path );
if ( ! is_dir( $wstm125_summary_directory ) && ! wp_mkdir_p( $wstm125_summary_directory ) ) {
	throw new RuntimeException( 'Could not create Site Kit permission summary directory.' );
}
$wstm125_json = wp_json_encode( array( 'passed' => count( $wstm125_results ) - $wstm125_failed, 'failed' => $wstm125_failed, 'cases' => $wstm125_results ), JSON_PRETTY_PRINT );
if ( false === $wstm125_json ) {
	throw new RuntimeException( 'Could not encode Site Kit permission regression summary.' );
}
$wstm125_written = file_put_contents(
	$wstm125_summary_path,
	$wstm125_json
);
if ( strlen( $wstm125_json ) !== $wstm125_written ) {
	throw new RuntimeException( "Could not write Site Kit permission regression summary: {$wstm125_summary_path}" );
}
if ( $wstm125_failed ) {
	throw new RuntimeException( "{$wstm125_failed} Site Kit permission regression cases failed." );
}
echo 'SUMMARY wstm125 ' . count( $wstm125_results ) . " passed, 0 failed\n";
