<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/bounded-manifest-projection.php';

final class BoundedManifestProjectionTest extends TestCase {
	private static function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
	}

	public function test_both_historical_views_retain_their_exact_cardinality(): void {
		$manifest = self::manifest();
		self::assertCount( 601, $manifest );
		self::assertCount( 594, BoundedManifestProjection::bounded_source( $manifest ) );
		self::assertCount( 589, BoundedManifestProjection::schema_source( $manifest ) );
		self::assertCount( 570, BoundedManifestProjection::accepted_main( $manifest ) );
		self::assertCount( 601, $manifest );
	}

	public static function owned_mutations(): array {
		return array_map( static fn( string $name ): array => array( $name ), array(
			'array-to-object', 'integer-to-float', 'role', 'summary-omission', 'object-correction',
			'schema-oracle', 'raw-permission', 'extra-case', 'duplicate-label', 'missing-case',
			'case-order', 'root-property-order', 'input-property-order', 'plain-capability',
		) );
	}

	/** @dataProvider owned_mutations */
	public function test_owned_projection_cannot_hide_unapproved_changes( string $mutation ): void {
		$manifest = self::manifest();
		$cases = array_column( $manifest, null, 'label' );
		switch ( $mutation ) {
			case 'array-to-object':
				$cases['wstm121 mapped CPT rejects array fields']->input->fields = new stdClass();
				break;
			case 'integer-to-float':
				$cases['wstm121 list-pages rejects numeric fields']->input->fields = 1.0;
				break;
			case 'role':
				$cases['wstm121 list-posts full retains content']->role = 'administrator';
				break;
			case 'summary-omission':
				unset( $cases['wstm121 list-revisions post default summary']->assert_missing_paths );
				break;
			case 'object-correction':
				$cases['wstm110 update-post rejects empty metadata presence']->input->meta = array();
				break;
			case 'schema-oracle':
				$cases['wstm110 create-cpt-mcp-case-study rejects metadata input']->expect_error_reason = 'metadata_requires_separate_call';
				break;
			case 'raw-permission':
				$cases['wstm122 denied post write with plain input preserves metadata']->assert_permission = 'invalid_input';
				break;
			case 'extra-case':
				$manifest[] = clone $manifest[0];
				break;
			case 'duplicate-label':
				$manifest[1]->label = $manifest[0]->label;
				break;
			case 'missing-case':
				array_pop( $manifest );
				break;
			case 'case-order':
				[ $manifest[0], $manifest[1] ] = [ $manifest[1], $manifest[0] ];
				break;
			case 'root-property-order':
				$case = $cases['wstm122 denied post write preserves metadata'];
				$value = $case->assert_post_meta;
				unset( $case->assert_post_meta );
				$case->assert_post_meta = $value;
				break;
			case 'input-property-order':
				$case = $cases['wstm122 denied post write preserves metadata'];
				$case->input = (object) array_reverse( get_object_vars( $case->input ), true );
				break;
			case 'plain-capability':
				$cases['wstm122 denied post write with plain input preserves metadata']->assert_capabilities[0]->allowed = true;
				break;
		}
		$this->expectException( AssertionFailedError::class );
		BoundedManifestProjection::accepted_main( $manifest );
	}

	public static function incoming_mutations(): array {
		return array_map( static fn( string $name ): array => array( $name ), array( 'error-reason', 'flag-type', 'privacy-role', 'privacy-type' ) );
	}

	/** @dataProvider incoming_mutations */
	public function test_incoming_projection_cannot_hide_unapproved_changes( string $mutation ): void {
		$manifest = self::manifest();
		$cases = array_column( $manifest, null, 'label' );
		$ledger = json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/destructive-safety-boolean-ledger.json' ), false, 512, JSON_THROW_ON_ERROR );
		$change = $ledger->cases[0];
		if ( 'error-reason' === $mutation ) {
			$cases[ $change->label ]->expect_error_reason = $change->after->expect_error_reason;
		}
		if ( 'flag-type' === $mutation ) {
			$cases[ $change->label ]->input->{$change->flag} = true;
		}
		if ( 'privacy-role' === $mutation ) {
			$cases[ $ledger->integration->cases[0]->label ]->role = 'subscriber';
		}
		if ( 'privacy-type' === $mutation ) {
			$cases[ $ledger->integration->cases[5]->label ]->input->include_table_names = 1.0;
		}
		$this->expectException( AssertionFailedError::class );
		BoundedManifestProjection::bounded_source( $manifest );
	}
}
