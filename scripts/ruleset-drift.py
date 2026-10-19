#!/usr/bin/env python3
"""Compare live rulesets and the release environment with the repository files.

Rulesets are compared with .github/rulesets/*.json and deployment environments
with .github/environments/*.json.

Failure it prevents: a ruleset changed (or emptied) in the GitHub UI while the
docs and the release process still assume the documented protection. On
2026-10-16 `main-ci-gates` sat with no required checks after a rename; nothing
reported it.

Each file in the rulesets directory is the desired state of one ruleset, named
after it. The check fails when a ruleset exists on only one side, or when the
target, enforcement, conditions, bypass actors, rule types or any parameter
written in the file differ from the live ruleset. Parameters GitHub returns
that the file does not mention are ignored, so a new GitHub default does not
fail the check; add the key to the file to start checking it. Lists are
compared without regard to order.

GitHub returns `bypass_actors` only to repository admins, so the workflow
token cannot see them. When a live ruleset has no `bypass_actors` field the
check skips that comparison and says so; run the script locally as an admin
to check bypass actors too.

Each file in the environments directory is the desired state of one
environment: admin bypass, required reviewers and self-review, wait timer,
the deployment branch policy and the allowed branch or tag patterns. Any
other protection rule, including an enabled custom GitHub App rule (read from
the separate deployment_protection_rules endpoint), is reported as drift. A missing environment is drift: GitHub would recreate it with no
protection the next time a job deploys to it. Secret names are not compared;
the workflow token cannot list them.

Usage:
  python3 scripts/ruleset-drift.py [--repo OWNER/REPO] [--dir DIR] [--live FILE]
                                   [--environments-dir DIR] [--live-environments FILE]

--live reads a JSON array of rulesets, and --live-environments a JSON object of
{name: {"environment": ..., "policies": ..., "custom_rules": ...}} API responses,
instead of calling
`gh api`.
"""

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

FIELDS = ("name", "target", "enforcement", "conditions", "rules", "bypass_actors")
ENV_FIELDS = ("name", "can_admins_bypass", "required_reviewers", "wait_timer",
              "deployment_branch_policy", "deployment_policies")
MISSING = object()


def norm(value):
    """Canonical form: dict keys sorted, lists sorted, so order never matters."""
    if isinstance(value, dict):
        return {key: norm(value[key]) for key in sorted(value)}
    if isinstance(value, list):
        return sorted((norm(item) for item in value), key=lambda item: json.dumps(item, sort_keys=True))
    return value


def show(value):
    return "(missing)" if value is MISSING else json.dumps(value, sort_keys=True)


def rules_by_type(ruleset, where):
    out = {}
    for rule in ruleset.get("rules") or []:
        kind = rule.get("type")
        if not isinstance(kind, str) or kind in out:
            raise ValueError(f"{where}: missing or duplicate rule type {kind!r}")
        out[kind] = rule.get("parameters") or {}
    return out


