<?php
/**
 * Actual-core/provider SEO reads across direct, registered, gateway and individual execution.
 */

declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM110_BATCH_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Requires WSTM110_BATCH_DISPOSABLE=1 in an owned disposable WordPress runtime.' );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
require_once __DIR__ . '/metadata-batch-fixture.php';
require_once __DIR__ . '/error-contract-assertions.php';
require_once __DIR__ . '/metadata-transport.php';

function wstm110_seo_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$boundary = getenv( 'WSTM110_BATCH_BOUNDARY' ) ?: 'ability';
wstm110_seo_assert( in_array( $boundary, array( 'direct', 'ability', 'http', 'individual' ), true ), 'Unknown SEO read boundary.' );
$summary = array(
	'boundary' => $boundary, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'yoast' => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : null,
	'seopress' => defined( 'SEOPRESS_VERSION' ) ? SEOPRESS_VERSION : null,
	'runner_sha256' => hash_file( 'sha256', __FILE__ ),
	'production_sha256' => hash_file( 'sha256', __DIR__ . '/../../includes/class-seo.php' ),
	'passed' => 0, 'failed' => 0, 'cases' => array(),
);
$user_id = 0;
$post_ids = array();
$password = null;
$transport = null;
$token = '';
$calls = array();
$old_user = get_current_user_id();
$run = 'wstm110-seo-' . wp_generate_uuid4();
$record_case = static function ( string $label, callable $check ) use ( &$summary, &$calls ): void {
	$record = array( 'label' => $label );
	$calls = array();
	try {
		$check( $record );
		$record['passed'] = true;
		$summary['passed']++;
	} catch ( Throwable $error ) {
		$record['passed'] = false;
		$record['failure'] = $error->getMessage();
		$summary['failed']++;
		echo "FAIL {$label}: {$error->getMessage()}\n";
	} finally {
		$record['calls'] = $calls;
		delete_option( 'wstm110_seo_read_probe' );
		delete_option( 'wstm110_seo_read_events' );
		delete_option( 'wstm110_policy' );
		$summary['cases'][] = $record;
	}
};
try {
	wstm110_seo_assert( function_exists( 'wstm110_seo_fields' ) && function_exists( 'wstm110_setup' ), 'SEO and standalone authorization MU fixtures are required.' );
	wstm110_seo_assert( null !== $summary['yoast'] && null !== $summary['seopress'], 'This provider-policy lane requires both real providers active; inactive behavior has separate unit coverage.' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$user_id = wp_create_user( $run, wp_generate_password( 40 ), $run . '@example.test' );
	wstm110_seo_assert( is_int( $user_id ) && $user_id > 0, 'Cannot create unique SEO read actor.' );
	$user = new WP_User( $user_id );
	$user->set_role( 'administrator' );
	$user->add_cap( 'edit_post_meta' );
	wp_set_current_user( $user_id );
	if ( in_array( $boundary, array( 'http', 'individual' ), true ) ) {
		$password = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $run ) );
		wstm110_seo_assert( is_array( $password ), 'Cannot create SEO HTTP application password.' );
		$token = bin2hex( random_bytes( 32 ) );
		update_option( 'wstm110_batch_http', array( 'token' => $token, 'user_id' => $user_id ), false );
		$transport = new Wstm110_Metadata_Transport( 'individual' === $boundary, array( 'login' => $run, 'password' => $password[0] ) );
		$transport->initialize();
		$summary['tools'] = $transport->catalog();
	}
	$execute = static function ( string $slug, array $input ) use ( $boundary, $transport, $token, &$calls ): array {
		$name = 'webmastery-site-toolkit-for-mcp/' . $slug;
		$call = array( 'ability' => $name, 'input' => $input, 'before' => wstm110_batch_snapshot() );
		$events = array();
		$observer = null;
		try {
			if ( null !== $transport ) {
				$result = $transport->execute( $name, $input, $token );
			} else {
				$observer = wstm110_batch_observe( static function ( $hook ) use ( &$events ): void { $events[] = $hook; } );
				$ability = wp_get_ability( $name );
				wstm110_seo_assert( null !== $ability, "Missing {$name}." );
				if ( 'direct' === $boundary ) {
					$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
					$property->setAccessible( true );
					$result = ( $property->getValue( $ability ) )( $input );
				} else {
					$result = $ability->execute( array() === $input && ! $ability->get_input_schema() ? null : $input );
				}
			}
			$result = is_wp_error( $result ) ? wstm118_error_envelope( $result ) : $result;
			$call['result'] = $result;
		} finally {
			if ( $observer instanceof Closure ) {
				wstm110_batch_unobserve( $observer );
			}
			if ( null !== $transport ) {
				wp_cache_flush();
				$events = $transport->last_events;
				$call['wire'] = $transport->last_response;
				$call['tool_name'] = $transport->tool_name( $name );
			}
			$call['mutation_hooks'] = $events;
			$call['after'] = wstm110_batch_snapshot();
			$calls[] = $call;
		}
		wstm110_batch_assert_unchanged( $call['before'], $call['after'], $events );
		return $result;
	};
	foreach ( array( 'post', 'page' ) as $type ) {
		$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $run, 'post_content' => 'Read-only fixture', 'post_status' => 'draft', 'post_author' => $user_id ), true );
		wstm110_seo_assert( is_int( $id ) && $id > 0, 'Cannot create SEO read fixture.' );
		$post_ids[] = $id;
		update_post_meta( $id, 'wstm110_gate', 'ready' );
		foreach ( wstm110_seo_fields() as $slug => $fields ) {
			foreach ( $fields as $field => $key ) {
				update_post_meta( $id, $key, 'WSTM110_INERT_FORBIDDEN_MARKER' );
				foreach ( array( 'deny_map', 'deny_user', 'allowed' ) as $policy ) {
					$record_case( "{$type}:{$slug}:{$key}:{$policy}", static function ( array &$record ) use ( $id, $slug, $field, $key, $policy, $execute, $transport ): void {
						$config = array( 'id' => $id, 'key' => $key );
						if ( 'allowed' !== $policy ) {
							$config[ $policy ] = true;
						}
						update_option( 'wstm110_policy', $config, false );
						$allowed = current_user_can( 'edit_post', $id ) && current_user_can( 'edit_post_meta', $id, $key );
						if ( 'allowed' !== $policy ) {
							wstm110_seo_assert( ! $allowed, 'Effective capability denial fixture failed.' );
						} else {
							wstm110_seo_assert( $allowed, 'Explicit primitive grant must exercise an allowed real-key control.' );
						}
						$record['effective_permission'] = $allowed;
						$stored = get_post_meta( $id, $key, true );
						update_option( 'wstm110_seo_read_probe', array( 'id' => $id, 'key' => $key, 'forbidden' => ! $allowed ), false );
						delete_option( 'wstm110_seo_read_events' );
						$before = wstm110_batch_snapshot();
						$result = $execute( $slug, array( 'post_id' => $id ) );
						$record['result'] = $result;
						$record['before'] = $before;
						$record['after'] = wstm110_batch_snapshot();
						$record['reads'] = get_option( 'wstm110_seo_read_events', array() );
						if ( null !== $transport ) {
							$record['wire'] = $transport->last_response;
							$record['tool_name'] = $transport->tool_name( 'webmastery-site-toolkit-for-mcp/' . $slug );
						}
						wstm110_seo_assert( $record['before'] === $record['after'], 'SEO read changed stored post/meta/term/cron state.' );
						wstm110_seo_assert( true === ( $result['success'] ?? null ), 'SEO inspector failed instead of returning an authorized projection.' );
						if ( $allowed ) {
							wstm110_seo_assert( $stored === ( $result['data']['raw_meta'][ $field ]['value'] ?? null ), 'Allowed raw value changed or disappeared.' );
							wstm110_seo_assert( ! empty( $record['reads'] ), 'Allowed control did not exercise metadata read observer.' );
						} else {
							wstm110_seo_assert( array() === $record['reads'], 'Denied key was read.' );
							wstm110_seo_assert( ! array_key_exists( $field, $result['data']['metadata'] ) && ! array_key_exists( $field, $result['data']['raw_meta'] ), 'Denied normalized/raw field leaked.' );
							wstm110_seo_assert( 'metadata_not_readable' === ( $result['data']['unavailable_fields'][ $field ]['reason'] ?? null ), 'Denied field lacks explicit unavailability.' );
						}
						if ( 'get-yoast-metadata' === $slug ) {
							wstm110_seo_assert( false === $result['data']['generated_head']['available'], 'Opaque generated head became available.' );
						}
					} );
				}
			}
		}
		foreach ( array( '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_seopress_titles_desc', '_seopress_analysis_target_kw' ) as $key ) {
			$record_case( "{$type}:analysis:{$key}", static function ( array &$record ) use ( $id, $key, $execute ): void {
				update_option( 'wstm110_policy', array( 'id' => $id, 'key' => $key, 'deny_map' => true ), false );
				update_option( 'wstm110_seo_read_probe', array( 'id' => $id, 'key' => $key, 'forbidden' => true ), false );
				$result = $execute( 'seo-analyze-post', array( 'post_id' => $id ) );
				$record['result'] = $result;
				$record['reads'] = get_option( 'wstm110_seo_read_events', array() );
				wstm110_seo_assert( true === ( $result['success'] ?? null ) && array() === $record['reads'], 'Analysis read a denied key.' );
				wstm110_seo_assert( ! empty( $result['data']['unavailable_fields'] ), 'Analysis did not identify unavailable input.' );
			} );
		}
	}
	foreach ( array( 'get-seo-scores' => '_yoast_wpseo_linkdex', 'get-readability-scores' => '_yoast_wpseo_content_score' ) as $slug => $key ) {
		$record_case( "{$slug}:denied key excluded within bounded window", static function ( array &$record ) use ( $post_ids, $slug, $key, $execute ): void {
			$id = $post_ids[0];
			wstm110_seo_assert( $id === wp_update_post( array( 'ID' => $id, 'post_status' => 'pending' ), true ), 'Cannot prepare pending score fixture.' );
			$input = array( 'post_type' => 'post', 'status' => 'pending', 'per_page' => 100, 'page' => 1 );
			$baseline = $execute( $slug, $input );
			wstm110_seo_assert( true === ( $baseline['success'] ?? null ) && in_array( $id, array_column( $baseline['data']['items'], 'post_id' ), true ), 'Score control did not include newly modified authorized object.' );
			update_option( 'wstm110_policy', array( 'id' => $id, 'key' => $key, 'deny_map' => true ), false );
			update_option( 'wstm110_seo_read_probe', array( 'id' => $id, 'key' => $key, 'forbidden' => true ), false );
			$result = $execute( $slug, $input );
			$record['baseline'] = $baseline;
			$record['result'] = $result;
			$record['reads'] = get_option( 'wstm110_seo_read_events', array() );
			wstm110_seo_assert( true === ( $result['success'] ?? null ) && array() === $record['reads'], 'Score list read forbidden key.' );
			$expected_items = array_values( array_filter( $baseline['data']['items'], static fn( $item ) => $item['post_id'] !== $id ) );
			wstm110_seo_assert( $expected_items === $result['data']['items'], 'Score window membership/values changed beyond denied object/key.' );
			wstm110_seo_assert( ! in_array( $id, array_column( $result['data']['items'], 'post_id' ), true ), 'Score page contains denied object/key.' );
			wstm110_seo_assert( ! array_key_exists( 'total', $result['data'] ) && ! array_key_exists( 'total_pages', $result['data'] ), 'Score response exposes obsolete totals.' );
			wstm110_seo_assert( $baseline['data']['next_page'] === $result['data']['next_page'] && 1 === $result['data']['page'] && 100 === $result['data']['per_page'], 'Score continuation changed with authorization.' );
		} );
	}
	$record_case( 'overview authorized bounded observations', static function ( array &$record ) use ( $post_ids, $execute ): void {
		wstm110_seo_assert( $post_ids[0] === wp_update_post( array( 'ID' => $post_ids[0], 'post_status' => 'publish' ), true ), 'Cannot prepare published overview fixture.' );
		$candidates = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ) );
		wstm110_seo_assert( ! empty( $candidates ), 'Overview requires observed candidates.' );
		$denied_id = (int) $candidates[0];
		$denied_key = '_yoast_wpseo_focuskw';
		update_option( 'wstm110_policy', array( 'id' => $denied_id, 'key' => $denied_key, 'deny_map' => true ), false );
		update_option( 'wstm110_seo_read_probe', array( 'id' => $denied_id, 'key' => $denied_key, 'forbidden' => true ), false );
		$expected = array();
		foreach ( array(
			'posts_missing_focus_keyword' => '_yoast_wpseo_focuskw',
			'posts_missing_meta_description' => '_yoast_wpseo_metadesc',
			'seopress_posts_missing_focus_keywords' => '_seopress_analysis_target_kw',
			'seopress_posts_missing_meta_description' => '_seopress_titles_desc',
		) as $field => $key ) {
			$expected[ $field ] = array( 'count' => 0, 'ids' => array(), 'observed_count' => 0 );
			foreach ( $candidates as $id ) {
				if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'edit_post_meta', $id, $key ) ) {
					continue;
				}
				++$expected[ $field ]['observed_count'];
				if ( '' === get_post_meta( $id, $key, true ) ) {
					++$expected[ $field ]['count'];
					if ( count( $expected[ $field ]['ids'] ) < 20 ) {
						$expected[ $field ]['ids'][] = (int) $id;
					}
				}
			}
		}
		$result = $execute( 'seo-site-overview', array() );
		$record['result'] = $result;
		$record['expected'] = $expected;
		$record['reads'] = get_option( 'wstm110_seo_read_events', array() );
		wstm110_seo_assert( true === ( $result['success'] ?? null ) && array() === $record['reads'], 'Overview read forbidden metadata.' );
		wstm110_seo_assert( array( 'mode' => 'sample', 'post_limit' => 100, 'ordering' => 'ID ASC', 'counts_are_sitewide' => false ) === $result['data']['observation_scope'], 'Overview did not report the bounded sample contract.' );
		foreach ( $expected as $field => $observation ) {
			wstm110_seo_assert( $observation === $result['data'][ $field ], 'Overview observation mismatch: ' . $field );
		}
	} );
	$record_case( 'URL-only generated head rejected', static function ( array &$record ) use ( $execute, $transport ): void {
		$result = $execute( 'get-yoast-metadata', array( 'url' => home_url( '/' ) ) );
		$record['result'] = $result;
		if ( null !== $transport ) {
			$record['wire'] = $transport->last_response;
		}
		wstm118_error_envelope( $result );
		wstm110_seo_assert( 'generated_head_key_authorization_unavailable' === $result['error']['reason'], 'URL head request did not fail explicitly.' );
	} );
	wstm110_seo_assert( 252 === count( $summary['cases'] ), 'SEO runtime case inventory is incomplete.' );
} catch ( Throwable $error ) {
	$summary['failed']++;
	$summary['fatal'] = $error->getMessage();
	echo 'FAIL SEO setup: ' . $error->getMessage() . "\n";
} finally {
	$cleanup = static function ( string $label, callable $action ) use ( &$summary ): void {
		try {
			$action();
		} catch ( Throwable $error ) {
			$summary['failed']++;
			$summary['cleanup_errors'][] = array( 'resource' => $label, 'error' => $error->getMessage() );
		}
	};
	foreach ( array( 'wstm110_policy', 'wstm110_seo_read_probe', 'wstm110_seo_read_events' ) as $option ) {
		delete_option( $option );
	}
	if ( '' !== $token ) {
		delete_option( 'wstm110_batch_http' );
	}
	if ( $transport instanceof Wstm110_Metadata_Transport ) {
		$cleanup( 'HTTP session', static function () use ( $transport ): void { $transport->close(); } );
	}
	if ( is_array( $password ) ) {
		$cleanup( 'application password', static function () use ( $user_id, $password ): void {
			wstm110_seo_assert( true === WP_Application_Passwords::delete_application_password( $user_id, $password[1]['uuid'] ), 'Cannot revoke SEO fixture password.' );
		} );
	}
	foreach ( $post_ids as $id ) {
		$cleanup( "post {$id}", static function () use ( $id ): void { wstm110_seo_assert( (bool) wp_delete_post( $id, true ), 'Cannot delete owned SEO fixture.' ); } );
	}
	if ( is_int( $user_id ) && $user_id > 0 ) {
		$cleanup( 'actor', static function () use ( $user_id ): void { wstm110_seo_assert( wp_delete_user( $user_id ), 'Cannot remove SEO fixture actor.' ); } );
	}
	wp_set_current_user( $old_user );
	$directory = dirname( __DIR__, 2 ) . '/e2e-artifacts';
	wstm110_seo_assert( is_dir( $directory ) || mkdir( $directory, 0777, true ), 'Cannot create SEO evidence directory.' );
	$json = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	wstm110_seo_assert( strlen( $json ) === file_put_contents( $directory . '/seo-metadata-' . $boundary . '.json', $json ), 'Cannot persist complete SEO evidence.' );
}
echo "SEO metadata: {$summary['passed']} passed, {$summary['failed']} failed\n";
exit( $summary['failed'] ? 1 : 0 );
