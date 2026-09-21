<?php
/**
 * Standalone, DESTRUCTIVE-FIXTURE, opt-in #121 acceptance benchmark.
 *
 * NEVER load as a plugin, via HTTP, or in a shared/production installation.
 * Requires PHP >=8.1, real WP >=6.9, active toolkit and real Yoast, a single-site
 * disposable database with EMPTY posts/postmeta tables, and no external object
 * cache. No Docker/WP-CLI/HTTP/credentials are used or provisioned by this file.
 * Only run after the coordinator grants the runtime lease.
 *
 * In that disposable installation's wp-config.php explicitly define:
 *   define( 'WSTM_BOUNDED_BENCHMARK', true );
 * Then, in the leased environment (PowerShell example, paths are examples):
 *   $env:WSTM_BOUNDED_BENCHMARK='1'
 *   $env:WSTM_BOUNDED_WP_LOAD='C:\disposable-wordpress\wp-load.php'
 *   $env:WSTM_BOUNDED_NAMESPACE='w121_'+[guid]::NewGuid().ToString('N').Substring(0,12)
 *   $env:WSTM_BOUNDED_SOURCE_SHA=(git rev-parse HEAD)
 *   php tests\e2e\bounded-list-benchmark.php run
 *
 * run creates a NEW e2e-artifacts/<namespace> directory (refuses collisions),
 * a random control option, and an owned non-login fixture user. It seeds 20,000
 * posts (EXACTLY 100 with 55 KiB content), 10,000 fileless attachments, 205 pages,
 * 205 CPT entries, and literal-reference probes. SQL setup deliberately avoids
 * 30,000 wp_insert_post hook executions. It is outside every measurement.
 *
 * Each measured page runs in a fresh PHP subprocess. Cold = fresh request-local
 * object cache; warm = repeat the identical operation once before its marker.
 * Every mode traverses the WHOLE immutable catalog at P=100, including one EOF
 * page, for every collection. Expect THOUSANDS of sequential PHP bootstraps.
 * Additional P=1 and default-20, ASC/title/date/modified tie, and full-field cases
 * are included, plus read-only get/revision projection compatibility probes.
 * This is intentionally not a quick CI smoke test.
 *
 * SQL query filters and user_has_cap observe ACTUAL operation calls only.
 * Catalog bootstrap, oracle, warmup, snapshots and assertion serialization are
 * outside the operation marker. Observer memory IS charged to the operation.
 * PHP 8.1 memory uses end-global-peak minus start-CURRENT-allocated memory:
 * a conservative upper bound, NEVER peak_after minus peak_before. Prior peaks
 * may cause conservative failures. Measures PHP allocator memory, not RSS or
 * database-server memory. "Cold" does not mean a cold database buffer cache.
 * Payload is uncompressed logical JSON, with a 64 KiB assertion ONLY for these
 * controlled default-20 post/page/CPT summaries, not arbitrary real-site data.
 *
 * Artifacts retain source hashes, git HEAD/diff, runtime versions, explicit
 * operation markers, raw SQL/cap/query observations, outcomes and failures.
 * No source, fixture token, or SQL is uploaded. Files stay locally owned.
 * If the leased PHP environment contains a source copy without Git, the
 * explicit WSTM_BOUNDED_SOURCE_SHA (40 hex) is mandatory; actual loaded-file
 * hashes remain authoritative and git-diff availability is recorded honestly.
 * Normal completion/failure cleans owned DB resources; artifacts remain.
 * After a killed run, with the SAME opt-ins and namespace, use:
 *   php tests\e2e\bounded-list-benchmark.php cleanup
 * Cleanup validates the random control token and every ownership marker.
 * No global cache flush, plugin activation, files under uploads, application
 * password, scheduled event, persistent role definition, or persistent hook is created.
 */

declare(strict_types=1);

require_once __DIR__ . '/bounded-list-assertions.php';

function wstm121_json_write( string $path, array $data ): void {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	$pending = $path . '.pending';
	wstm121_require( strlen( $json ) === file_put_contents( $pending, $json, LOCK_EX ), "Cannot write artifact: {$path}" );
	wstm121_require( rename( $pending, $path ), "Cannot publish artifact: {$path}" );
}

function wstm121_json_read( string $path ): array {
	$value = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	wstm121_require( is_array( $value ), "Invalid artifact: {$path}" );
	return $value;
}

function wstm121_db_ok(): void {
	global $wpdb;
	wstm121_require( '' === $wpdb->last_error, 'Fixture database operation failed: ' . $wpdb->last_error );
}

/** Keep the returned resource alive for the entire run/worker/cleanup lifetime. */
function wstm121_lock( string $path, int $mode ) {
	$handle = fopen( $path, 'c' );
	wstm121_require( is_resource( $handle ) && flock( $handle, $mode | LOCK_NB ), 'Live benchmark/worker owns this namespace; refusing concurrent access/cleanup.' );
	return $handle;
}

function wstm121_sources(): array {
	$files = array_merge(
		glob( dirname( __DIR__, 2 ) . '/includes/*.php' ),
		array( dirname( __DIR__, 2 ) . '/webmastery-site-toolkit-for-mcp.php', __FILE__, __DIR__ . '/bounded-list-assertions.php' )
	);
	$hashes = array();
	foreach ( $files as $file ) {
		$hashes[ str_replace( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR, '', $file ) ] = hash_file( 'sha256', $file );
	}
	$loaded = array();
	foreach ( array( 'Webmastery_MCP_Posts', 'Webmastery_MCP_Media', 'Webmastery_MCP_SEO', 'Webmastery_MCP_Content_Hygiene', 'Webmastery_MCP_Custom_Post_Types', 'Webmastery_MCP_List_Query' ) as $class ) {
		$file = ( new ReflectionClass( $class ) )->getFileName();
		$loaded[ $class ] = array( 'path' => $file, 'sha256' => hash_file( 'sha256', $file ) );
		wstm121_require( hash_file( 'sha256', dirname( __DIR__, 2 ) . '/includes/' . basename( $file ) ) === $loaded[ $class ]['sha256'], 'Loaded toolkit source differs from benchmark checkout.' );
	}
	return array( 'sha256' => $hashes, 'loaded' => $loaded );
}

