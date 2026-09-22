<?php

declare(strict_types=1);

function wstm126_expected_labels(): array {
	$labels = array();
	foreach ( array(
		'list-posts' => 'status', 'list-pages' => 'order', 'list-cpt-mcp-book' => 'orderby',
		'list-comments' => 'status', 'list-users' => 'orderby', 'get-seo-scores' => 'status',
		'get-site-kit-pagespeed' => 'strategy', 'list-posts-no-featured-image' => 'post_type',
	) as $slug => $field ) {
		foreach ( range( 0, 9 ) as $index ) { $labels[] = "{$slug}:{$field}:{$index}"; }
	}
	foreach ( array( 'post', 'cpt-mcp-book', 'cpt-mcp-case-study' ) as $type ) {
		foreach ( array( 'create', 'update' ) as $operation ) {
			foreach ( range( 0, 3 ) as $index ) { $labels[] = "{$operation}-{$type}:parent:{$index}"; }
		}
	}
	$labels[] = 'page unknown property';
	$labels[] = 'numeric string ID';
	foreach ( array( 'bulk-trash-posts', 'bulk-publish-posts', 'delete-media', 'delete-category', 'delete-tag' ) as $slug ) {
		foreach ( range( 0, 4 ) as $index ) { $labels[] = "{$slug}:confirm:{$index}"; }
		$flag = 'delete-media' === $slug ? 'force' : ( str_starts_with( $slug, 'bulk-' ) ? 'dry_run' : null );
		if ( null !== $flag ) {
			foreach ( range( 0, 5 ) as $index ) { $labels[] = "{$slug}:{$flag}:{$index}"; }
		}
	}
	return array_merge( $labels, array( 'valid subscriber denial', 'allowed hierarchical detach/omission 0', 'allowed hierarchical detach/omission 1' ) );
}

function wstm126_source_hashes( string $plugin_root, ?string $harness_root = null ): array {
	$harness_root = $harness_root ?? $plugin_root;
	$files = array(
		'production' => array( 'webmastery-site-toolkit-for-mcp.php', 'includes/class-ability.php', 'includes/class-input.php', 'includes/class-response.php' ),
		'harness' => array(
			'tests/e2e/input-schema-runner.php', 'tests/e2e/input-schema-fixture.php', 'tests/e2e/input-schema-proof.php',
			'tests/e2e/input-schema-cleanup.php', 'tests/e2e/input-schema-lifecycle.php', 'tests/e2e/input-schema-stage.php',
			'tests/e2e/input-schema-boot.php', 'tests/e2e/input-schema-probe.php', 'tests/e2e/metadata-transport.php',
			'tests/e2e/metadata-batch-fixture.php', 'tests/e2e/error-contract-fixture.php', 'tests/e2e/error-contract-assertions.php',
			'tests/e2e/custom-post-types-fixture.php', 'tests/e2e/site-kit-fixture.php',
		),
	);
	$hashes = array();
	foreach ( $files as $kind => $paths ) {
		$root = 'production' === $kind ? $plugin_root : $harness_root;
		if ( is_link( $root ) || false === realpath( $root ) ) {
			throw new RuntimeException( 'Unresolved or linked schema source root.' );
		}
		foreach ( $paths as $relative ) {
			$path = $root . '/' . $relative;
			$expected = str_replace( '\\', '/', realpath( $root ) ) . '/' . $relative;
			if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path )
				|| str_replace( '\\', '/', (string) realpath( $path ) ) !== $expected ) {
				throw new RuntimeException( 'Missing or linked schema source: ' . $relative );
			}
			$hash = hash_file( 'sha256', $path );
			if ( ! is_string( $hash ) ) { throw new RuntimeException( 'Cannot hash schema source.' ); }
			$hashes[ $kind ][ $relative ] = $hash;
		}
	}
	return $hashes;
}

