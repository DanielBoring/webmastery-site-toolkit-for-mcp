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

function e2e_ensure_application_password( $user_id, $name ) {
	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		return null;
	}

	foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $password ) {
		if ( $name === ( $password['name'] ?? '' ) ) {
			WP_Application_Passwords::record_application_password_usage( $user_id, $password['uuid'] );
			return $password['uuid'];
		}
	}

	$created = WP_Application_Passwords::create_new_application_password(
		$user_id,
		array(
			'name' => $name,
		)
	);

	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( $created->get_error_message() );
	}

	$password = $created[1];
	WP_Application_Passwords::record_application_password_usage( $user_id, $password['uuid'] );

	return $password['uuid'];
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

function e2e_delete_term_by_slug( $slug, $taxonomy ) {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term ) {
		wp_delete_term( (int) $term->term_id, $taxonomy );
	}
}

function e2e_insert_post( $type, $title, $content, $author_id, $status = 'publish' ) {
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_author'  => $author_id,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}

	return (int) $id;
}

function e2e_force_post_datetime( $post_id, $status, $local_datetime, $gmt_datetime ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deterministic E2E fixture setup for unusual scheduled-date states.
	$updated = $wpdb->update(
		$wpdb->posts,
		array(
			'post_status'   => $status,
			'post_date'     => $local_datetime,
			'post_date_gmt' => $gmt_datetime,
		),
		array( 'ID' => $post_id ),
		array( '%s', '%s', '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		throw new RuntimeException( $wpdb->last_error );
	}

	clean_post_cache( $post_id );
}

function e2e_insert_revisioned_post( $type, $title, $original_content, $updated_content, $author_id ) {
	$post_id     = e2e_insert_post( $type, $title, $original_content, $author_id );
	$revision_id = wp_save_post_revision( $post_id );

	if ( is_wp_error( $revision_id ) ) {
		throw new RuntimeException( $revision_id->get_error_message() );
	}
	if ( ! $revision_id ) {
		throw new RuntimeException( "Failed to create E2E revision for {$title}." );
	}

	$updated = wp_update_post(
		wp_slash(
			array(
				'ID'           => $post_id,
				'post_content' => $updated_content,
			)
		),
		true
	);

	if ( is_wp_error( $updated ) ) {
		throw new RuntimeException( $updated->get_error_message() );
	}

	return array(
		'post_id'     => (int) $post_id,
		'revision_id' => (int) $revision_id,
	);
}

function e2e_ensure_role( $role, $display_name, $caps ) {
	$wp_role = get_role( $role );

	if ( ! $wp_role ) {
		add_role( $role, $display_name, array_fill_keys( $caps, true ) );
		$wp_role = get_role( $role );
	}

	foreach ( $caps as $cap ) {
		$wp_role->add_cap( $cap );
	}
}

function e2e_block_by_path( $blocks, $path ) {
	$segments       = array_map( 'absint', explode( '.', $path ) );
	$current_blocks = $blocks;
	$current_block  = null;

	foreach ( $segments as $segment ) {
		if ( ! is_array( $current_blocks ) || ! array_key_exists( $segment, $current_blocks ) ) {
			throw new RuntimeException( "Unknown E2E block path: {$path}" );
		}

		$current_block  = $current_blocks[ $segment ];
		$current_blocks = $current_block['innerBlocks'] ?? array();
	}

	return $current_block;
}

function e2e_filter_empty_freeform_blocks( $blocks ) {
	$filtered = array();

	foreach ( $blocks as $block ) {
		$is_empty_freeform = null === ( $block['blockName'] ?? null )
			&& '' === trim( $block['innerHTML'] ?? '' )
			&& empty( $block['innerBlocks'] );

		if ( $is_empty_freeform ) {
			continue;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = e2e_filter_empty_freeform_blocks( $block['innerBlocks'] );
		}

		$filtered[] = $block;
	}

	return $filtered;
}

function e2e_block_hash_for_post_path( $post_id, $path ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		throw new RuntimeException( "Unknown E2E post ID: {$post_id}" );
	}

	$block = e2e_block_by_path( e2e_filter_empty_freeform_blocks( parse_blocks( $post->post_content ) ), $path );

	return hash( 'sha256', serialize_block( $block ) );
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

function e2e_insert_media( $post_id, $author_id, $suffix, $mime_type = 'text/plain' ) {
	$is_image = str_starts_with( $mime_type, 'image/' );
	$filename = $is_image ? "mcp-e2e-{$suffix}.png" : "mcp-e2e-{$suffix}.txt";
	$contents = $is_image
		? base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=' )
		: "MCP E2E media file {$suffix}.";
	$upload = wp_upload_bits( $filename, null, $contents );
	if ( ! empty( $upload['error'] ) ) {
		throw new RuntimeException( $upload['error'] );
	}

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime_type,
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

	if ( $is_image ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	}

	return (int) $id;
}

function e2e_ensure_plugin( $relative_path, $name, $headers = array() ) {
	$plugin_path = WP_PLUGIN_DIR . '/' . ltrim( $relative_path, '/' );
	$plugin_dir  = dirname( $plugin_path );

	if ( ! is_dir( $plugin_dir ) ) {
		wp_mkdir_p( $plugin_dir );
	}

	$header_lines = '';
	foreach ( $headers as $header => $value ) {
		$header_lines .= " * {$header}: {$value}\n";
	}

	$contents = <<<PHP
<?php
/**
 * Plugin Name: {$name}
 * Version: 1.0.0
{$header_lines} */

defined( 'ABSPATH' ) || exit;
PHP;

	file_put_contents( $plugin_path, $contents . "\n" );
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

function e2e_result_error_code( $result ) {
	if ( is_wp_error( $result ) ) {
		return $result->get_error_code();
	}

	if ( is_array( $result ) && false === ( $result['success'] ?? true ) && is_array( $result['error'] ?? null ) ) {
		return $result['error']['code'] ?? null;
	}

	return null;
}

function e2e_result_error_message( $result ) {
	if ( is_wp_error( $result ) ) {
		return $result->get_error_message();
	}

	if ( is_array( $result ) && false === ( $result['success'] ?? true ) ) {
		if ( is_array( $result['error'] ?? null ) ) {
			return $result['error']['message'] ?? '';
		}

		return (string) ( $result['error'] ?? '' );
	}

	return '';
}

function e2e_result_is_success( $result ) {
	return is_array( $result ) && true === ( $result['success'] ?? false );
}

function e2e_get_path_value( $value, $path, &$exists = false ) {
	$exists  = true;
	$segments = explode( '.', $path );

	foreach ( $segments as $segment ) {
		if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
			$value = $value[ $segment ];
			continue;
		}

		if ( is_array( $value ) && ctype_digit( (string) $segment ) ) {
			$index = (int) $segment;
			if ( array_key_exists( $index, $value ) ) {
				$value = $value[ $index ];
				continue;
			}
		}

		$exists = false;
		return null;
	}

	return $value;
}

function e2e_write_summary( $summary ) {
	$artifact_dir = WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/e2e-artifacts';
	if ( ! is_dir( $artifact_dir ) ) {
		wp_mkdir_p( $artifact_dir );
	}

	file_put_contents(
		$artifact_dir . '/e2e-summary.json',
		wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
	);
}

function e2e_apply_case_setup( $case ) {
	$setup = $case['setup'] ?? array();
	if ( ! is_array( $setup ) ) {
		return array();
	}

	$restore = array();

	if ( array_key_exists( 'active_plugins', $setup ) && is_array( $setup['active_plugins'] ) ) {
		$restore['active_plugins'] = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_values( array_map( 'strval', $setup['active_plugins'] ) ) );
	}

	return $restore;
}

