"""Focused required-job and retained-check regressions; no runtime orchestration."""

import itertools
import fnmatch
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / ".github" / "workflows"


def source(name):
    return (WORKFLOWS / name).read_text(encoding="utf-8")


def jobs(text):
    body = text.split("\njobs:\n", 1)[1]
    matches = list(re.finditer(r"^  ([a-z][a-z0-9-]*):\n", body, re.M))
    return {
        match[1]: body[match.end():matches[index + 1].start()
                       if index + 1 < len(matches) else len(body)]
        for index, match in enumerate(matches)
    }


def gate_command(text):
    match = re.search(r"^        run: (test .+)$", text, re.M)
    if match is None:
        raise AssertionError("Expected a single strict shell gate")
    return match[1]


def artifact_uploads(text):
    uploads = []
    for step in re.split(r"(?m)^      - name:", text):
        if "uses: actions/upload-artifact@" not in step:
            continue
        match = re.search(r"(?m)^          path: (.+)$", step)
        if match is None:
            raise AssertionError("Artifact upload must have an explicit path")
        if match[1].strip() == "|":
            block = re.match(r"(?:\n            [^\n]+)+", step[match.end():])
            if block is None:
                raise AssertionError("Artifact upload path list is empty")
            paths = block[0].split()
        else:
            paths = [match[1].strip()]
        uploads.append((step, paths))
    return uploads


