"""Native synthetic query supervision. No Docker, grant or admission is run."""

import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("untrusted_query", ROOT / "scripts/untrusted-query.py")
query = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(query)


@unittest.skipUnless(sys.platform.startswith("linux") and hasattr(os, "pidfd_open"),
                     "Native synthetic supervision requires Linux pidfd.")
class QueryTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="wstm108-query-")
        self.directory = Path(self.temporary.name)
        self.mask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.mask)
        self.temporary.cleanup()

    def run_synthetic(self, code, seconds=5):
        argv = [str(Path(sys.executable).resolve()), "-c", code]
        request = self.directory / "request.private.json"
        request.write_text(json.dumps({"argv": argv, "cwd": str(query.ROOT),
                                      "deadline_ns": time.monotonic_ns() + int(seconds * 10**9)}))
        handles = []
        saved = {}
        for descriptor, name in ((10, "stdout"), (11, "stderr")):
            try:
                saved[descriptor] = os.dup(descriptor)
            except OSError:
                saved[descriptor] = None
            handle = (self.directory / (name + ".log")).open("x+b")
            handles.append(handle)
            os.dup2(handle.fileno(), descriptor)
        if getattr(self, "mismatched_original", False):
            os.dup2(handles[1].fileno(), 10)
        try:
            with mock.patch.object(query, "readonly") as readonly, contextlib.redirect_stdout(io.StringIO()) as output:
                try:
                    query.execute(request)
                finally:
                    readonly.assert_called_once_with(argv)
            return output.getvalue()
        finally:
            for descriptor, original in saved.items():
                os.close(descriptor)
                if original is not None:
                    os.dup2(original, descriptor)
                    os.close(original)
            for handle in handles:
                handle.close()

    def receipt(self):
        return json.loads((self.directory / "receipt.json").read_bytes())

    def test_original_inherited_descriptors_and_binary_streams(self):
        original = self.directory / "stdout.log"
        self.assertEqual("WSTM108_QUERY_COMPLETE_V1\n",
                         self.run_synthetic("import os;os.write(1,b'\\0\\xff');os.write(2,b'private')"))
        self.assertEqual(b"\0\xff", original.read_bytes())
        self.assertEqual(b"private", (self.directory / "stderr.log").read_bytes())
        self.assertTrue(self.receipt()["root_exit_observed"])
        self.assertEqual(0, self.receipt()["exit_code"])

    def test_deadline_retains_prefix_and_proves_owned_exit(self):
        with self.assertRaises(RuntimeError):
            self.run_synthetic("import os,time;os.write(1,b'prefix');time.sleep(30)", 0.2)
        self.assertEqual(b"prefix", (self.directory / "stdout.log").read_bytes())
        receipt = self.receipt()
        self.assertTrue(receipt["root_exit_observed"])
        self.assertTrue(receipt["kill_requested"])
        self.assertLess(receipt["exit_code"], 0)

    def test_overflow_is_not_a_successful_complete_query(self):
        with self.assertRaises(RuntimeError):
            self.run_synthetic("import os;os.write(1,b'x'*(5*1024**2))")
        self.assertTrue(self.receipt()["truncated"])
        self.assertLessEqual((self.directory / "stdout.log").stat().st_size, 4 * 1024**2)

    def test_nonzero_exit_is_preserved(self):
        with self.assertRaises(query.ObservedQueryFailure) as failure:
            self.run_synthetic("import sys;sys.exit(47)")
        self.assertEqual(47, failure.exception.code)
        self.assertEqual(47, self.receipt()["exit_code"])

    def test_expired_deadline_never_launches_target(self):
        with self.assertRaises(RuntimeError):
            self.run_synthetic("raise AssertionError('must not launch')", -1)
        self.assertIsNone(self.receipt()["pid"])

    def test_descriptor_substitution_refuses_before_child_launch(self):
        self.mismatched_original = True
        with self.assertRaises(RuntimeError):
            self.run_synthetic("raise AssertionError('must not launch')")
        self.assertEqual(b"", (self.directory / "stdout.log").read_bytes())
        self.assertEqual(b"", (self.directory / "stderr.log").read_bytes())
        self.assertTrue((self.directory / "request.private.json").is_file())

    def test_stderr_overflow_remains_failed_with_owned_exit(self):
        with self.assertRaises(RuntimeError):
            self.run_synthetic("import os;os.write(2,b'x'*(5*1024**2))")
        self.assertTrue(self.receipt()["truncated"])
        self.assertTrue(self.receipt()["root_exit_observed"])
        self.assertLessEqual((self.directory / "stderr.log").stat().st_size, 4 * 1024**2)

    def test_descendant_held_pipes_are_closed_by_owned_group_cleanup(self):
        with self.assertRaises(RuntimeError):
            self.run_synthetic("import os,time;child=os.fork();"
                               "time.sleep(30) if child==0 else os._exit(0)", 0.2)
        receipt = self.receipt()
        self.assertTrue(receipt["root_exit_observed"])
        self.assertTrue(receipt["kill_requested"])
        self.assertTrue(receipt["stdout_eof"])
        self.assertTrue(receipt["stderr_eof"])

    def test_cli_refusal_is_closed_and_does_not_include_input(self):
        request = self.directory / "request.private.json"
        request.write_text('{"private":"SENTINEL"}')
        result = subprocess.run([sys.executable, "-B", str(ROOT / "scripts/untrusted-query.py"), str(request)],
                                capture_output=True, timeout=5)
        self.assertEqual(78, result.returncode)
        self.assertEqual(b"", result.stdout)
        self.assertEqual(b"WSTM108_QUERY_REFUSED_V1\n", result.stderr)

    def test_real_unwritable_terminal_channels_still_refuse_without_raw_trace(self):
        request = self.directory / "request.private.json"
        request.write_text('{"private":"SENTINEL"}')
        with open("/dev/full", "wb") as unwritable:
            result = subprocess.run([sys.executable, "-B", str(ROOT / "scripts/untrusted-query.py"), str(request)],
                                    stdout=subprocess.PIPE, stderr=unwritable, timeout=5)
            self.assertEqual(78, result.returncode)
            self.assertEqual(b"WSTM108_QUERY_DIAGNOSTIC_IO_REFUSED_V1\n", result.stdout)
            result = subprocess.run([sys.executable, "-B", str(ROOT / "scripts/untrusted-query.py"), str(request)],
                                    stdout=unwritable, stderr=unwritable, timeout=5)
            self.assertEqual(78, result.returncode)

    def test_partial_zero_and_exception_terminal_writes_retain_original_exit(self):
        def stop_process(code):
            raise SystemExit(code)

        for writes in ([OSError("SENTINEL"), 4, 0],
                       [OSError("SENTINEL"), OSError("SENTINEL")],
                       [OSError("SENTINEL"), 4, 32, 3],
                       [4, 0, 39]):
            with self.subTest(writes=writes):
                with mock.patch.object(query.os, "write", side_effect=writes) as write, \
                        mock.patch.object(query.os, "_exit", side_effect=stop_process) as stop:
                    with self.assertRaises(SystemExit) as outcome:
                        query.refused(47)
                self.assertEqual(47, outcome.exception.code)
                stop.assert_called_once_with(47)
                self.assertEqual(2, write.call_args_list[0].args[0])
                self.assertTrue(any(args.args[0] == 1 for args in write.call_args_list))
                for args in write.call_args_list:
                    self.assertNotIn(b"SENTINEL", args.args[1])

    def test_mutating_docker_commands_and_unreviewed_selectors_are_rejected(self):
        binary = str(Path("/usr/bin/docker").resolve())
        for args in (["run", "image"], ["compose", "--project-name", "owned", "down", "-v"],
                     ["compose", "--project-name", "owned", "-f", "unreviewed.yml", "ps", "--all", "--format", "json"],
                     ["--host", "tcp://private", "info", "--format", "{{json .ID}}"]):
            with self.subTest(args=args), self.assertRaises(RuntimeError):
                query.readonly([binary] + args)

    def test_existing_source_and_package_readonly_selectors_are_preserved(self):
        binary = str(Path("/usr/bin/docker").resolve())
        for files in ([], ["-f", "docker-compose.yml", "-f", "docker-compose.release.yml"]):
            query.readonly([binary, "--host", "unix:///run/docker.sock", "compose",
                            "--project-name", "owned"] + files + ["config", "--format", "json", "--no-env-resolution"])

    def test_exact_floor_projections_are_readonly_without_ambient_file_fallback(self):
        binary = str(Path("/usr/bin/docker").resolve())
        floor = str(ROOT / "tests/fixtures/php80-floor/compose.yml")
        for command in (["config", "--format", "json", "--no-env-resolution"],
                        ["ps", "--all", "--format", "json"]):
            query.readonly([binary, "compose", "--project-name", "owned", "--file", floor] + command)
        query.readonly([binary, "image", "inspect", "--format",
                        '{"id":{{json .Id}},"digests":{{json .RepoDigests}}}',
                        "mysql:8.0.36@sha256:" + "a" * 64])
        for replacement in ("compose.yml", floor + ".foreign", "/private/compose.yml"):
            with self.assertRaises(RuntimeError):
                query.readonly([binary, "compose", "--project-name", "owned",
                                "--file", replacement, "ps", "--all", "--format", "json"])
        with self.assertRaises(RuntimeError):
            query.readonly([binary, "compose", "--project-name", "owned", "--file", floor,
                            "-f", "docker-compose.yml", "-f", "docker-compose.release.yml",
                            "ps", "--all", "--format", "json"])
        with self.assertRaises(RuntimeError):
            query.readonly([binary, "image", "inspect", "--format",
                            '{"id":{{json .Id}},"digests":{{json .RepoDigests}}}', "mysql:latest"])

    def test_selection_original_streams_and_failure_are_supervised_without_php_or_docker(self):
        original_supervisor = query.supervisor()
        for code, expected in (("print('export SYNTHETIC=owned')", 0),
                               ("import sys;sys.stderr.write('PRIVATE_SENTINEL');sys.exit(47)", 47),
                               ("import time;time.sleep(30)", 78)):
            with self.subTest(expected=expected), tempfile.TemporaryDirectory(prefix="selection-source-") as source:
                source = Path(source)
                (source / "scripts").mkdir()
                (source / "scripts/qa-runtime.php").write_text(code)
                environment = {"WSTM108_HOST_AUTHORITY_ROOT": str(self.directory),
                               "WSTM108_HOST_PHP": str(Path(sys.executable).resolve())}
                written = []
                with mock.patch.object(query, "ROOT", source), \
                        mock.patch.object(query, "readonly") as guard, \
                        mock.patch.object(query, "supervisor", return_value=original_supervisor), \
                        mock.patch.object(query, "QUERY_SECONDS", 0.2), \
                        mock.patch.dict(os.environ, environment), \
                        mock.patch.object(query, "terminal", side_effect=lambda fd, body: written.append((fd, body))):
                    if expected == 0:
                        query.runtime_selection("shell")
                        self.assertEqual([(1, b"export SYNTHETIC=owned\n")], written)
                    else:
                        with self.assertRaises(RuntimeError) as failure:
                            query.runtime_selection("shell")
                        if expected == 47:
                            self.assertEqual(47, failure.exception.code)
                        self.assertEqual([], written)
                    guard.assert_called()
                captures = sorted(self.directory.glob("runtime-selection-*/request.private.json"),
                                  key=lambda path: path.stat().st_mtime_ns)
                capture = captures[-1].parent
                receipt = json.loads((capture / "receipt.json").read_bytes())
                self.assertTrue(receipt["root_exit_observed"])
                self.assertEqual(expected == 0, receipt["exit_code"] == 0)
                if expected == 47:
                    self.assertEqual(b"PRIVATE_SENTINEL", (capture / "stderr.log").read_bytes())

    def test_original_explicit_interpreter_is_not_rebound_or_replaced_by_a_default(self):
        canonical = Path(sys.executable).resolve()
        alias = self.directory / "php"
        alias.symlink_to(canonical)
        for original in ("php", "", str(alias)):
            with mock.patch.dict(os.environ, {"WSTM108_HOST_PHP": original}), self.assertRaises(RuntimeError):
                query.runtime_interpreter()
        with mock.patch.dict(os.environ, {"WSTM108_HOST_PHP": str(canonical)}):
            self.assertEqual(canonical, query.runtime_interpreter())
        with mock.patch.dict(os.environ, {}, clear=True), mock.patch.object(query.shutil, "which", return_value=str(canonical)):
            self.assertEqual(canonical, query.runtime_interpreter())