function e2e_restore_case_setup( $restore ) {
	if ( array_key_exists( 'active_plugins', $restore ) ) {
		update_option( 'active_plugins', $restore['active_plugins'] );
	}
}

$manifest_path = WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/tests/e2e/abilities-manifest.json';
$manifest      = json_decode( file_get_contents( $manifest_path ), true );

if ( ! is_array( $manifest ) ) {
	throw new RuntimeException( 'E2E ability manifest is missing or invalid JSON.' );
}

$admin         = get_user_by( 'login', 'admin' );
$admin_id      = (int) $admin->ID;
$author_id     = e2e_ensure_user( 'author_test', 'author@test.local', 'author' );
$editor_id     = e2e_ensure_user( 'editor_test', 'editor@test.local', 'editor' );
$subscriber_id = e2e_ensure_user( 'subscriber_test', 'subscriber@test.local', 'subscriber' );
$no_role_id    = e2e_ensure_user( 'no_role_test', 'no-role@test.local', 'subscriber' );
( new WP_User( $no_role_id ) )->set_role( '' );
e2e_ensure_application_password( $admin_id, 'MCP E2E App Password' );

e2e_ensure_role(
	'book_manager',
	'Book Manager',
	[
		'read',
		'edit_mcp_books',
		'edit_others_mcp_books',
		'edit_published_mcp_books',
		'publish_mcp_books',
		'delete_mcp_books',
		'delete_others_mcp_books',
		'delete_published_mcp_books',
		'read_private_mcp_books',
		'assign_mcp_genres',
	]
);
e2e_ensure_role(
	'case_manager',
	'Case Manager',
	[
		'read',
		'edit_mcp_cases',
		'edit_others_mcp_cases',
		'edit_published_mcp_cases',
		'publish_mcp_cases',
		'create_mcp_cases',
		'delete_mcp_cases',
		'delete_others_mcp_cases',
		'delete_published_mcp_cases',
		'read_private_mcp_cases',
	]
);

