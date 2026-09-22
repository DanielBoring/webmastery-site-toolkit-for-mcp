<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/schema-integration-ledger.php';

final class SchemaIntegrationLedgerTest extends TestCase {
	private function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
	}

	public function test_exact_source_projection_and_all_approved_field_changes(): void {
		$ledger = json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-integration-ledger.json' ), false, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'f93b7d11bec24620f5dd51202db3e6cb1df020d9', $ledger->parent_sha );
		self::assertSame( '1bf2eb2c8b5df9f17f7baa3487babedb70edbccb', $ledger->approved_schema_sha );
		$manifest = $this->manifest();
		$parent = wstm126_parent_manifest( $manifest );
		self::assertCount( 570, $parent );
		$safety = $privacy = $native = $raw = 0;
		foreach ( $ledger->changes as $change ) {
			self::assertSame( $change->before_sha256, wstm126_case_hash( $parent[ $change->source_index ] ) );
			self::assertSame( wstm126_case_hash( $change->before->input ?? null ), wstm126_case_hash( $change->after->input ?? null ) );
			self::assertSame( $change->before->role, $change->after->role );
			$fields = array();
			foreach ( array_unique( array_merge( array_keys( get_object_vars( $change->before ) ), array_keys( get_object_vars( $change->after ) ) ) ) as $field ) {
				if ( property_exists( $change->before, $field ) !== property_exists( $change->after, $field )
					|| wstm126_case_hash( $change->before->$field ?? null ) !== wstm126_case_hash( $change->after->$field ?? null ) ) {
					$fields[] = $field;
				}
			}
			self::assertSame( $fields, $change->changed_fields );
			if ( ( $change->before->expect_error_reason ?? null ) !== ( $change->after->expect_error_reason ?? null ) ) { ++$native; }
			if ( ( $change->before->assert_permission ?? null ) !== ( $change->after->assert_permission ?? null ) ) { ++$raw; }
			if ( str_starts_with( $change->before->label, 'wstm116 ' ) ) {
				++$safety;
				self::assertEmpty( array_diff( $fields, array( 'expect_error_code', 'expect_error_reason' ) ) );
				self::assertSame( 'invalid_input', $change->after->expect_error_code );
				self::assertSame( 'ability_invalid_input', $change->after->expect_error_reason );
			}
			if ( str_starts_with( $change->before->label, 'database-health ' ) ) {
				++$privacy;
				self::assertSame( array( 'expect_error_reason' ), $fields );
				self::assertSame( 'invalid_input', $change->before->expect_error_reason );
				self::assertSame( 'ability_invalid_input', $change->after->expect_error_reason );
			}
		}
		self::assertSame( 16, $safety );
		self::assertSame( 2, $privacy );
		self::assertSame( 52, $native );
		self::assertSame( 4, $raw );
		self::assertCount( 19, $ledger->additions );
		foreach ( $ledger->additions as $addition ) {
			self::assertSame( wstm126_case_hash( $addition->case ), wstm126_case_hash( $manifest[ $addition->index ] ) );
		}
	}

	public static function mutations(): array {
		return array_map( static fn( $value ) => array( $value ), array( 'extra', 'missing', 'order', 'integer-float', 'object-array', 'reason', 'no-write', 'addition', 'privacy' ) );
	}

	/** @dataProvider mutations */
	public function test_projection_rejects_every_unapproved_manifest_delta( string $mutation ): void {
		$manifest = $this->manifest();
		$positions = array_flip( array_column( $manifest, 'label' ) );
		if ( 'extra' === $mutation ) { $manifest[] = clone $manifest[0]; }
		if ( 'missing' === $mutation ) { array_pop( $manifest ); }
		if ( 'order' === $mutation ) { [ $manifest[0], $manifest[1] ] = array( $manifest[1], $manifest[0] ); }
		if ( 'integer-float' === $mutation ) { $manifest[ $positions['wstm116 delete-media confirm number'] ]->input->confirm = 1.0; }
		if ( 'object-array' === $mutation ) { $manifest[ $positions['wstm110 update-post rejects empty metadata presence'] ]->input->meta = array(); }
		if ( 'reason' === $mutation ) { $manifest[ $positions['wstm116 delete-media confirm string'] ]->expect_error_reason = 'missing_confirmation'; }
		if ( 'no-write' === $mutation ) { $manifest[ $positions['wstm116 delete-media confirm string'] ]->assert_unchanged = false; }
		if ( 'addition' === $mutation ) { $manifest[ $positions['wstm120 contributor cannot update unrelated draft with plain input'] ]->role = 'admin'; }
		if ( 'privacy' === $mutation ) { $manifest[ $positions['database-health numeric flag rejected'] ]->input->include_table_names = 1.0; }
		$this->expectException( RuntimeException::class );
		wstm126_parent_manifest( $manifest );
	}
}
