<?php

declare(strict_types=1);

// Separate process: none of these fake APIs can replace the unit bootstrap's APIs.
require_once dirname( __DIR__, 2 ) . '/e2e/destructive-safety-uploads.php';
require_once dirname( __DIR__, 2 ) . '/e2e/destructive-safety-cleanup.php';
require_once dirname( __DIR__, 2 ) . '/e2e/destructive-safety-evidence.php';

function wstm116_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_type( $id ) { return get_post( $id )->post_type; }
function get_attached_file( $id ) { return $GLOBALS['attached'][ $id ]; }
function wp_get_attachment_metadata( $id ) { return $GLOBALS['metadata'][ $id ] ?? false; }
function get_post_meta( $id, $key = null, $single = false ) {
	return null === $key ? ( $GLOBALS['meta'][ $id ] ?? array() ) : ( $GLOBALS['meta'][ $id ][ $key ] ?? '' );
}
function wp_next_scheduled( $hook, $args ) { return $GLOBALS['cron'][ $args[0] ] ?? false; }
function wp_delete_post( $id, $force ) {
	$GLOBALS['deleted_posts'][] = $id;
	unset( $GLOBALS['posts'][ $id ] );
	if ( 'post-meta' !== $GLOBALS['mode'] ) {
		unset( $GLOBALS['meta'][ $id ] );
	}
	if ( 'post-cron' !== $GLOBALS['mode'] ) {
		unset( $GLOBALS['cron'][ $id ] );
	}
}
function wp_delete_attachment( $id, $force ) {
	$GLOBALS['deleted_attachments'][] = $id;
	if ( 'veto' === $GLOBALS['mode'] && 1 === $id ) {
		return false;
	}
	wp_delete_file( get_attached_file( $id ) );
	foreach ( $GLOBALS['metadata'][ $id ] ?? array() as $file ) {
		wp_delete_file( $file );
	}
	foreach ( $GLOBALS['meta'][ $id ]['_wp_attachment_backup_sizes'] ?? array() as $file ) {
		wp_delete_file( $file );
	}
	wp_delete_post( $id, $force );
	return true;
}
function wp_delete_file( $file ) {
	$GLOBALS['deleted_files'][] = $file;
	if ( 'file-veto' !== $GLOBALS['mode'] && is_file( $file ) ) {
		unlink( $file );
	}
}
function term_exists( $id, $taxonomy ) { return $GLOBALS['terms'][ $id ] ?? false; }
function wp_delete_term( $id, $taxonomy ) {
	unset( $GLOBALS['terms'][ $id ] );
	if ( 'term-meta' !== $GLOBALS['mode'] ) {
		unset( $GLOBALS['term_meta'][ $id ] );
	}
}
function get_term_meta( $id ) { return $GLOBALS['term_meta'][ $id ] ?? array(); }
function wp_cache_delete( $key, $group ) {}
function get_option( $key, $default = false ) { return $GLOBALS['control'] ?? $default; }
function delete_option( $key ) { unset( $GLOBALS['control'] ); }
function wp_delete_user( $id ) {
	$GLOBALS['deleted_users'][] = $id;
	// Model WP's implicit deletion so removing the runner's actor guard is unsafe.
	foreach ( $GLOBALS['posts'] as $post_id => $post ) {
		if ( $post->post_author === $id ) {
			'attachment' === $post->post_type ? wp_delete_attachment( $post_id, true ) : wp_delete_post( $post_id, true );
		}
	}
	if ( 'actor-veto' !== $GLOBALS['mode'] ) {
		unset( $GLOBALS['users'][ $id ] );
	}
}
function get_userdata( $id ) { return $GLOBALS['users'][ $id ] ?? false; }
function wp_set_current_user( $id ) {
	if ( 'abort' === $GLOBALS['mode'] ) {
		throw new RuntimeException( 'Interrupted final cleanup.' );
	}
	$GLOBALS['current_user'] = $id;
}
class WP_Application_Passwords {
	public static function delete_all_application_passwords( $id ): void {
		if ( 'credentials' !== $GLOBALS['mode'] ) {
			unset( $GLOBALS['credentials'][ $id ] );
		}
	}
	public static function get_user_application_passwords( $id ): array { return $GLOBALS['credentials'][ $id ] ?? array(); }
}

