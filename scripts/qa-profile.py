"""Opt-in diagnostic timings. Never selects tests or supplies a QA gate result."""

import argparse
from collections import Counter
from datetime import datetime
import hashlib
import json
import math
from pathlib import Path
import re
import subprocess
import sys
import time
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "tests" / "qa-suites.json"
MAX_BYTES = 32 * 1024 * 1024
RUNTIME = {
    "wordpress-contract": "composer qa:contract",
    "mcp-integration": "composer qa:e2e",
    "source-product": "composer qa:local",
    "package-product": "composer qa:package",
    "publishing": "composer qa:publishing",
    "combined-release": "composer qa:release",
}


def read_bytes(path):
    with Path(path).open("rb") as stream:
        data = stream.read(MAX_BYTES + 1)
    if len(data) > MAX_BYTES:
        raise ValueError("Profile input exceeds 32 MiB")
    return data


def inventory(root=ROOT, catalog=CATALOG):
    data = json.loads(read_bytes(catalog))
    if data["schema_version"] != 1:
        raise ValueError("Unknown suite catalog version")
    categories = data["phpunit"]
    if set(categories) != {"production-unit", "qa-tooling-unit"}:
        raise ValueError("Unknown PHPUnit category")
    files = [name for names in categories.values() for name in names]
    if len(files) != len(set(files)):
        raise ValueError("Duplicate PHPUnit classification")
    actual = {p.relative_to(root / "tests" / "unit").as_posix()
              for p in (root / "tests" / "unit").rglob("*Test.php")}
    if set(files) != actual:
        raise ValueError("Unaccounted PHPUnit files: " + repr(sorted(set(files) ^ actual)))
    python = data["python"]
    actual_python = {p.name for p in (root / "tests" / "unit").glob("test_*.py")}
    if len(python) != len(set(python)) or set(python) != actual_python:
        raise ValueError("Unaccounted or duplicate Python tests")
    scripts = json.loads(read_bytes(root / "composer.json"))["scripts"]
    if {name for name in scripts if name.startswith("test:")} != set(data["safeguards"]):
        raise ValueError("Unaccounted Composer safeguards")
    for name, command in data["safeguards"].items():
        if scripts[name] != command:
            raise ValueError("Safeguard command drift: " + name)
    if data["runtime"] != RUNTIME:
        raise ValueError("Missing, extra or changed runtime classification")
    expected_runtime = {command.split(" ", 1)[1] for command in RUNTIME.values()}
    actual_runtime = {name for name in scripts if name.startswith("qa:")}
    actual_runtime -= {"qa:static", "qa:unit"}
    if actual_runtime != expected_runtime:
        raise ValueError("Unaccounted Composer runtime entry points")
    return data


def seconds(value):
    if isinstance(value, bool):
        raise ValueError("Boolean is not a measured duration")
    number = float(value)
    if not math.isfinite(number) or number < 0:
        raise ValueError("Invalid measured duration")
    return number


def report(kind):
    return {"schema_version": 1, "kind": kind, "diagnostic_only": True}


def junit(path, source, php, scope):
    catalog = inventory()
    classes = {Path(name).stem: category
               for category, names in catalog["phpunit"].items() for name in names}
    methods = {Path(name).stem: set(re.findall(r"\bfunction\s+(test\w+)\s*\(",
               (ROOT / "tests" / "unit" / name).read_text(encoding="utf-8")))
               for names in catalog["phpunit"].values() for name in names}
    raw = read_bytes(path)
    text = raw.decode("utf-8-sig")
    if "<!DOCTYPE" in text.upper() or "<!ENTITY" in text.upper():
        raise ValueError("DTD/entity declarations are not profile input")
    tree = ET.fromstring(text)
    if tree.tag not in {"testsuites", "testsuite"}:
        raise ValueError("Expected PHPUnit JUnit XML")
    cases = []
    for index, case in enumerate(tree.iter("testcase")):
        name = case.attrib["name"].split(" with data set", 1)[0]
        classname = case.attrib["class"].split("\\")[-1]
        if classname not in classes or name not in methods[classname]:
            raise ValueError("Unclassified or unsafe PHPUnit test identity")
        if {child.tag for child in case} - {"failure", "error", "skipped", "system-out", "system-err"}:
            raise ValueError("Unknown JUnit outcome element")
        statuses = [tag for tag in ("failure", "error", "skipped") if case.find(tag) is not None]
        if len(statuses) > 1:
            raise ValueError("Ambiguous JUnit outcome")
        cases.append({"class": classname, "method": name, "case_index": index,
                      "category": classes[classname], "seconds": seconds(case.attrib["time"]),
                      "status": statuses[0] if statuses else "passed"})
    if not cases:
        raise ValueError("Empty JUnit report")
    seen = {case["class"] for case in cases}
    if scope == "full" and seen != set(classes):
        raise ValueError("Full profile is missing classified PHPUnit classes")
    result = report("phpunit")
    result.update(source_sha=source, php_version=php, scope=scope,
                  input_sha256=hashlib.sha256(raw).hexdigest(),
                  catalog_sha256=hashlib.sha256(read_bytes(CATALOG)).hexdigest(),
                  outcomes=dict(Counter(case["status"] for case in cases)),
                  missing_classes=sorted(set(classes) - seen), tests=cases)
    return result