$book_manager_id = e2e_ensure_user( 'book_manager_test', 'book-manager@test.local', 'book_manager' );
$case_manager_id = e2e_ensure_user( 'case_manager_test', 'case-manager@test.local', 'case_manager' );
( new WP_User( $book_manager_id ) )->set_role( 'book_manager' );
( new WP_User( $case_manager_id ) )->set_role( 'case_manager' );

e2e_delete_term_by_slug( 'mcp-e2e-created-category', 'category' );
e2e_delete_term_by_slug( 'mcp-e2e-created-tag', 'post_tag' );
e2e_delete_term_by_slug( 'mcp-e2e-updated-category', 'category' );
e2e_delete_term_by_slug( 'mcp-e2e-updated-tag', 'post_tag' );

$fixtures = array(
	'admin_id'           => $admin_id,
	'author_id'          => $author_id,
	'editor_id'          => $editor_id,
	'subscriber_id'      => $subscriber_id,
	'no_role_id'         => $no_role_id,
	'book_manager_id'    => $book_manager_id,
	'case_manager_id'    => $case_manager_id,
	'category_id'        => e2e_ensure_term_id( 'MCP E2E Category', 'category' ),
	'tag_id'             => e2e_ensure_term_id( 'mcp-e2e-tag', 'post_tag' ),
	'genre_id'           => e2e_ensure_term_id( 'MCP E2E Genre', 'mcp_genre' ),
	'parent_category_id' => e2e_ensure_term_id( 'MCP E2E Parent Category', 'category' ),
	'update_category_id' => e2e_ensure_term_id( 'MCP E2E Update Category', 'category' ),
	'update_tag_id'      => e2e_ensure_term_id( 'mcp-e2e-update-tag', 'post_tag' ),
	'delete_category_id' => e2e_ensure_term_id( 'MCP E2E Delete Category', 'category' ),
	'delete_tag_id'      => e2e_ensure_term_id( 'mcp-e2e-delete-tag', 'post_tag' ),
);

