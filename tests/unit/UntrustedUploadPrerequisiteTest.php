<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-resources.php';
require_once __DIR__ . '/fixtures/untrusted-upload-metadata.php';

final class UntrustedUploadPrerequisiteTest extends TestCase {
	private string $directory;
	private string $root;
	private string $base;
	private array $calls = array();

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm108-upload-base-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->directory = realpath( $this->directory );
		$this->root = $this->directory . '/wordpress';
		self::assertTrue( mkdir( $this->root, 0755 ) );
		self::assertTrue( mkdir( $this->root . '/wp-content', 0755 ) );
		$this->base = $this->root . '/wp-content/uploads';
	}

	protected function tearDown(): void {
		$this->remove_fixture( $this->directory );
	}

	private function remove_fixture( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			unlink( $path );
			return;
		}
		chmod( $path, 0700 );
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_fixture( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

	private function configuration( string $subdir = '' ): array {
		return array( 'path' => $this->base . $subdir, 'url' => 'http://localhost/wp-content/uploads' . $subdir,
			'subdir' => $subdir, 'basedir' => $this->base, 'baseurl' => 'http://localhost/wp-content/uploads', 'error' => false );
	}

	private function native_creation( bool $create, string $subdir = '' ): array {
		$this->calls[] = $create;
		if ( $create ) {
			foreach ( array( $this->base, $this->base . substr( $subdir, 0, 5 ), $this->base . $subdir ) as $path ) {
				if ( ! is_dir( $path ) ) { self::assertTrue( mkdir( $path, 0755 ) ); }
			}
		}
		return $this->configuration( $subdir );
	}

	private function resources( string $kind ): Wstm108_Resources {
		return new Wstm108_Resources( $this->directory . '/resources.json',
			array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'owned-upload-project',
				'identity_kind' => $kind, 'identity_sha256' => str_repeat( 'b', 64 ) ), 'wstm108-0123456789abcdef' );
	}

	public static function successful_prerequisites(): array {
		$cases = array();
		foreach ( array( 'source', 'package' ) as $kind ) {
			foreach ( array( false, true ) as $existing ) {
				foreach ( array( '', '/2026/10' ) as $subdir ) {
					$cases[] = array( $kind, $existing, $subdir );
				}
			}
		}
		return $cases;
	}

	/** @dataProvider successful_prerequisites */
	public function test_native_prerequisite_then_exact_owned_child_and_file_journal( string $kind, bool $existing, string $subdir ): void {
		if ( $existing ) { $this->native_creation( true, $subdir ); }
		$this->calls = array();
		$resources = $this->resources( $kind );
		$journal = Wstm108_Files::file( $this->directory . '/resources.json' );
		$base = Wstm108_Files::native_upload_base( $this->root,
			fn( bool $create ): array => $this->native_creation( $create, $subdir ) );
		self::assertSame( array( false, true ), $this->calls );
		self::assertSame( $this->base, $base );
		self::assertSame( $journal, Wstm108_Files::file( $this->directory . '/resources.json' ) );
		$native_base = Wstm108_Files::directory( $base );
		$child = $resources->create_upload_directory( $base );
		self::assertSame( $base . '/wstm108-0123456789abcdef', $child );
		$file = $child . '/wstm108-0123456789abcdef-image.png';
		$resources->intent( 'file', array( 'path' => $file, 'sha256' => hash( 'sha256', 'owned image bytes' ) ) );
		$owned = Wstm108_Files::create( $file, 'owned image bytes', true );
		$resources->file( $file );
		$state = json_decode( file_get_contents( $this->directory . '/resources.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $base, $state['upload']['root'] );
		self::assertSame( $native_base, $state['upload']['root_identity'] );
		self::assertSame( $child, $state['upload']['path'] );
		self::assertCount( 1, $state['files'] );
		self::assertTrue( $state['intents'][0]['resolved'] );
		Wstm108_Files::remove( $file, $owned );
		self::assertTrue( rmdir( $child ) );
		self::assertSame( $native_base, Wstm108_Files::directory( $base ) );
		self::assertDirectoryExists( $base . $subdir );
	}

	public static function refusals(): array {
		return array_map( static fn( string $mode ): array => array( $mode ), array(
			'native-error-before', 'native-error-after', 'no-created-base', 'wrong-base', 'wrong-path', 'noncanonical-base',
			'noncanonical-root', 'symlink-base', 'symlink-parent', 'symlink-date', 'unreadable-parent', 'unreadable-base',
			'foreign-writable-base', 'configuration-drift', 'parent-drift', 'base-file', 'path-traversal',
			'wrong-base-after', 'wrong-path-after', 'symlink-base-after', 'observation-parent-drift', 'extra-parent-child',
		) );
	}

	/** @dataProvider refusals */
	public function test_refusal_precedes_child_creation_and_journal_writes( string $mode ): void {
		$resources = $this->resources( 'source' );
		$root = $this->root;
		if ( in_array( $mode, array( 'unreadable-base', 'foreign-writable-base', 'symlink-date' ), true ) ) {
			self::assertTrue( mkdir( $this->base, 0755 ) );
		}
		if ( 'unreadable-parent' === $mode ) { chmod( $root . '/wp-content', 0000 ); }
		if ( 'unreadable-base' === $mode ) { chmod( $this->base, 0000 ); }
		if ( 'foreign-writable-base' === $mode ) { chmod( $this->base, 0777 ); }
		if ( 'base-file' === $mode ) { file_put_contents( $this->base, 'foreign file' ); }
		if ( 'noncanonical-root' === $mode ) { $root .= '/.'; }
		if ( in_array( $mode, array( 'symlink-base', 'symlink-parent', 'symlink-date' ), true ) ) {
			if ( 'Windows' === PHP_OS_FAMILY ) { self::markTestSkipped( 'Requires native symlink fixture permission.' ); }
			self::assertTrue( mkdir( $this->directory . '/foreign', 0755 ) );
			$path = 'symlink-parent' === $mode ? $root . '/wp-content' : $this->base;
			if ( 'symlink-parent' === $mode ) { rmdir( $path ); }
			if ( 'symlink-date' === $mode ) { $path .= '/2026'; }
			self::assertTrue( symlink( $this->directory . '/foreign', $path ) );
		}
		if ( 'symlink-base-after' === $mode ) {
			if ( 'Windows' === PHP_OS_FAMILY ) { self::markTestSkipped( 'Requires native symlink fixture permission.' ); }
			self::assertTrue( mkdir( $this->directory . '/foreign', 0755 ) );
		}
		$journal = Wstm108_Files::file( $this->directory . '/resources.json' );
		try {
			$base = Wstm108_Files::native_upload_base( $root, function ( bool $create ) use ( $mode ): array {
				$this->calls[] = $create;
				$config = $this->configuration( 'symlink-date' === $mode ? '/2026/10' : '' );
				if ( 'native-error-before' === $mode || ( $create && 'native-error-after' === $mode ) ) { $config['error'] = 'Native creation failed.'; }
				if ( 'wrong-base' === $mode ) { $config['basedir'] = $this->directory . '/foreign'; }
				if ( 'wrong-path' === $mode ) { $config['path'] = $this->directory . '/foreign'; }
				if ( 'noncanonical-base' === $mode ) { $config['basedir'] .= '/.'; }
				if ( 'path-traversal' === $mode ) { $config['subdir'] = '/../foreign'; $config['path'] = $this->base . $config['subdir']; }
				if ( $create && 'wrong-base-after' === $mode ) { $config['basedir'] = $this->directory . '/foreign'; }
				if ( $create && 'wrong-path-after' === $mode ) { $config['path'] = $this->directory . '/foreign'; }
				if ( $create && 'symlink-base-after' === $mode ) { self::assertTrue( symlink( $this->directory . '/foreign', $this->base ) ); }
				if ( $create && 'configuration-drift' === $mode ) { $config['baseurl'] .= '/drift'; }
				if ( $create && 'parent-drift' === $mode ) { chmod( $this->root . '/wp-content', 0700 ); }
				if ( ! $create && 'observation-parent-drift' === $mode ) { chmod( $this->root . '/wp-content', 0700 ); }
				if ( $create && 'extra-parent-child' === $mode ) {
					self::assertTrue( mkdir( $this->base, 0755 ) );
					self::assertTrue( mkdir( $this->root . '/wp-content/unexpected-child', 0755 ) );
				}
				return $config;
			} );
			$resources->create_upload_directory( $base );
			self::fail( 'Expected native prerequisite refusal.' );
		} catch ( RuntimeException $error ) {
			self::assertStringStartsWith( 'WSTM108 ', $error->getMessage() );
		}
		self::assertSame( $journal, Wstm108_Files::file( $this->directory . '/resources.json' ) );
		self::assertDirectoryDoesNotExist( $this->base . '/wstm108-0123456789abcdef' );
		$state = json_decode( $journal['bytes'], true, 512, JSON_THROW_ON_ERROR );
		self::assertNull( $state['upload'] );
		self::assertSame( array(), $state['files'] );
		self::assertSame( array(), $state['intents'] );
		if ( in_array( $mode, array( 'native-error-after', 'no-created-base', 'configuration-drift', 'parent-drift',
			'wrong-base-after', 'wrong-path-after', 'symlink-base-after', 'extra-parent-child' ), true ) ) {
			self::assertSame( array( false, true ), $this->calls );
		} else {
			self::assertNotContains( true, $this->calls );
		}
	}

	public function test_actual_runner_uses_shared_prerequisite_before_child_without_cleanup_changes(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/untrusted-content-runner.php' );
		self::assertStringContainsString( "Wstm108_Files::native_upload_base( \$context['wordpress_root']", $source );
		self::assertStringContainsString( 'wp_upload_dir( null, $create, true )', $source );
		self::assertStringContainsString( '$resources->create_upload_directory( $upload_base )', $source );
		self::assertLessThan( strpos( $source, '$resources->create_upload_directory( $upload_base )' ),
			strpos( $source, 'Wstm108_Files::native_upload_base(' ) );
	}

	/** @dataProvider successful_prerequisites */
	public function test_actual_runner_prerequisite_through_attachment_metadata( string $kind, bool $existing, string $subdir ): void {
		$this->execute_attachment_sequence( $kind, $existing, $subdir, false );
	}

	public function test_original_stale_variable_fails_executed_sequence_before_metadata_write(): void {
		$this->execute_attachment_sequence( 'source', false, '', true );
	}

	private function execute_attachment_sequence( string $kind, bool $existing, string $subdir, bool $stale ): void {
		if ( $existing ) { $this->native_creation( true, $subdir ); }
		$this->calls = array();
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/untrusted-content-runner.php' );
		$start = strpos( $source, "\t\$product->wordpress();\n\t\$upload_base =" );
		$end = strpos( $source, "\n\t// Seed only owned post metadata.", $start );
		self::assertIsInt( $start );
		self::assertIsInt( $end );
		$code = substr( $source, $start, $end - $start );
		if ( $stale ) {
			$code = str_replace( 'strlen( $upload_base )', 'strlen( $upload[\'basedir\'] )', $code, $replacements );
			self::assertSame( 1, $replacements );
		}
		$resources = new \Wstm108UploadMetadataFixture\Resources( $this->resources( $kind ) );
		try {
			\Wstm108UploadMetadataFixture\execute( $code, $this->root,
				fn( bool $create ): array => $this->native_creation( $create, $subdir ), $resources );
			self::assertFalse( $stale, 'Original stale code must not reach metadata write.' );
		} catch ( ErrorException $error ) {
			self::assertTrue( $stale, $error->getMessage() );
			self::assertStringContainsString( 'Undefined variable $upload', $error->getMessage() );
		}
		self::assertSame( array( false, true ), $this->calls );
		$native = \Wstm108UploadMetadataFixture\Native::$attachment;
		self::assertSame( array( 31 ), $resources->posts );
		self::assertCount( 2, $resources->attachment_intents );
		self::assertSame( array( 31, '_wp_attachment_image_alt', 'inert owned attachment fixture' ), \Wstm108UploadMetadataFixture\Native::$alt );
		$state = json_decode( file_get_contents( $this->directory . '/resources.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $this->base, $state['upload']['root'] );
		self::assertCount( 1, $state['files'] );
		self::assertTrue( $state['intents'][0]['resolved'] );
		$expected = array( 'width' => 1, 'height' => 1,
			'file' => 'wstm108-0123456789abcdef/' . basename( $native['file'] ) );
		self::assertSame( $stale ? array() : $expected, \Wstm108UploadMetadataFixture\Native::$metadata );
		self::assertSame( $this->base . '/' . $expected['file'], $native['file'] );
		self::assertFileExists( $native['file'] );
	}
}
