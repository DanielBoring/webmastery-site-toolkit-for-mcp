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
			array('@test:phpstan-baseline', '@test:release-safeguards', 'bash tests/destructive-stage-test.sh', 'bash tests/input-schema-stage-test.sh', 'bash tests/untrusted-stage-test.sh', 'bash tests/compatibility-download-test.sh', 'bash tests/compatibility-dependency-policy-test.sh'),
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
		$sections = array(
			'# Additive schema-stage failures use the same real outer source/package wrappers.' => array(
				'export WSTM108_MOCK_LIVE="$WORK/schema-retention-$outer-$failure"',
				'for failure in restoration finalize cleanup combined ordinary; do',
			),
			'# Negative control: restoring the old checkout-mode selection must fail this test.' => array(
				'export WSTM108_MOCK_LIVE="$WORK/legacy-schema-retention-$outer"',
				'export FAIL_SCHEMA_RESTORE=1 FAIL_SCHEMA_RUNNER=1',
			),
		);
		foreach ($sections as $marker => $expected) {
			self::assertSame(1, substr_count($source, $marker));
			$section = substr($source, strpos($source, $marker));
			$end = strpos($section, "status=0\n");
			self::assertNotFalse($end);
			$preamble = substr($section, 0, $end);
			self::assertStringContainsString($expected[0] . "\n", $preamble);
			self::assertStringContainsString('mkdir -m 700 "$WSTM108_MOCK_LIVE"', $preamble);
			self::assertLessThan(strpos($preamble, 'mkdir -m 700'), strpos($preamble, $expected[0]));
			self::assertStringContainsString($expected[1], $section);
			self::assertStringNotContainsString('context-path.private', $preamble);
		}
		self::assertStringContainsString('restoration) expected=57 ;; finalize) expected=58 ;; cleanup) expected=54 ;; combined|ordinary) expected=53', $source);
		self::assertStringContainsString('[[ "$status" == 53 ]]', $source);
		$boundary = file_get_contents($root . '/tests/unit/fixtures/untrusted-docker.php');
		self::assertStringContainsString("\$create( 'context-path.private', \$context_path );", $boundary);
		self::assertStringContainsString("foreach ( array( 'private-state', 'private-journal', 'probe', 'original-wire.private', 'original-wire-metadata.private.json' )", $boundary);
	}

	public function test_schema_red_controls_bind_mutation_and_restoration_before_next_admission(): void {
		$source = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__, 2) . '/scripts/test-release-runtime.sh'));
		self::assertSame(1, preg_match('/for outer in package source; do\n\texport WSTM108_MOCK_LIVE="\$WORK\/legacy-schema-retention-\$outer"([\s\S]*?)\ndone/', $source, $matches));
		$section = $matches[1];
		self::assertSame(2, substr_count($section, 'commit_mock_outer "$script"'));
		self::assertStringContainsString('> "$script"' . "\n\t# Attest this deliberate RED mutation instead of failing before schema acquisition.\n\t" . 'commit_mock_outer "$script"', $section);
		self::assertLessThan(strpos($section, 'status=0'), strpos($section, 'commit_mock_outer "$script"'));
		self::assertStringContainsString('cp "$WORK/fixed-schema-retention-$outer.sh" "$script"' . "\n\t" . 'commit_mock_outer "$script"' . "\n\t" . 'rm build/wstm116-retention-release-runtime-fixture', $section);
		foreach (array('[[ "$status" == 53 ]]', '== 2 ]]', 'test ! -e "$WORK/schema-journal"', 'test ! -e "$WORK/schema-probe"', 'test -f build/wstm116-retention-release-runtime-fixture') as $assertion) {
			self::assertStringContainsString($assertion, $section);
		}
	}

	public function test_red_wrapper_binding_is_scoped_and_does_not_relax_provenance(): void {
		$root = dirname(__DIR__, 2);
		$source = str_replace("\r\n", "\n", file_get_contents($root . '/scripts/test-release-runtime.sh'));
		self::assertSame(1, preg_match('/^commit_mock_outer\(\) \{\n([\s\S]*?)^\}/m', $source, $matches));
		foreach (array(
			'"${WSTM108_MOCK_ONLY:-0}" != 1',
			'"$PWD" != "$WORK/source with spaces"',
			'"$PWD" != "$WSTM108_MOCK_CHECKOUT"',
			'scripts/release-qa.sh|scripts/e2e-test.sh)',
			'return 78',
			'core.autocrlf=false commit --quiet --only',
			'-- "$1"',
		) as $control) {
			self::assertStringContainsString($control, $matches[1]);
		}
		self::assertSame(1, substr_count($matches[1], "\n\tgit "));
		self::assertStringNotContainsString('||', substr($matches[1], strpos($matches[1], "\n\tgit ")));
		$provenance = file_get_contents($root . '/scripts/untrusted-provenance.php');
		self::assertStringContainsString("'diff', '--quiet', 'HEAD', '--'", $provenance);
		foreach (array("'scripts/e2e-test.sh'", "'scripts/release-qa.sh'") as $file) {
			self::assertStringContainsString($file, $provenance);
		}
	}

	public function test_controller_component_job_is_explicit_bounded_and_required(): void {
		$workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/unit-tests.yml');
		self::assertDoesNotMatchRegularExpression('/^      COMPONENT_ROOT:/m', $workflow);
		$expected_root = '${{ runner.temp }}/wstm-controller-${{ github.run_id }}-${{ github.run_attempt }}';
		foreach (array(
			'Run exactly ten synthetic controller components',
			'Retain scoped synthetic originals and symlink metadata',
			'Upload synthetic component evidence only',
		) as $name) {
			self::assertSame(1, preg_match('/^      - name: ' . preg_quote($name, '/') . '\r?\n([\s\S]*?)(?=^      - name:|^  [a-z][a-z-]*:|\z)/m', $workflow, $step_matches));
			self::assertSame(1, preg_match('/^        env:\r?\n          COMPONENT_ROOT: ([^\r\n]+)\r?$/m', $step_matches[1], $root_matches));
			self::assertSame($expected_root, $root_matches[1], $name . ' must resolve the same private absolute root at step scope.');
		}
		self::assertMatchesRegularExpression('/controller-components:\s+name: Synthetic controller components \/ Linux\s+runs-on: ubuntu-24\.04\s+timeout-minutes: 10/', $workflow);
		self::assertMatchesRegularExpression('/unit-tests-gate:\s+name: 2 - Unit Tests\s+needs: \[unit-tests, controller-components\]\s+if: \$\{\{ always\(\) \}\}/', $workflow);
		self::assertStringContainsString('needs.controller-components.result', $workflow);
		self::assertStringContainsString('test "$CONTROLLER_RESULT" = success', $workflow);
		self::assertStringContainsString('python3 -B scripts/test-controller-components.py run --root "$COMPONENT_ROOT"', $workflow);
		self::assertStringContainsString('python3 -B -m unittest discover -s tests/unit -p test_controller_ci_harness.py -v', $workflow);
		self::assertMatchesRegularExpression('/name: Retain scoped synthetic originals and symlink metadata\s+if: \$\{\{ always\(\) \}\}\s+run: python3 -B scripts\/test-controller-components.py retain --root "\$COMPONENT_ROOT"/', $workflow);
		self::assertMatchesRegularExpression('/name: Upload synthetic component evidence only\s+if: \$\{\{ always\(\) \}\}[\s\S]*?path: \$\{\{ env.COMPONENT_ROOT \}\}\/upload\/\s+if-no-files-found: error\s+retention-days: 7/', $workflow);
		self::assertStringNotContainsString('continue-on-error', $workflow);
		self::assertStringNotContainsString('bounded-list-controller.py ', $workflow, 'The benchmark entrypoint must not run in ordinary unit CI.');
	}

	public function test_release_compose_only_adds_explicit_harness_mounts(): void {
		$root = dirname(__DIR__, 2);
		$compose = file_get_contents($root . '/docker-compose.release.yml');
		preg_match_all('/^\s+source: ([^\r\n]+)\r?$/m', $compose, $sources);
		self::assertSame(
			array(
				'${E2E_PACKAGE_ROOT:?Package runtime requires an extracted plugin root}',
				'./tests',
				'./scripts/compatibility-baselines.php',
				'./scripts/compatibility-download.sh',
				'./.github/compatibility-versions.json',
				'./e2e-artifacts',
			),
			$sources[1]
		);
		$plugin = '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp';
		preg_match_all('/^\s+target: ([^\r\n]+)\r?$/m', $compose, $targets);
		self::assertSame(
			array($plugin, $plugin . '/tests', $plugin . '/scripts/compatibility-baselines.php', $plugin . '/scripts/compatibility-download.sh', $plugin . '/.github/compatibility-versions.json', $plugin . '/e2e-artifacts'),
			$targets[1]
		);
		self::assertSame(6, substr_count($compose, 'create_host_path: false'));
		self::assertSame(4, substr_count($compose, 'read_only: true'));
		self::assertStringContainsString('- ./:', file_get_contents($root . '/docker-compose.yml'));
		$workflow = file_get_contents($root . '/.github/workflows/release-package-qa.yml');
		self::assertStringContainsString("'docker-compose.release.yml'", $workflow);
		self::assertStringContainsString("'scripts/qa-compose.sh'", $workflow);
	}
}
