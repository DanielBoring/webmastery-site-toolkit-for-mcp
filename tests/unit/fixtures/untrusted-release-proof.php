<?php

declare(strict_types=1);

require_once dirname( __DIR__, 3 ) . '/scripts/untrusted-authority.php';

$require = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( 'Release mock proof: ' . $message ); }
};
$require( '1' === getenv( 'WSTM108_MOCK_ONLY' ) && 'Windows' !== PHP_OS_FAMILY, 'explicit native mock boundary required' );
$checkout = (string) getenv( 'WSTM108_MOCK_CHECKOUT' );
$live = (string) getenv( 'WSTM108_MOCK_LIVE' );
$project = (string) getenv( 'COMPOSE_PROJECT_NAME' );
$mode = $argv[1] ?? '';
Wstm108_Files::directory( $checkout );
Wstm108_Files::directory( $live );
$contexts = glob( $checkout . '/e2e-artifacts/untrusted-*/context.json' );
$matches = array();
foreach ( $contexts as $path ) {
	$c = json_decode( Wstm108_Files::file( $path )['bytes'], true, 512, JSON_THROW_ON_ERROR );
	$guard = $checkout . '/build/wstm116-retention-' . $project . '-wstm108-' . $c['binding']['owner'];
	if ( file_exists( $guard ) ) { $matches[] = array( $c, $path, $guard ); }
}
if ( 'isolate' === $mode && array() === $matches ) { exit( 0 ); }
if ( in_array( $mode, array( 'success', 'postcommit-ack' ), true ) ) {
	$require( array() === $matches && ! file_exists( $checkout . '/build/wstm116-retention-' . $project ), 'committed terminal release has no remaining guard' );
	// The executable boundary saved the exact context of this invocation.
	$path = Wstm108_Files::file( $live . '/context-path.private' )['bytes'];
	$c = json_decode( Wstm108_Files::file( $path )['bytes'], true, 512, JSON_THROW_ON_ERROR );
} else {
	$require( 1 === count( $matches ), 'one observed companion required, not an assumed primary' );
	list( $c, $path, $guard ) = $matches[0];
}
$directory = (string) getenv( 'WSTM108_HOST_AUTHORITY_ROOT' ) . '/wstm108-authority-' . $project . '-' . $c['binding']['owner'];
$primary = $checkout . '/build/wstm116-retention-' . $project;
if ( 'isolate' === $mode ) {
	// Archive only exact original or explicitly injected mock files, never
	// authorize production recovery or silently delete a failed case's bytes.
	$injection = $live . '/injected-companion.private.json';
	$expected = file_exists( $injection )
		? json_decode( Wstm108_Files::file( $injection )['bytes'], true, 512, JSON_THROW_ON_ERROR )
		: json_decode( Wstm108_Files::file( $directory . '/companion-enrollment.private.json' )['bytes'], true, 512, JSON_THROW_ON_ERROR )['companion'];
	Wstm108_Files::assert_file( $guard, $expected );
	$require( ! file_exists( $live . '/observed-companion-guard' ) && rename( $guard, $live . '/observed-companion-guard' ), 'retain observed companion in the completed case archive' );
	if ( file_exists( $primary ) ) {
		$b = $c['binding'];
		$require( Wstm108_Files::file( $primary )['bytes'] === "owner={$b['owner']}\nproject={$b['project']}\nsource={$b['source_sha']}\n", 'archive only the exact fixture primary' );
		$require( ! file_exists( $live . '/observed-primary-guard' ) && rename( $primary, $live . '/observed-primary-guard' ), 'retain observed primary' );
	}
	exit( 0 );
}
$receipt = $directory . '/' . Wstm108_ReleaseGuard::AUTHORIZATION;
if ( 'companion-partial' === $mode ) {
	$require( file_exists( $primary ) && 'partial companion bytes' === Wstm108_Files::file( $guard )['bytes'], 'primary and partial companion retained' );
	$require( file_exists( $directory . '/companion-intent.private.json' ) && ! file_exists( $directory . '/companion-enrollment.private.json' )
		&& ! file_exists( $live . '/private-state' ) && ! file_exists( $receipt ), 'intent survives without adoption, acquisition or credentials' );
} else {
	$require( ! file_exists( $primary ), 'do not claim an already-unlinked primary remains' );
	$record = json_decode( Wstm108_Files::file( $directory . '/companion-enrollment.private.json' )['bytes'], true, 512, JSON_THROW_ON_ERROR );
	if ( 'companion-replacement' === $mode ) {
		Wstm108_Files::assert_file( $live . '/retained-original-companion', $record['companion'] );
		$require( Wstm108_Files::file( $guard )['identity'] !== $record['companion']['identity']
			&& Wstm108_Files::file( $guard )['bytes'] === $record['companion']['bytes'], 'same-byte replacement remains foreign and undeleted' );
	} elseif ( ! in_array( $mode, array( 'success', 'postcommit-ack' ), true ) ) {
		Wstm108_Files::assert_file( $guard, $record['companion'] );
	}
	$require( file_exists( $directory . '/retirement.receipt.json' ), 'pre-clear runtime/resource retirement was fully validated' );
	$stdout = Wstm108_Files::file( $directory . '/clear-primary.stdout.private' )['bytes'];
	$stderr = Wstm108_Files::file( $directory . '/clear-primary.stderr.private' )['bytes'];
	$clear = json_decode( Wstm108_Files::file( $directory . '/clear-primary.process.receipt.json' )['bytes'], true, 512, JSON_THROW_ON_ERROR );
	$require( $clear['stdout']['sha256'] === hash( 'sha256', $stdout ) && $clear['stderr']['sha256'] === hash( 'sha256', $stderr ), 'actual clear originals retained with exact hashes' );
	if ( in_array( $mode, array( 'clear-echo', 'clear-exit', 'clear-truncated' ), true ) ) {
		$require( false === $clear['validated'] && ! file_exists( $receipt ), 'ambiguous clear never authorizes release' );
		if ( 'clear-exit' === $mode ) { $require( 73 === $clear['child_exit'], 'first clear nonzero preserved' ); }
		if ( 'clear-echo' === $mode ) { $require( '' === $stdout && '' !== $stderr && 0 !== $clear['child_exit'], 'real unchanged helper echo failed after unlink' ); }
		if ( 'clear-truncated' === $mode ) { $require( 16 === strlen( $stdout ) && 16 < strlen( Wstm108_Files::file( $live . '/clear-full.private' )['bytes'] ), 'partial frame refused, original full fixture output retained' ); }
	} else {
		$require( true === $clear['validated'] && 0 === $clear['child_exit'], 'exact clear frame and zero exit required' );
	}
}
if ( 'authorization-collision' === $mode ) {
	$require( 'preexisting receipt bytes must survive' === Wstm108_Files::file( $receipt )['bytes'], 'exclusive receipt collision preserved' );
} elseif ( in_array( $mode, array( 'precommit-evidence', 'companion-replacement', 'success', 'postcommit-ack' ), true ) ) {
	$r = json_decode( Wstm108_Files::file( $receipt )['bytes'], true, 512, JSON_THROW_ON_ERROR );
	$require( array_keys( $r ) === array( 'version', 'state', 'binding', 'context_sha256', 'prepared_sha256', 'process_verdicts_sha256', 'evidence_inventory_sha256',
		'primary_guard_absent', 'companion_guard_present', 'commit_point', 'commit_outcome', 'qa_outcome' ), 'one strictly typed public authorization shape' );
	$require( 1 === $r['version'] && 'precommit_authorized' === $r['state'] && $c['binding'] === $r['binding']
		&& true === $r['primary_guard_absent'] && true === $r['companion_guard_present']
		&& 'final_companion_unlink' === $r['commit_point'] && 'not_observed' === $r['commit_outcome'] && 'not_asserted' === $r['qa_outcome'], 'receipt is never a committed-release or green-QA acknowledgment' );
	foreach ( array( 'context_sha256', 'prepared_sha256', 'process_verdicts_sha256', 'evidence_inventory_sha256' ) as $key ) {
		$require( is_string( $r[ $key ] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $r[ $key ] ), 'typed nonsecret digest' );
	}
	foreach ( array( $directory, $checkout, 'stdout', 'stderr', 'identity', 'credentials', 'private' ) as $forbidden ) {
		$require( false === strpos( json_encode( $r, JSON_THROW_ON_ERROR ), $forbidden ), 'receipt excludes private authority and transcript data' );
	}
} elseif ( 'authorization-collision' !== $mode ) {
	$require( ! file_exists( $receipt ), 'no release authorization on earlier failure' );
}
echo 'Verified synthetic release case ' . $mode . ": observed remaining guards and precommit-only receipt; no real runtime success claim.\n";