function wstm121_control( array $state ): void {
	global $wpdb;
	$token = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $state['namespace'] . '_control' ) );
	wstm121_db_ok();
	wstm121_require( $token === $state['token'], 'Missing/mismatched control token; refusing access/cleanup.' );
}

function wstm121_insert_rows( string $table, array $columns, array $rows ): void {
	global $wpdb;
	if ( ! $rows ) {
		return;
	}
	$tuple = '(' . implode( ',', array_fill( 0, count( $columns ), '%s' ) ) . ')';
	$values = array();
	foreach ( $rows as $row ) {
		array_push( $values, ...$row );
	}
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', array_fill( 0, count( $rows ), $tuple ) ), $values ) );
	wstm121_db_ok();
}

function wstm121_seed( array &$state, string $directory ): void {
	global $wpdb;
	wstm121_require( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'Use an EMPTY disposable posts table; refusing to mix with existing content.' );
	wstm121_db_ok();
	wstm121_require( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ), 'Use an EMPTY disposable postmeta table.' );
	wstm121_db_ok();
	wstm121_require( ! username_exists( $state['namespace'] ), 'Fixture user collision.' );
	$state['owns_control'] = true;
	// Journal the random token BEFORE acquisition, so a killed setup can recover.
	wstm121_json_write( $directory . '/state.json', $state );
	wstm121_require( add_option( $state['namespace'] . '_control', $state['token'], '', false ), 'Control marker collision.' );
	// A disabled login, not a generated credential; avoid user-creation mail and
	// third-party user provisioning hooks in this deliberately isolated fixture.
	$wpdb->insert( $wpdb->users, array( 'user_login' => $state['namespace'], 'user_nicename' => $state['namespace'], 'display_name' => $state['namespace'], 'user_pass' => '!*wstm121-no-login*', 'user_email' => $state['namespace'] . '@example.invalid', 'user_registered' => gmdate( 'Y-m-d H:i:s' ) ) );
	wstm121_db_ok();
	$state['user'] = (int) $wpdb->insert_id;
	wstm121_require( $state['user'] > 0, 'Cannot create fixture actor.' );
	wstm121_json_write( $directory . '/state.json', $state );
	wstm121_insert_rows( $wpdb->usermeta, array( 'user_id', 'meta_key', 'meta_value' ), array( array( $state['user'], $wpdb->prefix . 'capabilities', serialize( array( 'administrator' => true ) ) ), array( $state['user'], $wpdb->prefix . 'user_level', '10' ) ) );
	$columns = array( 'post_author', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type', 'post_name', 'post_content_filtered', 'guid', 'post_mime_type', 'to_ping', 'pinged' );
	$counts = array( 'post' => 20000, 'attachment' => 10000, 'page' => 205, $state['cpt'] => 205 );
	foreach ( $counts as $type => $count ) {
		for ( $offset = 0; $offset < $count; $offset += 100 ) {
			$rows = array();
			for ( $n = $offset; $n < min( $count, $offset + 100 ); ++$n ) {
				$slug = $state['namespace'] . '-' . $type . '-' . $n;
				// Put the large bodies at the FRONT of the default descending list.
				$content = 'post' === $type && $n >= $count - 100 ? str_repeat( 'x', 55 * 1024 ) : 'Controlled short body.';
				$guid = 'https://example.invalid/' . $slug . ".guid_%'\\literal";
				$rows[] = array( $state['user'], '2020-01-02 03:04:05', '2020-01-02 03:04:05', '2020-01-02 03:04:05', '2020-01-02 03:04:05', 'Controlled tied title', $content, 'Controlled unchanged excerpt.', 'attachment' === $type ? 'inherit' : 'publish', $type, $slug, $state['token'], $guid, 'attachment' === $type ? 'image/jpeg' : '', '', '' );
			}
			wstm121_insert_rows( $wpdb->posts, $columns, $rows );
		}
		$state['catalog'][ $type ] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_content_filtered=%s ORDER BY ID DESC", $type, $state['token'] ) ) );
		wstm121_db_ok();
		wstm121_require( count( $state['catalog'][ $type ] ) === $count, 'Seed count mismatch.' );
	}
	$attachments = $state['catalog']['attachment'];
	foreach ( array_chunk( $attachments, 100 ) as $chunk ) {
		$rows = array();
		foreach ( $chunk as $id ) {
			$rows[] = array( $id, '_wp_attached_file', $state['namespace'] . "/asset-{$id}_%'\\literal.jpg" );
		}
		wstm121_insert_rows( $wpdb->postmeta, array( 'post_id', 'meta_key', 'meta_value' ), $rows );
	}
	$state['referenced'] = array();
	$rows = array();
	foreach ( $attachments as $rank => $id ) {
		if ( 0 === $rank % 5 ) {
			$rows[] = array( $state['catalog']['post'][0], '_thumbnail_id', $id );
			$state['referenced'][] = $id;
		}
	}
	foreach ( array_chunk( $rows, 100 ) as $chunk ) {
		wstm121_insert_rows( $wpdb->postmeta, array( 'post_id', 'meta_key', 'meta_value' ), $chunk );
	}
	// Reference scope includes revision/trash content, not only published posts.
	$probe_content = array(
		array( 'page', 'publish', wp_get_attachment_url( $attachments[1] ) ),
		array( 'page', 'publish', get_post( $attachments[2] )->guid ),
		array( 'revision', 'inherit', wp_get_attachment_url( $attachments[3] ) ),
		array( $state['cpt'], 'trash', get_post( $attachments[4] )->guid ),
		// Must NOT match asset 6 if LIKE escapes %, _, backslash correctly.
		array( 'page', 'publish', str_replace( array( '%', '_' ), array( 'EXPANDED', 'X' ), wp_get_attachment_url( $attachments[6] ) ) ),
	);
	foreach ( $probe_content as $n => $probe ) {
		$id = $state['catalog']['page'][ 200 + $n ];
		$wpdb->update( $wpdb->posts, array( 'post_type' => $probe[0], 'post_status' => $probe[1], 'post_content' => $probe[2], 'post_parent' => 'revision' === $probe[0] ? $state['catalog']['post'][0] : 0 ), array( 'ID' => $id ) );
		wstm121_db_ok();
		if ( $n < 4 ) {
			$state['referenced'][] = $attachments[ $n + 1 ];
		}
		if ( 'revision' === $probe[0] ) {
			$state['revision'] = $id;
		}
	}
	// The revision/trash probes are deliberately outside normal list candidates.
	$state['catalog']['page'] = array_values( array_diff( $state['catalog']['page'], array( $state['catalog']['page'][202], $state['catalog']['page'][203] ) ) );
	$state['referenced'] = array_values( array_unique( $state['referenced'] ) );
	wstm121_json_write( $directory . '/state.json', $state );
	$large = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND OCTET_LENGTH(post_content)=%d", 55 * 1024 ) );
	wstm121_db_ok();
	wstm121_require( 100 === $large, 'Fixture must contain exactly 100 large 55 KiB posts.' );
}

