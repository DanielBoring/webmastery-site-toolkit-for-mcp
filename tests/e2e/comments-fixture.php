<?php
require_once __DIR__ . '/error-contract-assertions.php';


function wstm105_comment_fixtures( $author_id, $book_id ) {
	// Only the deliberately orphaned fixture has no post author to notify.
	add_filter( 'notify_post_author', 'wstm105_notify_post_author', 10, 2 );
	e2e_ensure_role( 'comment_moderator', 'Comment moderator only', array( 'read', 'moderate_comments' ) );
	e2e_ensure_role( 'wstm105_own_editor', 'Own draft comment moderator', array( 'read', 'edit_posts', 'moderate_comments' ) );
	e2e_ensure_role( 'wstm105_mapped_moderator', 'Mapped CPT comment moderator', array( 'read', 'moderate_comments', 'edit_mcp_books', 'edit_others_mcp_books', 'edit_published_mcp_books' ) );
	$roles = array();
	foreach ( array( 'wstm105_moderator' => 'comment_moderator', 'wstm105_own_editor' => 'wstm105_own_editor', 'wstm105_mapped_moderator' => 'wstm105_mapped_moderator' ) as $key => $role ) {
		$roles[ $key ] = e2e_ensure_user( $key, "{$key}@test.local", $role );
		( new WP_User( $roles[ $key ] ) )->set_role( $role );
	}

	$posts = array(
		'other'  => e2e_insert_post( 'post', 'WSTM105 other post', 'Comment authorization fixture.', $author_id ),
		'own'    => e2e_insert_post( 'post', 'WSTM105 own draft', 'Comment authorization fixture.', $roles['wstm105_own_editor'], 'draft' ),
		'author' => e2e_insert_post( 'post', 'WSTM105 author post', 'Comment authorization fixture.', $author_id ),
		'orphan' => 987654321,
		'cpt'    => $book_id,
		'cpt_allowed' => $book_id,
	);
	$posts['admin'] = $posts['other'];
	$fixtures = array();
	foreach ( array( 'update', 'approve', 'trash', 'spam' ) as $action ) {
		foreach ( $posts as $scope => $post_id ) {
			$key = "wstm105_{$action}_{$scope}";
			$fixtures[ $key ] = e2e_insert_comment( $post_id, $key );
		}
	}
	$fixtures['wstm105_other_post'] = $posts['other'];
	$fixtures['wstm105_update_hold'] = e2e_insert_comment( $posts['other'], 'wstm105_update_hold' );
	wp_set_comment_status( $fixtures['wstm105_update_hold'], 'approve' );
	return array( 'roles' => $roles, 'fixtures' => $fixtures );
}

function wstm105_notify_post_author( $notify, $comment_id ) {
	$comment = get_comment( $comment_id );
	return $comment && 987654321 === (int) $comment->comment_post_ID ? false : $notify;
}

function wstm105_comment_state( $comment_id ) {
	clean_comment_cache( $comment_id );
	$comment = get_comment( $comment_id );
	if ( ! $comment ) {
		throw new RuntimeException( "WSTM105 comment {$comment_id} disappeared." );
	}
	return array(
		'content' => $comment->comment_content,
		'status'  => wp_get_comment_status( $comment_id ),
	);
}

function wstm105_assert_comment_state( $assertion ) {
	$actual = wstm105_comment_state( $assertion['comment_id'] );
	foreach ( array( 'content', 'status' ) as $field ) {
		if ( $actual[ $field ] !== $assertion[ $field ] ) {
			echo "FAIL WSTM105 persisted comment {$field}: " . wp_json_encode( $actual ) . "\n";
			return false;
		}
	}
	return true;
}

function wstm105_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( "WSTM105 {$message}" );
	}
}

function wstm105_finalize_proof( array $cleanup, array &$summary, string $artifact ): void {
	foreach ( $cleanup as $label => $callback ) {
		try {
			$callback();
		} catch ( Throwable $error ) {
			$summary['cleanup_errors'][] = array( 'label' => $label, 'error' => $error->getMessage() );
			$summary['failed']++;
			echo "FAIL comment cleanup {$label}: {$error->getMessage()}\n";
		}
	}
	$json = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	wstm105_assert( strlen( $json ) === file_put_contents( $artifact, $json ), 'Cannot write complete comment evidence.' );
}

function wstm105_assert_schema_error( $result, $permission = false ) {
	if ( $permission ) {
		wstm105_assert( is_wp_error( $result ) && 'invalid_input' === $result->get_error_code(), 'Registered permission must return native invalid_input.' );
		$decoded = json_decode( $result->get_error_message() );
		wstm105_assert( $decoded instanceof stdClass && ( $decoded->error ?? null ) instanceof stdClass, 'Permission error must carry canonical JSON.' );
		$envelope = (array) $decoded;
		$envelope['error'] = (array) $decoded->error;
		wstm105_assert( wp_json_encode( $envelope ) === $result->get_error_message(), 'Permission error JSON changed.' );
		$result = $envelope;
	}
	wstm105_assert( 'ability_invalid_input' === wstm118_error_reason( $result )
		&& 'invalid_input' === $result['error']['code']
		&& 'Ability input does not match its schema.' === $result['error']['message']
		&& '{}' === wp_json_encode( $result['error']['details'] ), 'Registered schema error contract changed.' );
}

