<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-plan.php';

final class UntrustedPlanTest extends TestCase {
	private static function native(): array {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		return array_values( array_unique( array_column( $manifest, 'ability' ) ) );
	}

	public function test_complete_inventory_requires_90_annotations_and_98_cases_at_each_actual_boundary(): void {
		$labels = Wstm108_Plan::labels( self::native() );
		self::assertCount( 286, $labels );
		self::assertCount( 286, array_unique( $labels ) );
		foreach ( array( 'annotations' => 90, 'gateway' => 98, 'individual' => 98 ) as $prefix => $count ) {
			self::assertCount( $count, array_filter( $labels, static fn( $label ) => 0 === strpos( $label, $prefix . ':' ) ) );
		}
		self::assertContains( 'annotations:webmastery-site-toolkit-for-mcp/bulk-publish-posts', $labels );
		self::assertContains( 'individual:subscriber-denied:get-cpt-wstm108-record', $labels );
		$cases = array_map( static fn( $label ) => array( 'label' => $label, 'passed' => true ), $labels );
		Wstm108_Plan::validate_cases( $cases, self::native() );
		$cases[0]['passed'] = false;
		Wstm108_Plan::validate_cases( $cases, self::native() );
	}

	public static function changes(): array {
		return array_map( static fn( $change ) => array( $change ), array( 'missing', 'extra', 'duplicate', 'order', 'label', 'absent-outcome', 'integer-outcome', 'native-missing', 'native-extra', 'native-duplicate', 'foreign', 'stage-name' ) );
	}

	/** @dataProvider changes */
	public function test_no_threshold_or_fabricated_outcome_can_satisfy_the_inventory( string $change ): void {
		$native = self::native();
		$cases = array_map( static fn( $label ) => array( 'label' => $label, 'passed' => true ), Wstm108_Plan::labels( $native ) );
		if ( 'missing' === $change ) { array_pop( $cases ); }
		if ( 'extra' === $change ) { $cases[] = $cases[0]; }
		if ( 'duplicate' === $change ) { $cases[1] = $cases[0]; }
		if ( 'order' === $change ) { [ $cases[0], $cases[1] ] = array( $cases[1], $cases[0] ); }
		if ( 'label' === $change ) { $cases[0]['label'] .= '-foreign'; }
		if ( 'absent-outcome' === $change ) { unset( $cases[0]['passed'] ); }
		if ( 'integer-outcome' === $change ) { $cases[0]['passed'] = 1; }
		if ( 'native-missing' === $change ) { array_pop( $native ); }
		if ( 'native-extra' === $change ) { $native[] = 'webmastery-site-toolkit-for-mcp/extra'; }
		if ( 'native-duplicate' === $change ) { $native[1] = $native[0]; }
		if ( 'foreign' === $change ) { $native[0] = 'foreign/name'; }
		if ( 'stage-name' === $change ) { $native[0] = 'webmastery-site-toolkit-for-mcp/get-cpt-wstm108-record'; }
		$this->expectException( RuntimeException::class );
		Wstm108_Plan::validate_cases( $cases, $native );
	}
}
