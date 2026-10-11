<?php

declare(strict_types=1);

namespace WstmProductArtifactExport;

use RuntimeException;

// Execute the real codec with only context acquisition, UID/GID observations
// and its output stream isolated from the host and PHPUnit's public output.
final class Wstm167_Product_Context {
	public static array $context = array();
	public static int $rechecks = 0;

	public static function open(): self { return new self(); }
	public function data(): array { return self::$context; }
	public function recheck(): void { ++self::$rechecks; }
}

function lstat( string $path ) {
	$stat = \lstat( $path );
	if ( false !== $stat ) {
		if ( ! function_exists( 'posix_geteuid' ) || \posix_geteuid() === $stat['uid'] ) { $stat['uid'] = 33; }
		if ( ! function_exists( 'posix_getegid' ) || \posix_getegid() === $stat['gid'] ) { $stat['gid'] = 33; }
	}
	return $stat;
}

if ( ! defined( __NAMESPACE__ . '\\STDOUT' ) ) {
	$stream = fopen( 'php://temp', 'w+b' );
	if ( false === $stream ) { throw new RuntimeException( 'Private test output reservation failed.' ); }
	define( __NAMESPACE__ . '\\STDOUT', $stream );
}

$source = file_get_contents( dirname( __DIR__, 3 ) . '/scripts/product-artifacts.php' );
if ( ! is_string( $source ) ) { throw new RuntimeException( 'Actual product codec source is unavailable.' ); }
$end = strpos( $source, "\nif (isset(\$_SERVER['SCRIPT_FILENAME'])" );
if ( false === $end ) { throw new RuntimeException( 'Actual product codec entrypoint is unavailable.' ); }
$source = str_replace(
	array( 'declare(strict_types=1);', "require_once __DIR__ . '/../tests/e2e/product-runtime-context.php';" ),
	'',
	substr( $source, 5, $end - 5 )
);
eval( 'namespace WstmProductArtifactExport; use \DirectoryIterator; use \RuntimeException;' . $source );
unset( $source, $end, $stream );

function capture( string $artifact_root, string $run ): string {
	if ( ! ftruncate( STDOUT, 0 ) || ! rewind( STDOUT ) ) { throw new RuntimeException( 'Private test output reset failed.' ); }
	Wstm167_Product_Context::$context = array( 'artifact_root' => $artifact_root, 'run' => $run );
	Wstm167_Product_Context::$rechecks = 0;
	Wstm_Product_Artifacts::export( 'bounded' );
	if ( ! rewind( STDOUT ) ) { throw new RuntimeException( 'Private test output rewind failed.' ); }
	$bytes = stream_get_contents( STDOUT );
	if ( ! is_string( $bytes ) ) { throw new RuntimeException( 'Private test output acquisition failed.' ); }
	return $bytes;
}
