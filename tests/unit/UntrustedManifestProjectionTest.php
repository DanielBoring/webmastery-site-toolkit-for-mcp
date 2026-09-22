<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-manifest-projection.php';

final class UntrustedManifestProjectionTest extends TestCase {
	private static function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
	}

	private static function find_case( array $manifest, callable $predicate ): object {
		foreach ( $manifest as $case ) {
			if ( $predicate( $case ) ) {
				return $case;
			}
		}
		self::fail( 'Mutation did not reach a real manifest case.' );
	}

	public function test_exact_projection_preserves_all_570_typed_cases_and_the_callers_copy(): void {
		$manifest = self::manifest();
		$before = serialize( $manifest );
		$projected = Wstm108_Manifest_Projection::project( $manifest );
		self::assertSame( $before, serialize( $manifest ) );
		self::assertCount( 570, $projected );
		self::assertSame( Wstm108_Manifest_Projection::BASELINE_SHA256, Wstm108_Manifest_Projection::fingerprint( $projected ) );
		foreach ( Wstm108_Manifest_Projection::inventory()->cases as $entry ) {
			self::assertSame( $entry->had_assert_values, property_exists( $projected[ $entry->index ], 'assert_values' ) );
		}
	}

	public static function mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array(
			'missing-marker', 'weakened-marker', 'extra-marker-field', 'marker-order',
			'wrong-marker-path', 'extra-marker-path', 'marker-fields-object',
			'assert-values-array', 'case-label', 'case-order', 'extra-case', 'missing-case',
			'input-value', 'integer-float', 'object-array', 'role', 'error-code', 'error-reason',
			'no-write', 'assertion-value', 'nested-user-marker', 'property-order',
		) );
	}

	/** @dataProvider mutations */
	public function test_same_projection_rejects_unapproved_changes( string $mutation ): void {
		$manifest = self::manifest();
		$entry = Wstm108_Manifest_Projection::inventory()->cases[0];
		$case = $manifest[ $entry->index ];
		$path = array_key_first( (array) $entry->markers );
		switch ( $mutation ) {
			case 'missing-marker':
				unset( $case->assert_values->$path );
				break;
			case 'weakened-marker':
				array_pop( $case->assert_values->$path );
				break;
			case 'extra-marker-field':
				$case->assert_values->{$path}[] = 'unapproved';
				break;
			case 'marker-order':
				$case->assert_values->$path = array_reverse( $case->assert_values->$path );
				break;
			case 'wrong-marker-path':
				$case->assert_values->{'data.wrong.untrusted_fields'} = $case->assert_values->$path;
				unset( $case->assert_values->$path );
				break;
			case 'extra-marker-path':
				$case->assert_values->{'data.wrong.untrusted_fields'} = array();
				break;
			case 'marker-fields-object':
				$case->assert_values->$path = (object) $case->assert_values->$path;
				break;
			case 'assert-values-array':
				$case->assert_values = array();
				break;
			case 'case-label':
				$case->label .= ' changed';
				break;
			case 'case-order':
				[ $manifest[0], $manifest[1] ] = array( $manifest[1], $manifest[0] );
				break;
			case 'extra-case':
				$manifest[] = clone $case;
				break;
			case 'missing-case':
				array_pop( $manifest );
				break;
			case 'input-value':
				$case->input->unapproved = true;
				break;
			case 'integer-float':
				$numeric = self::find_case( $manifest, static fn( $item ) => isset( $item->input->per_page ) && is_int( $item->input->per_page ) );
				$numeric->input->per_page = (float) $numeric->input->per_page;
				break;
			case 'object-array':
				$empty = self::find_case( $manifest, static fn( $item ) => $item->input instanceof stdClass && array() === get_object_vars( $item->input ) );
				$empty->input = array();
				break;
			case 'role':
				$case->role = 'unapproved';
				break;
			case 'error-code':
				$manifest[0]->expect_error_code = 'upstream_failed';
				break;
			case 'error-reason':
				$manifest[0]->expect_error_reason = 'unapproved';
				break;
			case 'no-write':
				self::assertTrue( $manifest[0]->assert_unchanged );
				$manifest[0]->assert_unchanged = false;
				break;
			case 'assertion-value':
				$value_case = self::find_case( $manifest, static fn( $item ) => isset( $item->assert_values->{'data.id'} ) );
				$value_case->assert_values->{'data.id'} = 'changed';
				break;
			case 'nested-user-marker':
				$stored = self::find_case( $manifest, static fn( $item ) => isset( $item->input->meta_value ) && $item->input->meta_value instanceof stdClass );
				$stored->input->meta_value->untrusted_fields = array( 'stored user data, not projection metadata' );
				break;
			case 'property-order':
				$role = $case->role;
				unset( $case->role );
				$case->role = $role;
				break;
			default:
				self::fail( 'Unknown mutation.' );
		}
		$this->expectException( AssertionFailedError::class );
		Wstm108_Manifest_Projection::project( $manifest );
	}

	private static function validate_import_indices( array $indices ): object {
		$imports = json_decode( file_get_contents( __DIR__ . '/fixtures/untrusted-import-inventory.json' ), false, 512, JSON_THROW_ON_ERROR );
		self::assertSame( '29d13070311dc94c334e12ea8102852f29248036e09d027bf762e6a85dde6d8e', Wstm108_Manifest_Projection::fingerprint( $imports ) );
		$markers = Wstm108_Manifest_Projection::inventory();
		foreach ( array( 'marker_source_sha', 'baseline_source_sha', 'marker_source_manifest_sha256', 'baseline_manifest_sha256' ) as $field ) {
			self::assertSame( $markers->$field, $imports->$field );
		}
		self::assertCount( 52, $imports->cases );
		self::assertSame( array_column( $imports->cases, 'index' ), $indices );
		return $imports;
	}

	public function test_mutation_provider_matches_the_entire_immutable_import_inventory(): void {
		$imports = self::validate_import_indices( array_column( self::imported_rows(), 0 ) );
		$manifest = self::manifest();
		foreach ( $imports->cases as $entry ) {
			$case = $manifest[ $entry->index ];
			self::assertSame( $entry->ability, $case->ability );
			self::assertSame( $entry->label, $case->label );
			self::assertSame( $entry->sha256, Wstm108_Manifest_Projection::fingerprint( $case ) );
		}
	}

	public static function import_index_mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'missing', 'extra', 'duplicate', 'wrong', 'reordered' ) );
	}

	/** @dataProvider import_index_mutations */
	public function test_import_provenance_rejects_omissions_duplicates_and_other_index_changes( string $mutation ): void {
		$indices = array_column( self::imported_rows(), 0 );
		if ( 'missing' === $mutation ) { array_pop( $indices ); }
		if ( 'extra' === $mutation ) { $indices[] = 44; }
		if ( 'duplicate' === $mutation ) { $indices[44] = $indices[0]; }
		if ( 'wrong' === $mutation ) { $indices[44] = 44; }
		if ( 'reordered' === $mutation ) { [ $indices[0], $indices[1] ] = array( $indices[1], $indices[0] ); }
		$this->expectException( AssertionFailedError::class );
		self::validate_import_indices( $indices );
	}

	public static function imported_rows(): array {
		return array_map( static fn( $index ) => array( $index ), array_merge( range( 0, 43 ), array( 187 ), range( 372, 378 ) ) );
	}

	/** @dataProvider imported_rows */
	public function test_all_52_imported_safety_and_privacy_rows_remain_exact_and_unmarked( int $index ): void {
		$manifest = self::manifest();
		self::assertNotContains( $index, array_column( Wstm108_Manifest_Projection::inventory()->cases, 'index' ) );
		$manifest[ $index ]->assert_values ??= new stdClass();
		$manifest[ $index ]->assert_values->{'data.untrusted_fields'} = array();
		$this->expectException( AssertionFailedError::class );
		Wstm108_Manifest_Projection::project( $manifest );
	}
}