function wstm121_snapshot(): array {
	global $wpdb;
	$result = array();
	foreach ( array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'users', 'usermeta', 'options' ) as $name ) {
		$table = $wpdb->$name;
		$hash = hash_init( 'sha256' );
		$count = 0;
		do {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY 1,2 LIMIT {$count},100", ARRAY_A );
			wstm121_db_ok();
			wstm121_require( is_array( $rows ), "Cannot snapshot {$name}." );
			foreach ( $rows as $row ) {
				hash_update( $hash, serialize( $row ) );
			}
			$count += count( $rows );
		} while ( 100 === count( $rows ) );
		$result[ $name ] = array( 'rows' => $count, 'sha256' => hash_final( $hash ) );
	}
	return $result;
}

function wstm121_cleanup( array $state ): void {
	global $wpdb;
	if ( empty( $state['owns_control'] ) ) {
		return;
	}
	wstm121_control( $state );
	$user = (int) ( $state['user'] ?? 0 );
	// Also recover the narrow crash interval between user insertion and journaling.
	if ( ! $user ) {
		$user = (int) username_exists( $state['namespace'] );
	}
	if ( $user ) {
		$login = $wpdb->get_var( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE ID=%d", $user ) );
		wstm121_db_ok();
		wstm121_require( $login === $state['namespace'], 'Actor ownership mismatch; refusing cleanup.' );
		$foreign = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author=%d AND post_content_filtered<>%s", $user, $state['token'] ) );
		wstm121_db_ok();
		wstm121_require( 0 === $foreign, 'Fixture actor owns unmarked posts; refusing cleanup.' );
		$wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_author=%d AND p.post_content_filtered=%s", $user, $state['token'] ) );
		wstm121_db_ok();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_author=%d AND post_content_filtered=%s", $user, $state['token'] ) );
		wstm121_db_ok();
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user ) );
		wstm121_db_ok();
		$wpdb->delete( $wpdb->users, array( 'ID' => $user ) );
		wstm121_db_ok();
	}
	wstm121_require( delete_option( $state['namespace'] . '_control' ), 'Cannot remove fixture control marker.' );
}

/**
 * Frozen independent pre-#121 algorithm, including literal LIKE escaping and
 * ALL post types/statuses. Do not replace with the new single-item wrapper.
 */
function wstm121_legacy_reference( int $id ): bool {
	global $wpdb;
	$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s", '_thumbnail_id', (string) $id ) );
	wstm121_db_ok();
	wstm121_require( null !== $count, 'Legacy thumbnail oracle failed.' );
	if ( (int) $count > 0 ) {
		return true;
	}
	$post = get_post( $id );
	foreach ( array_filter( array_unique( array( wp_get_attachment_url( $id ), $post->guid ) ) ) as $reference ) {
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_content LIKE %s", '%' . $wpdb->esc_like( $reference ) . '%' ) );
		wstm121_db_ok();
		wstm121_require( null !== $count, 'Legacy content oracle failed.' );
		if ( (int) $count > 0 ) {
			return true;
		}
	}
	return false;
}

