<?php

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/e2e/input-schema-proof.php';

/** Synthetic evidence for verifier mutations; not runtime or cleanup proof. */
function wstm126_fake_report( string $owner, string $source, string $project, string $boundary ): array {
	$report = array(
		'schema_version' => 1, 'owner' => $owner, 'source_sha' => $source, 'project' => $project, 'boundary' => $boundary,
		'expected_cases' => 152, 'completed' => true, 'cleanup_complete' => true, 'passed' => 152, 'failed' => 0,
		'cases' => array(), 'cleanup' => array( 'independent absence' => true ),
		'cleanup_proof' => array_fill_keys( array( 'identity_validated', 'sessions_closed', 'posts_absent', 'actors_absent', 'credentials_absent', 'observation_absent', 'baseline_restored', 'journal_retired' ), true ),
		'hashes' => array_fill_keys( array( 'input-schema-runner.php', 'input-schema-fixture.php', 'class-input.php', 'class-ability.php' ), str_repeat( 'a', 64 ) ),
		'source_hashes' => wstm126_source_hashes( dirname( __DIR__, 3 ) ),
	);
	foreach ( $report['source_hashes'] as $hashes ) {
		foreach ( $hashes as $path => $hash ) {
			if ( array_key_exists( basename( $path ), $report['hashes'] ) ) { $report['hashes'][ basename( $path ) ] = $hash; }
		}
	}
	foreach ( wstm126_expected_labels() as $index => $label ) {
		$snapshot = array_fill_keys( array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships' ), array( 'count' => 0, 'sha256' => str_repeat( 'a', 64 ) ) );
		$snapshot['cron'] = str_repeat( 'b', 64 );
		$reason = 'ability_invalid_input';
		if ( 'direct' === $boundary && false !== strpos( $label, ':confirm:' ) ) { $reason = 'missing_confirmation'; }
		if ( 'direct' === $boundary && ( false !== strpos( $label, ':force:' ) || false !== strpos( $label, ':dry_run:' ) ) ) { $reason = 'invalid_input'; }
		$callback = array( 'ability' => 'webmastery-site-toolkit-for-mcp/proof-test', 'stage' => 'permission_callback', 'queries' => 0, 'capabilities' => $index >= 149 ? 1 : 0 );
		$case = array(
			'label' => $label, 'passed' => true, 'input' => array( 'fixture' => 'Synthetic verifier input' ),
			'before' => $snapshot, 'after' => $snapshot,
			'evidence' => array( 'callbacks' => 'ability' === $boundary && $index < 149 ? array() : array( $callback ), 'mutations' => array() ),
			'result' => array( 'success' => false, 'error' => array( 'code' => 'missing_confirmation' === $reason ? 'precondition_failed' : 'invalid_input', 'reason' => $reason ) ),
		);
		if ( 149 === $index ) {
			$case['result']['error'] = array( 'code' => 'forbidden', 'reason' => 'ability_invalid_permissions' );
		}
		if ( $index >= 150 ) {
			$case['result'] = 'permission' === $boundary ? true : array( 'success' => true );
			if ( 'permission' !== $boundary ) {
				$case['after']['posts']['sha256'] = str_repeat( 'c', 64 );
				$case['evidence']['mutations'] = array( 'save_post' );
			}
		}
		if ( in_array( $boundary, array( 'http', 'individual' ), true ) ) {
			$case['wire'] = array( 'jsonrpc' => '2.0', 'id' => $index, 'result' => array( 'content' => array() ) );
		}
		$report['cases'][] = $case;
	}
	return $report;
}
