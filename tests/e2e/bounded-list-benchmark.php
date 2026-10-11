<?php
/**
 * Standalone, DESTRUCTIVE-FIXTURE, opt-in #121 acceptance benchmark.
 *
 * NEVER load as a plugin, via HTTP, or in a shared/production installation.
 * Requires PHP 8.0 or 8.4, real WP >=6.9, active toolkit and real Yoast, a single-site
 * disposable database with EMPTY posts/postmeta tables, and no external object
 * cache. No Docker/WP-CLI/HTTP/credentials are used or provisioned by this file.
 * Parent supplies the finite product context and E2E_OPT_IN=1.
 *
 * seed creates a NEW private <artifact-root>/<namespace> (refuses collisions),
 * a random control option, and an owned non-login fixture user. It seeds 20,000
 * posts (EXACTLY 100 with 55 KiB content), 10,000 fileless attachments, 205 pages,
 * 205 CPT entries, and literal-reference probes. SQL setup deliberately avoids
 * 30,000 wp_insert_post hook executions. It is outside every measurement.
 *
 * The small catalog is traversed completely. The large catalog uses 336 fixed
 * first/intermediate/final/EOF observations across seven surfaces, two caches,
 * three authorization regimes and two page sizes. Cold resets only the owned
 * request-local cache; warm repeats the operation before its measurement.
 * Projection, reference parity and forced SQL-failure probes remain separate.
 *
 * SQL query filters and user_has_cap observe ACTUAL operation calls only.
 * Catalog bootstrap, oracle, warmup, snapshots and assertion serialization are
 * outside the operation marker. Observer memory IS charged to the operation.
 * Memory uses end-global-peak minus start-CURRENT-allocated memory:
 * a conservative upper bound, NEVER peak_after minus peak_before. Prior peaks
 * may cause conservative failures. Measures PHP allocator memory, not RSS or
 * database-server memory. "Cold" does not mean a cold database buffer cache.
 * Payload is uncompressed logical JSON, with a 64 KiB assertion ONLY for these
 * controlled default-20 post/page/CPT summaries, not arbitrary real-site data.
 *
 * Artifacts retain loaded source hashes, parent identity, runtime versions, explicit
 * operation markers, raw SQL/cap/query observations, outcomes and failures.
 * No source, fixture token, or SQL is uploaded. Files stay locally owned.
 * Normal completion/failure cleans owned DB resources; artifacts remain.
 * A killed run remains private for the parent-owned stack teardown. This runner
 * grants no standalone recovery or broader cleanup authority.
 * Cleanup validates the random control token and every ownership marker.
 * A retry permits the journaled actor to be absent after partial cleanup, but
 * refuses a different actor at that ID or another actor using its namespace.
 * No global cache flush, plugin activation, files under uploads, application
 * password, scheduled event, persistent role definition, or persistent hook is created.
 */

declare(strict_types=1);

require_once __DIR__ . '/bounded-list-assertions.php';
require_once __DIR__ . '/bounded-list-plan.php';

function wstm121_json_write( string $path, array $data ): void {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
	wstm121_require( strlen( $json ) <= Wstm121Plan::JSON_BYTES, 'Artifact exceeds its 32-MiB limit.' );
	if ( isset( $GLOBALS['wstm167_product_output'] ) ) {
		$GLOBALS['wstm167_product_output']->write( $path, $json );
		return;
	}
	throw new RuntimeException( 'Product output requires the owned finite writer.' );
}

function wstm121_json_read( string $path ): array {
	$file = Wstm108_Files::file( $path );
	$value = json_decode( $file['bytes'], true, 512, JSON_THROW_ON_ERROR );
	wstm121_require( is_array( $value ), "Invalid artifact: {$path}" );
	return $value;
}

function wstm121_db_ok(): void {
	global $wpdb;
	wstm121_require( '' === $wpdb->last_error, 'Fixture database operation failed: ' . $wpdb->last_error );
}

