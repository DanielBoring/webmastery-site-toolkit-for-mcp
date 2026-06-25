<?php

declare(strict_types=1);

$repo_root   = dirname(__DIR__);
$plugin_slug = 'webmastery-site-toolkit-for-mcp';
$plugin_file = $repo_root . '/' . $plugin_slug . '.php';
$readme_file = $repo_root . '/readme.txt';
$zip_file    = $argv[1] ?? '';
$tag_version = $argv[2] ?? '';

function fail( string $message ): void {
	fwrite( STDERR, "ERROR {$message}\n" );
	exit( 1 );
}

function read_file_or_fail( string $path ): string {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		fail( "Could not read {$path}" );
	}

	return $contents;
}

$plugin = read_file_or_fail( $plugin_file );
$readme = read_file_or_fail( $readme_file );

if ( ! preg_match( '/^[ \t]*\*?[ \t]*Version:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)/mi', $plugin, $version_match ) ) {
	fail( 'Could not parse plugin header Version.' );
}

if ( ! preg_match( '/^[ \t]*\*?[ \t]*Text Domain:[ \t]*(.+)$/mi', $plugin, $text_domain_match ) ) {
	fail( 'Could not parse plugin header Text Domain.' );
}

if ( ! preg_match( '/^Stable tag:[ \t]*(.+)$/mi', $readme, $stable_tag_match ) ) {
	fail( 'Could not parse readme.txt Stable tag.' );
}

$plugin_version = trim( $version_match[1] );
$text_domain    = trim( $text_domain_match[1] );
$stable_tag     = trim( $stable_tag_match[1] );
$expected       = '' !== $tag_version ? ltrim( $tag_version, 'v' ) : $plugin_version;

if ( $plugin_slug !== $text_domain ) {
	fail( "Text Domain ({$text_domain}) does not match {$plugin_slug}." );
}
if ( $expected !== $plugin_version ) {
	fail( "Expected version ({$expected}) does not match plugin header ({$plugin_version})." );
}
if ( $expected !== $stable_tag ) {
	fail( "Expected version ({$expected}) does not match readme stable tag ({$stable_tag})." );
}

$readme_lines       = preg_split( '/\R/', $readme );
$after_donate_link  = false;
$short_description  = '';
foreach ( $readme_lines as $line ) {
	if ( preg_match( '/^Donate link:/i', $line ) ) {
		$after_donate_link = true;
		continue;
	}
	if ( $after_donate_link && '' !== trim( $line ) ) {
		$short_description = trim( $line );
		break;
	}
}

if ( strlen( $short_description ) > 150 ) {
	fail( 'readme.txt short description exceeds 150 characters.' );
}

if ( ! preg_match( '/^= ' . preg_quote( $expected, '/' ) . ' =\s*$/mi', $readme ) ) {
	fail( "No plugin release notes found in readme.txt for {$expected}." );
}

if ( '' !== $zip_file ) {
	if ( ! is_file( $zip_file ) || 0 === filesize( $zip_file ) ) {
		fail( "Missing or empty release artifact: {$zip_file}" );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_file ) ) {
		fail( "Could not open release artifact: {$zip_file}" );
	}

	$entries = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$entries[] = $zip->getNameIndex( $i );
	}
	$zip->close();

	foreach ( array( "{$plugin_slug}/{$plugin_slug}.php", "{$plugin_slug}/readme.txt", "{$plugin_slug}/LICENSE" ) as $required ) {
		if ( ! in_array( $required, $entries, true ) ) {
			fail( "Release artifact is missing {$required}." );
		}
	}

	$forbidden_pattern = '#^' . preg_quote( $plugin_slug, '#' ) . '/(\.git|\.github|assets|build|e2e-artifacts|scripts|tests|vendor)/|^' . preg_quote( $plugin_slug, '#' ) . '/(BACKLOG|CHANGELOG|CONTRIBUTING|README)\.md$|^' . preg_quote( $plugin_slug, '#' ) . '/(composer\.(json|lock)|docker-compose\.yml|phpcs\.xml\.dist|\.gitattributes|\.gitignore)$#';
	foreach ( $entries as $entry ) {
		if ( preg_match( $forbidden_pattern, $entry ) ) {
			fail( "Release artifact contains repository-only file: {$entry}" );
		}
	}
}

echo "PASS release package validation for {$expected}\n";
