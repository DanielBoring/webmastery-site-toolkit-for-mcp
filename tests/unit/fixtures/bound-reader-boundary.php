<?php

declare(strict_types=1);

namespace Wstm108ReaderBoundary;

final class Boundary {
	public static ?\Closure $during_read = null;
	public static array $lengths = array();
	public static string $outcome = 'native';
	public static int $read_number = 0;
	public static int $at_read = 1;
}

function stream_get_contents( $handle, int $length ) {
	Boundary::$lengths[] = $length;
	++Boundary::$read_number;
	if ( null !== Boundary::$during_read && Boundary::$at_read === Boundary::$read_number ) {
		$callback = Boundary::$during_read;
		Boundary::$during_read = null;
		$callback();
	}
	$bytes = \stream_get_contents( $handle, $length );
	if ( 'failed' === Boundary::$outcome ) {
		return false;
	}
	return 'short' === Boundary::$outcome ? substr( $bytes, 0, -1 ) : $bytes;
}

$source = file_get_contents( dirname( __DIR__, 2 ) . '/e2e/untrusted-content-files.php' );
$start = strpos( $source, 'final class Wstm108_Files {' );
if ( false === $start ) {
	throw new \RuntimeException( 'Bound reader source unavailable.' );
}
// Run the unchanged reader against native files, intercepting only its read boundary.
eval( 'namespace Wstm108ReaderBoundary; use \RuntimeException; ' . substr( $source, $start ) );
