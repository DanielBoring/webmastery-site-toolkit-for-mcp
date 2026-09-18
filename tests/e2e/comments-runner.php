<?php

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
define( 'WEBMASTERY_MCP_E2E_CLIENT_ONLY', true );
require_once __DIR__ . '/mcp-crud-runner.php';
require_once __DIR__ . '/comments-fixture.php';

function wstm105_snapshot(): array {
	global $wpdb;
	wp_cache_flush();
	$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->comments} ORDER BY comment_ID", ARRAY_A );
	wstm105_assert( '' === $wpdb->last_error, 'Comment snapshot query failed.' );
	$meta = $wpdb->get_results( "SELECT * FROM {$wpdb->commentmeta} ORDER BY meta_id", ARRAY_A );
	wstm105_assert( '' === $wpdb->last_error, 'Comment metadata snapshot query failed.' );
	return array( 'rows' => $rows, 'meta' => $meta );
}

function wstm105_error_value( $value ) {
	return is_wp_error( $value )
		? array( 'wp_error' => array( 'code' => $value->get_error_code(), 'message' => $value->get_error_message(), 'data' => $value->get_error_data() ) )
		: $value;
}

function wstm105_missing_result( string $action ): array {
	return array( 'success' => false, 'error' => 'update' === $action ? array( 'code' => 'not_found', 'message' => 'Comment not found.' ) : 'Comment not found.' );
}

$mode = getenv( 'WSTM105_MODE' ) ?: 'fixed';
$boundary_filter = getenv( 'WSTM105_BOUNDARY' ) ?: 'all';
wstm105_assert( in_array( $mode, array( 'fixed', 'baseline' ), true ), 'Unknown proof mode.' );
wstm105_assert( in_array( $boundary_filter, array( 'all', 'direct', 'ability', 'http' ), true ), 'Unknown proof boundary.' );
$artifact = getenv( 'WSTM105_ARTIFACT' ) ?: __DIR__ . '/../../e2e-artifacts/comments-' . $boundary_filter . '.json';
$summary = array(
	'mode' => $mode, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'runner_sha256' => hash_file( 'sha256', __FILE__ ),
	'passed' => 0, 'failed' => 0, 'cases' => array(),
);
$clients = array();
$passwords = array();

