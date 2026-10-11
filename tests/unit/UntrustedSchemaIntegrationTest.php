<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-manifest-projection.php';
require_once __DIR__ . '/fixtures/schema-integration-ledger.php';

final class UntrustedSchemaIntegrationTest extends TestCase {
	private static function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
	}

	public function test_exact_main_and_pr_projections_preserve_both_immutable_oracles(): void {
		$ledger = Wstm108_Schema_Integration::ledger();
		$manifest = self::manifest();
		self::assertCount( 601, $manifest );
		$manifest = Wstm108_Bounded_Manifest_Transition::historical( $manifest );
		self::assertCount( 589, $manifest );
		self::assertSame( '8ea870beb03076c4d118e8d8bccb3304947bf03a', $ledger->main_sha );
		self::assertSame( 'c35b4f2b7f80731f59feee1f4939683bb632222d', $ledger->pr_sha );
		self::assertSame( $ledger->merged_manifest_sha256, Wstm108_Schema_Integration::fingerprint( $manifest ) );
		$main = Wstm108_Schema_Integration::main_input( $manifest );
		self::assertSame( $ledger->main_manifest_sha256, Wstm108_Schema_Integration::fingerprint( $main ) );
		$pr = Wstm108_Schema_Integration::pr_input( $manifest );
		self::assertCount( 570, $pr );
		self::assertSame( $ledger->pr_manifest_sha256, Wstm108_Schema_Integration::fingerprint( $pr ) );
		self::assertSame( Wstm108_Manifest_Projection::BASELINE_SHA256, Wstm108_Schema_Integration::fingerprint( wstm126_parent_manifest( $main ) ) );
		self::assertSame( Wstm108_Manifest_Projection::BASELINE_SHA256, Wstm108_Schema_Integration::fingerprint( Wstm108_Manifest_Projection::project( $manifest ) ) );
		$retired = $main_only = 0;
		foreach ( $ledger->changes as $change ) {
			self::assertSame( $change->before_sha256, Wstm108_Schema_Integration::fingerprint( $main[ $change->index ] ) );
			self::assertSame( $change->after_sha256, Wstm108_Schema_Integration::fingerprint( $manifest[ $change->index ] ) );
			self::assertSame( Wstm108_Schema_Integration::fingerprint( $main[ $change->index ]->input ), Wstm108_Schema_Integration::fingerprint( $manifest[ $change->index ]->input ) );
			self::assertSame( $main[ $change->index ]->role, $manifest[ $change->index ]->role );
			if ( ! $change->applied ) {
				++$retired;
				self::assertSame( 'failure', $manifest[ $change->index ]->expect );
				self::assertSame( 'ability_invalid_input', $manifest[ $change->index ]->expect_error_reason );
				self::assertSame( $change->before_sha256, $change->after_sha256 );
			}
			if ( null === $change->pr_index ) { ++$main_only; }
		}
		self::assertCount( 195, $ledger->changes );
		self::assertSame( 4, $retired );
		self::assertSame( 1, $main_only );
	}

	public static function mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'extra', 'missing', 'order', 'integer-float', 'object-array', 'marker', 'main-oracle', 'role', 'unknown-field' ) );
	}

	/** @dataProvider mutations */
	public function test_unapproved_complete_typed_case_changes_are_refused( string $mutation ): void {
		$manifest = self::manifest();
		if ( 'extra' === $mutation ) { $manifest[] = $manifest[0]; }
		if ( 'missing' === $mutation ) { array_pop( $manifest ); }
		if ( 'order' === $mutation ) { [ $manifest[0], $manifest[1] ] = array( $manifest[1], $manifest[0] ); }
		if ( 'integer-float' === $mutation ) { $manifest[0]->input->parent = 0.0; }
		if ( 'object-array' === $mutation ) { $manifest[0]->input = array_values( (array) $manifest[0]->input ); }
		if ( 'marker' === $mutation ) {
			foreach ( $manifest as $case ) {
				if ( isset( $case->assert_values->{'data.untrusted_fields'} ) ) {
					$case->assert_values->{'data.untrusted_fields'} = array( 'title' );
					break;
				}
			}
		}
		if ( 'main-oracle' === $mutation ) { $manifest[0]->assert_unchanged = false; }
		if ( 'role' === $mutation ) { $manifest[0]->role = 'admin'; }
		if ( 'unknown-field' === $mutation ) { $manifest[0]->unapproved = true; }
		$this->expectException( RuntimeException::class );
		Wstm108_Schema_Integration::main_input( $manifest );
	}
}