def load_desired(directory):
    desired = {}
    for path in sorted(Path(directory).glob("*.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(data, dict):
            raise ValueError(f"{path.name}: expected a JSON object")
        unknown = set(data) - set(FIELDS)
        missing = set(FIELDS) - set(data)
        if unknown or missing:
            raise ValueError(f"{path.name}: unknown keys {sorted(unknown)}, missing keys {sorted(missing)}")
        if data["name"] != path.stem:
            raise ValueError(f"{path.name}: name {data['name']!r} must match the file name")
        rules_by_type(data, path.name)
        desired[data["name"]] = data
    if not desired:
        raise ValueError(f"no ruleset files in {directory}")
    return desired


def compare(desired, live):
    """Return a list of human-readable differences; empty means no drift."""
    findings = []
    for name in sorted(set(desired) - set(live)):
        findings.append(f"{name}: in the repository files but not live")
    for name in sorted(set(live) - set(desired)):
        findings.append(f"{name}: live but has no file in .github/rulesets/")
    for name in sorted(set(desired) & set(live)):
        want, have = desired[name], live[name]
        for field in ("target", "enforcement", "conditions", "bypass_actors"):
            if field == "bypass_actors" and field not in have:
                continue  # not visible to this token; reported by unverified_bypass()
            expected, actual = norm(want[field]), norm(have.get(field, MISSING))
            if expected != actual:
                findings.append(f"{name}: {field} expected {show(expected)}, live {show(actual)}")
        want_rules = rules_by_type(want, name)
        have_rules = rules_by_type(have, f"live {name}")
        for kind in sorted(set(want_rules) - set(have_rules)):
            findings.append(f"{name}: rule {kind} missing live")
        for kind in sorted(set(have_rules) - set(want_rules)):
            findings.append(f"{name}: live rule {kind} is not in the file")
        for kind in sorted(set(want_rules) & set(have_rules)):
            for key, value in sorted(want_rules[kind].items()):
                expected = norm(value)
                actual = norm(have_rules[kind][key]) if key in have_rules[kind] else MISSING
                if expected != actual:
                    findings.append(f"{name}: {kind}.{key} expected {show(expected)}, live {show(actual)}")
    return findings


def unverified_bypass(desired, live):
    """Rulesets whose bypass actors this token cannot see, so were not compared."""
    return sorted(name for name in set(desired) & set(live) if "bypass_actors" not in live[name])


def load_desired_environments(directory):
    desired = {}
    for path in sorted(Path(directory).glob("*.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(data, dict) or set(data) != set(ENV_FIELDS):
            raise ValueError(f"{path.name}: environment files need exactly the keys {list(ENV_FIELDS)}")
        if data["name"] != path.stem:
            raise ValueError(f"{path.name}: name {data['name']!r} must match the file name")
        desired[data["name"]] = data
    return desired


def environment_state(environment, policies, custom_rules=None):
    """Reduce the environment, deployment-branch-policies and deployment_protection_rules
    responses to the file's shape.

    `environment` is None when GitHub answered 404 (the environment does not exist).
    """
    if environment is None:
        return None
    state = {"name": environment["name"], "wait_timer": 0, "required_reviewers": None, "other_rules": []}
    if "can_admins_bypass" in environment:
        state["can_admins_bypass"] = environment["can_admins_bypass"]
    for rule in environment.get("protection_rules") or []:
        kind = rule.get("type")
        if kind == "required_reviewers":
            reviewers = []
            for entry in rule.get("reviewers") or []:
                who = entry.get("reviewer") or {}
                reviewers.append({"type": entry.get("type"), "login": who.get("login") or who.get("slug")})
            state["required_reviewers"] = {"prevent_self_review": rule.get("prevent_self_review"), "reviewers": reviewers}
        elif kind == "wait_timer":
            state["wait_timer"] = rule.get("wait_timer", 0)
        elif kind != "branch_policy":  # branch_policy is described by deployment_branch_policy
            state["other_rules"].append(kind)
    for rule in (custom_rules or {}).get("custom_deployment_protection_rules") or []:
        if rule.get("enabled", True):
            state["other_rules"].append("custom app " + str((rule.get("app") or {}).get("slug") or rule.get("id")))
    state["deployment_branch_policy"] = environment.get("deployment_branch_policy")
    state["deployment_policies"] = [
        {"type": item.get("type", "branch"), "name": item.get("name")}
        for item in (policies or {}).get("branch_policies") or []
    ]
    return state


def compare_environments(desired, live):
    """Differences between desired environments and live states (None = missing live)."""
    findings = []
    for name in sorted(desired):
        have = live.get(name)
        if have is None:
            findings.append(f"environment {name}: missing live (a deploying job would recreate it without protection)")
            continue
        for field in ENV_FIELDS[1:]:
            if field == "can_admins_bypass" and field not in have:
                continue  # not returned to this token; reported by unverified_environment_fields()
            expected, actual = norm(desired[name][field]), norm(have.get(field, MISSING))
            if expected != actual:
                findings.append(f"environment {name}: {field} expected {show(expected)}, live {show(actual)}")
        for kind in sorted(have["other_rules"]):
            findings.append(f"environment {name}: live protection rule {kind} is not in the file")
    return findings


def unverified_environment_fields(desired, live):
    return sorted(f"{name}.can_admins_bypass" for name in desired
                  if live.get(name) is not None and "can_admins_bypass" not in live[name])


def gh_json(path):
    """GET a GitHub API path; return None for 404, raise for anything else."""
    result = subprocess.run(["gh", "api", path], text=True, capture_output=True)
    if result.returncode == 0:
        return json.loads(result.stdout)
    if "HTTP 404" in result.stderr:
        return None
    raise ValueError(f"gh api {path} failed: {result.stderr.strip() or result.returncode}")


def fetch_environments(repo, names):
    live = {}
    for name in names:
        environment = gh_json(f"repos/{repo}/environments/{name}")
        policies = custom = None
        if environment:
            policies = gh_json(f"repos/{repo}/environments/{name}/deployment-branch-policies?per_page=100")
            custom = gh_json(f"repos/{repo}/environments/{name}/deployment_protection_rules")
            if custom is None:
                raise ValueError(f"environment {name}: deployment_protection_rules not found")
        live[name] = {"environment": environment, "policies": policies, "custom_rules": custom}
    return live


def fetch_live(repo):
    def gh(path):
        return json.loads(subprocess.check_output(["gh", "api", path], text=True))

    rulesets = []
    for summary in gh(f"repos/{repo}/rulesets?per_page=100&includes_parents=false"):
        rulesets.append(gh(f"repos/{repo}/rulesets/{summary['id']}"))
    return rulesets


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY", "DanielBoring/webmastery-site-toolkit-for-mcp"))
    parser.add_argument("--dir", default=".github/rulesets")
    parser.add_argument("--live", help="JSON array of live rulesets (skips the GitHub API)")
    parser.add_argument("--environments-dir", default=".github/environments")
    parser.add_argument("--live-environments", help="JSON object of live environment responses (skips the GitHub API)")
    args = parser.parse_args(argv)

    try:
        desired = load_desired(args.dir)
        rulesets = json.loads(Path(args.live).read_text(encoding="utf-8")) if args.live else fetch_live(args.repo)
        live = {}
        for ruleset in rulesets:
            if ruleset["name"] in live:
                raise ValueError(f"two live rulesets are named {ruleset['name']!r}")
            live[ruleset["name"]] = ruleset
        findings = compare(desired, live)
        unverified = unverified_bypass(desired, live)
        desired_envs = load_desired_environments(args.environments_dir)
        if args.live_environments:
            raw_envs = json.loads(Path(args.live_environments).read_text(encoding="utf-8"))
        else:
            raw_envs = fetch_environments(args.repo, sorted(desired_envs))
        live_envs = {name: environment_state(raw_envs.get(name, {}).get("environment"), raw_envs.get(name, {}).get("policies"),
                                             raw_envs.get(name, {}).get("custom_rules"))
                     for name in desired_envs}
        env_findings = compare_environments(desired_envs, live_envs)
        env_unverified = unverified_environment_fields(desired_envs, live_envs)
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError, json.JSONDecodeError) as error:
        print(f"ERROR {error}", file=sys.stderr)
        return 2

    if findings:
        report = ["Ruleset drift: live rulesets differ from .github/rulesets/.", ""]
        report += [f"- {line}" for line in findings]
        report += ["", "Apply the file in Settings > Rules > Rulesets, or update the file in a PR if the live change is intended."]
    else:
        report = [f"No ruleset drift: {len(desired)} rulesets match .github/rulesets/."]
    if unverified:
        report += ["", "Bypass actors not checked (only visible to repository admins): " + ", ".join(unverified) + ".",
                   "Run `python3 scripts/ruleset-drift.py` locally as an admin to check them."]
    report.append("")
    if env_findings:
        report += ["Environment drift: live environments differ from .github/environments/.", ""]
        report += [f"- {line}" for line in env_findings]
        report += ["", "Apply the file in Settings > Environments, or update the file in a PR if the live change is intended."]
    else:
        report.append(f"No environment drift: {len(desired_envs)} environment(s) match .github/environments/.")
    if env_unverified:
        report.append("Not returned to this token, so not checked: " + ", ".join(env_unverified) + ".")
    findings = findings + env_findings
    text = "\n".join(report)
    print(text)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write("## Ruleset and environment audit\n\n" + text + "\n")
    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main())
