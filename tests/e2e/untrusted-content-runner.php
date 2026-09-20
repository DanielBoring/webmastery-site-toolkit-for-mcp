<?php
/**
 * Real WordPress + actual MCP gateway/individual #108 proof, never a mock.
 *
 * Existing disposable site only. Install untrusted-content-fixture.php and the
 * shared error-contract-fixture.php as MU fixtures. Enable the companion HTTP
 * fixture in disposable wp-config.php: define( 'WSTM108_ALLOW_DISPOSABLE', true );
 *
 * WSTM108_ALLOW_DISPOSABLE=1 php tests/e2e/untrusted-content-runner.php
 * WSTM108_ARTIFACT optionally selects the summary path; <path>.http.jsonl keeps
 * credential-redacted raw responses, including malformed and failed responses.
 * This runner never provisions Docker, installs providers, or changes site roles.
 * Adapter 0.6.1 runtime proof is PENDING until this command actually succeeds.
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM108_ALLOW_DISPOSABLE' ) ) {
	fwrite( STDERR, "Refusing: WSTM108_ALLOW_DISPOSABLE=1 is required before WordPress, fixtures, credentials, or writes.\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
require_once __DIR__ . '/error-contract-assertions.php';
if ( ! function_exists( 'wstm108_assert' ) ) {
	require_once __DIR__ . '/untrusted-content-fixture.php';
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function wstm108_case( string $label, callable $action, array &$summary, Wstm108_Evidence $evidence, string $artifact ) {
	$case = array( 'label' => $label );
	$result = null;
	try {
		$result = $action( $case );
		$case['passed'] = true;
		++$summary['passed'];
	} catch ( Throwable $error ) {
		$case['passed'] = false;
		$case['error'] = $error->getMessage();
		++$summary['failed'];
	}
	$summary['cases'][] = $case;
	$evidence->save( $artifact, $summary );
	echo ( $case['passed'] ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	return $result;
}

function wstm108_success( Wstm108_Transport $client, string $name, array $input, array &$case ): array {
	$result = $client->execute( 'webmastery-site-toolkit-for-mcp/' . $name, $input );
	$case['response'] = $result;
	wstm108_assert( true === ( $result['success'] ?? null ), 'Expected success.' );
	wstm108_assert( array( 'success', 'data' ) === array_keys( $result ), 'Success envelope shape changed.' );
	wstm108_assert( is_array( $result['data'] ), 'Expected object data.' );
	return $result['data'];
}

function wstm108_find_record( array $items, int $id, string $key = 'id' ): array {
	$matches = array_values( array_filter( $items, static fn( $item ) => $id === (int) ( $item[ $key ] ?? 0 ) ) );
	wstm108_assert( 1 === count( $matches ), 'Owned fixture record absent or duplicated.' );
	return $matches[0];
}

function wstm108_refresh( int $id ): void {
	clean_post_cache( $id );
}

function wstm108_observe_http( string $method, string $url, Wstm108_Evidence $evidence ) {
	try {
		$response = 'HEAD' === $method
			? wp_remote_head( $url, array( 'timeout' => 5 ) )
			: wp_remote_get( $url, array( 'timeout' => 5 ) );
	} catch ( Throwable $error ) {
		$evidence->append( array( 'boundary' => 'wordpress-oracle', 'method' => $method, 'url' => $url, 'status' => 0, 'body' => '', 'transport_error' => $error->getMessage() ) );
		throw $error;
	}
	$error = is_wp_error( $response );
	$evidence->append( array(
		'boundary' => 'wordpress-oracle', 'method' => $method, 'url' => $url,
		'status' => $error ? 0 : wp_remote_retrieve_response_code( $response ),
		'body' => $error ? $response->get_error_message() : wp_remote_retrieve_body( $response ),
		'transport_error' => $error,
	) );
	return $response;
}

/**
 * Model pre-existing serialized provider data, not a supported metadata write.
 * Real providers often sanitize new array writes to "". Preserve their hooks and
 * seed only this run's owned row directly; every subsequent read still passes
 * through the real permission/ability/MCP stack with no authorization overrides.
 */
function wstm108_seed_legacy_map( int $id, string $key, string $run ): void {
	global $wpdb;
	$post = get_post( $id );
	wstm108_assert( $post && 0 === strpos( $post->post_name, $run . '-' ), 'Legacy fixture must belong to this run.' );
	update_post_meta( $id, $key, 'WSTM108 legacy stored map' );
	$meta_id = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1", $id, $key ) );
	wstm108_assert( $meta_id > 0, 'Cannot locate owned legacy metadata row.' );
	$written = $wpdb->update( $wpdb->postmeta, array( 'meta_value' => maybe_serialize( wstm108_nested() ) ), array( 'meta_id' => (int) $meta_id, 'post_id' => $id, 'meta_key' => $key ), array( '%s' ), array( '%d', '%d', '%s' ) );
	wstm108_assert( 1 === $written, 'Cannot seed the owned legacy metadata map.' );
	wp_cache_delete( $id, 'post_meta' );
	wstm108_assert( wstm108_nested() === get_post_meta( $id, $key, true ), 'Legacy map fixture did not round-trip through real core storage.' );
}

function wstm108_provider_keys( string $provider ): array {
	$yoast = array(
		'title' => '_yoast_wpseo_title', 'meta_description' => '_yoast_wpseo_metadesc',
		'focus_keyphrase' => '_yoast_wpseo_focuskw', 'canonical_url' => '_yoast_wpseo_canonical',
		'breadcrumb_title' => '_yoast_wpseo_bctitle', 'schema_page_type' => '_yoast_wpseo_schema_page_type',
		'schema_article_type' => '_yoast_wpseo_schema_article_type', 'opengraph_title' => '_yoast_wpseo_opengraph-title',
		'opengraph_description' => '_yoast_wpseo_opengraph-description', 'opengraph_image' => '_yoast_wpseo_opengraph-image',
		'twitter_title' => '_yoast_wpseo_twitter-title', 'twitter_description' => '_yoast_wpseo_twitter-description',
		'twitter_image' => '_yoast_wpseo_twitter-image', 'seo_score' => '_yoast_wpseo_linkdex',
		'readability_score' => '_yoast_wpseo_content_score', 'inclusive_language_score' => '_yoast_wpseo_inclusive_language_score',
		'primary_category' => '_yoast_wpseo_primary_category', 'cornerstone' => '_yoast_wpseo_is_cornerstone',
		'robots_noindex' => '_yoast_wpseo_meta-robots-noindex', 'robots_nofollow' => '_yoast_wpseo_meta-robots-nofollow',
		'robots_advanced' => '_yoast_wpseo_meta-robots-adv',
	);
	$seopress = array(
		'title' => '_seopress_titles_title', 'meta_description' => '_seopress_titles_desc',
		'focus_keywords' => '_seopress_analysis_target_kw', 'canonical_url' => '_seopress_robots_canonical',
		'opengraph_title' => '_seopress_social_fb_title', 'opengraph_description' => '_seopress_social_fb_desc',
		'opengraph_image' => '_seopress_social_fb_img', 'twitter_title' => '_seopress_social_twitter_title',
		'twitter_description' => '_seopress_social_twitter_desc', 'twitter_image' => '_seopress_social_twitter_img',
		'primary_category' => '_seopress_robots_primary_cat', 'robots_noindex' => '_seopress_robots_index',
		'robots_nofollow' => '_seopress_robots_follow', 'robots_noimageindex' => '_seopress_robots_imageindex',
		'robots_noarchive' => '_seopress_robots_archive', 'robots_nosnippet' => '_seopress_robots_snippet',
		'breadcrumb_title' => '_seopress_robots_breadcrumbs', 'news_sitemap_disabled' => '_seopress_news_disabled',
		'video_sitemap_disabled' => '_seopress_video_disabled',
	);
	return 'yoast' === $provider ? $yoast : $seopress;
}

