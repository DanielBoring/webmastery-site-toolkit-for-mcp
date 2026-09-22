<?php
/**
 * Opt-in native/direct/raw-permission/actual-HTTP proof. Never run on a live site.
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM126_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Set WSTM126_DISPOSABLE=1 only in an owned disposable runtime.' );
}
$stage_token = getenv( 'WSTM126_STAGE_TOKEN' );
$source_sha = getenv( 'WSTM126_SOURCE_SHA' );
$project = getenv( 'WSTM126_PROJECT' );
if ( ! is_string( $stage_token ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $stage_token )
	|| ! is_string( $source_sha ) || 1 !== preg_match( '/^[a-f0-9]{40}$/D', $source_sha )
	|| ! is_string( $project ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,62}$/D', $project ) ) {
	throw new RuntimeException( 'Missing or malformed schema runtime identity.' );
}
$boundary = getenv( 'WSTM126_BOUNDARY' );
if ( ! in_array( $boundary, array( 'direct', 'permission', 'ability', 'http', 'individual' ), true ) ) {
	throw new RuntimeException( 'WSTM126_BOUNDARY must be direct, permission, ability, http or individual.' );
}
$artifact = getenv( 'WSTM126_ARTIFACT' );
if ( ! is_string( $artifact ) || '' === $artifact || is_link( $artifact ) || file_exists( $artifact ) || ! is_writable( dirname( $artifact ) ) ) {
	throw new RuntimeException( 'WSTM126_ARTIFACT must name a new file in an existing writable directory.' );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
require_once __DIR__ . '/error-contract-assertions.php';
require_once __DIR__ . '/metadata-transport.php';
require_once __DIR__ . '/input-schema-cleanup.php';
require_once __DIR__ . '/input-schema-proof.php';

if ( ! defined( 'WSTM126_DISPOSABLE_RUNTIME' ) || true !== WSTM126_DISPOSABLE_RUNTIME || ! function_exists( 'wstm126_begin' )
	|| ! defined( 'WSTM126_STAGE_TOKEN' ) || WSTM126_STAGE_TOKEN !== $stage_token ) {
	throw new RuntimeException( 'Install the opt-in input-schema MU fixture before bootstrap.' );
}
wstm126_require( false === get_option( 'wstm126_http', false ), 'An input proof already owns the HTTP observation option.' );
$summary = array(
	'schema_version' => 1, 'owner' => $stage_token, 'project' => $project,
	'expected_cases' => 152, 'completed' => false, 'cleanup_complete' => false,
	'boundary' => $boundary, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'source_sha' => $source_sha,
	'passed' => 0, 'failed' => 0, 'cases' => array(), 'cleanup' => array(),
);
$plugin_root = dirname( ( new ReflectionClass( Webmastery_MCP_Ability::class ) )->getFileName(), 2 );
foreach ( array( Webmastery_MCP_Ability::class => 'class-ability.php', Webmastery_MCP_Input::class => 'class-input.php', Webmastery_MCP_Response::class => 'class-response.php' ) as $class => $filename ) {
	wstm126_require( realpath( $plugin_root . '/includes/' . $filename ) === realpath( ( new ReflectionClass( $class ) )->getFileName() ), 'Mixed loaded production sources.' );
}
wstm126_require( realpath( dirname( __DIR__, 2 ) ) === realpath( $plugin_root ), 'Runner is not in the loaded production mount.' );
$summary['source_hashes'] = wstm126_source_hashes( $plugin_root );
foreach ( array( __FILE__, __DIR__ . '/input-schema-fixture.php', __DIR__ . '/../../includes/class-input.php', __DIR__ . '/../../includes/class-ability.php' ) as $source ) {
	$summary['hashes'][ basename( $source ) ] = hash_file( 'sha256', $source );
}
$old_user = get_current_user_id();
$users = $posts = $transports = array();
$owns_option = false;
$run = 'wstm126-' . wp_generate_uuid4();
$token = bin2hex( random_bytes( 32 ) );
$journal = new Wstm126_Cleanup(
	(string) getenv( 'WSTM126_JOURNAL_DIR' ), ABSPATH, dirname( $artifact ), $stage_token, $boundary, $run,
	'wstm126_cleanup_snapshot',
	static function ( string $kind, int $id, string $reference = '' ): void {
		if ( 'post' === $kind ) { wp_delete_post( $id, true ); }
		elseif ( 'actor' === $kind ) { wp_delete_user( $id ); }
		elseif ( 'credential' === $kind ) { WP_Application_Passwords::delete_application_password( $id, $reference ); }
		else { delete_option( 'wstm126_http' ); }
	}
);
$http_actor = 0;
$http_plan = '';
$http_capture = array();
$http_secrets = array( $token );
$http_debug = static function ( $response, $context, $class, $args, $url ) use ( $journal, &$http_actor, &$http_plan, &$http_capture, &$http_secrets ): void {
	if ( 0 === $http_actor || 'response' !== $context || ! in_array( $url, array( 'http://localhost/wp-json/wstm118/tools', 'http://localhost/wp-json/mcp/mcp-adapter-default-server' ), true ) ) { return; }
	if ( is_wp_error( $response ) ) {
		$http_capture = array( 'transport_error' => true );
		return;
	}
	$session = wp_remote_retrieve_header( $response, 'mcp-session-id' );
	if ( is_string( $session ) && '' !== $session ) { $http_secrets[] = $session; }
	$http_capture = wstm126_capture_http( $journal, $http_actor, $http_plan, $url, $args, wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ), is_string( $session ) ? $session : '', $http_secrets );
};
add_action( 'http_api_debug', $http_debug, PHP_INT_MIN, 5 );
$http_pre = static function ( $pre, $args, $url ) use ( $http_debug ) {
	if ( false !== $pre ) { $http_debug( $pre, 'response', '', $args, $url ); }
	return $pre;
};
add_filter( 'pre_http_request', $http_pre, PHP_INT_MAX, 3 );
$record = static function ( string $label, callable $test ) use ( &$summary, &$http_secrets ): void {
	$entry = array( 'label' => $label );
	try {
		$test( $entry );
		$entry['passed'] = true;
		$summary['passed']++;
	} catch ( Throwable $error ) {
		$entry['passed'] = false;
		$entry['error'] = wstm126_safe_failure( $error->getMessage(), $http_secrets );
		$summary['failed']++;
	}
	$summary['cases'][] = $entry;
};
try {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$journal->plan( 'observation', array( 'owner' => $run ) );
	$owns_option = add_option( 'wstm126_http', array( 'owner' => $run ), '', false );
	wstm126_require( $owns_option, 'Observation option collision.' );
	$journal->observation_created();
	foreach ( array( 'administrator', 'subscriber' ) as $role ) {
		$login = $run . '-' . $role;
		$journal->plan( 'actor:' . $role, array( 'user_login' => $login, 'user_email' => $login . '@example.test' ) );
		$id = wp_create_user( $login, wp_generate_password( 40 ), $login . '@example.test' );
		wstm126_require( ! is_wp_error( $id ), 'Cannot create proof actor.' );
		$users[ $role ] = $id;
		$created = array_column( wstm126_cleanup_snapshot()['users'], null, 'ID' );
		$journal->created( 'actor:' . $role, 'actors', (string) $id, Wstm126_Cleanup::actor_identity( $created[ $id ] ) );
		$user = new WP_User( $id );
		$user->set_role( $role );
		if ( 'administrator' === $role ) {
			foreach ( array( 'mcp_book', 'mcp_case_study' ) as $type ) {
				$object = get_post_type_object( $type );
				wstm126_require( null !== $object, 'Missing CPT fixture.' );
				foreach ( (array) $object->cap as $cap ) { $user->add_cap( $cap ); }
			}
		}
		if ( in_array( $boundary, array( 'http', 'individual' ), true ) ) {
			$journal->plan( 'credential:' . $role, array( 'actor' => (int) $id, 'name' => $run ) );
			$password = WP_Application_Passwords::create_new_application_password( $id, array( 'name' => $run ) );
			wstm126_require( ! is_wp_error( $password ), 'Cannot create disposable HTTP credential.' );
			$http_secrets[] = $password[0];
			$http_secrets[] = base64_encode( $login . ':' . $password[0] );
			$journal->created( 'credential:' . $role, 'credentials', $password[1]['uuid'], Wstm126_Cleanup::credential_identity( (int) $id, $password[1] ) );
			$transports[ $role ] = new Wstm110_Metadata_Transport( 'individual' === $boundary, array( 'login' => $login, 'password' => $password[0] ) );
			$http_actor = (int) $id;
			$http_plan = 'session:' . $role;
			$journal->plan( $http_plan, array( 'actor' => $http_actor ) );
			$transports[ $role ]->initialize();
			$http_actor = 0;
			unset( $password );
		}
	}
	wp_set_current_user( $users['administrator'] );
	foreach ( array( 'post', 'page', 'mcp_book', 'mcp_case_study', 'parent-page' ) as $type ) {
		$post_type = 'parent-page' === $type ? 'page' : $type;
		$journal->plan( 'post:' . $type, array( 'post_type' => $post_type, 'post_author' => (string) $users['administrator'], 'post_name' => $run . '-' . $type ) );
		$id = wp_insert_post( array( 'post_type' => $post_type, 'post_name' => $run . '-' . $type, 'post_title' => $run, 'post_content' => 'Original', 'post_author' => $users['administrator'], 'post_status' => 'draft' ), true );
		wstm126_require( ! is_wp_error( $id ) && $id > 0, 'Cannot seed proof target.' );
		$posts[ $type ] = $id;
		$created = array_column( wstm126_cleanup_snapshot()['posts'], null, 'ID' );
		$journal->created( 'post:' . $type, 'posts', (string) $id, Wstm126_Cleanup::post_identity( $created[ $id ] ) );
	}
	$invoke = static function ( string $slug, array $input, string $role, array &$entry ) use ( $boundary, $users, $transports, $token, $run, &$http_actor, &$http_plan, &$http_capture ): array {
		wp_set_current_user( $users[ $role ] );
		$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $slug );
		wstm126_require( null !== $ability, 'Missing ability.' );
		$entry['input'] = $input;
		$entry['before'] = wstm110_batch_snapshot();
		if ( isset( $transports[ $role ] ) ) {
			wstm126_require( $run === get_option( 'wstm126_http' )['owner'], 'Lost observation ownership.' );
			update_option( 'wstm126_http', array( 'owner' => $run, 'token' => $token, 'user_id' => $users[ $role ] ), false );
			$http_actor = (int) $users[ $role ];
			$http_plan = 'session:' . $role;
			$http_capture = array();
			try {
				$result = $transports[ $role ]->execute( $ability->get_name(), $input, $token );
			} finally {
				$entry['wire'] = empty( $http_capture['redacted'] ) ? $transports[ $role ]->last_response : array( 'redacted' => true, 'sha256' => $http_capture['sha256'] ?? null );
				$entry['evidence'] = $transports[ $role ]->last_events;
				$entry['raw_response'] = $http_capture;
				$http_actor = 0;
			}
			wstm126_require( ! empty( $http_capture ) && false === ( $http_capture['redacted'] ?? null ), 'HTTP body retained as a hash-only safety witness.' );
		} else {
			$observer = wstm126_begin();
			try {
				if ( 'direct' === $boundary ) {
					$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
					$property->setAccessible( true );
					$result = ( $property->getValue( $ability ) )( $input );
				} elseif ( 'permission' === $boundary ) {
					$result = $ability->check_permissions( $input );
					wstm126_require( true === $result || is_wp_error( $result ), 'Permission result is not true or native WP_Error.' );
				} else {
					$result = $ability->execute( $input );
				}
			} finally {
				$entry['evidence'] = wstm126_end( $observer );
			}
		}
		$entry['after'] = wstm110_batch_snapshot();
		$entry['result'] = is_wp_error( $result ) ? Webmastery_MCP_Response::from_wp_error( $result ) : $result;
		return true === $result ? array( 'success' => true ) : $entry['result'];
	};
	$cases = array();
	foreach ( array(
		'list-posts' => 'status', 'list-pages' => 'order', 'list-cpt-mcp-book' => 'orderby',
		'list-comments' => 'status', 'list-users' => 'orderby', 'get-seo-scores' => 'status',
		'get-site-kit-pagespeed' => 'strategy', 'list-posts-no-featured-image' => 'post_type',
	) as $slug => $field ) {
		foreach ( array( null, true, false, 42, 1.5, array(), (object) array(), 'INVALID', ' publish', 'publish!' ) as $index => $value ) {
			$cases[ "{$slug}:{$field}:{$index}" ] = array( $slug, array( $field => $value ), 'ability_invalid_input' );
		}
	}
	foreach ( array( 'post', 'mcp_book', 'mcp_case_study' ) as $type ) {
		foreach ( array( 'create', 'update' ) as $operation ) {
			$slug = $operation . '-' . ( 'post' === $type ? $type : 'cpt-' . str_replace( '_', '-', $type ) );
			$base = array( 'title' => 'Must not persist', 'content' => 'Rejected' );
			if ( 'update' === $operation ) { $base[ 'post' === $type ? 'post_id' : 'id' ] = $posts[ $type ]; }
			foreach ( array( 0, $posts[ $type ], null, array() ) as $index => $parent ) {
				$cases[ "{$slug}:parent:{$index}" ] = array( $slug, $base + array( 'parent' => $parent ), 'ability_invalid_input' );
			}
		}
	}
	$cases['page unknown property'] = array( 'update-page', array( 'page_id' => $posts['page'], 'title' => 'Must not persist', 'parent' => 0, 'unknown' => true ), 'ability_invalid_input' );
	$cases['numeric string ID'] = array( 'update-page', array( 'page_id' => (string) $posts['page'], 'title' => 'Must not persist' ), 'ability_invalid_input' );
	foreach ( array(
		'bulk-trash-posts' => array( 'ids' => array( $posts['post'] ) ),
		'bulk-publish-posts' => array( 'ids' => array( $posts['post'] ) ),
		'delete-media' => array( 'media_id' => $posts['post'] ),
		'delete-category' => array( 'category_id' => $posts['post'] ),
		'delete-tag' => array( 'tag_id' => $posts['post'] ),
	) as $slug => $base ) {
		// Invalid flags must stop before even resolving these integer target IDs.
		foreach ( array( array(), array( 'confirm' => false ), array( 'confirm' => null ), array( 'confirm' => 'true' ), array( 'confirm' => 1 ) ) as $index => $flags ) {
			$cases[ "{$slug}:confirm:{$index}" ] = array( $slug, $base + $flags, 'direct' === $boundary ? 'missing_confirmation' : 'ability_invalid_input' );
		}
		$flag = 'delete-media' === $slug ? 'force' : ( str_starts_with( $slug, 'bulk-' ) ? 'dry_run' : null );
		if ( null !== $flag ) {
			foreach ( array( 'true', 'false', 0, 1, null, array() ) as $index => $value ) {
				$cases[ "{$slug}:{$flag}:{$index}" ] = array( $slug, $base + array( 'confirm' => true, $flag => $value ), 'direct' === $boundary ? 'invalid_input' : 'ability_invalid_input' );
			}
		}
	}
	foreach ( $cases as $label => [ $slug, $input, $reason ] ) {
		$record( $label, static function ( &$entry ) use ( $invoke, $slug, $input, $reason, $boundary ) {
			$result = $invoke( $slug, $input, 'administrator', $entry );
			wstm118_error_envelope( $result );
			wstm126_require( $reason === $result['error']['reason'], 'Wrong rejection layer/reason.' );
			wstm126_assert_no_work( $entry['before'], $entry['after'], $entry['evidence'] );
			$expected = 'ability' === $boundary ? 0 : 1;
			wstm126_require( $expected === count( $entry['evidence']['callbacks'] ), 'Missing or replayed callback observation.' );
		} );
	}
	$record( 'valid subscriber denial', static function ( &$entry ) use ( $invoke, $posts, $boundary ) {
		$result = $invoke( 'update-page', array( 'page_id' => $posts['page'], 'title' => 'Denied' ), 'subscriber', $entry );
		wstm118_error_envelope( $result );
		wstm126_require( 'forbidden' === $result['error']['code'], 'Valid input bypassed authorization.' );
		wstm126_require( $entry['before'] === $entry['after'] && array() === $entry['evidence']['mutations'], 'Denied actor changed state.' );
		wstm126_require( array_sum( array_column( $entry['evidence']['callbacks'], 'capabilities' ) ) > 0, 'Permission negative control did not reach real authorization.' );
	} );
	foreach ( array( array( 'parent' => 0 ), array() ) as $index => $parent ) {
		$record( 'allowed hierarchical detach/omission ' . $index, static function ( &$entry ) use ( $invoke, $posts, $parent, $index, $boundary, $run, $users ) {
			wp_set_current_user( $users['administrator'] );
			wstm126_require( $posts['page'] === wp_update_post( array( 'ID' => $posts['page'], 'post_parent' => $posts['parent-page'] ), true ), 'Cannot seed positive parent control.' );
			$result = $invoke( 'update-page', array( 'page_id' => $posts['page'], 'title' => $run . '-' . $index ) + $parent, 'administrator', $entry );
			wstm126_require( true === $result['success'], 'Advertised parent/default control rejected.' );
			wstm126_require( ! empty( $entry['evidence']['callbacks'] ), 'Valid callback observer was not installed.' );
			if ( 'permission' !== $boundary ) {
				wstm126_require( $entry['before'] !== $entry['after'] && ! empty( $entry['evidence']['mutations'] ), 'Valid write did not calibrate state/hook oracle.' );
				$expected_parent = array_key_exists( 'parent', $parent ) ? 0 : $posts['parent-page'];
				wstm126_require( $expected_parent === (int) get_post( $posts['page'] )->post_parent, 'Hierarchical detach/omission changed parent semantics.' );
			}
		} );
	}
} catch ( Throwable $error ) {
	$summary['fatal'] = wstm126_safe_failure( $error->getMessage(), $http_secrets );
} finally {
	wp_set_current_user( $users['administrator'] ?? $old_user );
	$flow_complete = ! isset( $summary['fatal'] ) && 152 === count( $summary['cases'] )
		&& wstm126_expected_labels() === array_column( $summary['cases'], 'label' );
	$cleaned = $journal->finish( static function () use ( $transports, $users, &$http_actor, &$http_plan ): void {
		foreach ( $transports as $role => $transport ) {
			$http_actor = (int) $users[ $role ];
			$http_plan = 'session:' . $role;
			$transport->close();
		}
		$http_actor = 0;
	}, $flow_complete, $summary['failed'] > 0 );
	$summary['cleanup_proof'] = $cleaned['proof'];
	$summary['cleanup'] = $cleaned['proof'];
	$summary['cleanup_complete'] = ! in_array( false, $cleaned['proof'], true );
	$summary['completed'] = $flow_complete && $summary['cleanup_complete'];
	if ( null !== $cleaned['error'] ) { $summary['cleanup_error'] = $cleaned['error']; }
	if ( null !== $cleaned['raw_evidence'] ) { $summary['private_raw_evidence'] = $cleaned['raw_evidence']; }
	remove_action( 'http_api_debug', $http_debug, PHP_INT_MIN );
	remove_filter( 'pre_http_request', $http_pre, PHP_INT_MAX );
	wp_set_current_user( $old_user );
	$json = wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	$file = fopen( $artifact, 'x' );
	wstm126_require( false !== $file, 'Cannot exclusively create proof artifact.' );
	try { wstm126_require( strlen( $json ) === fwrite( $file, $json ) && fflush( $file ), 'Cannot persist complete raw proof.' ); } finally { fclose( $file ); }
}
echo "Input schema {$boundary}: {$summary['passed']} passed, {$summary['failed']} failed.\n";
exit( $summary['failed'] || ! $summary['completed'] ? 1 : 0 );