function wstm121_parity( array $state, string $directory ): void {
	global $wpdb;
	$ids = array_slice( $state['catalog']['attachment'], 0, 100 );
	$before = wstm121_snapshot();
	$expected = array();
	foreach ( $ids as $id ) {
		$expected[ $id ] = wstm121_legacy_reference( $id );
		wstm121_require( $expected[ $id ] === in_array( $id, $state['referenced'], true ), 'Frozen reference oracle disagrees with seeded literal/escaped/featured fixtures.' );
		wstm121_require( $expected[ $id ] === Webmastery_MCP_Content_Hygiene::is_attachment_referenced( $id ), 'Public single-item reference parity failed.' );
	}
	$actual = Webmastery_MCP_Content_Hygiene::attachments_referenced( $ids );
	wstm121_require( ! is_wp_error( $actual ), 'Batch reference check failed.' );
	foreach ( $expected as $id => $referenced ) {
		wstm121_require( isset( $actual[ $id ] ) && $actual[ $id ] === $referenced, 'Batch reference parity failed.' );
	}
	// Inject READ failures only. Never rename/drop tables or suppress the error.
	$failures = array();
	foreach ( array( 'thumbnail', 'content' ) as $phase ) {
		$injected = 0;
		$filter = static function ( $sql ) use ( $wpdb, $phase, &$injected ) {
			$match = 'thumbnail' === $phase ? str_contains( $sql, '_thumbnail_id' ) : ( str_contains( $sql, 'post_content' ) && str_contains( strtoupper( $sql ), 'LIKE' ) );
			if ( $match && preg_match( '/^\\s*SELECT\\b/i', $sql ) ) {
				++$injected;
				return 'SELECT wstm121_deliberate_missing_column FROM ' . $wpdb->posts . ' LIMIT 1';
			}
			return $sql;
		};
		add_filter( 'query', $filter, PHP_INT_MAX );
		$previous = $wpdb->suppress_errors( true );
		try {
			$result = Webmastery_MCP_Content_Hygiene::attachments_referenced( array( $ids[6] ) );
		} finally {
			$wpdb->suppress_errors( $previous );
			remove_filter( 'query', $filter, PHP_INT_MAX );
		}
		$failures[ $phase ] = array( 'injected_reads' => $injected, 'wp_error' => is_wp_error( $result ) );
		wstm121_require( $injected > 0 && is_wp_error( $result ), 'Reference query failure did not fail closed.' );
	}
	wstm121_require( $before === wstm121_snapshot(), 'Reference parity probes mutated persisted state.' );
	wstm121_json_write( $directory . '/reference-parity.json', array( 'expected' => $expected, 'actual' => $actual, 'read_failures' => $failures ) );
}

function wstm121_mutation_hooks(): array {
	return array(
		'wp_insert_post_data', 'pre_post_update', 'save_post', 'wp_after_insert_post', 'transition_post_status',
		'before_delete_post', 'deleted_post', 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata',
		'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'pre_insert_term', 'created_term', 'edited_term',
		'set_object_terms', 'delete_term_relationships', 'pre_schedule_event', 'pre_unschedule_event',
		'pre_update_option', 'add_option', 'delete_option', 'user_register', 'profile_update', 'delete_user',
		'wp_insert_comment', 'edit_comment', 'delete_comment',
	);
}

function wstm121_projection_probes( array $state, string $directory ): void {
	$before = wstm121_snapshot();
	$evidence = array( 'method' => 'Unmeasured read-only compatibility probes; not candidate-window numeric acceptance.', 'sql' => array(), 'mutations' => array(), 'responses' => array() );
	$queries = static function ( $sql ) use ( &$evidence ) {
		$evidence['sql'][] = $sql;
		if ( ! preg_match( '/^\\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\\b/i', $sql ) ) {
			$evidence['mutations'][] = $sql;
		}
		return $sql;
	};
	$hook = static function ( $value = null ) use ( &$evidence ) {
		$evidence['mutations'][] = current_filter();
		return $value;
	};
	add_filter( 'query', $queries, PHP_INT_MAX );
	foreach ( wstm121_mutation_hooks() as $name ) {
		add_filter( $name, $hook, PHP_INT_MIN );
	}
	try {
		foreach ( array(
			array( 'get-post', array( 'post_id' => $state['catalog']['post'][0] ), $state['catalog']['post'][0] ),
			array( 'get-page', array( 'page_id' => $state['catalog']['page'][0] ), $state['catalog']['page'][0] ),
			array( 'get-cpt-' . str_replace( '_', '-', $state['cpt'] ), array( 'id' => $state['catalog'][ $state['cpt'] ][0] ), $state['catalog'][ $state['cpt'] ][0] ),
		) as $case ) {
			$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $case[0] );
			wstm121_require( null !== $ability, 'Missing get compatibility ability.' );
			$response = $ability->execute( $case[1] );
			$evidence['responses'][ $case[0] ] = $response;
			wstm121_require( is_array( $response ) && true === ( $response['success'] ?? null ), 'Get compatibility probe failed.' );
			wstm121_require( get_post( $case[2] )->post_content === ( $response['data']['content'] ?? null ), 'Get no longer returns unchanged full content.' );
		}
		$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/list-revisions' );
		wstm121_require( null !== $ability, 'Missing revisions ability.' );
		foreach ( array( 'default', 'summary', 'full' ) as $projection ) {
			$input = array( 'post_id' => $state['catalog']['post'][0] );
			if ( 'default' !== $projection ) {
				$input['fields'] = $projection;
			}
			$response = $ability->execute( $input );
			$evidence['responses'][ 'revisions-' . $projection ] = $response;
			wstm121_require( is_array( $response ) && true === ( $response['success'] ?? null ), 'Revision compatibility probe failed.' );
			$revisions = $response['data']['revisions'] ?? array();
			wstm121_require( 1 === count( $revisions ), 'Expected exactly the seeded revision.' );
			wstm121_require( $state['revision'] === (int) ( $revisions[0]['id'] ?? 0 ), 'Wrong revision returned.' );
			wstm121_require( ( 'full' === $projection ) === array_key_exists( 'content', $revisions[0] ), 'Revision projection mismatch.' );
			wstm121_require( 'Controlled unchanged excerpt.' === $revisions[0]['excerpt'], 'Revision excerpt changed.' );
			if ( 'full' === $projection ) {
				wstm121_require( get_post( $state['revision'] )->post_content === $revisions[0]['content'], 'Revision full content changed.' );
			}
		}
		wstm121_require( array() === $evidence['mutations'], 'Read-only compatibility probe reached mutation hooks/SQL.' );
	} finally {
		remove_filter( 'query', $queries, PHP_INT_MAX );
		foreach ( wstm121_mutation_hooks() as $name ) {
			remove_filter( $name, $hook, PHP_INT_MIN );
		}
		wstm121_json_write( $directory . '/projection-probes.json', $evidence );
	}
	wstm121_require( $before === wstm121_snapshot(), 'Read-only compatibility probes changed persisted state.' );
}

