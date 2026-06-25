<?php

declare(strict_types=1);

$source_dir = $argv[1] ?? '';
$zip_file   = $argv[2] ?? '';

if ( '' === $source_dir || '' === $zip_file ) {
	fwrite( STDERR, "Usage: php scripts/create-release-zip.php <source-dir> <zip-file>\n" );
	exit( 1 );
}

if ( ! is_dir( $source_dir ) ) {
	fwrite( STDERR, "Source directory does not exist: {$source_dir}\n" );
	exit( 1 );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Could not create zip file: {$zip_file}\n" );
	exit( 1 );
}

$source_dir = rtrim( str_replace( '\\', '/', realpath( $source_dir ) ?: $source_dir ), '/' );
$base_dir   = dirname( $source_dir );

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

$zip->addEmptyDir( basename( $source_dir ) );

foreach ( $iterator as $file ) {
	$path          = str_replace( '\\', '/', $file->getPathname() );
	$relative_path = ltrim( substr( $path, strlen( str_replace( '\\', '/', $base_dir ) ) ), '/' );

	if ( $file->isDir() ) {
		$zip->addEmptyDir( $relative_path );
	} else {
		$zip->addFile( $file->getPathname(), $relative_path );
	}
}

$zip->close();