function wstm126_validate_invocation( array $report, string $owner, string $source, string $project, string $boundary ): void {
	$require = static function ( bool $ok, string $message ): void {
		if ( ! $ok ) { throw new RuntimeException( $message ); }
	};
	$require( 1 === preg_match( '/^[a-f0-9]{32}$/D', $owner ) && 1 === preg_match( '/^[a-f0-9]{40}$/D', $source )
		&& 1 === preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $project )
		&& in_array( $boundary, array( 'direct', 'permission', 'ability', 'http', 'individual' ), true ), 'Invalid expected schema identity.' );
	foreach ( array( 'schema_version' => 1, 'owner' => $owner, 'source_sha' => $source, 'project' => $project,
		'boundary' => $boundary, 'expected_cases' => 152, 'completed' => true, 'cleanup_complete' => true ) as $key => $value ) {
		$require( array_key_exists( $key, $report ) && $value === $report[ $key ], 'Missing or foreign schema proof: ' . $key );
	}
	$require( ! array_key_exists( 'fatal', $report ), 'Fatal schema invocation is incomplete.' );
	$require( isset( $report['cases'] ) && is_array( $report['cases'] ) && array_keys( $report['cases'] ) === range( 0, 151 ), 'Incomplete schema case list.' );
	$labels = wstm126_expected_labels();
	$passed = 0;
	foreach ( $report['cases'] as $index => $case ) {
		$require( is_array( $case ) && ( $case['label'] ?? null ) === $labels[ $index ] && is_bool( $case['passed'] ?? null ), 'Missing, reordered or malformed schema case.' );
		if ( ! $case['passed'] ) {
			$require( is_string( $case['error'] ?? null ) && '' !== $case['error'], 'Failed case lacks failure evidence.' );
			continue;
		}
		++$passed;
		$require( isset( $case['input'], $case['before'], $case['after'], $case['evidence'], $case['result'] )
			&& is_array( $case['input'] ) && is_array( $case['before'] ) && is_array( $case['after'] )
			&& is_array( $case['evidence'] ) && ( is_array( $case['result'] ) || ( 'permission' === $boundary && $index >= 150 && true === $case['result'] ) ), 'Successful case lacks raw boundary evidence.' );
		$evidence = $case['evidence'];
		foreach ( array( 'before', 'after' ) as $phase ) {
			$require( array_keys( $case[ $phase ] ) === array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'cron' ), 'Incomplete persistent-state snapshot.' );
			foreach ( $case[ $phase ] as $table => $snapshot ) {
				if ( 'cron' === $table ) {
					$require( is_string( $snapshot ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $snapshot ), 'Missing cron snapshot.' );
				} else {
					$require( is_array( $snapshot ) && is_int( $snapshot['count'] ?? null ) && $snapshot['count'] >= 0
						&& is_string( $snapshot['sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $snapshot['sha256'] ), 'Missing table snapshot.' );
				}
			}
		}
		if ( in_array( $boundary, array( 'http', 'individual' ), true ) ) {
			$require( is_array( $case['wire'] ?? null ) && '2.0' === ( $case['wire']['jsonrpc'] ?? null )
				&& is_array( $case['wire']['result'] ?? null ), 'Missing actual HTTP response evidence.' );
		}
		$require( is_array( $evidence['callbacks'] ?? null ) && is_array( $evidence['mutations'] ?? null ), 'Missing callback/mutation observations.' );
		foreach ( $evidence['callbacks'] as $callback ) {
			$require( is_array( $callback ) && is_string( $callback['ability'] ?? null ) && is_string( $callback['stage'] ?? null )
				&& is_int( $callback['queries'] ?? null ) && $callback['queries'] >= 0
				&& is_int( $callback['capabilities'] ?? null ) && $callback['capabilities'] >= 0, 'Malformed callback observation.' );
		}
		if ( $index < 149 ) {
			$reason = 'ability_invalid_input';
			if ( 'direct' === $boundary && false !== strpos( $case['label'], ':confirm:' ) ) { $reason = 'missing_confirmation'; }
			if ( 'direct' === $boundary && ( false !== strpos( $case['label'], ':force:' ) || false !== strpos( $case['label'], ':dry_run:' ) ) ) { $reason = 'invalid_input'; }
			$require( false === ( $case['result']['success'] ?? null ) && $reason === ( $case['result']['error']['reason'] ?? null )
				&& ( 'missing_confirmation' === $reason ? 'precondition_failed' : 'invalid_input' ) === ( $case['result']['error']['code'] ?? null ), 'Changed strict input error oracle.' );
			$require( $case['before'] === $case['after'] && array() === $evidence['mutations']
				&& count( $evidence['callbacks'] ) === ( 'ability' === $boundary ? 0 : 1 ), 'Changed state or callback-count oracle.' );
			foreach ( $evidence['callbacks'] as $callback ) {
				$require( 0 === $callback['queries'] && 0 === $callback['capabilities'], 'Rejected input reached operation work.' );
			}
		} elseif ( 149 === $index ) {
			$require( false === ( $case['result']['success'] ?? null ) && 'forbidden' === ( $case['result']['error']['code'] ?? null )
				&& $case['before'] === $case['after'] && array() === $evidence['mutations']
				&& array_sum( array_column( $evidence['callbacks'], 'capabilities' ) ) > 0, 'Changed genuine authorization negative control.' );
		} else {
			$require( ( ( 'permission' === $boundary && true === $case['result'] ) || ( is_array( $case['result'] ) && true === ( $case['result']['success'] ?? null ) ) )
				&& array() !== $evidence['callbacks'], 'Missing valid input positive control.' );
			if ( 'permission' !== $boundary ) {
				$require( $case['before'] !== $case['after'] && array() !== $evidence['mutations'], 'Missing positive state/hook calibration.' );
			}
		}
	}
	$require( $passed === ( $report['passed'] ?? null ) && 152 - $passed === ( $report['failed'] ?? null ), 'Schema outcome totals do not match cases.' );
	$require( is_array( $report['cleanup'] ?? null ) && array() !== $report['cleanup'], 'Missing cleanup observations.' );
	foreach ( $report['cleanup'] as $result ) { $require( true === $result, 'Unproven schema cleanup.' ); }
	$keys = array( 'identity_validated', 'sessions_closed', 'posts_absent', 'actors_absent', 'credentials_absent', 'observation_absent', 'baseline_restored', 'journal_retired' );
	$require( is_array( $report['cleanup_proof'] ?? null ) && array_keys( $report['cleanup_proof'] ) === $keys, 'Incomplete schema cleanup attestation.' );
	foreach ( $keys as $key ) { $require( true === $report['cleanup_proof'][ $key ], 'Failed cleanup attestation: ' . $key ); }
	foreach ( array( 'input-schema-runner.php', 'input-schema-fixture.php', 'class-input.php', 'class-ability.php' ) as $key ) {
		$require( is_string( $report['hashes'][ $key ] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $report['hashes'][ $key ] ), 'Missing original source hash.' );
	}
	$require( is_array( $report['source_hashes'] ?? null ) && array_keys( $report['source_hashes'] ) === array( 'production', 'harness' ), 'Missing separated package/harness hashes.' );
	foreach ( $report['source_hashes'] as $hashes ) {
		$require( is_array( $hashes ) && array() !== $hashes, 'Empty source hash inventory.' );
		foreach ( $hashes as $path => $hash ) {
			$require( is_string( $path ) && is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $hash ), 'Malformed source hash.' );
		}
	}
	foreach ( array(
		'input-schema-runner.php' => array( 'harness', 'tests/e2e/input-schema-runner.php' ),
		'input-schema-fixture.php' => array( 'harness', 'tests/e2e/input-schema-fixture.php' ),
		'class-input.php' => array( 'production', 'includes/class-input.php' ),
		'class-ability.php' => array( 'production', 'includes/class-ability.php' ),
	) as $name => [ $kind, $path ] ) {
		$require( $report['hashes'][ $name ] === ( $report['source_hashes'][ $kind ][ $path ] ?? null ), 'Conflicting original and mounted source hashes.' );
	}
}
