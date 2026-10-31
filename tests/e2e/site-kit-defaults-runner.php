<?php

// Current runtime regression; deliberately separate from historical manifest/schema inventories.
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'WSTM202_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Requires WSTM202_DISPOSABLE=1 in an owned disposable WordPress runtime.' );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
require_once __DIR__ . '/error-contract-assertions.php';
require_once __DIR__ . '/metadata-transport.php';

function wstm202_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

wstm202_assert( function_exists( 'webmastery_mcp_e2e_site_kit_dashboard_permission' ), 'Controlled Site Kit fixture is required.' );
$original_user = get_current_user_id();
$ability_name = 'webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed';
$ability = wp_get_ability( $ability_name );
$schema = $ability->get_input_schema();
wstm202_assert( 'mobile' === $schema['properties']['strategy']['default'] && ! in_array( 'strategy', $schema['required'] ?? array(), true ), 'Native schema must advertise optional mobile default.' );
$passed = 0;
try {
	foreach ( array( 'shared', 'denied', 'no-read' ) as $scenario ) {
		$login = 'wstm202_' . str_replace( '-', '_', $scenario );
		wstm202_assert( ! username_exists( $login ), 'Refusing to reuse a preexisting fixture user.' );
		$id = wp_create_user( $login, wp_generate_password( 32 ), $login . '@test.local' );
		wstm202_assert( ! is_wp_error( $id ), 'Could not create owned fixture user.' );
		$user = new WP_User( $id );
		$user->set_role( 'subscriber' );
		if ( 'denied' !== $scenario ) {
			$user->add_cap( 'wstm125_site_kit_shared' );
		}
		if ( 'no-read' === $scenario ) {
			$user->add_cap( 'read', false );
		}
		$credential = null;
		try {
			wp_set_current_user( $id );
			$credential = WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'PageSpeed defaults regression' ) );
			wstm202_assert( ! is_wp_error( $credential ), 'Could not create application password.' );
			foreach ( array( 'permission', 'direct', 'native-permission', 'native', 'gateway', 'individual' ) as $boundary ) {
				// HTTP rejects no-read users during endpoint authentication; direct/native prove the local floor.
				if ( 'no-read' === $scenario && in_array( $boundary, array( 'gateway', 'individual' ), true ) ) {
					continue;
				}
				$transport = null;
				try {
					if ( in_array( $boundary, array( 'gateway', 'individual' ), true ) ) {
						$transport = new Wstm110_Metadata_Transport( 'individual' === $boundary, array( 'login' => $login, 'password' => $credential[0] ) );
						$transport->initialize();
					}
					foreach ( array( array(), array( 'url' => home_url( '/sample-page/' ) ), array( 'strategy' => 'mobile' ), array( 'strategy' => 'desktop' ), array( 'strategy' => null ), array( 'strategy' => false ), array( 'strategy' => array( 'mobile' ) ), array( 'strategy' => 'both' ) ) as $input ) {
						$invalid = array_key_exists( 'strategy', $input ) && ! in_array( $input['strategy'], array( 'mobile', 'desktop' ), true );
						if ( $transport ) {
							$result = $transport->execute( $ability_name, $input );
						} elseif ( 'native-permission' === $boundary ) {
							$result = $ability->check_permissions( $input ? $input : null );
						} elseif ( 'native' === $boundary ) {
							$result = $ability->execute( $input ? $input : null );
						} else {
							$method = 'permission' === $boundary ? 'permission_pagespeed' : 'execute_pagespeed';
							$result = Webmastery_MCP_Site_Kit::$method( $input );
						}
						$label = "{$scenario} {$boundary} " . wp_json_encode( $input );
						if ( 'shared' === $scenario && ! $invalid ) {
							if ( in_array( $boundary, array( 'permission', 'native-permission' ), true ) ) {
								wstm202_assert( true === $result, $label );
							} else {
								$strategy = $input['strategy'] ?? 'mobile';
								wstm202_assert( true === ( $result['success'] ?? null ) && $strategy === $result['data']['strategy'] && ( $input['url'] ?? home_url( '/' ) ) === $result['data']['requested_url'], $label );
								wstm202_assert( ( 'desktop' === $strategy ? 0.99 : 0.98 ) === $result['data']['categories']['performance']['score'], $label . ': upstream did not receive expected strategy' );
							}
						} else {
							if ( is_wp_error( $result ) ) {
								$result = Webmastery_MCP_Response::from_wp_error( $result );
							}
							$expected_code = $invalid && ( 'no-read' !== $scenario || ! in_array( $boundary, array( 'direct', 'permission' ), true ) ) ? 'invalid_input' : 'forbidden';
							wstm202_assert( false === ( $result['success'] ?? null ) && $expected_code === ( $result['error']['code'] ?? null ), $label . ': expected ' . $expected_code );
						}
						++$passed;
					}
				} finally {
					if ( $transport ) {
						$transport->close();
					}
				}
			}
		} finally {
			wp_set_current_user( $original_user );
			if ( is_array( $credential ) ) {
				WP_Application_Passwords::delete_application_password( $id, $credential[1]['uuid'] );
			}
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wstm202_assert( wp_delete_user( $id ), 'Fixture user cleanup failed.' );
		}
	}
} finally {
	wp_set_current_user( $original_user );
}
echo "SUMMARY PageSpeed defaults {$passed} passed, 0 failed\n";
