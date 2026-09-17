<?php
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'This test runner is CLI-only.' );
}

/**
 * Isolated real-core trash regressions. Run in a fresh PHP process, not wp eval-file.
 */

$mode = $argv[1] ?? '';
if ( ! in_array( $mode, [ 'enabled', 'disabled' ], true ) ) {
	fwrite( STDERR, "Usage: php tests/e2e/trash-safety-runner.php enabled|disabled\n" );
	exit( 1 );
}
if ( defined( 'ABSPATH' ) || defined( 'EMPTY_TRASH_DAYS' ) ) {
	throw new RuntimeException( 'Trash safety must run before WordPress/configuration is loaded.' );
}

define( 'EMPTY_TRASH_DAYS', 'disabled' === $mode ? 0 : 30 );
require dirname( __DIR__, 5 ) . '/wp-load.php';

$wstm109_results = [];
$wstm109_events  = [];

function wstm109_check( $label, $passed, $evidence = [] ) {
	global $wstm109_results;
	$wstm109_results[] = [ 'label' => $label, 'passed' => (bool) $passed, 'evidence' => $evidence ];
	echo ( $passed ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

function wstm109_user( $role ) {
	$login = 'wstm109_' . $role;
	$user  = get_user_by( 'login', $login );
	if ( ! $user ) {
		$id = wp_insert_user( [
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => $role,
		] );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$user = new WP_User( $id );
	}
	$user->set_role( $role );
	return (int) $user->ID;
}

function wstm109_post( $type, $author, $parent = 0 ) {
	$id = wp_insert_post( [
		'post_type'    => $type,
		'post_title'   => 'wstm109 trash safety ' . $type,
		'post_content' => 'wstm109 content must survive.',
		'post_status'  => 'publish',
		'post_author'  => $author,
		'post_parent'  => $parent,
	], true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	return (int) $id;
}

function wstm109_fixture( $type, $author ) {
	$id       = wstm109_post( $type, $author );
	$child    = wstm109_post( $type, $author, $id );
	$revision = wstm109_post( 'revision', $author, $id );
	foreach ( [ $id, $child, $revision ] as $post_id ) {
		add_post_meta( $post_id, 'wstm109_marker', 'original meta' );
	}
	register_taxonomy_for_object_type( 'category', $type );
	$term = term_exists( 'wstm109-trash-safety', 'category' );
	if ( ! $term ) {
		$term = wp_insert_term( 'wstm109-trash-safety', 'category' );
	}
	if ( is_wp_error( $term ) ) {
		throw new RuntimeException( $term->get_error_message() );
	}
	$assigned = wp_set_object_terms( $id, [ (int) $term['term_id'] ], 'category' );
	if ( is_wp_error( $assigned ) ) {
		throw new RuntimeException( $assigned->get_error_message() );
	}
	$comments = [];
	foreach ( [ $id, $id, $child ] as $post_id ) {
		$comment_id = wp_insert_comment( [
			'comment_post_ID' => $post_id,
			'comment_parent'  => $comments[0] ?? 0,
			'comment_content' => 'wstm109 comment must survive.',
			'comment_author'  => 'wstm109 fixture',
			'comment_approved' => '1',
		] );
		if ( ! $comment_id ) {
			throw new RuntimeException( 'Could not create wstm109 comment.' );
		}
		add_comment_meta( $comment_id, 'wstm109_marker', 'original comment meta' );
		$comments[] = (int) $comment_id;
	}
	return [ 'id' => $id, 'posts' => [ $id, $child, $revision ], 'comments' => $comments ];
}

function wstm109_snapshot( $fixture ) {
	global $wpdb;
	$posts    = implode( ',', array_map( 'intval', $fixture['posts'] ) );
	$comments = implode( ',', array_map( 'intval', $fixture['comments'] ) );
	// Read persisted rows, including descendants/relationships, without object-cache proxies.
	$queries = [
		"SELECT * FROM {$wpdb->posts} WHERE ID IN ($posts) ORDER BY ID",
		"SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($posts) ORDER BY meta_id",
		"SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ($posts) ORDER BY object_id, term_taxonomy_id",
		"SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ($comments) ORDER BY comment_ID",
		"SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ($comments) ORDER BY meta_id",
	];
	$snapshot = [];
	foreach ( $queries as $query ) {
		$snapshot[] = $wpdb->get_results( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( $wpdb->last_error );
		}
	}
	return $snapshot;
}

function wstm109_execute( $name, $input, $user ) {
	wp_set_current_user( $user );
	$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $name );
	if ( ! $ability ) {
		throw new RuntimeException( "Missing registered ability: $name" );
	}
	return $ability->execute( $input );
}

function wstm109_error( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : ( $result['error']['code'] ?? null );
}

function wstm109_denied( $label, $name, $input, $user, $code ) {
	wp_set_current_user( $user );
	$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $name );
	if ( ! $ability ) {
		throw new RuntimeException( "Missing registered ability: $name" );
	}
	$permission = $ability->check_permissions( $input );
	// Core wraps callback errors as ability_invalid_permissions during execute().
	// Suppress only the exact expected permission notice, not unrelated diagnostics.
	$expected_notice = function ( $trigger, $function, $message ) use ( $permission ) {
		return 'WP_Ability::execute' === $function && is_wp_error( $permission )
			&& $message === $permission->get_error_message() ? false : $trigger;
	};
	add_filter( 'doing_it_wrong_trigger_error', $expected_notice, 10, 3 );
	try {
		$result = $ability->execute( $input );
	} finally {
		remove_filter( 'doing_it_wrong_trigger_error', $expected_notice, 10 );
	}
	wstm109_check( $label, $code === wstm109_error( $permission )
		&& 'ability_invalid_permissions' === wstm109_error( $result ), [
			'permission' => $permission, 'result' => $result,
		] );
}

function wstm109_unchanged( $label, $fixture, $before, $events_before ) {
	global $wstm109_events;
	$after = wstm109_snapshot( $fixture );
	wstm109_check( "$label persisted rows unchanged", $before === $after, [
		'before_sha256' => hash( 'sha256', serialize( $before ) ),
		'after_sha256'  => hash( 'sha256', serialize( $after ) ),
		'row_counts'    => array_map( 'count', $after ),
	] );
	wstm109_check( "$label no permanent deletion calls/hooks", $events_before === count( $wstm109_events ) );
}

foreach ( [ 'pre_delete_post', 'pre_delete_comment' ] as $hook ) {
	add_filter( $hook, function ( $value ) use ( $hook ) {
		global $wstm109_events;
		$wstm109_events[] = $hook;
		return $value;
	}, 0 );
}
foreach ( [ 'before_delete_post', 'delete_post', 'deleted_post', 'delete_comment', 'deleted_comment' ] as $hook ) {
	add_action( $hook, function () use ( $hook ) {
		global $wstm109_events;
		$wstm109_events[] = $hook;
	}, 0 );
}

wstm109_check( 'actual boot constant', EMPTY_TRASH_DAYS === ( 'disabled' === $mode ? 0 : 30 ), [
	'EMPTY_TRASH_DAYS' => EMPTY_TRASH_DAYS,
	'wordpress'       => get_bloginfo( 'version' ),
	'php'             => PHP_VERSION,
] );

$author     = wstm109_user( 'author' );
$editor     = wstm109_user( 'editor' );
$subscriber = wstm109_user( 'subscriber' );
$cpt_role   = add_role( 'wstm109_cpt', 'WSTM109 CPT', [ 'read' => true ] );
$cpt_role   = $cpt_role ?: get_role( 'wstm109_cpt' );
foreach ( [ 'mcp_book', 'mcp_case_study' ] as $type ) {
	$object = get_post_type_object( $type );
	if ( ! $object ) {
		throw new RuntimeException( "Missing E2E CPT fixture: $type" );
	}
	foreach ( (array) $object->cap as $cap ) {
		$cpt_role->add_cap( $cap );
	}
}
$cpt_user = wstm109_user( 'wstm109_cpt' );
$missing  = 2147483647;
if ( get_post( $missing ) ) {
	throw new RuntimeException( 'The missing-ID fixture unexpectedly exists.' );
}
$wrong_type = wstm109_fixture( 'attachment', $editor );

foreach ( [
	[ 'post', 'delete-post', 'post_id', $author ],
	[ 'page', 'delete-page', 'page_id', $editor ],
	[ 'mcp_book', 'delete-cpt-mcp-book', 'id', $cpt_user ],
	[ 'mcp_case_study', 'delete-cpt-mcp-case-study', 'id', $cpt_user ],
] as [ $type, $ability, $key, $user ] ) {
	$fixture = wstm109_fixture( $type, $user );
	$before  = wstm109_snapshot( $fixture );
	$events  = count( $wstm109_events );
	foreach ( [
		[ 'denied role', $fixture['id'], $subscriber, 'forbidden' ],
		[ 'missing ID', $missing, $user, 'not_found' ],
		[ 'wrong type', $wrong_type['id'], $user, 'not_found' ],
		[ 'missing ID denied role', $missing, $subscriber, 'not_found' ],
		[ 'wrong type denied role', $wrong_type['id'], $subscriber, 'not_found' ],
	] as [ $label, $id, $actor, $code ] ) {
		wstm109_denied( "$ability $label precedence", $ability, [ $key => $id ], $actor, $code );
	}
	wstm109_unchanged( "$ability denied/missing/wrong-type", $fixture, $before, $events );

	$result = wstm109_execute( $ability, [ $key => $fixture['id'] ], $user );
	if ( 'disabled' === $mode ) {
		wstm109_check( "$ability refuses disabled trash", 'trash_disabled' === wstm109_error( $result ), [
			'result' => $result, 'row_exists' => null !== get_post( $fixture['id'] ),
		] );
		wstm109_unchanged( $ability, $fixture, $before, $events );
	} else {
		wstm109_check( "$ability normal response", [
			'success' => true, 'data' => [ 'id' => $fixture['id'], 'status' => 'trash' ],
		] === $result );
		wstm109_check( "$ability persists trash", 'trash' === get_post_status( $fixture['id'] ) );
		// CPTs have no restore ability; use core only for their restoration characterization.
		$restored = in_array( $type, [ 'post', 'page' ], true )
			? wstm109_execute( 'restore-' . $type, [ $key => $fixture['id'] ], $user )
			: wp_untrash_post( $fixture['id'] );
		wstm109_check( "$ability restore succeeds", ! is_wp_error( $restored ) && ( is_array( $restored ) ? ! empty( $restored['success'] ) : (bool) $restored ) );
		wstm109_check( "$ability restore preserves content/meta/terms/descendants/comments", 'draft' === get_post_status( $fixture['id'] )
			&& 'wstm109 content must survive.' === get_post( $fixture['id'] )->post_content
			&& array_slice( $before, 1 ) === array_slice( wstm109_snapshot( $fixture ), 1 )
			&& array_slice( $before[0], 1 ) === array_slice( wstm109_snapshot( $fixture )[0], 1 ) );
		wstm109_check( "$ability no permanent deletion calls/hooks", $events === count( $wstm109_events ) );
	}
}

$own     = wstm109_fixture( 'post', $author );
$foreign = wstm109_fixture( 'post', $editor );
$ids     = [ $own['id'], $foreign['id'], $missing, $wrong_type['id'] ];
$before  = wstm109_snapshot( $own );
$other   = wstm109_snapshot( $foreign );
$wrong   = wstm109_snapshot( $wrong_type );
$events  = count( $wstm109_events );
wstm109_denied( 'bulk denied role', 'bulk-trash-posts', [ 'ids' => $ids ], $subscriber, 'forbidden' );
wstm109_unchanged( 'bulk denied role', $own, $before, $events );
$result = wstm109_execute( 'bulk-trash-posts', [ 'ids' => $ids ], $author );
$data   = $result['data'] ?? [];
$codes  = array_column( $data['failures'] ?? [], 'code', 'id' );
$expected = [
	$foreign['id'] => 'forbidden',
	$missing => 'not_found',
	$wrong_type['id'] => 'not_found',
];
if ( 'disabled' === $mode ) {
	$expected = [ $own['id'] => 'trash_disabled' ] + $expected;
}
wstm109_check( 'bulk per-ID errors and precedence', $expected === $codes, [ 'result' => $result ] );
wstm109_check( 'bulk exact totals and successes', true === ( $result['success'] ?? null )
	&& 4 === ( $data['requested'] ?? null )
	&& ( 'disabled' === $mode ? 0 : 1 ) === ( $data['success_count'] ?? null )
	&& count( $expected ) === ( $data['failure_count'] ?? null )
	&& ( 'disabled' === $mode ? [] : [ [ 'id' => $own['id'], 'status' => 'trash' ] ] ) === ( $data['successes'] ?? null ) );
wstm109_unchanged( 'bulk forbidden fixture', $foreign, $other, $events );
wstm109_unchanged( 'bulk wrong-type fixture', $wrong_type, $wrong, $events );
if ( 'disabled' === $mode ) {
	wstm109_unchanged( 'bulk authorized fixture', $own, $before, $events );
} else {
	wstm109_check( 'bulk persisted trash', 'trash' === get_post_status( $own['id'] ) );
	$result = wstm109_execute( 'restore-post', [ 'post_id' => $own['id'] ], $author );
	wstm109_check( 'bulk post restored through ability', ! is_wp_error( $result ) && ! empty( $result['success'] ) && 'draft' === get_post_status( $own['id'] ) );
}

foreach ( [ 'trash-comment', 'update-comment' ] as $ability ) {
	$fixture = wstm109_fixture( 'post', $editor );
	$id      = $fixture['comments'][0];
	$input   = [ 'comment_id' => $id ];
	if ( 'update-comment' === $ability ) {
		$input += [ 'content' => 'wstm109 intentionally updated.', 'status' => 'trash' ];
	}
	$before = wstm109_snapshot( $fixture );
	$events = count( $wstm109_events );
	wstm109_denied( "$ability denied role", $ability, $input, $subscriber, 'forbidden' );
	wstm109_unchanged( "$ability denied role", $fixture, $before, $events );
	$result = wstm109_execute( $ability, $input, $editor );
	$after  = wstm109_snapshot( $fixture );
	// Only the moderated status/content and the owning post's comment count may change.
	$expected = $before;
	$expected[0][0]['comment_count'] = (string) ( (int) $expected[0][0]['comment_count'] - 1 );
	$expected[3][0]['comment_approved'] = 'trash';
	if ( 'update-comment' === $ability ) {
		$expected[3][0]['comment_content'] = $input['content'];
	}
	wstm109_check( "$ability remains stored with trash status", ! is_wp_error( $result )
		&& ! empty( $result['success'] ) && 'trash' === ( $result['data']['status'] ?? null )
		&& $expected === $after, [ 'result' => $result, 'comment_rows' => count( $after[3] ) ] );
	wstm109_check( "$ability no permanent deletion calls/hooks", $events === count( $wstm109_events ) );
}

$failed = count( array_filter( $wstm109_results, function ( $result ) { return ! $result['passed']; } ) );
$summary = [
	'mode' => $mode, 'empty_trash_days' => EMPTY_TRASH_DAYS,
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'passed' => count( $wstm109_results ) - $failed, 'failed' => $failed,
	'deletion_events' => $wstm109_events, 'cases' => $wstm109_results,
];
$directory = dirname( __DIR__, 2 ) . '/e2e-artifacts';
if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
	throw new RuntimeException( 'Could not create trash safety artifact directory.' );
}
if ( false === file_put_contents( "$directory/trash-safety-$mode.json", wp_json_encode( $summary, JSON_PRETTY_PRINT ) . "\n" ) ) {
	throw new RuntimeException( 'Could not write trash safety summary.' );
}
echo "SUMMARY {$summary['passed']} passed, $failed failed ($mode; EMPTY_TRASH_DAYS=" . EMPTY_TRASH_DAYS . ")\n";
exit( $failed ? 1 : 0 );
