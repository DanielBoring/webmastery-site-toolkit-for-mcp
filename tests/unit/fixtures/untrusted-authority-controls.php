<?php

declare(strict_types=1);

require_once dirname( __DIR__, 3 ) . '/scripts/untrusted-authority.php';
require_once __DIR__ . '/untrusted-host-boundaries.php';

// Standalone native controls, not a positive Windows skip or a Docker/WP test.
if ( 'Windows' === PHP_OS_FAMILY || ! function_exists( 'posix_geteuid' ) ) {
	fwrite( STDERR, "BLOCKED: actual authority/capture filesystem controls require native POSIX.\n" );
	exit( 78 );
}

$require = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( 'Native authority control failed: ' . $message ); }
};
$root = $argv[1] ?? '';
Wstm108_Files::directory( $root );
$base = $root . '/native-authority-controls';
$require( mkdir( $base, 0700 ), 'exclusive control root' );
foreach ( array( 'checkout', 'authority', 'docker-data' ) as $name ) {
	$require( mkdir( $base . '/' . $name, 0700 ), 'exclusive control child' );
}
$binding = array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'native-capture-control', 'source_sha' => str_repeat( 'b', 40 ), 'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
$mounts = Wstm108_HostBoundaryFixture::mounts( $binding['project'], $base . '/checkout', $base . '/docker-data' );
$authority = Wstm108_HostAuthority::create( $base . '/authority', $base . '/checkout', $binding, str_repeat( 'd', 64 ), $mounts );
$handle = $authority->handle();
$command = static fn( string $out, string $err, int $exit ) => array( PHP_BINARY, '-r',
	'fwrite(STDOUT,base64_decode($argv[1]));fwrite(STDERR,base64_decode($argv[2]));exit((int)$argv[3]);',
	base64_encode( $out ), base64_encode( $err ), (string) $exit );
$capture = $authority->capture( 'original', $command( "validated\n", '', 0 ), $base . '/checkout', getenv() );
Wstm108_HostAuthority::frame( $capture, "validated\n" );
$witness = Wstm108_HostAuthority::witness( $capture, true );
$receipt = $authority->receipt( 'original.process.receipt.json', $witness );
$authority->verify_processes( array( array( 'witness' => $witness, 'receipt' => $receipt ) ) );
$out = "unknown-before-handler\0\xff\n";
$err = "unknown-provider-stderr\0\xff\n";
$capture = $authority->capture( 'enabled', $command( $out, $err, 73 ), $base . '/checkout', getenv() );
$require( 73 === $capture['child_exit'] && $out === $capture['streams']['stdout']['bytes'] && $err === $capture['streams']['stderr']['bytes'], 'actual binary stdout/stderr and child exit' );
try {
	Wstm108_HostAuthority::frame( $capture, "validated\n" );
	throw new LogicException( 'Failed child was accepted.' );
} catch ( RuntimeException $expected ) {
	$require( 73 === Wstm108_HostAuthority::witness( $capture, false )['child_exit'], 'first child exit survives frame failure' );
}

// Fault only the source class's native fwrite call, not its file helper or child.
$source = file_get_contents( dirname( __DIR__, 3 ) . '/scripts/untrusted-authority.php' );
$start = strpos( $source, 'final class Wstm108_HostAuthority' );
$end = strpos( $source, "\nif ( realpath( ", $start );
$require( false !== $start && false !== $end, 'bounded original class extraction' );
eval( 'namespace Wstm108NativeWriteFault; use \Wstm108_Files; use \Wstm108_HostTopology; use \RuntimeException; use \Throwable;
function fwrite($stream, $bytes) {
	$uri = stream_get_meta_data($stream)["uri"] ?? "";
	if (false !== strpos($uri, $GLOBALS["wstm108_write_target"] ?? "runner.stdout.private")) {
		$budget = $GLOBALS["wstm108_write_budget"];
		if (0 === $budget) { return false; }
		$count = \fwrite($stream, substr($bytes, 0, $budget));
		if (is_int($count)) { $GLOBALS["wstm108_write_budget"] -= $count; }
		return $count;
	}
	return \fwrite($stream, $bytes);
}
' . substr( $source, $start, $end - $start ) );
$GLOBALS['wstm108_write_budget'] = 4;
$faulty = \Wstm108NativeWriteFault\Wstm108_HostAuthority::resume( $handle );
try {
	$faulty->capture( 'runner', $command( str_repeat( 'x', 131072 ), str_repeat( 'y', 131072 ), 73 ), $base . '/checkout', getenv() );
	throw new LogicException( 'Partial original capture was accepted.' );
} catch ( RuntimeException $expected ) {
	$require( 73 === $expected->getCode(), 'partial-write failure preserves actual child exit' );
	$require( 'xxxx' === file_get_contents( $handle['directory'] . '/runner.stdout.private' ), 'partial original prefix retained' );
	$failure = json_decode( file_get_contents( $handle['directory'] . '/runner.capture-failure.private.json' ), true, 512, JSON_THROW_ON_ERROR );
	$require( 73 === $failure['child_exit'] && false === $failure['capture_complete'], 'durable failure cannot certify complete capture' );
	$require( ! file_exists( $handle['directory'] . '/runner.process.receipt.json' ), 'failed capture has no successful process receipt' );
}
unset( $GLOBALS['wstm108_write_budget'] );

$path = $handle['directory'] . '/reservation.receipt.json';
$bytes = file_get_contents( $path );
$require( rename( $path, $handle['directory'] . '/retained-original-receipt' ), 'retain original authority receipt' );
Wstm108_Files::create( $path, $bytes );
try {
	Wstm108_HostAuthority::resume( $handle );
	throw new LogicException( 'Same-byte replacement authority was adopted.' );
} catch ( RuntimeException $expected ) {
	$require( $bytes === file_get_contents( $path ) && $bytes === file_get_contents( $handle['directory'] . '/retained-original-receipt' ), 'foreign replacement and original both retained' );
	$require( $out === file_get_contents( $handle['directory'] . '/enabled.stdout.private' )
		&& $err === file_get_contents( $handle['directory'] . '/enabled.stderr.private' ), 'refusal never deletes original process evidence' );
}
echo "Native POSIX capture, dual-stream/first-exit preservation, injected partial-write refusal and replacement-anchor refusal passed; topology is synthetic, no Docker/WP proof.\n";
require __DIR__ . '/untrusted-release-controls.php';
