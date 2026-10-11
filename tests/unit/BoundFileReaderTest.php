<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm108ReaderBoundary\Boundary;
use Wstm108ReaderBoundary\Wstm108_Files as BoundaryFiles;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-files.php';
require_once __DIR__ . '/fixtures/bound-reader-boundary.php';

final class BoundFileReaderTest extends TestCase {
	private string $directory;
	private string $path;
	private array $file;

	protected function setUp(): void {
		$this->directory = str_replace( '\\', '/', realpath( sys_get_temp_dir() ) ) . '/bound-reader-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->path = $this->directory . '/bound';
		$this->file = Wstm108_Files::create( $this->path, 'authenticated bytes' );
		Boundary::$during_read = null;
		Boundary::$lengths = array();
		Boundary::$outcome = 'native';
		Boundary::$read_number = 0;
		Boundary::$at_read = 1;
	}

	protected function tearDown(): void {
		Boundary::$during_read = null;
		foreach ( scandir( $this->directory ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$path = $this->directory . '/' . $name;
				self::assertTrue( is_dir( $path ) && ! is_link( $path ) ? rmdir( $path ) : unlink( $path ) );
			}
		}
		self::assertTrue( rmdir( $this->directory ) );
	}

	public static function lengths(): array {
		$cases = array();
		foreach ( array( 0, 24, 8192, 33554431, 33554432 ) as $length ) {
			foreach ( array( false, true ) as $reserved ) {
				$cases[ $length . ':' . (int) $reserved ] = array( $length, $reserved );
			}
		}
		return $cases;
	}

	/** @dataProvider lengths */
	public function testNativeExactReadThroughFiniteLimit( int $length, bool $reserved ): void {
		$handle = fopen( $this->path, 'r+b' );
		self::assertTrue( ftruncate( $handle, $length ) );
		self::assertTrue( fclose( $handle ) );
		$identity = $this->file['identity'];
		$expected_hash = hash_file( 'sha256', $this->path );
		$actual = Wstm108_Files::read_bound( $this->path, $identity, $reserved ? $length : null );
		self::assertSame( array( 'identity', 'sha256', 'bytes' ), array_keys( $actual ) );
		self::assertSame( $identity, $actual['identity'] );
		self::assertSame( $length, strlen( $actual['bytes'] ) );
		self::assertSame( $expected_hash, $actual['sha256'] );
		unset( $actual );
		$actual = BoundaryFiles::read_bound( $this->path, $identity, $reserved ? $length : null );
		self::assertSame( $expected_hash, $actual['sha256'] );
		self::assertSame( $length, strlen( $actual['bytes'] ) );
		self::assertSame( 0 === $length ? array() : array( $length ), Boundary::$lengths );
		unset( $actual );
		Boundary::$lengths = array();
		$actual = Wstm108_Files::file( $this->path );
		self::assertSame( $identity, $actual['identity'] );
		self::assertSame( $expected_hash, $actual['sha256'] );
		self::assertSame( $length, strlen( $actual['bytes'] ) );
		unset( $actual );
		$actual = BoundaryFiles::file( $this->path );
		self::assertSame( $expected_hash, $actual['sha256'] );
		self::assertSame( 0 === $length ? array() : array( $length ), Boundary::$lengths );
	}

	public static function invalidFiles(): array {
		return array_map( static fn( $case ) => array( $case ), array(
			'missing', 'foreign-identity', 'directory', 'symlink', 'parent-symlink',
			'hard-link', 'oversize', 'negative-reservation', 'oversize-reservation', 'wrong-reservation', 'locked',
		) );
	}

