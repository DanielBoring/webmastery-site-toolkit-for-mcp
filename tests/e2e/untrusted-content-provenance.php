<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-content-files.php';

final class Wstm108_Provenance {
	public static function production( string $root ): array {
		Wstm108_Files::directory( $root );
		$files = array();
		foreach ( array( 'webmastery-site-toolkit-for-mcp.php', 'readme.txt', 'LICENSE' ) as $name ) {
			$files[ $name ] = Wstm108_Files::file( $root . '/' . $name )['sha256'];
		}
		Wstm108_Files::directory( $root . '/includes' );
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				Wstm108_Files::directory( $file->getPathname() );
				continue;
			}
			$name = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			if ( 1 !== preg_match( '#^includes/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\.php$#D', $name ) ) {
				throw new RuntimeException( 'WSTM108 Unexpected production include path.' );
			}
			$files[ $name ] = Wstm108_Files::file( $file->getPathname() )['sha256'];
		}
		ksort( $files, SORT_STRING );
		return $files;
	}

	public static function harness( string $root ): array {
		$paths = glob( $root . '/tests/e2e/untrusted-content-*.php' );
		if ( ! is_array( $paths ) || array() === $paths ) {
			throw new RuntimeException( 'WSTM108 Missing owned harness sources.' );
		}
		foreach ( array( 'error-contract-fixture.php', 'error-contract-assertions.php', 'destructive-safety-boot.php', 'destructive-safety-uploads.php', 'abilities-manifest.json' ) as $name ) {
			$paths[] = $root . '/tests/e2e/' . $name;
		}
		$paths[] = $root . '/.github/compatibility-versions.json';
		$result = array();
		foreach ( $paths as $path ) {
			$result[ str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) ) ] = Wstm108_Files::file( $path )['sha256'];
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	public static function verify( string $root, array $expected ): array {
		$actual = array( 'production' => self::production( $root ), 'harness' => self::harness( $root ) );
		if ( $actual !== $expected ) {
			throw new RuntimeException( 'WSTM108 Actual production or harness files differ from their source/original-ZIP attestation.' );
		}
		return $actual;
	}

	public static function loaded_root( string $root ): array {
		$production = self::production( $root );
		$loaded = array();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 !== strpos( $class, 'Webmastery_MCP_' ) ) {
				continue;
			}
			$file = ( new ReflectionClass( $class ) )->getFileName();
			$file = is_string( $file ) ? str_replace( '\\', '/', $file ) : '';
			$relative = substr( $file, strlen( $root ) + 1 );
			if ( 0 !== strpos( $file, $root . '/' ) || ! isset( $production[ $relative ] ) ) {
				throw new RuntimeException( 'WSTM108 Loaded production class is outside the actual tested root: ' . $class );
			}
			$loaded[ $class ] = array( 'file' => $file, 'sha256' => $production[ $relative ] );
		}
		foreach ( array( 'Webmastery_MCP_Posts' => 'class-posts.php', 'Webmastery_MCP_Untrusted' => 'class-untrusted.php',
			'Webmastery_MCP_Response' => 'class-response.php', 'Webmastery_MCP_Permissions' => 'class-permissions.php' ) as $class => $file ) {
			if ( $root . '/includes/' . $file !== ( $loaded[ $class ]['file'] ?? null ) ) {
				throw new RuntimeException( 'WSTM108 Missing actual production class binding: ' . $class );
			}
		}
		ksort( $loaded, SORT_STRING );
		return $loaded;
	}
}
