<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm113Calibration\State;

require_once __DIR__ . '/fixtures/scheduling-calibration.php';

final class SchedulingCalibrationTest extends TestCase {
	public static function variants(): array {
		return array(
			array( 'post', 'post', 'post_id' ),
			array( 'page', 'page', 'page_id' ),
			array( 'mcp_book', 'cpt-mcp-book', 'id' ),
			array( 'mcp_case_study', 'cpt-mcp-case-study', 'id' ),
		);
	}

	public function test_all_264_case_identities_and_original_helper_expectation_remain(): void {
		$original = array( 'unparseable', 'missing', 'empty', 'whitespace', 'zero-string', 'overflow-day', 'nonleap', 'overflow-month', 'overflow-hour', 'past', 'now', 'near-now', 'invalid-nonfuture', 'utc', 'positive-offset', 'negative-offset', 'offsetless', 'relative', 'date-only', 'fold-first', 'fold-second', 'named-gap', 'nonfuture-date', 'ordinary', 'omitted-status', 'nonfuture-omitted-status-date' );
		$updates = array( 'existing-future-omitted', 'existing-future-explicit', 'existing-future-empty', 'existing-future-near', 'existing-future-overdue', 'direct-invalid-status-overdue', 'existing-future-invalid', 'existing-timezone-change', 'explicit-unschedule', 'explicit-publish-overdue', 'pending-zero-gmt', 'private-transition', 'publish-transition', 'nonfuture-zero-gmt' );
		$reasons = array_fill_keys( array( 'unparseable', 'zero-string', 'overflow-day', 'nonleap', 'overflow-month', 'overflow-hour', 'invalid-nonfuture', 'existing-future-invalid' ), 'invalid_scheduled_date' )
			+ array_fill_keys( array( 'missing', 'empty', 'whitespace', 'existing-future-empty' ), 'missing_scheduled_date' )
			+ array_fill_keys( array( 'past', 'now', 'near-now', 'existing-future-near', 'existing-future-overdue' ), 'scheduled_date_too_soon' )
			+ array( 'direct-invalid-status-overdue' => 'ability_invalid_input' );
		$identities = array();
		foreach ( self::variants() as [ $type, $base, $id_key ] ) {
			foreach ( array( 'create', 'update' ) as $operation ) {
				$cases = Wstm113Calibration\cases( $operation );
				self::assertSame( 'update' === $operation ? array_merge( $original, $updates ) : $original, array_column( $cases, 0 ) );
				foreach ( $cases as $case ) {
					$identities[] = "$operation-$base:{$case[0]}";
					self::assertSame( $reasons[$case[0]] ?? null, $case[2], $case[0] );
					if ( 'direct-invalid-status-overdue' === $case[0] ) {
						self::assertSame( array( $case[0], array( 'status' => 'not-a-status' ), 'ability_invalid_input', 'overdue' ), $case );
					}
				}
			}
			$summary = Wstm113Calibration\run( $type, $base, $id_key );
			self::assertSame( 'scheduled_date_too_soon', $summary['cases'][0]['layers']['scheduling_helper']['expected_reason'] );
		}
		self::assertCount( 264, $identities );
		self::assertCount( 264, array_unique( $identities ) );
	}

	/** @dataProvider variants */
	public function test_both_real_validators_share_the_fixture_and_observation_window( string $type, string $base, string $id_key ): void {
		$summary = Wstm113Calibration\run( $type, $base, $id_key );
		self::assertSame( 1, $summary['passed'] );
		self::assertSame( 0, $summary['failed'] );
		self::assertCount( 1, $summary['cases'] );
		$row = $summary['cases'][0];
		self::assertTrue( $row['passed'] );
		self::assertSame( 'update-' . $base, $row['ability'] );
		self::assertSame( 'direct-invalid-status-overdue', $row['label'] );
		self::assertSame( 'ability_invalid_input', $row['expected_error'] );
		self::assertSame( $row['before'], $row['after'] );
		self::assertSame( $row['before'], $row['layers']['registered_callback']['after'] );
		self::assertSame( array(), $row['hooks'] );
		self::assertSame( array(), $row['expected_date'] );
		self::assertSame( 1906545600, $row['stored']['initial_cron'] );
		self::assertSame( $row['stored']['initial_cron'], $row['stored']['cron_after_early_guard'] );
		self::assertSame( array( 'snapshot', 'registered_callback', 'snapshot', 'scheduling_helper', 'snapshot' ), State::$phases );
		self::assertSame( array( 42, 42 ), State::$post_reads );
		self::assertSame( array( 'registered_callback', 'scheduling_helper' ), array_keys( State::$calls ) );
		self::assertSame( State::$calls['registered_callback']['input'], State::$calls['scheduling_helper']['input'] );
		self::assertSame( 42, State::$calls['scheduling_helper']['input'][$id_key] );
		self::assertSame( State::$post, State::$calls['scheduling_helper']['post'] );
		self::assertInstanceOf( WP_Error::class, State::$calls['scheduling_helper']['native_result'] );
		foreach ( $row['layers'] as $name => $layer ) {
			self::assertTrue( $layer['error_matches'] );
			self::assertNull( $layer['oracle_failure'] );
			self::assertSame( 'invalid_input', $layer['result']['error']['code'] );
			self::assertSame( $layer['expected_reason'], $layer['actual_reason'] );
			self::assertSame( State::$calls[$name]['input'], $layer['input'] );
			self::assertSame( json_encode( State::$calls[$name]['result'] ), json_encode( $layer['result'] ) );
			self::assertTrue( State::$calls[$name]['observing'] );
		}
		self::assertSame( $row['result'], $row['layers']['registered_callback']['result'] );
		self::assertSame( State::$post, $row['layers']['scheduling_helper']['post'] );
	}

