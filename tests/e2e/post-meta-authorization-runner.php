<?php
/**
 * Actual-core standalone metadata proof. Run only in disposable QA WordPress.
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';

function wstm110_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wstm110_http( string $method, array $params, array $credential, string &$session ): array {
	$headers = array( 'Content-Type' => 'application/json', 'Authorization' => 'Basic ' . base64_encode( $credential['login'] . ':' . $credential['password'] ) );
	if ( $session ) {
		$headers['Mcp-Session-Id'] = $session;
	}
	$body = array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params );
	if ( 'notifications/initialized' !== $method ) {
		$body['id'] = 1;
	}
	$response = wp_remote_post( 'http://localhost/wp-json/mcp/mcp-adapter-default-server', array(
		'headers' => $headers, 'body' => wp_json_encode( $body ), 'timeout' => 45,
	) );
	wstm110_assert( ! is_wp_error( $response ), 'MCP HTTP request failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );
	$status = wp_remote_retrieve_response_code( $response );
	wstm110_assert( $status >= 200 && $status < 300, 'Unexpected HTTP status ' . $status );
	$session = wp_remote_retrieve_header( $response, 'mcp-session-id' ) ?: $session;
	$raw = wp_remote_retrieve_body( $response );
	return '' === $raw ? array() : json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
}

function wstm110_payload( array $response ): array {
	wstm110_assert( ! isset( $response['error'] ), 'JSON-RPC failure: ' . wp_json_encode( $response['error'] ?? null ) );
	$tool = $response['result'] ?? array();
	$result = $tool['structuredContent'] ?? null;
	if ( null === $result ) {
		foreach ( $tool['content'] ?? array() as $content ) {
			if ( 'text' === ( $content['type'] ?? '' ) ) {
				$decoded = json_decode( $content['text'], true );
				if ( is_array( $decoded ) ) {
					$result = $decoded;
					break;
				}
			}
		}
	}
	if ( isset( $result['data']['success'] ) ) {
		$result = $result['data'];
	}
	wstm110_assert( is_array( $result ) && array_key_exists( 'success', $result ), 'Unrecognized MCP result: ' . wp_json_encode( $tool ) );
	return $result;
}

function wstm110_snapshot( int $id ): array {
	global $wpdb;
	wp_cache_flush();
	$meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $id ), ARRAY_A );
	wstm110_assert( '' === $wpdb->last_error, 'Metadata snapshot failed.' );
	return array( 'post' => get_post( $id, ARRAY_A ), 'meta' => $meta );
}

function wstm110_cases(): array {
	$cases = array(
		array( 'read restricted', 'get-post-meta', 'wstm110_restricted', false ),
		array( 'write restricted', 'update-post-meta', 'wstm110_restricted', false ),
		array( 'absent upsert restricted', 'update-post-meta', 'wstm110_absent', false ),
		array( 'unchanged restricted', 'update-post-meta', 'wstm110_restricted', false, array( 'value' => 'original' ) ),
		array( 'delete restricted', 'delete-post-meta', 'wstm110_restricted', false ),
		array( 'absent delete restricted', 'delete-post-meta', 'wstm110_absent', false ),
		array( 'listing hides restricted', 'get-post-meta', '', true ),
		array( 'authorized read', 'get-post-meta', 'wstm110_restricted', true, array( 'actor' => 'administrator' ) ),
		array( 'authorized write', 'update-post-meta', 'wstm110_restricted', true, array( 'actor' => 'administrator' ) ),
		array( 'custom grant upsert', 'update-post-meta', 'wstm110_absent', true, array( 'grant' => 'wstm110_manage_meta' ) ),
		array( 'primitive grant upsert', 'update-post-meta', 'wstm110_absent', true, array( 'grant' => 'edit_post_meta' ) ),
		array( 'authorized no-op', 'update-post-meta', 'wstm110_restricted', true, array( 'actor' => 'administrator', 'value' => 'original' ) ),
		array( 'authorized delete', 'delete-post-meta', 'wstm110_restricted', true, array( 'actor' => 'administrator' ) ),
		array( 'authorized absent delete', 'delete-post-meta', 'wstm110_absent', true, array( 'actor' => 'administrator' ) ),
		array( 'edit cap allowed', 'update-post-meta', 'wstm110_edit_only', true ),
		array( 'delete cap denied', 'delete-post-meta', 'wstm110_edit_only', false, array( 'actor' => 'administrator' ) ),
		array( 'global policy', 'update-post-meta', 'wstm110_global', false ),
		array( 'protected eligibility unchanged', 'update-post-meta', '_wstm110_hidden', false, array( 'actor' => 'administrator', 'code' => 'protected_meta_key' ) ),
	);
	foreach ( array( '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw' ) as $key ) {
		foreach ( array( 'get-post-meta', 'update-post-meta', 'delete-post-meta' ) as $ability ) {
			$cases[] = array( "real provider {$ability} {$key}", $ability, $key, null );
			$cases[] = array( "unregistered SEO {$ability} {$key}", $ability, $key, true, array( 'provider_policy' => 'unregistered' ) );
			foreach ( array( 'deny_map', 'deny_user' ) as $filter ) {
				$cases[] = array( "unregistered SEO {$filter} {$ability} {$key}", $ability, $key, false, array( 'provider_policy' => 'unregistered', $filter => true ) );
				$cases[] = array( "real provider {$filter} {$ability} {$key}", $ability, $key, false, array( $filter => true ) );
			}
		}
		$cases[] = array( "registered provider callback {$key}", 'update-post-meta', $key, null, array( 'provider_policy' => 'restricted' ) );
	}
	foreach ( array( 'deny_map', 'deny_user' ) as $filter ) {
		foreach ( array( 'get-post-meta', 'update-post-meta', 'delete-post-meta' ) as $ability ) {
			$cases[] = array( "open {$filter} {$ability}", $ability, 'wstm110_open', false, array( $filter => true ) );
		}
	}
	return $cases;
}

$boundary = getenv( 'WSTM110_BOUNDARY' ) ?: 'ability';
wstm110_assert( in_array( $boundary, array( 'direct', 'ability', 'http' ), true ), 'Unknown test boundary.' );
$summary = array( 'boundary' => $boundary, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'production_sha256' => hash_file( 'sha256', __DIR__ . '/../../includes/class-posts.php' ),
	'passed' => 0, 'failed' => 0, 'cases' => array() );
$credentials = array();
$sessions = array();
$posts = array();
$actors = array();
try {
	wstm110_assert( function_exists( 'wstm110_setup' ), 'Standalone metadata MU fixture is missing.' );
	foreach ( array( 'author', 'editor', 'administrator' ) as $role ) {
		$login = 'wstm110_' . $role;
		$user = get_user_by( 'login', $login );
		$id = $user ? $user->ID : wp_create_user( $login, wp_generate_password(), $login . '@example.test' );
		wstm110_assert( ! is_wp_error( $id ), 'Cannot create fixture actor.' );
		$user = new WP_User( $id );
		$user->set_role( $role );
		$actors[ $role ] = $user;
		if ( 'http' === $boundary ) {
			$password = WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Standalone metadata QA' ) );
			wstm110_assert( ! is_wp_error( $password ), 'Cannot create fixture application password.' );
			$credentials[ $role ] = array( 'login' => $login, 'password' => $password[0], 'uuid' => $password[1]['uuid'] );
			$sessions[ $role ] = '';
			wstm110_http( 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'metadata-qa', 'version' => '1' ) ), $credentials[ $role ], $sessions[ $role ] );
			wstm110_assert( '' !== $sessions[ $role ], 'MCP session missing.' );
			wstm110_http( 'notifications/initialized', array(), $credentials[ $role ], $sessions[ $role ] );
		}
	}
	foreach ( array( 'post' => 'author', 'page' => 'editor' ) as $type => $owner ) {
		foreach ( wstm110_cases() as $case ) {
			[ $label, $slug, $key, $expected ] = $case;
			$options = $case[4] ?? array();
			$role = $options['actor'] ?? $owner;
			$user = $actors[ $role ];
			$record = array( 'label' => "{$type}: {$label}", 'actor' => $role );
			$saved = array();
			try {
				delete_option( 'wstm110_policy' );
				wp_set_current_user( $actors[ $owner ]->ID );
				$id = wp_insert_post( array( 'post_type' => $type, 'post_author' => $actors[ $owner ]->ID, 'post_status' => 'draft', 'post_title' => 'Standalone metadata QA' ), true );
				wstm110_assert( ! is_wp_error( $id ) && $id > 0, 'Cannot insert fixture.' );
				$posts[] = $id;
				foreach ( array( 'wstm110_restricted', 'wstm110_open', 'wstm110_edit_only', 'wstm110_global', '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw' ) as $seed ) {
					update_post_meta( $id, $seed, 'original' );
				}
				update_post_meta( $id, 'wstm110_gate', 'ready' );
				$config = array_merge( $options, array( 'id' => $id, 'key' => $key ) );
				update_option( 'wstm110_policy', $config, false );
				delete_option( 'wstm110_events' );
				$saved = wstm110_setup( $config );
				if ( ! empty( $options['grant'] ) ) {
					$user->add_cap( $options['grant'] );
				}
				wp_set_current_user( 0 );
				wp_set_current_user( $user->ID );
				$cap = 'delete-post-meta' === $slug ? 'delete_post_meta' : 'edit_post_meta';
				$core = $key ? current_user_can( $cap, $id, $key ) : null;
				$record['core_allowed'] = $core;
				if ( null === $expected ) {
					$expected = $core;
				}
				if ( 'wstm110_global' === $key && 'page' === $type ) {
					$expected = true;
				}
				$record['expected_success'] = $expected;
				$input = array( 'post_id' => $id );
				if ( $key ) {
					$input['meta_key'] = $key;
				}
				if ( 'update-post-meta' === $slug ) {
					$input['meta_value'] = $options['value'] ?? 'changed';
				}
				$before = wstm110_snapshot( $id );
				$hook = "auth_post_meta_{$key}_for_{$type}";
				$hook_before = isset( $GLOBALS['wp_filter'][ $hook ] ) ? clone $GLOBALS['wp_filter'][ $hook ] : null;
				$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $slug );
				wstm110_assert( null !== $ability, 'Missing ability.' );
				if ( 'direct' === $boundary ) {
					$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
					$property->setAccessible( true );
					$result = ( $property->getValue( $ability ) )( $input );
				} elseif ( 'ability' === $boundary ) {
					$result = $ability->execute( $input );
				} else {
					$result = wstm110_payload( wstm110_http( 'tools/call', array( 'name' => 'mcp-adapter-execute-ability', 'arguments' => array( 'ability_name' => $ability->get_name(), 'parameters' => $input ) ), $credentials[ $role ], $sessions[ $role ] ) );
				}
				$record['result'] = $result;
				wstm110_assert( ! is_wp_error( $result ) && $expected === ( $result['success'] ?? null ), 'Unexpected success/denial: ' . wp_json_encode( $result ) );
				if ( ! $expected ) {
					wstm110_assert( ( $options['code'] ?? 'forbidden' ) === ( $result['error']['code'] ?? '' ), 'Wrong denial code.' );
					$after = wstm110_snapshot( $id );
					$record['before_sha256'] = hash( 'sha256', serialize( $before ) );
					$record['after_sha256'] = hash( 'sha256', serialize( $after ) );
					$record['mutation_hooks'] = get_option( 'wstm110_events', array() );
					wstm110_assert( $before === $after, 'Denied operation changed persisted state.' );
					wstm110_assert( array() === $record['mutation_hooks'], 'Denied operation reached metadata mutation hooks.' );
				} elseif ( 'get-post-meta' === $slug ) {
					if ( $key ) {
						wstm110_assert( array( 'original' ) === $result['data']['meta'][ $key ], 'Read shape/value changed.' );
					} else {
						wstm110_assert( ! isset( $result['data']['meta']['wstm110_restricted'] ) && array( 'original' ) === $result['data']['meta']['wstm110_open'], 'Listing did not filter restricted keys.' );
					}
				} elseif ( 'update-post-meta' === $slug ) {
					wp_cache_flush();
					$value = $input['meta_value'];
					wstm110_assert( $value === get_post_meta( $id, $key, true ) && $value === $result['data']['current_value'], 'Write did not persist/return the value.' );
					wstm110_assert( ( 'original' !== $value ) === $result['data']['updated'], 'Upsert/no-op flag changed.' );
				} else {
					wp_cache_flush();
					wstm110_assert( ! metadata_exists( 'post', $id, $key ), 'Delete did not persist.' );
					wstm110_assert( ( 'wstm110_absent' === $key ? 0 : 1 ) === $result['data']['deleted_count'], 'Delete count changed.' );
				}
				$hook_after = $GLOBALS['wp_filter'][ $hook ] ?? null;
				wstm110_assert( ( $hook_before ? $hook_before->callbacks : array() ) === ( $hook_after ? $hook_after->callbacks : array() ), 'Temporary authorization filter leaked.' );
				$record['temporary_filter_restored'] = true;
				$record['passed'] = true;
				$summary['passed']++;
			} catch ( Throwable $error ) {
				$record['passed'] = false;
				$record['error'] = $error->getMessage();
				$summary['failed']++;
			} finally {
				if ( ! empty( $options['grant'] ) ) {
					$user->remove_cap( $options['grant'] );
				}
				wstm110_restore( $saved );
				delete_option( 'wstm110_policy' );
			}
			$summary['cases'][] = $record;
			echo ( $record['passed'] ? 'PASS ' : 'FAIL ' ) . $record['label'] . ( isset( $record['error'] ) ? ': ' . $record['error'] : '' ) . "\n";
		}
	}
	// Exceptions must unwind the compatibility filter just like ordinary denials.
	wp_set_current_user( $actors['author']->ID );
	$id = $posts[0];
	$config = array( 'id' => $id, 'key' => '_yoast_wpseo_focuskw', 'provider_policy' => 'unregistered', 'throw' => true );
	update_option( 'wstm110_policy', $config, false );
	$saved = wstm110_setup( $config );
	try {
		$method = new ReflectionMethod( Webmastery_MCP_Posts::class, 'can_edit_post_meta_key' );
		$method->setAccessible( true );
		try {
			$method->invoke( null, $id, $config['key'] );
			throw new RuntimeException( 'Expected capability exception was not raised.' );
		} catch ( RuntimeException $error ) {
			wstm110_assert( 'wstm110 expected capability exception' === $error->getMessage(), $error->getMessage() );
		}
		wstm110_assert( ! has_filter( 'auth_post_meta__yoast_wpseo_focuskw_for_post' ), 'Exception leaked temporary filter.' );
		$summary['passed']++;
		$summary['cases'][] = array( 'label' => 'CLI helper exception restores temporary filter', 'passed' => true );
	} finally {
		wstm110_restore( $saved );
		delete_option( 'wstm110_policy' );
	}
	$actors['author']->add_cap( 'edit_post_meta' );
	try {
		wp_set_current_user( 0 );
		wp_set_current_user( $actors['author']->ID );
		$page = end( $posts );
		$method = new ReflectionMethod( Webmastery_MCP_Posts::class, 'can_edit_post_meta_key' );
		$method->setAccessible( true );
		wstm110_assert( false === $method->invoke( null, $page, 'wstm110_restricted' ), 'Primitive grant bypassed object edit floor.' );
		$summary['passed']++;
		$summary['cases'][] = array( 'label' => 'CLI helper retains object floor despite primitive grant', 'passed' => true );
	} finally {
		$actors['author']->remove_cap( 'edit_post_meta' );
	}
} catch ( Throwable $error ) {
	$summary['failed']++;
	$summary['fatal'] = $error->getMessage();
} finally {
	delete_option( 'wstm110_policy' );
	delete_option( 'wstm110_events' );
	foreach ( $credentials as $role => $credential ) {
		WP_Application_Passwords::delete_application_password( $actors[ $role ]->ID, $credential['uuid'] );
	}
	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
	$path = __DIR__ . '/../../e2e-artifacts/post-meta-authorization-' . $boundary . '.json';
	$json = wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	wstm110_assert( strlen( $json ) === file_put_contents( $path, $json ), 'Cannot write complete metadata QA evidence.' );
}
echo "Standalone metadata {$boundary}: {$summary['passed']} passed, {$summary['failed']} failed.\n";
exit( $summary['failed'] ? 1 : 0 );
