<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/docker-qa-gate.php';
require_once dirname(__DIR__, 2) . '/scripts/docker-qa-summary.php';

final class GithubSafeguardsTest extends TestCase {
	public function test_gate_fails_closed_for_every_result_combination(): void {
		$results = array('success', 'failure', 'cancelled', 'skipped', '', 'unknown');
		foreach ($results as $detector) {
			foreach (array('true', 'false', '', 'invalid', 'TRUE') as $should_run) {
				foreach ($results as $contract) {
					foreach ($results as $transport) {
						$expected = 'success' === $detector && (
							('true' === $should_run && 'success' === $contract && 'success' === $transport) ||
							('false' === $should_run && 'skipped' === $contract && 'skipped' === $transport)
						);
						self::assertSame($expected, docker_qa_gate_passes($detector, $should_run, $contract, $transport));
					}
				}
			}
		}
	}

	public function test_summaries_only_publish_nonnegative_integer_counts(): void {
		self::assertSame(
			array('passed' => 3, 'failed' => 0, 'summary_available' => true),
			docker_qa_safe_summary(array(
				'passed' => 3,
				'failed' => 0,
				'cases' => array(array('message' => 'secret')),
				'endpoint' => 'https://user:password@example.org',
				'test_cases' => 'secret',
				'negative_cases' => -1,
			))
		);
		self::assertSame(array('summary_available' => false), docker_qa_safe_summary(array()));
	}

	public function test_workflow_gate_is_unconditional_and_uses_all_results(): void {
		$workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/e2e-qa.yml');
		self::assertIsString($workflow);
		self::assertMatchesRegularExpression('/docker-qa-gate:\s+name: Docker QA gate\s+needs: \[changes, ability-contract-qa, full-mcp-e2e-qa\]\s+if: \$\{\{ always\(\) \}\}/', $workflow);
		foreach (array('needs.changes.result', 'needs.changes.outputs.should-run', 'needs.ability-contract-qa.result', 'needs.full-mcp-e2e-qa.result') as $input) {
			self::assertStringContainsString($input, $workflow);
		}
		self::assertStringContainsString('php scripts/docker-qa-gate.php "$DETECTOR" "$SHOULD_RUN" "$CONTRACT" "$TRANSPORT"', $workflow);
	}

	public function test_workflow_lint_is_opt_in_and_versions_match_ci(): void {
		$root = dirname(__DIR__, 2);
		$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('php scripts/workflow-lint.php', $composer['scripts']['lint:workflows']);
		foreach (array('qa', 'qa:static', 'qa:unit') as $script) {
			self::assertStringNotContainsString('lint:workflows', json_encode($composer['scripts'][$script]));
		}
		$helper = file_get_contents($root . '/scripts/workflow-lint.php');
		$workflow = file_get_contents($root . '/.github/workflows/workflow-lint.yml');
		foreach (array('1.7.12', '0.11.0', '1.30.1') as $version) {
			self::assertStringContainsString($version, $helper);
			self::assertStringContainsString('/v' . $version . '/', $workflow);
		}
		self::assertSame(3, substr_count($workflow, 'sha256sum --check --strict'));
		self::assertStringContainsString('zizmor --offline', $helper);
	}

	public function test_normal_pr_ci_executes_static_release_and_compatibility_regressions(): void {
		$root = dirname(__DIR__, 2);
		$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(
			array('python3 -B -m unittest discover -s tests/unit -p test_rescoped_workflow_gates.py -v', 'python3 -B -m unittest discover -s tests/unit -p test_catalog_ordering.py -v', 'bash tests/qa-compose-safety-test.sh', 'bash tests/legacy-artifact-stage-test.sh', 'bash tests/owned-bootstrap-origin-test.sh', '@test:phpstan-baseline', '@test:release-safeguards', 'bash tests/destructive-stage-test.sh', 'bash tests/input-schema-stage-test.sh', 'bash tests/compatibility-download-test.sh', 'bash tests/compatibility-dependency-policy-test.sh'),
			$composer['scripts']['test:ci-safeguards']
		);
		self::assertSame('php scripts/test-phpstan-baseline.php', $composer['scripts']['test:phpstan-baseline']);
		self::assertContains('@test:phpstan-baseline', $composer['scripts']['qa:static']);
		self::assertSame(
			array('php scripts/test-release-safeguards.php', 'bash scripts/test-release-tag-check.sh', 'bash scripts/test-release-plugin-check.sh', 'bash scripts/test-release-runtime.sh', 'php scripts/test-release-recovery.php', 'bash scripts/test-release-control-check.sh', 'bash scripts/test-release-publish-prerequisites.sh'),
			$composer['scripts']['test:release-safeguards']
		);
		$workflow = file_get_contents($root . '/.github/workflows/unit-tests.yml');
		self::assertStringContainsString('run: composer test:ci-safeguards', $workflow);
		self::assertStringContainsString('extensions: zip', $workflow);
		foreach (array('release.yml', 'release-package-qa.yml') as $file) {
			$release_workflow = file_get_contents($root . '/.github/workflows/' . $file);
			self::assertStringContainsString('bash scripts/test-release-runtime.sh', $release_workflow);
			self::assertStringContainsString('php scripts/test-release-recovery.php', $release_workflow);
			self::assertStringContainsString('bash scripts/test-release-control-check.sh', $release_workflow);
			self::assertStringContainsString('bash scripts/test-release-publish-prerequisites.sh', $release_workflow);
		}
	}