function wstm108_unavailable(): array {
	return array(
		'code' => 'forbidden', 'reason' => 'metadata_not_readable',
		'message' => 'This metadata field is unavailable under the effective key-level permission.',
		'details' => array(),
	);
}

/**
 * Availability is observed separately through a standalone key read. Expected
 * record values/shapes still come only from core storage and this frozen oracle.
 * No auth hooks are replaced or permissions granted to force a passing result.
 */
function wstm108_expected_provider( int $id, string $provider, array $readable ): array {
	$p = get_post( $id );
	$metadata = array();
	$raw = array();
	$unavailable = array();
	foreach ( wstm108_provider_keys( $provider ) as $field => $key ) {
		if ( ! ( $readable[ $key ] ?? false ) ) {
			$unavailable[ $field ] = wstm108_unavailable();
			continue;
		}
		$value = get_post_meta( $id, $key, true );
		$normalized = '' === $value ? null : $value;
		if ( '' !== $value && in_array( $field, array( 'seo_score', 'readability_score', 'inclusive_language_score', 'primary_category' ), true ) ) {
			$normalized = (int) $value;
		}
		if ( '' !== $value && in_array( $field, array( 'cornerstone', 'robots_noindex', 'robots_nofollow', 'robots_noimageindex', 'robots_noarchive', 'robots_nosnippet', 'news_sitemap_disabled', 'video_sitemap_disabled' ), true ) ) {
			$normalized = rest_sanitize_boolean( $value );
		}
		$metadata[ $field ] = $normalized;
		$raw[ $field ] = array( 'key' => $key, 'value' => $value );
	}
	$result = array(
		$provider . '_active' => true, 'post_id' => $id, 'post_type' => $p->post_type,
		'title' => $p->post_title, 'url' => get_permalink( $id ),
		'metadata' => $metadata, 'raw_meta' => $raw, 'unavailable_fields' => $unavailable,
	);
	if ( 'yoast' === $provider ) {
		$result['generated_head'] = array( 'available' => false, 'error' => array(
			'code' => 'unsupported', 'reason' => 'key_authorization_unavailable',
			'message' => 'Opaque generated head output cannot enforce per-key authorization.', 'details' => array(),
		) );
	}
	return $result;
}

function wstm108_expected_metrics( int $id, array $readable, array $providers ): array {
	$p = get_post( $id );
	$fields = array(
		'yoast_meta_description' => '_yoast_wpseo_metadesc', 'seopress_meta_description' => '_seopress_titles_desc',
		'yoast_focus_keyword' => '_yoast_wpseo_focuskw', 'seopress_focus_keywords' => '_seopress_analysis_target_kw',
	);
	$data = array( 'title' => $p->post_title, 'url' => get_permalink( $id ), 'word_count' => str_word_count( wp_strip_all_tags( $p->post_content ) ), 'title_length' => mb_strlen( $p->post_title ) );
	foreach ( $fields as $field => $key ) {
		if ( $readable[ $key ] ?? false ) {
			$data[ $field ] = get_post_meta( $id, $key, true );
		}
	}
	foreach ( array( 'meta' => array( 'yoast_meta_description', 'seopress_meta_description' ), 'focus' => array( 'yoast_focus_keyword', 'seopress_focus_keywords' ) ) as $kind => $pair ) {
		$first = $data[ $pair[0] ] ?? '';
		$second = $data[ $pair[1] ] ?? '';
		if ( '' !== $first || '' !== $second || ( array_key_exists( $pair[0], $data ) && array_key_exists( $pair[1], $data ) ) ) {
			$data[ 'seo_provider_' . $kind . '_source' ] = '' !== $first ? 'yoast' : ( '' !== $second ? 'seopress' : null );
		}
	}
	$data['seo_plugins'] = array( 'yoast_active' => $providers['yoast'], 'seopress_active' => $providers['seopress'] );
	// The fixture intentionally contains no img or a elements.
	$data['images_without_alt'] = 0;
	$data['internal_links'] = 0;
	$data['external_links'] = 0;
	$data['slug'] = $p->post_name;
	return $data;
}

$run = 'wstm108-' . bin2hex( random_bytes( 8 ) );
$artifact = getenv( 'WSTM108_ARTIFACT' ) ?: __DIR__ . '/../../e2e-artifacts/untrusted-content-' . $run . '.json';
wstm108_assert( is_dir( dirname( $artifact ) ) || wp_mkdir_p( dirname( $artifact ) ), 'Cannot create evidence directory.' );
$evidence = new Wstm108_Evidence( $artifact . '.http.jsonl' );
$summary = array(
	'run' => $run, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'runner_sha256' => hash_file( 'sha256', __FILE__ ), 'fixture_sha256' => hash_file( 'sha256', __DIR__ . '/untrusted-content-fixture.php' ),
	'status' => 'in_progress', 'passed' => 0, 'failed' => 0, 'cases' => array(), 'cleanup' => array(), 'blocked' => array(),
	'raw_http' => basename( $artifact ) . '.http.jsonl',
);
$clients = array();
$users = array();
$passwords = array();
$owned_posts = array();
$owned_comments = array();
$owned_files = array();
$old_user = get_current_user_id();
$evidence->save( $artifact, $summary );