$fixtures['post_id']         = e2e_insert_post( 'post', 'MCP E2E Post', 'Content for MCP E2E post.', $author_id );
$fixtures['partial_post_id'] = e2e_insert_post(
	'post',
	'MCP E2E Partial Post',
	'<!-- wp:heading {"level":2} --><h2>Overview</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Original overview.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":2} --><h2>Details</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Original details.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":2} --><h2>Wrap Up</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Final note.</p><!-- /wp:paragraph -->',
	$author_id
);
$fixtures['ambiguous_partial_post_id'] = e2e_insert_post(
	'post',
	'MCP E2E Ambiguous Partial Post',
	'<!-- wp:heading {"level":2} --><h2>Details</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>First details.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":2} --><h2>Details</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Second details.</p><!-- /wp:paragraph -->',
	$author_id
);
$fixtures['classic_partial_post_id'] = e2e_insert_post(
	'post',
	'MCP E2E Classic Partial Post',
	'<p>Classic intro.</p><p>Replace this exact sentence.</p><p>Classic ending.</p>',
	$author_id
);
$fixtures['block_path_post_id'] = e2e_insert_post(
	'post',
	'MCP E2E Block Path Post',
	'<!-- wp:paragraph --><p>First top-level block.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Middle top-level block.</p><!-- /wp:paragraph -->
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph --><p>Nested paragraph block.</p><!-- /wp:paragraph --></div>
<!-- /wp:group -->',
	$author_id
);
$fixtures['block_hash_post_id'] = e2e_insert_post(
	'post',
	'MCP E2E Block Hash Post',
	'<!-- wp:paragraph --><p>Hash intro block.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Hash target block.</p><!-- /wp:paragraph -->',
	$author_id
);
$fixtures['delete_post_id']  = e2e_insert_post( 'post', 'MCP E2E Delete Post', 'Delete fixture.', $author_id );
$fixtures['restore_post_id'] = e2e_insert_post( 'post', 'MCP E2E Restore Post', 'Restore fixture.', $author_id );
$fixtures['meta_post_id']    = e2e_insert_post( 'post', 'MCP E2E Meta Post', 'Meta fixture.', $author_id );
$fixtures['delete_meta_post_id'] = e2e_insert_post( 'post', 'MCP E2E Delete Meta Post', 'Delete meta fixture.', $author_id );
$fixtures['bulk_trash_post_id']   = e2e_insert_post( 'post', 'MCP E2E Bulk Trash Post', 'Bulk trash fixture.', $author_id );
$fixtures['bulk_publish_post_id'] = e2e_insert_post( 'post', 'MCP E2E Bulk Publish Post', 'Bulk publish fixture.', $author_id, 'draft' );
$fixtures['bulk_missing_post_id'] = 987654321;
$fixtures['page_id']         = e2e_insert_post( 'page', 'MCP E2E Page', 'Content for MCP E2E page.', $editor_id );
$fixtures['book_id']         = e2e_insert_post( 'mcp_book', 'MCP E2E Book', 'Content for MCP E2E book.', $book_manager_id );
$fixtures['delete_book_id']  = e2e_insert_post( 'mcp_book', 'MCP E2E Delete Book', 'Delete CPT fixture.', $book_manager_id );
$fixtures['case_id']         = e2e_insert_post( 'mcp_case_study', 'MCP E2E Case Study', 'Content for MCP E2E case study.', $case_manager_id );
$fixtures['delete_case_id']  = e2e_insert_post( 'mcp_case_study', 'MCP E2E Delete Case Study', 'Delete CPT fixture.', $case_manager_id );
$fixtures['block_path_page_id'] = e2e_insert_post(
	'page',
	'MCP E2E Block Path Page',
	'<!-- wp:paragraph --><p>Page first block.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Page target block.</p><!-- /wp:paragraph -->',
	$editor_id
);
$fixtures['delete_page_id']  = e2e_insert_post( 'page', 'MCP E2E Delete Page', 'Delete fixture.', $editor_id );
$fixtures['restore_page_id'] = e2e_insert_post( 'page', 'MCP E2E Restore Page', 'Restore fixture.', $editor_id );
$revision_post               = e2e_insert_revisioned_post( 'post', 'MCP E2E Revision Post', 'Original revision content.', 'Updated revision content.', $author_id );
$restore_revision_post       = e2e_insert_revisioned_post( 'post', 'MCP E2E Restore Revision Post', 'Restore original revision content.', 'Restore updated revision content.', $author_id );
$revision_page               = e2e_insert_revisioned_post( 'page', 'MCP E2E Revision Page', 'Original page revision content.', 'Updated page revision content.', $editor_id );
$fixtures['revision_post_id']          = $revision_post['post_id'];
$fixtures['revision_id']               = $revision_post['revision_id'];
$fixtures['restore_revision_post_id']  = $restore_revision_post['post_id'];
$fixtures['restore_revision_id']       = $restore_revision_post['revision_id'];
$fixtures['revision_page_id']          = $revision_page['post_id'];
$fixtures['revision_page_revision_id'] = $revision_page['revision_id'];
$fixtures['block_hash_post_path_1'] = e2e_block_hash_for_post_path( $fixtures['block_hash_post_id'], '1' );

