<?php

declare(strict_types=1);

/** Keep the live, independently recorded upload ledger authoritative. */
function wstm116_cleanup_file_guard( ?Wstm116_Uploads &$owned_uploads, ?array &$owned_configuration, array &$files, array &$file_hashes, array &$file_identities ): Closure {
	return static function ( string $file ) use ( &$owned_uploads, &$owned_configuration, &$files, &$file_hashes, &$file_identities ): void {
		wstm116_require( null !== $owned_uploads, 'No owned upload directory.' );
		$owned_uploads->validate_known_file( $file, true );
		wstm116_require( in_array( $file, $files, true ) && ! is_link( $file ) && realpath( dirname( $file ) ) === $owned_configuration['path'], 'Untracked, outside-directory or symlinked upload; retaining evidence.' );
		if ( file_exists( $file ) ) {
			$identity = lstat( $file );
			wstm116_require( is_array( $identity ) && is_file( $file ) && is_string( $file_hashes[ $file ] )
				&& hash_file( 'sha256', $file ) === $file_hashes[ $file ]
				&& array_intersect_key( $identity, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode' ) ) ) === $file_identities[ $file ], 'Owned upload identity or contents changed; retaining evidence.' );
		}
	};
}

/**
 * The runner's complete cleanup flow, also exercised with local fake WP APIs.
 * A refused attachment retains the entire upload ledger and ownership proof.
 */
function wstm116_cleanup( array &$summary, array $transports, bool $owns_control, string $run, array $posts, array $terms, array $files, ?Wstm116_Uploads $owned_uploads, array $users, int $old_user, callable $check_file ): void {
	$summary['cleanup_complete'] = false;
	$failures_before = $summary['failed'];
	$cleanup = static function ( string $label, callable $action ) use ( &$summary ): bool {
		try {
			$action();
			$summary['cleanup'][ $label ] = true;
			return true;
		} catch ( Throwable $error ) {
			$summary['failed']++;
			$summary['cleanup'][ $label ] = $error->getMessage();
			return false;
		}
	};
	foreach ( $transports as $role => $transport ) {
		$cleanup( "HTTP {$role}", static function () use ( $transport ): void { $transport->close(); } );
	}
	if ( $owns_control ) {
		$cleanup( 'control option', static function () use ( $run ): void {
			wp_cache_delete( 'wstm116_control', 'options' );
			wstm116_require( $run === ( get_option( 'wstm116_control' )['owner'] ?? null ), 'Refusing to delete another owner control option.' );
			delete_option( 'wstm116_control' );
			wstm116_require( false === get_option( 'wstm116_control', false ), 'Control option remains.' );
		} );
	}
	$check_attachment = static function ( int $id ) use ( $check_file ): void {
		$check_file( get_attached_file( $id ) );
		wstm116_require( in_array( wp_get_attachment_metadata( $id ), array( false, array() ), true )
			&& in_array( get_post_meta( $id, '_wp_attachment_backup_sizes', true ), array( '', array() ), true ),
			'Unexpected derivative attachment references; refusing untracked file deletion.' );
	};
	$retain_uploads = false;
	// Validate all references before deleting any attachment: a foreign reference
	// may point at another tracked file, not only at this attachment's main file.
	foreach ( $posts as $id ) {
		if ( ! $cleanup( "attachment references {$id}", static function () use ( $id, $check_attachment ): void {
			if ( get_post( $id ) && 'attachment' === get_post_type( $id ) ) {
				$check_attachment( $id );
			}
		} ) ) {
			$retain_uploads = true;
		}
	}
	foreach ( array_reverse( $posts ) as $id ) {
		if ( ! $cleanup( "post {$id}", static function () use ( $id, $check_attachment, $retain_uploads ): void {
			if ( get_post( $id ) ) {
				if ( 'attachment' === get_post_type( $id ) ) {
					wstm116_require( ! $retain_uploads, 'Attachment cleanup is unproven; retaining uploads and ownership proof.' );
					$check_attachment( $id );
				}
				'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
			}
			wstm116_require( null === get_post( $id ) && array() === get_post_meta( $id ) && false === wp_next_scheduled( 'publish_future_post', array( $id ) ), 'Owned post/meta/scheduled event remains.' );
		} ) ) {
			$retain_uploads = true;
		}
	}
	foreach ( $terms as $taxonomy => $id ) {
		$cleanup( "term {$id}", static function () use ( $taxonomy, $id ): void {
			if ( term_exists( $id, $taxonomy ) ) {
				wp_delete_term( $id, $taxonomy );
			}
			wstm116_require( ! term_exists( $id, $taxonomy ) && array() === get_term_meta( $id ), 'Owned term/meta remains.' );
		} );
	}
	foreach ( $files as $file ) {
		$cleanup( 'file ' . basename( $file ), static function () use ( $file, $check_file, $retain_uploads ): void {
			wstm116_require( ! $retain_uploads, 'Attachment cleanup is unproven; retaining uploads and ownership proof.' );
			$check_file( $file );
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
			wstm116_require( ! file_exists( $file ), 'Owned upload remains.' );
		} );
	}
	if ( null !== $owned_uploads ) {
		$cleanup( 'owned upload directory', static function () use ( $owned_uploads, $retain_uploads ): void {
			wstm116_require( ! $retain_uploads, 'Attachment cleanup is unproven; retaining uploads and ownership proof.' );
			$owned_uploads->finalize();
		} );
	}
	foreach ( $users as $role => $id ) {
		$cleanup( "actor {$role}", static function () use ( $id, $posts ): void {
			WP_Application_Passwords::delete_all_application_passwords( $id );
			foreach ( $posts as $post_id ) {
				$post = get_post( $post_id );
				wstm116_require( null === $post || (int) $post->post_author !== $id, 'Actor still owns a retained post; refusing implicit file deletion.' );
			}
			wp_delete_user( $id );
			wstm116_require( false === get_userdata( $id ) && array() === WP_Application_Passwords::get_user_application_passwords( $id ), 'Owned actor or credentials remain.' );
		} );
	}
	wp_set_current_user( $old_user );
	$summary['completed'] = true;
	$summary['cleanup_complete'] = $failures_before === $summary['failed']
		&& array() === array_filter( $summary['cleanup'], static function ( $result ): bool { return true !== $result; } );
}