@unittest.skipUnless(sys.platform.startswith("linux"), "Actual Bash source-fixture probes require Linux.")
class PipelineTest(unittest.TestCase):
    def test_floor_selection_refusal_precedes_admission_reset_and_fixture_cleanup(self):
        program = r'''
source "$1/scripts/e2e-test.sh"
COMPOSE_PROJECT_NAME=owned
QA_MODE=all
WSTM_QA_RUNTIME_PROFILE=php80-floor
run_untrusted_runtime_selection() { echo partial; return 47; }
run_untrusted_admission() { echo forbidden-admission; }
wstm116_require_no_retention() { echo forbidden-retention; }
start_compose() { echo forbidden-lifecycle; }
status=0
main || status=$?
exit "$status"
'''
        result = subprocess.run(["/bin/bash", "--noprofile", "--norc", "-c",
                                 program, "fixture", str(ROOT)], capture_output=True, text=True, timeout=5)
        self.assertEqual(47, result.returncode, result.stderr)
        self.assertNotIn("forbidden-", result.stdout)
        self.assertNotIn("partial", result.stdout)

    def test_floor_package_closed_refusal_precedes_build_or_cleanup_even_offline(self):
        source = (ROOT / "scripts/release-qa.sh").read_text()
        early = source[:source.index('if [[ ( "${CI:-}"')]
        program = r'''
set -Eeuo pipefail
WSTM_QA_RUNTIME_PROFILE=php80-floor
SKIP_PLUGIN_CHECK=1
source() { :; }
run_untrusted_runtime_selection() { printf 'captured:%s\n' "$1"; return 47; }
''' + '\nbuiltin source "$1"\n'
        with tempfile.TemporaryDirectory(prefix="package-selection-") as temporary:
            fixture = Path(temporary) / "release-qa.sh"
            fixture.write_text(early)
            result = subprocess.run(["/bin/bash", "--noprofile", "--norc", "-c", program, "fixture", str(fixture)],
                                    cwd=ROOT, capture_output=True, text=True, timeout=5)
        self.assertEqual(47, result.returncode, result.stderr)
        self.assertEqual("captured:package\n", result.stdout)

    def test_real_lifecycle_propagates_down_failure_without_starting_containers(self):
        program = r'''
source "$1/scripts/e2e-test.sh"
E2E_MANAGE_COMPOSE=1
wstm116_require_no_retention() { :; }
compose() { echo "operation:$1"; if [[ "$1" == down ]]; then return 47; fi; }
status=0
start_compose || status=$?
exit "$status"
'''
        result = subprocess.run(["/bin/bash", "--noprofile", "--norc", "-c",
                                 program, "fixture", str(ROOT)],
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(47, result.returncode, result.stderr)
        self.assertIn("operation:down\n", result.stdout)
        self.assertNotIn("operation:up\n", result.stdout)

    def test_real_admission_wrapper_does_not_change_parent_mode(self):
        program = r'''
source "$1/scripts/e2e-test.sh"
QA_MODE=all
run_untrusted_content_qa() { echo "child-mode:$QA_MODE"; }
run_untrusted_admission
echo "parent-mode:$QA_MODE"
'''
        result = subprocess.run(["/bin/bash", "--noprofile", "--norc", "-c",
                                 program, "fixture", str(ROOT)],
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("child-mode:admission\nparent-mode:all\n", result.stdout)

    def test_package_parent_does_not_claim_cleanup_before_observed_child_success(self):
        source = (ROOT / "scripts/release-qa.sh").read_text()
        start = source.index("runtime_status=0\n")
        end = source.index("\nsource scripts/release-plugin-check.sh", start)
        actual = source[start:end]
        for child_status in (0, 47, 78):
            with self.subTest(child_status=child_status):
                program = r'''
set -Eeuo pipefail
child_status=$1
bash() { printf 'child:%s\n' "$*"; return "$child_status"; }
cleanup_release() { echo parent-cleanup; }
''' + actual + "\nexit 53\n"
                result = subprocess.run(["/bin/bash", "--noprofile", "--norc", "-c",
                                         program, "fixture", str(child_status)],
                                        capture_output=True, text=True, timeout=5)
                self.assertEqual(child_status or 53, result.returncode)
                self.assertIn("child:scripts/e2e-test.sh all", result.stdout)
                self.assertEqual(child_status == 0, "parent-cleanup" in result.stdout)

    def test_cleanup_ownership_preserves_first_exit_and_contract_keep_behavior(self):
        cases = (("contract", 53, 0, False), ("all", 53, 0, True),
                 ("all", 0, 0, False), ("all", 53, 47, True))
        for mode, original, cleanup, expected in cases:
            with self.subTest(mode=mode, original=original, cleanup=cleanup):
                program = r'''
source "$1/scripts/e2e-test.sh"
QA_MODE=$2
E2E_MANAGE_COMPOSE=1
E2E_KEEP_COMPOSE=1
cleanup_status=$4
wstm116_require_no_retention() { :; }
compose() { echo owned-cleanup; return "$cleanup_status"; }
trap cleanup_compose EXIT
exit "$3"
'''
                result = subprocess.run(["/bin/bash", "--noprofile", "--norc", "-c", program,
                                         "fixture", str(ROOT), mode, str(original), str(cleanup)],
                                        capture_output=True, text=True, timeout=5)
                self.assertEqual(original, result.returncode)
                self.assertEqual(expected, "owned-cleanup" in result.stdout)

    def test_admission_and_lifecycle_refusals_have_no_fixture_or_cleanup(self):
        for failure in (1, 2, 3):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory(prefix="wstm108-pipeline-") as work:
                root = Path(work)
                (root / "retained").mkdir()
                (root / "retained/original").write_text("retained")
                program = r'''
source "$1/scripts/e2e-test.sh"
COMPOSE_PROJECT_NAME=owned
E2E_ARTIFACTS_DIR=retained
QA_MODE=all
E2E_MANAGE_COMPOSE=0
E2E_KEEP_COMPOSE=1
calls=0
wstm116_require_no_retention() { :; }
run_untrusted_admission() { calls=$((calls+1)); echo "admission-$calls"; if [[ "$calls" == "$2" ]]; then return 78; fi; }
start_compose() { echo lifecycle; if [[ "$failure" == 3 ]]; then return 47; fi; }
cleanup_compose() { echo forbidden-cleanup; }
wait_for_wordpress_files() { echo forbidden-fixture; }
status=0
main || status=$?
exit "$status"
'''
                # Function $2 would be its own arguments, so retain the selected
                # fault in a fixture-local variable before invoking the source.
                program = program.replace('calls=0', 'calls=0; failure=$2').replace('"$2"', '"$failure"')
                result = subprocess.run(["/bin/bash", "--noprofile", "--norc", "-c", program, "fixture", str(ROOT), str(failure)],
                                        cwd=root, capture_output=True, text=True, timeout=5)
                self.assertEqual(47 if failure == 3 else 78, result.returncode, result.stderr)
                self.assertNotIn("forbidden-", result.stdout)
                if failure == 1:
                    self.assertNotIn("lifecycle", result.stdout)
                    self.assertEqual("retained", (root / "retained/original").read_text())
                else:
                    self.assertIn("lifecycle", result.stdout)
                    if failure == 3:
                        self.assertNotIn("admission-2", result.stdout)


if __name__ == "__main__":
    unittest.main()
