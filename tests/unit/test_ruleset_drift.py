"""Tests for scripts/ruleset-drift.py and the checked-in ruleset files.

Failures these prevent: the drift check passing while a ruleset is emptied or
loosened in the UI, and a workflow rename leaving `main-ci-gates` requiring a
check name that no workflow reports any more.
"""

import copy
import importlib.util
import io
import json
import re
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RULESETS = ROOT / ".github" / "rulesets"
WORKFLOWS = ROOT / ".github" / "workflows"

spec = importlib.util.spec_from_file_location("ruleset_drift", ROOT / "scripts" / "ruleset-drift.py")
drift = importlib.util.module_from_spec(spec)
spec.loader.exec_module(drift)


def live_copy(desired):
    """Shape a desired ruleset like the API returns it: extra fields, reordered lists."""
    live = copy.deepcopy(desired)
    live.update({"id": 1, "node_id": "x", "source_type": "Repository", "current_user_can_bypass": "never"})
    for rule in live["rules"]:
        params = rule.get("parameters")
        if params:
            params["a_new_github_default"] = True
            for key, value in params.items():
                if isinstance(value, list):
                    params[key] = list(reversed(value))
    live["rules"] = list(reversed(live["rules"]))
    return live


class RulesetDriftTest(unittest.TestCase):
    def setUp(self):
        self.desired = drift.load_desired(RULESETS)
        self.live = {name: live_copy(ruleset) for name, ruleset in self.desired.items()}

    def test_matching_live_rulesets_have_no_drift(self):
        self.assertEqual([], drift.compare(self.desired, self.live))

    def test_emptied_required_checks_are_reported(self):
        self.live["main-ci-gates"]["rules"] = []
        self.assertEqual(["main-ci-gates: rule required_status_checks missing live"],
                         drift.compare(self.desired, self.live))

    def test_dropped_required_check_is_reported(self):
        params = self.live["main-ci-gates"]["rules"][0]["parameters"]
        params["required_status_checks"] = params["required_status_checks"][1:]
        findings = drift.compare(self.desired, self.live)
        self.assertEqual(1, len(findings))
        self.assertIn("required_status_checks.required_status_checks expected", findings[0])

    def test_changed_parameter_and_enforcement_are_reported(self):
        self.live["main-pull-requests"]["rules"][0]["parameters"]["allowed_merge_methods"] = ["merge", "squash"]
        self.live["main-history-protection"]["enforcement"] = "disabled"
        findings = drift.compare(self.desired, self.live)
        self.assertTrue(any("allowed_merge_methods" in line for line in findings), findings)
        self.assertTrue(any("main-history-protection: enforcement" in line for line in findings), findings)

    def test_bypass_and_rule_set_changes_are_reported(self):
        self.live["main-ci-gates"]["bypass_actors"] = [{"actor_id": 5, "actor_type": "RepositoryRole", "bypass_mode": "always"}]
        self.live["main-history-protection"]["rules"].append({"type": "required_linear_history"})
        findings = drift.compare(self.desired, self.live)
        self.assertIn("main-history-protection: live rule required_linear_history is not in the file", findings)
        self.assertTrue(any("main-ci-gates: bypass_actors" in line for line in findings), findings)

    def test_missing_and_unexpected_rulesets_are_reported(self):
        renamed = self.live.pop("main-pull-requests")
        renamed["name"] = "main-pr-no-required-approvals"
        self.live[renamed["name"]] = renamed
        self.assertEqual(
            ["main-pull-requests: in the repository files but not live",
             "main-pr-no-required-approvals: live but has no file in .github/rulesets/"],
            drift.compare(self.desired, self.live),
        )

    def test_files_must_be_named_after_their_ruleset_and_use_known_keys(self):
        with tempfile.TemporaryDirectory() as tmp:
            bad = dict(self.desired["main-ci-gates"], name="other")
            Path(tmp, "main-ci-gates.json").write_text(json.dumps(bad), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "must match the file name"):
                drift.load_desired(tmp)
            extra = dict(self.desired["main-ci-gates"], id=1)
            Path(tmp, "main-ci-gates.json").write_text(json.dumps(extra), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "unknown keys"):
                drift.load_desired(tmp)

    def test_command_line_exit_codes(self):
        with tempfile.TemporaryDirectory() as tmp:
            live_file = Path(tmp, "live.json")
            live_file.write_text(json.dumps(list(self.live.values())), encoding="utf-8")
            with redirect_stdout(io.StringIO()):
                self.assertEqual(0, drift.main(["--live", str(live_file), "--dir", str(RULESETS)]))
            live_file.write_text(json.dumps(list(self.live.values())[1:]), encoding="utf-8")
            with redirect_stdout(io.StringIO()):
                self.assertEqual(1, drift.main(["--live", str(live_file), "--dir", str(RULESETS)]))
            live_file.write_text("not json", encoding="utf-8")
            with redirect_stderr(io.StringIO()):
                self.assertEqual(2, drift.main(["--live", str(live_file), "--dir", str(RULESETS)]))


class CheckedInRulesetsTest(unittest.TestCase):
    def test_required_checks_are_reported_by_the_merge_gate_workflows(self):
        gates = drift.load_desired(RULESETS)["main-ci-gates"]
        contexts = [check["context"] for rule in gates["rules"] if rule["type"] == "required_status_checks"
                    for check in rule["parameters"]["required_status_checks"]]
        names = {}
        for path in WORKFLOWS.glob("*.yml"):
            names[path.name] = re.match(r"name: (.+)\n", path.read_text(encoding="utf-8"))[1]
        expected = [names["01-static-qa.yml"], names["02-unit-tests.yml"], names["03-04-docker-qa.yml"]]
        self.assertEqual(expected, contexts)
        for check in gates["rules"][0]["parameters"]["required_status_checks"]:
            self.assertEqual(15368, check["integration_id"])  # GitHub Actions

    def test_main_merges_are_squash_only(self):
        rule = drift.load_desired(RULESETS)["main-pull-requests"]["rules"][0]
        self.assertEqual(["squash"], rule["parameters"]["allowed_merge_methods"])


if __name__ == "__main__":
    unittest.main()