def phase(name):
    if name in {"Run Release Package QA", "Run combined release QA"}:
        return "mixed-or-control"
    if name.startswith("Post ") or name in {
        "Complete job", "Check WordPress debug log for errors",
        "Upload immutable run-scoped validated bundle",
    } or name.startswith(("Upload ", "Stop WordPress")):
        return "cleanup-or-export"
    if name.startswith(("Set up", "Checkout", "Install ", "Cache ", "Get Composer",
                        "Verify selected PHP", "Start WordPress")):
        return "setup"
    if name.startswith(("Run ", "Gate static", "Test ")):
        return "execution"
    # A package command contains its own installation, tests and cleanup.
    return "mixed-or-control"


def jobs(path):
    raw = read_bytes(path)
    data = json.loads(raw)
    records = data["jobs"]
    if not records or data["total_count"] != len(records):
        raise ValueError("Empty or incompletely paginated Actions jobs")
    if len({job["id"] for job in records}) != len(records):
        raise ValueError("Duplicate Actions job identity")
    sources = {job["head_sha"] for job in records}
    runs = {job["run_id"] for job in records}
    attempts = {job["run_attempt"] for job in records}
    if len(sources) != 1 or len(runs) != 1 or len(attempts) != 1:
        raise ValueError("Mixed Actions source/run/attempt")
    if not all(isinstance(sha, str) and re.fullmatch(r"[0-9a-f]{40}", sha) for sha in sources):
        raise ValueError("Invalid Actions source SHA")
    measured = []
    for job in records:
        steps = []
        for step in job["steps"]:
            duration = None
            if step["started_at"] and step["completed_at"]:
                start = datetime.fromisoformat(step["started_at"].replace("Z", "+00:00"))
                end = datetime.fromisoformat(step["completed_at"].replace("Z", "+00:00"))
                duration = seconds((end - start).total_seconds())
            steps.append({"name": step["name"], "phase": phase(step["name"]),
                          "status": step["status"], "conclusion": step["conclusion"],
                          "seconds": duration})
        measured.append({"id": job["id"], "name": job["name"], "status": job["status"],
                         "conclusion": job["conclusion"], "steps": steps})
    result = report("actions-stages")
    result.update(source_sha=sources.pop(), run_id=runs.pop(), run_attempt=attempts.pop(),
                  input_sha256=hashlib.sha256(raw).hexdigest(), jobs=measured)
    return result


def write_report(path, data):
    with Path(path).open("x", encoding="utf-8") as stream:
        json.dump(data, stream, indent=2, allow_nan=False)
        stream.write("\n")


def record(args):
    if not args.command:
        raise ValueError("A command is required after --")
    # Reserve before execution: refuse occupied output instead of replacing evidence.
    status = None
    try:
        with Path(args.output).open("x", encoding="utf-8") as stream:
            started = time.perf_counter()
            completed = subprocess.run(args.command, cwd=ROOT, check=False)
            elapsed = time.perf_counter() - started
            status = completed.returncode
            data = report("command-stage")
            data.update(source_sha=args.source, lane=args.lane, suite=args.suite,
                        phase=args.phase, seconds=elapsed, exit_code=status)
            json.dump(data, stream, indent=2, allow_nan=False)
            stream.write("\n")
            stream.flush()
    except OSError:
        if status is None or status == 0:
            raise
        print("Unable to write diagnostic profile; preserving original command failure.",
              file=sys.stderr)
    return status if status >= 0 else 128 - status


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="mode", required=True)
    commands.add_parser("inventory")
    xml = commands.add_parser("junit")
    xml.add_argument("input")
    xml.add_argument("--source", required=True)
    xml.add_argument("--php", required=True)
    xml.add_argument("--scope", choices=("full", "focused"), required=True)
    xml.add_argument("--output", required=True)
    api = commands.add_parser("jobs")
    api.add_argument("input")
    api.add_argument("--output", required=True)
    stage = commands.add_parser("record")
    stage.add_argument("--source", required=True)
    stage.add_argument("--lane", required=True)
    stage.add_argument("--suite", choices=("production-unit", "qa-tooling-unit",
                       "wordpress-contract", "mcp-integration", "source-product", "package-product",
                       "publishing", "combined-release", "mixed-phpunit"), required=True)
    stage.add_argument("--phase", choices=("setup", "execution", "cleanup"), required=True)
    stage.add_argument("--output", required=True)
    stage.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    try:
        if args.mode == "inventory":
            catalog = inventory()
            print(json.dumps({"diagnostic_only": True,
                              "phpunit_files": {k: len(v) for k, v in catalog["phpunit"].items()},
                              "python_files": len(catalog["python"]), "runtime": catalog["runtime"],
                              "safeguards": catalog["safeguards"]}))
        elif args.mode == "junit":
            if not re.fullmatch(r"[0-9a-f]{40}", args.source):
                raise ValueError("Exact source SHA is required")
            write_report(args.output, junit(args.input, args.source, args.php, args.scope))
        elif args.mode == "jobs":
            write_report(args.output, jobs(args.input))
        else:
            if args.command[:1] == ["--"]:
                args.command = args.command[1:]
            if not re.fullmatch(r"[0-9a-f]{40}", args.source):
                raise ValueError("Exact source SHA is required")
            return record(args)
    except (OSError, ValueError, KeyError, ET.ParseError) as error:
        parser.error(str(error))
    return 0


if __name__ == "__main__":
    sys.exit(main())
