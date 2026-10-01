<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/bounded-manifest-projection.php';

final class BoundedListManifestTest extends TestCase {
	private static function canonical( $value ) {
		if ( is_object( $value ) ) {
			$properties = get_object_vars( $value );
			ksort( $properties );
			return (object) array_map( array( self::class, 'canonical' ), $properties );
		}
		return is_array( $value ) ? array_map( array( self::class, 'canonical' ), $value ) : $value;
	}

	private static function encoded( $value ): string {
		return json_encode( self::canonical( $value ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	}

	public function test_all_base_cases_preserved_or_explicitly_migrated(): void {
		$root = dirname( __DIR__ );
		$manifest = json_decode( file_get_contents( $root . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
		$manifest = BoundedManifestProjection::bounded_source( $manifest );
		$ledger = json_decode( file_get_contents( $root . '/fixtures/bounded-list-manifest-migration.json' ), false, 512, JSON_THROW_ON_ERROR );
		$cases = array();
		foreach ( $manifest as $case ) {
			$cases[ $case->label ] = $case;
		}
		$integration = json_decode( file_get_contents( $root . '/fixtures/bounded-integration-manifest-migration.json' ), false, 512, JSON_THROW_ON_ERROR );
		self::assertSame( '1a8e76dae6183d99a48ff4c0a7ae34c1cdd17e18', $integration->parent_sha );
		self::assertSame( '062a6e4f587cc6aa656aca9d020d364d3259eb12', $integration->reviewed_source_sha );
		self::assertCount( 6, $integration->changed );
		self::assertCount( 3, $integration->added );
		$permission_corrections = array();
		foreach ( $integration->changed as $correction ) {
			self::assertArrayNotHasKey( $correction->label, $permission_corrections );
			$permission_corrections[ $correction->label ] = $correction;
			foreach ( array( 'ability', 'role', 'input', 'expect', 'assert_capabilities', 'assert_post_meta' ) as $field ) {
				self::assertSame( self::encoded( $correction->before->$field ?? null ), self::encoded( $correction->after->$field ?? null ), $correction->label . ':' . $field );
			}
		}
		$changes = array();
		foreach ( $ledger->changed as $change ) {
			$changes[ $change->label ] = $change;
			self::assertSame( self::encoded( $change->after ), self::encoded( $cases[ $change->label ] ), $change->label );
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
		$schema = json_decode( file_get_contents( $root . '/fixtures/input-schema-manifest-migration.json' ), false, 512, JSON_THROW_ON_ERROR );
		self::assertSame( '3f6226bb947687d48f7ec5412290dfca6fd76574', $schema->baseline_sha );
		self::assertSame( '1c460f1e4468d73103e2ec55a21f990f1da94ef5', $schema->source_sha );
		self::assertCount( 31, $schema->changed );
		self::assertCount( 16, $schema->added );
		self::assertCount( 2, $schema->integration_corrections );
		$corrections = array();
		foreach ( $schema->integration_corrections as $correction ) {
			self::assertContains( $correction->label, array(
				'wstm110 update-post rejects empty metadata presence',
				'wstm110 create-cpt-mcp-case-study rejects metadata input',
			) );
			$corrections[ $correction->label ] = $correction;
		}
		self::assertCount( 2, $corrections );
		foreach ( $schema->changed as $change ) {
			self::assertArrayNotHasKey( $change->label, $changes, 'Schema and window migrations must remain disjoint.' );
			$expected = $change->after;
			if ( isset( $corrections[ $change->label ] ) ) {
				$correction = $corrections[ $change->label ];
				self::assertEquals( $change->after, $correction->source_after );
				$restored = clone $change->after;
				$restored->input = $change->before->input;
				self::assertEquals( $restored, $correction->corrected_after, 'Only restore original input; retain the approved schema oracle.' );
				$expected = $correction->corrected_after;
			}
			if ( isset( $permission_corrections[ $change->label ] ) ) {
				$correction = $permission_corrections[ $change->label ];
				self::assertSame( self::encoded( $expected ), self::encoded( $correction->before ), 'Preserve the earlier reviewed schema oracle before applying the permission correction.' );
				$expected = $correction->after;
			}
			self::assertSame( self::encoded( $expected ), self::encoded( $cases[ $change->label ] ), $change->label );
			self::assertSame( $change->before->role, $expected->role );
			self::assertEquals( $change->before->input, $expected->input );
			$changes[ $change->label ] = $change;
		}
		$additional_native_changes = 0;
		foreach ( $permission_corrections as $label => $correction ) {
			self::assertSame( self::encoded( $correction->after ), self::encoded( $cases[ $label ] ), $label );
			if ( ! isset( $changes[ $label ] ) ) {
				$changes[ $label ] = $correction;
				++$additional_native_changes;
			}
		}
		self::assertSame( 3, $additional_native_changes );
		foreach ( $integration->added as $case ) {
			self::assertArrayHasKey( $case->label, $cases );
			self::assertSame( self::encoded( $case ), self::encoded( $cases[ $case->label ] ), $case->label );
		}
		foreach ( $schema->added as $case ) {
			self::assertArrayHasKey( $case->label, $cases );
			self::assertEquals( $case, $cases[ $case->label ], $case->label );
		}
		foreach ( $ledger->baseline as $base ) {
			self::assertArrayHasKey( $base->label, $cases );
			$case = isset( $changes[ $base->label ] ) ? $changes[ $base->label ]->before : $cases[ $base->label ];
			self::assertSame( $base->sha256, hash( 'sha256', json_encode( self::canonical( $case ), JSON_UNESCAPED_SLASHES ) ), $base->label );
		}
	}
}