try {
	wstm105_assert( post_type_exists( 'mcp_book' ), 'Mapped CPT fixture is required.' );
	$custom_roles = array(
		'comment_moderator' => array( 'read', 'moderate_comments' ),
		'wstm105_own_editor' => array( 'read', 'moderate_comments', 'edit_posts' ),
		'wstm105_mapped_moderator' => array( 'read', 'moderate_comments', 'edit_mcp_books', 'edit_others_mcp_books', 'edit_published_mcp_books' ),
	);
	foreach ( $custom_roles as $role => $caps ) {
		add_role( $role, $role, array_fill_keys( $caps, true ) );
		$expected_caps = array_fill_keys( $caps, true );
		$actual_caps = get_role( $role )->capabilities;
		ksort( $expected_caps );
		ksort( $actual_caps );
		wstm105_assert( $expected_caps === $actual_caps, "{$role} fixture capabilities changed." );
	}
	$actors = array();
	foreach ( array( 'editor', 'administrator', 'author', 'subscriber', 'comment_moderator', 'wstm105_own_editor', 'wstm105_mapped_moderator' ) as $role ) {
		$login = "wstm105_proof_{$role}";
		$user = get_user_by( 'login', $login );
		$id = $user ? $user->ID : wp_create_user( $login, wp_generate_password(), "{$login}@example.test" );
		wstm105_assert( ! is_wp_error( $id ), 'Cannot create comment proof actor.' );
		$user = new WP_User( $id );
		$user->set_role( $role );
		$actors[ $role ] = (int) $id;
		if ( in_array( $boundary_filter, array( 'all', 'http' ), true ) ) {
			$created = WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'wstm105 comment proof' ) );
			wstm105_assert( ! is_wp_error( $created ), 'Cannot create fixture application password.' );
			$passwords[ $role ] = $created[1]['uuid'];
			$clients[ $role ] = new Webmastery_MCP_E2E_Client( 'http://localhost/wp-json/mcp/mcp-adapter-default-server', $login, $created[0] );
			$clients[ $role ]->initialize();
		}
	}
	$posts = array();
	foreach ( array( 'other' => array( 'post', 'author', 'publish' ), 'own' => array( 'post', 'wstm105_own_editor', 'draft' ), 'author' => array( 'post', 'author', 'draft' ), 'cpt' => array( 'mcp_book', 'author', 'publish' ) ) as $scope => list( $type, $owner, $status ) ) {
		$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => "WSTM105 {$scope}", 'post_author' => $actors[ $owner ] ), true );
		wstm105_assert( ! is_wp_error( $id ) && $id > 0, 'Cannot create comment proof parent.' );
		$posts[ $scope ] = (int) $id;
	}
	foreach ( array( 'direct', 'ability', 'http' ) as $boundary ) {
		if ( 'all' !== $boundary_filter && $boundary !== $boundary_filter ) {
			continue;
		}
		foreach ( array( 'update', 'approve', 'trash', 'spam' ) as $action ) {
			$name = "webmastery-site-toolkit-for-mcp/{$action}-comment";
			$ability = wp_get_ability( $name );
			wstm105_assert( null !== $ability, "Missing {$name}." );
			$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
			$property->setAccessible( true );
			$execute = $property->getValue( $ability );
			$source = ( new ReflectionFunction( $execute ) )->getFileName();
			$summary['production_sha256'] = hash_file( 'sha256', $source );
			$scenarios = array(
				'moderator_other' => array( 'comment_moderator', 'other', true, false ),
				'own_allowed' => array( 'wstm105_own_editor', 'own', true, true ),
				'own_other_denied' => array( 'wstm105_own_editor', 'other', true, false ),
				'author_floor' => array( 'author', 'author', false, true ),
				'subscriber_floor' => array( 'subscriber', 'other', false, false ),
				'editor_allowed' => array( 'editor', 'other', true, true ),
				'admin_allowed' => array( 'administrator', 'other', true, true ),
				'cpt_denied' => array( 'editor', 'cpt', true, false ),
				'cpt_allowed' => array( 'wstm105_mapped_moderator', 'cpt', true, true ),
			);
			foreach ( array( 'editor', 'subscriber' ) as $role ) {
				foreach ( array( 'missing', 'zero', 'negative_missing', 'negative_existing', 'missing_id', 'string_id', 'array_id', 'null_id' ) as $invalid ) {
					$scenarios[ "{$role}_{$invalid}" ] = array( $role, 'other', 'editor' === $role, 'editor' === $role, $invalid );
				}
			}
			if ( 'direct' === $boundary ) {
				$scenarios['global_zero'] = array( 'editor', 'other', true, true, 'global_zero' );
			}
			foreach ( $scenarios as $scenario => list( $role, $scope, $moderate, $edit ) ) {
				$invalid = $scenarios[ $scenario ][4] ?? '';
				// Malformed direct calls had no stable old contract (warnings/coercion); fixed-only checks are explicit.
				if ( 'baseline' === $mode && 'direct' === $boundary && in_array( $invalid, array( 'missing_id', 'string_id', 'array_id', 'null_id' ), true ) ) {
					continue;
				}
				$label = "{$boundary}:{$action}:{$scenario}";
				$record = array( 'label' => $label, 'boundary' => $boundary, 'action' => $action, 'scenario' => $scenario );
				try {
					wp_set_current_user( $actors[ $role ] );
					$id = wp_insert_comment( array( 'comment_post_ID' => $posts[ $scope ], 'comment_content' => 'WSTM105 original.', 'comment_approved' => '0' ) );
					wstm105_assert( $id > 0, 'Cannot create proof comment.' );
					add_comment_meta( $id, 'wstm105_marker', $label );
					$input = array( 'comment_id' => $id );
					if ( 'update' === $action ) {
						$input['content'] = 'WSTM105 changed.';
						$input['status'] = 'spam';
					}
					unset( $GLOBALS['comment'] );
					switch ( $invalid ) {
						case 'missing': $input['comment_id'] = 2147483647; break;
						case 'zero': $input['comment_id'] = 0; break;
						case 'negative_missing': $input['comment_id'] = -2147483647; break;
						case 'negative_existing': $input['comment_id'] = -$id; break;
						case 'missing_id': unset( $input['comment_id'] ); break;
						case 'string_id': $input['comment_id'] = 'not-an-id'; break;
						case 'array_id': $input['comment_id'] = array( $id ); break;
						case 'null_id': $input['comment_id'] = null; break;
						case 'global_zero':
							$GLOBALS['comment'] = get_comment( $id );
							$input['comment_id'] = 0;
							break;
					}
					$record['input'] = $input;
					$record['capabilities'] = array( 'moderate_comments' => current_user_can( 'moderate_comments' ), 'edit_comment' => current_user_can( 'edit_comment', $id ) );
					wstm105_assert( array( 'moderate_comments' => $moderate, 'edit_comment' => $edit ) === $record['capabilities'], 'Fixture capability mismatch.' );
					$before = wstm105_snapshot();
					$record['before_sha256'] = hash( 'sha256', serialize( $before ) );
					$record['before'] = wstm105_comment_state( $id );
					if ( 'http' === $boundary ) {
						$raw = $clients[ $role ]->call( 'tools/call', array( 'name' => 'mcp-adapter-execute-ability', 'arguments' => array( 'ability_name' => $name, 'parameters' => (object) $input ) ) );
						$record['raw'] = $raw;
						$result = true === ( $raw['isError'] ?? false ) ? array( 'mcp_error' => $raw ) : webmastery_mcp_e2e_extract_tool_payload( $raw, $label );
						$record['envelope'] = $result;
						if ( true === ( $result['success'] ?? null ) && isset( $result['data']['success'] ) ) {
							$result = $result['data'];
						}
					} else {
						$result = 'direct' === $boundary ? $execute( $input ) : $ability->execute( $input );
					}
					$record['response'] = wstm105_error_value( $result );
					$after = wstm105_snapshot();
					$record['after_sha256'] = hash( 'sha256', serialize( $after ) );
					$record['after'] = wstm105_comment_state( $id );
					$schema_invalid = 'direct' !== $boundary && in_array( $invalid, array( 'missing_id', 'string_id', 'array_id', 'null_id' ), true );
					$schema_message = sprintf( 'Ability "%s" has invalid input. Reason: %s', $name, 'missing_id' === $invalid ? 'comment_id is a required property of input.' : 'input[comment_id] is not of type integer.' );
					$legacy_target = in_array( $invalid, array( '', 'negative_existing', 'global_zero' ), true );
					$allowed = 'baseline' === $mode ? $legacy_target && ( 'direct' === $boundary || $moderate ) : '' === $invalid && $moderate && $edit;
					wstm105_assert( $allowed === ( is_array( $result ) && true === ( $result['success'] ?? false ) ), 'Unexpected success/failure.' );
					if ( $allowed ) {
						$status = array( 'update' => 'spam', 'approve' => 'approved', 'trash' => 'trash', 'spam' => 'spam' )[ $action ];
						wstm105_assert( array( 'content' => 'update' === $action ? 'WSTM105 changed.' : 'WSTM105 original.', 'status' => $status ) === $record['after'], 'Successful write did not persist exactly.' );
						$response_id = 'baseline' === $mode && 'global_zero' === $invalid && 'update' !== $action ? 0 : $id;
						$response_status = 0 === $response_id ? 'unapproved' : $status;
						wstm105_assert( $response_id === $result['data']['id'] && $response_status === $result['data']['status'], 'Success response changed.' );
					} else {
						wstm105_assert( $before === $after, 'Denied/invalid call changed persisted comments or metadata.' );
						$permission_denied = ! $schema_invalid && ( '' === $invalid ? ! $moderate || ! $edit : ! $moderate ) && 'direct' !== $boundary;
						if ( ! $schema_invalid && ! $permission_denied && '' !== $invalid ) {
							wstm105_assert( wstm105_missing_result( $action ) === $result, 'Missing/invalid direct result changed.' );
							if ( 'http' === $boundary ) {
								wstm105_assert( array( 'success' => true, 'data' => $result ) === $record['envelope'], 'Missing-object HTTP gateway envelope changed.' );
								wstm105_assert( array(
									'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $record['envelope'] ) ) ),
									'structuredContent' => $record['envelope'], 'isError' => false,
								) === $raw, 'Missing-object raw MCP tool result changed.' );
							}
						} elseif ( 'ability' === $boundary ) {
							$code = $schema_invalid ? 'ability_invalid_input' : 'ability_invalid_permissions';
							wstm105_assert( is_wp_error( $result ) && $code === $result->get_error_code(), 'Core wrapper error code changed.' );
							if ( ! $schema_invalid ) {
								wstm105_assert( sprintf( 'Ability "%s" does not have necessary permission.', $name ) === $result->get_error_message(), 'Core permission message changed.' );
							} else {
								wstm105_assert( $schema_message === $result->get_error_message(), 'Core input message changed.' );
							}
						} elseif ( 'direct' === $boundary ) {
							$message = ! $moderate ? 'Requires moderate_comments capability.' : 'Requires edit_comment capability for this comment.';
							$expected = array( 'success' => false, 'error' => 'update' === $action ? array( 'code' => 'forbidden', 'message' => $message ) : $message );
							wstm105_assert( $expected === $result, 'Direct forbidden response changed.' );
						} else {
							$message = ! $moderate ? 'Requires moderate_comments capability.' : ( $schema_invalid ? $schema_message : 'Requires edit_comment capability for this comment.' );
							wstm105_assert( array( 'content' => array( array( 'type' => 'text', 'text' => $message ) ), 'isError' => true ) === $raw, 'HTTP denial envelope changed.' );
						}
					}
					$record['compatibility'] = in_array( $invalid, array( 'missing', 'zero', 'negative_missing' ), true )
						|| ( $schema_invalid )
						|| ( '' === $invalid && ! $moderate && 'direct' !== $boundary );
					$record['passed'] = true;
					$summary['passed']++;
				} catch ( Throwable $error ) {
					$record['passed'] = false;
					$record['error'] = $error->getMessage();
					$summary['failed']++;
					echo "FAIL {$label}: {$error->getMessage()}\n";
				} finally {
					unset( $GLOBALS['comment'] );
					$summary['cases'][] = $record;
				}
			}
		}
	}
} catch ( Throwable $error ) {
	$summary['failed']++;
	$summary['setup_error'] = $error->getMessage();
	echo "FAIL comment proof: {$error->getMessage()}\n";
} finally {
	foreach ( $clients as $client ) {
		$client->close();
	}
	foreach ( $passwords as $role => $uuid ) {
		wstm105_assert( true === WP_Application_Passwords::delete_application_password( $actors[ $role ], $uuid ), 'Cannot revoke fixture credential.' );
	}
	$json = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	wstm105_assert( strlen( $json ) === file_put_contents( $artifact, $json ), 'Cannot write complete comment evidence.' );
}
echo "SUMMARY WSTM105 {$boundary_filter}: {$summary['passed']} passed, {$summary['failed']} failed\n";
exit( $summary['failed'] ? 1 : 0 );
