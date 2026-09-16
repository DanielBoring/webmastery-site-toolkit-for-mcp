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

	public function test_normal_pr_ci_executes_release_and_compatibility_regressions(): void {
		$root = dirname(__DIR__, 2);
		$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(
			array('@test:release-safeguards', 'bash tests/compatibility-download-test.sh', 'bash tests/compatibility-dependency-policy-test.sh'),
			$composer['scripts']['test:ci-safeguards']
		);
		self::assertSame(
			array('php scripts/test-release-safeguards.php', 'bash scripts/test-release-tag-check.sh', 'bash scripts/test-release-plugin-check.sh'),
			$composer['scripts']['test:release-safeguards']
		);
		$workflow = file_get_contents($root . '/.github/workflows/unit-tests.yml');
		self::assertStringContainsString('run: composer test:ci-safeguards', $workflow);
		self::assertStringContainsString('extensions: zip', $workflow);
	}

	public function test_compatibility_promotion_only_blocks_open_pull_requests(): void {
		$workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/compatibility-qa.yml');
		self::assertIsString($workflow);
		self::assertStringContainsString('gh pr list --state open', $workflow);
		self::assertStringNotContainsString('gh pr list --state all', $workflow);
	}
}
