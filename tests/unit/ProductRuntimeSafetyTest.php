<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/bounded-product-runtime.php';
require_once dirname( __DIR__ ) . '/e2e/untrusted-content-private-wire.php';
require_once dirname( __DIR__ ) . '/e2e/untrusted-content-evidence.php';

final class ProductRuntimeSafetyTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = str_replace( '\\', '/', realpath( sys_get_temp_dir() ) ) . '/wstm167-product-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );
	}

	protected function tearDown(): void {
		foreach ( scandir( $this->directory ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				self::assertTrue( unlink( $this->directory . '/' . $name ) );
			}
		}
		self::assertTrue( rmdir( $this->directory ) );
	}

	private function property( object $object, string $name ): ReflectionProperty {
		$property = new ReflectionProperty( $object, $name );
		$property->setAccessible( true );
		return $property;
	}

	private function refuses( callable $action ): void {
		try {
			$action();
		} catch ( RuntimeException $error ) {
			self::assertNotSame( '', $error->getMessage() );
			return;
		}
		self::fail( 'Ownership/quota mutation unexpectedly accepted.' );
	}

	public function testCheckedOutputKeepsFailedAcquisitionDebitAndCannotRetryThatPath(): void {
		$output = new Wstm167_Product_Output( $this->directory, static function (): void {} );
		$path = $this->directory . '/collision.json';
		Wstm108_Files::create( $path, 'foreign preserved bytes' );
		$this->refuses( static fn() => $output->write( $path, 'reserved private attempt' ) );
		$bytes = $this->property( $output, 'bytes' )->getValue( $output );
		self::assertSame( strlen( 'reserved private attempt' ), $bytes );
		$this->refuses( static fn() => $output->write( $path, 'retry' ) );
		self::assertSame( $bytes, $this->property( $output, 'bytes' )->getValue( $output ) );
		self::assertSame( 'foreign preserved bytes', file_get_contents( $path ) );
	}

	public function testUpdateDriftRetainsDebitAndNeverRestoresOrRetriesForeignBytes(): void {
		$output = new Wstm167_Product_Output( $this->directory, static function (): void {} );
		$path = $this->directory . '/owned.json';
		$output->write( $path, 'first' );
		file_put_contents( $path, 'drift' );
		$this->refuses( static fn() => $output->write( $path, 'update-attempt' ) );
		self::assertSame( strlen( 'firstupdate-attempt' ), $this->property( $output, 'bytes' )->getValue( $output ) );
		$this->refuses( static fn() => $output->write( $path, 'second-retry' ) );
		self::assertSame( 'drift', file_get_contents( $path ) );
	}

	public function testOutputExhaustionAndLexicalEscapesRefuseBeforeCreatingFiles(): void {
		$output = new Wstm167_Product_Output( $this->directory, static function (): void {} );
		foreach ( array( '../escape.json', './dot.json', 'unsafe name.json', 'missing/child.json' ) as $relative ) {
			$this->refuses( fn() => $output->write( $this->directory . '/' . $relative, 'attempt' ) );
		}
		$this->property( $output, 'bytes' )->setValue( $output, 536870912 );
		$this->refuses( fn() => $output->write( $this->directory . '/exhausted.json', 'x' ) );
		self::assertFileDoesNotExist( $this->directory . '/exhausted.json' );
		$this->property( $output, 'bytes' )->setValue( $output, 0 );
		$this->property( $output, 'files' )->setValue( $output, array_fill_keys( range( 1, 4096 ), null ) );
		$this->refuses( fn() => $output->write( $this->directory . '/slots.json', 'x' ) );
		self::assertFileDoesNotExist( $this->directory . '/slots.json' );
	}

	public function testResumingWireRetainsCommittedAndFailedIntentByteReservations(): void {
		$binding = array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'wstm167-model',
			'identity_kind' => 'source', 'identity_sha256' => str_repeat( 'b', 64 ) );
		$wire = Wstm108_PrivateWire::create( $this->directory, $binding, str_repeat( 'c', 64 ), static function (): void {} );
		$wire->begin( 'runner' );
		$wire->capture( 'runner', array( 'body' => 'private original' ) );
		Wstm108_Files::create( $this->directory . '/wire-000002.bin', 'foreign frame' );
		$this->refuses( static fn() => $wire->capture( 'runner', array( 'body' => 'failed acquisition' ) ) );
		$state = json_decode( file_get_contents( $this->directory . '/private-wire.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'intent', $state['events'][1]['state'] );
		$resumed = Wstm108_PrivateWire::resume( $this->directory, $binding, str_repeat( 'c', 64 ), $wire->identity(), static function (): void {} );
		self::assertSame( array_sum( array_column( $state['events'], 'length' ) ),
			$this->property( $resumed, 'reserved_bytes' )->getValue( $resumed ) );
		$this->property( $resumed, 'reserved_bytes' )->setValue( $resumed, 134217728 );
		$before = file_get_contents( $this->directory . '/private-wire.json' );
		$this->refuses( static fn() => $resumed->capture( 'runner', array( 'body' => 'unknown-secret' ) ) );
		self::assertSame( $before, file_get_contents( $this->directory . '/private-wire.json' ) );
		self::assertFileDoesNotExist( $this->directory . '/wire-000003.bin' );
		self::assertSame( 'foreign frame', file_get_contents( $this->directory . '/wire-000002.bin' ) );
	}

	public function testOversizedOwnedFileIsRefusedBeforeReadingItsBody(): void {
		$path = $this->directory . '/oversized.bin';
		$handle = fopen( $path, 'xb' );
		self::assertTrue( ftruncate( $handle, 33554433 ) );
		fclose( $handle );
		$this->refuses( static fn() => Wstm108_Files::file( $path ) );
		self::assertSame( 33554433, filesize( $path ) );
	}

	public function testCompletedOutputRechecksAllRecordsAndRefusesDriftOrIncompleteReservations(): void {
		$output = new Wstm167_Product_Output( $this->directory, static function (): void {} );
		$path = $this->directory . '/record.json';
		$output->write( $path, 'original' );
		$output->recheck();
		file_put_contents( $path, 'mutated' );
		$this->refuses( static fn() => $output->recheck() );
		$this->property( $output, 'files' )->setValue( $output, array( $path => null ) );
		$this->refuses( static fn() => $output->recheck() );
		self::assertSame( 'mutated', file_get_contents( $path ) );
	}

	public function testEvidenceSummaryExhaustionPrecedesTruncationAndCannotErasePriorBytes(): void {
		$path = $this->directory . '/runner.json';
		$evidence = new Wstm108_Evidence( $path );
		$evidence->save( array( 'status' => 'in_progress', 'error' => 'private sentinel' ) );
		$before = file_get_contents( $path );
		self::assertStringNotContainsString( 'private sentinel', $before );
		$this->property( $evidence, 'reserved_bytes' )->setValue( $evidence, 134217728 );
		$this->refuses( static fn() => $evidence->save( array( 'status' => 'passed' ) ) );
		self::assertSame( $before, file_get_contents( $path ) );
		self::assertSame( 134217728, $this->property( $evidence, 'reserved_bytes' )->getValue( $evidence ) );
		unset( $evidence );
	}

	public function testEvidenceDriftRetainsFailedDebitAndPreventsAllRetries(): void {
		$path = $this->directory . '/runner.json';
		$evidence = new Wstm108_Evidence( $path );
		$evidence->save( array( 'status' => 'in_progress' ) );
		$before = $this->property( $evidence, 'reserved_bytes' )->getValue( $evidence );
		file_put_contents( $path, 'foreign unchanged' );
		$this->refuses( static fn() => $evidence->save( array( 'status' => 'passed' ) ) );
		$failed = $this->property( $evidence, 'reserved_bytes' )->getValue( $evidence );
		self::assertGreaterThan( $before, $failed );
		$this->refuses( static fn() => $evidence->save( array( 'status' => 'passed' ) ) );
		self::assertSame( $failed, $this->property( $evidence, 'reserved_bytes' )->getValue( $evidence ) );
		self::assertSame( 'foreign unchanged', file_get_contents( $path ) );
		unset( $evidence );
	}

	public function testArtifactRootAnchorSurvivesOwnedChildCreationWithoutDroppingIdentityOrMode(): void {
		$method = new ReflectionMethod( Wstm167_Product_Context::class, 'root_identity' );
		$method->setAccessible( true );
		$before = $method->invoke( null, $this->directory );
		self::assertSame( array( 'dev', 'ino', 'mode', 'uid', 'gid' ), array_keys( $before ) );
		$child = $this->directory . '/parent-cache';
		self::assertTrue( mkdir( $child, 0700 ) );
		try {
			self::assertSame( $before, $method->invoke( null, $this->directory ) );
			if ( 'Windows' !== PHP_OS_FAMILY ) {
				self::assertTrue( chmod( $this->directory, 0755 ) );
				self::assertNotSame( $before, $method->invoke( null, $this->directory ) );
				self::assertTrue( chmod( $this->directory, 0700 ) );
			}
		} finally {
			self::assertTrue( rmdir( $child ) );
		}
	}
}