function wstm105_schema_negative( $permission, $execute, $input, $comment_id ) {
	global $wpdb;
	$before = wstm105_comment_state( $comment_id );
	$cap_calls = 0;
	$record_cap = static function ( $caps ) use ( &$cap_calls ) {
		$cap_calls++;
		return $caps;
	};
	add_filter( 'map_meta_cap', $record_cap, -10000 );
	try {
		foreach ( array( true, false ) as $is_permission ) {
			$queries = $wpdb->num_queries;
			$result = $is_permission ? $permission( $input ) : $execute( $input );
			// Both original callbacks begin with a capability check; neither may run.
			wstm105_assert( 0 === $cap_calls && $queries === $wpdb->num_queries, 'Schema rejection reached original capability/query work.' );
			wstm105_assert_schema_error( $result, $is_permission );
		}
	} finally {
		remove_filter( 'map_meta_cap', $record_cap, -10000 );
	}
	wstm105_assert( $before === wstm105_comment_state( $comment_id ), 'Schema rejection changed persisted content/status.' );
}

function wstm105_status_counterpart( $action, $permission, $execute, $input, $comment_id, &$checks ) {
	if ( 'update' === $action ) {
		return $input;
	}
	// Retain the original typed payload as a negative control before its counterpart.
	wstm105_schema_negative( $permission, $execute, $input, $comment_id );
	$checks++;
	return array( 'comment_id' => $input['comment_id'] );
}

