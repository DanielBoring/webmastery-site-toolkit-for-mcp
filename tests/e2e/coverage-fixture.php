<?php

// Loaded only by the disposable ability runner, never as a WordPress plugin.
function wstm120_seed_fixtures( array &$fixtures ): void {
	foreach ( array( 'draft', 'publish', 'private' ) as $status ) {
		$fixtures[ "wstm120_contributor_{$status}_id" ] = e2e_insert_post(
			'post', "WSTM120 Contributor {$status}", 'WSTM120 original content.', $fixtures['contributor_id'], $status
		);
	}
	$fixtures['wstm120_other_draft_id'] = e2e_insert_post( 'post', 'WSTM120 Other Draft', 'WSTM120 other content.', $fixtures['editor_id'], 'draft' );
	$fixtures['wstm120_contributor_delete_id'] = e2e_insert_post( 'post', 'WSTM120 Contributor Delete', 'WSTM120 delete content.', $fixtures['contributor_id'], 'draft' );
	$fixtures['wstm120_contributor_page_id'] = e2e_insert_post( 'page', 'WSTM120 Contributor Private Page', 'WSTM120 private page.', $fixtures['contributor_id'], 'private' );
	$fixtures['wstm120_page_editor_private_id'] = e2e_insert_post( 'page', 'WSTM120 Page Editor Private', 'WSTM120 page editor content.', $fixtures['wstm120_page_editor_id'], 'private' );
	$fixtures['wstm120_page_editor_trash_id'] = e2e_insert_post( 'page', 'WSTM120 Page Editor Trash', 'WSTM120 page editor content.', $fixtures['wstm120_page_editor_id'], 'draft' );
	$fixtures['wstm120_contributor_trash_published_id'] = e2e_insert_post( 'post', 'WSTM120 Contributor Trashed Published', 'WSTM120 formerly published.', $fixtures['contributor_id'], 'publish' );
	foreach ( array( 'wstm120_page_editor_trash_id', 'wstm120_contributor_trash_published_id' ) as $key ) {
		if ( ! wp_trash_post( $fixtures[ $key ] ) ) {
			throw new RuntimeException( 'Could not seed WSTM120 status-aware trash fixture.' );
		}
	}
	foreach ( array( 'post', 'page' ) as $type ) {
		$id = e2e_insert_post( $type, "WSTM120 Contributor Trashed {$type}", 'WSTM120 trashed content.', $fixtures['contributor_id'], 'draft' );
		if ( ! wp_trash_post( $id ) ) {
			throw new RuntimeException( 'Could not seed WSTM120 trash fixture.' );
		}
		$fixtures[ "wstm120_contributor_trash_{$type}_id" ] = $id;
	}
	$fixtures['wstm120_media_parent_id'] = e2e_insert_post( 'post', 'WSTM120 Media Parent', 'WSTM120 media parent.', $fixtures['editor_id'], 'draft' );
	$fixtures['wstm120_media_id'] = e2e_insert_media( $fixtures['wstm120_media_parent_id'], $fixtures['editor_id'], 'wstm120-denied-delete', 'image/png' );
	update_post_meta( $fixtures['wstm120_media_id'], '_wp_attachment_image_alt', 'WSTM120 retained alt text' );
	update_post_meta( $fixtures['wstm120_media_parent_id'], '_thumbnail_id', $fixtures['wstm120_media_id'] );
	update_post_meta( $fixtures['wstm120_contributor_draft_id'], 'wstm120_sentinel', 'unchanged' );
	if ( ! is_file( get_attached_file( $fixtures['wstm120_media_id'] ) ) ) {
		throw new RuntimeException( 'Missing WSTM120 media file before permission testing.' );
	}
}

function wstm120_snapshot(): array {
	global $wpdb;
	$snapshot = array();
	foreach ( array( 'posts' => 'ID', 'postmeta' => 'meta_id', 'term_relationships' => 'object_id, term_taxonomy_id' ) as $table => $order ) {
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->$table} ORDER BY {$order}", ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( "Could not snapshot {$table}: {$wpdb->last_error}" );
		}
		$snapshot[ $table ] = array( 'count' => count( $rows ), 'sha256' => hash( 'sha256', serialize( $rows ) ) );
	}
	$cron = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron'" );
	if ( $wpdb->last_error ) {
		throw new RuntimeException( "Could not snapshot cron: {$wpdb->last_error}" );
	}
	$snapshot['cron'] = hash( 'sha256', serialize( $cron ) );
	$uploads = wp_upload_dir();
	if ( $uploads['error'] ) {
		throw new RuntimeException( $uploads['error'] );
	}
	$files = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads['basedir'], FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$hash = hash_file( 'sha256', $file->getPathname() );
			if ( false === $hash ) {
				throw new RuntimeException( 'Could not hash upload fixture.' );
			}
			$files[ substr( $file->getPathname(), strlen( $uploads['basedir'] ) ) ] = $hash;
		}
	}
	ksort( $files );
	$snapshot['files'] = $files;
	return $snapshot;
}

function wstm120_assert_capabilities( array $case ): array {
	$evidence = array();
	foreach ( $case['assert_capabilities'] ?? array() as $assertion ) {
		$actual = current_user_can( $assertion['capability'], ...$assertion['args'] );
		$evidence[] = array_merge( $assertion, array( 'actual' => $actual, 'passed' => $actual === $assertion['allowed'] ) );
	}
	return $evidence;
}

function wstm120_assert_stored_post( array $assertion, $result ): bool {
	$id = $assertion['post_id'] ?? ( is_array( $result ) ? ( $result['data']['id'] ?? 0 ) : 0 );
	clean_post_cache( $id );
	$post = get_post( $id, ARRAY_A );
	if ( ! $post ) {
		return false;
	}
	foreach ( $assertion['fields'] as $field => $value ) {
		if ( ! array_key_exists( $field, $post ) || (string) $value !== (string) $post[ $field ] ) {
			return false;
		}
	}
	return true;
}
