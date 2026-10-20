"""Execute launcher engine controls with API doubles, never a real container engine."""

import json
import re
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

from test_rescoped_workflow_gates import jobs


ROOT = Path(__file__).resolve().parents[2]


class QaExecutionTest(unittest.TestCase):
    def run_control(self, body, env=None):
        script = r"""
set -Eeuo pipefail
source scripts/qa-compose.sh
docker() {
    printf 'docker-call:%s\n' "$*" >&2
    if [[ "${1:-}" == --host ]]; then shift 2; fi
    case "${1:-}" in
        context) printf '%s\n' "${MOCK_CONTEXT:-unix:///var/run/docker.sock}" ;;
        version) printf '%s\n' "$MOCK_SERVER" ;;
        compose) return "${MOCK_COMPOSE_STATUS:-0}" ;;
        *) return 95 ;;
    esac
}
podman() { return "${MOCK_PODMAN_STATUS:-0}"; }
""" + body
        selected = {**os.environ, "BASH_ENV": os.devnull}
        for key in ("WSTM_QA_ENGINE", "WSTM_QA_ENGINE_HOST", "WSTM_QA_LOCATION", "DOCKER_HOST"):
            selected.pop(key, None)
        selected["MOCK_SERVER"] = json.dumps({
            "Os": "linux", "Version": "29.6.2", "Platform": {"Name": "Docker Engine"}
        })
        selected.update(env or {})
        return subprocess.run(
            [shutil.which("bash"), "--noprofile", "--norc"], input=script.encode(),
            cwd=ROOT, env=selected, capture_output=True, timeout=30, check=False,
        )

    def test_docker_preflight_pins_local_endpoint(self):
        result = self.run_control(
            'qa_engine_preflight\n'
            'docker() { [[ "$1 $2" == "--host unix:///var/run/docker.sock" ]]; }\n'
            'qa_engine info\n'
        )
        self.assertEqual(0, result.returncode, result.stderr.decode())
        self.assertIn(b"QA engine preflight passed", result.stdout)

    def test_invalid_and_remote_selection_never_reaches_server(self):
        for env in (
            {"WSTM_QA_ENGINE": "unknown"},
            {"WSTM_QA_ENGINE_HOST": "ssh://other-host"},
            {"DOCKER_HOST": "tcp://other-host:2375"},
            {"WSTM_QA_ENGINE": "podman"},
        ):
            with self.subTest(env=env):
                result = self.run_control("qa_engine_preflight\n", env)
                self.assertNotEqual(0, result.returncode)
                self.assertNotIn(b"QA engine preflight passed", result.stdout)
                self.assertNotIn(b"docker-call:version", result.stderr)
                self.assertNotIn(b"docker-call:--host", result.stderr)

    def test_podman_requires_matching_api_and_connected_native_client(self):
        env = {"WSTM_QA_ENGINE": "podman", "WSTM_QA_ENGINE_HOST": "unix:///run/user/1000/podman/podman.sock"}
        result = self.run_control("qa_engine_preflight\n", env)
        self.assertNotEqual(0, result.returncode)
        self.assertIn(b"does not match", result.stderr)
        env["MOCK_SERVER"] = json.dumps({
            "Os": "linux", "Version": "6.1.2", "Platform": {"Name": "Podman Engine"}
        })
        result = self.run_control("qa_engine_preflight\n", env)
        self.assertEqual(0, result.returncode, result.stderr.decode())
        self.assertIn(b"podman, Linux server 6.1.2", result.stdout)
        env["MOCK_PODMAN_STATUS"] = "125"
        result = self.run_control("qa_engine_preflight\n", env)
        self.assertEqual(78, result.returncode)
        self.assertNotIn(b"docker-call:", result.stderr)

    def test_wrong_os_missing_version_and_compose_failure_remain_failures(self):
        for server in ({"Os": "windows", "Version": "29"}, {"Os": "linux"}, None):
            with self.subTest(server=server):
                result = self.run_control("qa_engine_preflight\n", {"MOCK_SERVER": json.dumps(server)})
                self.assertNotEqual(0, result.returncode)
                self.assertNotIn(b"QA engine preflight passed", result.stdout)
        result = self.run_control("qa_engine_preflight\n", {"MOCK_COMPOSE_STATUS": "42"})
        self.assertEqual(42, result.returncode)

    def test_preflight_failure_uses_stderr_even_when_php_defaults_to_stdout(self):
        result = self.run_control(
            'php() { command php -d display_errors=stdout -d log_errors=0 "$@"; }\n'
            'qa_engine_preflight\n',
            {"MOCK_SERVER": json.dumps({"Os": "windows", "Version": "29"})},
        )
        self.assertNotEqual(0, result.returncode)
        self.assertIn(b"QA requires an observed Linux container server", result.stderr)
        self.assertNotIn(b"RuntimeException", result.stdout)

    def test_launcher_modes_and_both_wrappers_use_shared_entry(self):
        launcher = (ROOT / "scripts" / "qa-run.sh").read_text()
        self.assertIn("qa_engine_preflight", launcher)
        self.assertIn("bash scripts/release-qa.sh --product", launcher)
        self.assertIn("bash scripts/release-qa.sh --publishing", launcher)
        self.assertNotIn("gh workflow", launcher)
        for name in ("qa-local.sh", "qa-local.ps1"):
            self.assertIn("scripts/qa-run.sh", (ROOT / "scripts" / name).read_text())

    def test_owned_project_refuses_endpoint_changes(self):
        with tempfile.TemporaryDirectory(prefix="wstm-engine-control-") as directory:
            result = self.run_control(
                'qa_prepare_project\n'
                'WSTM_QA_ENGINE_HOST=unix:///different-engine.sock\n'
                'qa_prepare_project\n',
                {"COMPOSE_PROJECT_NAME": "wstm-engine-control",
                 "WSTM_QA_STATE_ROOT": directory.replace("\\", "/"),
                 "WSTM_QA_ENGINE_HOST": "unix:///var/run/docker.sock"},
            )
        self.assertEqual(78, result.returncode, result.stderr.decode())
        self.assertIn(b"engine or endpoint changed", result.stderr)

    def test_single_check_gate_rejects_missing_detection_and_unexpected_skips(self):
        for detector, selected, result, expected in (
            ("success", "true", "success", 0),
            ("success", "false", "skipped", 0),
            ("failure", "false", "skipped", 1),
            ("success", "true", "skipped", 1),
            ("success", "false", "success", 1),
            ("success", "", "success", 1),
            ("success", "true", "cancelled", 1),
        ):
            with self.subTest(detector=detector, selected=selected, result=result):
                actual = subprocess.run(
                    ["php", "scripts/qa-check-gate.php", detector, selected, result],
                    cwd=ROOT, capture_output=True, timeout=30, check=False,
                )
                self.assertEqual(expected, actual.returncode)

    def test_real_manifest_oracles_reject_fabricated_terms_and_empty_diagnostics(self):
        script = r"""<?php
class WP_Term {
    public $taxonomy = 'category';
    public $name = 'Stored category';
    public $slug = 'stored-category';
}
function get_term($id, $taxonomy) {
    if ($id === 12 && $taxonomy === 'category') { return new WP_Term(); }
    return null;
}
require 'tests/e2e/qa-assertions.php';
$state = ['taxonomy' => 'category', 'name' => 'Stored category', 'slug' => 'stored-category'];
$created = ['data' => ['id' => 12]];
if (!webmastery_mcp_qa_term_state($state, $created)) { exit(1); }
foreach ([['data' => ['id' => 13]], ['data' => ['id' => '12']], ['data' => []]] as $fake) {
    if (webmastery_mcp_qa_term_state($state, $fake)) { exit(2); }
}
$state['name'] = 'Fabricated name';
if (webmastery_mcp_qa_term_state($state, $created)) { exit(3); }
if (webmastery_mcp_qa_term_state(['taxonomy' => 'category', 'id' => 12, 'deleted' => true], [])) { exit(4); }
if (!webmastery_mcp_qa_term_state(['taxonomy' => 'category', 'id' => 13, 'deleted' => true], [])) { exit(5); }
$ability = 'webmastery-site-toolkit-for-mcp/security-audit';
$data = ['summary' => ['fail' => 0, 'warn' => 0, 'pass' => 1], 'fail' => [], 'warn' => [],
    'pass' => [['check' => 'ssl', 'label' => 'Observed configuration']]];
if (!webmastery_mcp_qa_bucket_counts(['data' => $data], $ability)) { exit(6); }
$data['summary']['pass'] = 2;
if (webmastery_mcp_qa_bucket_counts(['data' => $data], $ability)) { exit(7); }
$data['summary']['pass'] = 0; $data['pass'] = [];
if (webmastery_mcp_qa_bucket_counts(['data' => $data], $ability)) { exit(8); }
$data['summary']['pass'] = '0';
if (webmastery_mcp_qa_bucket_counts(['data' => $data], $ability)) { exit(9); }
$health = ['summary' => ['critical' => 0, 'recommended' => 0, 'good' => 1],
    'results' => ['critical' => [], 'recommended' => [], 'good' => [['test' => 'php_version', 'label' => 'Observed PHP']]]];
if (!webmastery_mcp_qa_bucket_counts(['data' => $health], 'webmastery-site-toolkit-for-mcp/site-health-check')) { exit(10); }
echo "Term persistence and diagnostic mutation controls passed.\n";
"""
        result = subprocess.run(
            ["php"], input=script.encode(), cwd=ROOT, capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(0, result.returncode, result.stderr.decode())
        self.assertIn(b"mutation controls passed", result.stdout)

    def test_product_workflows_report_only_same_job_artifacts_and_fail_closed_gates(self):
        for file, producer, gate in (
            ("05-release-package-qa.yml", "release-package-qa", "package-gate"),
            ("06-minimum-runtime-qa.yml", "php80-runtime", "minimum-runtime-gate"),
        ):
            text = (ROOT / ".github" / "workflows" / file).read_text()
            self.assertIn("  schedule:\n", text)
            self.assertIn("  workflow_dispatch:\n", text)
            self.assertNotIn("  push:\n", text)
            self.assertIn(f"needs: [changes, {producer}]", text)
            gate_text = text.split(f"\n  {gate}:", 1)[1]
            self.assertIn("if: ${{ always() }}", gate_text)
            self.assertIn('run: php scripts/qa-check-gate.php "$DETECTOR" "$SHOULD_RUN" "$RESULT"', gate_text)
            self.assertNotIn("actions/upload-artifact@", gate_text)
        package = (ROOT / ".github" / "workflows" / "05-release-package-qa.yml").read_text()
        self.assertNotIn("    paths:", package)
        self.assertIn("          QA_SCOPE: package\n", package)
        floor = (ROOT / ".github" / "workflows" / "06-minimum-runtime-qa.yml").read_text()
        paths = re.search(r"    paths:\n((?:      - .+\n)+)", floor)
        self.assertIsNotNone(paths)
        self.assertEqual(
            ["'.github/workflows/06-minimum-runtime-qa.yml'", "'scripts/qa-floor.sh'",
             "'tests/fixtures/php80-floor/**'"],
            [line.strip()[2:] for line in paths[1].splitlines()],
        )
        for file, count in (("03-04-docker-qa.yml", 2), ("07-compatibility-qa.yml", 1)):
            text = (ROOT / ".github" / "workflows" / file).read_text()
            self.assertEqual(count, text.count("E2E_ARTIFACTS_DIR: e2e-artifacts"))

    def test_term_oracle_validator_rejects_explicit_null_fields(self):
        manifest = json.loads((ROOT / "tests" / "e2e" / "abilities-manifest.json").read_text())
        for field in ("id", "deleted", "name", "slug", "description"):
            with self.subTest(field=field), tempfile.TemporaryDirectory(prefix="wstm-term-oracle-") as directory:
                candidate = json.loads(json.dumps(manifest))
                case = next(case for case in candidate if case["label"] == "create-category")
                case["assert_term_state"][field] = None
                fixture = Path(directory)
                (fixture / "scripts").mkdir()
                (fixture / "tests" / "e2e").mkdir(parents=True)
                shutil.copyfile(ROOT / "scripts" / "validate-e2e-manifest.php", fixture / "scripts" / "validate-e2e-manifest.php")
                shutil.copyfile(ROOT / "tests" / "e2e" / "error-contract-assertions.php", fixture / "tests" / "e2e" / "error-contract-assertions.php")
                path = fixture / "tests" / "e2e" / "abilities-manifest.json"
                path.write_text(json.dumps(candidate))
                result = subprocess.run(
                    ["php", str(fixture / "scripts" / "validate-e2e-manifest.php")],
                    cwd=ROOT, capture_output=True, timeout=30, check=False,
                )
                self.assertNotEqual(0, result.returncode)
                self.assertIn(f"assert_term_state.{field}".encode(), result.stderr)

    def test_actual_runner_executes_diagnostic_and_term_oracles_independently_once(self):
        source = (ROOT / "tests" / "e2e" / "ability-runner.php").read_text()
        start = source.index("\tif ( $passed && ! empty( $case['assert_values'] ) ) {")
        end = source.index("\tif ( $passed && isset( $case['assert_diagnostic_findings'] ) ) {", start)
        body = source[start:end]
        script = r"""<?php
class WP_Term { public $taxonomy = 'category'; public $name = 'Stored category'; }
$reads = 0;
function get_term($id, $taxonomy) {
    ++$GLOBALS['reads'];
    return $id === 12 && $taxonomy === 'category' ? new WP_Term() : null;
}
function e2e_get_path_value($result, $path, &$exists) { $exists = true; return 12; }
require 'tests/e2e/qa-assertions.php';
function verdict($case, $result, $initial = true) {
    $passed = $initial;
""" + body + r"""
    return $passed;
}
foreach (['site-health-check', 'security-audit'] as $slug) {
    $case = ['ability' => 'webmastery-site-toolkit-for-mcp/' . $slug, 'assert_bucket_counts' => true];
    $keys = $slug === 'site-health-check' ? ['critical', 'recommended', 'good'] : ['fail', 'warn', 'pass'];
    $buckets = array_fill_keys($keys, []);
    $buckets[$keys[2]] = [[$slug === 'site-health-check' ? 'test' : 'check' => 'observed', 'label' => 'Observed']];
    $summary = array_combine($keys, [0, 0, 1]);
    $data = $slug === 'site-health-check' ? ['summary' => $summary, 'results' => $buckets] : ['summary' => $summary] + $buckets;
    if (!verdict($case, ['data' => $data])) { exit(1); }
    if (verdict($case, ['data' => []])) { exit(2); }
    $bad = $data; $bad['summary'][$keys[2]] = 2;
    if (verdict($case, ['data' => $bad])) { exit(3); }
    $bad['summary'][$keys[2]] = '1';
    if (verdict($case, ['data' => $bad])) { exit(4); }
    if (verdict($case, ['data' => $data], false)) { exit(5); }
}
$case = ['assert_term_state' => ['taxonomy' => 'category', 'name' => 'Stored category']];
if (!verdict($case, ['data' => ['id' => 12]]) || $reads !== 1) { exit(6); }
$case['assert_values'] = ['data.id' => 12, 'data.other' => 12];
$reads = 0;
if (!verdict($case, ['data' => ['id' => 12]]) || $reads !== 1) { exit(7); }
$case['assert_term_state']['name'] = 'Wrong category';
if (verdict($case, ['data' => ['id' => 12]])) { exit(8); }
$reads = 0;
$case['assert_values']['data.id'] = 13;
if (verdict($case, ['data' => ['id' => 12]]) || $reads !== 0) { exit(9); }
echo "Actual runner independent oracle controls passed.\n";
"""
        result = subprocess.run(
            ["php"], input=script.encode(), cwd=ROOT, capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout.decode() + result.stderr.decode())
        mutant = script.replace(body, "if ( ! empty( $case['assert_values'] ) ) {\n" + body + "}\n", 1)
        rejected = subprocess.run(
            ["php"], input=mutant.encode(), cwd=ROOT, capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(2, rejected.returncode, "Value-gated diagnostic assertions must fail the executable regression.")

    def test_contract_workflow_preserves_stack_until_version_and_debug_reporting(self):
        contract = jobs((ROOT / ".github" / "workflows" / "03-04-docker-qa.yml").read_text())["ability-contract-qa"]
        environment = contract.split("    steps:", 1)[0]
        self.assertIn("      E2E_KEEP_COMPOSE: 1", environment)
        ordered = ["Run Ability Contract QA", "Collect tested versions and contract summary",
                   "Check WordPress debug log for errors", "Clean up"]
        positions = [contract.index(f"      - name: {name}") for name in ordered]
        self.assertEqual(sorted(positions), positions)
        self.assertIn("run: bash scripts/destructive-retention.sh cleanup", contract)

    def test_change_detection_lists_both_sides_of_renames(self):
        # Without --no-renames, git reports only a rename's destination, so a
        # runtime file moved to an ignored Markdown name would skip Docker QA.
        body = r"""
git() {
    case "$1" in
        merge-base) printf 'base\n' ;;
        diff)
            if [[ " $* " == *" --no-renames "* ]]; then
                printf 'includes/runtime.php\nincludes/runtime.md\n'
            else
                printf 'includes/runtime.md\n'
            fi
            ;;
        *) return 99 ;;
    esac
}
source scripts/qa-changes.sh
"""
        for event_env in (
            {"EVENT_NAME": "pull_request", "BASE_SHA": "base", "HEAD_SHA": "head"},
            {"EVENT_NAME": "push", "BEFORE_SHA": "before", "CURRENT_SHA": "head"},
        ):
            with self.subTest(event=event_env["EVENT_NAME"]):
                result = self.run_control(body, {**event_env, "QA_SCOPE": "runtime"})
                self.assertEqual(0, result.returncode, result.stderr.decode())
                self.assertIn(b"should-run=true", result.stdout)

    def test_change_detection_refuses_unknown_events_and_git_failures(self):
        body = r"""
git() {
    case "$1" in
        merge-base) printf 'base\n' ;;
        diff) printf '%s\n' "$MOCK_CHANGED"; return "${MOCK_GIT_STATUS:-0}" ;;
        *) return 99 ;;
    esac
}
source scripts/qa-changes.sh
"""
        for paths, selected in (
            ("README.md\ndocs/00-sdlc-overview.md", "false"),
            ("scripts/qa-compose.sh", "true"),
            ("docker-compose.release.yml", "true"),
            ("tests/fixtures/php80-floor/Dockerfile", "true"),
            ('"quoted-path.md"', "true"),
        ):
            result = self.run_control(body, {
                "EVENT_NAME": "pull_request", "BASE_SHA": "base", "HEAD_SHA": "head",
                "MOCK_CHANGED": paths,
            })
            self.assertEqual(0, result.returncode, result.stderr.decode())
            self.assertIn(f"should-run={selected}".encode(), result.stdout)
        for scope, paths, selected in (
            ("runtime", "README.md\ndocs/00-sdlc-overview.md\ntests/unit/ResponseTest.php", "false"),
            ("runtime", "tests/e2e/README.md\ndocs/reference/3.0-migration.md\n.github/ISSUE_TEMPLATE/bug_report.md", "false"),
            ("runtime", "readme.txt\nCHANGELOG.md", "false"),
            ("runtime", "tests/e2e/README.md\ntests/e2e/ability-runner.php", "true"),
            ("runtime", "readme.txt\nincludes/class-posts.php", "true"),
            ("runtime", "includes/readme.txt", "true"),
            ("runtime", ".github/workflows/02-unit-tests.yml\nscripts/test-release-runtime.sh", "false"),
            ("runtime", ".github/rulesets/main-ci-gates.json\n.github/environments/wordpress-org.json\nscripts/ruleset-drift.py", "false"),
            ("runtime", "scripts/release-tools.php", "true"),
            ("runtime", ".github/workflows/03-04-docker-qa.yml", "true"),
            ("runtime", ".github/compatibility-versions.json", "true"),
            ("runtime", "includes/class-posts.php", "true"),
            ("runtime", "tests/e2e/ability-runner.php", "true"),
            ("runtime", "unknown-new-path.txt", "true"),
            ("runtime", '"quoted-path.md"', "true"),
            ("package", "includes/class-posts.php\ndocs/x.md", "false"),
            ("package", "readme.txt", "true"),
            ("package", "scripts/build-release.sh", "true"),
            ("package", "scripts/create-release-zip.php", "true"),
            ("package", "LICENSE", "true"),
            ("package", '"quoted-path.md"', "true"),
        ):
            with self.subTest(scope=scope, paths=paths):
                result = self.run_control(body, {
                    "EVENT_NAME": "pull_request", "BASE_SHA": "base", "HEAD_SHA": "head",
                    "MOCK_CHANGED": paths, "QA_SCOPE": scope,
                })
                self.assertEqual(0, result.returncode, result.stderr.decode())
                self.assertIn(f"should-run={selected}".encode(), result.stdout)
        result = self.run_control(body, {
            "EVENT_NAME": "pull_request", "BASE_SHA": "base", "HEAD_SHA": "head",
            "MOCK_CHANGED": "README.md", "QA_SCOPE": "unknown",
        })
        self.assertEqual(78, result.returncode)
        for event in ("schedule", "workflow_dispatch"):
            result = self.run_control(body, {"EVENT_NAME": event, "MOCK_CHANGED": "README.md"})
            self.assertEqual(0, result.returncode, result.stderr.decode())
            self.assertIn(b"should-run=true", result.stdout)
        result = self.run_control(body, {"EVENT_NAME": "unknown"})
        self.assertEqual(78, result.returncode)
        result = self.run_control(body, {
            "EVENT_NAME": "pull_request", "BASE_SHA": "base", "HEAD_SHA": "head",
            "MOCK_CHANGED": "README.md", "MOCK_GIT_STATUS": "42",
        })
        self.assertEqual(42, result.returncode)
        self.assertNotIn(b"should-run=false", result.stdout)


if __name__ == "__main__":
    unittest.main()
