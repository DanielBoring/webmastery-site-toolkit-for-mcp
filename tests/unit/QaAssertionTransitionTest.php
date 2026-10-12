<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/qa-assertion-transition.php';

final class QaAssertionTransitionTest extends TestCase {
	private static function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
	}

	public function test_only_eleven_additive_oracles_are_reversed_without_mutating_live_cases(): void {
		$manifest = self::manifest();
		$original = QaAssertionTransition::fingerprint( $manifest );
		$before = QaAssertionTransition::before( $manifest );
		$ledger = QaAssertionTransition::ledger();
		$changes = array_column( $ledger->changes, null, 'index' );
		self::assertCount( 11, $changes );
		foreach ( $manifest as $index => $case ) {
			$copy = clone $case;
			foreach ( $changes[ $index ]->added_fields ?? array() as $field ) {
				self::assertFalse( property_exists( $before[ $index ], $field ) );
				unset( $copy->$field );
			}
			self::assertSame( QaAssertionTransition::fingerprint( $before[ $index ] ), QaAssertionTransition::fingerprint( $copy ) );
		}
		self::assertSame( $original, QaAssertionTransition::fingerprint( $manifest ) );
		self::assertSame( $ledger->before_sha256, QaAssertionTransition::fingerprint( $before ) );
		self::assertSame( $before, QaAssertionTransition::before( $before ) );
	}

	public static function mutations(): array {
		$cases = array();
		foreach ( array( 250, 253, 257, 261, 264, 268, 300, 303, 395, 397, 423 ) as $index ) {
			foreach ( array( 'missing', 'value', 'role', 'input', 'outcome' ) as $mutation ) {
				$cases[ "$index:$mutation" ] = array( $index, $mutation );
			}
		}
		return $cases;
	}

	/** @dataProvider mutations */
	public function test_changed_or_missing_assertions_and_non_oracle_drift_are_refused( int $index, string $mutation ): void {
		$manifest = self::manifest();
		$changes = array_column( QaAssertionTransition::ledger()->changes, null, 'index' );
		$field = $changes[ $index ]->added_fields[0];
		switch ( $mutation ) {
			case 'missing': unset( $manifest[ $index ]->$field ); break;
			case 'value': $manifest[ $index ]->$field = false; break;
			case 'role': $manifest[ $index ]->role = 'administrator'; break;
			case 'input': $manifest[ $index ]->input = (object) array( 'unapproved' => 1.0 ); break;
			case 'outcome': $manifest[ $index ]->expect = 'failure'; break;
		}
		$this->expectException( AssertionFailedError::class );
		QaAssertionTransition::before( $manifest );
	}

	public function test_unrelated_case_drift_and_reordering_are_not_hidden(): void {
		$manifest = self::manifest();
		[ $manifest[0], $manifest[1] ] = array( $manifest[1], $manifest[0] );
		$this->expectException( AssertionFailedError::class );
		QaAssertionTransition::before( $manifest );
	}
}
