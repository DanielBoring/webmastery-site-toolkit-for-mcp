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
wstm110_batch_require( in_array( $boundary, array( 'direct', 'ability' ), true ), 'This runner currently supports only direct and ability boundaries; HTTP requires the transport fixture.' );
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
			$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
			$property->setAccessible( true );
			$callback = $property->getValue( $ability );
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
					$observer = wstm110_batch_observe( static function ( $hook ) use ( &$events ): void { $events[] = $hook; } );
					try {
						$raw = 'direct' === $boundary ? $callback( $input ) : $ability->execute( $input );
					} finally {
						wstm110_batch_unobserve( $observer );
						$observer = null;
					}
					$after = wstm110_batch_snapshot();
					$record['before'] = $before;
					$record['after'] = $after;
					$record['mutation_hooks'] = $events;
					$result = wstm110_batch_result( $raw );
					$record['result'] = $result;
					wstm110_batch_assert_unchanged( $before, $after, $events );
					wstm110_batch_require( false === ( $result['success'] ?? null ), 'Combined request did not fail.' );
					wstm110_batch_require( 'invalid_input' === ( $result['error']['code'] ?? null ), 'Combined request did not have canonical invalid_input code.' );
					$reasons = 'ability' === $boundary ? array( 'metadata_requires_separate_call', 'ability_invalid_input' ) : array( 'metadata_requires_separate_call' );
					wstm110_batch_require( in_array( $result['error']['reason'] ?? null, $reasons, true ), 'Wrong combined-request reason.' );
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
