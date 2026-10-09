"""Synthetic launcher regressions, not WordPress ownership/numeric acceptance."""

import importlib.util
import json
import os
from pathlib import Path
import sys
import unittest
from unittest import mock


SOURCE = Path(__file__).resolve().parents[1] / "e2e" / "bounded-list-recovery.py"
SPEC = importlib.util.spec_from_file_location("bounded_recovery", SOURCE)
recovery = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(recovery)
c = recovery.controller


@unittest.skipUnless(sys.platform.startswith("linux") and hasattr(os, "pidfd_open"),
                     "Recovery process tests require Linux pidfd.")
class RecoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = Path(os.environ["WSTM_RECOVERY_TEST_ARTIFACTS"])
        if not cls.root.is_absolute():
            raise RuntimeError("Explicit private native Linux artifact root required.")
        cls.root.mkdir(mode=0o700)
        os.umask(0o077)

    def setUp(self):
        self.root = self.root / self._testMethodName
        self.root.mkdir(mode=0o700)
        self.evidence = self.root / "w121_0123456789ab"
        self.control = self.root / "w121_0123456789ab.controller"
        self.evidence.mkdir(mode=0o700)
        self.control.mkdir(mode=0o700)
        (self.root / "wp").mkdir(mode=0o700)
        (self.root / "wp" / "wp-load.php").write_text("synthetic only")
        (self.root / "receipt.json").write_text('{ "synthetic": true }\n')
        receipt = {"path": str(self.root / "receipt.json"),
                   "sha256": c.digest(self.root / "receipt.json")}
        self.environment = {
            "namespace": self.evidence.name, "shard": "posts", "source_sha": "a" * 40,
            "php": sys.executable, "php_sha256": c.digest(sys.executable),
            "wp_load": str(self.root / "wp" / "wp-load.php"),
            "wp_load_sha256": c.digest(self.root / "wp" / "wp-load.php"),
            "storage_receipt": receipt, "private_durability_receipt": receipt,
            "owned_storage_roots": [str(self.root)], "artifact_root": str(self.root),
            "whole_job_bytes": 10 * 1024**3, "cleanup_reserve_bytes": 64 * 1024**2,
        }
        self.environment_path = self.control / "environment.json"
        self.environment_path.write_text(json.dumps(self.environment))
        for name in ("storage_receipt", "private_durability_receipt"):
            (self.control / (name + ".original.json")).write_bytes((self.root / "receipt.json").read_bytes())
        (self.control / "invocation.lock").write_bytes(b"")
        (self.control / "php-write-bytes.log").write_bytes(b"100\n")
        state = {"namespace": self.evidence.name, "token": "original-token",
                 "sources": {"sha256": {"tests/e2e/bounded-list-controller.py":
                                       c.digest(recovery.SOURCE)}}}
        (self.evidence / "state.json").write_text(json.dumps(state))
        (self.evidence / "cleanup.json").write_bytes(b'{ "original_failure": true }\n')
        (self.evidence / "cleanup-readback.json").write_bytes(b'{ "original_remaining": 1 }\n')
        (self.evidence / "failure.json").write_bytes(b'{ "failure": "interrupted" }\n')
        (self.control / "outcome.json").write_bytes(b'{ "failure": "original" }\n')
        self.originals = {path: path.read_bytes() for root in (self.evidence, self.control)
                          for path in root.rglob("*") if path.is_file()}
        self.worker = self.root / "synthetic-worker.py"
        self.worker.write_text(
            "import json,os,pathlib,sys\n"
            "out=pathlib.Path(os.environ['WSTM_BOUNDED_RECOVERY_OUTPUT'])\n"
            "control=out.parent\n"
            "mode=sys.argv[1]\n"
            "print('original synthetic '+mode,flush=True)\n"
            "assert os.environ['WSTM_BOUNDED_NAMESPACE']=='w121_0123456789ab'\n"
            "assert os.environ['WSTM_BOUNDED_CLEANUP_PHASE']=='1'\n"
            "ceiling=int(os.environ['WSTM_BOUNDED_PHP_BYTE_CEILING'])\n"
            "assert 0<ceiling<8*1024**3\n"
            "if os.environ.get('WSTM_SYNTHETIC_FAIL')=='1': sys.exit(7)\n"
            "data={'passed':True} if mode=='cleanup' else "
            "{'remaining':dict.fromkeys(['posts','actor','actor_meta','control'],0),'snapshot':{}}\n"
            "raw=json.dumps(data).encode()\n"
            "with (control/'php-write-bytes.log').open('ab') as f: f.write(str(len(raw)).encode()+b'\\n')\n"
            "with (out/(mode+'.json')).open('xb') as f: f.write(raw)\n")
        self.real_process = c.run_process

    def invoke(self, fail=False):
        args = [str(self.environment_path), c.digest(self.environment_path),
                c.digest(self.evidence / "state.json"),
                c.digest(self.control / "php-write-bytes.log")]

        def synthetic(argv, *arguments):
            return self.real_process([sys.executable, str(self.worker), argv[-1]], *arguments)

        with mock.patch.object(c, "run_process", side_effect=synthetic), \
                mock.patch.dict(os.environ, {"WSTM_SYNTHETIC_FAIL": "1" if fail else "0"}):
            return recovery.recover(*args)

    def assert_originals(self):
        for path, raw in self.originals.items():
            if path.name == "php-write-bytes.log":
                self.assertTrue(path.read_bytes().startswith(raw))
            else:
                self.assertEqual(raw, path.read_bytes())
        self.assertFalse((self.control / "custody.json").exists())
        self.assertFalse((self.evidence / "execution.json").exists())

    def test_successful_cleanup_is_never_successful_shard(self):
        outcome = self.invoke()
        self.assertFalse(outcome["shard_success"])
        self.assertEqual({"cleanup", "cleanup-readback"}, set(outcome["phases"]))
        self.assert_originals()

    def test_failed_attempt_retry_preserves_every_original_and_capture(self):
        with self.assertRaisesRegex(RuntimeError, "Recovery failed"):
            self.invoke(True)
        failed = self.control / "recovery-1"
        before = {path: path.read_bytes() for path in failed.rglob("*") if path.is_file()}
        self.assertIn("original synthetic cleanup", (failed / "cleanup" / "stdout.log").read_text())
        self.assertIsNotNone(c.original(failed / "outcome.json")["failure"])
        self.invoke()
        self.assertTrue((self.control / "recovery-2" / "outcome.json").exists())
        for path, raw in before.items():
            self.assertEqual(raw, path.read_bytes())
        self.assert_originals()

    def test_original_identity_or_ownership_drift_refuses_before_process(self):
        args = [str(self.environment_path), c.digest(self.environment_path),
                c.digest(self.evidence / "state.json"), c.digest(self.control / "php-write-bytes.log")]
        (self.evidence / "state.json").write_text('{"namespace":"foreign"}')
        with mock.patch.object(c, "run_process") as process:
            with self.assertRaisesRegex(RuntimeError, "state/write journal drift"):
                recovery.recover(*args)
            process.assert_not_called()

    def test_live_controller_refuses_recovery(self):
        import fcntl
        with (self.control / "invocation.lock").open("r+b") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):
                self.invoke()

    def test_bounded_deadline_failure_keeps_captures_and_allows_separate_retry(self):
        with mock.patch.dict(c.PHASE_SECONDS, {"cleanup": 0}):
            with self.assertRaisesRegex(RuntimeError, "Recovery failed"):
                self.invoke()
        receipt = c.original(self.control / "recovery-1" / "cleanup" / "receipt.json")
        self.assertIsNone(receipt["pid"])
        self.assertIn("Deadline", receipt["failure"])
        self.invoke()
        self.assert_originals()

    def test_environment_and_journal_hash_drift_refuse(self):
        args = [str(self.environment_path), c.digest(self.environment_path),
                c.digest(self.evidence / "state.json"), c.digest(self.control / "php-write-bytes.log")]
        self.environment_path.write_text(self.environment_path.read_text() + "\n")
        with self.assertRaisesRegex(RuntimeError, "environment drift"):
            recovery.recover(*args)
        args[1] = c.digest(self.environment_path)
        (self.control / "php-write-bytes.log").write_bytes(b"100\n1\n")
        with self.assertRaisesRegex(RuntimeError, "state/write journal drift"):
            recovery.recover(*args)

    def test_incomplete_journal_never_resets_or_launches(self):
        (self.control / "php-write-bytes.log").write_bytes(b"100\n22")
        with mock.patch.object(c, "run_process") as process:
            with self.assertRaisesRegex(RuntimeError, "Incomplete"):
                self.invoke()
            process.assert_not_called()
        self.assertEqual(b"100\n22", (self.control / "php-write-bytes.log").read_bytes())

    def test_invalid_numeric_reservations_never_launch(self):
        for value in (b"0\n", b"01\n", b"33554433\n"):
            (self.control / "php-write-bytes.log").write_bytes(value)
            with mock.patch.object(c, "run_process") as process:
                with self.assertRaises(RuntimeError):
                    self.invoke()
                process.assert_not_called()
            self.assertEqual(value, (self.control / "php-write-bytes.log").read_bytes())

    def test_source_drift_refuses_before_launch(self):
        state_path = self.evidence / "state.json"
        state = c.original(state_path)
        state["sources"]["sha256"]["tests/e2e/bounded-list-controller.py"] = "0" * 64
        state_path.write_text(json.dumps(state))
        with self.assertRaisesRegex(RuntimeError, "Immutable source drift"):
            self.invoke()

    def test_retained_owner_receipt_drift_refuses(self):
        (self.control / "storage_receipt.original.json").write_text("{}")
        with self.assertRaisesRegex(RuntimeError, "owner receipt drift"):
            self.invoke()

    def test_linked_or_public_namespace_refuses(self):
        (self.evidence / "linked").symlink_to(self.root / "receipt.json")
        with self.assertRaisesRegex(RuntimeError, "Linked"):
            self.invoke()
        (self.evidence / "linked").unlink()
        self.evidence.chmod(0o755)
        with self.assertRaisesRegex(RuntimeError, "private"):
            self.invoke()

    def test_quota_exhaustion_and_completed_custody_refuse(self):
        (self.control / "php-write-bytes.log").write_text("33554432\n" * 256)
        with self.assertRaisesRegex(RuntimeError, "capacity exhausted"):
            self.invoke()
        (self.control / "custody.json").write_text("{}")
        with self.assertRaisesRegex(RuntimeError, "Completed custody"):
            self.invoke()


if __name__ == "__main__":
    unittest.main()
