<?php

function e2e_ensure_user( $login, $email, $role ) {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		return (int) $user->ID;
	}

	$id = wp_create_user( $login, 'password123', $email );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}

	( new WP_User( $id ) )->set_role( $role );
	return (int) $id;
}

function e2e_ensure_term_id( $name, $taxonomy ) {
	$existing = term_exists( $name, $taxonomy );
	if ( $existing ) {
		return (int) $existing['term_id'];
	}

	$created = wp_insert_term( $name, $taxonomy );
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( $created->get_error_message() );
	}

	return (int) $created['term_id'];
}

function e2e_insert_post( $type, $title, $content, $author_id ) {
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_author'  => $author_id,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}

	return (int) $id;
}

function e2e_insert_comment( $post_id, $suffix ) {
	return (int) wp_insert_comment(
		array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => 'MCP Tester ' . $suffix,
			'comment_author_email' => "comment-{$suffix}@test.local",
			'comment_content'      => "MCP E2E comment {$suffix}.",
			'comment_approved'     => '0',
		)
	);
}

function e2e_insert_media( $post_id, $author_id, $suffix ) {
	$upload = wp_upload_bits( "mcp-e2e-{$suffix}.txt", null, "MCP E2E media file {$suffix}." );
	if ( ! empty( $upload['error'] ) ) {
		throw new RuntimeException( $upload['error'] );
	}

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'text/plain',
			'post_title'     => "MCP E2E Media {$suffix}",
			'post_status'    => 'inherit',
			'post_author'    => $author_id,
		),
		$upload['file'],
		$post_id,
		true
	);

	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}

	return (int) $id;
}

function e2e_resolve_placeholders( $value, $fixtures ) {
	if ( is_string( $value ) && preg_match( '/^__([a-z0-9_]+)__$/', $value, $matches ) ) {
		$key = $matches[1];
		if ( ! array_key_exists( $key, $fixtures ) ) {
			throw new RuntimeException( "Unknown E2E fixture placeholder: {$value}" );
		}
		return $fixtures[ $key ];
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = e2e_resolve_placeholders( $item, $fixtures );
		}
	}

	return $value;
}

function e2e_result_is_success( $result ) {
	return is_array( $result ) && true === ( $result['success'] ?? false );
}

function e2e_write_summary( $summary ) {
	$artifact_dir = WP_PLUGIN_DIR . '/unlock-mcp-potential/e2e-artifacts';
	if ( ! is_dir( $artifact_dir ) ) {
		wp_mkdir_p( $artifact_dir );
	}

	file_put_contents(
		$artifact_dir . '/e2e-summary.json',
		wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
	);
}

$manifest_path = WP_PLUGIN_DIR . '/unlock-mcp-potential/tests/e2e/abilities-manifest.json';
$manifest      = json_decode( file_get_contents( $manifest_path ), true );

if ( ! is_array( $manifest ) ) {
	throw new RuntimeException( 'E2E ability manifest is missing or invalid JSON.' );
}

$admin         = get_user_by( 'login', 'admin' );
$admin_id      = (int) $admin->ID;
$author_id     = e2e_ensure_user( 'author_test', 'author@test.local', 'author' );
$editor_id     = e2e_ensure_user( 'editor_test', 'editor@test.local', 'editor' );
$subscriber_id = e2e_ensure_user( 'subscriber_test', 'subscriber@test.local', 'subscriber' );

$fixtures = array(
	'admin_id'           => $admin_id,
	'author_id'          => $author_id,
	'editor_id'          => $editor_id,
	'subscriber_id'      => $subscriber_id,
	'category_id'        => e2e_ensure_term_id( 'MCP E2E Category', 'category' ),
	'tag_id'             => e2e_ensure_term_id( 'mcp-e2e-tag', 'post_tag' ),
	'delete_category_id' => e2e_ensure_term_id( 'MCP E2E Delete Category', 'category' ),
	'delete_tag_id'      => e2e_ensure_term_id( 'mcp-e2e-delete-tag', 'post_tag' ),
);

$fixtures['post_id']        = e2e_insert_post( 'post', 'MCP E2E Post', 'Content for MCP E2E post.', $author_id );
$fixtures['delete_post_id'] = e2e_insert_post( 'post', 'MCP E2E Delete Post', 'Delete fixture.', $author_id );
$fixtures['page_id']        = e2e_insert_post( 'page', 'MCP E2E Page', 'Content for MCP E2E page.', $editor_id );
$fixtures['delete_page_id'] = e2e_insert_post( 'page', 'MCP E2E Delete Page', 'Delete fixture.', $editor_id );