function wstm121_worker( array $state, array $job, string $directory ): void {
	global $wpdb;
	wstm121_control( $state );
	wp_set_current_user( $state['user'] );
	wp_get_upload_dir();
	$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $job['ability'] );
	wstm121_require( null !== $ability, 'Required ability is not registered: ' . $job['ability'] );
	$catalog = $state['catalog'][ $job['type'] ];
	$ranks = array_flip( $catalog );
	if ( 'ASC' === ( $job['input']['order'] ?? 'DESC' ) ) {
		$catalog = array_reverse( $catalog );
	}
	$p = $job['per_page'];
	$page = $job['page'];
	$offset = ( $page - 1 ) * $p;
	$candidates = array_slice( $catalog, $offset, $p + 1 );
	$window = array_slice( $candidates, 0, $p );
	$expected = array_values( array_filter( $window, static fn( $id ) => wstm121_allowed( $job['mode'], $ranks[ $id ] ) && ( ! $job['orphan'] || ! in_array( $id, $state['referenced'], true ) ) ) );
	$deny = static function ( $caps, $cap, $user, $args ) use ( $state, $job, $ranks ) {
		if ( (int) $user === $state['user'] && in_array( $cap, array( 'edit_post', 'read_post', 'edit_post_meta', 'read_post_meta' ), true ) && isset( $args[0], $ranks[ (int) $args[0] ] ) && ! wstm121_allowed( $job['mode'], $ranks[ (int) $args[0] ] ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	};
	add_filter( 'map_meta_cap', $deny, PHP_INT_MAX, 4 );
	if ( 'warm' === $job['cache'] ) {
		$warm = $ability->execute( $job['input'] );
		wstm121_require( ! is_wp_error( $warm ) && true === ( $warm['success'] ?? null ), 'Warmup failed.' );
		unset( $warm );
	}
	$record = array(
		'job' => $job, 'page' => $page, 'per_page' => $p, 'orphan' => $job['orphan'],
		'controlled_default_summary' => $job['controlled_default_summary'],
		'expected_ids' => $expected, 'window_ids' => $window, 'expected_candidate_ids' => $candidates,
		'expected_next_page' => count( $candidates ) > $p ? $page + 1 : null,
		'cap_calls' => 0, 'raw_cap_calls' => array(), 'checked_ids' => array(), 'sql' => array(),
		'queries' => array(), 'mutations' => array(), 'reference_pattern_counts' => array(), 'thumbnail_sql' => array(),
	);
	$query_objects = array();
	$query_observer = static function ( $query ) use ( &$query_objects ): void { $query_objects[] = $query; };
	$sql_observer = static function ( $sql ) use ( &$record ) {
		$record['sql'][] = $sql;
		if ( ! preg_match( '/^\\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\\b/i', $sql ) ) {
			$record['mutations'][] = array( 'sql' => $sql );
		}
		if ( str_contains( $sql, 'post_content' ) && preg_match( '/\\bLIKE\\b/i', $sql ) ) {
			$record['reference_pattern_counts'][] = preg_match_all( '/\\bLIKE\\b/i', $sql );
		}
		if ( str_contains( $sql, '_thumbnail_id' ) && str_contains( $sql, 'meta_value' ) ) {
			$record['thumbnail_sql'][] = $sql;
		}
		return $sql;
	};
	$cap_observer = static function ( $allcaps, $caps, $args ) use ( &$record ) {
		++$record['cap_calls'];
		$record['raw_cap_calls'][] = array( 'requested' => $args, 'mapped' => $caps );
		if ( in_array( $args[0], array( 'edit_post', 'read_post', 'edit_post_meta', 'read_post_meta' ), true ) && isset( $args[2] ) ) {
			$record['checked_ids'][ (int) $args[2] ] = (int) $args[2];
		}
		return $allcaps;
	};
	$mutation_observer = static function ( $value = null ) use ( &$record ) {
		$record['mutations'][] = array( 'hook' => current_filter() );
		return $value;
	};
	add_action( 'pre_get_posts', $query_observer, PHP_INT_MAX );
	add_filter( 'query', $sql_observer, PHP_INT_MAX );
	add_filter( 'user_has_cap', $cap_observer, PHP_INT_MAX, 3 );
	foreach ( wstm121_mutation_hooks() as $hook ) {
		add_filter( $hook, $mutation_observer, PHP_INT_MIN );
	}
	$record['operation_start'] = array( 'time_ns' => hrtime( true ), 'current_allocated_bytes' => memory_get_usage( true ), 'historical_peak_bytes' => memory_get_peak_usage( true ), 'wpdb_num_queries' => $wpdb->num_queries );
	$start_usage = memory_get_usage( true );
	$exception = null;
	try {
		$response = $ability->execute( $job['input'] );
		// Logical response serialization belongs to measured operation memory.
		$json = wp_json_encode( $response );
	} catch ( Throwable $error ) {
		$exception = $error;
	} finally {
		$peak = memory_get_peak_usage( true );
		$record['operation_end'] = array( 'time_ns' => hrtime( true ), 'peak_allocated_bytes' => $peak, 'current_allocated_bytes' => memory_get_usage( true ), 'wpdb_num_queries' => $wpdb->num_queries );
		remove_action( 'pre_get_posts', $query_observer, PHP_INT_MAX );
		remove_filter( 'query', $sql_observer, PHP_INT_MAX );
		remove_filter( 'user_has_cap', $cap_observer, PHP_INT_MAX );
		remove_filter( 'map_meta_cap', $deny, PHP_INT_MAX );
		foreach ( wstm121_mutation_hooks() as $hook ) {
			remove_filter( $hook, $mutation_observer, PHP_INT_MIN );
		}
	}
	$record['memory_method'] = 'fresh PHP process; max(whole-process allocated peak) - CURRENT allocated bytes immediately before operation; conservative upper bound, not historical peak delta; includes observer/JSON costs; not RSS';
	$record['memory_start_current_bytes'] = $start_usage;
	$record['memory_upper_bound_bytes'] = wstm121_peak_bound( $start_usage, $peak );
	$record['sql_count'] = $record['operation_end']['wpdb_num_queries'] - $record['operation_start']['wpdb_num_queries'];
	$record['checked_ids'] = array_values( $record['checked_ids'] );
	$record['candidate_ids'] = array();
	foreach ( $query_objects as $query ) {
		$ids = array_map( static fn( $post ) => is_object( $post ) ? (int) $post->ID : (int) $post, $query->posts ?? array() );
		array_push( $record['candidate_ids'], ...$ids );
		$record['queries'][] = array(
			'fields' => $query->get( 'fields' ), 'no_found_rows' => $query->get( 'no_found_rows' ),
			'posts_per_page' => $query->get( 'posts_per_page' ), 'offset' => $query->get( 'offset' ),
			'orderby' => $query->get( 'orderby' ), 'order' => $query->get( 'order' ), 'request' => $query->request,
			'returned_ids' => $ids,
		);
	}
	$record['candidate_count'] = count( $record['candidate_ids'] );
	$record['payload_bytes'] = isset( $json ) && is_string( $json ) ? strlen( $json ) : null;
	$record['response'] = isset( $response ) && is_wp_error( $response ) ? array( 'wp_error' => $response->get_error_code(), 'message' => $response->get_error_message() ) : ( $response ?? null );
	$record['assertions_passed'] = false;
	try {
		if ( $exception ) {
			throw $exception;
		}
		wstm121_require( is_array( $response ) && true === ( $response['success'] ?? null ), 'Ability did not return success.' );
		$data = $response['data'];
		wstm121_assert_page_envelope( $data, $page, $p );
		wstm121_require( is_string( $json ), 'Logical JSON serialization failed.' );
		$record['item_ids'] = array_map( static fn( $item ) => (int) ( $item['id'] ?? $item['post_id'] ?? 0 ), $data['items'] );
		$record['next_page'] = $data['next_page'];
		if ( in_array( $job['type'], array( 'post', 'page', $state['cpt'] ), true ) && ! str_contains( $job['ability'], 'scores' ) ) {
			foreach ( $data['items'] as $item ) {
				wstm121_require( ( 'full' === ( $job['input']['fields'] ?? 'summary' ) ) === array_key_exists( 'content', $item ), 'Summary/full content projection mismatch.' );
				wstm121_require( 'Controlled unchanged excerpt.' === ( $item['excerpt'] ?? null ), 'Summary/full changed excerpt.' );
			}
		}
		wstm121_require( count( $record['sql'] ) === $record['sql_count'], 'SQL observer and wpdb counter disagree.' );
		wstm121_assert_operation( $record );
		$record['assertions_passed'] = true;
	} catch ( Throwable $error ) {
		$record['failure'] = get_class( $error ) . ': ' . $error->getMessage();
	} finally {
		// Retain full raw response, even failed assertions. Only this bounded page.
		wstm121_json_write( $directory . '/result.json', $record );
	}
	wstm121_require( $record['assertions_passed'], $record['failure'] ?? 'Operation assertion failed.' );
}

function wstm121_subprocess( array $job, string $directory ): array {
	wstm121_json_write( $directory . '/job.json', $job );
	$process = proc_open(
		array( PHP_BINARY, __FILE__, 'worker', $directory ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $directory . '/stdout.log', 'w' ), 2 => array( 'file', $directory . '/stderr.log', 'w' ) ),
		$pipes,
		dirname( __DIR__, 2 )
	);
	wstm121_require( is_resource( $process ), 'Cannot start fresh PHP subprocess.' );
	fclose( $pipes[0] );
	$status = proc_close( $process );
	wstm121_require( is_file( $directory . '/result.json' ), "Worker exited {$status} without evidence: {$directory}" );
	$result = wstm121_json_read( $directory . '/result.json' );
	wstm121_require( 0 === $status && true === $result['assertions_passed'], 'Worker failed: ' . ( $result['failure'] ?? $directory ) );
	return $result;
}

