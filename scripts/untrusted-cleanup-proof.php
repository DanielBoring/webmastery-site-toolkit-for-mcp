<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-files.php';
require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-proof.php';

try {
	$directory = str_replace( '\\', '/', $argv[6] ?? '' );
	$source_root = str_replace( '\\', '/', realpath( dirname( __DIR__ ) ) );
	$owner = $argv[5] ?? '';
	if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $owner )
		|| 1 !== preg_match( '/^' . preg_quote( $source_root, '/' ) . '\/[a-zA-Z0-9_-]+\/untrusted-' . $owner . '$/D', $directory ) ) {
		throw new RuntimeException( 'Foreign explicit artifact owner or source invocation root.' );
	}
	Wstm108_Files::directory( $directory );
	$owned_path = static function ( string $input, string $name ) use ( $directory ): string {
		$input = str_replace( '\\', '/', $input );
		if ( $name !== $input && $directory . '/' . $name !== $input ) {
			throw new RuntimeException( 'Unexpected evidence path outside the explicit owned directory.' );
		}
		return $directory . '/' . $name;
	};
	$context_path = $owned_path( $argv[2] ?? '', 'context.json' );
	$proof_path = $owned_path( $argv[1] ?? '', 'runner.json' );
	$context_file = Wstm108_Files::file( $context_path );
	$context = json_decode( $context_file['bytes'], true, 512, JSON_THROW_ON_ERROR );
	$relative = $context['artifact_directory'] ?? '';
	if ( $source_root !== ( $context['host_harness_root'] ?? null )
		|| ! is_string( $relative ) || 1 !== preg_match( '/^[a-zA-Z0-9_-]+\/untrusted-[a-f0-9]{32}$/D', $relative )
		|| $directory !== $source_root . '/' . $relative
		|| basename( $directory ) !== 'untrusted-' . ( $argv[5] ?? '' ) ) {
		throw new RuntimeException( 'Foreign artifact directory or source invocation root.' );
	}
	$proof_file = Wstm108_Files::file( $proof_path );
	$proof = json_decode( $proof_file['bytes'], false, 512, JSON_THROW_ON_ERROR );
	if ( ! $proof instanceof stdClass || ( $argv[3] ?? null ) !== ( $context['binding']['source_sha'] ?? null )
		|| ( $argv[4] ?? null ) !== ( $context['binding']['project'] ?? null ) || ( $argv[5] ?? null ) !== ( $context['binding']['owner'] ?? null ) ) {
		throw new RuntimeException( 'Foreign or malformed source/project/owner evidence.' );
	}
	Wstm108_Proof::runner( $proof, $context, $context_file['sha256'], Wstm108_Plan::native_from_manifest( dirname( __DIR__ ) . '/tests/e2e/abilities-manifest.json' ) );
	Wstm108_Proof::journal( $proof_path . '.http.jsonl', $proof );
	if ( '--final' === ( $argv[7] ?? null ) ) {
		Wstm108_Proof::lifecycle( dirname( $proof_path ), $proof, $context, $context_file['sha256'] );
	} elseif ( isset( $argv[7] ) ) {
		throw new RuntimeException( 'Unknown cleanup proof phase.' );
	}
	echo "Verified complete source/project/owner-bound untrusted proof and resource cleanup.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Untrusted cleanup proof rejected; runtime retained: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
