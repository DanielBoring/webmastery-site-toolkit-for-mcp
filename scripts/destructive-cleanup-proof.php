<?php

declare(strict_types=1);

// Reads only the reserved public summary; private configuration never leaves the runtime.
$path = $argv[1] ?? '';
if ( ! is_file( $path ) || is_link( $path ) ) {
	fwrite( STDERR, "Missing or unsafe destructive cleanup proof; runtime retained.\n" );
	exit( 1 );
}
try {
	$proof = json_decode( file_get_contents( $path ), false, 512, JSON_THROW_ON_ERROR );
} catch ( JsonException $error ) {
	fwrite( STDERR, "Malformed destructive cleanup proof; runtime retained.\n" );
	exit( 1 );
}
if ( ! is_object( $proof ) || true !== ( $proof->completed ?? null )
	|| true !== ( $proof->cleanup_complete ?? null )
	|| ( $argv[2] ?? null ) !== ( $proof->source_sha ?? null )
	|| ( $argv[3] ?? null ) !== ( $proof->boundary ?? null )
	|| ! in_array( $argv[4] ?? null, array( '0', '30' ), true ) || (int) $argv[4] !== ( $proof->trash_days ?? null )
	|| ! is_object( $proof->cleanup ?? null ) || array() === get_object_vars( $proof->cleanup )
	|| array_filter( get_object_vars( $proof->cleanup ), static fn( $value ): bool => true !== $value ) ) {
	fwrite( STDERR, "Destructive cleanup is incomplete or its identity differs; runtime retained.\n" );
	exit( 1 );
}
echo "Verified source-bound destructive runner cleanup.\n";
