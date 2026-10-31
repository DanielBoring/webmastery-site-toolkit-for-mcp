<?php

// Called only by the owned disposable contract runner, after its role bootstrap.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

function wstm206_run_featured_image_tests(): array {
	global $wpdb, $wp_version;
	$summary = array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'passed' => 0, 'failed' => 0, 'cases' => array() );
	$original_user = get_current_user_id();
	$owned_posts = $owned_media = $owned_files = array();
	$require = static function ( $condition, $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	};
	$record = static function ( $label, callable $run ) use ( &$summary ): void {
		$case = array( 'label' => $label, 'passed' => false );
		try {
			$case['evidence'] = $run();
			$case['passed'] = true;
		} catch ( Throwable $error ) {
			$case['error'] = $error->getMessage();
		}
		$summary[ $case['passed'] ? 'passed' : 'failed' ]++;
		$summary['cases'][] = $case;
		echo ( $case['passed'] ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	};
	$stored_id = static function ( $id ) use ( $wpdb ): int {
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_thumbnail_id' ORDER BY meta_id LIMIT 1", $id ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Cannot read persisted thumbnail state.' );
		}
		return (int) $value;
	};
	$invoke = static function ( $slug, $input ) {
		return wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $slug . '-featured-image' )->execute( $input );
	};
	try {
		$admin = get_user_by( 'login', 'admin' );
		$editor = get_user_by( 'login', 'editor_test' );
		$subscriber = get_user_by( 'login', 'subscriber_test' );
		$author = get_user_by( 'login', 'author_test' );
		$require( $admin && $editor && $subscriber && $author, 'Featured-image regressions require the owned contract role bootstrap.' );
		wp_set_current_user( $admin->ID );
		$body = str_repeat( 'Long tutorial body. ', 4000 );
		foreach ( array( 'post', 'page' ) as $type ) {
			$owned_posts[ $type ] = e2e_insert_post( $type, 'WSTM206 ' . wp_generate_uuid4(), $body, $editor->ID );
		}
		foreach ( array( 'first' => 'image/png', 'second' => 'image/png', 'text' => 'text/plain' ) as $key => $mime ) {
			$owned_media[ $key ] = e2e_insert_media( $owned_posts['post'], $admin->ID, 'wstm206-' . wp_generate_uuid4(), $mime );
			$owned_files[] = get_attached_file( $owned_media[ $key ] );
		}
		wp_set_current_user( $editor->ID );
		foreach ( $owned_posts as $type => $id ) {
			foreach ( array( 'default', 'full' ) as $format ) {
				$record( "featured image {$type} {$format} preserves full post", static function () use ( $require, $invoke, $stored_id, $id, $owned_media, $body, $format ) {
					delete_post_thumbnail( $id );
					foreach ( array( 'set', 'remove' ) as $slug ) {
						$input = array( 'post_id' => $id ) + ( 'set' === $slug ? array( 'attachment_id' => $owned_media['first'] ) : array() );
						if ( 'full' === $format ) { $input['response_format'] = 'full'; }
						$result = $invoke( $slug, $input );
						$require( true === ( $result['success'] ?? null ) && $id === $result['data']['id'] && $body === $result['data']['content'], 'Full response lost the unchanged post content.' );
						$require( $stored_id( $id ) === $result['data']['featured_image_id'], 'Full response differs from persisted image.' );
						$require( ! isset( $result['data']['previous_featured_image_id'] ), 'Legacy full response gained compact fields.' );
					}
					return array( 'post_id' => $id, 'content_bytes' => strlen( $body ), 'projection' => $format );
				} );
			}
			foreach ( array( array( 'set', 'first', 0 ), array( 'set', 'second', 'first' ), array( 'remove', null, 'second' ), array( 'remove', null, 0 ) ) as $index => $scenario ) {
				$record( "featured image {$type} compact transition {$index}", static function () use ( $require, $invoke, $stored_id, $id, $owned_media, $scenario ) {
					list( $slug, $image, $previous ) = $scenario;
					$previous = $previous ? $owned_media[ $previous ] : 0;
					$require( $previous === $stored_id( $id ), 'Unexpected initial thumbnail state.' );
					$current = $image ? $owned_media[ $image ] : 0;
					$normalizations = 0;
					$observe = static function ( $name ) use ( &$normalizations ) { ++$normalizations; return $name; };
					add_filter( 'get_the_author_display_name', $observe );
					try {
						$result = $invoke( $slug, array( 'post_id' => $id, 'response_format' => 'compact' ) + ( $image ? array( 'attachment_id' => $current ) : array() ) );
					} finally {
						remove_filter( 'get_the_author_display_name', $observe );
					}
					$expected = array( 'success' => true, 'data' => array( 'post_id' => $id, 'featured_image_id' => $current,
						'featured_image_url' => $current ? wp_get_attachment_url( $current ) : null,
						'previous_featured_image_id' => $previous, 'untrusted_fields' => array( 'featured_image_url' ) ) );
					$require( $expected === $result && $current === $stored_id( $id ), 'Compact response differs from persisted transition.' );
					$require( 0 === $normalizations, 'Compact path entered full post normalization.' );
					return $result;
				} );
			}
			$record( "featured image {$type} compact same-image set retains failure", static function () use ( $require, $invoke, $stored_id, $id, $owned_media ) {
				set_post_thumbnail( $id, $owned_media['first'] );
				$result = $invoke( 'set', array( 'post_id' => $id, 'attachment_id' => $owned_media['first'], 'response_format' => 'compact' ) );
				$require( 'featured_image_failed' === wstm118_error_reason( $result ) && $owned_media['first'] === $stored_id( $id ), 'Repeated set changed legacy error/state.' );
				return $result;
			} );
			foreach ( array( 'set', 'remove' ) as $slug ) {
				$record( "featured image {$type} compact {$slug} denied", static function () use ( $require, $invoke, $stored_id, $id, $owned_media, $subscriber, $editor, $slug ) {
					$before = $stored_id( $id );
					$reads = 0;
					$observe = static function ( $value ) use ( &$reads ) { ++$reads; return $value; };
					wp_set_current_user( $subscriber->ID );
					add_filter( 'get_post_metadata', $observe );
					try {
						$result = $invoke( $slug, array( 'post_id' => $id, 'response_format' => 'compact' ) + ( 'set' === $slug ? array( 'attachment_id' => $owned_media['second'] ) : array() ) );
					} finally {
						remove_filter( 'get_post_metadata', $observe );
						wp_set_current_user( $editor->ID );
					}
					$require( 'forbidden' === wstm118_error_envelope( $result )['error']['code'] && 0 === $reads && $before === $stored_id( $id ), 'Denial read image metadata or mutated the target.' );
					return array( 'result' => wstm118_error_envelope( $result ), 'post_metadata_reads' => $reads, 'persisted_image' => $before );
				} );
			}
		}
		$id = $owned_posts['post'];
		foreach ( array( 'set', 'remove' ) as $slug ) {
			foreach ( array( null, false, 1, '', 'COMPACT', 'summary' ) as $index => $format ) {
				$record( "featured image {$slug} invalid response format {$index}", static function () use ( $require, $invoke, $stored_id, $wpdb, $id, $owned_media, $slug, $format ) {
					$before = $stored_id( $id );
					$caps = $reads = 0;
					$observe_caps = static function ( $value ) use ( &$caps ) { ++$caps; return $value; };
					$observe_reads = static function ( $value ) use ( &$reads ) { ++$reads; return $value; };
					add_filter( 'map_meta_cap', $observe_caps );
					add_filter( 'get_post_metadata', $observe_reads );
					try {
						$queries = $wpdb->num_queries;
						$result = $invoke( $slug, array( 'post_id' => $id, 'response_format' => $format ) + ( 'set' === $slug ? array( 'attachment_id' => $owned_media['second'] ) : array() ) );
						$queries = $wpdb->num_queries - $queries;
					} finally {
						remove_filter( 'map_meta_cap', $observe_caps );
						remove_filter( 'get_post_metadata', $observe_reads );
					}
					$require( 'ability_invalid_input' === wstm118_error_reason( $result ) && 0 === $caps && 0 === $reads && 0 === $queries && $before === $stored_id( $id ), 'Invalid projection entered authorization, storage, or write branches.' );
					return array( 'result' => wstm118_error_envelope( $result ), 'capability_calls' => $caps, 'metadata_reads' => $reads, 'queries' => $queries );
				} );
			}
		}
		$record( 'featured image compact preserves foreign-media URL privacy', static function () use ( $require, $invoke, $stored_id, $owned_media, $body, $author, $editor, &$owned_posts ) {
			$id = e2e_insert_post( 'post', 'WSTM206 author ' . wp_generate_uuid4(), $body, $author->ID, 'draft' );
			$owned_posts['author'] = $id;
			wp_set_current_user( $author->ID );
			try {
				$require( current_user_can( 'edit_post', $id ) && ! current_user_can( 'edit_post', $owned_media['second'] ), 'Foreign-media privacy fixture has the wrong capabilities.' );
				$result = $invoke( 'set', array( 'post_id' => $id, 'attachment_id' => $owned_media['second'], 'response_format' => 'compact' ) );
				$expected = array( 'success' => true, 'data' => array( 'post_id' => $id, 'featured_image_id' => $owned_media['second'],
					'featured_image_url' => null, 'previous_featured_image_id' => 0, 'untrusted_fields' => array( 'featured_image_url' ) ) );
				$require( $expected === $result && $owned_media['second'] === $stored_id( $id ), 'URL privacy changed assignment eligibility or exposed inaccessible media URL.' );
				return $result;
			} finally {
				wp_set_current_user( $editor->ID );
			}
		} );
		foreach ( array( $owned_media['text'] => 'unsupported_mime_type', $id => 'not_found', 2147483647 => 'not_found' ) as $attachment => $reason ) {
			$record( "featured image compact attachment {$reason} {$attachment}", static function () use ( $require, $invoke, $stored_id, $id, $attachment, $reason ) {
				$before = $stored_id( $id );
				$result = $invoke( 'set', array( 'post_id' => $id, 'attachment_id' => $attachment, 'response_format' => 'compact' ) );
				$require( $reason === wstm118_error_reason( $result ) && $before === $stored_id( $id ), 'Attachment rejection changed error or persisted image.' );
				return $result;
			} );
		}
	} catch ( Throwable $error ) {
		$summary['failed']++;
		$summary['fatal'] = $error->getMessage();
	} finally {
		wp_set_current_user( $original_user );
		foreach ( $owned_media as $id ) {
			wp_delete_attachment( $id, true );
			if ( get_post( $id ) ) { $summary['failed']++; $summary['cleanup_errors'][] = "Media {$id} remains."; }
		}
		foreach ( $owned_files as $file ) {
			if ( file_exists( $file ) ) { $summary['failed']++; $summary['cleanup_errors'][] = 'Owned upload remains.'; }
		}
		foreach ( $owned_posts as $id ) {
			wp_delete_post( $id, true );
			if ( get_post( $id ) ) { $summary['failed']++; $summary['cleanup_errors'][] = "Post {$id} remains."; }
		}
	}
	echo "FEATURED IMAGE SUMMARY {$summary['passed']} passed, {$summary['failed']} failed\n";
	return $summary;
}
