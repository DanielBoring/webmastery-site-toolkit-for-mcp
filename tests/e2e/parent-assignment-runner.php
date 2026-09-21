<?php
/**
 * Real WordPress parent regression proof, identical for baseline and fixed runs.
 * Mutating fixture setup is CLI-only; never deploy this runner on a live site.
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
require_once __DIR__ . '/error-contract-assertions.php';


$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';

function wstm106_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wstm106_write( string $path, array $data ): void {
	$directory = dirname( $path );
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
		throw new RuntimeException( "Cannot create evidence directory: {$directory}" );
	}
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	wstm106_assert( strlen( $json ) === file_put_contents( $path, $json ), "Cannot write complete evidence: {$path}" );
}

function wstm106_post( string $type, int $author, int $parent = 0 ): int {
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_author'  => $author,
			'post_title'   => 'Parent QA original',
			'post_content' => 'Original content.',
			'post_excerpt' => 'Original excerpt.',
			'post_status'  => 'draft',
			'post_name'    => 'parent-original-' . wp_generate_uuid4(),
			'post_parent'  => $parent,
		),
		true
	);
	wstm106_assert( ! is_wp_error( $id ) && $id > 0, 'Cannot create fixture post.' );
	update_post_meta( $id, '_yoast_wpseo_metadesc', 'Original metadata.' );
	return $id;
}

function wstm106_snapshot( array $ids, string $slug ): array {
	global $wpdb;
	wp_cache_flush();
	$id_list = implode( ',', array_map( 'absint', $ids ) );
	// Fixture-only evidence includes all graph members, created rows, and revisions.
	$posts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID IN ({$id_list}) OR (post_type = 'revision' AND post_parent IN ({$id_list})) OR post_name = %s ORDER BY ID", $slug ), ARRAY_A );
	wstm106_assert( '' === $wpdb->last_error, 'Post snapshot query failed: ' . $wpdb->last_error );
	$id_list = implode( ',', array_map( 'absint', array_column( $posts, 'ID' ) ) );
	$result = array( 'posts' => $posts );
	foreach ( array( 'postmeta' => array( 'post_id', 'meta_id' ), 'term_relationships' => array( 'object_id', 'object_id, term_taxonomy_id' ) ) as $table => $fields ) {
		$result[ $table ] = $wpdb->get_results( "SELECT * FROM {$wpdb->$table} WHERE {$fields[0]} IN ({$id_list}) ORDER BY {$fields[1]}", ARRAY_A );
		wstm106_assert( '' === $wpdb->last_error, 'Related snapshot query failed: ' . $wpdb->last_error );
	}
	$result['cron'] = array();
	foreach ( _get_cron_array() as $time => $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			foreach ( $events as $key => $event ) {
				if ( 'publish_future_post' === $hook && in_array( (int) ( $event['args'][0] ?? 0 ), array_map( 'intval', array_column( $posts, 'ID' ) ), true ) ) {
					$result['cron'][ $time ][ $hook ][ $key ] = $event;
				}
			}
		}
	}
	return $result;
}

function wstm106_seed_loop( int $a, int $b ): void {
	global $wpdb;
	// Fixture-only corruption, NOT evidence that a normal API creates cycles.
	wstm106_assert( 1 === $wpdb->update( $wpdb->posts, array( 'post_parent' => $b ), array( 'ID' => $a ) ), 'Cannot seed corrupt graph.' );
	clean_post_cache( $a );
	wstm106_assert( (int) get_post( $b )->post_parent === $a, 'Corrupt loop second edge is missing.' );
}

function wstm106_http( string $method, array $params, string $username, string $password, string &$session, string $trace = '' ): array {
	$headers = array( 'Content-Type: application/json', 'Authorization: Basic ' . base64_encode( $username . ':' . $password ) );
	if ( $session ) {
		$headers[] = 'Mcp-Session-Id: ' . $session;
	}
	if ( $trace ) {
		$headers[] = 'X-WSTM106-Trace: ' . $trace;
	}
	$body = array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params );
	if ( 'notifications/initialized' !== $method ) {
		$body['id'] = 1;
	}
	$context = stream_context_create( array( 'http' => array(
		'method' => 'POST', 'header' => implode( "\r\n", $headers ),
		'content' => json_encode( $body, JSON_THROW_ON_ERROR ), 'ignore_errors' => true, 'timeout' => 45,
	) ) );
	$raw = file_get_contents( 'http://localhost/wp-json/mcp/mcp-adapter-default-server', false, $context );
	wstm106_assert( false !== $raw, 'HTTP request failed.' );
	$response_headers = $http_response_header;
	foreach ( $response_headers as $header ) {
		if ( stripos( $header, 'mcp-session-id:' ) === 0 ) {
			$session = trim( substr( $header, strlen( 'mcp-session-id:' ) ) );
		}
	}
	wstm106_assert( (bool) preg_match( '/^HTTP\/\S+ 2\d\d/', $response_headers[0] ), 'Unexpected HTTP status: ' . $response_headers[0] . ' ' . $raw );
	return array( 'headers' => array_values( array_filter( $response_headers, static fn( $h ) => stripos( $h, 'mcp-session-id:' ) !== 0 ) ), 'body' => $raw === '' ? null : json_decode( $raw, true, 512, JSON_THROW_ON_ERROR ) );
}

function wstm106_result( $raw ): array {
	if ( is_wp_error( $raw ) ) {
		return wstm118_error_envelope( $raw );
	}
	if ( isset( $raw['body'] ) ) {
		$body = $raw['body'];
		if ( isset( $body['error'] ) ) {
			return array( 'success' => false, 'error' => $body['error'] );
		}
		$tool = $body['result'] ?? array();
		if ( true === ( $tool['isError'] ?? false ) ) {
			return wstm118_wire_error( $tool );
		}
		if ( isset( $tool['structuredContent'] ) ) {
			$raw = $tool['structuredContent'];
		} else {
			$raw = null;
			foreach ( $tool['content'] ?? array() as $content ) {
				if ( 'text' === ( $content['type'] ?? '' ) ) {
					$decoded = json_decode( $content['text'], true );
					if ( is_array( $decoded ) ) {
						$raw = $decoded;
						break;
					}
				}
			}
			if ( null === $raw && ! empty( $tool['isError'] ) ) {
				return array( 'success' => false, 'error' => $tool );
			}
		}
		if ( isset( $raw['data']['success'] ) ) {
			$raw = $raw['data'];
		}
	}
	wstm106_assert( is_array( $raw ) && array_key_exists( 'success', $raw ), 'Unrecognized ability result.' );
	return $raw;
}

$mode = getenv( 'WSTM106_MODE' ) ?: 'fixed';
wstm106_assert( in_array( $mode, array( 'baseline', 'fixed' ), true ), 'Unknown proof mode.' );
$boundary_filter = getenv( 'WSTM106_BOUNDARY' ) ?: 'all';
wstm106_assert( in_array( $boundary_filter, array( 'all', 'direct', 'ability', 'http' ), true ), 'Unknown boundary.' );
$artifact = getenv( 'WSTM106_ARTIFACT' ) ?: __DIR__ . '/../../e2e-artifacts/parent-assignment-' . $boundary_filter . '.json';
$summary = array(
	'mode' => $mode, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'runner_sha256' => hash_file( 'sha256', __FILE__ ),
	'fixture_sha256' => hash_file( 'sha256', __DIR__ . '/parent-assignment-fixture.php' ),
	'production_sha256' => array(
		'posts' => hash_file( 'sha256', __DIR__ . '/../../includes/class-posts.php' ),
		'cpt' => hash_file( 'sha256', __DIR__ . '/../../includes/class-custom-post-types.php' ),
	),
	'passed' => 0, 'failed' => 0, 'cases' => array(),
);

try {
	wstm106_assert( post_type_exists( 'wstm106_default' ), 'Dedicated parent MU fixture is not installed.' );
	$allcaps = array( 'read' => true, 'edit_posts' => true, 'edit_pages' => true, 'publish_posts' => true, 'publish_pages' => true, 'assign_mcp_genres' => true );
	foreach ( array( 'page', 'wstm106_default', 'wstm106_mapped', 'wstm106_explicit', 'wstm106_primitive', 'mcp_book', 'mcp_case_study' ) as $type ) {
		$pto = get_post_type_object( $type );
		foreach ( (array) $pto->cap as $name => $cap ) {
			if ( ! str_contains( $name, 'others' ) ) {
				$allcaps[ $cap ] = true;
			}
		}
	}
	add_role( 'wstm106_actor', 'Parent QA limited actor', $allcaps );
	foreach ( $allcaps as $cap => $grant ) {
		get_role( 'wstm106_actor' )->add_cap( $cap, $grant );
	}
	$actors = array();
	foreach ( array( 'limited', 'other', 'author' ) as $name ) {
		$login = 'wstm106_' . $name;
		$user = get_user_by( 'login', $login );
		$id = $user ? $user->ID : wp_create_user( $login, wp_generate_password(), $login . '@example.test' );
		wstm106_assert( ! is_wp_error( $id ), 'Cannot create QA actor.' );
		$user = new WP_User( $id );
		$user->set_role( 'author' === $name ? 'author' : 'wstm106_actor' );
		$actors[ $name ] = (int) $id;
	}
	$token = wp_generate_password( 32, false );
	update_option( 'wstm106_trace_token', $token );
	delete_option( 'wstm106_cap_rule' );
	$credentials = array();
	$sessions = array();
	if ( in_array( $boundary_filter, array( 'all', 'http' ), true ) ) {
		foreach ( array( 'limited', 'author' ) as $actor ) {
			$password = WP_Application_Passwords::create_new_application_password( $actors[ $actor ], array( 'name' => 'Issue106 proof' ) );
			wstm106_assert( ! is_wp_error( $password ), 'Cannot create fixture application password.' );
			$credentials[ $actor ] = $password;
			$sessions[ $actor ] = '';
			wstm106_http( 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'issue106-proof', 'version' => '1' ) ), 'wstm106_' . $actor, $password[0], $sessions[ $actor ] );
			wstm106_assert( '' !== $sessions[ $actor ], 'No MCP session returned.' );
			wstm106_http( 'notifications/initialized', array(), 'wstm106_' . $actor, $password[0], $sessions[ $actor ] );
		}
	}
	$types = array( 'page', 'wstm106_default', 'wstm106_mapped', 'wstm106_explicit', 'wstm106_primitive', 'mcp_book', 'mcp_case_study', 'post' );
	$summary['registered_abilities'] = array_values( array_filter( array_keys( wp_get_abilities() ), static fn( $name ) => str_starts_with( $name, 'webmastery-site-toolkit-for-mcp/' ) ) );
	foreach ( $types as $type ) {
		$pto = get_post_type_object( $type );
		$hierarchical = (bool) $pto->hierarchical;
		$cases = $hierarchical
			? array( 'allowed', 'same', 'detach', 'omitted', 'inaccessible', 'missing', 'wrong_type', 'self', 'descendant', 'corrupt', 'deep', 'filter_deny', 'filter_allow', 'negative' )
			: array( 'positive_extra', 'detach', 'omitted' );
		if ( 'page' === $type ) {
			$cases[] = 'author_denied';
			$cases[] = 'meta_precedence';
			$cases[] = 'publish_precedence';
		}
		if ( 'mcp_book' === $type ) {
			$cases[] = 'taxonomy_precedence';
		}
		$deep = 0;
		if ( $hierarchical ) {
			wp_set_current_user( $actors['limited'] );
			for ( $depth = 0; $depth < 260; $depth++ ) {
				$deep = wstm106_post( $type, $depth === 259 ? $actors['limited'] : $actors['other'], $deep );
			}
		}
		foreach ( array( 'direct', 'ability', 'http' ) as $boundary ) {
			if ( 'all' !== $boundary_filter && $boundary !== $boundary_filter ) {
				continue;
			}
			foreach ( array( 'create', 'update' ) as $operation ) {
				foreach ( $cases as $case ) {
					if ( 'create' === $operation && in_array( $case, array( 'same', 'self', 'descendant', 'omitted' ), true ) ) {
						// Omitted create is still exercised for nonhierarchical input compatibility.
						if ( 'omitted' !== $case ) {
							continue;
						}
					}
					$label = "{$boundary}:{$operation}:{$type}:{$case}";
					$record = array( 'label' => $label, 'boundary' => $boundary, 'operation' => $operation, 'type' => $type, 'case' => $case );
					try {
						wp_set_current_user( $actors['limited'] );
						delete_option( 'wstm106_cap_rule' );
						$parent = wstm106_post( $type, $actors['limited'] );
						$target = wstm106_post( $type, $actors['limited'], $parent );
						// Start published so core's draft date-clearing behavior (#113) is not this test's subject.
						wstm106_assert( $target === wp_update_post( array( 'ID' => $target, 'post_status' => 'publish' ), true ), 'Cannot publish target fixture.' );
						$proposed = $parent;
						$edit_cap = 'page' === $type ? 'edit_post' : $pto->cap->edit_post;
						$actor = 'author_denied' === $case ? 'author' : 'limited';
						if ( in_array( $case, array( 'inaccessible', 'filter_allow', 'omitted' ), true ) ) {
							wp_update_post( array( 'ID' => $parent, 'post_author' => $actors['other'] ) );
						}
						if ( 'allowed' === $case ) {
							$proposed = wstm106_post( $type, $actors['limited'] );
						} elseif ( 'missing' === $case ) {
							$proposed = 2147483647;
						} elseif ( 'wrong_type' === $case ) {
							$proposed = wstm106_post( 'post', $actors['limited'] );
						} elseif ( 'self' === $case ) {
							$proposed = $target;
						} elseif ( 'descendant' === $case ) {
							$proposed = wstm106_post( $type, $actors['limited'], $target );
						} elseif ( 'corrupt' === $case ) {
							$loop_child = wstm106_post( $type, $actors['limited'], $parent );
							wstm106_seed_loop( $parent, $loop_child );
							$record['fixture_only_corrupt_edges'] = array( $parent => $loop_child, $loop_child => $parent );
						} elseif ( 'detach' === $case ) {
							$proposed = 0;
						} elseif ( 'deep' === $case ) {
							$proposed = $deep;
							$record['ancestry_length'] = 260;
						} elseif ( 'negative' === $case ) {
							$proposed = -$parent;
						}
						if ( in_array( $case, array( 'filter_allow', 'filter_deny' ), true ) || ( 'inaccessible' === $case && 'wstm106_primitive' === $type ) ) {
							update_option( 'wstm106_cap_rule', array( 'cap' => $edit_cap, 'id' => $parent, 'allow' => 'filter_allow' === $case ) );
						}
						$input = array(
							'title' => 'Changed title', 'content' => '<p>Changed content.</p>', 'excerpt' => 'Changed excerpt.',
							'slug' => 'changed-' . wp_generate_uuid4(), 'status' => 'future', 'scheduled_date' => '2035-01-02T03:04:05', 'parent' => $proposed,
						);
						if ( 'omitted' === $case ) {
							unset( $input['parent'] );
						}
						if ( in_array( $type, array( 'page', 'post' ), true ) ) {
							if ( 'meta_precedence' === $case ) {
								$input['meta'] = array( '_wstm106_unregistered' => 'Not writable' );
								$input['parent'] = $target;
							}
							if ( 'publish_precedence' === $case ) {
								update_option( 'wstm106_cap_rule', array( 'cap' => 'publish_pages', 'id' => 0, 'allow' => false ) );
								$input['parent'] = $target;
							}
						} else {
							$taxonomy = str_starts_with( $type, 'wstm106_' ) ? $type . '_tag' : ( 'mcp_book' === $type ? 'mcp_genre' : '' );
							if ( $taxonomy ) {
								$term = wp_insert_term( 'Parent proof ' . wp_generate_uuid4(), $taxonomy );
								wstm106_assert( ! is_wp_error( $term ), 'Cannot create taxonomy fixture.' );
								( new WP_User( $actors['limited'] ) )->add_cap( get_taxonomy( $taxonomy )->cap->assign_terms );
								$input['taxonomy_terms'] = array( $taxonomy => array( $term['term_id'] ) );
							}
							if ( 'taxonomy_precedence' === $case ) {
								$input['taxonomy_terms'] = array( 'wstm106_missing_taxonomy' => array( 1 ) );
								$input['parent'] = $target;
							}
						}
						$ability_name = 'webmastery-site-toolkit-for-mcp/' . $operation . '-' . ( in_array( $type, array( 'page', 'post' ), true ) ? $type : 'cpt-' . str_replace( '_', '-', $type ) );
						if ( 'update' === $operation ) {
							$input[ in_array( $type, array( 'page', 'post' ), true ) ? $type . '_id' : 'id' ] = $target;
						}
						wp_set_current_user( 0 );
						wp_set_current_user( $actors[ $actor ] );
						$record['input'] = $input;
						$record['actor'] = $actor;
						$record['edit_cap'] = $edit_cap;
						$record['target_id'] = $target;
						$scope = array( $target => true, $parent => true );
						$cursor = absint( $proposed );
						$walked = array();
						while ( $cursor && ! isset( $walked[ $cursor ] ) ) {
							$walked[ $cursor ] = true;
							$scope[ $cursor ] = true;
							$ancestor = get_post( $cursor );
							$cursor = $ancestor ? (int) $ancestor->post_parent : 0;
						}
						$record['before'] = wstm106_snapshot( array_keys( $scope ), $input['slug'] );
						$GLOBALS['wstm106_hooks'] = array();
						$GLOBALS['wstm106_observing'] = true;
						if ( 'http' === $boundary ) {
							$GLOBALS['wstm106_observing'] = false;
							if ( file_exists( '/tmp/wstm106-http-hooks.json' ) ) {
								wstm106_assert( unlink( '/tmp/wstm106-http-hooks.json' ), 'Cannot remove previous HTTP trace.' );
							}
							$raw = wstm106_http( 'tools/call', array( 'name' => 'mcp-adapter-execute-ability', 'arguments' => array( 'ability_name' => $ability_name, 'parameters' => $input ) ), 'wstm106_' . $actor, $credentials[ $actor ][0], $sessions[ $actor ], $token );
							// Response flush can precede PHP shutdown; bounded wait is only for evidence delivery.
							for ( $attempt = 0; $attempt < 100 && ! file_exists( '/tmp/wstm106-http-hooks.json' ); $attempt++ ) {
								usleep( 10000 );
							}
							wstm106_assert( file_exists( '/tmp/wstm106-http-hooks.json' ), 'HTTP observation did not run.' );
							$record['hooks'] = json_decode( file_get_contents( '/tmp/wstm106-http-hooks.json' ), true, 512, JSON_THROW_ON_ERROR );
						} else {
							$ability = wp_get_ability( $ability_name );
							wstm106_assert( null !== $ability, 'Fixture ability was not registered.' );
							if ( 'direct' === $boundary ) {
								$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
								$property->setAccessible( true );
								$raw = ( $property->getValue( $ability ) )( $input );
							} else {
								$raw = $ability->execute( $input );
							}
							$record['hooks'] = $GLOBALS['wstm106_hooks'];
						}
						$GLOBALS['wstm106_observing'] = false;
						$record['result'] = wstm106_result( $raw );
						$record['raw'] = is_wp_error( $raw ) ? $record['result'] : $raw;
						$record['after'] = wstm106_snapshot( array_keys( $scope ), $input['slug'] );
						$record['unchanged'] = $record['before'] === $record['after'];
						$denial = in_array( $case, array( 'inaccessible', 'missing', 'wrong_type', 'self', 'descendant', 'corrupt', 'filter_deny', 'author_denied', 'meta_precedence', 'publish_precedence', 'taxonomy_precedence' ), true )
							|| ( 'positive_extra' === $case && 'post' !== $type );
						$existing_denial = in_array( $case, array( 'author_denied', 'meta_precedence', 'publish_precedence', 'taxonomy_precedence' ), true )
							|| ( 'create' === $operation && ( in_array( $case, array( 'inaccessible', 'filter_deny' ), true ) || ( 'missing' === $case && 'wstm106_primitive' !== $type ) ) );
						$record['expected_fixed_denial'] = $denial;
						if ( $denial && ( 'fixed' === $mode || $existing_denial ) ) {
							wstm106_assert( false === $record['result']['success'], 'Unsafe assignment was not rejected.' );
							wstm118_error_envelope( $record['result'] );
							if ( 'meta_precedence' === $case ) {
								wstm106_assert( 'metadata_requires_separate_call' === $record['result']['error']['reason'], 'Combined metadata must be rejected before parent validation.' );
							}
							wstm106_assert( $record['unchanged'], 'Rejected payload changed stored rows/relationships/revisions/metadata/cron.' );
							wstm106_assert( array() === $record['hooks'], 'Rejected payload reached pre-write/write hooks.' );
						} elseif ( ! $denial ) {
							wstm106_assert( true === $record['result']['success'], 'Positive control was rejected.' );
							$written_id = 'update' === $operation ? $target : (int) $record['result']['data']['id'];
							$written = get_post( $written_id );
							$expected_parent = 'post' === $type ? ( 'update' === $operation ? $parent : 0 ) : ( 'omitted' === $case ? ( 'update' === $operation ? $parent : 0 ) : absint( $proposed ) );
							wstm106_assert( (int) $written->post_parent === $expected_parent, 'Positive parent was not preserved/assigned.' );
							wstm106_assert( 'Changed title' === $written->post_title && '<p>Changed content.</p>' === $written->post_content && 'Changed excerpt.' === $written->post_excerpt && $input['slug'] === $written->post_name && 'future' === $written->post_status, 'Positive mixed fields did not persist.' );
							wstm106_assert( ! empty( $record['hooks'] ) && ! $record['unchanged'], 'Positive control did not exercise observation.' );
							wstm106_assert( ( 'update' === $operation ? 'Original metadata.' : '' ) === get_post_meta( $written_id, '_yoast_wpseo_metadesc', true ), 'Plain parent request changed unrelated metadata.' );
							foreach ( $input['taxonomy_terms'] ?? array() as $taxonomy => $ids ) {
								$actual = wp_get_object_terms( $written_id, $taxonomy, array( 'fields' => 'ids' ) );
								wstm106_assert( $ids === $actual, 'Positive terms did not persist.' );
							}
						}
						$record['passed'] = true;
						$summary['passed']++;
					} catch ( Throwable $error ) {
						$GLOBALS['wstm106_observing'] = false;
						$record['passed'] = false;
						$record['failure'] = $error->getMessage();
						$summary['failed']++;
						fwrite( STDERR, "FAIL {$label}: {$error->getMessage()}\n" );
					}
					$summary['cases'][] = $record;
				}
			}
		}
	}
} catch ( Throwable $error ) {
	$summary['failed']++;
	$summary['fatal'] = $error->getMessage();
	fwrite( STDERR, $error->getMessage() . "\n" );
} finally {
	$GLOBALS['wstm106_observing'] = false;
	delete_option( 'wstm106_cap_rule' );
	delete_option( 'wstm106_trace_token' );
	foreach ( $credentials ?? array() as $actor => $password ) {
		WP_Application_Passwords::delete_application_password( $actors[ $actor ], $password[1]['uuid'] );
	}
	wstm106_write( $artifact, $summary );
}
echo "Parent {$mode} {$boundary_filter}: {$summary['passed']} passed, {$summary['failed']} failed.\n";
exit( $summary['failed'] ? 1 : 0 );