wp_trash_post( $fixtures['restore_post_id'] );
wp_trash_post( $fixtures['restore_page_id'] );

wp_set_post_categories( $fixtures['post_id'], array( $fixtures['category_id'] ) );
wp_set_post_tags( $fixtures['post_id'], array( $fixtures['tag_id'] ) );
wp_set_object_terms( $fixtures['book_id'], array( $fixtures['genre_id'] ), 'mcp_genre' );
update_post_meta( $fixtures['meta_post_id'], 'mcp_e2e_public_key', 'public meta value' );
update_post_meta( $fixtures['meta_post_id'], '_mcp_e2e_protected_key', 'hidden protected value' );
update_post_meta( $fixtures['delete_meta_post_id'], 'mcp_e2e_delete_key', 'delete me' );
delete_metadata( 'post', $fixtures['bulk_missing_post_id'], 'mcp_e2e_orphaned_meta' );
add_metadata( 'post', $fixtures['bulk_missing_post_id'], 'mcp_e2e_orphaned_meta', 'orphaned meta fixture', true );
delete_option( '_transient_timeout_mcp_e2e_expired' );
add_option( '_transient_timeout_mcp_e2e_expired', time() - HOUR_IN_SECONDS, '', 'no' );
delete_option( 'mcp_e2e_autoloaded_option' );
add_option( 'mcp_e2e_autoloaded_option', 'autoloaded option fixture', '', true );

$fixtures['comment_id']       = e2e_insert_comment( $fixtures['post_id'], 'approve' );
$fixtures['trash_comment_id'] = e2e_insert_comment( $fixtures['post_id'], 'trash' );
$fixtures['spam_comment_id']  = e2e_insert_comment( $fixtures['post_id'], 'spam' );
$fixtures['media_id']          = e2e_insert_media( $fixtures['post_id'], $author_id, 'read-update' );
$fixtures['delete_media_id']   = e2e_insert_media( $fixtures['post_id'], $author_id, 'delete' );
$fixtures['featured_image_id'] = e2e_insert_media( $fixtures['post_id'], $author_id, 'featured-image', 'image/png' );
$fixtures['orphaned_media_id'] = e2e_insert_media( 0, $author_id, 'orphaned' );
$fixtures['yoast_score_post_id'] = e2e_insert_post( 'post', 'MCP E2E Yoast Score Post', 'Yoast score fixture.', $author_id );
wp_update_post(
	array(
		'ID'          => $fixtures['yoast_score_post_id'],
		'post_status' => 'pending',
	)
);

