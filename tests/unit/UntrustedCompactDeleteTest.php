<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-manifest-projection.php';

final class UntrustedCompactDeleteTest extends TestCase {
	private static function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
	}

	public function test_exact_five_case_calibration_preserves_the_other_565_typed_cases_and_historical_goldens(): void {
		$ledger = Wstm108_Compact_Delete_Calibration::ledger();
		$current = self::manifest();
		$before = serialize( $current );
		self::assertSame( $ledger->after_manifest_sha256, Wstm108_Manifest_Projection::fingerprint( $current ) );
		$historical = Wstm108_Compact_Delete_Calibration::restore_historical_cases( $current, $ledger );
		self::assertSame( $ledger->before_manifest_sha256, Wstm108_Manifest_Projection::fingerprint( $historical ) );
		$indices = array_column( $ledger->cases, 'index' );
		$unchanged = 0;
		foreach ( $current as $index => $case ) {
			if ( ! in_array( $index, $indices, true ) ) {
				self::assertSame( serialize( $case ), serialize( $historical[ $index ] ) );
				++$unchanged;
			}
		}
		self::assertSame( 565, $unchanged );
		foreach ( $ledger->cases as $entry ) {
			self::assertSame( serialize( $entry->after ), serialize( $current[ $entry->index ] ) );
			self::assertFalse( property_exists( $entry->after->assert_values, 'data.untrusted_fields' ) );
			self::assertSame( array( 'id', 'status' ), array_keys( get_object_vars( $entry->after->assert_values->data ) ) );
			self::assertSame( $entry->before->assert_values->{'data.id'}, $entry->after->assert_values->data->id );
			self::assertSame( 'trash', $entry->after->assert_values->data->status );
			self::assertTrue( $entry->observed_response->success );
			self::assertIsInt( $entry->observed_response->data->id );
			self::assertSame( array( 'id', 'status' ), array_keys( get_object_vars( $entry->observed_response->data ) ) );
		}
		self::assertSame( Wstm108_Manifest_Projection::BASELINE_SHA256, Wstm108_Manifest_Projection::fingerprint( Wstm108_Manifest_Projection::project( $current ) ) );
		self::assertSame( $before, serialize( $current ) );
	}

	public static function case_mutations(): array {
		$cases = array();
		foreach ( array( 69, 79, 202, 219, 509 ) as $index ) {
			foreach ( array(
				'phantom-marker', 'absent-field', 'label', 'role', 'input', 'input-type',
				'id-assertion', 'status-assertion', 'missing-exact-data', 'weakened-exact-data',
				'extra-response-field', 'exact-data-type', 'exact-data-order', 'case-property-order',
			) as $mutation ) {
				$cases[ $index . ':' . $mutation ] = array( $index, $mutation );
			}
		}
		foreach ( array( 'permission', 'stored-state', 'metadata' ) as $mutation ) {
			$cases[ '509:' . $mutation ] = array( 509, $mutation );
		}
		return $cases;
	}

	/** @dataProvider case_mutations */
	public function test_projection_cannot_mask_any_unapproved_change_to_a_calibrated_case( int $index, string $mutation ): void {
		$manifest = self::manifest();
		$case = $manifest[ $index ];
		if ( 'phantom-marker' === $mutation ) { $case->assert_values->{'data.untrusted_fields'} = array( 'title', 'content', 'excerpt', 'slug', 'url', 'author_name' ); }
		if ( 'absent-field' === $mutation ) { $case->assert_values->{'data.untrusted_fields'} = array( 'title' ); }
		if ( 'label' === $mutation ) { $case->label .= ' changed'; }
		if ( 'role' === $mutation ) { $case->role = 'subscriber'; }
		if ( 'input' === $mutation ) { $key = array_key_first( get_object_vars( $case->input ) ); $case->input->$key = 42.0; }
		if ( 'input-type' === $mutation ) { $case->input = array(); }
		if ( 'id-assertion' === $mutation ) { $case->assert_values->{'data.id'} = '__wrong_id__'; }
		if ( 'status-assertion' === $mutation ) { $case->assert_values->{'data.status'} = 'draft'; }
		if ( 'missing-exact-data' === $mutation ) { unset( $case->assert_values->data ); }
		if ( 'weakened-exact-data' === $mutation ) { unset( $case->assert_values->data->status ); }
		if ( 'extra-response-field' === $mutation ) { $case->assert_values->data->untrusted_fields = array(); }
		if ( 'exact-data-type' === $mutation ) { $case->assert_values->data = array_values( get_object_vars( $case->assert_values->data ) ); }
		if ( 'exact-data-order' === $mutation ) { $case->assert_values->data = (object) array_reverse( get_object_vars( $case->assert_values->data ), true ); }
		if ( 'case-property-order' === $mutation ) { $role = $case->role; unset( $case->role ); $case->role = $role; }
		if ( 'permission' === $mutation ) { $case->assert_permission = false; }
		if ( 'stored-state' === $mutation ) { $case->assert_stored_post->fields->post_content = 'changed'; }
		if ( 'metadata' === $mutation ) { $case->assert_post_meta[0]->value = 'changed'; }
		$this->expectException( AssertionFailedError::class );
		Wstm108_Manifest_Projection::project( $manifest );
	}

	public static function manifest_mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'sixth-marker', 'missing', 'extra', 'order' ) );
	}

	/** @dataProvider manifest_mutations */
	public function test_calibration_never_allows_an_unapproved_sixth_case_or_manifest_shape_change( string $mutation ): void {
		$manifest = self::manifest();
		if ( 'sixth-marker' === $mutation ) {
			$entry = Wstm108_Manifest_Projection::inventory()->cases[0];
			$path = array_key_first( get_object_vars( $entry->markers ) );
			unset( $manifest[ $entry->index ]->assert_values->$path );
		}
		if ( 'missing' === $mutation ) { array_pop( $manifest ); }
		if ( 'extra' === $mutation ) { $manifest[] = clone $manifest[69]; }
		if ( 'order' === $mutation ) { [ $manifest[69], $manifest[79] ] = array( $manifest[79], $manifest[69] ); }
		$this->expectException( AssertionFailedError::class );
		Wstm108_Manifest_Projection::project( $manifest );
	}

	public function test_exact_data_assertion_rejects_missing_extra_or_mistyped_actual_fields(): void {
		foreach ( Wstm108_Compact_Delete_Calibration::ledger()->cases as $entry ) {
			$expected = get_object_vars( $entry->after->assert_values->data );
			$expected['id'] = $entry->observed_response->data->id;
			$actual = get_object_vars( $entry->observed_response->data );
			self::assertSame( $expected, $actual );
			foreach ( array( 'untrusted_fields' => array(), 'title' => 'absent stored title', 'extra' => true ) as $field => $value ) {
				self::assertNotSame( $expected, $actual + array( $field => $value ) );
			}
			$actual['id'] = (float) $actual['id'];
			self::assertNotSame( $expected, $actual );
			unset( $actual['status'] );
			self::assertNotSame( $expected, $actual );
		}
	}
}
