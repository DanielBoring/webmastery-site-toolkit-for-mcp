<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/input-schema-report.php';

final class InputSchemaProofTest extends TestCase {
	private function report( string $boundary = 'ability' ): array {
		return wstm126_fake_report( str_repeat( 'a', 32 ), str_repeat( 'b', 40 ), 'schema-proof-test', $boundary );
	}

	private function check( array $report, string $boundary = 'ability' ): void {
		wstm126_validate_invocation( $report, str_repeat( 'a', 32 ), str_repeat( 'b', 40 ), 'schema-proof-test', $boundary );
	}

	public function test_all_five_boundaries_and_failed_cases_with_independent_cleanup(): void {
		self::assertCount( 152, wstm126_expected_labels() );
		self::assertCount( 152, array_unique( wstm126_expected_labels() ) );
		foreach ( array( 'direct', 'permission', 'ability', 'http', 'individual' ) as $boundary ) {
			$report = $this->report( $boundary );
			$this->check( $report, $boundary );
			$report['cases'][0]['passed'] = false;
			$report['cases'][0]['error'] = 'A test failure still fails the runner, but independently safe cleanup can retire retention.';
			$report['passed']--;
			$report['failed']++;
			$this->check( $report, $boundary );
			self::assertSame( 1, $report['failed'] );
		}
	}

	public static function mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array(
			'owner', 'source', 'project', 'boundary', 'version', 'completed', 'cleanup-complete', 'cleanup-empty', 'cleanup-string',
			'proof-missing', 'proof-false', 'proof-extra', 'missing-case', 'extra-case', 'duplicate-case', 'case-order',
			'case-passed-type', 'totals', 'query', 'capability', 'state', 'hook', 'native-callback', 'reason', 'code',
			'denial', 'positive-write', 'hash', 'hash-group', 'fatal', 'failed-without-evidence',
		) );
	}

	/** @dataProvider mutations */
	public function test_partial_foreign_or_weakened_proof_never_retires_retention( string $mutation ): void {
		$report = $this->report();
		switch ( $mutation ) {
			case 'owner': $report['owner'] = str_repeat( 'c', 32 ); break;
			case 'source': $report['source_sha'] = str_repeat( 'c', 40 ); break;
			case 'project': $report['project'] = 'foreign'; break;
			case 'boundary': $report['boundary'] = 'http'; break;
			case 'version': $report['schema_version'] = '1'; break;
			case 'completed': $report['completed'] = false; break;
			case 'cleanup-complete': $report['cleanup_complete'] = false; break;
			case 'cleanup-empty': $report['cleanup'] = array(); break;
			case 'cleanup-string': $report['cleanup'] = array( 'post' => 'passed' ); break;
			case 'proof-missing': unset( $report['cleanup_proof']['actors_absent'] ); break;
			case 'proof-false': $report['cleanup_proof']['actors_absent'] = false; break;
			case 'proof-extra': $report['cleanup_proof']['extra'] = true; break;
			case 'missing-case': array_pop( $report['cases'] ); break;
			case 'extra-case': $report['cases'][] = $report['cases'][0]; break;
			case 'duplicate-case': $report['cases'][1] = $report['cases'][0]; break;
			case 'case-order': [ $report['cases'][1], $report['cases'][0] ] = array( $report['cases'][0], $report['cases'][1] ); break;
			case 'case-passed-type': $report['cases'][0]['passed'] = 1; break;
			case 'totals': $report['passed'] = 151; break;
			case 'query': $report['cases'][0]['evidence']['callbacks'][] = array( 'ability' => 'probe', 'stage' => 'permission_callback', 'queries' => 1, 'capabilities' => 0 ); break;
			case 'capability': $report['cases'][149]['evidence']['callbacks'][0]['capabilities'] = 0; break;
			case 'state': $report['cases'][0]['after']['new'] = true; break;
			case 'hook': $report['cases'][0]['evidence']['mutations'][] = 'rolled-back-write'; break;
			case 'native-callback': $report['cases'][0]['evidence']['callbacks'][] = array( 'ability' => 'probe', 'stage' => 'permission_callback', 'queries' => 0, 'capabilities' => 0 ); break;
			case 'reason': $report['cases'][0]['result']['error']['reason'] = 'ability_invalid_permissions'; break;
			case 'code': $report['cases'][0]['result']['error']['code'] = 'forbidden'; break;
			case 'denial': $report['cases'][149]['result']['error']['code'] = 'invalid_input'; break;
			case 'positive-write': $report['cases'][150]['after'] = $report['cases'][150]['before']; break;
			case 'hash': unset( $report['hashes']['class-ability.php'] ); break;
			case 'hash-group': $report['source_hashes']['production'] = array(); break;
			case 'fatal': $report['fatal'] = 'Setup failed'; break;
			case 'failed-without-evidence': $report['cases'][0]['passed'] = false; break;
		}
		$this->expectException( RuntimeException::class );
		$this->check( $report );
	}

	public function test_native_zero_is_not_a_permitted_http_fallback(): void {
		$report = $this->report( 'http' );
		$report['cases'][0]['evidence']['callbacks'] = array();
		$this->expectException( RuntimeException::class );
		$this->check( $report, 'http' );
	}

	public function test_hashes_separate_actual_package_production_from_readonly_harness(): void {
		$root = dirname( __DIR__, 2 );
		$hashes = wstm126_source_hashes( $root );
		self::assertSame( hash_file( 'sha256', $root . '/includes/class-ability.php' ), $hashes['production']['includes/class-ability.php'] );
		self::assertArrayHasKey( 'tests/e2e/input-schema-runner.php', $hashes['harness'] );
		$this->expectException( RuntimeException::class );
		wstm126_source_hashes( $root . '/nonexistent-package', $root );
	}
}
