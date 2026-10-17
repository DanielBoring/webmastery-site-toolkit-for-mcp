#!/usr/bin/env python3
"""Compare the live repository rulesets with .github/rulesets/*.json.

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

Usage:
  python3 scripts/ruleset-drift.py [--repo OWNER/REPO] [--dir DIR] [--live FILE]

--live reads a JSON array of rulesets instead of calling `gh api`.
"""

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

FIELDS = ("name", "target", "enforcement", "conditions", "rules", "bypass_actors")
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
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError, json.JSONDecodeError) as error:
        print(f"ERROR {error}", file=sys.stderr)
        return 2

    if findings:
        report = ["Ruleset drift: live rulesets differ from .github/rulesets/.", ""]
        report += [f"- {line}" for line in findings]
        report += ["", "Apply the file in Settings > Rules > Rulesets, or update the file in a PR if the live change is intended."]
    else:
        report = [f"No ruleset drift: {len(desired)} rulesets match .github/rulesets/."]
    text = "\n".join(report)
    print(text)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write("## Ruleset audit\n\n" + text + "\n")
    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main())
