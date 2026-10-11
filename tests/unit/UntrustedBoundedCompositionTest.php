<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-bounded-manifest-transition.php';
require_once __DIR__ . '/fixtures/product-source-bindings.php';

final class UntrustedBoundedCompositionTest extends TestCase {
	public function test_every_new_marker_assertion_is_additive_and_the_complete_bounded_view_is_preserved(): void {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
		$bounded = Wstm108_Bounded_Manifest_Transition::bounded( $manifest );
		self::assertCount( 601, $manifest );
		self::assertSame( array_column( $manifest, 'label' ), array_column( $bounded, 'label' ) );
		$changes = 0;
		foreach ( $manifest as $index => $case ) {
			$before = $bounded[ $index ];
			$copy = json_decode( json_encode( $case, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 512, JSON_THROW_ON_ERROR );
			if ( isset( $copy->assert_values ) ) {
				foreach ( get_object_vars( $copy->assert_values ) as $path => $value ) {
					if ( isset( $before->assert_values ) && property_exists( $before->assert_values, $path ) ) { continue; }
					self::assertSame( 'success', $case->expect );
					self::assertSame( 1, preg_match( '/^data(?:\.[a-z_]+|\.\d+)*\.untrusted_fields$/', $path ), $case->label );
					self::assertIsArray( $value );
					self::assertSame( array_values( array_unique( $value ) ), $value );
					unset( $copy->assert_values->$path );
				}
				if ( ! isset( $before->assert_values ) ) {
					self::assertSame( array(), get_object_vars( $copy->assert_values ) );
					unset( $copy->assert_values );
				}
			}
			$encode = static fn( $value ): string => json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
			self::assertSame( $encode( $before ), $encode( $copy ), $case->label );
			$changes += $encode( $before ) !== $encode( $case ) ? 1 : 0;
		}
		self::assertSame( 188, $changes );
	}

	public function test_composition_preserves_current_bounded_and_marker_production_sources(): void {
		foreach ( array( 'posts', 'custom-post-types', 'list-query', 'untrusted' ) as $name ) {
			$path = 'includes/class-' . $name . '.php';
			wstm_test_assert_product_source( $path, file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_finite_composition_binding_rejects_production_drift(): void {
		$path = 'includes/class-list-query.php';
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
		$this->expectException( RuntimeException::class );
		wstm_test_assert_product_source( $path, $source . "\n" );
	}
}
