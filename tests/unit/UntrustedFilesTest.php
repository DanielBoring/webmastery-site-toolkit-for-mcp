<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-files.php';

final class UntrustedFilesTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm108-files-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->directory = realpath( $this->directory );
	}

	protected function tearDown(): void {
		foreach ( array( 'owned', 'foreign', 'link' ) as $name ) {
			$path = $this->directory . DIRECTORY_SEPARATOR . $name;
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		rmdir( $this->directory );
	}

	public function test_exclusive_owned_writes_preserve_private_permissions_and_parent_metadata(): void {
		$path = $this->directory . DIRECTORY_SEPARATOR . 'owned';
		$parent = Wstm108_Files::directory( $this->directory );
		$initial = Wstm108_Files::create( $path, "initial\0bytes" );
		$next = Wstm108_Files::update( $path, $initial, "next\\bytes\n" );
		self::assertSame( $initial['identity'], $next['identity'] );
		self::assertSame( "next\\bytes\n", $next['bytes'] );
		self::assertSame( $parent, Wstm108_Files::directory( $this->directory ) );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			self::assertSame( 0600, $next['identity']['mode'] & 0777 );
		}
		Wstm108_Files::remove( $path, $next );
		self::assertFileDoesNotExist( $path );
	}

	public function test_collision_does_not_overwrite_preexisting_bytes(): void {
		$path = $this->directory . DIRECTORY_SEPARATOR . 'foreign';
		file_put_contents( $path, 'preexisting' );
		try {
			Wstm108_Files::create( $path, 'incorrect overwrite' );
			self::fail( 'Expected collision refusal.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'collision', $error->getMessage() );
		}
		self::assertSame( 'preexisting', file_get_contents( $path ) );
	}

	public static function foreign_operations(): array {
		return array( 'update' => array( true ), 'remove' => array( false ) );
	}

	/** @dataProvider foreign_operations */
	public function test_foreign_replacement_is_never_written_or_removed( bool $update ): void {
		$path = $this->directory . DIRECTORY_SEPARATOR . 'owned';
		$owned = Wstm108_Files::create( $path, 'owned' );
		unlink( $path );
		file_put_contents( $path, 'foreign replacement' );
		try {
			if ( $update ) {
				Wstm108_Files::update( $path, $owned, 'incorrect' );
			} else {
				Wstm108_Files::remove( $path, $owned );
			}
			self::fail( 'Expected ownership refusal.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'changed', $error->getMessage() );
		}
		self::assertSame( 'foreign replacement', file_get_contents( $path ) );
	}

	public function test_unknown_directory_is_not_created(): void {
		try {
			Wstm108_Files::create( $this->directory . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'owned', 'data' );
			self::fail( 'Expected missing-directory refusal.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'already exist', $error->getMessage() );
		}
		self::assertDirectoryDoesNotExist( $this->directory . DIRECTORY_SEPARATOR . 'missing' );
	}
}
