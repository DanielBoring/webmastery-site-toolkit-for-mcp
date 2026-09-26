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
$summary = array( 'boundary' => $boundary, 'source_sha' => getenv( 'WSTM116_SOURCE_SHA' ), 'failed' => 1, 'phase' => 'bootstrap', 'cleanup_complete' => false );
register_shutdown_function( static function () use ( $evidence, &$summary, &$completed ): void {
	if ( ! $completed ) {
		$summary['completed'] = false;
		$summary['cleanup_complete'] = false;
		$summary['fatal_shutdown'] = error_get_last();
		$evidence->save( $summary );
	}
} );
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
require_once __DIR__ . '/destructive-safety-fixture.php';
require_once __DIR__ . '/error-contract-assertions.php';
require_once __DIR__ . '/metadata-transport.php';
require_once __DIR__ . '/destructive-safety-wire.php';
require_once __DIR__ . '/destructive-safety-lifecycle.php';
require_once __DIR__ . '/destructive-safety-uploads.php';
require_once __DIR__ . '/destructive-safety-cleanup.php';

wstm116_require( defined( 'WSTM116_DISPOSABLE_RUNTIME' ) && true === WSTM116_DISPOSABLE_RUNTIME, 'Runtime must explicitly define WSTM116_DISPOSABLE_RUNTIME=true before fixture writes or credentials.' );
$expected_days = getenv( 'WSTM116_EXPECT_TRASH_DAYS' );
$stage_token = getenv( 'WSTM116_STAGE_TOKEN' );
wstm116_require( in_array( $expected_days, array( '0', '30' ), true ) && (int) $expected_days === EMPTY_TRASH_DAYS, 'Actual CLI trash configuration mismatch.' );
wstm116_require( defined( 'WSTM116_STAGE_TOKEN' ) && is_string( $stage_token ) && hash_equals( WSTM116_STAGE_TOKEN, $stage_token ), 'CLI stage ownership mismatch.' );
$summary['cli_boot'] = array( 'owner' => $stage_token, 'trash_days' => EMPTY_TRASH_DAYS, 'disposable' => WSTM116_DISPOSABLE_RUNTIME,
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
	'effective_uid' => function_exists( 'posix_geteuid' ) ? posix_geteuid() : null,
	'config_sha256' => hash_file( 'sha256', ABSPATH . 'wp-config.php' ) );
$lifecycle = new Wstm116_Lifecycle( '/var/www/html', dirname( __DIR__, 2 ), '/tmp/wstm116-stage', $stage_token );
$boot = wp_remote_get( 'http://localhost/wp-json/wstm116/boot', array( 'headers' => array( 'X-WSTM116-Stage' => $stage_token ), 'timeout' => 2, 'redirection' => 0 ) );
$summary['http_boot_attempt'] = is_wp_error( $boot )
	? array( 'error_code' => $boot->get_error_code(), 'error_message' => $boot->get_error_message() )
	: wstm116_boot_diagnostics( wp_remote_retrieve_response_code( $boot ), wp_remote_retrieve_body( $boot ) );
