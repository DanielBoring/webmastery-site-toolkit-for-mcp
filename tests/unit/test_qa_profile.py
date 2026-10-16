"""Diagnostic profiles never replace tests, hide failure, or export private payloads."""

import argparse
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("qa_profile", ROOT / "scripts" / "qa-profile.py")
PROFILE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PROFILE)


class QaProfileTest(unittest.TestCase):
    def test_every_php_and_python_test_file_is_classified_once(self):
        catalog = PROFILE.inventory()
        self.assertEqual(109, sum(len(v) for v in catalog["phpunit"].values()))
        self.assertEqual(5, len(catalog["python"]))
        xml = (ROOT / "phpunit.xml.dist").read_text()
        self.assertNotIn("<directory>", xml)
        self.assertNotIn("<exclude>", xml)
        suites = PROFILE.phpunit_suites()
        self.assertEqual(sorted(catalog["phpunit"]["production-unit"]), suites["product"])
        self.assertEqual(sorted(catalog["phpunit"]["qa-tooling-unit"]), suites["qa-tooling"])

    def test_phpunit_suite_drift_fails_inventory(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            unit = root / "tests" / "unit"
            unit.mkdir(parents=True)
            (unit / "ProductTest.php").touch()
            (unit / "ToolTest.php").touch()
            catalog = root / "catalog.json"
            catalog.write_text(json.dumps({"schema_version": 1, "phpunit": {
                "production-unit": ["ProductTest.php"], "qa-tooling-unit": ["ToolTest.php"]},
                "python": []}))
            for product, tooling in (
                (["ToolTest.php"], ["ProductTest.php"]),
                (["ProductTest.php"], []),
                (["ProductTest.php", "ToolTest.php"], ["ToolTest.php"]),
            ):
                files = lambda names: "".join(f"<file>tests/unit/{n}</file>" for n in names)
                (root / "phpunit.xml.dist").write_text(
                    f'<phpunit><testsuites><testsuite name="product">{files(product)}</testsuite>'
                    f'<testsuite name="qa-tooling">{files(tooling)}</testsuite></testsuites></phpunit>')
                with self.subTest(product=product, tooling=tooling), self.assertRaises(ValueError):
                    PROFILE.inventory(root, catalog)
            (root / "phpunit.xml.dist").write_text(
                '<phpunit><testsuites><testsuite name="all"><directory>tests/unit</directory>'
                '</testsuite></testsuites></phpunit>')
            with self.assertRaises(ValueError):
                PROFILE.inventory(root, catalog)

    def test_missing_duplicate_and_new_test_files_fail_inventory(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            unit = root / "tests" / "unit"
            unit.mkdir(parents=True)
            (unit / "ExampleTest.php").touch()
            catalog = root / "catalog.json"
            for names in ([], ["ExampleTest.php", "ExampleTest.php"], ["OtherTest.php"]):
                catalog.write_text(json.dumps({"schema_version": 1,
                    "phpunit": {"production-unit": names, "qa-tooling-unit": []}, "python": []}))
                with self.assertRaises(ValueError):
                    PROFILE.inventory(root, catalog)

    def test_junit_preserves_outcomes_and_removes_labels_paths_and_messages(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "input.xml"
            path.write_text("""<testsuites><testsuite>
                <testcase class="ResponseTest" name='test_known_reasons_keep_precise_reason_and_canonical_code with data set "PRIVATE_PASSWORD"'
                 time="1.5" file="/private/site"><failure>PRIVATE_WIRE</failure></testcase>
                <testcase class="ResponseTest" name="test_unknown_external_errors_drop_code_message_and_data" time="0"><skipped>PRIVATE</skipped></testcase>
                <testcase class="ResponseTest" name="test_core_callback_exception_does_not_expose_exception_text" time="2"><error>PRIVATE</error></testcase>
                <testcase class="ResponseTest" name="test_native_permission_carrier_keeps_status_and_json_object_shape" time="3"/>
                </testsuite></testsuites>""")
            result = PROFILE.junit(path, "a" * 40, "8.4", "focused")
            self.assertEqual({"failure": 1, "skipped": 1, "error": 1, "passed": 1}, result["outcomes"])
            self.assertEqual([1.5, 0, 2, 3], [r["seconds"] for r in result["tests"]])
            self.assertNotIn("PRIVATE", json.dumps(result))
            self.assertNotIn("/private/site", json.dumps(result))
            with self.assertRaises(ValueError):
                PROFILE.junit(path, "a" * 40, "8.4", "full")

    def test_bad_xml_duration_unknown_class_and_dtd_fail(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "input.xml"
            for text in (
                '<testsuites><testcase class="ResponseTest" name="test_known_reasons_keep_precise_reason_and_canonical_code" time="nan"/></testsuites>',
                '<testsuites><testcase class="UnknownTest" name="test_x" time="1"/></testsuites>',
                '<testsuites><testcase class="ResponseTest" name="PRIVATE_PASSWORD" time="1"/></testsuites>',
                '<testsuites><testcase class="ResponseTest" name="test_known_reasons_keep_precise_reason_and_canonical_code" time="1"><unknown/></testcase></testsuites>',
                '<!DOCTYPE testsuites><testsuites/>', '<testsuites/>',
            ):
                path.write_text(text)
                with self.assertRaises(ValueError):
                    PROFILE.junit(path, "a" * 40, "8.4", "focused")
        for value in (-1, float("inf"), float("nan"), True):
            with self.assertRaises(ValueError):
                PROFILE.seconds(value)

    def test_safeguard_drift_is_not_silently_reclassified(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            unit = root / "tests" / "unit"
            unit.mkdir(parents=True)
            catalog = PROFILE.inventory()
            for files in catalog["phpunit"].values():
                for name in files:
                    (unit / name).touch()
            for name in catalog["python"]:
                (unit / name).touch()
            (root / "phpunit.xml.dist").write_text((ROOT / "phpunit.xml.dist").read_text())
            catalog_path = root / "catalog.json"
            catalog_path.write_text(json.dumps(catalog))
            composer = json.loads((ROOT / "composer.json").read_text())
            composer["scripts"]["test:ci-safeguards"].pop()
            (root / "composer.json").write_text(json.dumps(composer))
            with self.assertRaisesRegex(ValueError, "Safeguard command drift"):
                PROFILE.inventory(root, catalog_path)

    def test_runtime_inventory_is_closed_and_new_commands_cannot_disappear(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            unit = root / "tests" / "unit"
            unit.mkdir(parents=True)
            catalog = PROFILE.inventory()
            for files in catalog["phpunit"].values():
                for name in files:
                    (unit / name).touch()
            for name in catalog["python"]:
                (unit / name).touch()
            (root / "phpunit.xml.dist").write_text((ROOT / "phpunit.xml.dist").read_text())
            path = root / "catalog.json"
            original = json.loads((ROOT / "composer.json").read_text())
            composer = root / "composer.json"
            composer.write_text(json.dumps(original))
            for change in ("delete", "add", "replace"):
                current = json.loads(json.dumps(catalog))
                if change == "delete":
                    del current["runtime"]["wordpress-contract"]
                elif change == "add":
                    current["runtime"]["extra"] = "composer qa:contract"
                else:
                    current["runtime"]["wordpress-contract"] = "composer qa:e2e"
                path.write_text(json.dumps(current))
                with self.assertRaisesRegex(ValueError, "runtime classification"):
                    PROFILE.inventory(root, path)
            path.write_text(json.dumps(catalog))
            for change in ("delete", "add"):
                current = json.loads(json.dumps(original))
                if change == "delete":
                    del current["scripts"]["qa:local"]
                else:
                    current["scripts"]["qa:new-runtime"] = "new runtime"
                composer.write_text(json.dumps(current))
                with self.assertRaisesRegex(ValueError, "runtime entry points"):
                    PROFILE.inventory(root, path)

    def test_job_steps_keep_failed_skipped_and_mixed_phases(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "jobs.json"
            job = {"id": 1, "head_sha": "a" * 40, "run_id": 2, "run_attempt": 1,
                   "name": "08 - Release / validate", "status": "completed", "conclusion": "cancelled",
                   "steps": [{"name": "Build once and test the package with pinned and current Plugin Check",
                              "status": "completed", "conclusion": "cancelled",
                              "started_at": "2026-10-12T15:00:00Z", "completed_at": "2026-10-12T15:01:00Z"}]}
            path.write_text(json.dumps({"total_count": 1, "jobs": [job]}))
            result = PROFILE.jobs(path)
            self.assertEqual("cancelled", result["jobs"][0]["conclusion"])
            self.assertEqual(60, result["jobs"][0]["steps"][0]["seconds"])
            self.assertEqual("mixed-or-control", result["jobs"][0]["steps"][0]["phase"])
            path.write_text(json.dumps({"total_count": 2, "jobs": [job]}))
            with self.assertRaises(ValueError):
                PROFILE.jobs(path)
            path.write_text(json.dumps({"total_count": 2, "jobs": [
                job, dict(job, id=3, head_sha="b" * 40)
            ]}))
            with self.assertRaisesRegex(ValueError, "Mixed Actions"):
                PROFILE.jobs(path)
            self.assertEqual("setup", PROFILE.phase("Start WordPress stack"))
            self.assertEqual("cleanup-or-export", PROFILE.phase("Stop WordPress stack"))
            self.assertEqual("mixed-or-control", PROFILE.phase("Run Release Package QA"))

    def test_command_status_and_unique_evidence_are_preserved(self):
        with tempfile.TemporaryDirectory() as directory:
            output = str(Path(directory) / "stage.json")
            command = [sys.executable, str(ROOT / "scripts" / "qa-profile.py"), "record",
                       "--source", "a" * 40, "--lane", "synthetic-control",
                       "--suite", "qa-tooling-unit", "--phase", "execution", "--output", output,
                       "--", sys.executable, "-c", "raise SystemExit(23)"]
            result = subprocess.run(command, capture_output=True, check=False)
            self.assertEqual(23, result.returncode, result.stderr)
            recorded = json.loads(Path(output).read_text())
            self.assertEqual(23, recorded["exit_code"])
            original = Path(output).read_bytes()
            result = subprocess.run(command, capture_output=True, check=False)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual(original, Path(output).read_bytes())

    def test_measurement_write_error_retains_original_test_failure(self):
        stream = Mock()
        stream.__enter__ = Mock(return_value=stream)
        stream.__exit__ = Mock(return_value=False)
        stream.write.side_effect = OSError("fixture write refusal")
        args = argparse.Namespace(output="unused", command=["unused"], source="a" * 40,
                                  lane="fixture", suite="qa-tooling-unit", phase="execution")
        with patch.object(Path, "open", return_value=stream), \
                patch.object(PROFILE.subprocess, "run", return_value=Mock(returncode=23)), \
                patch.object(PROFILE.sys, "stderr"):
            self.assertEqual(23, PROFILE.record(args))
        with patch.object(Path, "open", return_value=stream), \
                patch.object(PROFILE.subprocess, "run", return_value=Mock(returncode=0)):
            with self.assertRaises(OSError):
                PROFILE.record(args)


if __name__ == "__main__":
    unittest.main()
