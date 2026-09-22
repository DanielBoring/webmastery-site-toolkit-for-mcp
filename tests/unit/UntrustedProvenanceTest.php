<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-provenance.php';
require_once dirname( __DIR__, 2 ) . '/scripts/release-lib.php';

final class UntrustedProvenanceTest extends TestCase {
	private string $root;
	private array $files = array();
	private array $directories = array();

	protected function setUp(): void {
		$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm108-provenance-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $path, 0700 ) );
		$this->root = str_replace( '\\', '/', realpath( $path ) );
		foreach ( array( 'includes', 'tests', 'tests/e2e', '.github' ) as $name ) {
			$this->directories[] = $this->root . '/' . $name;
			self::assertTrue( mkdir( end( $this->directories ), 0700 ) );
		}
		foreach ( array( 'webmastery-site-toolkit-for-mcp.php', 'readme.txt', 'LICENSE', 'includes/class-proof.php',
			'tests/e2e/untrusted-content-proof.php', 'tests/e2e/error-contract-fixture.php', 'tests/e2e/error-contract-assertions.php',
			'tests/e2e/destructive-safety-boot.php', 'tests/e2e/destructive-safety-uploads.php', 'tests/e2e/abilities-manifest.json',
			'.github/compatibility-versions.json' ) as $name ) {
			$this->files[] = $this->root . '/' . $name;
			file_put_contents( end( $this->files ), 'original bytes: ' . $name );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) { unlink( $file ); }
		}
		foreach ( array_reverse( $this->directories ) as $directory ) { rmdir( $directory ); }
		rmdir( $this->root );
	}

	public function test_independent_release_source_and_original_zip_maps_match_exact_runtime_bytes(): void {
		$source = release_source_files( $this->root );
		self::assertSame( $source, Wstm108_Provenance::production( $this->root ) );
		$path = $this->root . '/original.zip';
		$this->files[] = $path;
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::EXCL ) );
		foreach ( array_keys( $source ) as $name ) {
			self::assertTrue( $zip->addFile( $this->root . '/' . $name, RELEASE_SLUG . '/' . $name ) );
		}
		self::assertTrue( $zip->close() );
		$digest = hash_file( 'sha256', $path );
		$expected = array( 'production' => release_zip_files( $path ), 'harness' => Wstm108_Provenance::harness( $this->root ) );
		self::assertSame( $source, $expected['production'] );
		self::assertSame( $expected, Wstm108_Provenance::verify( $this->root, $expected ) );
		self::assertSame( $digest, hash_file( 'sha256', $path ), 'The original archive is never rebuilt or altered.' );
	}

	public static function provenance_mutations(): array {
		return array_map( static fn( $kind ) => array( $kind ), array( 'production-byte', 'harness-byte', 'missing-production', 'extra-production', 'wrong-map', 'missing-harness' ) );
	}

	/** @dataProvider provenance_mutations */
	public function test_changed_or_incomplete_source_and_package_provenance_is_refused( string $kind ): void {
		$expected = array( 'production' => release_source_files( $this->root ), 'harness' => Wstm108_Provenance::harness( $this->root ) );
		if ( 'production-byte' === $kind ) { file_put_contents( $this->root . '/includes/class-proof.php', 'modified' ); }
		if ( 'harness-byte' === $kind ) { file_put_contents( $this->root . '/tests/e2e/untrusted-content-proof.php', 'modified' ); }
		if ( 'missing-production' === $kind ) { unlink( $this->root . '/LICENSE' ); }
		if ( 'extra-production' === $kind ) {
			$this->files[] = $this->root . '/includes/class-unexpected.php';
			file_put_contents( end( $this->files ), 'unexpected' );
		}
		if ( 'wrong-map' === $kind ) { $expected['production']['includes/class-proof.php'] = str_repeat( '0', 64 ); }
		if ( 'missing-harness' === $kind ) { unset( $expected['harness']['tests/e2e/untrusted-content-proof.php'] ); }
		$this->expectException( RuntimeException::class );
		Wstm108_Provenance::verify( $this->root, $expected );
	}
}