if ( ! is_wp_error( $boot ) && '' !== wp_remote_retrieve_header( $boot, 'x-wstm116-boot' ) ) {
	$diagnostics = base64_decode( wp_remote_retrieve_header( $boot, 'x-wstm116-boot' ), true );
	wstm116_require( false !== $diagnostics, 'Invalid HTTP boot diagnostics encoding.' );
	$summary['http_boot_attempt']['runtime'] = json_decode( $diagnostics, true, 512, JSON_THROW_ON_ERROR );
}
$evidence->append( array( 'phase' => 'boot', 'cli' => $summary['cli_boot'], 'http' => $summary['http_boot_attempt'] ) );
$evidence->save( $summary );
wstm116_require( ! is_wp_error( $boot ) && 200 === wp_remote_retrieve_response_code( $boot ), 'Cannot attest actual HTTP boot before credentials.' );
$http_attestation = json_decode( wp_remote_retrieve_body( $boot ), true, 512, JSON_THROW_ON_ERROR );
wstm116_validate_boot( $http_attestation, $lifecycle->identity() );
wstm116_require( wstm116_runtime_configuration() === $http_attestation['runtime'], 'Actual HTTP and CLI safety configuration differ.' );
$http_boot = array( 'owner' => $http_attestation['identity']['owner'], 'trash_days' => $http_attestation['runtime']['trash_days'], 'disposable' => $http_attestation['runtime']['disposable'] );
wstm116_require( false === get_option( 'wstm116_control', false ), 'Refusing an existing destructive-safety control option.' );
foreach ( array_merge( range( 10000001, 10000101 ), array( 2147483647 ) ) as $missing_id ) {
	wstm116_require( null === get_post( $missing_id ), 'Missing-ID fixture collides with an existing post.' );
}
$summary = array(
	'boundary' => $boundary, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'source_sha' => getenv( 'WSTM116_SOURCE_SHA' ) ?: 'not supplied',
	'trash_days' => EMPTY_TRASH_DAYS, 'http_boot' => $http_boot,
	'cli_boot' => $summary['cli_boot'], 'http_boot_attempt' => $summary['http_boot_attempt'],
	'http_attestation' => $http_attestation,
	'passed' => 0, 'failed' => 0, 'cases' => array(), 'cleanup' => array(), 'cleanup_complete' => false,
);
foreach ( array( __FILE__, __DIR__ . '/destructive-safety-fixture.php', __DIR__ . '/destructive-safety-http-fixture.php',
	__DIR__ . '/destructive-safety-evidence.php', __DIR__ . '/destructive-safety-lifecycle.php', __DIR__ . '/destructive-safety-stage.php',
	__DIR__ . '/destructive-safety-preflight.php', __DIR__ . '/destructive-safety-wire.php', __DIR__ . '/destructive-safety-diagnostics.php',
	__DIR__ . '/destructive-safety-boot.php', __DIR__ . '/destructive-safety-probe.php', __DIR__ . '/destructive-safety-uploads.php',
	__DIR__ . '/destructive-safety-cleanup.php',
	__DIR__ . '/metadata-transport.php', __DIR__ . '/metadata-batch-fixture.php',
	__DIR__ . '/error-contract-assertions.php', __DIR__ . '/error-contract-fixture.php', __DIR__ . '/abilities-manifest.json',
	__DIR__ . '/../../includes/class-posts.php', __DIR__ . '/../../includes/class-media.php',
	__DIR__ . '/../../includes/class-taxonomy.php', __DIR__ . '/../../includes/class-content-hygiene.php' ) as $source ) {
	$summary['hashes'][ basename( $source ) ] = hash_file( 'sha256', $source );
}
$run = 'wstm116-' . wp_generate_uuid4();
$users = $posts = $terms = $files = $transports = array();
$file_hashes = array();
$file_identities = array();
$owned_uploads = null;
$owned_configuration = null;
$check_file = wstm116_cleanup_file_guard( $owned_uploads, $owned_configuration, $files, $file_hashes, $file_identities );
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
	$upload_configuration = wp_upload_dir( null, false );
	wstm116_require( false === $upload_configuration['error'], 'Cannot resolve original upload configuration.' );
	$upload_owner = bin2hex( random_bytes( 16 ) );
	$owned_uploads = new Wstm116_Uploads( $upload_configuration['basedir'], $upload_configuration['baseurl'], $upload_owner, $http_attestation['identity']['uid'] );
	$owned_uploads->acquire();
	$owned_configuration = $owned_uploads->filter( $upload_configuration );
	$upload_response = wp_remote_get( 'http://localhost/wp-json/wstm116/upload/' . $upload_owner, array( 'headers' => array( 'X-WSTM116-Stage' => $stage_token ), 'timeout' => 2, 'redirection' => 0 ) );
	wstm116_require( ! is_wp_error( $upload_response ) && 200 === wp_remote_retrieve_response_code( $upload_response ), 'Cannot attest HTTP upload ownership.' );
	$upload_attestation = json_decode( wp_remote_retrieve_body( $upload_response ), true, 512, JSON_THROW_ON_ERROR );
	$summary['http_upload'] = $upload_attestation;
	$evidence->append( array( 'phase' => 'upload-ownership', 'attestation' => $upload_attestation ) );
	$evidence->save( $summary );
	wstm116_validate_boot( $upload_attestation['boot'], $lifecycle->identity() );
	wstm116_require( wstm116_runtime_configuration() === $upload_attestation['boot']['runtime']
		&& $upload_attestation['upload']['owner'] === $upload_owner
		&& $upload_attestation['upload']['directory'] === $owned_configuration['path']
		&& $upload_attestation['upload']['uid'] === $http_attestation['identity']['uid']
		&& true === $upload_attestation['upload']['writable'], 'Owned upload directory is not writable by the exact attested HTTP UID.' );
	$owned_uploads->assert_owned();
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
	$make_media = static function () use ( &$posts, &$files, &$file_hashes, &$file_identities, $users, $run, $owned_uploads ): int {
		$name = $run . '-' . wp_generate_uuid4() . '.png';
		$upload = $owned_uploads->seed( static function () use ( $name ): array {
			$uploads = wp_upload_dir( null, false );
			wstm116_require( ! $uploads['error'] && ! file_exists( $uploads['path'] . '/' . $name ) && ! is_link( $uploads['path'] . '/' . $name ), 'Upload path collision/error.' );
			return wp_upload_bits( $name, null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0ioAAAAASUVORK5CYII=' ) );
		}, 'add_filter', 'remove_filter' );
		wstm116_require( ! $upload['error'], 'Cannot create owned media file.' );
		$files[] = $upload['file'];
		$file_hashes[ $upload['file'] ] = hash_file( 'sha256', $upload['file'] );
		$identity = lstat( $upload['file'] );
		wstm116_require( is_array( $identity ) && is_string( $file_hashes[ $upload['file'] ] ), 'Cannot capture owned upload identity.' );
		$file_identities[ $upload['file'] ] = array_intersect_key( $identity, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode' ) ) );
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
			$entry['permission_is_wp_error'] = is_wp_error( $permission );
			$entry['permission_error_code'] = is_wp_error( $permission ) ? $permission->get_error_code() : null;
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
				$entry['wire_body'] = $transports[ $role ]->last_response_body;
				$evidence->append( array( 'phase' => 'wire', 'ability' => $slug, 'role' => $role, 'input' => $input, 'wire' => $entry['wire'], 'wire_body' => $entry['wire_body'] ) );
				wp_cache_flush();
			}
			wp_cache_delete( 'wstm116_control', 'options' );
			$control = get_option( 'wstm116_control' );
			wstm116_require( $nonce === ( $control['observed'] ?? null ), 'HTTP mutation observer did not attest this invocation.' );
			wstm116_require( EMPTY_TRASH_DAYS === ( $control['trash_days'] ?? null ) && WSTM116_STAGE_TOKEN === ( $control['stage_owner'] ?? null ), 'HTTP boot changed during safety proof.' );
			$events = $control['events'];
			$entry['http_file_operations'] = $control['file_operations'] ?? null;
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
		if ( isset( $transports[ $role ] ) && true === ( $result['success'] ?? false ) && isset( $result['data']['failures'] ) ) {
			$result = wstm116_bulk_wire_result( $entry['wire_body'], 'individual' === $boundary, $result );
			$entry['result'] = $result;
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
			if ( 'invalid_input' === $permission ) {
				wstm116_require( true === $entry['permission_is_wp_error'], 'Strict permission rejection must be a native WP_Error.' );
				wstm116_require( 'invalid_input' === $entry['permission_error_code'], 'Strict permission rejection must retain its canonical native code.' );
				$raw = wstm118_error_envelope( $entry['permission'] );
				wstm116_require( 'invalid_input' === $raw['error']['code'] && 'ability_invalid_input' === $raw['error']['reason'], 'Wrong strict raw permission code/reason.' );
			} else {
				wstm116_require( $permission === ( true === $entry['permission'] ), 'Permission callback result did not match the expected independent boundary.' );
				if ( false === $permission ) {
					wstm116_require( true === $entry['permission_is_wp_error'] && 'forbidden' === $entry['permission_error_code'] && 'forbidden' === $entry['permission']['error']['code'] && 'forbidden' === $entry['permission']['error']['reason'], 'Valid-input actor denial must retain its native forbidden permission error.' );
				}
			}
			wstm116_require( false === strpos( wp_json_encode( $envelope ), 'WSTM116_PRIVATE_SQL_FAILURE' ), 'Private SQL escaped.' );
			if ( 'too_many_ids' === $reason ) {
				wstm116_require( (object) array( 'limit' => 100 ) == $envelope['error']['details'], 'Missing raw ID limit details.' );
			}
		} );
	};
	foreach ( $inputs as $slug => $base ) {
		foreach ( array( 'missing' => array(), 'false' => array( 'confirm' => false ), 'string' => array( 'confirm' => 'true' ), 'number' => array( 'confirm' => 1 ), 'null' => array( 'confirm' => null ) ) as $label => $flags ) {
			$reason = 'direct' === $boundary ? 'missing_confirmation' : 'ability_invalid_input';
			$error_case( "{$slug} confirmation {$label}", $slug, array_merge( $base, $flags ), 'editor', $reason, 'invalid_input' );
			$error_case( "{$slug} administrator confirmation {$label}", $slug, array_merge( $base, $flags ), 'administrator', $reason, 'invalid_input' );
			if ( 0 === strpos( $slug, 'bulk-' ) ) {
				$error_case( "{$slug} preview confirmation {$label}", $slug, array_merge( $base, $flags, array( 'dry_run' => true ) ), 'editor', $reason, 'invalid_input' );
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
			$error_case( "{$slug} strict {$flag} {$index}", $slug, $inputs[ $slug ] + array( 'confirm' => true, $flag => $value ), 'editor', 'direct' === $boundary ? 'invalid_input' : 'ability_invalid_input', 'invalid_input' );
		}
	}
	foreach ( array( 'bulk-trash-posts', 'bulk-publish-posts' ) as $slug ) {
		foreach ( array( range( 10000001, 10000101 ), array_fill( 0, 101, $draft ) ) as $index => $ids ) {
			foreach ( array( false, true ) as $dry_run ) {
				$error_case( "{$slug} 101 raw {$index} preview " . (int) $dry_run, $slug, array( 'ids' => $ids, 'confirm' => true, 'dry_run' => $dry_run ), 'editor', 'direct' === $boundary ? 'too_many_ids' : 'ability_invalid_input', 'invalid_input' );
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
			$entry['file_before'] = wstm116_file_diagnostics( $file );
			$result = $invoke( 'delete-media', array( 'media_id' => $id, 'confirm' => true, 'force' => $known ), 'author', array(), $entry, false );
			$entry['deletion_state'] = array( 'post_exists' => null !== get_post( $id ), 'file' => wstm116_file_diagnostics( $file ) );
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
	wstm116_cleanup( $summary, $transports, $owns_control, $run, $posts, $terms, $files, $owned_uploads, $users, $old_user, $check_file );
	$evidence->save( $summary );
	$completed = true;
}
echo 'SUMMARY ' . $summary['passed'] . ' passed, ' . $summary['failed'] . " failed\n";
exit( $summary['failed'] ? 1 : 0 );