function wstm121_sources(): array {
	$root = $GLOBALS['wstm167_product_plugin_root'] ?? dirname( __DIR__, 2 );
	$files = array_merge(
		glob( $root . '/includes/*.php' ),
		array( $root . '/webmastery-site-toolkit-for-mcp.php', __FILE__, __DIR__ . '/bounded-list-assertions.php', __DIR__ . '/bounded-list-plan.php', __DIR__ . '/bounded-product-runtime.php', __DIR__ . '/product-runtime-context.php' )
	);
	$hashes = array();
	foreach ( $files as $file ) {
		$hashes[ str_replace( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR, '', $file ) ] = hash_file( 'sha256', $file );
		wstm121_require( is_string( end( $hashes ) ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', end( $hashes ) ), 'Product source could not be read.' );
	}
	$loaded = array();
	foreach ( array( 'Webmastery_MCP_Posts', 'Webmastery_MCP_Media', 'Webmastery_MCP_SEO', 'Webmastery_MCP_Content_Hygiene', 'Webmastery_MCP_Custom_Post_Types', 'Webmastery_MCP_List_Query' ) as $class ) {
		$file = ( new ReflectionClass( $class ) )->getFileName();
		$loaded[ $class ] = array( 'path' => $file, 'sha256' => hash_file( 'sha256', $file ) );
		wstm121_require( realpath( $file ) === $root . '/includes/' . basename( $file )
			&& hash_file( 'sha256', $root . '/includes/' . basename( $file ) ) === $loaded[ $class ]['sha256'], 'Loaded toolkit source differs from owned source/package root.' );
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

function wstm121_seed( array &$state, string $directory, ?callable $before_acquire = null ): void {
	global $wpdb;
	wstm121_require( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'Use an EMPTY disposable posts table; refusing to mix with existing content.' );
	wstm121_db_ok();
	wstm121_require( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ), 'Use an EMPTY disposable postmeta table.' );
	wstm121_db_ok();
	wstm121_require( ! username_exists( $state['namespace'] ), 'Fixture user collision.' );
	$state['owns_control'] = true;
	// Journal the random token BEFORE acquisition, so a killed setup can recover.
	wstm121_json_write( $directory . '/state.json', $state );
	if ( null !== $before_acquire ) { $before_acquire(); }
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
	$small = true === ( $state['small_catalog'] ?? false );
	$counts = $small ? array( 'post' => 43, 'attachment' => 43, 'page' => 48, $state['cpt'] => 43 )
		: array( 'post' => 20000, 'attachment' => 10000, 'page' => 205, $state['cpt'] => 205 );
	foreach ( $counts as $type => $count ) {
		for ( $offset = 0; $offset < $count; $offset += 100 ) {
			$rows = array();
			for ( $n = $offset; $n < min( $count, $offset + 100 ); ++$n ) {
				$slug = $state['namespace'] . '-' . $type . '-' . $n;
				// Put the large bodies at the FRONT of the default descending list.
				$content = ! $small && 'post' === $type && $n >= $count - 100 ? wstm121_large_content() : 'Controlled short body.';
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
		$id = $state['catalog']['page'][ count( $state['catalog']['page'] ) - 5 + $n ];
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
	$page_count = count( $state['catalog']['page'] );
	$state['catalog']['page'] = array_values( array_diff( $state['catalog']['page'], array( $state['catalog']['page'][ $page_count - 3 ], $state['catalog']['page'][ $page_count - 2 ] ) ) );
	$state['referenced'] = array_values( array_unique( $state['referenced'] ) );
	wstm121_json_write( $directory . '/state.json', $state );
	$large = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND OCTET_LENGTH(post_content)=%d", 55 * 1024 ) );
	wstm121_db_ok();
	wstm121_json_write( $directory . '/seed-measurements.json', array(
		'catalog_counts' => array_map( 'count', $state['catalog'] ),
		'large_posts' => $large, 'large_body_bytes' => 55 * 1024, 'large_count_sql' => $wpdb->last_query,
		'method' => 'Catalog IDs read from token-owned rows after setup; large-body count from real OCTET_LENGTH SQL. Excluded from operation counters.',
	) );
	wstm121_require( ( $small ? 0 : 100 ) === $large, 'Fixture large-body count differs from its planned scale.' );
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
		// Actor deletion precedes control deletion. An absent journaled actor is
		// therefore a valid retry state, not permission to adopt a replacement.
		wstm121_require( null === $login || $login === $state['namespace'], 'Actor ownership mismatch; refusing cleanup.' );
		$replacement = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login=%s AND ID<>%d", $state['namespace'], $user ) );
		wstm121_db_ok();
		wstm121_require( 0 === $replacement, 'Actor namespace belongs to a different ID; refusing cleanup.' );
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
	$evidence = array( 'ids' => $ids, 'before' => $before, 'legacy' => array(), 'single' => array(), 'batch' => null,
		'read_failures' => array(), 'sql' => array(), 'mutations' => array() );
	$queries = static function ( $sql ) use ( &$evidence ) {
		$evidence['sql'][] = $sql;
		if ( ! preg_match( '/^\\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\\b/i', $sql ) ) {
			$evidence['mutations'][] = $sql;
		}
		return $sql;
	};
	$mutation = static function ( $value = null ) use ( &$evidence ) {
		$evidence['mutations'][] = current_filter();
		return $value;
	};
	// Observe after native placeholder removal, before the later same-priority injector.
	add_filter( 'query', $queries, PHP_INT_MAX );
	foreach ( wstm121_mutation_hooks() as $name ) {
		add_filter( $name, $mutation, PHP_INT_MIN );
	}
	try {
		foreach ( $ids as $id ) {
			$evidence['legacy'][ $id ] = wstm121_legacy_reference( $id );
			$evidence['single'][ $id ] = Webmastery_MCP_Content_Hygiene::is_attachment_referenced( $id );
			wstm121_require( $evidence['legacy'][ $id ] === in_array( $id, $state['referenced'], true ), 'Frozen reference oracle disagrees with seeded literal/escaped/featured fixtures.' );
			wstm121_require( $evidence['legacy'][ $id ] === $evidence['single'][ $id ], 'Public single-item reference parity failed.' );
		}
		$actual = Webmastery_MCP_Content_Hygiene::attachments_referenced( $ids );
		$evidence['batch'] = $actual;
		wstm121_require( ! is_wp_error( $actual ) && $actual === $evidence['legacy'], 'Batch reference parity failed.' );
		$delete = wp_get_ability( 'webmastery-site-toolkit-for-mcp/delete-media' );
		wstm121_require( null !== $delete, 'Missing real delete-media ability.' );
		foreach ( array( 'thumbnail', 'content' ) as $phase ) {
			$injected = array();
			$filter = static function ( $sql ) use ( $wpdb, $phase, &$injected, &$evidence ) {
				$match = 'thumbnail' === $phase ? str_contains( $sql, '_thumbnail_id' ) : ( str_contains( $sql, 'post_content' ) && str_contains( strtoupper( $sql ), 'LIKE' ) );
				if ( $match && preg_match( '/^\\s*SELECT\\b/i', $sql ) ) {
					wstm121_require( $sql === end( $evidence['sql'] ), 'Reference injector original differs from its boundary observation.' );
					$replacement = 'SELECT wstm121_deliberate_missing_column FROM ' . $wpdb->posts . ' LIMIT 1';
					$injected[] = array( 'original' => $sql, 'replacement' => $replacement );
					return $replacement;
				}
				return $sql;
			};
			add_filter( 'query', $filter, PHP_INT_MAX );
			$previous = $wpdb->suppress_errors( true );
			try {
				$result = Webmastery_MCP_Content_Hygiene::attachments_referenced( array( $ids[6] ) );
				$evidence['read_failures'][ $phase ] = array(
					'helper' => is_wp_error( $result ) ? array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $result->get_error_data() ) : $result,
					'forced_input' => array( 'media_id' => $ids[6], 'confirm' => true, 'force' => true ),
				);
				wstm121_require( is_wp_error( $result ), 'Reference query failure did not fail closed.' );
				$forced = $delete->execute( $evidence['read_failures'][ $phase ]['forced_input'] );
				$evidence['read_failures'][ $phase ]['forced_response'] = $forced;
				wstm121_require( is_array( $forced ) && false === ( $forced['success'] ?? null )
					&& 'upstream_failed' === ( $forced['error']['code'] ?? null )
					&& 'content_hygiene_query_failed' === ( $forced['error']['reason'] ?? null ), 'Force bypassed the failed reference scan.' );
				wstm121_require( ! str_contains( wp_json_encode( $forced ), 'wstm121_deliberate_missing_column' )
					&& ! str_contains( wp_json_encode( $forced ), $wpdb->posts ), 'Forced-deletion error disclosed SQL diagnostics.' );
			} finally {
				$evidence['read_failures'][ $phase ]['injected'] = $injected;
				$wpdb->suppress_errors( $previous );
				remove_filter( 'query', $filter, PHP_INT_MAX );
			}
			wstm121_require( 2 === count( $injected ), 'Both helper and forced-deletion failures must execute real failing SQL.' );
		}
		$evidence['after'] = wstm121_snapshot();
		wstm121_require( $before === $evidence['after'] && array() === $evidence['mutations'], 'Reference probes mutated persisted state or reached write hooks.' );
		require_once __DIR__ . '/bounded-list-verifier.php';
		Wstm121Verifier::reference_probe( $state, $evidence );
	} catch ( Throwable $error ) {
		$evidence['failure'] = get_class( $error ) . ': ' . $error->getMessage();
		throw $error;
	} finally {
		remove_filter( 'query', $queries, PHP_INT_MAX );
		foreach ( wstm121_mutation_hooks() as $name ) {
			remove_filter( $name, $mutation, PHP_INT_MIN );
		}
		wstm121_json_write( $directory . '/reference-parity.json', $evidence );
	}
}

function wstm121_window_faults( array $state, string $directory ): void {
	global $wpdb;
	require_once __DIR__ . '/query-failure-witness.php';
	$before = wstm121_snapshot();
	$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/list-posts' );
	wstm121_require( null !== $ability, 'Missing real list-posts ability.' );
	$input = array( 'page' => 1, 'per_page' => 2, 'orderby' => 'id', 'order' => 'DESC' );
	$expected = array_slice( $state['catalog']['post'], 0, 2 );
	$evidence = array( 'input' => $input, 'expected_ids' => $expected, 'phases' => array(), 'before' => $before );
	try {
		foreach ( array( 'candidate', 'priming' ) as $phase ) {
			foreach ( array_slice( $state['catalog']['post'], 0, 3 ) as $id ) {
				wp_cache_delete( $id, 'posts' );
			}
			// Isolated request-local setup only; never invalidate between failure and retry.
			wp_cache_set_posts_last_changed();
			$request = null;
			$injected = 0;
			$sql_seen = array();
			$injection = null;
			$witness = new Wstm121QueryFailureWitness();
			$observe = static function ( $sql ) use ( $witness, $wpdb ) {
				$witness->observe( $wpdb, 'before-next-query' );
				return $sql;
			};
			$capture = static function ( $sql, $query ) use ( &$request ) {
				if ( 'ids' === $query->get( 'fields' ) && 3 === $query->get( 'posts_per_page' ) ) {
					$request = $sql;
				}
				return $sql;
			};
			$inject = static function ( $sql ) use ( $phase, &$request, &$injected, &$sql_seen, &$injection, $witness, $wpdb ) {
				$sql_seen[] = $sql;
				$table = preg_quote( $wpdb->posts, '~' );
				$prime = 1 === preg_match( '~^\\s*SELECT\\s+(?:' . $table . '\\.)?\\*\\s+FROM\\s+' . $table . '\\s+WHERE\\s+(?:' . $table . '\\.)?ID\\s+IN\\s*\\(~i', $sql );
				if ( 0 === $injected && ( 'candidate' === $phase ? $sql === $request : $prime ) ) {
					++$injected;
					$replacement = 'SELECT wstm121_deliberate_missing_column FROM ' . $wpdb->posts . ' LIMIT 1';
					$injection = array( 'original' => $sql, 'replacement' => $replacement,
						'query_before' => $wpdb->num_queries, 'sql_index' => count( $sql_seen ) - 1 );
					$witness->injected( $replacement, $wpdb->num_queries, count( $sql_seen ) - 1 );
					return $replacement;
				}
				return $sql;
			};
			add_filter( 'posts_request', $capture, PHP_INT_MAX, 2 );
			add_filter( 'query', $observe, PHP_INT_MIN );
			add_filter( 'query', $inject, PHP_INT_MAX );
			$suppressed = $wpdb->suppress_errors( true );
			$start = $wpdb->num_queries;
			$generation = wp_cache_get_last_changed( 'posts' );
			try {
				$failed = $ability->execute( $input );
				$witness->observe( $wpdb, 'final' );
			} finally {
				$wpdb->suppress_errors( $suppressed );
				remove_filter( 'query', $inject, PHP_INT_MAX );
				remove_filter( 'query', $observe, PHP_INT_MIN );
				remove_filter( 'posts_request', $capture, PHP_INT_MAX );
			}
			$record = array(
				'injected' => $injected, 'request' => $request, 'sql' => $sql_seen,
				'injection' => $injection, 'failure_witness' => $witness->retained(),
				'query_start' => $start, 'query_end' => $wpdb->num_queries,
				'generation_before' => $generation, 'generation_after' => wp_cache_get_last_changed( 'posts' ),
				'failed_response' => $failed, 'private_database_error' => $wpdb->last_error,
			);
			$evidence['phases'][ $phase ] = $record;
			wstm121_require( 1 === $injected && $record['query_end'] > $start, 'Intended real database failure was not exercised.' );
			wstm121_require( is_array( $failed ) && false === ( $failed['success'] ?? null )
				&& 'upstream_failed' === ( $failed['error']['code'] ?? null )
				&& 'The list query could not be completed.' === ( $failed['error']['message'] ?? null ), 'Query failure masqueraded as EOF or exposed a different error.' );
			wstm121_require( ! str_contains( wp_json_encode( $failed ), 'wstm121_deliberate_missing_column' )
				&& ! str_contains( wp_json_encode( $failed ), $wpdb->posts ), 'SQL diagnostics escaped into the public response.' );
			wstm121_require( $record['generation_before'] !== $record['generation_after'], 'Fresh failure did not invalidate the failed query generation.' );
			$retry_start = $wpdb->num_queries;
			$retry = $ability->execute( $input );
			$evidence['phases'][ $phase ]['retry'] = array( 'query_start' => $retry_start, 'query_end' => $wpdb->num_queries, 'response' => $retry );
			wstm121_require( true === ( $retry['success'] ?? null ) && $wpdb->num_queries > $retry_start
				&& $expected === array_column( $retry['data']['items'], 'id' )
				&& 2 === $retry['data']['next_page'], 'Retry reused a failed empty cache entry instead of recovering the same window.' );
			$previous_error = $wpdb->last_error;
			$wpdb->last_error = 'wstm121_stale_error_without_new_sql';
			$warm_start = $wpdb->num_queries;
			try {
				$warm = $ability->execute( $input );
				$evidence['phases'][ $phase ]['warm_stale'] = array( 'query_start' => $warm_start, 'query_end' => $wpdb->num_queries, 'response' => $warm, 'last_error' => $wpdb->last_error );
				wstm121_require( $warm_start === $wpdb->num_queries && 'wstm121_stale_error_without_new_sql' === $wpdb->last_error
					&& true === ( $warm['success'] ?? null ) && $expected === array_column( $warm['data']['items'], 'id' ), 'SQL-free warm cache treated an unrelated stale error as failure.' );
			} finally {
				$wpdb->last_error = $previous_error;
			}
		}
		$evidence['after'] = wstm121_snapshot();
		wstm121_require( $before === $evidence['after'], 'Fault/cache probes changed persisted state.' );
		require_once __DIR__ . '/bounded-list-verifier.php';
		Wstm121Verifier::cache_probe( $state, $evidence );
	} catch ( Throwable $error ) {
		$evidence['failure'] = $error->getMessage();
		throw $error;
	} finally {
		wstm121_json_write( $directory . '/window-cache-faults.json', $evidence );
	}
}

function wstm121_cleanup_readback( array $state, string $directory, ?string $output = null ): void {
	global $wpdb;
	$checks = array(
		'posts' => $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content_filtered=%s", $state['token'] ),
		'actor' => $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID=%d OR user_login=%s", $state['user'] ?? 0, $state['namespace'] ),
		'actor_meta' => $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id=%d", $state['user'] ?? 0 ),
		'control' => $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s", $state['namespace'] . '_control' ),
	);
	$remaining = array();
	foreach ( $checks as $name => $sql ) {
		$count = $wpdb->get_var( $sql );
		wstm121_db_ok();
		wstm121_require( null !== $count && is_numeric( $count ), 'Missing cleanup readback count.' );
		$remaining[ $name ] = (int) $count;
	}
	$snapshot = wstm121_snapshot();
	wstm121_json_write( ( $output ?? $directory ) . '/cleanup-readback.json', array( 'remaining' => $remaining, 'snapshot' => $snapshot ) );
	wstm121_require( array_fill_keys( array_keys( $checks ), 0 ) === $remaining, 'Owned fixture resources remain after cleanup.' );
	wstm121_require( $snapshot === wstm121_json_read( $directory . '/setup-before.json' ), 'Cleanup did not restore the pre-seed persisted state.' );
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
	$evidence = array( 'method' => 'Unmeasured read-only compatibility probes; not candidate-window numeric acceptance.', 'before' => $before, 'sql' => array(), 'mutations' => array(), 'responses' => array(), 'stored_content' => array() );
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
			$evidence['stored_content'][ $case[0] ] = get_post( $case[2] )->post_content;
			wstm121_require( is_array( $response ) && true === ( $response['success'] ?? null ), 'Get compatibility probe failed.' );
			wstm121_require( get_post( $case[2] )->post_content === ( $response['data']['content'] ?? null ), 'Get no longer returns unchanged full content.' );
		}
		$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/list-revisions' );
		wstm121_require( null !== $ability, 'Missing revisions ability.' );
		$evidence['stored_content']['revision'] = get_post( $state['revision'] )->post_content;
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
		$evidence['after'] = wstm121_snapshot();
		wstm121_require( $before === $evidence['after'], 'Read-only compatibility probes changed persisted state.' );
		require_once __DIR__ . '/bounded-list-verifier.php';
		Wstm121Verifier::projection_probe( $state, $evidence );
	} catch ( Throwable $error ) {
		$evidence['failure'] = get_class( $error ) . ': ' . $error->getMessage();
		throw $error;
	} finally {
		remove_filter( 'query', $queries, PHP_INT_MAX );
		foreach ( wstm121_mutation_hooks() as $name ) {
			remove_filter( $name, $hook, PHP_INT_MIN );
		}
		wstm121_json_write( $directory . '/projection-probes.json', $evidence );
	}
}

function wstm121_worker( array $state, array $job, string $directory ): array {
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
	$allowed = static function ( int $rank ) use ( $state, $job ): bool {
		if ( true === ( $state['small_catalog'] ?? false ) ) {
			return 'dense' === $job['mode'] || ( 'sparse' === $job['mode'] && 0 === intdiv( $rank, 20 ) % 2 && 0 === $rank % 3 );
		}
		return wstm121_allowed( $job['mode'], $rank );
	};
	$expected = array_values( array_filter( $window, static fn( $id ) => $allowed( $ranks[ $id ] ) && ( ! $job['orphan'] || ! in_array( $id, $state['referenced'], true ) ) ) );
	$deny = static function ( $caps, $cap, $user, $args ) use ( $state, $ranks, $allowed ) {
		if ( (int) $user === $state['user'] && in_array( $cap, array( 'edit_post', 'read_post', 'edit_post_meta', 'read_post_meta' ), true ) && isset( $args[0], $ranks[ (int) $args[0] ] ) && ! $allowed( $ranks[ (int) $args[0] ] ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	};
	add_filter( 'map_meta_cap', $deny, PHP_INT_MAX, 4 );
	if ( 'cold' === $job['cache'] ) {
		wstm121_require( ! wp_using_ext_object_cache() && wp_cache_flush(), 'Cannot reset isolated request-local cache.' );
		wp_set_current_user( $state['user'] );
	}
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
	$record['operation_start'] = array( 'time_ns' => 0, 'current_allocated_bytes' => 0, 'historical_peak_bytes' => 0, 'wpdb_num_queries' => 0 );
	$record['operation_end'] = array( 'time_ns' => 0, 'peak_allocated_bytes' => 0, 'current_allocated_bytes' => 0, 'wpdb_num_queries' => 0 );
	$start_usage = memory_get_usage( true );
	$record['operation_start']['current_allocated_bytes'] = $start_usage;
	$record['operation_start']['historical_peak_bytes'] = memory_get_peak_usage( true );
	$record['operation_start']['wpdb_num_queries'] = $wpdb->num_queries;
	$record['operation_start']['time_ns'] = hrtime( true );
	$exception = null;
	try {
		$response = $ability->execute( $job['input'] );
		// Logical response serialization belongs to measured operation memory.
		$json = wp_json_encode( $response );
	} catch ( Throwable $error ) {
		$exception = $error;
	} finally {
		$peak = memory_get_peak_usage( true );
		$record['operation_end']['time_ns'] = hrtime( true );
		$record['operation_end']['peak_allocated_bytes'] = $peak;
		$record['operation_end']['current_allocated_bytes'] = memory_get_usage( true );
		$record['operation_end']['wpdb_num_queries'] = $wpdb->num_queries;
		remove_action( 'pre_get_posts', $query_observer, PHP_INT_MAX );
		remove_filter( 'query', $sql_observer, PHP_INT_MAX );
		remove_filter( 'user_has_cap', $cap_observer, PHP_INT_MAX );
		remove_filter( 'map_meta_cap', $deny, PHP_INT_MAX );
		foreach ( wstm121_mutation_hooks() as $hook ) {
			remove_filter( $hook, $mutation_observer, PHP_INT_MIN );
		}
	}
	$record['memory_method'] = 'same owned process; isolated request-local cache reset for cold; whole-process allocated peak minus current start bytes; conservative upper bound including prior peaks, observers and JSON; not RSS';
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
		require_once __DIR__ . '/bounded-list-verifier.php';
		Wstm121Verifier::operation( $state, $job, $record );
		$record['assertions_passed'] = true;
	} catch ( Throwable $error ) {
		$record['failure'] = get_class( $error ) . ': ' . $error->getMessage();
	} finally {
		// Retain full raw response, even failed assertions. Only this bounded page.
		wstm121_json_write( $directory . '/result.json', $record );
	}
	wstm121_require( $record['assertions_passed'], $record['failure'] ?? 'Operation assertion failed.' );
	return $record;
}



if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		require_once __DIR__ . '/bounded-product-runtime.php';
		exit( wstm121_product_main() );
	} catch ( Throwable $error ) {
		fwrite( STDERR, "Product bounded stage failed; private evidence retained.\n" );
		exit( 1 );
	}
}