wp_set_post_categories( $fixtures['post_id'], array( $fixtures['category_id'] ) );
wp_set_post_tags( $fixtures['post_id'], array( $fixtures['tag_id'] ) );

$fixtures['comment_id']       = e2e_insert_comment( $fixtures['post_id'], 'approve' );
$fixtures['trash_comment_id'] = e2e_insert_comment( $fixtures['post_id'], 'trash' );
$fixtures['spam_comment_id']  = e2e_insert_comment( $fixtures['post_id'], 'spam' );
$fixtures['media_id']         = e2e_insert_media( $fixtures['post_id'], $author_id, 'read-update' );
$fixtures['delete_media_id']  = e2e_insert_media( $fixtures['post_id'], $author_id, 'delete' );

$roles = array(
	'admin'      => $admin_id,
	'author'     => $author_id,
	'editor'     => $editor_id,
	'subscriber' => $subscriber_id,
);

$registered = array_filter(
	array_keys( wp_get_abilities() ),
	static function ( $ability_name ) {
		return str_starts_with( $ability_name, 'wp-mcp/' );
	}
);
sort( $registered );

$covered = array_values( array_unique( array_column( $manifest, 'ability' ) ) );
sort( $covered );

$missing = array_values( array_diff( $registered, $covered ) );
$extra   = array_values( array_diff( $covered, $registered ) );

$summary = array(
	'registered_abilities' => count( $registered ),
	'covered_abilities'    => count( $covered ),
	'test_cases'           => count( $manifest ),
	'negative_cases'       => count(
		array_filter(
			$manifest,
			static function ( $case ) {
				return 'failure' === ( $case['expect'] ?? 'success' );
			}
		)
	),
	'passed'                => 0,
	'failed'                => 0,
	'missing_coverage'      => $missing,
	'unknown_manifest'      => $extra,
	'cases'                 => array(),
);

echo 'INFO registered wp-mcp abilities: ' . count( $registered ) . "\n";
echo 'INFO manifest-covered abilities: ' . count( $covered ) . "\n";
echo 'INFO manifest test cases: ' . count( $manifest ) . "\n";
echo 'INFO negative permission cases: ' . $summary['negative_cases'] . "\n";

foreach ( $registered as $ability_name ) {
	echo in_array( $ability_name, $covered, true )
		? "PASS covered {$ability_name}\n"
		: "FAIL uncovered {$ability_name}\n";
}

foreach ( $extra as $ability_name ) {
	echo "FAIL manifest references unregistered {$ability_name}\n";
}

if ( $missing || $extra ) {
	$summary['failed'] = count( $missing ) + count( $extra );
	e2e_write_summary( $summary );
	exit( 1 );
}

foreach ( $manifest as $case ) {
	$label        = $case['label'] ?? $case['ability'];
	$ability_name = $case['ability'];
	$role         = $case['role'] ?? 'subscriber';
	$expect       = $case['expect'] ?? 'success';

	if ( ! isset( $roles[ $role ] ) ) {
		throw new RuntimeException( "Unknown E2E role for {$label}: {$role}" );
	}

	wp_set_current_user( $roles[ $role ] );

	$ability = wp_get_ability( $ability_name );
	$input   = e2e_resolve_placeholders( $case['input'] ?? null, $fixtures );
	$result  = $ability->execute( $input );
	$ok      = ! is_wp_error( $result ) && e2e_result_is_success( $result );
	$passed  = ( 'success' === $expect && $ok ) || ( 'failure' === $expect && ! $ok );

	if ( $passed ) {
		$summary['passed']++;
		echo 'PASS ' . $label . ( 'failure' === $expect ? ' denied as expected' : '' ) . "\n";
	} else {
		$summary['failed']++;
		$message = is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $result );
		echo "FAIL {$label}: {$message}\n";
	}

	$summary['cases'][] = array(
		'label'   => $label,
		'ability' => $ability_name,
		'role'    => $role,
		'expect'  => $expect,
		'passed'  => $passed,
	);
}

echo "SUMMARY {$summary['passed']} passed, {$summary['failed']} failed\n";
e2e_write_summary( $summary );

exit( $summary['failed'] > 0 ? 1 : 0 );