	public function test_compatibility_promotion_only_blocks_open_pull_requests(): void {
		$workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/compatibility-qa.yml');
		self::assertIsString($workflow);
		self::assertStringContainsString('gh pr list --state open', $workflow);
		self::assertStringNotContainsString('gh pr list --state all', $workflow);
	}

	public function test_schema_outer_faults_and_red_controls_use_fresh_private_case_roots(): void {
		$root = dirname(__DIR__, 2);
		$source = str_replace("\r\n", "\n", file_get_contents($root . '/scripts/test-release-runtime.sh'));
		self::assertStringContainsString('[[ ! -e "$WORK" && ! -L "$WORK" ]]', $source);
		self::assertStringContainsString('cp -R scripts includes tests "$WORK/source with spaces/"', $source);
		self::assertStringContainsString('cd "$WORK/source with spaces"', $source);
		self::assertStringContainsString('export WSTM_QA_STATE_ROOT="$WORK/private-state"', $source);
		self::assertSame(1, preg_match('/^fresh_case\(\) \{\n([\s\S]*?)^\}/m', $source, $fresh));
		foreach (array(
			'rm -rf e2e-artifacts "$WORK/container"',
			'mkdir -m 0700 "$WORK/container"',
			'rm -f build/wstm116-retention-release-runtime-fixture',
			'"$WORK/schema-journal"', '"$WORK/schema-probe"',
			'"$WORK/schema-post"', '"$WORK/schema-actor"', '"$WORK/schema-credential"',
			': > "$TRACE"',
		) as $control) {
			self::assertStringContainsString($control, $fresh[1]);
		}
		$header = "for outer in package source; do\n\tfor failure in restoration finalize cleanup combined ordinary; do\n";
		self::assertSame(1, preg_match('/' . preg_quote($header, '/') . '([\s\S]*?)\ndone/', $source, $schema));
		self::assertStringContainsString("\t\tfresh_case\n\t\tstatus=0\n", $schema[1]);
		self::assertStringContainsString('outer_qa "$outer"', $schema[1]);
		self::assertStringContainsString('assert_reentry_refused', $schema[1]);
		self::assertStringContainsString('restoration) expected=57 ;; finalize) expected=58 ;; cleanup) expected=54 ;; combined|ordinary) expected=53', $source);
		foreach (array('test -f build/wstm116-retention-release-runtime-fixture', 'schema-owner-journal', 'schema-owned-probe', 'retained-owned-post', 'retained-owned-actor', 'retained-owned-credential') as $control) {
			self::assertStringContainsString($control, $schema[1]);
		}
	}

