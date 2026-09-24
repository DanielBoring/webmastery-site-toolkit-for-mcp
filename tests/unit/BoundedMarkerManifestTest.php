<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/bounded-manifest-projection.php';

final class BoundedMarkerManifestTest extends TestCase {
	private const LABELS = array( 'list-orphaned-media', 'list-posts-no-featured-image posts', 'list-posts-no-featured-image pages', 'list-stuck-scheduled' );
	private const PATH = 'data.items.0.untrusted_fields';

	private static function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
	}

	private static function typed( $value ): string {
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
	}

	public function test_closed_marker_layer_preserves_every_original_typed_property_and_historical_projection(): void {
		$manifest = self::manifest();
		$original = self::typed( $manifest );
		$before = BoundedManifestProjection::before_markers( $manifest );
		self::assertCount( 601, $before );
		$changes = 0;
		foreach ( $manifest as $index => $case ) {
			$expected = json_decode( self::typed( $case ), false, 512, JSON_THROW_ON_ERROR );
			if ( in_array( $case->label, self::LABELS, true ) ) {
				unset( $expected->assert_values->{self::PATH} );
				++$changes;
			}
			self::assertSame( self::typed( $expected ), self::typed( $before[ $index ] ), $case->label );
		}
		self::assertSame( 4, $changes );
		self::assertSame( 'da4395a6a9d6532c10423e25d02c710c2ed150d226dfa87a988b5594ede8fa4a',
			hash( 'sha256', self::typed( BoundedManifestProjection::accepted_main( $manifest ) ) ) );
		self::assertCount( 594, BoundedManifestProjection::bounded_source( $manifest ) );
		self::assertCount( 589, BoundedManifestProjection::schema_source( $manifest ) );
		self::assertSame( $original, self::typed( $manifest ) );
	}

	public static function mutations(): array {
		$cases = array();
		foreach ( self::LABELS as $label ) {
			foreach ( array( 'missing', 'empty', 'duplicate', 'extra', 'wrong-record', 'marker-object',
				'role', 'input-type', 'ability', 'failure', 'stored-value', 'extra-assertion' ) as $mutation ) {
				$cases[ $label . ': ' . $mutation ] = array( $label, $mutation );
			}
		}
		return $cases;
	}

	/** @dataProvider mutations */
	public function test_no_marker_or_typed_case_relaxation_is_hidden( string $label, string $mutation ): void {
		$manifest = self::manifest();
		$case = array_column( $manifest, null, 'label' )[ $label ];
		$path = self::PATH;
		switch ( $mutation ) {
			case 'missing': unset( $case->assert_values->$path ); break;
			case 'empty': $case->assert_values->$path = array(); break;
			case 'duplicate': $case->assert_values->{$path}[] = 'title'; break;
			case 'extra': $case->assert_values->{$path}[] = 'content'; break;
			case 'wrong-record':
				$case->assert_values->{'data.untrusted_fields'} = $case->assert_values->$path;
				unset( $case->assert_values->$path );
				break;
			case 'marker-object': $case->assert_values->$path = (object) $case->assert_values->$path; break;
			case 'role': $case->role = 'administrator'; break;
			case 'input-type': $case->input->per_page = 5.0; break;
			case 'ability': $case->ability = 'webmastery-site-toolkit-for-mcp/list-users'; break;
			case 'failure': $case->expect = 'failure'; break;
			case 'stored-value': $case->assert_values->{'data.items.0.title'} = 'Changed sentinel'; break;
			case 'extra-assertion': $case->assert_values->{'data.items.1.untrusted_fields'} = array( 'title' ); break;
		}
		$this->expectException( AssertionFailedError::class );
		BoundedManifestProjection::accepted_main( $manifest );
	}

	public static function denied_labels(): array {
		return array(
			array( 'list-orphaned-media as subscriber' ),
			array( 'list-posts-no-featured-image as subscriber' ),
			array( 'list-stuck-scheduled as subscriber' ),
		);
	}

	/** @dataProvider denied_labels */
	public function test_denied_cases_cannot_acquire_success_markers( string $label ): void {
		$manifest = self::manifest();
		$cases = array_column( $manifest, null, 'label' );
		self::assertArrayHasKey( $label, $cases );
		self::assertSame( 'failure', $cases[ $label ]->expect );
		$cases[ $label ]->assert_values = (object) array( self::PATH => array( 'title', 'url' ) );
		$this->expectException( AssertionFailedError::class );
		BoundedManifestProjection::accepted_main( $manifest );
	}
}