$mode = $argv[1];
$root = realpath( $argv[2] );
$owned_uploads = new Wstm116_Uploads( $root, 'https://example.test/uploads', str_repeat( 'a', 32 ), fileowner( $root ) );
$owned_uploads->acquire();
$directory = $owned_uploads->directory();
$owned_configuration = array( 'path' => realpath( $directory ) );
$files = array( $directory . '/main.png', $directory . '/orphan.png', $directory . '/other.png' );
$file_hashes = $file_identities = array();
// Construct before populating the ledger, just as the runner does.
$check_file = wstm116_cleanup_file_guard( $owned_uploads, $owned_configuration, $files, $file_hashes, $file_identities );
foreach ( $files as $file ) {
	file_put_contents( $file, 'original fixture ' . basename( $file ) );
	$file_hashes[ $file ] = hash_file( 'sha256', $file );
	$file_identities[ $file ] = array_intersect_key( lstat( $file ), array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode' ) ) );
}
$foreign = $root . '/foreign.png';
file_put_contents( $foreign, 'foreign bytes' );
$foreign_hash = hash_file( 'sha256', $foreign );
$marker = $directory . '/.wstm116-owner';
$marker_hash = hash_file( 'sha256', $marker );
$posts = array(
	1 => (object) array( 'post_type' => 'attachment', 'post_author' => 11 ),
	2 => (object) array( 'post_type' => 'post', 'post_author' => 12 ),
	3 => (object) array( 'post_type' => 'attachment', 'post_author' => 11 ),
	99 => (object) array( 'post_type' => 'attachment', 'post_author' => 99 ),
);
$attached = array( 1 => $files[0], 3 => $files[2], 99 => $foreign );
$meta = array( 1 => array( 'sentinel' => 'owned' ), 2 => array( 'sentinel' => 'owned' ), 3 => array( 'sentinel' => 'owned' ) );
$metadata = array();
$cron = array( 2 => 123 );
$terms = array( 5 => true );
$term_meta = array( 5 => array( 'sentinel' => 'owned' ) );
$users = array( 11 => true, 12 => true, 99 => true );
$credentials = array( 11 => array( 'owned credential' ), 12 => array( 'owned credential' ), 99 => array( 'foreign credential' ) );
$control = array( 'owner' => 'owned-run' );
$deleted_posts = $deleted_attachments = $deleted_files = $deleted_users = array();
$transport = new class {
	public bool $closed = false;
	public function close(): void {
		$this->closed = true;
		if ( 'transport' === $GLOBALS['mode'] ) {
			throw new RuntimeException( 'Transport close failed.' );
		}
	}
};
if ( 'metadata' === $mode ) {
	$metadata[1] = array( 'file' => $foreign );
} elseif ( 'cross-reference' === $mode ) {
	$metadata[1] = array( 'file' => $files[2] );
} elseif ( 'backup' === $mode ) {
	$meta[1]['_wp_attachment_backup_sizes'] = array( 'backup' => $foreign );
} elseif ( 'foreign-main' === $mode ) {
	$attached[1] = $foreign;
} elseif ( 'changed-hash' === $mode ) {
	file_put_contents( $files[0], 'changed fixture bytes' );
} elseif ( 'changed-identity' === $mode ) {
	// Portable identity mismatch even on Windows filesystems with zero inodes.
	$file_identities[ $files[0] ]['ino']++;
} elseif ( 'foreign-control' === $mode ) {
	$control['owner'] = 'foreign-run';
}
$summary = array( 'failed' => 'prior-failure' === $mode ? 1 : 0, 'cleanup' => array(), 'completed' => false, 'cleanup_complete' => false );
if ( 'nontrue-entry' === $mode ) {
	$summary['cleanup']['earlier cleanup'] = 1;
}
$evidence = new Wstm116_Evidence( $root . '/summary.json' );
$evidence->save( $summary );
try {
	wstm116_cleanup( $summary, array( 'administrator' => $transport ), true, 'owned-run', array( 1, 2, 3 ), array( 'category' => 5 ), $files, $owned_uploads, array( 'author' => 11, 'administrator' => 12 ), 42, $check_file );
} catch ( Throwable $error ) {
	$summary['fatal'] = $error->getMessage();
}
$evidence->save( $summary );
clearstatcache();
echo json_encode( array(
	'summary' => json_decode( file_get_contents( $root . '/summary.json' ), true, 512, JSON_THROW_ON_ERROR ),
	'posts' => array_keys( $posts ), 'meta' => $meta, 'cron' => $cron,
	'terms' => $terms, 'term_meta' => $term_meta, 'users' => array_keys( $users ), 'credentials' => $credentials,
	'deleted_users' => $deleted_users, 'deleted_attachments' => $deleted_attachments,
	'directory' => is_dir( $directory ), 'marker_intact' => is_file( $marker ) && $marker_hash === hash_file( 'sha256', $marker ),
	'main_exists' => is_file( $files[0] ), 'main_intact' => is_file( $files[0] ) && $file_hashes[ $files[0] ] === hash_file( 'sha256', $files[0] ),
	'orphan_intact' => is_file( $files[1] ) && $file_hashes[ $files[1] ] === hash_file( 'sha256', $files[1] ),
	'other_intact' => is_file( $files[2] ) && $file_hashes[ $files[2] ] === hash_file( 'sha256', $files[2] ),
	'foreign_intact' => is_file( $foreign ) && $foreign_hash === hash_file( 'sha256', $foreign ),
	'transport_closed' => $transport->closed, 'control' => $control ?? false, 'current_user' => $current_user ?? null,
), JSON_THROW_ON_ERROR );
