<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

require_once __DIR__ . '/schema-integration-ledger.php';

final class BoundedManifestProjection {
	private static function read( string $path ) {
		return json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ), false, 512, JSON_THROW_ON_ERROR );
	}

	private static function exact( $expected, $actual, string $label ): void {
		Assert::assertSame(
			json_encode( $expected, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ),
			json_encode( $actual, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ),
			$label
		);
	}

	private static function indexed( array $manifest ): array {
		Assert::assertCount( 601, $manifest );
		self::exact( self::read( 'fixtures/bounded-schema-composition.json' )->case_order, array_column( $manifest, 'label' ), 'Original live case order.' );
		$cases = array();
		foreach ( $manifest as $case ) {
			Assert::assertArrayNotHasKey( $case->label, $cases, 'Duplicate manifest label.' );
			$cases[ $case->label ] = clone $case;
		}
		return $cases;
	}

	private static function reverse( array &$cases, object $change, ?object $expected = null ): void {
		Assert::assertArrayHasKey( $change->label, $cases );
		self::exact( $expected ?? $change->after, $cases[ $change->label ], $change->label );
		$cases[ $change->label ] = $change->before;
	}

	private static function schema_ledger(): object {
		Assert::assertSame( '17adc6c7df150f46b6826f47132a004368fdce9bf817cd0a80c0981e2411c84f', hash_file( 'sha256', dirname( __DIR__, 2 ) . '/e2e/input-schema-integration-ledger.json' ) );
		return self::read( 'e2e/input-schema-integration-ledger.json' );
	}

	/** Reverse only the remaining 18 already-approved native schema changes. */
	private static function before_schema( array $manifest ): array {
		$cases = self::indexed( $manifest );
		$composition = self::read( 'fixtures/bounded-schema-composition.json' );
		$changes = array();
		foreach ( self::schema_ledger()->changes as $change ) {
			$changes[ $change->after->label ] = $change;
		}
		Assert::assertCount( 18, $composition->native_changes );
		Assert::assertCount( 18, array_unique( $composition->native_changes ) );
		foreach ( $composition->native_changes as $label ) {
			Assert::assertArrayHasKey( $label, $changes );
			Assert::assertArrayHasKey( $label, $cases );
			self::exact( $changes[ $label ]->after, $cases[ $label ], $label );
			$cases[ $label ] = $changes[ $label ]->before;
		}
		return array_values( $cases );
	}

	/** Preserve both historical layers and the independently pinned full schema layer. */
	public static function accepted_main( array $manifest ): array {
		$manifest = self::before_markers( $manifest );
		$historical = self::legacy_main( self::before_schema( $manifest ) );
		$parent = wstm126_parent_manifest( self::schema_source_unmarked( $manifest ) );
		self::exact( $historical, $parent, 'Both dependency projections reconstruct the same exact parent.' );
		return $parent;
	}

	public static function schema_source( array $manifest ): array {
		return self::schema_source_unmarked( self::before_markers( $manifest ) );
	}

	/** Strip exactly the four approved hygiene marker assertions, never stored values. */
	public static function before_markers( array $manifest ): array {
		$copy = json_decode( json_encode( $manifest, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 512, JSON_THROW_ON_ERROR );
		$cases = self::indexed( $copy );
		$approved = array(
			'list-orphaned-media' => array( 'list-orphaned-media', array( 'title', 'url' ) ),
			'list-posts-no-featured-image posts' => array( 'list-posts-no-featured-image', array( 'title', 'url' ) ),
			'list-posts-no-featured-image pages' => array( 'list-posts-no-featured-image', array( 'title', 'url' ) ),
			'list-stuck-scheduled' => array( 'list-stuck-scheduled', array( 'title', 'url', 'author_name' ) ),
		);
		$path = 'data.items.0.untrusted_fields';
		foreach ( $approved as $label => [ $slug, $fields ] ) {
			Assert::assertArrayHasKey( $label, $cases );
			$case = $cases[ $label ];
			Assert::assertSame( 'webmastery-site-toolkit-for-mcp/' . $slug, $case->ability );
			Assert::assertSame( 'success', $case->expect );
			Assert::assertInstanceOf( stdClass::class, $case->assert_values ?? null );
			Assert::assertTrue( property_exists( $case->assert_values, $path ), 'Missing approved hygiene marker: ' . $label );
			Assert::assertSame( $fields, $case->assert_values->$path, 'Changed approved hygiene marker: ' . $label );
			unset( $case->assert_values->$path );
		}
		return array_values( $cases );
	}

	private static function schema_source_unmarked( array $manifest ): array {
		$cases = self::indexed( $manifest );
		$bounded = self::read( 'fixtures/bounded-list-manifest-migration.json' );
		$additions = self::read( 'fixtures/bounded-list-manifest-additions.json' );
		Assert::assertCount( 12, $additions->cases );
		foreach ( $additions->cases as $case ) {
			Assert::assertArrayHasKey( $case->label, $cases );
			self::exact( $case, $cases[ $case->label ], $case->label );
			unset( $cases[ $case->label ] );
		}
		Assert::assertCount( 18, $bounded->changed );
		foreach ( $bounded->changed as $change ) {
			self::reverse( $cases, $change );
		}
		$composition = self::read( 'fixtures/bounded-schema-composition.json' );
		Assert::assertCount( 6, $composition->bridges );
		foreach ( $composition->bridges as $bridge ) {
			$original = json_decode( $bridge->bounded_json, false, 512, JSON_THROW_ON_ERROR );
			$schema = json_decode( $bridge->schema_json, false, 512, JSON_THROW_ON_ERROR );
			Assert::assertArrayHasKey( $bridge->label, $cases );
			self::exact( $original, $cases[ $bridge->label ], $bridge->label );
			$original_fields = get_object_vars( $original );
			$schema_fields = get_object_vars( $schema );
			ksort( $original_fields );
			ksort( $schema_fields );
			self::exact( (object) $original_fields, (object) $schema_fields, 'Only root property ordering may differ.' );
			$cases[ $bridge->label ] = $schema;
		}
		$ledger = self::schema_ledger();
		$schema_additions = array_column( $ledger->additions, null, 'index' );
		Assert::assertCount( 19, $schema_additions );
		foreach ( $schema_additions as $addition ) {
			$case = $addition->case;
			Assert::assertArrayHasKey( $case->label, $cases );
			self::exact( $case, $cases[ $case->label ], $case->label );
			unset( $cases[ $case->label ] );
		}
		Assert::assertCount( 570, $cases );
		$source = array_values( $cases );
		$position = 0;
		$result = array();
		foreach ( $ledger->rows as $index => $row ) {
			if ( null === $row->source_index ) {
				Assert::assertArrayHasKey( $index, $schema_additions );
				$case = $schema_additions[ $index ]->case;
			} else {
				Assert::assertSame( $position, $row->source_index, 'Original parent case order.' );
				$case = $source[ $position++ ];
			}
			Assert::assertSame( $row->label, $case->label );
			Assert::assertSame( $row->after_sha256, wstm126_case_hash( $case ), $row->label );
			$result[] = $case;
		}
		Assert::assertSame( 570, $position );
		Assert::assertCount( 589, $result );
		return $result;
	}

	private static function legacy_main( array $manifest ): array {
		$cases = self::indexed( $manifest );
		$bounded = self::read( 'fixtures/bounded-list-manifest-migration.json' );
		$schema = self::read( 'fixtures/input-schema-manifest-migration.json' );
		$integration = self::read( 'fixtures/bounded-integration-manifest-migration.json' );
		$additions = self::read( 'fixtures/bounded-list-manifest-additions.json' );
		Assert::assertSame( '1dadee170492654db606b05c13695e26fca8c10d', $additions->source_sha );
		Assert::assertCount( 12, $additions->cases );
		Assert::assertCount( 16, $schema->added );
		Assert::assertCount( 3, $integration->added );
		$added = array_merge( $additions->cases, $schema->added, $integration->added );
		foreach ( $added as $case ) {
			Assert::assertArrayHasKey( $case->label, $cases );
			self::exact( $case, $cases[ $case->label ], $case->label );
			unset( $cases[ $case->label ] );
		}
		Assert::assertCount( 6, $integration->changed );
		foreach ( $integration->changed as $change ) {
			self::reverse( $cases, $change );
		}
		Assert::assertCount( 2, $schema->integration_corrections );
		$corrections = array_column( $schema->integration_corrections, null, 'label' );
		Assert::assertCount( 31, $schema->changed );
		foreach ( $schema->changed as $change ) {
			$expected = $change->after;
			if ( isset( $corrections[ $change->label ] ) ) {
				$correction = $corrections[ $change->label ];
				self::exact( $change->after, $correction->source_after, $change->label );
				$restored = clone $change->after;
				$restored->input = $change->before->input;
				self::exact( $restored, $correction->corrected_after, $change->label );
				$expected = $correction->corrected_after;
			}
			self::reverse( $cases, $change, $expected );
		}
		Assert::assertCount( 18, $bounded->changed );
		foreach ( $bounded->changed as $change ) {
			self::reverse( $cases, $change );
		}
		Assert::assertCount( 570, $cases );
		return array_values( $cases );
	}

	/** Validate incoming main deltas before replaying the earlier owned preservation guards. */
	public static function bounded_source( array $manifest ): array {
		$manifest = self::before_markers( $manifest );
		$cases = self::indexed( self::before_schema( $manifest ) );
		$ledger = self::read( 'e2e/destructive-safety-boolean-ledger.json' );
		Assert::assertCount( 16, $ledger->cases );
		foreach ( $ledger->cases as $change ) {
			Assert::assertArrayHasKey( $change->label, $cases );
			$case = $cases[ $change->label ];
			Assert::assertSame( $change->value, $case->input->{$change->flag} );
			foreach ( get_object_vars( $change->after ) as $field => $value ) {
				Assert::assertSame( $value, $case->$field, $change->label . ':' . $field );
				$case->$field = $ledger->before->$field;
			}
		}
		Assert::assertCount( 7, $ledger->integration->cases );
		foreach ( $ledger->integration->cases as $import ) {
			Assert::assertArrayHasKey( $import->label, $cases );
			Assert::assertSame( $import->sha256, hash( 'sha256', json_encode( $cases[ $import->label ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ), $import->label );
			unset( $cases[ $import->label ] );
		}
		Assert::assertCount( 594, $cases );
		return array_values( $cases );
	}
}
