<?php
/**
 * CLI-only direct/ability metadata-batch proof for an explicitly owned QA runtime.
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM110_BATCH_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Set WSTM110_BATCH_DISPOSABLE=1 only in an owned disposable WordPress runtime.' );
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
require_once __DIR__ . '/metadata-batch-fixture.php';
require_once __DIR__ . '/error-contract-assertions.php';
require_once __DIR__ . '/metadata-transport.php';

function wstm110_batch_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wstm110_batch_result( $result ): array {
	if ( is_wp_error( $result ) ) {
		return Webmastery_MCP_Response::from_wp_error( $result );
	}
	wstm110_batch_require( is_array( $result ), 'Unrecognized ability result.' );
	return $result;
}

function wstm110_batch_cleanup( array &$summary, string $label, callable $action ): void {
	try {
		$action();
	} catch ( Throwable $error ) {
		$summary['failed']++;
		$summary['cleanup_errors'][] = array( 'resource' => $label, 'error' => $error->getMessage() );
		echo "FAIL cleanup {$label}: {$error->getMessage()}\n";
	}
}

$boundary = getenv( 'WSTM110_BATCH_BOUNDARY' ) ?: 'ability';
wstm110_batch_require( in_array( $boundary, array( 'direct', 'ability', 'http', 'individual' ), true ), 'Unknown metadata-batch boundary.' );
$summary = array(
	'boundary' => $boundary, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'runner_sha256' => hash_file( 'sha256', __FILE__ ),
	'fixture_sha256' => hash_file( 'sha256', __DIR__ . '/metadata-batch-fixture.php' ),
	'posts_sha256' => hash_file( 'sha256', __DIR__ . '/../../includes/class-posts.php' ),
	'cpt_sha256' => hash_file( 'sha256', __DIR__ . '/../../includes/class-custom-post-types.php' ),
	'passed' => 0, 'failed' => 0, 'cases' => array(),
);
$user_id = 0;
$observer = null;
$transport = null;
$password = null;
$token = '';
$term_ids = array();
$old_user_id = get_current_user_id();
$run = 'wstm110-batch-' . wp_generate_uuid4();
try {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$user_id = wp_create_user( $run, wp_generate_password( 40 ), $run . '@example.test' );
	wstm110_batch_require( ! is_wp_error( $user_id ), 'Cannot create unique metadata QA actor.' );
	$user = new WP_User( $user_id );
	$user->set_role( 'administrator' );
	foreach ( array( 'mcp_book', 'mcp_case_study' ) as $type ) {
		$object = get_post_type_object( $type );
		wstm110_batch_require( null !== $object, "Missing {$type} QA fixture." );
		foreach ( array( 'create_posts', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'delete_posts', 'delete_published_posts', 'publish_posts' ) as $capability ) {
			$user->add_cap( $object->cap->{$capability} );
		}
	}
	$user->add_cap( 'assign_mcp_genres' );
	wp_set_current_user( $user_id );
	if ( in_array( $boundary, array( 'http', 'individual' ), true ) ) {
		$password = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $run ) );
		wstm110_batch_require( ! is_wp_error( $password ), 'Cannot create metadata HTTP fixture credential.' );
		$token = bin2hex( random_bytes( 32 ) );
		update_option( 'wstm110_batch_http', array( 'token' => $token, 'user_id' => $user_id ), false );
		$transport = new Wstm110_Metadata_Transport( 'individual' === $boundary, array( 'login' => $run, 'password' => $password[0] ) );
		$transport->initialize();
		$summary['tools'] = $transport->catalog();
	}
	$execute = static function ( string $name, array $input ) use ( $boundary, $transport, $token ): array {
		if ( null !== $transport ) {
			return $transport->execute( $name, $input, $token );
		}
		$ability = wp_get_ability( $name );
		wstm110_batch_require( null !== $ability, "Missing {$name}." );
		if ( 'direct' === $boundary ) {
			$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
			$property->setAccessible( true );
			return wstm110_batch_result( ( $property->getValue( $ability ) )( $input ) );
		}
		return wstm110_batch_result( $ability->execute( $input ) );
	};
	foreach ( array( 'category', 'post_tag', 'mcp_genre' ) as $taxonomy ) {
		$term = wp_insert_term( $run, $taxonomy );
		wstm110_batch_require( ! is_wp_error( $term ), "Cannot seed {$taxonomy}." );
		$term_ids[ $taxonomy ] = $term['term_id'];
	}

	foreach ( array( 'post', 'page', 'mcp_book', 'mcp_case_study' ) as $type ) {
		$id = wp_insert_post( array(
			'post_type' => $type, 'post_author' => $user_id, 'post_title' => $run,
			'post_content' => 'Original content', 'post_status' => 'draft',
		), true );
		wstm110_batch_require( ! is_wp_error( $id ) && $id > 0, 'Cannot seed metadata-batch object.' );
		update_post_meta( $id, 'wstm110_sentinel', 'original' );
		update_post_meta( $id, '_yoast_wpseo_metadesc', 'original' );
		$suffix = in_array( $type, array( 'post', 'page' ), true ) ? $type : 'cpt-' . str_replace( '_', '-', $type );
		$id_key = in_array( $type, array( 'post', 'page' ), true ) ? $type . '_id' : 'id';
		foreach ( array( 'create', 'update' ) as $operation ) {
			$name = 'webmastery-site-toolkit-for-mcp/' . $operation . '-' . $suffix;
			$ability = wp_get_ability( $name );
			wstm110_batch_require( null !== $ability, "Missing {$name}." );
			foreach ( wstm110_batch_payloads() as $label => $payload ) {
				$input = array_merge( array(
					'title' => $run . ' changed', 'content' => 'Must not persist',
					'status' => 'future', 'scheduled_date' => '+2 days',
				), $payload );
				if ( 'update' === $operation ) {
					$input[ $id_key ] = $id;
				}
				if ( 'post' === $type ) {
					$input['category_ids'] = array( $term_ids['category'] );
					$input['tag_ids'] = array( $term_ids['post_tag'] );
				} elseif ( 'mcp_book' === $type ) {
					$input['taxonomy_terms'] = array( 'mcp_genre' => array( $term_ids['mcp_genre'] ) );
				}
				$record = array( 'ability' => $name, 'variant' => $label, 'input' => $input );
				$events = array();
				try {
					$before = wstm110_batch_snapshot();
					if ( null === $transport ) {
						$observer = wstm110_batch_observe( static function ( $hook ) use ( &$events ): void { $events[] = $hook; } );
					}
					try {
						$raw = $execute( $name, $input );
						if ( null !== $transport ) {
							$events = $transport->last_events;
							$record['wire'] = $transport->last_response;
							$record['tool_name'] = $transport->tool_name( $name );
						}
					} finally {
						if ( $observer instanceof Closure ) {
							wstm110_batch_unobserve( $observer );
						}
						$observer = null;
					}
					$after = wstm110_batch_snapshot();
					$record['before'] = $before;
					$record['after'] = $after;
					$record['mutation_hooks'] = $events;
					$result = wstm110_batch_result( $raw );
					wstm118_error_envelope( $result );
					$record['result'] = $result;
					wstm110_batch_assert_unchanged( $before, $after, $events );
					wstm110_batch_require( false === ( $result['success'] ?? null ), 'Combined request did not fail.' );
					wstm110_batch_require( 'invalid_input' === ( $result['error']['code'] ?? null ), 'Combined request did not have canonical invalid_input code.' );
					wstm110_batch_require( 'metadata_requires_separate_call' === ( $result['error']['reason'] ?? null ), 'Wrong combined-request reason.' );
					$record['passed'] = true;
					$summary['passed']++;
				} catch ( Throwable $error ) {
					$record['passed'] = false;
					$record['failure'] = $error->getMessage();
					$record['mutation_hooks'] = $events;
					$summary['failed']++;
					echo "FAIL {$name} {$label}: {$error->getMessage()}\n";
				}
				$summary['cases'][] = $record;
			}
		}
		// Calibrate each boundary with actual allowed writes and a non-atomic draft workflow.
		$record = array( 'type' => $type, 'steps' => array() );
		try {
			$migration_id = 0;
			$steps = array(
				array( 'create-' . $suffix, array( 'title' => $run . ' migration', 'content' => 'Plain migration draft', 'status' => 'draft' ) ),
				array( 'update-' . $suffix, array( $id_key => null, 'title' => $run . ' plain update' ) ),
				array( 'update-post-meta', array( 'post_id' => null, 'meta_key' => '_yoast_wpseo_metadesc', 'meta_value' => 'C:\\migration\\' ) ),
				array( 'update-' . $suffix, array( $id_key => null, 'status' => 'publish' ) ),
			);
			foreach ( $steps as $step => [ $slug, $input ] ) {
				foreach ( $input as &$value ) {
					if ( null === $value ) {
						$value = $migration_id;
					}
				}
				unset( $value );
				$events = array();
				if ( null === $transport ) {
					$observer = wstm110_batch_observe( static function ( $hook ) use ( &$events ): void { $events[] = $hook; } );
				}
				try {
					$result = $execute( 'webmastery-site-toolkit-for-mcp/' . $slug, $input );
				} finally {
					if ( $observer instanceof Closure ) {
						wstm110_batch_unobserve( $observer );
						$observer = null;
					}
				}
				if ( null !== $transport ) {
					$events = $transport->last_events;
				}
				wstm110_batch_require( true === ( $result['success'] ?? null ), "Allowed migration step {$slug} failed." );
				wstm110_batch_require( array() !== $events, "Mutation observer did not detect allowed {$slug}." );
				if ( 0 === $step ) {
					$migration_id = $result['data']['id'];
				}
				clean_post_cache( $migration_id );
				$stored = get_post( $migration_id );
				wstm110_batch_require( ( 3 === $step ? 'publish' : 'draft' ) === $stored->post_status, 'Migration published before authorized metadata completed.' );
				if ( $step >= 2 ) {
					wstm110_batch_require( 'C:\\migration\\' === get_post_meta( $migration_id, '_yoast_wpseo_metadesc', true ), 'Migration lost metadata or backslashes.' );
				}
				$record['steps'][] = array( 'ability' => $slug, 'result' => $result, 'hooks' => $events, 'status' => $stored->post_status );
				if ( null !== $transport ) {
					$record['steps'][ $step ]['wire'] = $transport->last_response;
				}
			}
			$record['passed'] = true;
			$summary['passed']++;
		} catch ( Throwable $error ) {
			$record['passed'] = false;
			$record['failure'] = $error->getMessage();
			$summary['failed']++;
		}
		$summary['migrations'][] = $record;
	}
	wstm110_batch_require( 8 * count( wstm110_batch_payloads() ) === count( $summary['cases'] ), 'Coverage loop did not execute every variant/entrypoint.' );
} catch ( Throwable $error ) {
	$summary['failed']++;
	$summary['fatal'] = $error->getMessage();
	echo "FAIL metadata batch setup: {$error->getMessage()}\n";
} finally {
	if ( $observer instanceof Closure ) {
		wstm110_batch_unobserve( $observer );
	}
	if ( $transport instanceof Wstm110_Metadata_Transport ) {
		wstm110_batch_cleanup( $summary, 'HTTP session', static function () use ( $transport ): void { $transport->close(); } );
	}
	if ( is_array( $password ) ) {
		wstm110_batch_cleanup( $summary, 'application password', static function () use ( $user_id, $password ): void {
			wstm110_batch_require( true === WP_Application_Passwords::delete_application_password( $user_id, $password[1]['uuid'] ), 'Cannot revoke metadata HTTP fixture credential.' );
		} );
	}
	if ( '' !== $token ) {
		delete_option( 'wstm110_batch_http' );
	}
	if ( is_int( $user_id ) && $user_id > 0 ) {
		wstm110_batch_cleanup( $summary, 'owned posts', static function () use ( $user_id, &$summary ): void {
			global $wpdb;
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d ORDER BY ID DESC", $user_id ) );
			wstm110_batch_require( '' === $wpdb->last_error && is_array( $ids ), 'Cannot enumerate owned fixture posts for cleanup.' );
			foreach ( $ids as $id ) {
				wstm110_batch_cleanup( $summary, "post {$id}", static function () use ( $id ): void {
					if ( get_post( $id ) ) {
						wstm110_batch_require( (bool) wp_delete_post( $id, true ), "Cannot delete owned post {$id}." );
					}
				} );
			}
		} );
		foreach ( $term_ids as $taxonomy => $term_id ) {
			wstm110_batch_cleanup( $summary, "term {$taxonomy}", static function () use ( $term_id, $taxonomy ): void {
				wstm110_batch_require( true === wp_delete_term( $term_id, $taxonomy ), "Cannot delete {$taxonomy} fixture term." );
			} );
		}
		wstm110_batch_cleanup( $summary, 'unique actor', static function () use ( $user_id ): void {
			wstm110_batch_require( wp_delete_user( $user_id ), 'Cannot remove unique QA actor.' );
		} );
	}
	wp_set_current_user( $old_user_id );
	$directory = dirname( __DIR__, 2 ) . '/e2e-artifacts';
	wstm110_batch_require( is_dir( $directory ) || mkdir( $directory, 0777, true ), 'Cannot create metadata-batch artifact directory.' );
	$json = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	$path = $directory . '/metadata-batch-' . $boundary . '.json';
	wstm110_batch_require( strlen( $json ) === file_put_contents( $path, $json ), 'Cannot persist complete metadata-batch evidence.' );
}
echo 'Metadata batch: ' . $summary['passed'] . ' passed, ' . $summary['failed'] . " failed\n";
exit( $summary['failed'] ? 1 : 0 );