	public static function faults(): array {
		$rows = array();
		foreach ( self::variants() as $variant ) {
			foreach ( array( 'registered_callback', 'scheduling_helper' ) as $layer ) {
				$faults = array( 'wrong-reason', 'wrong-code', 'success', 'raw-object', 'object-data', 'redirect-id', 'post', 'metadata', 'sentinel-read', 'terms', 'cron', 'hook' );
				if ( 'registered_callback' === $layer ) { $faults[] = 'native-error'; }
				foreach ( $faults as $fault ) {
					$rows[] = array_merge( $variant, array( $layer, $fault ) );
				}
			}
		}
		return $rows;
	}

	/** @dataProvider faults */
	public function test_either_layer_wrong_reason_or_side_effect_fails_with_evidence_retained( string $type, string $base, string $id_key, string $layer, string $fault ): void {
		$summary = Wstm113Calibration\run( $type, $base, $id_key, $layer, $fault );
		self::assertSame( 0, $summary['passed'] );
		self::assertSame( 1, $summary['failed'] );
		self::assertCount( 1, $summary['cases'] );
		$row = $summary['cases'][0];
		self::assertFalse( $row['passed'] );
		self::assertSame( array( 'registered_callback', 'scheduling_helper' ), array_keys( State::$calls ) );
		self::assertSame( array( 'snapshot', 'registered_callback', 'snapshot', 'scheduling_helper', 'snapshot' ), State::$phases );
		self::assertSame( array( 42, 42 ), State::$post_reads );
		self::assertSame( 42, $row['stored']['id'] );
		self::assertSame( State::$calls['registered_callback']['result'], $row['result'] );
		foreach ( $row['layers'] as $name => $evidence ) {
			self::assertSame( json_encode( State::$calls[$name]['result'] ), json_encode( $evidence['result'] ) );
			self::assertTrue( State::$calls[$name]['observing'] );
		}
		self::assertSame( $row['layers']['registered_callback']['input'], $row['layers']['scheduling_helper']['input'] );
		if ( in_array( $fault, array( 'raw-object', 'object-data', 'redirect-id', 'native-error' ), true ) ) {
			self::assertSame( State::$calls[$layer]['result'], $row['layers'][$layer]['result'] );
		}
		if ( in_array( $fault, array( 'wrong-reason', 'wrong-code', 'success', 'raw-object', 'object-data', 'redirect-id', 'native-error' ), true ) ) {
			self::assertFalse( $row['layers'][$layer]['error_matches'] );
			self::assertSame( $row['before'], $row['after'] );
			self::assertSame( array(), $row['hooks'] );
			if ( 'wrong-reason' === $fault ) {
				self::assertSame( 'invalid_scheduled_date', $row['layers'][$layer]['actual_reason'] );
				self::assertNull( $row['layers'][$layer]['oracle_failure'] );
			} else {
				self::assertIsString( $row['layers'][$layer]['oracle_failure'] );
				self::assertStringContainsString( 'Noncanonical error envelope:', $row['layers'][$layer]['oracle_failure'] );
			}
		} elseif ( 'hook' === $fault ) {
			self::assertSame( $row['before'], $row['after'] );
			self::assertSame( array( 'save_post' => 1 ), $row['hooks'] );
		} elseif ( 'sentinel-read' === $fault ) {
			self::assertSame( $row['before'], $row['after'] );
			self::assertSame( array(), $row['hooks'] );
		} else {
			self::assertNotSame( $row['before'], $row['after'] );
		}
		self::assertSame( 1906545600, $row['stored']['cron_after_early_guard'] );
	}
}
