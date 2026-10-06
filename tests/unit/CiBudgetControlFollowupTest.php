<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/ci-diagnostic-transition.php';
require_once __DIR__ . '/fixtures/scoped-host-observation-transition.php';

final class CiBudgetControlFollowupTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_unit_budget_changes_only_the_measured_limit_and_skips_no_controls(): void {
		$path = '.github/workflows/unit-tests.yml';
		$current = $this->read( $path );
		self::assertSame( 1, substr_count( $current, '    timeout-minutes: 60' ) );
		self::assertSame( str_replace( '    timeout-minutes: 60', '    timeout-minutes: 15', $current ), Wstm167CiBudgetControlTransition::restore( $path, $current ) );
		self::assertStringContainsString( "php: ['8.0', '8.4']", $current );
		self::assertStringContainsString( 'fail-fast: false', $current );
		self::assertLessThanOrEqual( 60 * 60, ( 2198 + 144 ) * 1.5 );
		self::assertStringContainsString( 'composer qa:unit', $current );
		self::assertStringContainsString( 'composer test:ci-safeguards', $current );
		$composer = $this->read( 'composer.json' );
		self::assertStringContainsString( 'test_untrusted_query.py', $composer );
		self::assertStringContainsString( 'bash scripts/test-release-runtime.sh', $composer );
		self::assertStringContainsString( 'bash tests/untrusted-stage-test.sh', $composer );
	}

	public function test_candidate_diagnostic_uses_approved_tools_without_changing_candidate_qa(): void {
		$path = '.github/workflows/compatibility-qa.yml';
		$current = $this->read( $path );
		$override = '        working-directory: ${{ github.workspace }}/candidate-floor-tools' . "\n";
		self::assertSame( 1, substr_count( $current, $override ) );
		self::assertSame( 4, substr_count( $current, "          WSTM108_HOST_INSPECTION: system-readonly-v1\n" ) );
		self::assertSame( 4, substr_count( $current, 'WSTM108_HOST_INSPECTION:' ) );
		$before_observation = Wstm167ScopedHostObservationTransition::restore( $path, $current );
		self::assertSame( str_replace( $override, '', $before_observation ), Wstm167CiBudgetControlTransition::restore( $path, $current ) );
		self::assertSame( 1, preg_match( '/^  candidate-floor:\n(.*?)(?=^  [a-z-]+:|\z)/ms', $current, $job ) );
		self::assertStringContainsString( "        working-directory: candidate\n", $job[1] );
		self::assertStringContainsString( 'ref: ${{ github.workflow_sha }}' . "\n          path: candidate-floor-tools", $job[1] );
		self::assertStringContainsString( 'ref: ${{ inputs.candidate_sha }}' . "\n          path: candidate", $job[1] );
		self::assertSame( 1, preg_match( '/      - name: Show closed admission refusal diagnostic \(not acceptance\)\n(.*?)(?=      - name:)/s', $job[1], $diagnostic ) );
		self::assertSame( $override . "        if: \${{ always() }}\n        continue-on-error: true\n        env:\n"
			. '          WSTM108_ADMISSION_FAILURE: ${{ steps.qa.outputs.untrusted_admission_failure }}' . "\n"
			. '          WSTM108_ADMISSION_CALLSITE_V1: ${{ steps.qa.outputs.untrusted_admission_callsite_v1 }}' . "\n"
			. "        run: php -d display_errors=0 -d log_errors=0 scripts/untrusted-admission-diagnostic.php\n", $diagnostic[1] );
		self::assertSame( 1, preg_match( '/      - name: Run unchanged full candidate E2E harness\n(.*?)(?=      - name:)/s', $job[1], $qa ) );
		self::assertStringNotContainsString( 'working-directory:', $qa[1] );
		self::assertStringContainsString( 'run: bash scripts/e2e-test.sh all', $qa[1] );
		self::assertStringNotContainsString( 'candidate-floor-tools', $qa[1] );
		self::assertStringNotContainsString( 'untrusted_admission_failure', $qa[1] );
		self::assertStringNotContainsString( 'untrusted_admission_callsite_v1', $qa[1] );
		self::assertSame( 4, substr_count( $current, 'scripts/untrusted-admission-diagnostic.php' ) );
	}

	public function test_exact_outer_restoration_keeps_accepted_seals_and_parent_consumer_fix(): void {
		$map = Wstm167CiBudgetControlTransition::load();
		self::assertSame( '32387be002a5aff0e256d9570d17817fb12e55bb', $map['base_commit'] );
		self::assertSame( array( '.github/REPOSITORY_CHANGELOG.md', '.github/workflows/compatibility-qa.yml', '.github/workflows/unit-tests.yml',
			'docs/ci-cd-strategy.md', 'tests/unit/CiAdmissionDiagnosticTest.php', 'tests/unit/fixtures/ci-diagnostic-transition.php' ), array_keys( $map['files'] ) );
		self::assertSame( 'a1e215abbc4e0f8ae3bf47a7becda2ab474740a8b8192ca0223afc668aeae201', Wstm167CiDiagnosticTransition::SEAL );
		self::assertSame( Wstm167CiDiagnosticTransition::SEAL, hash( 'sha256', $this->read( 'tests/unit/fixtures/ci-diagnostic-transition.json' ) ) );
		Wstm167CiBudgetControlTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		Wstm167CiDiagnosticTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['files'] as $path => $binding ) {
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', Wstm167CiBudgetControlTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		self::assertStringContainsString( '$workflow = Wstm167CiDiagnosticTransition::restore(', $this->read( 'tests/unit/CompatibilityBaselinesTest.php' ) );
	}

	public function test_old_raw_foreign_and_missing_sources_cannot_bypass_the_outer_layer(): void {
		$map = Wstm167CiBudgetControlTransition::load();
		foreach ( $map['files'] as $path => $binding ) {
			$current = $this->read( $path );
			$before = Wstm167CiBudgetControlTransition::restore( $path, $current );
			foreach ( array( $before, $current . "\nforeign", str_replace( "\n", "\r\n", $current ) ) as $foreign ) {
				try {
					Wstm167CiDiagnosticTransition::restore( $path, $foreign );
					self::fail( 'Old or foreign bytes accepted as a fallback.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
		foreach ( array_merge( array_keys( $map['files'] ), array_keys( $map['dependencies'] ) ) as $path ) {
			foreach ( array( false, '', $this->read( $path ) . "\nforeign" ) as $foreign ) {
				try {
					Wstm167CiDiagnosticTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Missing or foreign dependency accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() );
				}
			}
		}
	}

	public function test_forged_or_omitted_ledger_cannot_replace_any_accepted_seal(): void {
		$json = $this->read( 'tests/unit/fixtures/ci-budget-control-transition.json' );
		$map = Wstm167CiBudgetControlTransition::load();
		$forgeries = array( '', $json . "\n" );
		foreach ( array_keys( $map ) as $key ) {
			$foreign = $map;
			unset( $foreign[ $key ] );
			$forgeries[] = json_encode( $foreign, JSON_THROW_ON_ERROR );
		}
		foreach ( array( 'hunks', 'start', 'before', 'after', 'baseline_raw_sha256', 'current_raw_sha256' ) as $key ) {
			$forgeries[] = str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json );
		}
		foreach ( $forgeries as $foreign ) {
			try {
				Wstm167CiBudgetControlTransition::load( $foreign );
				self::fail( 'Forged outer correction accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'CI budget/control transition seal mismatch.', $error->getMessage() );
			}
		}
	}
}