	public function test_schema_red_controls_restore_only_the_copied_wrappers(): void {
		$source = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__, 2) . '/scripts/test-release-runtime.sh'));
		self::assertSame(1, preg_match('/# These negative controls mutate ONLY the copied orchestration scripts\.\n([\s\S]*?)# Newly isolated product boundary/', $source, $matches));
		$section = $matches[1];
		foreach (array(
			'for stage in destructive schema; do', 'for outer in package source; do',
			'fresh_case', 'script=scripts/release-qa.sh', 'script=scripts/e2e-test.sh',
			'cp "$script" "$WORK/fixed-$stage-$outer.sh"',
			'sed \'s/if ! wstm116_require_no_retention; then/if false; then/\'',
			'export FAIL_SCHEMA_RESTORE=1 FAIL_SCHEMA_RUNNER=1',
			'outer_qa "$outer"', '[[ "$stage" != schema ]] || expected=53',
			'[[ "$status" == "$expected" &&', '== 2 ]]',
			'test ! -e "$WORK/schema-journal"', 'test ! -e "$WORK/schema-probe"',
			'test -f build/wstm116-retention-release-runtime-fixture',
			'cp "$WORK/fixed-$stage-$outer.sh" "$script"',
		) as $assertion) {
			self::assertStringContainsString($assertion, $section);
		}
		self::assertLessThan(strpos($section, 'status=0'), strpos($section, 'sed \'s/if !'));
		self::assertLessThan(strpos($section, 'cp "$WORK/fixed-$stage-$outer.sh" "$script"'), strpos($section, 'test -f build/wstm116-retention-release-runtime-fixture'));
		self::assertStringNotContainsString('git ', $section);
	}

	public function test_runtime_mock_binding_is_scoped_without_source_or_git_mutation(): void {
		$root = dirname(__DIR__, 2);
		$source = str_replace("\r\n", "\n", file_get_contents($root . '/scripts/test-release-runtime.sh'));
		self::assertSame(1, preg_match('/^git\(\) \{\n([\s\S]*?)^\}/m', $source, $matches));
		foreach (array(
			'[[ "$*" == "rev-parse HEAD" ]]',
			'Unexpected mock Git operation.', 'return 95',
		) as $control) {
			self::assertStringContainsString($control, $matches[1]);
		}
		self::assertStringNotContainsString('git commit', $source);
		self::assertStringNotContainsString('commit_mock_outer', $source);
		self::assertStringContainsString('before its main', file_get_contents($root . '/tests/unit/fixtures/release-runtime-product.sh'));
		$boundary = file_get_contents($root . '/tests/unit/fixtures/release-runtime-docker.sh');
		foreach (array(
			'compose --project-name release-runtime-fixture -f docker-compose.yml',
			'Unexpected Compose project or internal file selection.',
			'Release runtime did not select fresh, separate package roots.',
			'Candidate execution did not use fixed UID/GID 33.',
			'WP-CLI did not use the fixed private cache, phar and local URL.',
		) as $control) {
			self::assertStringContainsString($control, $boundary);
		}
	}

	public function test_unit_and_safeguard_jobs_are_independent_bounded_and_required(): void {
		$workflow = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/unit-tests.yml'));
		foreach (array('unit-tests' => 'composer qa:unit', 'ci-safeguards' => 'composer test:ci-safeguards') as $job => $command) {
			self::assertSame(1, preg_match('/^  ' . preg_quote($job, '/') . ':\n([\s\S]*?)(?=^  [a-z][a-z-]*:|\z)/m', $workflow, $section));
			foreach (array('timeout-minutes: 60', 'fail-fast: false', "php: ['8.0', '8.4']", "COMPOSER_PROCESS_TIMEOUT: '0'", 'run: ' . $command) as $control) {
				self::assertStringContainsString($control, $section[1]);
			}
			self::assertDoesNotMatchRegularExpression('/^    (if|needs):/m', $section[1]);
		}
		self::assertMatchesRegularExpression('/unit-tests-gate:\s+name: 2 - Unit Tests\s+needs: \[unit-tests, ci-safeguards\]\s+if: \$\{\{ always\(\) \}\}/', $workflow);
		self::assertStringContainsString('test "$UNIT_RESULT" = success && test "$SAFEGUARD_RESULT" = success', $workflow);
		self::assertStringNotContainsString('continue-on-error', $workflow);
		self::assertStringNotContainsString('controller-components', $workflow);
		self::assertStringNotContainsString('bounded-list-controller.py ', $workflow);
	}

	public function test_release_compose_only_adds_explicit_harness_mounts(): void {
		$root = dirname(__DIR__, 2);
		$compose = file_get_contents($root . '/docker-compose.release.yml');
		preg_match_all('/^\s+source: ([^\r\n]+)\r?$/m', $compose, $sources);
		self::assertSame(
			array(
				'${E2E_CHECKER_ROOT:?Package runtime requires the pristine checker extraction}',
				'${E2E_PACKAGE_ROOT:?Package runtime requires an extracted plugin root}',
				'./tests',
				'./scripts/compatibility-baselines.php',
				'./scripts/compatibility-download.sh',
				'./.github/compatibility-versions.json',
			),
			$sources[1]
		);
		$plugin = '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp';
		preg_match_all('/^\s+target: ([^\r\n]+)\r?$/m', $compose, $targets);
		self::assertSame(
			array('/qa-package-pristine', $plugin, $plugin . '/tests', $plugin . '/scripts/compatibility-baselines.php', $plugin . '/scripts/compatibility-download.sh', $plugin . '/.github/compatibility-versions.json'),
			$targets[1]
		);
		self::assertSame(6, substr_count($compose, 'create_host_path: false'));
		self::assertSame(6, substr_count($compose, 'read_only: true'));
		self::assertStringNotContainsString('e2e-artifacts', $compose);
		$base = str_replace("\r\n", "\n", file_get_contents($root . '/docker-compose.yml'));
		self::assertStringContainsString("source: ./\n        target: " . $plugin . "\n        read_only: true", $base);
		self::assertStringContainsString('- qa_artifacts:/qa-artifacts', $base);
		self::assertStringNotContainsString('./e2e-artifacts', $base);
		$workflow = file_get_contents($root . '/.github/workflows/release-package-qa.yml');
		self::assertStringContainsString("'docker-compose.release.yml'", $workflow);
		self::assertStringContainsString("'scripts/qa-compose.sh'", $workflow);
	}
}
