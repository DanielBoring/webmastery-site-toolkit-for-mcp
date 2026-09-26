<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-manifest-projection.php';

final class UntrustedHygieneManifestTest extends TestCase {
	private static function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
	}

	public function test_only_four_new_markers_are_removed_before_the_unchanged_historical_projection(): void {
		$current = self::manifest();
		$before = serialize( $current );
		$restored = Wstm108_Manifest_Projection::restore_pre_hygiene_manifest( $current );
		$indices = array( 332, 334, 335, 337 );
		$path = 'data.items.0.untrusted_fields';
		foreach ( $current as $index => $case ) {
			$expected = unserialize( serialize( $case ) );
			if ( in_array( $index, $indices, true ) ) {
				self::assertTrue( property_exists( $expected->assert_values, $path ) );
				unset( $expected->assert_values->$path );
			}
			self::assertSame( serialize( $expected ), serialize( $restored[ $index ] ) );
		}
		self::assertSame( Wstm108_Compact_Delete_Calibration::ledger()->after_manifest_sha256, Wstm108_Manifest_Projection::fingerprint( $restored ) );
		self::assertSame( Wstm108_Manifest_Projection::BASELINE_SHA256, Wstm108_Manifest_Projection::fingerprint( Wstm108_Manifest_Projection::project( $current ) ) );
		self::assertSame( 190, Wstm108_Manifest_Projection::inventory()->marked_case_count );
		self::assertSame( 200, Wstm108_Manifest_Projection::inventory()->marker_assertion_count );
		self::assertSame( $before, serialize( $current ) );
	}

	public static function case_mutations(): array {
		$cases = array();
		foreach ( array( 332, 334, 335, 337 ) as $index ) {
			foreach ( array( 'missing', 'weakened', 'extra', 'reordered', 'object', 'wrong-record', 'data-value', 'label', 'role', 'input-type', 'extra-marker', 'assert-values-type' ) as $mutation ) {
				$cases[ $index . ':' . $mutation ] = array( $index, $mutation );
			}
		}
		return $cases;
	}

	/** @dataProvider case_mutations */
	public function test_each_success_case_requires_exact_markers_and_preserves_all_other_typed_data( int $index, string $mutation ): void {
		$manifest = self::manifest();
		$case = $manifest[ $index ];
		$path = 'data.items.0.untrusted_fields';
		switch ( $mutation ) {
			case 'missing':
				unset( $case->assert_values->$path );
				break;
			case 'weakened':
				array_pop( $case->assert_values->$path );
				break;
			case 'extra':
				$case->assert_values->{$path}[] = 'author_login';
				break;
			case 'reordered':
				$case->assert_values->$path = array_reverse( $case->assert_values->$path );
				break;
			case 'object':
				$case->assert_values->$path = (object) $case->assert_values->$path;
				break;
			case 'wrong-record':
				$case->assert_values->{'data.untrusted_fields'} = $case->assert_values->$path;
				unset( $case->assert_values->$path );
				break;
			case 'data-value':
				$case->assert_values->{'data.items.0.title'} = 'changed stored title';
				break;
			case 'label':
				$case->label .= ' changed';
				break;
			case 'role':
				$case->role = 'subscriber';
				break;
			case 'input-type':
				$case->input = array();
				break;
			case 'extra-marker':
				$case->assert_values->{'data.items.1.untrusted_fields'} = $case->assert_values->$path;
				break;
			case 'assert-values-type':
				$case->assert_values = array();
				break;
			default:
				self::fail( 'Unknown hygiene mutation.' );
		}
		$this->expectException( AssertionFailedError::class );
		Wstm108_Manifest_Projection::project( $manifest );
	}

	public static function denied_cases(): array {
		return array( array( 333 ), array( 336 ), array( 338 ) );
	}

	/** @dataProvider denied_cases */
	public function test_denied_roles_cannot_gain_record_markers( int $index ): void {
		$manifest = self::manifest();
		$case = $manifest[ $index ];
		self::assertSame( 'subscriber', $case->role );
		self::assertSame( 'failure', $case->expect );
		self::assertSame( 'forbidden', $case->expect_error_code );
		self::assertSame( 'ability_invalid_permissions', $case->expect_error_reason );
		$case->assert_values = (object) array( 'data.items.0.untrusted_fields' => array( 'title', 'url' ) );
		$this->expectException( AssertionFailedError::class );
		Wstm108_Manifest_Projection::project( $manifest );
	}
}