function wstm121_suite( array $state, string $directory ): void {
	$collections = array(
		'list-posts' => 'post', 'list-pages' => 'page', 'list-cpt-' . str_replace( '_', '-', $state['cpt'] ) => $state['cpt'],
		'list-media' => 'attachment', 'get-seo-scores' => 'post', 'get-readability-scores' => 'post', 'list-orphaned-media' => 'attachment',
	);
	$summary = array();
	$sequence = 0;
	foreach ( $collections as $ability => $type ) {
		foreach ( array( 'cold', 'warm' ) as $cache ) {
			foreach ( array( 'dense', 'sparse', 'all-denied' ) as $mode ) {
				$seen = array();
				$count = count( $state['catalog'][ $type ] );
				$last_page = (int) ceil( $count / 100 ) + 1;
				for ( $page = 1; $page <= $last_page; ++$page ) {
					$input = array( 'page' => $page, 'per_page' => 100 );
					if ( str_contains( $ability, 'scores' ) ) {
						$input['post_type'] = 'post';
					}
					$job = array( 'ability' => $ability, 'type' => $type, 'cache' => $cache, 'mode' => $mode, 'input' => $input, 'page' => $page, 'per_page' => 100, 'orphan' => 'list-orphaned-media' === $ability, 'controlled_default_summary' => false );
					$path = $directory . '/operation-' . ++$sequence;
					wstm121_require( mkdir( $path, 0700 ), 'Cannot create operation artifact directory.' );
					$result = wstm121_subprocess( $job, $path );
					foreach ( $result['item_ids'] as $id ) {
						wstm121_require( ! isset( $seen[ $id ] ), 'Duplicate across immutable candidate pages.' );
						$seen[ $id ] = true;
					}
					unset( $result );
				}
				$expected = array();
				foreach ( $state['catalog'][ $type ] as $rank => $id ) {
					if ( wstm121_allowed( $mode, $rank ) && ( 'list-orphaned-media' !== $ability || ! in_array( $id, $state['referenced'], true ) ) ) {
						$expected[] = $id;
					}
				}
				wstm121_require( array_keys( $seen ) === $expected, 'Traversal skipped/reordered eligible IDs.' );
				$summary[] = array( 'ability' => $ability, 'cache' => $cache, 'mode' => $mode, 'pages_with_eof_probe' => $last_page, 'eligible_count' => count( $expected ), 'ordered_ids_sha256' => hash( 'sha256', json_encode( array_keys( $seen ) ) ) );
				wstm121_json_write( $directory . '/traversals.json', $summary );
			}
		}
		// Defaults and explicitly requested full projection; tied alternate sorts.
		$cases = array( array( 'per_page' => 1 ), array() );
		if ( in_array( $type, array( 'post', 'page', $state['cpt'] ), true ) && ! str_contains( $ability, 'scores' ) ) {
			$cases[] = array( 'fields' => 'full', 'per_page' => 100 );
			foreach ( array( 'date', 'title', 'modified', 'id' ) as $sort ) {
				foreach ( array( 'ASC', 'DESC' ) as $order ) {
					$cases[] = array( 'orderby' => $sort, 'order' => $order, 'page' => 2, 'per_page' => 100 );
				}
			}
		}
		foreach ( $cases as $input ) {
			if ( str_contains( $ability, 'scores' ) ) {
				$input['post_type'] = 'post';
			}
			$p = $input['per_page'] ?? ( str_contains( $ability, 'scores' ) ? 10 : 20 );
			$controlled = empty( $input ) && in_array( $type, array( 'post', 'page', $state['cpt'] ), true );
			foreach ( array( 'cold', 'warm' ) as $cache ) {
				$job = array( 'ability' => $ability, 'type' => $type, 'cache' => $cache, 'mode' => 'dense', 'input' => $input, 'page' => $input['page'] ?? 1, 'per_page' => $p, 'orphan' => 'list-orphaned-media' === $ability, 'controlled_default_summary' => $controlled );
				$path = $directory . '/operation-' . ++$sequence;
				wstm121_require( mkdir( $path, 0700 ), 'Cannot create operation artifact directory.' );
				wstm121_subprocess( $job, $path );
			}
		}
	}
	wstm121_json_write( $directory . '/suite.json', array( 'passed' => true, 'operations' => $sequence, 'traversals' => count( $summary ) ) );
}