if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', 'e2e' );
}

update_post_meta( $fixtures['yoast_score_post_id'], '_yoast_wpseo_linkdex', '82' );
update_post_meta( $fixtures['yoast_score_post_id'], '_yoast_wpseo_content_score', '74' );

$fixtures['no_featured_post_id'] = e2e_insert_post( 'post', 'MCP E2E No Featured Image Post', 'Missing featured image fixture.', $author_id );
$fixtures['no_featured_page_id'] = e2e_insert_post( 'page', 'MCP E2E No Featured Image Page', 'Missing featured image page fixture.', $editor_id );
$fixtures['stuck_scheduled_post_id'] = e2e_insert_post( 'post', 'MCP E2E Stuck Scheduled Post', 'Missed schedule fixture.', $author_id, 'draft' );

$future_timestamp = time() + DAY_IN_SECONDS;
$past_timestamp   = time() - DAY_IN_SECONDS;
e2e_force_post_datetime(
	$fixtures['no_featured_post_id'],
	'publish',
	wp_date( 'Y-m-d H:i:s', $future_timestamp ),
	gmdate( 'Y-m-d H:i:s', $future_timestamp )
);
e2e_force_post_datetime(
	$fixtures['no_featured_page_id'],
	'publish',
	wp_date( 'Y-m-d H:i:s', $future_timestamp ),
	gmdate( 'Y-m-d H:i:s', $future_timestamp )
);
e2e_force_post_datetime(
	$fixtures['stuck_scheduled_post_id'],
	'future',
	wp_date( 'Y-m-d H:i:s', $past_timestamp ),
	gmdate( 'Y-m-d H:i:s', $past_timestamp )
);

e2e_ensure_plugin(
	'mcp-e2e-plugin/mcp-e2e-plugin.php',
	'MCP E2E Plugin',
	array(
		'Requires at least' => '5.0',
		'Tested up to'      => '5.0',
	)
);
e2e_ensure_plugin( 'mcp-e2e-duplicate/mcp-e2e-duplicate.php', 'MCP E2E Duplicate Folder Plugin' );
e2e_ensure_plugin( 'mcp-e2e-duplicate.php', 'MCP E2E Duplicate Single Plugin' );
e2e_ensure_plugin( 'wp-super-cache/wp-cache.php', 'WP Super Cache' );
e2e_ensure_plugin( 'updraftplus/updraftplus.php', 'UpdraftPlus' );
e2e_ensure_plugin( 'backwpup/backwpup.php', 'BackWPup' );
e2e_ensure_plugin( 'duplicator/duplicator.php', 'Duplicator' );
e2e_ensure_plugin( 'all-in-one-wp-migration/all-in-one-wp-migration.php', 'All-in-One WP Migration' );
deactivate_plugins(
	array(
		'mcp-e2e-plugin/mcp-e2e-plugin.php',
		'mcp-e2e-duplicate/mcp-e2e-duplicate.php',
		'mcp-e2e-duplicate.php',
		'wp-super-cache/wp-cache.php',
		'updraftplus/updraftplus.php',
		'backwpup/backwpup.php',
		'duplicator/duplicator.php',
		'all-in-one-wp-migration/all-in-one-wp-migration.php',
	),
	true
);
activate_plugin( 'wp-super-cache/wp-cache.php' );
activate_plugin( 'updraftplus/updraftplus.php' );
update_option( 'updraft_last_backup', array( 'backup_time' => 1747278000, 'backup_nonce' => '999999999999' ) );
update_option( 'updraft_interval', 'weekly' );
update_option( 'updraft_interval_database', 'weekly' );
update_option( 'backwpup_jobs', array( array( 'lastrun' => 1747191600 ) ) );

