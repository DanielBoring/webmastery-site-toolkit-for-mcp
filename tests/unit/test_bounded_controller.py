"""Standalone stdlib tests. Process cases require Linux, not WordPress/Docker.

Set WSTM_CONTROLLER_TEST_ARTIFACTS to a fresh, private, owned directory.
Original captures are retained, including intentionally failed process cases.
"""

import importlib.util
import json
import os
from pathlib import Path
import selectors
import sys
import time
import unittest
from unittest import mock


SOURCE = Path(__file__).resolve().parents[1] / "e2e" / "bounded-list-controller.py"
SPEC = importlib.util.spec_from_file_location("bounded_controller", SOURCE)
controller = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(controller)


@unittest.skipUnless(sys.platform.startswith("linux") and hasattr(os, "pidfd_open"),
                     "Real process controls require Linux pidfd support.")
class BoundedControllerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        destination = os.environ.get("WSTM_CONTROLLER_TEST_ARTIFACTS")
        if not destination or not Path(destination).is_absolute():
            raise RuntimeError("Explicit fresh private capture directory required.")
        cls.artifacts = Path(destination)
        cls.artifacts.mkdir(mode=0o700)

    def setUp(self):
        self.directory = self.artifacts / self._testMethodName
        self.directory.mkdir(mode=0o700)

    def run_python(self, source, seconds=5, storage_check=lambda: None):
        return controller.run_process(
            [sys.executable, "-c", source], self.directory,
            time.monotonic_ns() + int(seconds * 10**9),
            os.environ.copy(), storage_check,
        )

    def receipt(self):
        terminal = controller.original(self.directory / "terminal.json")
        receipt = controller.original(self.directory / "receipt.json")
        for field, value in terminal.items():
            self.assertEqual(value, receipt[field])
        for name in ("stdout", "stderr"):
            data = (self.directory / (name + ".log")).read_bytes()
            self.assertEqual(len(data), receipt[name + "_bytes"])
            self.assertEqual(controller.digest(self.directory / (name + ".log")), receipt[name + "_sha256"])
            self.assertEqual(receipt[name + "_received"],
                             receipt[name + "_bytes"] + receipt[name + "_diagnostic_bytes"]
                             + receipt[name + "_observed_not_retained"])
        return receipt

    def test_both_binary_pipes_are_complete_and_original(self):
        receipt, _ = self.run_python("import os; os.write(1,b'a'*200000+b'\\x00\\xff'); os.write(2,b'b'*200000)")
        self.assertEqual(200002, receipt["stdout_bytes"])
        self.assertEqual(200000, receipt["stderr_bytes"])
        self.assertTrue(receipt["root_exit_observed"])
        self.assertTrue(receipt["stdout_eof"] and receipt["stderr_eof"])
        self.assertIsNone(self.receipt()["failure"])

    def test_deadline_kills_owned_root_and_retains_failure(self):
        with self.assertRaisesRegex(RuntimeError, "Owned process failed"):
            self.run_python("import signal; signal.pause()", 0.2)
        receipt = self.receipt()
        self.assertIn("deadline", receipt["failure"].lower())
        self.assertTrue(receipt["kill_requested"])
        self.assertTrue(receipt["root_exit_observed"])
        self.assertLess(receipt["exit_code"], 0)

    def test_output_overflow_keeps_prefix_and_bounded_diagnostic(self):
        with self.assertRaisesRegex(RuntimeError, "Owned process failed"):
            self.run_python("import os; os.write(1,b'x'*(5*1024*1024))")
        receipt = self.receipt()
        self.assertTrue(receipt["truncated"])
        self.assertEqual(controller.STREAM_BYTES, receipt["stdout_bytes"])
        self.assertGreater(receipt["stdout_diagnostic_bytes"], 0)
        self.assertLessEqual(receipt["stdout_diagnostic_bytes"], controller.DIAGNOSTIC_BYTES)
        self.assertIn("Output cap exceeded", receipt["failure"])

    def test_no_launch_after_preflight_deadline_or_storage_failure(self):
        with mock.patch.object(controller.subprocess, "Popen") as launch:
            with self.assertRaises(RuntimeError):
                self.run_python("raise AssertionError('must not launch')", -1)
            launch.assert_not_called()
        self.assertIsNone(self.receipt()["pid"])

    def test_storage_failure_is_not_success_shaped(self):
        def exhausted():
            raise RuntimeError("Owned storage exhausted.")

        with mock.patch.object(controller.subprocess, "Popen") as launch:
            with self.assertRaises(RuntimeError):
                self.run_python("raise AssertionError('must not launch')", storage_check=exhausted)
            launch.assert_not_called()
        self.assertIn("Owned storage exhausted", self.receipt()["failure"])

    def test_nonzero_exit_preserves_both_streams(self):
        with self.assertRaises(RuntimeError):
            self.run_python("import os,sys; os.write(1,b'original'); os.write(2,b'failed'); sys.exit(7)")
        receipt = self.receipt()
        self.assertEqual(7, receipt["exit_code"])
        self.assertEqual(b"original", (self.directory / "stdout.log").read_bytes())
        self.assertEqual(b"failed", (self.directory / "stderr.log").read_bytes())

    def test_closed_pipe_descendant_is_terminated_with_the_owned_group(self):
        self.run_python(
            "import os,signal\n"
            "child=os.fork()\n"
            "if child==0:\n"
            " os.close(1); os.close(2); signal.pause()\n"
            "else:\n"
            " os.write(1,str(child).encode())\n"
        )
        receipt = self.receipt()
        self.assertTrue(receipt["kill_requested"])
        child = int((self.directory / "stdout.log").read_bytes())
        try:
            pidfd = os.pidfd_open(child)
        except ProcessLookupError:
            return
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(pidfd, selectors.EVENT_READ)
                self.assertTrue(selector.select(5), "Owned descendant exit was not observed.")
        finally:
            os.close(pidfd)

    def test_exclusive_records_cannot_overwrite_an_earlier_failure(self):
        path = self.directory / "original.json"
        controller.publish(path, {"failure": "preserve"})
        with self.assertRaises(FileExistsError):
            controller.publish(path, {"failure": None})
        self.assertEqual({"failure": "preserve"}, json.loads(path.read_bytes()))

    def test_external_receipt_keeps_original_typed_bytes_and_refuses_drift(self):
        source = self.directory / "approved.json"
        raw = b'{ "input": {}, "integer": 1, "float": 1.0 }\n'
        source.write_bytes(raw)
        binding = {"path": str(source), "sha256": controller.digest(source)}
        destination = self.directory / "retained.original.json"
        controller.retain_receipt(binding, destination)
        self.assertEqual(raw, destination.read_bytes())
        with self.assertRaises(FileExistsError):
            controller.retain_receipt(binding, destination)
        source.write_bytes(raw.replace(b"{}", b"[]"))
        rejected = self.directory / "drift.original.json"
        with self.assertRaisesRegex(RuntimeError, "identity drift"):
            controller.retain_receipt(binding, rejected)
        self.assertFalse(rejected.exists())

    def test_external_receipt_size_and_link_are_rejected_before_retention(self):
        source = self.directory / "oversized.json"
        source.write_bytes(b"x" * (controller.CONTROL_RECORD_BYTES + 1))
        binding = {"path": str(source), "sha256": controller.digest(source)}
        destination = self.directory / "retained.original.json"
        with self.assertRaisesRegex(RuntimeError, "size/identity"):
            controller.retain_receipt(binding, destination)
        self.assertFalse(destination.exists())
        linked = self.directory / "linked.json"
        linked.symlink_to(source)
        binding["path"] = str(linked)
        with self.assertRaisesRegex(RuntimeError, "linked"):
            controller.retain_receipt(binding, destination)
        self.assertFalse(destination.exists())


if __name__ == "__main__":
    unittest.main()
