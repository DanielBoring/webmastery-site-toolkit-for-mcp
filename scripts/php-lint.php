<?php

declare(strict_types=1);

$repo_root = dirname(__DIR__);
$paths     = array(
	$repo_root . '/webmastery-site-toolkit-for-mcp.php',
	$repo_root . '/includes',
	$repo_root . '/scripts',
	$repo_root . '/tests',
);

$files = array();
foreach ( $paths as $path ) {
	if ( is_file( $path ) && 'php' === pathinfo( $path, PATHINFO_EXTENSION ) ) {
		$files[] = $path;
		continue;
	}

	if ( ! is_dir( $path ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}

sort( $files );

$failed = false;
foreach ( $files as $file ) {
	$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file );
	passthru( $command, $exit_code );
	if ( 0 !== $exit_code ) {
		$failed = true;
	}
}

if ( $failed ) {
	exit( 1 );
}

printf( "PASS PHP lint: %d files\n", count( $files ) );