try {
	wstm108_assert( post_type_exists( 'wstm108_record' ), 'Install the opted-in companion MU fixture before running.' );
	wstm108_assert( function_exists( 'wp_get_abilities' ) && class_exists( 'WP_Application_Passwords' ), 'Core Abilities API and application passwords are required.' );
	foreach ( get_plugins() as $path => $plugin ) {
		if ( false !== strpos( $path, 'mcp-adapter' ) ) {
			$summary['adapter'] = array( 'plugin' => $path, 'version' => $plugin['Version'] );
		}
	}
	wstm108_assert( '0.6.1' === ( $summary['adapter']['version'] ?? null ), 'This proof targets the actual installed Adapter 0.6.1.' );
	$summary['providers'] = array(
		'yoast' => defined( 'WPSEO_VERSION' ) || defined( 'WPSEO_FILE' ) || class_exists( 'WPSEO_Options' ) || function_exists( 'wpseo_init' ),
		'seopress' => defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) || class_exists( 'SEOPress' ) || function_exists( 'seopress_activation' ) || in_array( 'wp-seopress/seopress.php', (array) get_option( 'active_plugins', array() ), true ),
	);
	$payload = wstm108_payload();
	$content = '<!-- wp:paragraph ' . wp_json_encode( array( 'wstm108' => wstm108_nested(), 'untrusted_fields' => array( 'literal stored block attribute' ) ) ) . ' --><p>' . esc_html( $payload ) . '</p><!-- /wp:paragraph -->';
	foreach ( array( 'administrator', 'subscriber', 'reader' ) as $role ) {
		$login = $run . '-' . $role;
		wstm108_assert( false === username_exists( $login ), 'Unique actor name unexpectedly exists.' );
		$id = wp_insert_user( array(
			'user_login' => $login, 'user_pass' => wp_generate_password( 40 ), 'user_email' => $login . '@example.test',
			'role' => 'reader' === $role ? 'subscriber' : $role, 'display_name' => $payload,
			'user_nicename' => $login, 'user_url' => 'https://example.test/' . $run . '?literal=%22%5C%E9%9B%AA',
		) );
		wstm108_assert( ! is_wp_error( $id ) && $id > 0, 'Cannot create unique proof actor.' );
		$users[ $role ] = (int) $id;
		if ( 'administrator' === $role ) {
			// Core may require the explicit primitive as well as object ownership.
			( new WP_User( $id ) )->add_cap( 'edit_post_meta' );
		}
		if ( 'reader' === $role ) {
			// Per-owned-user capability only; no global role mutation or auth bypass.
			( new WP_User( $id ) )->add_cap( 'list_users' );
		}
		update_user_meta( $id, 'last_login', $payload );
		$created = WP_Application_Passwords::create_new_application_password( $id, array( 'name' => $run . ' ' . $payload ) );
		wstm108_assert( ! is_wp_error( $created ), 'Cannot create owned application password.' );
		$passwords[ $id ] = $created[1]['uuid'];
		$evidence->secret( $created[0] );
		foreach ( array( false, true ) as $individual ) {
			$client = new Wstm108_Transport( $individual, $login, $created[0], $evidence, $role );
			$clients[ $client->label ] = $client;
			$client->initialize();
			$summary['catalogs'][ $client->label ] = $client->tools;
		}
		unset( $created );
	}
	wp_set_current_user( $users['administrator'] );
	$gateway = $clients['gateway:administrator'];
	$individual = $clients['individual:administrator'];
	wstm108_assert( 3 === count( $gateway->tools ), 'Default gateway catalog is expected to contain exactly three adapter tools, not individual plugin annotations.' );

	foreach ( wp_get_abilities() as $ability ) {
		$name = $ability->get_name();
		if ( 0 !== strpos( $name, 'webmastery-site-toolkit-for-mcp/' ) || false !== strpos( $name, '/wstm118-' ) ) {
			continue;
		}
		$registered = array(
			'name' => $name, 'label' => $ability->get_label(), 'description' => $ability->get_description(),
			'input_schema' => $ability->get_input_schema(), 'output_schema' => $ability->get_output_schema(),
			'meta' => $ability->get_meta(),
		);
		$summary['registered'][ $name ] = $registered;
		wstm108_case( 'annotations:' . $name, static function ( &$case ) use ( $individual, $gateway, $registered, $name ): void {
			$tool = $individual->descriptor( $name );
			$case['descriptor'] = $tool;
			foreach ( array( 'readonly' => 'readOnlyHint', 'destructive' => 'destructiveHint', 'idempotent' => 'idempotentHint' ) as $source => $wire ) {
				wstm108_assert( is_bool( $registered['meta']['annotations'][ $source ] ?? null ), 'Registered annotation missing.' );
				wstm108_assert( $registered['meta']['annotations'][ $source ] === ( $tool['annotations'][ $wire ] ?? null ), 'Emitted ' . $wire . ' changed.' );
			}
			wstm108_assert( trim( $registered['label'] ) === ( $tool['title'] ?? null ), 'Emitted title changed.' );
			wstm108_assert( ( $registered['meta']['annotations']['title'] ?? trim( $registered['label'] ) ) === ( $tool['annotations']['title'] ?? null ), 'Emitted annotation title changed.' );
			$info = $gateway->info( $name );
			$case['get_info'] = $info;
			$wire = $info['result']['structuredContent'] ?? json_decode( $info['result']['content'][0]['text'] ?? '', true, 512, JSON_THROW_ON_ERROR );
			wstm108_assert( $name === ( $wire['name'] ?? null ), 'Gateway get-info lost ability identity.' );
			wstm108_assert( wstm108_canonical( $registered['meta']['annotations'] ) === wstm108_canonical( $wire['meta']['annotations'] ?? null ), 'Gateway get-info changed registered annotations.' );
		}, $summary, $evidence, $artifact );
	}

	// Discover the dynamic CPT ability names from the actual list-post-types result.
	$types_case = array();
	$types = wstm108_success( $gateway, 'list-post-types', array(), $types_case );
	$cpt = array_values( array_filter( $types['items'], static fn( $item ) => 'wstm108_record' === ( $item['name'] ?? $item['slug'] ?? '' ) ) );
	wstm108_assert( 1 === count( $cpt ), 'Owned CPT not discoverable through list-post-types.' );
	$cpt_names = $cpt[0]['abilities'];
	$post_ids = array();
	foreach ( array( 'post', 'page', 'wstm108_record' ) as $type ) {
		$id = wp_insert_post( wp_slash( array(
			'post_type' => $type, 'post_status' => 'draft', 'post_title' => $run . ' ' . $payload,
			'post_content' => $content, 'post_excerpt' => $payload, 'post_name' => $run . '-' . $type,
			'post_author' => $users['administrator'],
		) ), true );
		wstm108_assert( ! is_wp_error( $id ) && $id > 0, 'Cannot seed owned content.' );
		$owned_posts[] = (int) $id;
		$post_ids[ $type ] = (int) $id;
		update_post_meta( $id, 'wstm108_payload', wp_slash( wstm108_nested() ) );
		wp_save_post_revision( $id );
	}
	$post_id = $post_ids['post'];
	$comment_id = wp_insert_comment( wp_slash( array(
		'comment_post_ID' => $post_id, 'comment_content' => '<p>' . esc_html( $payload ) . '</p>',
		'comment_author' => $payload, 'comment_author_email' => $run . '@example.test',
		'comment_author_url' => 'https://example.test/' . $run, 'comment_approved' => '1', 'user_id' => $users['administrator'],
	) ) );
	wstm108_assert( $comment_id > 0, 'Cannot seed comment.' );
	$owned_comments[] = (int) $comment_id;
	$upload = wp_upload_dir();
	wstm108_assert( ! $upload['error'], 'No writable disposable upload directory.' );
	$file = $upload['basedir'] . '/' . $run . '-雪.png';
	wstm108_assert( ! file_exists( $file ), 'Owned image path collision.' );
	$owned_files[] = $file;
	wstm108_assert( false !== file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=', true ) ), 'Cannot write owned image.' );
	$media_id = wp_insert_attachment( wp_slash( array(
		'post_title' => $run . ' ' . $payload, 'post_excerpt' => $payload, 'post_mime_type' => 'image/png',
		'post_status' => 'inherit', 'post_author' => $users['administrator'],
	) ), $file, $post_id, true );
	wstm108_assert( ! is_wp_error( $media_id ) && $media_id > 0, 'Cannot seed media.' );
	$owned_posts[] = (int) $media_id;
	update_post_meta( $media_id, '_wp_attachment_image_alt', wp_slash( $payload ) );
	wp_update_attachment_metadata( $media_id, array( 'width' => 1, 'height' => 1, 'file' => basename( $file ) ) );

	// Seed only owned post metadata. Existing provider registrations/auth remain intact.
	foreach ( array( 'yoast', 'seopress' ) as $provider ) {
		foreach ( wstm108_provider_keys( $provider ) as $field => $key ) {
			$value = in_array( $field, array( 'seo_score', 'readability_score', 'inclusive_language_score', 'primary_category' ), true ) ? '42' : $payload;
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	foreach ( array( 'gateway', 'individual' ) as $boundary ) {
		$client = $clients[ $boundary . ':administrator' ];
		wp_set_current_user( $users['administrator'] );
		foreach ( $post_ids as $type => $id ) {
			$names = 'wstm108_record' === $type ? array_map( static fn( $name ) => substr( $name, strlen( 'webmastery-site-toolkit-for-mcp/' ) ), $cpt_names ) : array( 'get' => 'get-' . $type, 'list' => 'list-' . ( 'post' === $type ? 'posts' : 'pages' ), 'create' => 'create-' . $type, 'update' => 'update-' . $type );
			foreach ( array( 'get', 'list', 'update', 'create' ) as $operation ) {
				wstm108_case( "{$boundary}:{$type}:{$operation}", static function ( &$case ) use ( $client, $id, $type, $operation, $names, $run, $content, $payload, &$owned_posts ): void {
					$id_key = 'wstm108_record' === $type ? 'id' : $type . '_id';
					$input = 'list' === $operation ? array( 'search' => $run, 'per_page' => 100 ) : array( $id_key => $id );
					if ( 'create' === $operation ) {
						$input = array( 'title' => $run . ' ' . $payload, 'content' => $content, 'excerpt' => $payload, 'status' => 'draft', 'slug' => $run . '-created-' . $type );
					} elseif ( 'update' === $operation ) {
						$input['content'] = $content;
						$input['excerpt'] = $payload;
					}
					$data = wstm108_success( $client, $names[ $operation ], $input, $case );
					$target = 'create' === $operation ? (int) ( $data['id'] ?? 0 ) : $id;
					if ( 'create' === $operation && $target ) {
						$owned_posts[] = $target;
					}
					wstm108_assert( $target > 0, 'Create response lacks owned ID.' );
					wstm108_refresh( $target );
					$actual = 'list' === $operation ? wstm108_find_record( $data['items'], $id ) : $data;
					$expected = wstm108_expected_post( $target );
					$case['expected'] = $expected;
					wstm108_compare_record( $actual, $expected, 'post' );
				}, $summary, $evidence, $artifact );
			}
		}
		wstm108_case( "{$boundary}:blocks", static function ( &$case ) use ( $client, $post_id ): void {
			$data = wstm108_success( $client, 'list-content-blocks', array( 'content_id' => $post_id, 'content_type' => 'post' ), $case );
			wstm108_refresh( $post_id );
			$expected = wstm108_expected_blocks( get_post_field( 'post_content', $post_id ) );
			$case['expected'] = $expected;
			wstm108_assert( count( $expected ) === count( $data['blocks'] ), 'Block count changed.' );
			foreach ( $expected as $i => $record ) {
				wstm108_compare_record( $data['blocks'][ $i ], $record, 'block' );
			}
		}, $summary, $evidence, $artifact );
		wstm108_case( "{$boundary}:patch-content-block", static function ( &$case ) use ( $client, $post_id, $content ): void {
			wstm108_refresh( $post_id );
			$before = get_post_field( 'post_content', $post_id );
			$blocks = wstm108_expected_blocks( $before );
			$data = wstm108_success( $client, 'patch-content-block', array(
				'content_id' => $post_id, 'content_type' => 'post', 'target_type' => 'block_path',
				'block_path' => '0', 'replacement_content' => $content,
				'expected_block_hash' => $blocks[0]['hash'], 'expected_content_hash' => hash( 'sha256', $before ),
			), $case );
			wstm108_refresh( $post_id );
			$after = get_post_field( 'post_content', $post_id );
			$after_blocks = wstm108_expected_blocks( $after );
			wstm108_compare_record( $data, array(
				'id' => $post_id, 'type' => 'post', 'target' => array( 'type' => 'block_path', 'block_path' => '0' ),
				'block_hash_before' => $blocks[0]['hash'], 'block_hash_after' => $after_blocks[0]['hash'],
				'content_hash_before' => hash( 'sha256', $before ), 'content_hash_after' => hash( 'sha256', $after ),
				'content' => $after,
			), 'content-patch' );
		}, $summary, $evidence, $artifact );
		foreach ( array( 'exact', 'heading' ) as $target_type ) {
			wstm108_case( "{$boundary}:patch-post-content:{$target_type}", static function ( &$case ) use ( $client, $boundary, $target_type, $run, $payload, $content, &$owned_posts ): void {
				$body = '<!-- wp:heading {"level":2} --><h2>' . esc_html( $payload ) . '</h2><!-- /wp:heading -->' . $content;
				$id = wp_insert_post( wp_slash( array(
					'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $run . ' patch fixture',
					'post_name' => $run . '-patch-' . $boundary . '-' . $target_type, 'post_content' => $body,
				) ), true );
				wstm108_assert( ! is_wp_error( $id ) && $id > 0, 'Cannot create owned patch target.' );
				$owned_posts[] = (int) $id;
				$before = get_post_field( 'post_content', $id );
				$input = array(
					'post_id' => $id, 'content_type' => 'page', 'target_type' => $target_type,
					'replacement_content' => $content, 'expected_content_hash' => hash( 'sha256', $before ),
				);
				$expected_target = array( 'type' => $target_type );
				if ( 'heading' === $target_type ) {
					$input['heading_text'] = $payload;
					$expected_target['heading_text'] = $payload;
					$expected_target['heading_level'] = 2;
				} else {
					$input['old_content'] = $before;
					$input['replacement_content'] = $before;
				}
				$data = wstm108_success( $client, 'patch-post-content', $input, $case );
				wstm108_refresh( $id );
				$expected_post = wstm108_expected_post( $id );
				wstm108_compare_record( $data['target'], $expected_target, 'patch-target' );
				wstm108_compare_record( $data['post'], $expected_post, 'post' );
				unset( $data['target']['untrusted_fields'], $data['post']['untrusted_fields'] );
				$expected = array(
					'id' => $id, 'type' => 'page', 'target' => $expected_target,
					'replaced_blocks' => 'heading' === $target_type ? 1 : null,
					'content_hash_before' => hash( 'sha256', $before ),
					'content_hash_after' => hash( 'sha256', $expected_post['content'] ), 'post' => $expected_post,
				);
				$case['expected'] = $expected;
				wstm108_assert( wstm108_canonical( $expected ) === wstm108_canonical( $data ), 'Full patch response shape changed beyond the two explicit markers.' );
			}, $summary, $evidence, $artifact );
		}
		foreach ( array( 'post', 'page' ) as $type ) {
			$id = $post_ids[ $type ];
			wstm108_case( "{$boundary}:{$type}:revisions", static function ( &$case ) use ( $client, $id ): void {
				$data = wstm108_success( $client, 'list-revisions', array( 'post_id' => $id, 'per_page' => 100 ), $case );
				wstm108_assert( ! empty( $data['revisions'] ), 'No real revisions were created.' );
				foreach ( $data['revisions'] as $revision ) {
					wstm108_compare_record( $revision, wstm108_expected_revision( $revision['id'] ), 'revision' );
				}
				$restored = wstm108_success( $client, 'restore-revision', array( 'revision_id' => $data['revisions'][0]['id'] ), $case );
				wstm108_refresh( $id );
				wstm108_compare_record( $restored['revision'], wstm108_expected_revision( $data['revisions'][0]['id'] ), 'revision' );
				wstm108_compare_record( $restored['post'], wstm108_expected_post( $id ), 'post' );
			}, $summary, $evidence, $artifact );
		}
		foreach ( array( 'list', 'get', 'update' ) as $operation ) {
			wstm108_case( "{$boundary}:media:{$operation}", static function ( &$case ) use ( $client, $operation, $media_id, $run, $payload ): void {
				$input = 'list' === $operation ? array( 'search' => $run, 'per_page' => 100 ) : array( 'media_id' => (int) $media_id );
				if ( 'update' === $operation ) {
					$input['caption'] = $payload;
					$input['alt_text'] = $payload;
				}
				$data = wstm108_success( $client, $operation . '-media', $input, $case );
				wstm108_refresh( (int) $media_id );
				$expected = wstm108_expected_media( (int) $media_id );
				$case['expected'] = $expected;
				wstm108_compare_record( 'list' === $operation ? wstm108_find_record( $data['items'], (int) $media_id ) : $data, $expected, 'media' );
			}, $summary, $evidence, $artifact );
		}
		foreach ( array( 'list', 'reply', 'update' ) as $operation ) {
			wstm108_case( "{$boundary}:comments:{$operation}", static function ( &$case ) use ( $client, $boundary, $operation, $post_id, $comment_id, $payload, &$owned_comments ): void {
				$input = 'list' === $operation ? array( 'post_id' => $post_id, 'per_page' => 100 ) : array( 'comment_id' => (int) $comment_id, 'content' => '<p>' . esc_html( $operation . ' ' . $boundary . ' ' . $payload ) . '</p>' );
				$data = wstm108_success( $client, $operation . ( 'list' === $operation ? '-comments' : '-comment' ), $input, $case );
				$id = 'reply' === $operation ? (int) $data['id'] : (int) $comment_id;
				if ( 'reply' === $operation ) {
					$owned_comments[] = $id;
				}
				clean_comment_cache( $id );
				$expected = wstm108_expected_comment( $id );
				$case['expected'] = $expected;
				wstm108_compare_record( 'list' === $operation ? wstm108_find_record( $data['items'], $id ) : $data, $expected, 'comment' );
			}, $summary, $evidence, $artifact );
		}
		foreach ( array( 'administrator' => true, 'reader' => false ) as $actor => $private ) {
			foreach ( array( 'list', 'get' ) as $operation ) {
				wstm108_case( "{$boundary}:user:{$actor}:{$operation}", static function ( &$case ) use ( $clients, $boundary, $actor, $private, $operation, $users, $run ): void {
					$input = 'list' === $operation ? array( 'search' => $run, 'per_page' => 100 ) : array( 'user_id' => $users['administrator'] );
					$data = wstm108_success( $clients[ $boundary . ':' . $actor ], $operation . ( 'list' === $operation ? '-users' : '-user' ), $input, $case );
					$expected = wstm108_expected_user( $users['administrator'], $private );
					$case['expected'] = $expected;
					wstm108_compare_record( 'list' === $operation ? wstm108_find_record( $data['items'], $users['administrator'] ) : $data, $expected, 'user' );
				}, $summary, $evidence, $artifact );
			}
		}
		wstm108_case( "{$boundary}:user-access-audit", static function ( &$case ) use ( $client, $users, $passwords, $payload ): void {
			$data = wstm108_success( $client, 'user-access-audit', array(), $case );
			$id = $users['administrator'];
			clean_user_cache( $id );
			$u = get_userdata( $id );
			$account = wstm108_find_record( $data['admin_accounts'], $id );
			wstm108_compare_record( $account, array( 'id' => $id, 'login' => $u->user_login, 'email' => $u->user_email, 'registered' => $u->user_registered, 'last_login' => $payload ), 'account' );
			$entry = WP_Application_Passwords::get_user_application_password( $id, $passwords[ $id ] );
			$last = $entry['last_used'] ?? null;
			$app = wstm108_find_record( $data['application_passwords'], $id, 'user_id' );
			wstm108_compare_record( $app, array( 'user_id' => $id, 'user_login' => $u->user_login, 'app_name' => $entry['name'], 'last_used' => is_numeric( $last ) ? gmdate( 'c', (int) $last ) : ( $last ?: null ) ), 'application' );
		}, $summary, $evidence, $artifact );

		wstm108_case( "{$boundary}:standalone-meta-read-update", static function ( &$case ) use ( $client, $post_id, $boundary ): void {
			wstm108_refresh( $post_id );
			$before = get_post_meta( $post_id, 'wstm108_payload', true );
			$data = wstm108_success( $client, 'get-post-meta', array( 'post_id' => $post_id, 'meta_key' => 'wstm108_payload' ), $case );
			wstm108_compare_record( $data, array( 'post_id' => $post_id, 'meta' => array( 'wstm108_payload' => array( $before ) ) ), 'meta' );
			$value = wstm108_nested();
			$value['boundary'] = $boundary;
			$data = wstm108_success( $client, 'update-post-meta', array( 'post_id' => $post_id, 'meta_key' => 'wstm108_payload', 'meta_value' => $value ), $case );
			wstm108_compare_record( $data, array( 'post_id' => $post_id, 'meta_key' => 'wstm108_payload', 'updated' => true, 'previous_value' => $before, 'current_value' => $value ), 'meta-update' );
			wstm108_refresh( $post_id );
			wstm108_assert( $value === get_post_meta( $post_id, 'wstm108_payload', true ), 'Stored map changed or lost nested literal untrusted_fields.' );
		}, $summary, $evidence, $artifact );
		wstm108_case( "{$boundary}:standalone-meta-delete", static function ( &$case ) use ( $client, $post_id, $boundary ): void {
			$key = 'wstm108_delete_' . $boundary;
			add_post_meta( $post_id, $key, wp_slash( wstm108_nested() ) );
			add_post_meta( $post_id, $key, wp_slash( wstm108_payload() ) );
			$before = get_post_meta( $post_id, $key, false );
			wstm108_assert( 2 === count( $before ), 'Owned delete fixture rows missing.' );
			$data = wstm108_success( $client, 'delete-post-meta', array( 'post_id' => $post_id, 'meta_key' => $key ), $case );
			$expected = array( 'post_id' => $post_id, 'meta_key' => $key, 'deleted_count' => 2 );
			$case['expected'] = $expected;
			wstm108_compare_record( $data, $expected, 'meta-delete' );
			wstm108_refresh( $post_id );
			wstm108_assert( array() === get_post_meta( $post_id, $key, false ), 'Deleted owned metadata remains.' );
		}, $summary, $evidence, $artifact );

		// Seed legacy maps only after content mutations, avoiding provider indexers
		// interpreting those deliberately adversarial historical values as new input.
		foreach ( array( 'yoast', 'seopress' ) as $provider ) {
			$keys = wstm108_provider_keys( $provider );
			foreach ( array( 'opengraph_title', 'twitter_title' ) as $field ) {
				wstm108_seed_legacy_map( $post_id, $keys[ $field ], $run );
			}
		}
		$readable = array();
		foreach ( array_unique( array_merge( array_values( wstm108_provider_keys( 'yoast' ) ), array_values( wstm108_provider_keys( 'seopress' ) ) ) ) as $key ) {
			wstm108_case( "{$boundary}:seo-key-policy:{$key}", static function ( &$case ) use ( $client, $post_id, $key, &$readable ): void {
				$result = $client->execute( 'webmastery-site-toolkit-for-mcp/get-post-meta', array( 'post_id' => $post_id, 'meta_key' => $key ) );
				$case['response'] = $result;
				$readable[ $key ] = true === $result['success'];
				if ( ! $readable[ $key ] ) {
					wstm118_error_envelope( $result );
					wstm108_assert( 'forbidden' === $result['error']['code'], 'Unexpected SEO key error.' );
					return;
				}
				wstm108_compare_record( $result['data'], array( 'post_id' => $post_id, 'meta' => array( $key => array( get_post_meta( $post_id, $key, true ) ) ) ), 'meta' );
			}, $summary, $evidence, $artifact );
		}
		wstm108_case( "{$boundary}:seo-metrics", static function ( &$case ) use ( $client, $post_id, $readable, &$summary ): void {
			$data = wstm108_success( $client, 'seo-analyze-post', array( 'post_id' => $post_id ), $case );
			$expected = wstm108_expected_metrics( $post_id, $readable, $summary['providers'] );
			$case['expected_metrics'] = $expected;
			wstm108_compare_record( $data['metrics'], $expected, 'metrics' );
			foreach ( array_merge( $data['issues'], $data['good'] ) as $finding ) {
				wstm108_assert( false === strpos( $finding['message'], 'INERT TEST DATA' ), 'Stored SEO data was interpolated into an assessment message.' );
			}
		}, $summary, $evidence, $artifact );
		foreach ( array( 'yoast', 'seopress' ) as $provider ) {
			wstm108_case( "{$boundary}:{$provider}:metadata", static function ( &$case ) use ( $client, $post_id, $provider, $readable, &$summary ): void {
				$data = wstm108_success( $client, 'get-' . $provider . '-metadata', array( 'post_id' => $post_id ), $case );
				if ( ! $summary['providers'][ $provider ] ) {
					wstm108_assert( false === ( $data[ $provider . '_active' ] ?? null ) && ! isset( $data['metadata'], $data['untrusted_fields'] ), 'Inactive provider emitted content.' );
					$summary['blocked'][ $provider ] = 'Real provider is not installed/active; active-provider proof remains pending.';
					return;
				}
				$expected = wstm108_expected_provider( $post_id, $provider, $readable );
				$case['expected'] = $expected;
				wstm108_compare_record( $data, $expected, 'provider' );
				$keys = wstm108_provider_keys( $provider );
				wstm108_assert( ! empty( $readable[ $keys['opengraph_title'] ] ), 'Real provider nested-map key is not authorized; cannot prove preservation.' );
				wstm108_assert( wstm108_nested() === $data['metadata']['opengraph_title'], 'Nested provider data did not survive storage and transport.' );
			}, $summary, $evidence, $artifact );
		}
		foreach ( array( 'get-seo-scores' => '_yoast_wpseo_linkdex', 'get-readability-scores' => '_yoast_wpseo_content_score' ) as $name => $key ) {
			wstm108_case( "{$boundary}:{$name}", static function ( &$case ) use ( $client, $name, $key, $post_id, &$summary ): void {
				$data = wstm108_success( $client, $name, array( 'post_type' => 'post', 'status' => 'draft', 'per_page' => 100 ), $case );
				if ( ! $summary['providers']['yoast'] ) {
					wstm108_assert( array() === $data['items'] && false === $data['yoast_active'], 'Inactive scores emitted content.' );
					return;
				}
				$p = get_post( $post_id );
				$actual = wstm108_find_record( $data['items'], $post_id, 'post_id' );
				wstm108_compare_record( $actual, array( 'post_id' => $post_id, 'title' => $p->post_title, 'url' => get_permalink( $post_id ), 'post_type' => 'post', 'modified_gmt' => $p->post_modified_gmt, 'score' => (int) get_post_meta( $post_id, $key, true ) ), 'score' );
			}, $summary, $evidence, $artifact );
		}
		foreach ( array(
			'yoast_meta_description' => array( 'yoast', '_yoast_wpseo_metadesc' ),
			'seopress_meta_description' => array( 'seopress', '_seopress_titles_desc' ),
			'yoast_focus_keyword' => array( 'yoast', '_yoast_wpseo_focuskw' ),
			'seopress_focus_keywords' => array( 'seopress', '_seopress_analysis_target_kw' ),
		) as $field => list( $provider, $key ) ) {
			wstm108_case( "{$boundary}:seo-forbidden-read-trap:{$field}", static function ( &$case ) use ( $client, $post_id, $readable, $provider, $field, $key, &$summary ): void {
				$client->deny_read( $post_id, $key );
				try {
					$data = wstm108_success( $client, 'seo-analyze-post', array( 'post_id' => $post_id ), $case );
					$restricted = $readable;
					$restricted[ $key ] = false;
					wstm108_compare_record( $data['metrics'], wstm108_expected_metrics( $post_id, $restricted, $summary['providers'] ), 'metrics' );
					wstm108_assert( wstm108_unavailable() === $data['unavailable_fields'][ $field ], 'Unavailable field policy changed.' );
					if ( $summary['providers'][ $provider ] ) {
						$data = wstm108_success( $client, 'get-' . $provider . '-metadata', array( 'post_id' => $post_id ), $case );
						wstm108_compare_record( $data, wstm108_expected_provider( $post_id, $provider, $restricted ), 'provider' );
					}
				} finally {
					$client->deny_read();
				}
			}, $summary, $evidence, $artifact );
		}
		wstm108_case( "{$boundary}:no-opaque-head", static function ( &$case ) use ( $client, $post_id ): void {
			$result = $client->execute( 'webmastery-site-toolkit-for-mcp/get-yoast-metadata', array( 'url' => get_permalink( $post_id ) ) );
			$case['response'] = $result;
			wstm118_error_envelope( $result );
			wstm108_assert( 'generated_head_key_authorization_unavailable' === $result['error']['reason'], 'Opaque head policy was restored or changed.' );
		}, $summary, $evidence, $artifact );
		wstm108_case( "{$boundary}:no-combined-seo-alias-write", static function ( &$case ) use ( $client, $post_id ): void {
			wstm108_refresh( $post_id );
			$before = get_post_meta( $post_id );
			$result = $client->execute( 'webmastery-site-toolkit-for-mcp/update-post', array( 'post_id' => $post_id, 'yoast_title' => 'MUST NOT WRITE' ) );
			$case['response'] = $result;
			wstm118_error_envelope( $result );
			wstm108_assert( 'metadata_requires_separate_call' === $result['error']['reason'], 'Combined alias-write policy changed.' );
			wstm108_refresh( $post_id );
			wstm108_assert( $before === get_post_meta( $post_id ), 'Denied combined alias mutated metadata.' );
		}, $summary, $evidence, $artifact );
		wstm108_case( "{$boundary}:private-image-url-still-denied", static function ( &$case ) use ( $client ): void {
			$result = $client->execute( 'webmastery-site-toolkit-for-mcp/upload-image', array( 'image_url' => 'http://127.0.0.1/wstm108-must-not-fetch.png' ) );
			$case['response'] = $result;
			wstm118_error_envelope( $result );
			wstm108_assert( 'invalid_url' === $result['error']['reason'], 'Private-address image safety boundary changed.' );
		}, $summary, $evidence, $artifact );
		wstm108_case( "{$boundary}:seo-site-overview-records", static function ( &$case ) use ( $client, $evidence ): void {
			// Independently observe real disposable-site endpoints, without replacing
			// HTTP responses, changing home/siteurl, or trusting marker-derived values.
			$sitemap_url = home_url( '/sitemap_index.xml' );
			$robots_url = home_url( '/robots.txt' );
			$sitemap_head = wstm108_observe_http( 'HEAD', $sitemap_url, $evidence );
			$sitemap_ok = ! is_wp_error( $sitemap_head ) && 200 === wp_remote_retrieve_response_code( $sitemap_head );
			$expected_sitemap = array( 'url' => $sitemap_url, 'accessible' => $sitemap_ok );
			if ( $sitemap_ok ) {
				$body = wp_remote_retrieve_body( wstm108_observe_http( 'GET', $sitemap_url, $evidence ) );
				preg_match_all( '/<loc>(.*?)<\/loc>/i', $body, $matches );
				$expected_sitemap['entries'] = array_values( array_map( 'esc_url_raw', $matches[1] ?? array() ) );
				$expected_sitemap['entry_count'] = count( $expected_sitemap['entries'] );
			}
			$robots_head = wstm108_observe_http( 'HEAD', $robots_url, $evidence );
			$expected_robots = array( 'url' => $robots_url, 'accessible' => ! is_wp_error( $robots_head ) && 200 === wp_remote_retrieve_response_code( $robots_head ) );
			$case['expected'] = array( 'sitemap' => $expected_sitemap, 'robots_txt' => $expected_robots );
			$data = wstm108_success( $client, 'seo-site-overview', array(), $case );
			wstm108_compare_record( $data['sitemap'], $expected_sitemap, 'sitemap' );
			wstm108_compare_record( $data['robots_txt'], $expected_robots, 'robots' );
		}, $summary, $evidence, $artifact );

		$denials = array(
			array( 'get-post', array( 'post_id' => $post_id ) ), array( 'update-post', array( 'post_id' => $post_id, 'title' => 'must not write' ) ),
			array( 'get-page', array( 'page_id' => $post_ids['page'] ) ), array( substr( $cpt_names['get'], strlen( 'webmastery-site-toolkit-for-mcp/' ) ), array( 'id' => $post_ids['wstm108_record'] ) ),
			array( 'get-media', array( 'media_id' => (int) $media_id ) ), array( 'update-comment', array( 'comment_id' => (int) $comment_id, 'content' => 'must not write' ) ),
			array( 'get-user', array( 'user_id' => $users['administrator'] ) ), array( 'user-access-audit', array() ),
			array( 'get-post-meta', array( 'post_id' => $post_id, 'meta_key' => 'wstm108_payload' ) ),
			array( 'seo-analyze-post', array( 'post_id' => $post_id ) ), array( 'get-yoast-metadata', array( 'post_id' => $post_id ) ),
			array( 'get-seopress-metadata', array( 'post_id' => $post_id ) ),
			array( 'delete-post-meta', array( 'post_id' => $post_id, 'meta_key' => 'wstm108_payload' ) ),
			array( 'seo-site-overview', array() ),
		);
		foreach ( $denials as list( $name, $input ) ) {
			wstm108_case( "{$boundary}:subscriber-denied:{$name}", static function ( &$case ) use ( $clients, $boundary, $users, $name, $input, $post_id, $comment_id ): void {
				wstm108_refresh( $post_id );
				clean_comment_cache( $comment_id );
				$before = array( get_post( $post_id )->to_array(), (array) get_comment( $comment_id ) );
				wp_set_current_user( $users['subscriber'] );
				try {
					$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $name );
					$denied = $ability->check_permissions( $input );
					wstm108_assert( is_wp_error( $denied ), 'Core permission oracle unexpectedly allowed subscriber.' );
					$expected = wstm118_error_envelope( $denied );
					$result = $clients[ $boundary . ':subscriber' ]->execute( 'webmastery-site-toolkit-for-mcp/' . $name, $input );
					$case['expected'] = $expected;
					$case['response'] = $result;
					wstm108_assert( json_encode( $expected ) === json_encode( $result ), 'HTTP denial differs from unchanged canonical permission error.' );
					wstm108_assert( 'forbidden' === $result['error']['code'] && ! isset( $result['untrusted_fields'] ), 'Denial taxonomy or marker changed.' );
					wstm108_refresh( $post_id );
					clean_comment_cache( $comment_id );
					wstm108_assert( $before === array( get_post( $post_id )->to_array(), (array) get_comment( $comment_id ) ), 'Denied call mutated owned records.' );
				} finally {
					wp_set_current_user( $users['administrator'] );
				}
			}, $summary, $evidence, $artifact );
		}
		foreach ( array( 'yoast', 'seopress' ) as $provider ) {
			$keys = wstm108_provider_keys( $provider );
			foreach ( array( 'opengraph_title', 'twitter_title' ) as $field ) {
				update_post_meta( $post_id, $keys[ $field ], wp_slash( $payload ) );
			}
		}
	}
} catch ( Throwable $error ) {
	++$summary['failed'];
	$summary['fatal'] = $error->getMessage();
} finally {
	$actions = array();
	foreach ( $clients as $label => $client ) {
		$actions[ 'session:' . $label ] = static fn() => $client->close();
	}
	foreach ( $passwords as $id => $uuid ) {
		$actions[ 'password:' . $id ] = static function () use ( $id, $uuid ): void {
			$result = WP_Application_Passwords::delete_application_password( $id, $uuid );
			wstm108_assert( ! is_wp_error( $result ), 'Application password revocation failed.' );
			wstm108_assert( null === WP_Application_Passwords::get_user_application_password( $id, $uuid ), 'Application password still exists.' );
		};
	}
	// Recover IDs even when create succeeded server-side but its response was invalid.
	// Every queried author is a unique user created by this run, never a shared actor.
	try {
		foreach ( $users as $id ) {
			$owned_posts = array_merge( $owned_posts, get_posts( array( 'author' => $id, 'post_type' => array( 'post', 'page', 'wstm108_record', 'attachment', 'revision' ), 'post_status' => array_values( get_post_stati() ), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
		}
		foreach ( array_unique( $owned_posts ) as $id ) {
			// Include spam/trash too, even if a malformed response lost a new reply ID.
			global $wpdb;
			$comment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d", $id ) );
			wstm108_assert( '' === $wpdb->last_error, 'Owned comment recovery query failed.' );
			$owned_comments = array_merge( $owned_comments, array_map( 'intval', $comment_ids ) );
			$owned_posts = array_merge( $owned_posts, array_keys( wp_get_post_revisions( $id ) ) );
		}
	} catch ( Throwable $error ) {
		++$summary['failed'];
		$summary['cleanup'][] = array( 'label' => 'owned-id-recovery', 'passed' => false, 'error' => $error->getMessage() );
	}
	foreach ( array_unique( $owned_comments ) as $id ) {
		$actions[ 'comment:' . $id ] = static function () use ( $id ): void {
			if ( get_comment( $id ) ) {
				wp_delete_comment( $id, true );
			}
			clean_comment_cache( $id );
			wstm108_assert( null === get_comment( $id ), 'Owned comment remains.' );
		};
	}
	$owned_posts = array_unique( array_map( 'intval', $owned_posts ) );
	rsort( $owned_posts );
	foreach ( $owned_posts as $id ) {
		$actions[ 'post:' . $id ] = static function () use ( $id ): void {
			if ( get_post( $id ) ) {
				'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
			}
			clean_post_cache( $id );
			wstm108_assert( null === get_post( $id ), 'Owned post, attachment, or revision remains.' );
		};
	}
	foreach ( $owned_files as $file ) {
		$actions[ 'file:' . basename( $file ) ] = static function () use ( $file ): void {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
			wstm108_assert( ! file_exists( $file ), 'Owned media file remains.' );
		};
	}
	foreach ( $users as $id ) {
		$actions[ 'user:' . $id ] = static function () use ( $id ): void {
			wp_delete_user( $id );
			clean_user_cache( $id );
			wstm108_assert( false === get_userdata( $id ), 'Owned user remains.' );
		};
	}
	wstm108_cleanup( $actions, $summary );
	wp_set_current_user( $old_user );
	$summary['status'] = $summary['failed'] ? 'failed' : ( $summary['blocked'] ? 'incomplete' : 'passed' );
	$evidence->save( $artifact, $summary );
}

echo "WSTM108 {$summary['status']}: {$summary['passed']} passed; {$summary['failed']} failed. Evidence: {$artifact}\n";
exit( 'passed' === $summary['status'] ? 0 : 1 );
