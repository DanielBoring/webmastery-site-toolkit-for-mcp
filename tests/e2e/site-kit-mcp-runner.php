<?php

// Executed by WP-CLI only on the disposable fixture site; all calls use real HTTP MCP.
define( 'WEBMASTERY_MCP_E2E_CLIENT_ONLY', true );
require_once __DIR__ . '/mcp-crud-runner.php';

if ( ! function_exists( 'webmastery_mcp_e2e_site_kit_dashboard_permission' ) ) {
	throw new RuntimeException( 'Site Kit MCP tests require the controlled MU-plugin fixture.' );
}

$wstm125_summary = array( 'transport' => 'http', 'provider' => 'controlled-mu-plugin-fixture', 'passed' => 0, 'failed' => 0, 'cases' => array() );
add_role( 'wstm125_no_read', 'Site Kit no read fixture', array( 'wstm125_site_kit_shared' => true ) );
add_role( 'wstm125_read', 'Site Kit read fixture', array( 'wstm125_site_kit_shared' => true, 'read' => true ) );
foreach ( array(
	'no_read' => 'wstm125_no_read',
	'read' => 'wstm125_read',
	'subscriber_denied' => 'subscriber',
	'subscriber_shared' => 'subscriber',
) as $scenario => $role ) {
	$login = "wstm125_mcp_{$scenario}";
	$user = get_user_by( 'login', $login );
	if ( ! $user ) {
		$id = wp_create_user( $login, wp_generate_password( 32 ), "{$login}@test.local" );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$user = new WP_User( $id );
	}
	$user->set_role( $role );
	if ( 'subscriber_shared' === $scenario ) {
		$user->add_cap( 'wstm125_site_kit_shared' );
	}
	$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'wstm125 transport regression' ) );
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( $created->get_error_message() );
	}
	$client = new Webmastery_MCP_E2E_Client( 'http://localhost/wp-json/mcp/mcp-adapter-default-server', $login, $created[0] );
	$initialized = false;
	try {
		$client->initialize();
		$initialized = true;
		webmastery_mcp_e2e_assert( 'no_read' !== $scenario, 'Default MCP endpoint unexpectedly admitted a no-read user.' );
		foreach ( array( 'list-site-kit-modules', 'get-site-kit-permissions', 'get-site-kit-pagespeed' ) as $slug ) {
			$label = "{$slug} {$scenario}";
			$params = 'get-site-kit-pagespeed' === $slug ? array( 'strategy' => 'mobile' ) : array();
			$result = webmastery_mcp_e2e_execute_ability( $client, "webmastery-site-toolkit-for-mcp/{$slug}", $params, $label );
			$allowed = in_array( $scenario, array( 'read', 'subscriber_shared' ), true );
			webmastery_mcp_e2e_assert( $allowed === ( true === ( $result['success'] ?? false ) ), "{$label}: unexpected MCP result " . webmastery_mcp_e2e_json( $result ) );
			if ( ! $allowed ) {
				$message = 'no_read' === $scenario ? 'Requires read capability.' : 'Google Site Kit does not permit';
				webmastery_mcp_e2e_assert( false !== strpos( webmastery_mcp_e2e_json( $result ), $message ), "{$label}: wrong denial layer " . webmastery_mcp_e2e_json( $result ) );
			} else {
				$data = $result['data'];
				if ( 'list-site-kit-modules' === $slug ) {
					webmastery_mcp_e2e_assert( 2 === $data['total'] && ! isset( $data['items'][0]['owner'] ), "{$label}: unsafe module projection" );
				} elseif ( 'get-site-kit-permissions' === $slug ) {
					webmastery_mcp_e2e_assert( true === $data['permissions']['view_shared_dashboard'], "{$label}: wrong permissions" );
				} else {
					webmastery_mcp_e2e_assert( 0.98 === $data['categories']['performance']['score'] && ! isset( $data['lighthouse_result'] ), "{$label}: wrong PageSpeed projection" );
				}
			}
			webmastery_mcp_e2e_pass( $wstm125_summary, $label );
		}
	} catch ( Throwable $error ) {
		if ( 'no_read' === $scenario && ! $initialized && false !== strpos( $error->getMessage(), 'HTTP POST returned 403:' ) && false !== strpos( $error->getMessage(), '"code":"rest_forbidden"' ) ) {
			webmastery_mcp_e2e_pass( $wstm125_summary, 'no-read user denied at default MCP HTTP boundary before ability execution' );
		} else {
			webmastery_mcp_e2e_fail( $wstm125_summary, $scenario, $error );
		}
	} finally {
		$client->close();
		WP_Application_Passwords::delete_application_password( $user->ID, $created[1]['uuid'] );
	}
}
webmastery_mcp_e2e_write_summary( __DIR__ . '/../../e2e-artifacts/issue125-mcp-summary.json', $wstm125_summary );
if ( $wstm125_summary['failed'] ) {
	throw new RuntimeException( 'Site Kit MCP transport regressions failed.' );
}
echo "SUMMARY wstm125 MCP {$wstm125_summary['passed']} passed, 0 failed\n";
