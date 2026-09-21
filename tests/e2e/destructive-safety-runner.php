<?php
/**
 * CLI-only, opt-in proof against real WordPress, including actual MCP HTTP.
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM116_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Set WSTM116_DISPOSABLE=1 only for an owned disposable runtime.' );
}
$boundary = getenv( 'WSTM116_BOUNDARY' ) ?: 'ability';
if ( ! in_array( $boundary, array( 'direct', 'ability', 'http', 'individual' ), true ) ) {
	throw new RuntimeException( 'Unknown destructive-safety boundary.' );
}
$artifact = getenv( 'WSTM116_ARTIFACT' );
if ( ! is_string( $artifact ) || '' === $artifact || file_exists( $artifact ) || ! is_writable( dirname( $artifact ) ) ) {
	throw new RuntimeException( 'WSTM116_ARTIFACT must name a new file in an existing writable artifact directory.' );
}
require_once __DIR__ . '/destructive-safety-evidence.php';
$evidence = new Wstm116_Evidence( $artifact );
$completed = false;
$summary = array( 'boundary' => $boundary, 'source_sha' => getenv( 'WSTM116_SOURCE_SHA' ), 'failed' => 1, 'phase' => 'bootstrap' );
register_shutdown_function( static function () use ( $evidence, &$summary, &$completed ): void {
	if ( ! $completed ) {
		$summary['completed'] = false;
		$summary['fatal_shutdown'] = error_get_last();
		$evidence->save( $summary );
	}
} );
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
require_once __DIR__ . '/destructive-safety-fixture.php';
require_once __DIR__ . '/error-contract-assertions.php';
require_once __DIR__ . '/metadata-transport.php';

wstm116_require( defined( 'WSTM116_DISPOSABLE_RUNTIME' ) && true === WSTM116_DISPOSABLE_RUNTIME, 'Runtime must explicitly define WSTM116_DISPOSABLE_RUNTIME=true before fixture writes or credentials.' );
$expected_days = getenv( 'WSTM116_EXPECT_TRASH_DAYS' );
$stage_token = getenv( 'WSTM116_STAGE_TOKEN' );
wstm116_require( in_array( $expected_days, array( '0', '30' ), true ) && (int) $expected_days === EMPTY_TRASH_DAYS, 'Actual CLI trash configuration mismatch.' );
wstm116_require( defined( 'WSTM116_STAGE_TOKEN' ) && is_string( $stage_token ) && hash_equals( WSTM116_STAGE_TOKEN, $stage_token ), 'CLI stage ownership mismatch.' );
$boot = wp_remote_get( 'http://localhost/wp-json/wstm116/runtime', array( 'headers' => array( 'X-WSTM116-Stage' => $stage_token ) ) );
wstm116_require( ! is_wp_error( $boot ) && 200 === wp_remote_retrieve_response_code( $boot ), 'Cannot attest actual HTTP boot before credentials.' );
$http_boot = json_decode( wp_remote_retrieve_body( $boot ), true, 512, JSON_THROW_ON_ERROR );
wstm116_require( array( 'owner' => $stage_token, 'trash_days' => EMPTY_TRASH_DAYS, 'disposable' => true ) === $http_boot, 'Actual HTTP and CLI safety configuration differ.' );
wstm116_require( false === get_option( 'wstm116_control', false ), 'Refusing an existing destructive-safety control option.' );
foreach ( array_merge( range( 10000001, 10000101 ), array( 2147483647 ) ) as $missing_id ) {
	wstm116_require( null === get_post( $missing_id ), 'Missing-ID fixture collides with an existing post.' );
}
$summary = array(
	'boundary' => $boundary, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'source_sha' => getenv( 'WSTM116_SOURCE_SHA' ) ?: 'not supplied',
	'trash_days' => EMPTY_TRASH_DAYS, 'http_boot' => $http_boot,
	'passed' => 0, 'failed' => 0, 'cases' => array(), 'cleanup' => array(),
);
foreach ( array( __FILE__, __DIR__ . '/destructive-safety-fixture.php', __DIR__ . '/destructive-safety-http-fixture.php',
	__DIR__ . '/destructive-safety-evidence.php', __DIR__ . '/destructive-safety-lifecycle.php', __DIR__ . '/destructive-safety-stage.php',
	__DIR__ . '/destructive-safety-preflight.php', __DIR__ . '/metadata-transport.php', __DIR__ . '/metadata-batch-fixture.php',
	__DIR__ . '/error-contract-assertions.php', __DIR__ . '/error-contract-fixture.php', __DIR__ . '/abilities-manifest.json',
	__DIR__ . '/../../includes/class-posts.php', __DIR__ . '/../../includes/class-media.php',
	__DIR__ . '/../../includes/class-taxonomy.php', __DIR__ . '/../../includes/class-content-hygiene.php' ) as $source ) {
	$summary['hashes'][ basename( $source ) ] = hash_file( 'sha256', $source );
}
$run = 'wstm116-' . wp_generate_uuid4();
$users = $posts = $terms = $files = $transports = array();
$old_user = get_current_user_id();
$owns_control = false;
$record = static function ( string $label, callable $test ) use ( &$summary, $evidence ): void {
	$entry = array( 'label' => $label );
	try {
		$test( $entry );
		$entry['passed'] = true;
		$summary['passed']++;
		echo "PASS {$label}\n";
	} catch ( Throwable $error ) {
		$entry['passed'] = false;
		$entry['error'] = $error->getMessage();
		$summary['failed']++;
		echo "FAIL {$label}: {$error->getMessage()}\n";
	}
	$summary['cases'][] = $entry;
	$evidence->append( $entry );
	$evidence->save( $summary );
};
try {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$owns_control = add_option( 'wstm116_control', array( 'owner' => $run, 'active' => false ), '', false );
	wstm116_require( $owns_control, 'Control option collision.' );
	foreach ( array( 'administrator', 'editor', 'author', 'subscriber' ) as $role ) {
		$login = $run . '-' . $role;
		wstm116_require( ! username_exists( $login ) && ! email_exists( $login . '@example.test' ), 'User collision.' );
		$id = wp_create_user( $login, wp_generate_password( 40 ), $login . '@example.test' );
		wstm116_require( ! is_wp_error( $id ), 'Cannot create proof actor.' );
		$users[ $role ] = $id;
		( new WP_User( $id ) )->set_role( $role );
		if ( in_array( $boundary, array( 'http', 'individual' ), true ) ) {
			$password = WP_Application_Passwords::create_new_application_password( $id, array( 'name' => $run ) );
			wstm116_require( ! is_wp_error( $password ), 'Cannot create owned HTTP credential.' );
			$transports[ $role ] = new Wstm110_Metadata_Transport( 'individual' === $boundary, array( 'login' => $login, 'password' => $password[0] ) );
			$transports[ $role ]->initialize();
			$summary['catalogs'][ $role ] = $transports[ $role ]->catalog();
			unset( $password );
		}
	}
	wp_set_current_user( $users['administrator'] );
	$make_post = static function ( string $type = 'post', string $status = 'draft' ) use ( &$posts, $users, $run ): int {
		$input = array( 'post_type' => $type, 'post_title' => $run, 'post_content' => 'Owned safety fixture.', 'post_author' => $users['author'], 'post_status' => $status );
		if ( 'future' === $status ) {
			$input['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			$input['post_date_gmt'] = $input['post_date'];
		}
		$id = wp_insert_post( $input, true );
		wstm116_require( ! is_wp_error( $id ) && $id > 0, 'Cannot seed post.' );
		$posts[] = $id;
		update_post_meta( $id, 'wstm116_sentinel', $run );
		return $id;
	};
	$make_media = static function () use ( &$posts, &$files, $users, $run ): int {
		$name = $run . '-' . wp_generate_uuid4() . '.png';
		$uploads = wp_upload_dir();
		wstm116_require( ! $uploads['error'] && ! file_exists( $uploads['path'] . '/' . $name ), 'Upload path collision/error.' );
		$upload = wp_upload_bits( $name, null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0ioAAAAASUVORK5CYII=' ) );
		wstm116_require( ! $upload['error'], 'Cannot create owned media file.' );
		$files[] = $upload['file'];
		$id = wp_insert_attachment( array( 'post_title' => $run, 'post_mime_type' => 'image/png', 'post_author' => $users['author'], 'post_status' => 'inherit', 'guid' => 'https://old.example.test/' . $name ), $upload['file'], 0, true );
		wstm116_require( ! is_wp_error( $id ) && $id > 0, 'Cannot seed attachment.' );
		$posts[] = $id;
		update_post_meta( $id, 'wstm116_sentinel', $run );
		return $id;
	};
	$draft = $make_post();
	$page = $make_post( 'page' );
	$scheduled = $make_post( 'post', 'future' );
	$media = $make_media();
	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		wstm116_require( ! term_exists( $run, $taxonomy ), 'Term collision.' );
		$term = wp_insert_term( $run, $taxonomy );
		wstm116_require( ! is_wp_error( $term ), 'Cannot seed term.' );
		$terms[ $taxonomy ] = $term['term_id'];
		wp_set_object_terms( $draft, array( $term['term_id'] ), $taxonomy );
		add_term_meta( $term['term_id'], 'wstm116_sentinel', $run );
	}
	$invoke = static function ( string $slug, array $input, string $role, array $fault, array &$entry, bool $unchanged ) use ( $boundary, $run, $users, &$files, $transports, $evidence ): array {
		wp_set_current_user( $users[ $role ] );
		$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $slug );
		wstm116_require( null !== $ability, 'Missing owned ability.' );
		$entry['input'] = $input;
		$entry['role'] = $role;
		$entry['mode'] = $fault;
		$events = array();
		$undo = wstm116_faults( $fault );
		try {
			$permission = $ability->check_permissions( $input );
			$entry['permission'] = true === $permission ? true : Webmastery_MCP_Response::from_wp_error( $permission );
		} finally {
			$undo();
		}
		$before = wstm116_snapshot( $files );
		$entry['before'] = $before;
		if ( isset( $transports[ $role ] ) ) {
			$control = get_option( 'wstm116_control' );
			wstm116_require( $run === ( $control['owner'] ?? null ), 'Lost control option ownership.' );
			$nonce = wp_generate_uuid4();
			update_option( 'wstm116_control', array_merge( $fault, array( 'owner' => $run, 'active' => true, 'user_id' => $users[ $role ], 'nonce' => $nonce ) ), false );
			try {
				$result = $transports[ $role ]->execute( $ability->get_name(), $input );
			} finally {
				$entry['wire'] = $transports[ $role ]->last_response;
				$evidence->append( array( 'phase' => 'wire', 'ability' => $slug, 'role' => $role, 'input' => $input, 'wire' => $entry['wire'] ) );
				wp_cache_flush();
			}
			if ( true === ( $result['success'] ?? false ) && isset( $result['data']['failures'] ) ) {
				$wire = $entry['wire']['result'];
				$payload = isset( $wire['structuredContent'] )
					? json_decode( wp_json_encode( $wire['structuredContent'] ) )
					: json_decode( $wire['content'][0]['text'], false, 512, JSON_THROW_ON_ERROR );
				if ( 'http' === $boundary ) {
					$payload = $payload->data;
				}
				foreach ( $result['data']['failures'] as $index => &$failure ) {
					$details = $payload->data->failures[ $index ]->details ?? null;
					wstm116_require( $details instanceof stdClass, 'Wire per-ID details are not an object.' );
					$failure['details'] = $details;
				}
				unset( $failure );
			}
			wp_cache_delete( 'wstm116_control', 'options' );
			$control = get_option( 'wstm116_control' );
			wstm116_require( $nonce === ( $control['observed'] ?? null ), 'HTTP mutation observer did not attest this invocation.' );
			wstm116_require( EMPTY_TRASH_DAYS === ( $control['trash_days'] ?? null ) && WSTM116_STAGE_TOKEN === ( $control['stage_owner'] ?? null ), 'HTTP boot changed during safety proof.' );
			$events = $control['events'];
		} else {
			$observer = wstm116_observe( static function ( $hook ) use ( &$events ): void { $events[] = $hook; } );
			$undo = wstm116_faults( $fault );
			try {
				if ( 'direct' === $boundary ) {
					$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
					$property->setAccessible( true );
					$result = ( $property->getValue( $ability ) )( $input );
				} else {
					$result = $ability->execute( $input );
				}
				if ( is_wp_error( $result ) ) {
					$result = Webmastery_MCP_Response::from_wp_error( $result );
				}
			} finally {
				$undo();
				wstm116_unobserve( $observer );
			}
		}
		$entry['result'] = $result;
		$entry['before'] = $before;
		$entry['after'] = wstm116_snapshot( $files );
		$entry['hooks'] = $events;
		$evidence->append( array( 'phase' => 'invocation', 'ability' => $slug, 'evidence' => $entry ) );
		if ( $unchanged ) {
			wstm116_require( $entry['before'] === $entry['after'], 'Guard/preview changed persisted state or owned files.' );
			wstm116_require( array() === $events, 'Guard/preview reached a mutation hook.' );
		}
		return $result;
	};
	$inputs = array(
		'bulk-trash-posts' => array( 'ids' => array( $draft ) ),
		'bulk-publish-posts' => array( 'ids' => array( $draft ) ),
		'delete-media' => array( 'media_id' => $media ),
		'delete-category' => array( 'category_id' => $terms['category'] ),
		'delete-tag' => array( 'tag_id' => $terms['post_tag'] ),
	);
	$error_case = static function ( $label, $slug, $input, $role, $reason, $permission = true, $fault = array() ) use ( $record, $invoke ): void {
		$record( $label, static function ( &$entry ) use ( $slug, $input, $role, $reason, $permission, $fault, $invoke ): void {
			$result = $invoke( $slug, $input, $role, $fault, $entry, true );
			$envelope = wstm118_error_envelope( $result );
			wstm116_require( $reason === $envelope['error']['reason'], 'Wrong failure layer/reason: ' . $envelope['error']['reason'] );
			wstm116_require( $permission === ( true === $entry['permission'] ), 'Permission callback result did not match the expected independent boundary.' );
			wstm116_require( false === strpos( wp_json_encode( $envelope ), 'WSTM116_PRIVATE_SQL_FAILURE' ), 'Private SQL escaped.' );
			if ( 'too_many_ids' === $reason ) {
				wstm116_require( (object) array( 'limit' => 100 ) == $envelope['error']['details'], 'Missing raw ID limit details.' );
			}
		} );
	};
	foreach ( $inputs as $slug => $base ) {
		foreach ( array( 'missing' => array(), 'false' => array( 'confirm' => false ), 'string' => array( 'confirm' => 'true' ), 'number' => array( 'confirm' => 1 ), 'null' => array( 'confirm' => null ) ) as $label => $flags ) {
			$error_case( "{$slug} confirmation {$label}", $slug, array_merge( $base, $flags ), 'editor', 'direct' === $boundary ? 'missing_confirmation' : 'ability_invalid_input' );
			$error_case( "{$slug} administrator confirmation {$label}", $slug, array_merge( $base, $flags ), 'administrator', 'direct' === $boundary ? 'missing_confirmation' : 'ability_invalid_input' );
			if ( 0 === strpos( $slug, 'bulk-' ) ) {
				$error_case( "{$slug} preview confirmation {$label}", $slug, array_merge( $base, $flags, array( 'dry_run' => true ) ), 'editor', 'direct' === $boundary ? 'missing_confirmation' : 'ability_invalid_input' );
			}
		}
		if ( 'direct' === $boundary && 0 === strpos( $slug, 'bulk-' ) ) {
			$record( "{$slug} direct subscriber inline denial", static function ( &$entry ) use ( $invoke, $slug, $base ): void {
				$result = $invoke( $slug, $base + array( 'confirm' => true, 'dry_run' => true ), 'subscriber', array(), $entry, true );
				wstm116_require( true !== $entry['permission'], 'Subscriber unexpectedly passed the permission callback.' );
				wstm116_require( true === $result['success'] && 0 === $result['data']['success_count'] && 'forbidden' === $result['data']['failures'][0]['reason'], 'Direct per-item denial was bypassed.' );
			} );
		} else {
			$error_case( "{$slug} subscriber permission", $slug, $base + array( 'confirm' => true ), 'subscriber', 'ability' === $boundary ? 'ability_invalid_permissions' : 'forbidden', false );
		}
	}
	foreach ( array( 'bulk-trash-posts' => 'dry_run', 'bulk-publish-posts' => 'dry_run', 'delete-media' => 'force' ) as $slug => $flag ) {
		foreach ( array( 'true', 'false', 0, 1, null, array() ) as $index => $value ) {
			$error_case( "{$slug} strict {$flag} {$index}", $slug, $inputs[ $slug ] + array( 'confirm' => true, $flag => $value ), 'editor', 'direct' === $boundary ? 'invalid_input' : 'ability_invalid_input' );
		}
	}
	foreach ( array( 'bulk-trash-posts', 'bulk-publish-posts' ) as $slug ) {
		foreach ( array( range( 10000001, 10000101 ), array_fill( 0, 101, $draft ) ) as $index => $ids ) {
			foreach ( array( false, true ) as $dry_run ) {
				$error_case( "{$slug} 101 raw {$index} preview " . (int) $dry_run, $slug, array( 'ids' => $ids, 'confirm' => true, 'dry_run' => $dry_run ), 'editor', 'direct' === $boundary ? 'too_many_ids' : 'ability_invalid_input' );
			}
		}
		foreach ( array( 'unique' => range( 10000001, 10000100 ), 'duplicate' => array_fill( 0, 100, $draft ) ) as $label => $ids ) {
			$record( "{$slug} accepts 100 {$label}", static function ( &$entry ) use ( $invoke, $slug, $ids ): void {
				$result = $invoke( $slug, array( 'ids' => $ids, 'confirm' => true, 'dry_run' => true ), 'editor', array(), $entry, true );
				wstm116_require( true === $result['success'] && true === $result['data']['dry_run'], '100 raw IDs were not accepted.' );
			} );
		}
		$record( "{$slug} object permission preview", static function ( &$entry ) use ( $invoke, $slug, $draft ): void {
			$result = $invoke( $slug, array( 'ids' => array( $draft ), 'confirm' => true, 'dry_run' => true ), 'editor', array( 'mode' => 'deny_object', 'id' => $draft ), $entry, true );
			wstm116_require( true === $entry['permission'], 'Top-level permission must pass for execute-denial proof.' );
			wstm116_require( true === $result['success'] && 0 === $result['data']['success_count'] && 'forbidden' === $result['data']['failures'][0]['reason'], 'Per-object permission was bypassed.' );
		} );
		foreach ( array( 'all failed', 'mixed' ) as $variant ) {
			wp_set_current_user( $users['administrator'] );
			$id = $make_post();
			$trashed = $make_post( 'post', 'trash' );
			$ids = 'all failed' === $variant ? array( $page, $trashed, 2147483647 ) : array( $id, $id, $page, $trashed, 2147483647 );
			$record( "{$slug} {$variant} preview equals execution", static function ( &$entry ) use ( $invoke, $slug, $ids, $variant ): void {
				$preview = $invoke( $slug, array( 'ids' => $ids, 'confirm' => true, 'dry_run' => true ), 'editor', array(), $entry, true );
				$entry['preview'] = $preview;
				$entry['preview_evidence'] = array( 'before' => $entry['before'], 'after' => $entry['after'], 'hooks' => $entry['hooks'] );
				wstm116_require( true === $preview['success'] && true === $preview['data']['dry_run'], 'Invalid preview summary.' );
				foreach ( $preview['data']['failures'] as $failure ) {
					wstm116_require( array( 'id', 'code', 'reason', 'message', 'details' ) === array_keys( $failure ), 'Invalid per-ID failure fields.' );
					wstm116_require( '{}' === wp_json_encode( $failure['details'] ), 'Per-ID details must be an empty object.' );
				}
				$expected = 'mixed' === $variant && ( 'bulk-publish-posts' === $slug || ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS ) ? 1 : 0;
				wstm116_require( $expected === $preview['data']['success_count'], 'Incorrect would-act count.' );
				$actual = $invoke( $slug, array( 'ids' => $ids, 'confirm' => true, 'dry_run' => false ), 'editor', array(), $entry, 0 === $expected );
				unset( $preview['data']['dry_run'] );
				wstm116_require( wp_json_encode( $preview ) === wp_json_encode( $actual ), 'Would-act results differ from the real run.' );
			} );
		}
	}
	foreach ( array( 'featured', 'url', 'guid', 'unused' ) as $reference ) {
		wp_set_current_user( $users['administrator'] );
		$id = $make_media();
		$referrer = $make_post();
		if ( 'featured' === $reference ) {
			update_post_meta( $referrer, '_thumbnail_id', $id );
		} elseif ( 'unused' !== $reference ) {
			wp_update_post( array( 'ID' => $referrer, 'post_content' => 'Reference: ' . ( 'url' === $reference ? wp_get_attachment_url( $id ) : get_post( $id )->guid ) ) );
		}
		$known = 'unused' !== $reference;
		if ( $known ) {
			$error_case( "media {$reference} refuses known reference", 'delete-media', array( 'media_id' => $id, 'confirm' => true ), 'author', 'media_in_use' );
		}
		$error_case( "media {$reference} force cannot bypass object denial", 'delete-media', array( 'media_id' => $id, 'confirm' => true, 'force' => true ), 'author', 'ability' === $boundary ? 'ability_invalid_permissions' : 'forbidden', false, array( 'mode' => 'deny_object', 'id' => $id ) );
		foreach ( array( false, true ) as $force ) {
			$error_case( "media {$reference} scan failure force " . (int) $force, 'delete-media', array( 'media_id' => $id, 'confirm' => true, 'force' => $force ), 'author', 'content_hygiene_query_failed', true, array( 'mode' => 'query_failure' ) );
		}
		$record( "media {$reference} truthful deletion", static function ( &$entry ) use ( $invoke, $id, $known ): void {
			$file = get_attached_file( $id );
			$result = $invoke( 'delete-media', array( 'media_id' => $id, 'confirm' => true, 'force' => $known ), 'author', array(), $entry, false );
			wstm116_require( array( 'success' => true, 'data' => array( 'id' => $id, 'deleted' => true, 'in_use' => $known ) ) === $result, 'Incorrect in_use/deleted result.' );
			wstm116_require( null === get_post( $id ) && ! file_exists( $file ), 'Attachment or file remains after reported deletion.' );
		} );
	}
	foreach ( array( 'category' => 'category', 'post_tag' => 'tag' ) as $taxonomy => $slug ) {
		$id = $terms[ $taxonomy ];
		$error_case( "delete {$slug} final term denial", 'delete-' . $slug, array( $slug . '_id' => $id, 'confirm' => true ), 'editor', 'ability' === $boundary ? 'ability_invalid_permissions' : 'forbidden', false, array( 'mode' => 'deny_object', 'id' => $id ) );
		$record( "delete {$slug} confirmed success", static function ( &$entry ) use ( $invoke, $slug, $taxonomy, $id ): void {
			$result = $invoke( 'delete-' . $slug, array( $slug . '_id' => $id, 'confirm' => true ), 'editor', array(), $entry, false );
			wstm116_require( true === $result['success'] && true === $result['data']['deleted'] && null === get_term( $id, $taxonomy ), 'Term deletion did not persist.' );
		} );
	}
} catch ( Throwable $error ) {
	$summary['failed']++;
	$summary['fatal'] = $error->getMessage();
	echo 'FAIL runtime: ' . $error->getMessage() . "\n";
} finally {
	$cleanup = static function ( string $label, callable $action ) use ( &$summary ): void {
		try {
			$action();
			$summary['cleanup'][ $label ] = true;
		} catch ( Throwable $error ) {
			$summary['failed']++;
			$summary['cleanup'][ $label ] = $error->getMessage();
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
	foreach ( array_reverse( $posts ) as $id ) {
		$cleanup( "post {$id}", static function () use ( $id ): void {
			if ( get_post( $id ) ) {
				'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
			}
			wstm116_require( null === get_post( $id ) && array() === get_post_meta( $id ) && false === wp_next_scheduled( 'publish_future_post', array( $id ) ), 'Owned post/meta/scheduled event remains.' );
		} );
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
		$cleanup( 'file ' . basename( $file ), static function () use ( $file ): void {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
			wstm116_require( ! file_exists( $file ), 'Owned upload remains.' );
		} );
	}
	foreach ( $users as $role => $id ) {
		$cleanup( "actor {$role}", static function () use ( $id ): void {
			WP_Application_Passwords::delete_all_application_passwords( $id );
			wp_delete_user( $id );
			wstm116_require( false === get_userdata( $id ) && array() === WP_Application_Passwords::get_user_application_passwords( $id ), 'Owned actor or credentials remain.' );
		} );
	}
	wp_set_current_user( $old_user );
	$summary['completed'] = true;
	$evidence->save( $summary );
	$completed = true;
}
echo 'SUMMARY ' . $summary['passed'] . ' passed, ' . $summary['failed'] . " failed\n";
exit( $summary['failed'] ? 1 : 0 );