function wstm121_main( array $argv ): int {
	wstm121_require( 'cli' === PHP_SAPI && PHP_VERSION_ID >= 80100, 'CLI PHP >=8.1 required.' );
	wstm121_require( '1' === getenv( 'WSTM_BOUNDED_BENCHMARK' ), 'Explicit WSTM_BOUNDED_BENCHMARK=1 CLI opt-in required.' );
	$namespace = (string) getenv( 'WSTM_BOUNDED_NAMESPACE' );
	wstm121_require( 1 === preg_match( '/\\Aw121_[a-f0-9]{12}\\z/D', $namespace ), 'Supply a unique WSTM_BOUNDED_NAMESPACE=w121_<12 lowercase hex>.' );
	$mode = $argv[1] ?? '';
	wstm121_require( in_array( $mode, array( 'run', 'worker', 'cleanup' ), true ), 'Usage: php bounded-list-benchmark.php run|cleanup (worker is internal).' );
	$load = (string) getenv( 'WSTM_BOUNDED_WP_LOAD' );
	wstm121_require( is_file( $load ) && 'wp-load.php' === basename( $load ), 'Supply explicit WSTM_BOUNDED_WP_LOAD path.' );
	wstm121_require( ! defined( 'ABSPATH' ), 'Run standalone, not from an already bootstrapped WordPress process.' );
	define( 'WP_USE_THEMES', false );
	define( 'DISABLE_WP_CRON', true );
	// Core converts preinitialized hook arrays to WP_Hook before firing init.
	// Register before the abilities registry is initialized, not outside the
	// mandatory wp_abilities_api_init registration action.
	$GLOBALS['wp_filter']['init'][0][] = array(
		'function' => static function () use ( $namespace ): void {
			wstm121_require( defined( 'WSTM_BOUNDED_BENCHMARK' ) && true === WSTM_BOUNDED_BENCHMARK, 'Runtime wp-config.php constant opt-in is also required.' );
			wstm121_require( ! post_type_exists( $namespace ), 'Fixture CPT registration collision.' );
			register_post_type( $namespace, array( 'public' => true, 'show_ui' => true, 'supports' => array( 'title', 'editor', 'excerpt' ), 'capability_type' => 'post', 'map_meta_cap' => true ) );
		},
		'accepted_args' => 0,
	);
	require $load;
	wstm121_require( defined( 'WSTM_BOUNDED_BENCHMARK' ) && true === WSTM_BOUNDED_BENCHMARK, 'Runtime wp-config.php constant opt-in is also required.' );
	wstm121_require( version_compare( $GLOBALS['wp_version'], '6.9', '>=' ), 'Real WordPress >=6.9 required.' );
	wstm121_require( ! is_multisite(), 'Use an isolated single-site fixture; super-admin bypass invalidates cap-call observations.' );
	wstm121_require( ! wp_using_ext_object_cache(), 'Use request-local object cache; this runner will not flush a shared persistent cache.' );
	wstm121_require( function_exists( 'wp_get_ability' ) && class_exists( 'Webmastery_MCP_Posts' ), 'Activate the real toolkit before running.' );
	wstm121_require( defined( 'WPSEO_VERSION' ), 'Activate real Yoast before running score benchmarks; no fake active-provider shortcut.' );
	$cpt = $namespace;
	// Initialize the real catalog outside all operation counters.
	wp_get_abilities();
	$directory = dirname( __DIR__, 2 ) . '/e2e-artifacts/' . $namespace;
	if ( 'worker' === $mode ) {
		$operation = realpath( $argv[2] ?? '' );
		wstm121_require( false !== $operation && dirname( $operation ) === realpath( $directory ) && 1 === preg_match( '/\\Aoperation-[0-9]+\\z/', basename( $operation ) ), 'Worker artifact path is outside this run.' );
		$worker_lock = wstm121_lock( $directory . '/workers.lock', LOCK_SH );
		$state = wstm121_json_read( $directory . '/state.json' );
		wstm121_require( $state['namespace'] === $namespace, 'State namespace mismatch.' );
		wstm121_require( $state['sources'] === wstm121_sources(), 'Source changed during immutable benchmark.' );
		wstm121_worker( $state, wstm121_json_read( $operation . '/job.json' ), $operation );
		return 0;
	}
	if ( 'cleanup' === $mode ) {
		$run_lock = wstm121_lock( $directory . '/run.lock', LOCK_EX );
		$worker_lock = wstm121_lock( $directory . '/workers.lock', LOCK_EX );
		$state = wstm121_json_read( $directory . '/state.json' );
		wstm121_require( $state['namespace'] === $namespace, 'State namespace mismatch.' );
		wstm121_cleanup( $state );
		wstm121_json_write( $directory . '/cleanup.json', array( 'passed' => true ) );
		return 0;
	}
	wstm121_require( ! file_exists( $directory ), 'Artifact namespace collision; choose a fresh namespace (or cleanup the previous run).' );
	wstm121_require( is_dir( dirname( $directory ) ) || mkdir( dirname( $directory ), 0700 ), 'Cannot create artifact root.' );
	wstm121_require( mkdir( $directory, 0700 ), 'Cannot exclusively create artifact namespace.' );
	$run_lock = wstm121_lock( $directory . '/run.lock', LOCK_EX );
	$state = array( 'namespace' => $namespace, 'token' => bin2hex( random_bytes( 32 ) ), 'cpt' => $cpt, 'sources' => wstm121_sources() );
	$failed = false;
	$before = null;
	try {
		$git_head = array();
		$git_diff = array();
		$git = 'git --no-pager -C ' . escapeshellarg( dirname( __DIR__, 2 ) );
		exec( $git . ' rev-parse HEAD 2>&1', $git_head, $git_status );
		$source_sha = 0 === $git_status ? trim( implode( '', $git_head ) ) : (string) getenv( 'WSTM_BOUNDED_SOURCE_SHA' );
		wstm121_require( 1 === preg_match( '/\\A[a-f0-9]{40}\\z/i', $source_sha ), 'Cannot record Git HEAD; supply explicit WSTM_BOUNDED_SOURCE_SHA for a source-copy runtime.' );
		$diff_status = null;
		if ( 0 === $git_status ) {
			exec( $git . ' diff --no-ext-diff --binary HEAD 2>&1', $git_diff, $diff_status );
			wstm121_require( 0 === $diff_status, 'Cannot record source diff.' );
		}
		wstm121_json_write( $directory . '/provenance.json', array( 'source_sha' => $source_sha, 'source_sha_method' => 0 === $git_status ? 'git HEAD; uncommitted state also hashed' : 'explicit operator-provided SHA; actual loaded-file hashes retained', 'git_head' => $git_head, 'git_diff' => $git_diff, 'git_diff_available' => 0 === $diff_status, 'sources' => $state['sources'], 'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'], 'yoast' => WPSEO_VERSION, 'seed' => array( 'posts' => 20000, 'attachments' => 10000, 'large_posts' => 100, 'large_body_bytes' => 55 * 1024 ), 'memory_method' => 'conservative fresh-process PHP allocator peak minus current usage at operation start; not RSS; see source header' ) );
		wstm121_seed( $state, $directory );
		wp_set_current_user( $state['user'] );
		wstm121_parity( $state, $directory );
		wstm121_projection_probes( $state, $directory );
		$before = wstm121_snapshot();
		wstm121_json_write( $directory . '/before.json', $before );
		wstm121_suite( $state, $directory );
	} catch ( Throwable $error ) {
		$failed = true;
		wstm121_json_write( $directory . '/failure.json', array( 'class' => get_class( $error ), 'message' => $error->getMessage(), 'trace' => $error->getTraceAsString() ) );
		fwrite( STDERR, $error->getMessage() . PHP_EOL );
	} finally {
		try {
			if ( null !== $before ) {
				$after = wstm121_snapshot();
				wstm121_json_write( $directory . '/after.json', $after );
				wstm121_require( $before === $after, 'Benchmark changed database state, including cron/options/hooks side effects.' );
			}
		} catch ( Throwable $error ) {
			$failed = true;
			wstm121_json_write( $directory . '/mutation-failure.json', array( 'message' => $error->getMessage() ) );
		}
		try {
			wstm121_cleanup( $state );
			wstm121_json_write( $directory . '/cleanup.json', array( 'passed' => true ) );
		} catch ( Throwable $error ) {
			$failed = true;
			wstm121_json_write( $directory . '/cleanup-failure.json', array( 'message' => $error->getMessage(), 'recovery' => 'With the same opt-ins and namespace: php tests/e2e/bounded-list-benchmark.php cleanup' ) );
		}
		wstm121_json_write( $directory . '/outcome.json', array( 'passed' => ! $failed, 'real_runtime_executed' => true ) );
	}
	fwrite( STDOUT, ( $failed ? 'FAILED: ' : 'PASSED: ' ) . $directory . PHP_EOL );
	return $failed ? 1 : 0;
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		exit( wstm121_main( $argv ) );
	} catch ( Throwable $error ) {
		fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
