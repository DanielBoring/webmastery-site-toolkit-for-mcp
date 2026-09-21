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
$boundary = getenv( 'WSTM126_BOUNDARY' );
if ( ! in_array( $boundary, array( 'direct', 'permission', 'ability', 'http', 'individual' ), true ) ) {
	throw new RuntimeException( 'WSTM126_BOUNDARY must be direct, permission, ability, http or individual.' );
}
$artifact = getenv( 'WSTM126_ARTIFACT' );
if ( ! is_string( $artifact ) || '' === $artifact || file_exists( $artifact ) || ! is_writable( dirname( $artifact ) ) ) {
	throw new RuntimeException( 'WSTM126_ARTIFACT must name a new file in an existing writable directory.' );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
require_once __DIR__ . '/error-contract-assertions.php';
require_once __DIR__ . '/metadata-transport.php';

if ( ! defined( 'WSTM126_DISPOSABLE_RUNTIME' ) || true !== WSTM126_DISPOSABLE_RUNTIME || ! function_exists( 'wstm126_begin' ) ) {
	throw new RuntimeException( 'Install the opt-in input-schema MU fixture before bootstrap.' );
}
wstm126_require( false === get_option( 'wstm126_http', false ), 'An input proof already owns the HTTP observation option.' );
$summary = array(
	'boundary' => $boundary, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'source_sha' => getenv( 'WSTM126_SOURCE_SHA' ) ?: 'not supplied',
	'passed' => 0, 'failed' => 0, 'cases' => array(), 'cleanup' => array(),
);
foreach ( array( __FILE__, __DIR__ . '/input-schema-fixture.php', __DIR__ . '/../../includes/class-input.php' ) as $source ) {
	$summary['hashes'][ basename( $source ) ] = hash_file( 'sha256', $source );
}
$old_user = get_current_user_id();
$users = $posts = $transports = array();
$owns_option = false;
$run = 'wstm126-' . wp_generate_uuid4();
$token = bin2hex( random_bytes( 32 ) );
$record = static function ( string $label, callable $test ) use ( &$summary ): void {
	$entry = array( 'label' => $label );
	try {
		$test( $entry );
		$entry['passed'] = true;
		$summary['passed']++;
	} catch ( Throwable $error ) {
		$entry['passed'] = false;
		$entry['error'] = $error->getMessage();
		$summary['failed']++;
	}
	$summary['cases'][] = $entry;
};
try {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$owns_option = add_option( 'wstm126_http', array( 'owner' => $run ), '', false );
	wstm126_require( $owns_option, 'Observation option collision.' );
	foreach ( array( 'administrator', 'subscriber' ) as $role ) {
		$login = $run . '-' . $role;
		$id = wp_create_user( $login, wp_generate_password( 40 ), $login . '@example.test' );
		wstm126_require( ! is_wp_error( $id ), 'Cannot create proof actor.' );
		$users[ $role ] = $id;
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
			$password = WP_Application_Passwords::create_new_application_password( $id, array( 'name' => $run ) );
			wstm126_require( ! is_wp_error( $password ), 'Cannot create disposable HTTP credential.' );
			$transports[ $role ] = new Wstm110_Metadata_Transport( 'individual' === $boundary, array( 'login' => $login, 'password' => $password[0] ) );
			$transports[ $role ]->initialize();
			unset( $password );
		}
	}
	wp_set_current_user( $users['administrator'] );
	foreach ( array( 'post', 'page', 'mcp_book', 'mcp_case_study', 'parent-page' ) as $type ) {
		$id = wp_insert_post( array( 'post_type' => 'parent-page' === $type ? 'page' : $type, 'post_title' => $run, 'post_content' => 'Original', 'post_author' => $users['administrator'], 'post_status' => 'draft' ), true );
		wstm126_require( ! is_wp_error( $id ) && $id > 0, 'Cannot seed proof target.' );
		$posts[ $type ] = $id;
	}
	$invoke = static function ( string $slug, array $input, string $role, array &$entry ) use ( $boundary, $users, $transports, $token, $run ): array {
		wp_set_current_user( $users[ $role ] );
		$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $slug );
		wstm126_require( null !== $ability, 'Missing ability.' );
		$entry['input'] = $input;
		$entry['before'] = wstm110_batch_snapshot();
		if ( isset( $transports[ $role ] ) ) {
			wstm126_require( $run === get_option( 'wstm126_http' )['owner'], 'Lost observation ownership.' );
			update_option( 'wstm126_http', array( 'owner' => $run, 'token' => $token, 'user_id' => $users[ $role ] ), false );
			try {
				$result = $transports[ $role ]->execute( $ability->get_name(), $input, $token );
			} finally {
				$entry['wire'] = $transports[ $role ]->last_response;
				$entry['evidence'] = $transports[ $role ]->last_events;
			}
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
	$cases['numeric string ID'] = array( 'update-page', array( 'page_id' => (string) $posts['page'], 'title' => 'Must not persist' ), 'ability' === $boundary ? 'ability_invalid_permissions' : 'ability_invalid_input' );
	foreach ( $cases as $label => [ $slug, $input, $reason ] ) {
		$record( $label, static function ( &$entry ) use ( $invoke, $slug, $input, $reason, $boundary ) {
			$result = $invoke( $slug, $input, 'administrator', $entry );
			wstm118_error_envelope( $result );
			wstm126_require( $reason === $result['error']['reason'], 'Wrong rejection layer/reason.' );
			wstm126_assert_no_work( $entry['before'], $entry['after'], $entry['evidence'] );
			$expected = 'ability' === $boundary && 'ability_invalid_permissions' !== $reason ? 0 : 1;
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
	$summary['failed']++;
	$summary['fatal'] = $error->getMessage();
} finally {
	$cleanup = static function ( string $label, callable $action ) use ( &$summary ) {
		try {
			$action();
			$summary['cleanup'][ $label ] = 'passed';
		} catch ( Throwable $error ) {
			$summary['cleanup'][ $label ] = $error->getMessage();
			$summary['failed']++;
		}
	};
	foreach ( $transports as $role => $transport ) { $cleanup( 'session:' . $role, static fn() => $transport->close() ); }
	wp_set_current_user( $users['administrator'] ?? $old_user );
	foreach ( $posts as $id ) { $cleanup( 'post:' . $id, static function () use ( $id ) { wstm126_require( (bool) wp_delete_post( $id, true ), 'Cannot delete owned fixture post.' ); } ); }
	foreach ( $users as $id ) { $cleanup( 'user:' . $id, static function () use ( $id ) { wstm126_require( wp_delete_user( $id ), 'Cannot delete owned actor and credentials.' ); } ); }
	if ( $owns_option ) {
		$cleanup( 'observation', static function () use ( $run ) {
			wstm126_require( $run === get_option( 'wstm126_http' )['owner'], 'Lost observation ownership during cleanup.' );
			wstm126_require( delete_option( 'wstm126_http' ), 'Cannot remove owned observation option.' );
		} );
	}
	wp_set_current_user( $old_user );
	$json = wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	wstm126_require( strlen( $json ) === file_put_contents( $artifact, $json ), 'Cannot persist complete raw proof.' );
}
echo "Input schema {$boundary}: {$summary['passed']} passed, {$summary['failed']} failed.\n";
exit( $summary['failed'] ? 1 : 0 );
