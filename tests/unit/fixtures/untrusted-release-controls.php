<?php

declare(strict_types=1);

// Included after the actual native capture controls. These deliberately use
// synthetic successful stage records, not WordPress/runtime acceptance.
if ( 'Windows' === PHP_OS_FAMILY || ! isset( $base, $require, $command ) ) { throw new RuntimeException( 'Native capture control context required.' ); }
$release_source = file_get_contents( dirname( __DIR__, 3 ) . '/scripts/untrusted-release.php' );
$release_class = substr( $release_source, strpos( $release_source, 'final class Wstm108_ReleaseGuard' ) );
eval( 'namespace Wstm108ClearWriteFault; use \Wstm108NativeWriteFault\Wstm108_HostAuthority; use \Wstm108_Files; use \Wstm108_Provenance; use \RuntimeException; use \Throwable; ' . $release_class );
eval( 'namespace Wstm108PostcommitFault; use \Wstm108_HostAuthority; use \Wstm108_Files; use \Wstm108_Provenance; use \RuntimeException; use \Throwable;
function unlink($path) { if (!\unlink($path)) { return false; } throw new RuntimeException("injected postcommit acknowledgment failure", 75); }
' . $release_class );
$files_source = file_get_contents( dirname( __DIR__, 2 ) . '/e2e/untrusted-content-files.php' );
eval( 'namespace Wstm108CompanionWriteFault; use \RuntimeException;
function fwrite($handle, $bytes) {
	$uri = stream_get_meta_data($handle)["uri"] ?? "";
	if (false !== strpos($uri, "-wstm108-")) {
		$budget = $GLOBALS["wstm108_companion_budget"];
		if (0 === $budget) { return false; }
		$n = \fwrite($handle, substr($bytes, 0, $budget));
		if (is_int($n)) { $GLOBALS["wstm108_companion_budget"] -= $n; }
		return $n;
	}
	return \fwrite($handle, $bytes);
}
' . substr( $files_source, strpos( $files_source, 'final class Wstm108_Files' ) ) );
eval( 'namespace Wstm108PartialCompanion; use \Wstm108_HostAuthority; use \Wstm108CompanionWriteFault\Wstm108_Files; use \Wstm108_Provenance; use \RuntimeException; use \Throwable; ' . $release_class );
eval( 'namespace Wstm108PrerequisiteNoise;
final class Wstm108_Files {
	public static function __callStatic($method, $arguments) {
		$result = \Wstm108_Files::$method(...$arguments);
		if ("file" === $method && substr($arguments[0], -12) === "context.json") {
			if (5 !== \fwrite($GLOBALS["wstm108_prerequisite_stream"], "noise")) { throw new \RuntimeException("Noise injection failed."); }
		}
		return $result;
	}
}
use \Wstm108_HostAuthority; use \Wstm108_Provenance; use \RuntimeException; use \Throwable; ' . $release_class );
foreach ( array( 'partial-companion', 'partial-clear-capture', 'replacement', 'mode', 'receipt-replacement', 'guard-false', 'guard-throws', 'prerequisite-noise', 'success', 'postcommit-fault' ) as $case ) {
	$case_root = $base . '/release-' . $case;
	foreach ( array( $case_root, $case_root . '/checkout', $case_root . '/checkout/scripts', $case_root . '/checkout/build',
		$case_root . '/checkout/e2e-artifacts', $case_root . '/checkout/e2e-artifacts/untrusted-control', $case_root . '/authority', $case_root . '/docker-data' ) as $path ) {
		$require( mkdir( $path, 0700 ), 'exclusive release control directory' );
	}
	$checkout = $case_root . '/checkout';
	$binding = array( 'owner' => bin2hex( random_bytes( 16 ) ), 'project' => 'release-control', 'source_sha' => str_repeat( 'b', 40 ), 'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
	$helper = file_get_contents( dirname( __DIR__, 3 ) . '/scripts/destructive-retention.sh' );
	Wstm108_Files::create( $checkout . '/scripts/destructive-retention.sh', $helper );
	foreach ( array( 'includes', 'tests', 'tests/e2e', '.github' ) as $name ) { $require( mkdir( $checkout . '/' . $name, 0700 ), 'synthetic source directory' ); }
	foreach ( array( 'webmastery-site-toolkit-for-mcp.php', 'readme.txt', 'LICENSE', '.github/compatibility-versions.json',
		'tests/e2e/untrusted-content-control.php', 'tests/e2e/error-contract-fixture.php', 'tests/e2e/error-contract-assertions.php',
		'tests/e2e/destructive-safety-boot.php', 'tests/e2e/destructive-safety-uploads.php', 'tests/e2e/abilities-manifest.json' ) as $name ) {
		Wstm108_Files::create( $checkout . '/' . $name, 'synthetic file identity control, never executed' );
	}
	$context = array( 'binding' => $binding, 'artifact_directory' => 'e2e-artifacts/untrusted-control',
		'host_files' => array( 'scripts/destructive-retention.sh' => hash( 'sha256', $helper ) ), 'host_production_root' => $checkout,
		'files' => array( 'production' => Wstm108_Provenance::production( $checkout ), 'harness' => Wstm108_Provenance::harness( $checkout ) ) );
	$context_file = Wstm108_Files::create( $checkout . '/' . $context['artifact_directory'] . '/context.json', json_encode( $context, JSON_THROW_ON_ERROR ) );
	Wstm108_Files::create( $checkout . '/' . $context['artifact_directory'] . '/stage.log', 'closed synthetic stage evidence' );
	foreach ( array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'restored', 'finalize', 'retire' ) as $phase ) {
		foreach ( array( '.json', '.json.http.jsonl' ) as $suffix ) { Wstm108_Files::create( $checkout . '/' . $context['artifact_directory'] . '/' . $phase . $suffix, 'synthetic-control' ); }
	}
	$mounts = Wstm108_HostBoundaryFixture::mounts( $binding['project'], $checkout, $case_root . '/docker-data' );
	$authority = Wstm108_HostAuthority::create( $case_root . '/authority', $checkout, $binding, $context_file['sha256'], $mounts );
	$h = $authority->handle();
	$environment = getenv();
	$environment['COMPOSE_PROJECT_NAME'] = $binding['project'];
	$arm = $authority->capture( 'acquire', array( 'bash', '-c', 'set -e; source "$1"; wstm116_arm_retention "$2" "$3"',
		'wstm108-control-arm', $checkout . '/scripts/destructive-retention.sh', $binding['owner'], $binding['source_sha'] ), $checkout, $environment );
	$require( 0 === $arm['child_exit'] && '' === $arm['streams']['stderr']['bytes'], 'actual unchanged primary arm first' );
	$guard = $checkout . '/build/wstm116-retention-' . $binding['project'] . '-wstm108-' . $binding['owner'];
	$primary = $checkout . '/build/wstm116-retention-' . $binding['project'];
	$release = new Wstm108_ReleaseGuard( $authority );
	if ( 'partial-companion' === $case ) {
		$GLOBALS['wstm108_companion_budget'] = 4;
		try {
			(new \Wstm108PartialCompanion\Wstm108_ReleaseGuard( $authority ))->arm();
			throw new LogicException( 'Partial companion creation accepted.' );
		} catch ( RuntimeException $expected ) {
			$require( file_exists( $primary ) && 'vers' === file_get_contents( $guard )
				&& file_exists( $h['directory'] . '/companion-intent.private.json' ) && ! file_exists( $h['directory'] . '/companion-enrollment.private.json' ), 'durable intent and partial bytes survive before any acquisition' );
		}
		unset( $GLOBALS['wstm108_companion_budget'] );
		continue;
	}
	$companion = $release->arm();
	$state = array( 'companion' => $companion, 'prepared' => array( 'synthetic' => true ), 'processes' => array() );
	foreach ( array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'runner-proof', 'restored', 'finalize', 'retire', 'final-proof' ) as $action ) {
		$c = 'acquire' === $action ? $arm : $authority->capture( $action, $command( "synthetic validated\n", '', 0 ), $checkout, $environment );
		$w = Wstm108_HostAuthority::witness( $c, true );
		$state['processes'][] = array( 'witness' => $w, 'receipt' => $authority->receipt( $action . '.process.receipt.json', $w ) );
	}
	$state['retirement_receipt'] = $authority->receipt( 'retirement.receipt.json', array( 'binding' => $binding, 'prepared' => $state['prepared'], 'processes' => $state['processes'] ) );
	if ( 'partial-clear-capture' === $case ) {
		$GLOBALS['wstm108_write_target'] = 'clear-primary.stdout.private';
		$GLOBALS['wstm108_write_budget'] = 4;
		$faulty = \Wstm108NativeWriteFault\Wstm108_HostAuthority::resume( $h );
		try {
			(new \Wstm108ClearWriteFault\Wstm108_ReleaseGuard( $faulty ))->clear_primary( $companion );
			throw new LogicException( 'Partial clear capture accepted.' );
		} catch ( RuntimeException $expected ) {
			$require( 1 === $expected->getCode() && ! file_exists( $primary ) && 'Rete' === file_get_contents( $h['directory'] . '/clear-primary.stdout.private' ), 'actual post-unlink capture failure retains exact original prefix and failure' );
			Wstm108_Files::assert_file( $guard, $companion['companion'] );
			$failed = json_decode( file_get_contents( $h['directory'] . '/clear-primary.capture-failure.private.json' ), true, 512, JSON_THROW_ON_ERROR );
			$require( 0 === $failed['child_exit'] && false === $failed['capture_complete'] && ! file_exists( $h['directory'] . '/' . Wstm108_ReleaseGuard::AUTHORIZATION ), 'zero child exit is not a successful capture/authorization' );
		}
		unset( $GLOBALS['wstm108_write_target'], $GLOBALS['wstm108_write_budget'] );
		continue;
	}
	$state['processes'][] = $release->clear_primary( $companion );
	$state['release_authorization'] = $release->authorize( $state, $context );
	if ( 'replacement' === $case ) {
		$require( rename( $guard, $case_root . '/original-companion' ), 'retain original companion' );
		Wstm108_Files::create( $guard, $companion['companion']['bytes'] );
	} elseif ( 'mode' === $case ) {
		$require( chmod( $guard, 0640 ), 'inject companion mode drift' );
	} elseif ( 'receipt-replacement' === $case ) {
		$path = $h['directory'] . '/' . Wstm108_ReleaseGuard::AUTHORIZATION;
		$bytes = Wstm108_Files::file( $path )['bytes'];
		$require( rename( $path, $case_root . '/original-authorization' ), 'retain original authorization' );
		Wstm108_Files::create( $path, $bytes );
	} elseif ( 'postcommit-fault' === $case ) {
		$release = new \Wstm108PostcommitFault\Wstm108_ReleaseGuard( $authority );
	} elseif ( 'prerequisite-noise' === $case ) {
		$GLOBALS['wstm108_prerequisite_stream'] = fopen( $case_root . '/modeled-controller-stream.private', 'x+b' );
		$require( is_resource( $GLOBALS['wstm108_prerequisite_stream'] ), 'synthetic guard has a real private noise stream' );
		$release = new \Wstm108PrerequisiteNoise\Wstm108_ReleaseGuard( $authority );
	}
	try {
		$release->commit( $state, $context, static function () use ( $case ): bool {
			if ( 'guard-throws' === $case ) { throw new RuntimeException( 'Synthetic original-stream guard veto.' ); }
			if ( 'prerequisite-noise' === $case ) { return 0 === ftell( $GLOBALS['wstm108_prerequisite_stream'] ); }
			return 'guard-false' !== $case;
		} );
		$require( 'success' === $case && ! file_exists( $guard ) && ! file_exists( $primary ), 'only completely validated terminal success releases the companion' );
	} catch ( RuntimeException $expected ) {
		$require( 'success' !== $case, 'positive terminal control must really succeed' );
		$require( ! file_exists( $primary ), 'never assert a removed primary remains' );
		if ( 'postcommit-fault' === $case ) {
			$require( 75 === $expected->getCode() && ! file_exists( $guard ), 'postcommit nonzero has no retained-guard claim' );
		} else {
			$require( file_exists( $guard ), 'precommit refusal preserves the observed companion' );
		}
		if ( 'prerequisite-noise' === $case ) {
			$stream = $GLOBALS['wstm108_prerequisite_stream'];
			$require( ftell( $stream ) > 0 && fflush( $stream ) && fsync( $stream ), 'real prerequisite noise precedes modeled final guard veto' );
			fclose( $stream ); unset( $GLOBALS['wstm108_prerequisite_stream'] );
		}
	}
	$r = json_decode( file_get_contents( $h['directory'] . '/' . Wstm108_ReleaseGuard::AUTHORIZATION ), true, 512, JSON_THROW_ON_ERROR );
	$require( 'not_observed' === $r['commit_outcome'] && 'not_asserted' === $r['qa_outcome'], 'authorization is not a completion acknowledgment, including after commit' );
}
echo "Native companion/private-capture/identity/commit controls passed with synthetic stage records; postcommit fault is expected coverage, not runtime success.\n";