$fixtures['fixture_plugin']        = 'mcp-e2e-plugin/mcp-e2e-plugin.php';
$fixtures['ambiguous_plugin_slug'] = 'mcp-e2e-duplicate';
$fixtures['protected_plugin']      = 'webmastery-site-toolkit-for-mcp/webmastery-site-toolkit-for-mcp.php';

set_site_transient(
	'update_plugins',
	(object) array(
		'last_checked' => time(),
		'checked'      => array(
			$fixtures['fixture_plugin'] => '1.0.0',
		),
		'response'     => array(
			$fixtures['fixture_plugin'] => (object) array(
				'plugin'          => $fixtures['fixture_plugin'],
				'slug'            => 'mcp-e2e-plugin',
				'new_version'     => '1.2.0',
				'requires'        => '5.0',
				'tested'          => '5.0',
				'security_update' => true,
			),
		),
	)
);

$roles = array(
	'admin'        => $admin_id,
	'author'       => $author_id,
	'editor'       => $editor_id,
	'subscriber'   => $subscriber_id,
	'no_role'      => $no_role_id,
	'book_manager' => $book_manager_id,
	'case_manager' => $case_manager_id,
);

$registered = array_filter(
	array_keys( wp_get_abilities() ),
	static function ( $ability_name ) {
		return str_starts_with( $ability_name, 'webmastery-site-toolkit-for-mcp/' );
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

echo 'INFO registered webmastery-site-toolkit-for-mcp abilities: ' . count( $registered ) . "\n";
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
	$case = e2e_resolve_placeholders( $case, $fixtures );

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
	$restore = e2e_apply_case_setup( $case );
	$result  = $ability->execute( $input );
	e2e_restore_case_setup( $restore );
	$ok      = ! is_wp_error( $result ) && e2e_result_is_success( $result );
	$passed  = ( 'success' === $expect && $ok ) || ( 'failure' === $expect && ! $ok );

	if ( $passed && ! empty( $case['expect_error_code'] ) ) {
		$passed = $case['expect_error_code'] === e2e_result_error_code( $result );
	}

	if ( $passed && ! empty( $case['expect_error_message_contains'] ) ) {
		$passed = false !== strpos( e2e_result_error_message( $result ), $case['expect_error_message_contains'] );
	}

	if ( $passed && ! empty( $case['assert_paths'] ) ) {
		foreach ( (array) $case['assert_paths'] as $path ) {
			e2e_get_path_value( $result, $path, $exists );
			if ( ! $exists ) {
				$passed = false;
				break;
			}
		}
	}

	if ( $passed && ! empty( $case['assert_values'] ) ) {
		foreach ( (array) $case['assert_values'] as $path => $expected_value ) {
			$actual_value = e2e_get_path_value( $result, $path, $exists );
			if ( ! $exists || $expected_value !== $actual_value ) {
				$passed = false;
				break;
			}
		}
	}

	if ( $passed && ! empty( $case['assert_contains'] ) ) {
		foreach ( (array) $case['assert_contains'] as $path => $expected_text ) {
			if ( is_array( $expected_text ) ) {
				$path          = $expected_text['path'] ?? '';
				$expected_text = $expected_text['value'] ?? '';
			}

			$actual_value = e2e_get_path_value( $result, $path, $exists );
			if ( ! $exists || ! is_string( $actual_value ) || false === strpos( $actual_value, $expected_text ) ) {
				$passed = false;
				break;
			}
		}
	}

	if ( $passed && ! empty( $case['assert_not_contains'] ) ) {
		foreach ( (array) $case['assert_not_contains'] as $path => $unexpected_text ) {
			if ( is_array( $unexpected_text ) ) {
				$path            = $unexpected_text['path'] ?? '';
				$unexpected_text = $unexpected_text['value'] ?? '';
			}

			$actual_value = e2e_get_path_value( $result, $path, $exists );
			if ( ! $exists || ! is_string( $actual_value ) || false !== strpos( $actual_value, $unexpected_text ) ) {
				$passed = false;
				break;
			}
		}
	}

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
