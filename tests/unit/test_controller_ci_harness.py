"""In-process harness regressions; no controller import, subprocess or WordPress."""

import copy
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import zipfile


SOURCE = Path(__file__).resolve().parents[2] / "scripts" / "test-controller-components.py"
SPEC = importlib.util.spec_from_file_location("controller_ci_harness", SOURCE)
harness = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(harness)


class ControllerCiHarnessTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name).resolve()
        self.old_umask = os.umask(0o077)
        self.addCleanup(os.umask, self.old_umask)

    def terminal_record(self, accepted=False):
        record = harness.result_record(None)
        record.update(accepted=accepted, failure=None if accepted else "Precheck refused.",
                      expected=harness.EXPECTED[:], scope="synthetic controller components")
        if accepted:
            record.update(testsRun=10, started=harness.EXPECTED[:],
                          finished=harness.EXPECTED[:], successes=harness.EXPECTED[:])
        return record

    def root(self, name="owned", terminal=None):
        root = self.parent / name
        root.mkdir(mode=0o700)
        (root / "control").mkdir(mode=0o700)
        if terminal is None:
            terminal = json.dumps(self.terminal_record()).encode("utf-8")
        (root / "control" / "result.json").write_bytes(terminal)
        return root

    def assert_terminal_retention(self, raw, name, complete):
        root = self.root(name, raw)
        (root / "control" / "unittest.log").write_bytes(b"retained even after a malformed result")
        if complete:
            harness.retain(root)
        else:
            with self.assertRaises(RuntimeError):
                harness.retain(root)
        retention = json.loads((root / "upload" / "retention.json").read_bytes())
        self.assertEqual(complete, retention["complete"])
        self.assertEqual(complete, retention["failure"] is None)
        self.assertEqual(raw, (root / "control" / "result.json").read_bytes())
        with zipfile.ZipFile(root / "upload" / "synthetic-components.zip") as bundle:
            self.assertEqual(raw, bundle.read("control/result.json"))
            self.assertEqual(b"retained even after a malformed result", bundle.read("control/unittest.log"))

    def test_exact_ten_success_is_required_not_exit_or_count_alone(self):
        record = harness.result_record(None)
        record.update(testsRun=10, started=harness.EXPECTED[:], finished=harness.EXPECTED[:],
                      successes=harness.EXPECTED[:])
        harness.require_complete(record)
        for field in ("started", "finished", "successes"):
            for change in ("missing", "duplicate", "unknown"):
                with self.subTest(field=field, change=change):
                    broken = copy.deepcopy(record)
                    if change == "missing":
                        broken[field].pop()
                    elif change == "duplicate":
                        broken[field][-1] = broken[field][0]
                    else:
                        broken[field][-1] = "unexpected.Test.test_extra"
                    with self.assertRaises(RuntimeError):
                        harness.require_complete(broken)
        for field in ("failures", "errors", "skipped", "expectedFailures", "unexpectedSuccesses"):
            with self.subTest(field=field):
                broken = copy.deepcopy(record)
                broken[field] = ["not a pass"]
                with self.assertRaises(RuntimeError):
                    harness.require_complete(broken)
        record["testsRun"] = 9
        with self.assertRaises(RuntimeError):
            harness.require_complete(record)

    def test_precheck_calls_real_api_shapes_and_closes_descriptor(self):
        with mock.patch.object(harness.sys, "platform", "linux"), \
                mock.patch.object(harness.sys, "version_info", (3, 11)), \
                mock.patch.object(harness.os, "pidfd_open", return_value=123) as opened, \
                mock.patch.object(harness.os, "waitid", side_effect=ChildProcessError) as waited, \
                mock.patch.object(harness.os, "close") as closed:
            harness.precheck()
            opened.assert_called_once_with(os.getpid())
            waited.assert_called_once_with(os.P_PIDFD, 123, os.WEXITED | os.WNOWAIT | os.WNOHANG)
            closed.assert_called_once_with(123)

    def test_unsupported_or_denied_platform_is_failure_not_skip(self):
        with mock.patch.object(harness.sys, "version_info", (3, 10)):
            with self.assertRaises(RuntimeError):
                harness.precheck()
        with mock.patch.object(harness.sys, "platform", "win32"):
            with self.assertRaises(RuntimeError):
                harness.precheck()
        with mock.patch.object(harness.os, "pidfd_open", side_effect=PermissionError("denied")):
            with self.assertRaises(PermissionError):
                harness.precheck()
        with mock.patch.object(harness.os, "pidfd_open", return_value=123), \
                mock.patch.object(harness.os, "waitid", side_effect=OSError("unsupported")), \
                mock.patch.object(harness.os, "close") as closed:
            with self.assertRaises(OSError):
                harness.precheck()
            closed.assert_called_once_with(123)

    def test_precheck_failure_retains_explicit_zero_execution_and_refuses_reuse(self):
        root = self.parent / "new"
        with mock.patch.object(harness, "precheck", side_effect=RuntimeError("unsupported")):
            with self.assertRaisesRegex(RuntimeError, "unsupported"):
                harness.run(root)
        raw = (root / "control" / "result.json").read_bytes()
        record = json.loads(raw)
        self.assertFalse(record["accepted"])
        self.assertEqual(0, record["testsRun"])
        self.assertEqual([], record["started"])
        self.assertIn("unsupported", record["failure"])
        self.assertFalse((root / "synthetic").exists())
        with self.assertRaises(FileExistsError):
            harness.run(root)
        self.assertEqual(raw, (root / "control" / "result.json").read_bytes())
        harness.retain(root)
        self.assertTrue(json.loads((root / "upload" / "retention.json").read_bytes())["complete"])
        partial = self.parent / "interrupted"
        partial.mkdir(mode=0o700)
        (partial / "control").mkdir(mode=0o700)
        (partial / "control" / "unittest.log").write_bytes(b"original partial output")
        with self.assertRaisesRegex(RuntimeError, "Missing complete test outcome"):
            harness.retain(partial)
        self.assertFalse(json.loads((partial / "upload" / "retention.json").read_bytes())["complete"])
        with zipfile.ZipFile(partial / "upload" / "synthetic-components.zip") as bundle:
            self.assertEqual(b"original partial output", bundle.read("control/unittest.log"))
        failed_test = self.terminal_record()
        failed_test.update(testsRun=1, started=harness.EXPECTED[:1], finished=harness.EXPECTED[:1],
                           errors=[{"id": harness.EXPECTED[0], "detail": "Original test exception."}])
        for name, value in (("valid-precheck", self.terminal_record()),
                            ("valid-test-failure", failed_test),
                            ("valid-success", self.terminal_record(True))):
            with self.subTest(terminal=name):
                self.assert_terminal_retention(json.dumps(value).encode("utf-8"), name, True)
        invalid = {
            "empty": b"", "truncated": b'{"accepted":', "array": b"[]",
            "missing-fields": b'{"accepted":false}', "malformed": b"{not-json}",
            "invalid-encoding": b'{"failure":"\xff"}',
        }
        for field, value in (("accepted", "false"), ("testsRun", False), ("testsRun", 0.0),
                             ("failure", None), ("expected", []), ("started", [17]),
                             ("errors", [{"id": "setUpClass"}]),
                             ("successes", harness.EXPECTED[:1])):
            broken = self.terminal_record()
            broken[field] = value
            invalid["invalid-" + field + "-" + str(len(invalid))] = json.dumps(broken).encode("utf-8")
        missing = self.terminal_record()
        del missing["finished"]
        invalid["missing-finished"] = json.dumps(missing).encode("utf-8")
        false_success = self.terminal_record()
        false_success.update(accepted=True, failure=None)
        invalid["false-success"] = json.dumps(false_success).encode("utf-8")
        original = json.dumps(self.terminal_record()).encode("utf-8")
        invalid["duplicate-field"] = original[:-1] + b',"accepted":false}'
        for name, raw in invalid.items():
            with self.subTest(terminal=name):
                self.assert_terminal_retention(raw, name, False)

    def test_retention_keeps_exact_bytes_and_only_symlink_metadata(self):
        root = self.root()
        directory = root / "synthetic" / harness.METHODS[9]
        directory.mkdir(mode=0o700, parents=True)
        # A target outside the owned namespace must never be read or bundled.
        target = self.parent / "not-owned-secret"
        target.write_bytes(b"never retain this target")
        (directory / "linked.json").symlink_to(target)
        original = b'{"input":{},"number":1.0}\x00\xff\n'
        (directory / "oversized.json").write_bytes(original)
        harness.retain(root)
        record = json.loads((root / "upload" / "retention.json").read_bytes())
        self.assertTrue(record["complete"])
        link = next(entry for entry in record["entries"] if entry["type"] == "symlink")
        self.assertEqual(str(target), link["target"])
        self.assertFalse(link["content_retained"])
        with zipfile.ZipFile(root / "upload" / "synthetic-components.zip") as bundle:
            self.assertEqual(original, bundle.read(directory.relative_to(root).as_posix() + "/oversized.json"))
            self.assertNotIn(link["path"], bundle.namelist())
            self.assertNotIn("not-owned-secret", bundle.namelist())
        self.assertTrue((directory / "linked.json").is_symlink())
        self.assertEqual(original, (directory / "oversized.json").read_bytes())
        with self.assertRaises(RuntimeError):
            harness.retain(root)

    def test_retention_rejects_unknown_paths_instead_of_broad_globbing(self):
        root = self.root()
        (root / "private-producer").mkdir(mode=0o700)
        with self.assertRaisesRegex(RuntimeError, "Unexpected capture-root"):
            harness.retain(root)
        self.assertFalse((root / "upload").exists())

    def test_retention_rejects_hardlinks_and_keeps_failure_record(self):
        root = self.root()
        os.link(root / "control" / "result.json", root / "control" / "startup.json")
        with self.assertRaisesRegex(RuntimeError, "hardlinked"):
            harness.retain(root)
        record = json.loads((root / "upload" / "retention.json").read_bytes())
        self.assertFalse(record["complete"])
        self.assertIn("hardlinked", record["failure"])
        self.assertTrue((root / "control" / "startup.json").exists())

    def test_existing_link_cannot_become_a_capture_root(self):
        target = self.parent / "target"
        target.mkdir(mode=0o700)
        root = self.parent / "linked"
        root.symlink_to(target, target_is_directory=True)
        with self.assertRaises(FileExistsError):
            harness.run(root)
        self.assertEqual([], list(target.iterdir()))


if __name__ == "__main__":
    unittest.main()