	/** @dataProvider invalidFiles */
	public function testInvalidFileOrReservationRefusedBeforeRead( string $case ): void {
		$path = $this->path;
		$identity = $this->file['identity'];
		$reserved = null;
		$lock = null;
		switch ( $case ) {
			case 'missing':
				self::assertTrue( unlink( $path ) );
				break;
			case 'foreign-identity':
				++$identity['ino'];
				break;
			case 'directory':
				$path = $this->directory;
				$identity = Wstm108_Files::directory( $path );
				break;
			case 'symlink':
				$path = $this->directory . '/alias';
				self::assertTrue( symlink( $this->path, $path ) );
				break;
			case 'parent-symlink':
				self::assertTrue( symlink( $this->directory, $this->directory . '/alias' ) );
				$path = $this->directory . '/alias/bound';
				break;
			case 'hard-link':
				self::assertTrue( link( $path, $this->directory . '/alias' ) );
				break;
			case 'oversize':
				$handle = fopen( $path, 'r+b' );
				self::assertTrue( ftruncate( $handle, 33554433 ) );
				self::assertTrue( fclose( $handle ) );
				break;
			case 'negative-reservation':
				$reserved = -1;
				break;
			case 'oversize-reservation':
				$reserved = 33554433;
				break;
			case 'wrong-reservation':
				$reserved = strlen( $this->file['bytes'] ) + 1;
				break;
			case 'locked':
				$lock = fopen( $path, 'r+b' );
				self::assertTrue( flock( $lock, LOCK_EX | LOCK_NB ) );
				break;
		}
		try {
			BoundaryFiles::read_bound( $path, $identity, $reserved );
			self::fail( 'Invalid authority accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringStartsWith( 'WSTM108 ', $error->getMessage() );
			self::assertSame( array(), Boundary::$lengths );
		} finally {
			if ( null !== $lock ) {
				self::assertTrue( fclose( $lock ) );
			}
			if ( ! in_array( $case, array( 'foreign-identity', 'negative-reservation', 'oversize-reservation', 'wrong-reservation' ), true ) ) {
				if ( 'hard-link' === $case ) {
					// file() observes the new link count itself, then rejects it.
					self::assertSame( 2, stat( $path )['nlink'] );
				}
				$lock = 'locked' === $case ? fopen( $path, 'r+b' ) : null;
				if ( null !== $lock ) {
					self::assertTrue( flock( $lock, LOCK_EX | LOCK_NB ) );
				}
				try {
					BoundaryFiles::file( $path );
					self::fail( 'Invalid file() authority accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertStringStartsWith( 'WSTM108 ', $error->getMessage() );
					self::assertSame( array(), Boundary::$lengths );
				} finally {
					if ( null !== $lock ) {
						self::assertTrue( fclose( $lock ) );
					}
				}
			}
		}
	}

	public static function mutations(): array {
		return array_map( static fn( $case ) => array( $case ), array(
			'truncate', 'extend', 'replace', 'mode', 'hard-link', 'failed', 'short',
		) );
	}

	/** @dataProvider mutations */
	public function testActualReadBoundaryMutationOrReadFailureIsRefused( string $case ): void {
		Boundary::$during_read = function () use ( $case ): void {
			switch ( $case ) {
				case 'truncate':
					self::assertSame( 0, file_put_contents( $this->path, '' ) );
					break;
				case 'extend':
					self::assertSame( 1, file_put_contents( $this->path, 'x', FILE_APPEND ) );
					break;
				case 'replace':
					self::assertTrue( rename( $this->path, $this->directory . '/original' ) );
					self::assertSame( strlen( $this->file['bytes'] ), file_put_contents( $this->path, $this->file['bytes'] ) );
					break;
				case 'mode':
					self::assertTrue( chmod( $this->path, 0644 ) );
					break;
				case 'hard-link':
					self::assertTrue( link( $this->path, $this->directory . '/alias' ) );
					break;
				default:
					Boundary::$outcome = $case;
			}
		};
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Authoritative journal changed while reading.' );
		BoundaryFiles::read_bound( $this->path, $this->file['identity'] );
	}

	public function testSameSizeContentMutationRemainsBoundToExpectedHashAndBytes(): void {
		Boundary::$during_read = function (): void {
			self::assertSame( strlen( $this->file['bytes'] ), file_put_contents( $this->path, str_repeat( 'x', strlen( $this->file['bytes'] ) ) ) );
		};
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Owned file bytes, mode, owner or identity changed; retaining evidence.' );
		BoundaryFiles::assert_file( $this->path, $this->file );
	}

	public static function readbackMutations(): array {
		$cases = array();
		foreach ( array( 'file', 'create', 'update-before', 'update-after' ) as $entry ) {
			foreach ( array( 'truncate', 'extend', 'replace', 'mode', 'hard-link', 'failed', 'short' ) as $mutation ) {
				$cases[ $entry . ':' . $mutation ] = array( $entry, $mutation );
			}
			if ( 'file' !== $entry ) {
				$cases[ $entry . ':content' ] = array( $entry, 'content' );
			}
		}
		return $cases;
	}

	/** @dataProvider readbackMutations */
	public function testAllCoupledReadbackBoundariesRejectMutation( string $entry, string $mutation ): void {
		$original_length = strlen( $this->file['bytes'] );
		if ( 'create' === $entry ) {
			self::assertTrue( unlink( $this->path ) );
		}
		Boundary::$at_read = 'update-before' === $entry ? 2 : ( 'update-after' === $entry ? 3 : 1 );
		Boundary::$during_read = function () use ( $mutation ): void {
			switch ( $mutation ) {
				case 'truncate':
					self::assertSame( 0, file_put_contents( $this->path, '' ) );
					break;
				case 'extend':
					self::assertSame( 1, file_put_contents( $this->path, 'x', FILE_APPEND ) );
					break;
				case 'replace':
					self::assertTrue( rename( $this->path, $this->directory . '/original' ) );
					self::assertSame( strlen( $this->file['bytes'] ), file_put_contents( $this->path, $this->file['bytes'] ) );
					break;
				case 'mode':
					self::assertTrue( chmod( $this->path, 0644 ) );
					break;
				case 'hard-link':
					self::assertTrue( link( $this->path, $this->directory . '/alias' ) );
					break;
				case 'content':
					$length = filesize( $this->path );
					self::assertSame( $length, file_put_contents( $this->path, str_repeat( 'z', $length ) ) );
					break;
				default:
					Boundary::$outcome = $mutation;
			}

		};
		try {
			if ( 'file' === $entry ) {
				BoundaryFiles::file( $this->path );
			} elseif ( 'create' === $entry ) {
				BoundaryFiles::create( $this->path, $this->file['bytes'] );
			} else {
				BoundaryFiles::update( $this->path, $this->file, 'new bytes' );
			}
			self::fail( 'Coupled readback mutation accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringStartsWith( 'WSTM108 ', $error->getMessage() );
			self::assertSame( Boundary::$at_read, Boundary::$read_number );
			self::assertSame( 'update-after' === $entry ? strlen( 'new bytes' ) : $original_length, end( Boundary::$lengths ) );
		}
	}

	public function testFileObservedHashStillRequiresExpectedContentAuthentication(): void {
		Boundary::$during_read = function (): void {
			self::assertSame( strlen( $this->file['bytes'] ), file_put_contents( $this->path, str_repeat( 'z', strlen( $this->file['bytes'] ) ) ) );
		};
		$actual = BoundaryFiles::file( $this->path );
		self::assertNotSame( $this->file['sha256'], $actual['sha256'] );
		self::assertSame( hash( 'sha256', $actual['bytes'] ), $actual['sha256'] );
		$this->expectException( RuntimeException::class );
		BoundaryFiles::assert_file( $this->path, $this->file );
	}

	/** @dataProvider lengths */
	public function testNativeCreateUpdateAndReadbackThroughLimit( int $length, bool $reserved ): void {
		$bytes = str_repeat( 'x', $length );
		if ( $reserved ) {
			$actual = Wstm108_Files::update( $this->path, $this->file, $bytes );
		} else {
			self::assertTrue( unlink( $this->path ) );
			$actual = Wstm108_Files::create( $this->path, $bytes );
		}
		self::assertSame( $bytes, $actual['bytes'] );
		self::assertSame( hash( 'sha256', $bytes ), $actual['sha256'] );
		unset( $bytes );
		Wstm108_Files::assert_file( $this->path, $actual );
		self::assertSame( $actual, Wstm108_Files::update( $this->path, $actual, $actual['bytes'] ) );
	}

	public function testUpdateKeepsBeforeAndAfterReadbackAllocationExact(): void {
		$actual = BoundaryFiles::update( $this->path, $this->file, 'new bytes' );
		self::assertSame( 'new bytes', $actual['bytes'] );
		self::assertSame( array( strlen( $this->file['bytes'] ), strlen( $this->file['bytes'] ), 9, 9 ), Boundary::$lengths );
	}

	public function testUpdateRejectsForeignExpectedHashBeforePersistence(): void {
		$expected = $this->file;
		$expected['sha256'] = str_repeat( '0', 64 );
		try {
			BoundaryFiles::update( $this->path, $expected, 'not authorized' );
			self::fail( 'Foreign expected hash accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Owned file bytes, mode, owner or identity changed', $error->getMessage() );
			self::assertSame( $this->file, Wstm108_Files::file( $this->path ) );
		}
	}

	public function testNoMaximumSizedReadVariantRemainsInSharedHelper(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/untrusted-content-files.php' );
		self::assertStringNotContainsString( '33554433', $source );
		self::assertSame( 1, substr_count( $source, 'stream_get_contents(' ) );
		self::assertStringNotContainsString( 'file_get_contents(', $source );
	}
}
