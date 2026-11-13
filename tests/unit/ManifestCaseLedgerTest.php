<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/manifest-case-ledger.php';
require_once __DIR__ . '/fixtures/qa-assertion-transition.php';

/**
 * Prevents unrecorded E2E manifest drift: every case added or changed after the sealed
 * 601-case manifest must be recorded, and must reverse exactly to the sealed view that
 * the historical projection tests check.
 */
final class ManifestCaseLedgerTest extends TestCase {
	public function test_live_manifest_reduces_to_the_sealed_manifest_of_the_historical_chain(): void {
		$live = ManifestCaseLedger::live();
		$original = ManifestCaseLedger::fingerprint( $live );
		$sealed = ManifestCaseLedger::sealed( $live );
		self::assertCount( 601, $sealed );
		self::assertSame( QaAssertionTransition::ledger()->after_sha256, ManifestCaseLedger::fingerprint( $sealed ) );
		self::assertSame( $original, ManifestCaseLedger::fingerprint( $live ), 'Reduction must not mutate the caller copy.' );
		self::assertSame( ManifestCaseLedger::fingerprint( $sealed ), ManifestCaseLedger::fingerprint( ManifestCaseLedger::sealed( $live ) ) );
	}

	public function test_recorded_steps_only_add_cases_or_change_assertions_of_existing_cases(): void {
		$ledger = ManifestCaseLedger::ledger();
		self::assertNotEmpty( $ledger->steps );
		$count = ManifestCaseLedger::SEALED_COUNT;
		$hash = ManifestCaseLedger::SEALED_SHA256;
		$labels = array_column( ManifestCaseLedger::sealed(), 'label' );
		foreach ( $ledger->steps as $step ) {
			self::assertSame( $count, $step->before_count, (string) $step->issue );
			self::assertSame( $hash, $step->before_sha256, (string) $step->issue );
			self::assertSame( $count + count( $step->added ), $step->after_count );
			foreach ( $step->added as $addition ) {
				self::assertNotContains( $addition->case->label, $labels );
				$labels[] = $addition->case->label;
			}
			foreach ( $step->changed as $change ) {
				foreach ( array( 'ability', 'label', 'role', 'input', 'expect' ) as $field ) {
					self::assertSame( ManifestCaseLedger::fingerprint( $change->before->$field ?? null ), ManifestCaseLedger::fingerprint( $change->after->$field ?? null ), $change->label . ': ' . $field );
				}
				self::assertNotSame( ManifestCaseLedger::fingerprint( $change->before ), ManifestCaseLedger::fingerprint( $change->after ) );
			}
			$count = $step->after_count;
			$hash = $step->after_sha256;
		}
		$live = ManifestCaseLedger::live();
		self::assertCount( $count, $live );
		self::assertSame( $hash, ManifestCaseLedger::fingerprint( $live ) );
		self::assertSame( count( $labels ), count( array_unique( $labels ) ) );
	}

	public function test_step_238_strengthens_backup_assertions_without_relaxing_any(): void {
		$steps = array_column( ManifestCaseLedger::ledger()->steps, null, 'issue' );
		self::assertArrayHasKey( 238, $steps );
		self::assertCount( 6, $steps[238]->added );
		self::assertSame( array( 'backup-status as admin', 'backup-status BackWPup' ), array_column( $steps[238]->changed, 'label' ) );
		foreach ( $steps[238]->changed as $change ) {
			$before = get_object_vars( $change->before->assert_values );
			$after = get_object_vars( $change->after->assert_values );
			self::assertSame( $before, array_intersect_key( $after, $before ) );
			self::assertSame( array( 'data.active_known_plugins.0.backup_verified' => true ), array_diff_key( $after, $before ) );
		}
	}

	public static function drift(): array {
		return array_map( static fn( string $name ): array => array( $name ), array(
			'added-assertion-relaxed', 'added-case-missing', 'changed-case-reverted', 'sealed-case-edited',
			'extra-case', 'case-order', 'integer-to-float', 'added-case-moved',
		) );
	}

	/** @dataProvider drift */
	public function test_unrecorded_manifest_drift_is_refused( string $mutation ): void {
		$manifest = ManifestCaseLedger::live();
		$labels = array_flip( array_column( $manifest, 'label' ) );
		$added = $labels['backup-status Jetpack only is not a verified backup'];
		$changed = $labels['backup-status BackWPup'];
		switch ( $mutation ) {
			case 'added-assertion-relaxed': unset( $manifest[ $added ]->assert_missing_paths ); break;
			case 'added-case-missing': array_splice( $manifest, $added, 1 ); break;
			case 'changed-case-reverted': unset( $manifest[ $changed ]->assert_values->{'data.active_known_plugins.0.backup_verified'} ); break;
			case 'sealed-case-edited': $manifest[0]->role = 'admin'; break;
			case 'extra-case': $manifest[] = clone $manifest[ $added ]; break;
			case 'case-order': [ $manifest[0], $manifest[1] ] = array( $manifest[1], $manifest[0] ); break;
			case 'integer-to-float': $manifest[ $labels['list-tags second page returns the next term'] ]->input->page = 2.0; break;
			case 'added-case-moved':
				$case = $manifest[ $added ];
				array_splice( $manifest, $added, 1 );
				array_splice( $manifest, $added - 1, 0, array( $case ) );
				break;
		}
		$this->expectException( RuntimeException::class );
		ManifestCaseLedger::sealed( $manifest );
	}

	public function test_recording_refuses_removed_or_reordered_cases(): void {
		$before = ManifestCaseLedger::sealed();
		$after = $before;
		array_pop( $after );
		try {
			ManifestCaseLedger::build_step( 1, 'removal', $before, $after );
			self::fail( 'Removal was recorded.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Removing', $error->getMessage() );
		}
		$after = $before;
		[ $after[0], $after[1] ] = array( $after[1], $after[0] );
		$this->expectExceptionMessage( 'Reordering' );
		ManifestCaseLedger::build_step( 1, 'order', $before, $after );
	}

	public function test_recording_the_live_change_reproduces_the_committed_step(): void {
		$steps = ManifestCaseLedger::ledger()->steps;
		$last = end( $steps );
		$live = ManifestCaseLedger::live();
		$before = ManifestCaseLedger::reverse_step( $live, $last );
		$built = ManifestCaseLedger::build_step( $last->issue, $last->summary, $before, $live );
		self::assertSame( ManifestCaseLedger::fingerprint( $last ), ManifestCaseLedger::fingerprint( $built ) );
	}
}