class RescopedWorkflowGatesTest(unittest.TestCase):
    def test_local_bash_timeout_is_command_scoped_and_failure_preserved(self):
        bash_path = shutil.which("bash")
        self.assertIsNotNone(bash_path, "Bash is required for local wrapper regression")
        mock = r"""
composer() {
    printf 'mock-composer:%s:%s\n' "$1" "${COMPOSER_PROCESS_TIMEOUT-unset}"
    if [[ "$1" == qa ]]; then return "$MOCK_QA_STATUS"; fi
}
php() { printf '0123456789abcdef'; }
git() { return 0; }
docker() { return 0; }
bash() { printf 'mock-child:%s:%s\n' "$*" "${COMPOSER_PROCESS_TIMEOUT-unset}"; }
source scripts/qa-local.sh
printf 'mock-restored:%s\n' "${COMPOSER_PROCESS_TIMEOUT-unset}"
"""
        for prior, mode in itertools.product(
            (None, "", "217", "0"), ("success", "failure", "preflight")
        ):
            with self.subTest(prior=prior, mode=mode):
                env = os.environ.copy()
                env.pop("COMPOSER_PROCESS_TIMEOUT", None)
                if prior is not None:
                    env["COMPOSER_PROCESS_TIMEOUT"] = prior
                env["MOCK_QA_STATUS"] = "23" if mode == "failure" else "0"
                args = ["--contract", "--release"]
                if mode == "preflight":
                    args.append("--preflight-only")
                result = subprocess.run(
                    [bash_path, "--noprofile", "--norc", "-s", "--", *args],
                    input=mock.encode("utf-8"), cwd=ROOT, env=env,
                    capture_output=True, check=False,
                )
                result = subprocess.CompletedProcess(
                    result.args, result.returncode,
                    result.stdout.decode("utf-8"), result.stderr.decode("utf-8"),
                )
                self.assertEqual(23 if mode == "failure" else 0, result.returncode,
                                 result.stdout + result.stderr)
                if mode == "preflight":
                    self.assertNotIn("mock-composer:", result.stdout)
                    self.assertNotIn("mock-child:", result.stdout)
                    continue
                self.assertIn("mock-composer:qa:0\n", result.stdout)
                expected = prior if prior is not None else "unset"
                installs = [line for line in result.stdout.splitlines()
                            if line.startswith("mock-composer:install:")]
                self.assertTrue(all(line == "mock-composer:install:" + expected
                                    for line in installs))
                if mode == "failure":
                    self.assertNotIn("mock-child:", result.stdout)
                    self.assertNotIn("Local QA completed successfully.", result.stdout)
                else:
                    self.assertIn("mock-restored:" + expected + "\n", result.stdout)
                    children = [line for line in result.stdout.splitlines()
                                if line.startswith("mock-child:")]
                    self.assertEqual(2, len(children))
                    self.assertTrue(all(line.endswith(":" + expected) for line in children))

    def test_local_powershell_timeout_restored_on_success_and_failure(self):
        pwsh = shutil.which("pwsh")
        if pwsh is None:
            self.skipTest("PowerShell required for actual local PowerShell wrapper regression")
        wrapper = str(ROOT / "scripts" / "qa-local.ps1").replace("'", "''")
        mock = r"""
$global:QAObserved = [Collections.Generic.List[object]]::new()
function composer {
    $global:QAObserved.Add(@{command=$args[0]; timeout=$env:COMPOSER_PROCESS_TIMEOUT})
    $global:LASTEXITCODE = if ($args[0] -eq 'qa') { [int]$env:MOCK_QA_STATUS } else { 0 }
}
function php { $global:LASTEXITCODE = 0 }
function git { $global:LASTEXITCODE = 0 }
function docker { $global:LASTEXITCODE = 0 }
function bash {
    $global:QAObserved.Add(@{command='child'; timeout=$env:COMPOSER_PROCESS_TIMEOUT})
    $global:LASTEXITCODE = 0
}
$prior = [Environment]::GetEnvironmentVariable('COMPOSER_PROCESS_TIMEOUT', 'Process')
$failure = $null
try { & '__WRAPPER__' -Contract -Release } catch { $failure = $_.Exception.Message }
$result = @{
    prior=$prior
    restored=[Environment]::GetEnvironmentVariable('COMPOSER_PROCESS_TIMEOUT', 'Process')
    failure=$failure
    observed=@($global:QAObserved.ToArray())
}
Write-Output ('mock-result:' + (ConvertTo-Json -InputObject $result -Compress -Depth 4))
""".replace("__WRAPPER__", wrapper)
        for prior, status in itertools.product((None, "", "217", "0"), ("0", "23")):
            with self.subTest(prior=prior, status=status):
                env = os.environ.copy()
                env.pop("COMPOSER_PROCESS_TIMEOUT", None)
                if prior is not None:
                    env["COMPOSER_PROCESS_TIMEOUT"] = prior
                env["MOCK_QA_STATUS"] = status
                result = subprocess.run(
                    [pwsh, "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", mock],
                    cwd=ROOT, env=env, text=True, capture_output=True, check=False,
                )
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)
                records = [line[len("mock-result:"):]
                           for line in result.stdout.splitlines()
                           if line.startswith("mock-result:")]
                self.assertEqual(1, len(records), result.stdout + result.stderr)
                observed = json.loads(records[0])
                self.assertEqual(prior, observed["prior"])
                self.assertEqual(prior, observed["restored"])
                self.assertEqual([{"command": "qa", "timeout": "0"}],
                                 [call for call in observed["observed"]
                                  if call["command"] == "qa"])
                for call in observed["observed"]:
                    if call["command"] != "qa":
                        self.assertEqual(prior, call["timeout"])
                children = [call for call in observed["observed"]
                            if call["command"] == "child"]
                if status == "23":
                    self.assertIn("failed with exit code 23", observed["failure"])
                    self.assertEqual([], children)
                else:
                    self.assertIsNone(observed["failure"])
                    self.assertEqual(2, len(children))

    def test_composer_test_steps_defer_timeout_to_bounded_jobs(self):
        unit = jobs(source("unit-tests.yml"))
        for name, title, command in (
            ("unit-tests", "Run Unit Tests", "composer qa:unit"),
            ("ci-safeguards", "Run CI safeguards regression tests", "composer test:ci-safeguards"),
        ):
            with self.subTest(job=name):
                self.assertIn("    timeout-minutes: 60\n", unit[name])
                self.assertIn(
                    "      - name: " + title + "\n"
                    "        env:\n"
                    "          COMPOSER_PROCESS_TIMEOUT: '0'\n"
                    "        run: " + command + "\n",
                    unit[name],
                )
                self.assertEqual(1, unit[name].count("COMPOSER_PROCESS_TIMEOUT:"))

    def test_product_uploads_allow_only_closed_top_level_summary(self):
        safe = "e2e-artifacts/product-qa.summary.json"
        private = [
            "e2e-artifacts/product-deadbeef/" + name for name in (
                "private-error.log", "stdout.private.log", "stderr.private.log",
                "context.private.json", "wire.json", "sql.json", "resources.json",
                "untrusted.capture.private.bin", "bounded.capture.private.bin",
                "materialized/records.json", "nested/product-qa.summary.json",
            )
        ]
        for name, inherited, summaries in (
            ("e2e-qa.yml", 34, 2),
            ("release-package-qa.yml", 8, 1),
            ("compatibility-qa.yml", 9, 0),
        ):
            uploads = artifact_uploads(source(name))
            paths = [path for _, selected in uploads for path in selected]
            self.assertEqual(inherited + summaries, len(paths), name)
            self.assertEqual(summaries, paths.count(safe), name)
            for step, selected in uploads:
                if safe in selected:
                    self.assertEqual([safe], selected)
                    self.assertIn("        if: ${{ always() }}", step)
                    self.assertIn("          if-no-files-found: error", step)
                for path in selected:
                    pattern = path.rstrip("/") + ("/**" if path.endswith("/") else "")
                    for raw in private:
                        with self.subTest(workflow=name, path=path, private=raw):
                            self.assertFalse(fnmatch.fnmatchcase(raw, pattern))
            for job in jobs(source(name)).values():
                if "run: bash scripts/e2e-test.sh " in job or "run: bash scripts/release-qa.sh" in job:
                    if name != "compatibility-qa.yml":
                        self.assertEqual(1, job.count("          path: " + safe + "\n"))
            self.assertNotRegex(
                source(name), r"(?m)^\s*(?:run: )?(?:cat|tee|touch|cp|echo).+product-qa\.summary"
            )

    def test_runtime_mock_enforces_actual_qa_wp_interface(self):
        script = """
set -eu
export TRACE=/dev/null SOURCE_MODE=1
source <(sed 's/\\r$//' scripts/qa-compose.sh)
source <(sed 's/\\r$//' tests/unit/fixtures/release-runtime-docker.sh)
compose() {
    local command="$1"
    shift
    docker compose --project-name release-runtime-fixture -f docker-compose.yml "$command" --user 33:33 "$@"
}
qa_wp core version
qa_wp cli version
compose exec -T wordpress bash /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/scripts/compatibility-download.sh https://example.invalid/wp-cli.phar fixture-digest sha512 /var/www/html/.qa-bin/wp
refuse() {
    local status=0
    "$@" || status=$?
    test "$status" = 96
}
refuse compose exec -T wordpress php /var/www/html/.qa-bin/wp --url=http://localhost core version
refuse compose exec -T -e WP_CLI_CACHE_DIR=/wrong wordpress php /var/www/html/.qa-bin/wp --url=http://localhost core version
refuse compose exec -T -e WP_CLI_CACHE_DIR=/qa-artifacts/parent-cache/wp-cli wordpress php /var/www/html/.qa-bin/wp core version
refuse compose exec -T -e WP_CLI_CACHE_DIR=/qa-artifacts/parent-cache/wp-cli wordpress php /var/www/html/.qa-bin/wp --url=https://example.invalid core version
refuse compose exec -T wordpress wp --allow-root core version
"""
        result = subprocess.run(
            ["bash", "--noprofile", "--norc"], input=script.encode(),
            capture_output=True, cwd=ROOT, timeout=30,
            env={**os.environ, "BASH_ENV": os.devnull},
        )
        self.assertEqual(0, result.returncode, result.stderr.decode(errors="replace"))

    def test_independent_required_php_jobs(self):
        unit = jobs(source("unit-tests.yml"))
        self.assertEqual(
            {"unit-tests", "ci-safeguards", "unit-tests-gate"}, set(unit)
        )
        for name, command in (
            ("unit-tests", "composer qa:unit"),
            ("ci-safeguards", "composer test:ci-safeguards"),
        ):
            with self.subTest(job=name):
                job = unit[name]
                for required in (
                    "    timeout-minutes: 60\n",
                    "      fail-fast: false\n",
                    "        php: ['8.0', '8.4']\n",
                    "      contents: read\n",
                    "          php-version: ${{ matrix.php }}\n",
                    "          tools: composer:v2\n",
                    "        run: composer install --no-interaction --prefer-dist --no-progress\n",
                    "        run: " + command + "\n",
                    'test "$(php -r \'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;\')" = "$MATRIX_PHP"',
                ):
                    self.assertIn(required, job)
                self.assertNotRegex(job, re.compile(r"^    (if|needs):", re.M), name)
                self.assertNotIn("continue-on-error:", job)
                self.assertEqual(1, job.count("shivammathur/setup-php@"))
        self.assertNotIn("composer test:ci-safeguards", unit["unit-tests"])
        self.assertNotIn("composer qa:unit", unit["ci-safeguards"])
        gate = unit["unit-tests-gate"]
        self.assertIn("    name: 2 - Unit Tests\n", gate)
        self.assertIn("    needs: [unit-tests, ci-safeguards]\n", gate)
        self.assertIn("    if: ${{ always() }}\n", gate)
        self.assertIn("UNIT_RESULT: ${{ needs.unit-tests.result }}", gate)
        self.assertIn("SAFEGUARD_RESULT: ${{ needs.ci-safeguards.result }}", gate)
        self.assertNotIn("continue-on-error:", gate)

    def test_actual_aggregate_shell_rejects_failure_cancellation_skip_and_unknown(self):
        command = gate_command(jobs(source("unit-tests.yml"))["unit-tests-gate"])
        states = ("success", "failure", "cancelled", "skipped", "", "unknown")
        script = ["set -eu", "gate() { " + command + "; }"]
        for unit, safeguard in itertools.product(states, repeat=2):
            script.extend((
                "UNIT_RESULT=" + "'" + unit + "'",
                "SAFEGUARD_RESULT=" + "'" + safeguard + "'",
                "if gate; then observed=0; else observed=1; fi",
                "test \"$observed\" = " +
                ("0" if unit == safeguard == "success" else "1"),
            ))
        # Each PHP cell is an independent required execution; a bad cell makes
        # its matrix result non-success, including cancellation and skipping.
        for cells in itertools.product(states, repeat=4):
            unit = "success" if cells[0] == cells[1] == "success" else "failure"
            safeguard = "success" if cells[2] == cells[3] == "success" else "failure"
            script.extend((
                "UNIT_RESULT='" + unit + "'; SAFEGUARD_RESULT='" + safeguard + "'",
                "if gate; then observed=0; else observed=1; fi",
                "test \"$observed\" = " +
                ("0" if all(cell == "success" for cell in cells) else "1"),
            ))
        result = subprocess.run(
            ["bash", "--noprofile", "--norc"], input=("\n".join(script) + "\n").encode(),
            capture_output=True, cwd=ROOT, timeout=30,
            env={**os.environ, "BASH_ENV": os.devnull},
        )
        self.assertEqual(0, result.returncode, result.stderr.decode(errors="replace"))

    def test_no_removed_framework_calls_or_synthetic_placeholder(self):
        obsolete = (
            "host-prerequisite", "host-php-apt", "provision-php",
            "private-custody", "WSTM_CUSTODY", "WSTM108_HOST",
            "untrusted-export", "untrusted-proof", "untrusted-admission",
            "untrusted-stage", "verify-candidate-floor", "candidate-floor-tools",
            "controller-components", "kernel-mount", "test_untrusted_query",
            "test_kernel_mount_holder", "test:host-prerequisites",
        )
        texts = {name: source(name) for name in (
            "unit-tests.yml", "e2e-qa.yml", "release-package-qa.yml",
            "compatibility-qa.yml", "release.yml",
        )}
        texts["composer.json"] = (ROOT / "composer.json").read_text(encoding="utf-8")
        for name in (
            "scripts/test-release-runtime.sh",
            "tests/unit/fixtures/release-runtime-docker.sh",
            "tests/unit/fixtures/release-runtime-product.sh",
        ):
            texts[name] = (ROOT / name).read_text(encoding="utf-8")
        for name, text in texts.items():
            for forbidden in obsolete:
                with self.subTest(file=name, token=forbidden):
                    self.assertNotIn(forbidden, text)
        for name in ("unit-tests.yml", "e2e-qa.yml", "release-package-qa.yml"):
            self.assertIn("  pull_request:\n", texts[name])
            self.assertNotIn("pull_request_target:", texts[name])
        for name, text in texts.items():
            if name.endswith(".yml"):
                self.assertIn("\npermissions: {}\n", text)
                self.assertNotIn("docker compose", text)
                self.assertNotIn("MYSQL_PORT:", text)
                for action in re.findall(r"^\s+uses: (\S+)", text, re.M):
                    self.assertRegex(action, r"^[^@]+@[0-9a-f]{40}$")

    def test_composer_keeps_main_static_and_full_unit_discovery(self):
        composer = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
        scripts = composer["scripts"]
        self.assertEqual([
            "@lint:php", "@phpcs", "@phpstan", "@test:phpstan-baseline",
            "@audit:locked", "@validate:e2e-manifest",
            "@validate:security-qa", "@diff-check",
        ], scripts["qa:static"])
        self.assertEqual(
            "php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml.dist",
            scripts["qa:unit"],
        )
        self.assertEqual([
            "php scripts/test-release-safeguards.php",
            "bash scripts/test-release-tag-check.sh",
            "bash scripts/test-release-plugin-check.sh",
            "bash scripts/test-release-runtime.sh",
            "php scripts/test-release-recovery.php",
            "bash scripts/test-release-control-check.sh",
            "bash scripts/test-release-publish-prerequisites.sh",
        ], scripts["test:release-safeguards"])
        for command in (
            "@test:phpstan-baseline", "@test:release-safeguards",
            "bash tests/destructive-stage-test.sh",
            "bash tests/input-schema-stage-test.sh",
            "bash tests/compatibility-download-test.sh",
            "bash tests/compatibility-dependency-policy-test.sh",
            "bash tests/qa-compose-safety-test.sh",
            "bash tests/legacy-artifact-stage-test.sh",
            "python3 -B -m unittest discover -s tests/unit -p test_rescoped_workflow_gates.py -v",
        ):
            self.assertIn(command, scripts["test:ci-safeguards"])
        self.assertEqual("8.0.0", composer["config"]["platform"]["php"])

    def test_runtime_and_package_preserve_main_checks_and_fail_closed_detector(self):
        docker = jobs(source("e2e-qa.yml"))
        detector = docker["changes"]
        for required in (
            'git merge-base "$BASE_SHA" "$HEAD_SHA"',
            'git diff --name-only "$MERGE_BASE" "$HEAD_SHA"',
            'if [ "$status" -ne 1 ]; then',
            'echo "Runtime change detection failed." >&2',
        ):
            self.assertIn(required, detector)
        gate = next(job for job in docker.values()
                    if "run: php scripts/docker-qa-gate.php" in job)
        for required in (
            "if: ${{ always() }}",
            "DETECTOR: ${{ needs.changes.result }}",
            "SHOULD_RUN: ${{ needs.changes.outputs.should-run }}",
            "CONTRACT: ${{ needs.ability-contract-qa.result }}",
            "TRANSPORT: ${{ needs.full-mcp-e2e-qa.result }}",
            'run: php scripts/docker-qa-gate.php "$DETECTOR" "$SHOULD_RUN" "$CONTRACT" "$TRANSPORT"',
        ):
            self.assertIn(required, gate)
        for name, mode in (("ability-contract-qa", "contract"),
                           ("full-mcp-e2e-qa", "e2e")):
            self.assertIn("if: needs.changes.outputs.should-run == 'true'", docker[name])
            self.assertIn("run: bash scripts/e2e-test.sh " + mode, docker[name])
            self.assertIn("run: php scripts/docker-qa-summary.php", docker[name])
            self.assertIn("run: bash scripts/destructive-retention.sh cleanup", docker[name])
            self.assertIn("shivammathur/setup-php@", docker[name])
            self.assertNotIn("continue-on-error:", docker[name])
        self.assertIn("Check WordPress debug log for errors", docker["ability-contract-qa"])
        for text in (source("e2e-qa.yml"), source("compatibility-qa.yml")):
            self.assertNotIn("--allow-root", text)
            self.assertNotIn("docker compose exec", text)
            self.assertNotIn("docker compose down", text)
            self.assertNotIn("docker compose up", text)
            self.assertIn("bash scripts/qa-compose.sh exec -T", text)
        package = source("release-package-qa.yml")
        composer = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
        for command in composer["scripts"]["test:release-safeguards"]:
            self.assertIn(command, package)
        self.assertIn("run: bash scripts/release-qa.sh", package)
        self.assertIn("php-version: '8.2'", package)
        self.assertNotIn("continue-on-error:", package)
        for text in (source("e2e-qa.yml"), source("compatibility-qa.yml"),
                     package, source("release.yml")):
            self.assertNotRegex(text, r"\bmkdir[^\n]*\be2e-artifacts\b")
            self.assertNotRegex(text, r"\brm[^\n]*\be2e-artifacts\b")

    def test_actual_docker_gate_rejects_failed_detector_and_unexpected_runtime_skip(self):
        script = r"""<?php
require 'scripts/docker-qa-gate.php';
$states = array( 'success', 'failure', 'cancelled', 'skipped', '', 'unknown' );
foreach ( $states as $detector ) {
    foreach ( array( 'true', 'false', '', 'unknown' ) as $should_run ) {
        foreach ( $states as $contract ) {
            foreach ( $states as $transport ) {
                $expected = 'success' === $detector && (
                    ( 'true' === $should_run && 'success' === $contract && 'success' === $transport ) ||
                    ( 'false' === $should_run && 'skipped' === $contract && 'skipped' === $transport )
                );
                if ( $expected !== docker_qa_gate_passes( $detector, $should_run, $contract, $transport ) ) {
                    exit( 1 );
                }
            }
        }
    }
}
echo "864 actual Docker gate cases passed.\n";
"""
        result = subprocess.run(
            ["php"], input=script.encode(), capture_output=True,
            cwd=ROOT, timeout=30,
        )
        self.assertEqual(0, result.returncode, result.stderr.decode(errors="replace"))
        self.assertIn(b"864 actual Docker gate cases passed.", result.stdout)

    def test_main_release_policy_is_unchanged_by_framework_removal(self):
        # Exact main policy includes tag/metadata/recovery/control/environment
        # approval guards and both publication destinations, not only job names.
        digest = hashlib.sha256(source("release.yml").encode()).hexdigest()
        self.assertEqual(
            "0c19c4426972eaeaa835c35f70e79b8dac9b2fde4333f714618a292b26d970a8",
            digest,
        )

    def test_compatibility_preserves_main_campaign_and_honest_floor_label(self):
        compatibility = source("compatibility-qa.yml")
        self.assertNotIn("  pull_request:", compatibility)
        self.assertIn("  schedule:", compatibility)
        for required in (
            "run: bash scripts/discover-compatibility.sh",
            "bash tests/compatibility-download-test.sh",
            "bash tests/compatibility-dependency-policy-test.sh",
            "for attempt in 1 2 3; do",
            "Lane untested; promotion blocked.",
            "      fail-fast: false",
            'test "$RUNTIME_RESULT" = success',
            'test "$(git rev-parse HEAD)" = "$CANDIDATE_SHA"',
            'test "$(git rev-parse FETCH_HEAD)" = "$SOURCE_SHA"',
            "Do not substitute workflow_dispatch runs",
            "This PR is never auto-merged.",
            'for required in discover-versions compatibility-qa current-plugin-check; do',
        ):
            self.assertIn(required, compatibility)
        matrix = (ROOT / "scripts" / "compatibility-matrix.php").read_text(encoding="utf-8")
        self.assertIn("$lane( 'wp69-php81-compatibility', '6.9'", matrix)
        self.assertNotIn("'supported-floor'", matrix)
        self.assertNotIn("'8.0'", matrix)
        self.assertNotIn("path: e2e-artifacts/", compatibility)
        self.assertNotIn("            e2e-artifacts/\n", compatibility)
        self.assertNotIn("            compatibility-artifacts/\n", compatibility)


if __name__ == "__main__":
    unittest.main()