function wstm105_check_direct_callbacks( $roles, $fixtures ) {
	$checks = 0;
	foreach ( array( 'update', 'approve', 'trash', 'spam' ) as $action ) {
		$ability = wp_get_ability( "webmastery-site-toolkit-for-mcp/{$action}-comment" );
		$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
		$property->setAccessible( true );
		$execute = $property->getValue( $ability );
		$property = new ReflectionProperty( WP_Ability::class, 'permission_callback' );
		$property->setAccessible( true );
		$permission = $property->getValue( $ability );
		$scenarios = array(
			array( 'wstm105_moderator', 'other', true, false ),
			array( 'wstm105_own_editor', 'other', true, false ),
			array( 'wstm105_own_editor', 'own', true, true ),
			array( 'author', 'author', false, true ),
			array( 'editor', 'other', true, true ),
			array( 'admin', 'other', true, true ),
			array( 'editor', 'cpt', true, false ),
			array( 'wstm105_mapped_moderator', 'cpt', true, true ),
			array( 'wstm105_moderator', 'orphan', true, false ),
			array( 'wstm105_own_editor', 'orphan', true, true ),
		);
		foreach ( $scenarios as list( $role, $scope, $moderate, $edit ) ) {
			$post_id = get_comment( $fixtures[ "wstm105_{$action}_{$scope}" ] )->comment_post_ID;
			$comment_id = e2e_insert_comment( $post_id, "wstm105-direct-{$action}-{$role}-{$scope}" );
			$input = array( 'comment_id' => $comment_id, 'content' => 'Updated directly.', 'status' => 'spam' );
			wp_set_current_user( $roles[ $role ] );
			wstm105_assert( $moderate === current_user_can( 'moderate_comments' ) && $edit === current_user_can( 'edit_comment', $comment_id ), "{$role}/{$scope} fixture capability mismatch." );
			$allowed = $moderate && $edit;
			$before = wstm105_comment_state( $comment_id );
			$input = wstm105_status_counterpart( $action, $permission, $execute, $input, $comment_id, $checks );
			$result = $permission( $input );
			wstm105_assert( $allowed ? true === $result : is_wp_error( $result ) && 'forbidden' === $result->get_error_code(), "{$action} permission callback mismatch for {$role}/{$scope}." );
			wstm105_assert( $before === wstm105_comment_state( $comment_id ), 'Permission callback mutated a comment.' );
			$result = $execute( $input );
			$after = wstm105_comment_state( $comment_id );
			wstm105_assert( $allowed === e2e_result_is_success( $result ), "{$action} direct execution mismatch for {$role}/{$scope}." );
			if ( ! $allowed ) {
				wstm105_assert( $before === $after, "{$action} direct denial changed persisted content/status." );
				wstm105_assert( false === $result['success'] && ! empty( $result['error'] ), 'Direct denial must preserve the error response.' );
			} else {
				$status = array( 'update' => 'spam', 'approve' => 'approved', 'trash' => 'trash', 'spam' => 'spam' )[ $action ];
				wstm105_assert( $status === $after['status'], "{$action} did not persist the expected status." );
				wstm105_assert( ( 'update' === $action ? 'Updated directly.' : $before['content'] ) === $after['content'], "{$action} persisted unexpected content." );
				wstm105_assert( $comment_id === $result['data']['id'] && $status === $result['data']['status'], 'Success response contract changed.' );
			}
			$checks++;
		}

		wp_set_current_user( $roles['editor'] );
		$input = array( 'comment_id' => $fixtures['missing_comment_id'], 'content' => 'Missing.' );
		$input = wstm105_status_counterpart( $action, $permission, $execute, $input, $fixtures[ "wstm105_{$action}_other" ], $checks );
		$result = $permission( $input );
		wstm105_assert( true === $result, "{$action} missing comment must reach the existing execute error." );
		$result = $execute( $input );
		wstm105_assert( false === $result['success'], "{$action} missing comment succeeded." );
		wstm105_assert( 'not_found' === wstm118_error_reason( $result ) && 'Comment not found.' === $result['error']['message'], 'Missing direct response contract changed.' );
		$checks++;

		$comment_id = $fixtures[ "wstm105_{$action}_other" ];
		$before = wstm105_comment_state( $comment_id );
		$GLOBALS['comment'] = get_comment( $comment_id );
		try {
			$input = array( 'comment_id' => 0, 'content' => 'Must not target the global comment.', 'status' => 'spam' );
			$input = wstm105_status_counterpart( $action, $permission, $execute, $input, $comment_id, $checks );
			wstm105_assert( true === $permission( $input ), "{$action} zero ID must defer to the missing-comment execution error." );
			wstm105_assert( $before === wstm105_comment_state( $comment_id ), 'Zero-ID permission callback changed the global comment.' );
			$result = $execute( $input );
			wstm105_assert( 'not_found' === wstm118_error_reason( $result ) && 'Comment not found.' === $result['error']['message']
				&& '{}' === wp_json_encode( $result['error']['details'] ), "{$action} zero ID resolved the global comment." );
			wstm105_assert( $before === wstm105_comment_state( $comment_id ), 'Zero-ID execution changed the global comment.' );
			$checks++;
		} finally {
			unset( $GLOBALS['comment'] );
		}
		$invalid_inputs = array( null, false, 7, 'invalid', new stdClass(), array(), array( 'comment_id' => array( $comment_id ) ), array( 'comment_id' => new stdClass() ), array( 'comment_id' => true ), array( 'comment_id' => -$comment_id ), array( 'comment_id' => 1.5 ) );
		foreach ( $invalid_inputs as $input ) {
			if ( 'update' !== $action && is_array( $input ) && is_int( $input['comment_id'] ?? null ) && $input['comment_id'] < 0 ) {
				wstm105_assert( true === $permission( $input ), "{$action} negative integer ID must defer to guarded execution." );
				$result = $execute( $input );
				wstm105_assert( 'not_found' === wstm118_error_reason( $result ) && 'Comment not found.' === $result['error']['message']
					&& '{}' === wp_json_encode( $result['error']['details'] ), 'Negative integer ID response changed.' );
			} else {
				wstm105_schema_negative( $permission, $execute, $input, $comment_id );
			}
			wstm105_assert( $before === wstm105_comment_state( $comment_id ), 'Malformed input changed persisted state.' );
			$checks++;
		}

		$deny = static function ( $caps, $cap, $user_id, $args ) use ( $comment_id ) {
			return 'edit_comment' === $cap && $comment_id === ( $args[0] ?? null ) ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		try {
			$input = array( 'comment_id' => $comment_id, 'content' => 'Filtered out.', 'status' => 'trash' );
			$input = wstm105_status_counterpart( $action, $permission, $execute, $input, $comment_id, $checks );
			$result = $permission( $input );
			wstm105_assert( is_wp_error( $result ) && 'forbidden' === $result->get_error_code(), 'Custom edit_comment mapping bypassed by permission callback.' );
			wstm105_assert( false === $execute( $input )['success'], 'Custom edit_comment mapping bypassed by execution.' );
			wstm105_assert( $before === wstm105_comment_state( $comment_id ), 'Custom capability denial changed persisted state.' );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
		$checks++;

		if ( 'update' === $action ) {
			foreach ( array( array( 'content' => array() ), array( 'content' => new stdClass() ), array( 'content' => '' ), array( 'status' => array() ), array( 'status' => new stdClass() ), array( 'status' => 'pending' ) ) as $invalid ) {
				$input = array_merge( array( 'comment_id' => $comment_id, 'content' => 'Must not persist.' ), $invalid );
				$result = $execute( $input );
				wstm105_assert( false === $result['success'], 'Invalid comment update accepted.' );
				wstm105_assert( $before === wstm105_comment_state( $comment_id ), 'Invalid update changed content/status before validation.' );
				$checks++;
			}
		}
	}
	wstm105_assert( 141 === $checks, 'Direct callback inventory changed.' );
	echo "PASS WSTM105 {$checks} direct callback authorization checks\n";
}
