<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BoundedListManifestTest extends TestCase {
	private static function canonical( $value ) {
		if ( is_object( $value ) ) {
			$properties = get_object_vars( $value );
			ksort( $properties );
			return (object) array_map( array( self::class, 'canonical' ), $properties );
		}
		return is_array( $value ) ? array_map( array( self::class, 'canonical' ), $value ) : $value;
	}

	public function test_all_base_cases_preserved_or_explicitly_migrated(): void {
		$root = dirname( __DIR__ );
		$manifest = json_decode( file_get_contents( $root . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
		$ledger = json_decode( file_get_contents( $root . '/fixtures/bounded-list-manifest-migration.json' ), false, 512, JSON_THROW_ON_ERROR );
		$cases = array();
		foreach ( $manifest as $case ) {
			$cases[ $case->label ] = $case;
		}
		$changes = array();
		foreach ( $ledger->changed as $change ) {
			$changes[ $change->label ] = $change;
			self::assertEquals( $change->after, $cases[ $change->label ], $change->label );
			self::assertSame( $change->before->role, $change->after->role );
			self::assertSame( $change->before->expect, $change->after->expect );
			foreach ( get_object_vars( $change->before->assert_values ?? new stdClass() ) as $path => $value ) {
				if ( ! in_array( $path, array( 'data.total', 'data.total_pages' ), true ) ) {
					self::assertEquals( $value, $change->after->assert_values->$path, $path );
				}
			}
		}
		self::assertCount( 563, $ledger->baseline );
		self::assertCount( 18, $ledger->changed );
		foreach ( $ledger->baseline as $base ) {
			self::assertArrayHasKey( $base->label, $cases );
			$case = isset( $changes[ $base->label ] ) ? $changes[ $base->label ]->before : $cases[ $base->label ];
			self::assertSame( $base->sha256, hash( 'sha256', json_encode( self::canonical( $case ), JSON_UNESCAPED_SLASHES ) ), $base->label );
		}
	}
}
