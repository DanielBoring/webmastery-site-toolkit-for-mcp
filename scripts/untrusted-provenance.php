<?php

declare(strict_types=1);

require_once __DIR__ . '/release-lib.php';
require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-provenance.php';

set_exception_handler( static function ( Throwable $error ): void {
	fwrite( STDERR, 'ERROR Untrusted source provenance: ' . $error->getMessage() . PHP_EOL );
	exit( 255 );
} );

$root = str_replace( '\\', '/', realpath( dirname( __DIR__ ) ) );
$owner = $argv[1] ?? '';
$project = $argv[2] ?? '';
$mode = $argv[3] ?? '';
$artifact_directory = $argv[4] ?? '';
if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $owner ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $project )
	|| ! in_array( $mode, array( 'contract', 'e2e', 'all' ), true )
	|| 1 !== preg_match( '/^[a-zA-Z0-9_-]+\/untrusted-' . preg_quote( $owner, '/' ) . '$/D', $artifact_directory ) ) {
	throw new RuntimeException( 'Untrusted proof requires exact owner/project/mode/artifact identity.' );
}
$git = static function ( array $args ) use ( $root ): string {
	$command = implode( ' ', array_map( 'escapeshellarg', array_merge( array( 'git', '-C', $root ), $args ) ) );
	$output = array();
	exec( $command, $output, $status );
	if ( 0 !== $status ) {
		throw new RuntimeException( 'Cannot attest the exact Git source identity.' );
	}
	return implode( "\n", $output );
};
$source = $git( array( 'rev-parse', 'HEAD' ) );
$commit = $git( array( 'cat-file', '-p', $source ) );
if ( 1 !== preg_match( '/\Atree ([a-f0-9]{40})(?:\n|$)/', $commit, $matches ) ) {
	throw new RuntimeException( 'Missing exact commit tree identity.' );
}
$tree = $matches[1];
if ( 1 !== preg_match( '/^[a-f0-9]{40}$/D', $source ) || 1 !== preg_match( '/^[a-f0-9]{40}$/D', $tree ) ) {
	throw new RuntimeException( 'Invalid Git source/tree identity.' );
}
$require_committed = static function ( array $files ) use ( $git ): void {
	$git( array_merge( array( 'diff', '--quiet', 'HEAD', '--' ), array_keys( $files ) ) );
	$tracked = explode( "\n", $git( array_merge( array( 'ls-tree', '-r', '--name-only', 'HEAD', '--' ), array_keys( $files ) ) ) );
	sort( $tracked, SORT_STRING );
	if ( $tracked !== array_keys( $files ) ) {
		throw new RuntimeException( 'Proof contains uncommitted files; no source identity can be claimed.' );
	}
};
$production = release_source_files( $root );
$require_committed( $production );
$tracked = explode( "\n", $git( array( 'ls-tree', '-r', '--name-only', 'HEAD', '--', 'includes', RELEASE_SLUG . '.php', 'readme.txt', 'LICENSE' ) ) );
sort( $tracked, SORT_STRING );
if ( $tracked !== array_keys( $production ) ) {
	throw new RuntimeException( 'Production files differ from the exact committed source inventory.' );
}
$harness = Wstm108_Provenance::harness( $root );
$require_committed( $harness );
$host_files = array();
foreach ( array( 'scripts/e2e-test.sh', 'scripts/untrusted-stage.sh', 'scripts/untrusted-provenance.php',
	'scripts/untrusted-cleanup-proof.php', 'scripts/untrusted-authority.php', 'scripts/untrusted-release.php', 'scripts/destructive-retention.sh', 'scripts/qa-compose.sh',
	'scripts/untrusted-host-topology.php', 'scripts/untrusted-host-bootstrap.sh', 'scripts/untrusted-host-controller.php', 'scripts/untrusted-export.php',
	'scripts/release-lib.php', 'scripts/release-qa.sh', '.github/workflows/e2e-qa.yml', '.github/workflows/unit-tests.yml',
	'.github/workflows/release-package-qa.yml', '.github/workflows/compatibility-qa.yml', '.github/workflows/release.yml' ) as $name ) {
	$host_files[ $name ] = Wstm108_Files::file( $root . '/' . $name )['sha256'];
}
ksort( $host_files, SORT_STRING );
$require_committed( $host_files );
if ( $production !== Wstm108_Provenance::production( $root ) ) {
	throw new RuntimeException( 'Independent production file inventories disagree.' );
}
$package = getenv( 'E2E_PACKAGE_ZIP' );
$package_root = getenv( 'E2E_PACKAGE_ROOT' );
$digest = null;
if ( $package || $package_root ) {
	if ( ! $package || ! $package_root ) {
		throw new RuntimeException( 'Original ZIP and extracted production root must both be supplied.' );
	}
	release_validate_package( $root, $package );
	release_validate_runtime_tree( $package_root, $package );
	$archive = release_zip_files( $package );
	if ( $archive !== $production ) {
		throw new RuntimeException( 'Original ZIP differs from source production bytes; no rebuild or fallback is permitted.' );
	}
	$production = $archive;
	$digest = hash_file( 'sha256', $package );
}
echo json_encode( array(
	'binding' => array( 'owner' => $owner, 'project' => $project, 'source_sha' => $source, 'tree_sha' => $tree, 'package_sha256' => $digest ),
	'mode' => $mode, 'boundaries' => array( 'gateway', 'individual' ),
	'dependency_policy' => getenv( 'DEPENDENCY_POLICY' ) ?: 'pinned',
	'production_origin' => null === $digest ? 'source' : 'original-zip',
	'host_harness_root' => $root, 'artifact_directory' => $artifact_directory,
	'host_production_root' => null === $digest ? $root : str_replace( '\\', '/', realpath( $package_root ) ),
	'files' => array( 'production' => $production, 'harness' => $harness ),
	'host_files' => $host_files,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
