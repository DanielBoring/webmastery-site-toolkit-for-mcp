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
ENVIRONMENTS = ROOT / ".github" / "environments"
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


def environment_response():
    """GET environment + GET deployment-branch-policies, shaped like GitHub's documented responses."""
    return {
        "environment": {
            "id": 1, "node_id": "x", "name": "wordpress-org", "can_admins_bypass": False,
            "protection_rules": [
                {"id": 2, "type": "required_reviewers", "prevent_self_review": False,
                 "reviewers": [{"type": "User", "reviewer": {"login": "DanielBoring", "id": 5}}]},
                {"id": 3, "type": "branch_policy"},
            ],
            "deployment_branch_policy": {"protected_branches": False, "custom_branch_policies": True},
        },
        "policies": {"total_count": 1, "branch_policies": [{"id": 9, "node_id": "y", "name": "v*", "type": "tag"}]},
    }


def write_live(tmp, rulesets, environments):
    rules_file, env_file = Path(tmp, "live.json"), Path(tmp, "envs.json")
    rules_file.write_text(json.dumps(rulesets), encoding="utf-8")
    env_file.write_text(json.dumps(environments), encoding="utf-8")
    return ["--live", str(rules_file), "--dir", str(RULESETS),
            "--live-environments", str(env_file), "--environments-dir", str(ENVIRONMENTS)]


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

    def test_bypass_actors_hidden_from_non_admin_tokens_are_skipped_and_named(self):
        # GitHub omits bypass_actors for tokens without admin access, such as GITHUB_TOKEN.
        for ruleset in self.live.values():
            del ruleset["bypass_actors"]
        self.assertEqual([], drift.compare(self.desired, self.live))
        self.assertEqual(sorted(self.desired), drift.unverified_bypass(self.desired, self.live))
        with tempfile.TemporaryDirectory() as tmp:
            out = io.StringIO()
            with redirect_stdout(out):
                self.assertEqual(0, drift.main(write_live(tmp, list(self.live.values()), {"wordpress-org": environment_response()})))
            self.assertIn("Bypass actors not checked", out.getvalue())
        self.live["main-ci-gates"]["rules"] = []
        self.assertEqual(["main-ci-gates: rule required_status_checks missing live"],
                         drift.compare(self.desired, self.live))

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
        envs = {"wordpress-org": environment_response()}
        with tempfile.TemporaryDirectory() as tmp:
            with redirect_stdout(io.StringIO()):
                self.assertEqual(0, drift.main(write_live(tmp, list(self.live.values()), envs)))
            with redirect_stdout(io.StringIO()):
                self.assertEqual(1, drift.main(write_live(tmp, list(self.live.values())[1:], envs)))
            args = write_live(tmp, list(self.live.values()), envs)
            Path(args[1]).write_text("not json", encoding="utf-8")
            with redirect_stderr(io.StringIO()):
                self.assertEqual(2, drift.main(args))


class EnvironmentDriftTest(unittest.TestCase):
    def setUp(self):
        self.desired = drift.load_desired_environments(ENVIRONMENTS)
        self.raw = environment_response()

    def findings(self):
        live = {"wordpress-org": drift.environment_state(self.raw["environment"], self.raw["policies"])}
        return drift.compare_environments(self.desired, live)

    def test_matching_environment_has_no_drift(self):
        self.assertEqual([], self.findings())

    def test_missing_environment_is_reported(self):
        # GitHub recreates a missing environment, unprotected, when a job deploys to it.
        self.raw["environment"] = None
        self.assertEqual(1, len(self.findings()))
        self.assertIn("missing live", self.findings()[0])

    def test_loosened_protection_is_reported(self):
        env = self.raw["environment"]
        env["can_admins_bypass"] = True
        env["protection_rules"][0]["prevent_self_review"] = True
        env["protection_rules"][0]["reviewers"] = []
        env["protection_rules"].append({"id": 4, "type": "wait_timer", "wait_timer": 30})
        env["protection_rules"].append({"id": 5, "type": "custom_app"})
        findings = "\n".join(self.findings())
        for text in ("can_admins_bypass", "required_reviewers", "wait_timer", "live protection rule custom_app"):
            self.assertIn(text, findings)

    def test_removed_reviewer_rule_is_reported(self):
        self.raw["environment"]["protection_rules"] = [{"id": 3, "type": "branch_policy"}]
        self.assertTrue(any("required_reviewers expected" in line for line in self.findings()), self.findings())

    def test_widened_deployment_policy_is_reported(self):
        self.raw["policies"]["branch_policies"][0]["name"] = "*"
        self.assertTrue(any("deployment_policies" in line for line in self.findings()))
        self.raw = environment_response()
        self.raw["environment"]["deployment_branch_policy"] = None  # "No restriction"
        self.raw["policies"] = None
        findings = "\n".join(self.findings())
        self.assertIn("deployment_branch_policy", findings)
        self.assertIn("deployment_policies", findings)

    def test_unreturned_admin_bypass_is_skipped_and_named(self):
        del self.raw["environment"]["can_admins_bypass"]
        self.assertEqual([], self.findings())
        live = {"wordpress-org": drift.environment_state(self.raw["environment"], self.raw["policies"])}
        self.assertEqual(["wordpress-org.can_admins_bypass"], drift.unverified_environment_fields(self.desired, live))

    def test_release_workflow_deploys_to_an_audited_environment(self):
        release = (WORKFLOWS / "08-release.yml").read_text(encoding="utf-8")
        names = re.findall(r"^    environment:\n      name: (\S+)$", release, re.M)
        self.assertEqual(["wordpress-org"], names)
        self.assertEqual(set(names), set(self.desired))
        wanted = self.desired["wordpress-org"]
        self.assertFalse(wanted["can_admins_bypass"])
        self.assertEqual([{"type": "tag", "name": "v*"}], wanted["deployment_policies"])


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
