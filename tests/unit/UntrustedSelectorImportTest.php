<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-release.php';
require_once __DIR__ . '/fixtures/shared-helper-transition.php';

final class UntrustedSelectorImportTest extends TestCase {
	public const REPAIR_SEAL = '67e7fc5b92b672b8e56c6346c121f5837f9a1cb62257e31b4ae494c23c34f539';
	private const NAMESPACES = array( 'Wstm108ClearWriteFault', 'Wstm108PostcommitFault', 'Wstm108PartialCompanion', 'Wstm108PrerequisiteNoise' );

	private function fixture(): string {
		return file_get_contents( __DIR__ . '/fixtures/untrusted-release-controls.php' );
	}

	private function extracted_classes( string $suffix, bool $imports ): void {
		$source = $this->fixture();
		$start = strpos( $source, '$release_source = ' );
		$end = strpos( $source, "foreach ( array( 'partial-companion'" );
		self::assertIsInt( $start );
		self::assertIsInt( $end );
		$source = substr( $source, $start, $end - $start );
		$source = str_replace( '__DIR__', var_export( __DIR__ . '/fixtures', true ), $source );
		if ( ! $imports ) { $source = str_replace( 'use \WstmQaRuntime; ', '', $source ); }
		foreach ( array_merge( self::NAMESPACES, array( 'Wstm108CompanionWriteFault' ) ) as $namespace ) {
			$source = str_replace( $namespace, $namespace . $suffix, $source );
		}
		eval( $source );
	}

	private function marker( string $namespace ): string {
		$class = new ReflectionClass( $namespace . '\Wstm108_ReleaseGuard' );
		$guard = $class->newInstanceWithoutConstructor();
		$host = $class->getProperty( 'host' );
		$host->setAccessible( true );
		$host->setValue( $guard, array(
			'checkout' => '/synthetic-not-used-by-default-selection',
			'binding' => array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'original-control',
				'source_sha' => str_repeat( 'b', 40 ), 'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null ),
		) );
		$method = $class->getMethod( 'retention_bytes' );
		$method->setAccessible( true );
		return $method->invoke( $guard );
	}

	public function testActualExtractedFaultCopiesResolveOriginalSelectorAndRefuseInvalidSelection(): void {
		// Pure selector dispatch only; no authority construction, filesystem or release.
		$keys = array( WstmQaRuntime::PROFILE, WstmQaRuntime::CONFIG, WstmQaRuntime::HASH, 'E2E_PACKAGE_ROOT', 'E2E_PACKAGE_ZIP' );
		$original = array();
		foreach ( $keys as $key ) { $original[ $key ] = getenv( $key ); putenv( $key ); }
		try {
			$this->extracted_classes( 'SelectorBound', true );
			foreach ( self::NAMESPACES as $namespace ) {
				self::assertSame( 'owner=' . str_repeat( 'a', 32 ) . "\nproject=original-control\nsource=" . str_repeat( 'b', 40 ) . "\n",
					$this->marker( $namespace . 'SelectorBound' ) );
			}
			putenv( WstmQaRuntime::CONFIG . '=/original/missing-profile.json' );
			foreach ( self::NAMESPACES as $namespace ) {
				try { $this->marker( $namespace . 'SelectorBound' ); self::fail( 'Unprofiled identity accepted by extracted guard.' ); }
				catch ( RuntimeException $error ) { self::assertSame( 'QA runtime: floor config without its explicit profile.', $error->getMessage() ); }
			}
			putenv( WstmQaRuntime::CONFIG );
			$this->extracted_classes( 'SelectorMissing', false );
			foreach ( self::NAMESPACES as $namespace ) {
				try { $this->marker( $namespace . 'SelectorMissing' ); self::fail( 'Missing import was not exercised.' ); }
				catch ( Error $error ) {
					self::assertSame( 'Class "' . $namespace . 'SelectorMissing\WstmQaRuntime" not found', $error->getMessage() );
				}
			}
		} finally {
			foreach ( $original as $key => $value ) { putenv( false === $value ? $key : $key . '=' . $value ); }
		}
	}

	public function testExactAdditiveRepairChangesOnlyFourImportsAndPreservesPredecessors(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/selector-import-transition.json' );
		self::assertSame( self::REPAIR_SEAL, hash( 'sha256', $json ) );
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$binding = $map['files']['tests/unit/fixtures/untrusted-release-controls.php'];
		$current = $this->fixture();
		self::assertSame( $binding['current_raw_sha256'], hash( 'sha256', $current ) );
		$count = 0;
		$before = str_replace( 'use \WstmQaRuntime; ', '', $current, $count );
		self::assertSame( 4, $count );
		self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) );
		$lines = explode( "\n", $current );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			self::assertSame( $hunk['after'], array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) );
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		self::assertSame( $before, implode( "\n", $lines ) );
		self::assertSame( '204877c9ff0325b4a6a90f040de918759d9ec45ed22b75a29a075de3451c2d78', $map['predecessor_seals']['floor'] );
		$read = Wstm166BoundedAdmissionTransition::reader( static fn( $path ) => file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
		foreach ( $map['frozen_files'] as $path => $sha ) {
			self::assertSame( $sha, hash( 'sha256', $read( $path ) ), $path );
		}
		Wstm166BoundedAdmissionTransition::verify_dependencies( static fn( $path ) => file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
		Wstm167FloorSelectorTransition::verify_dependencies( $read );
	}
}
