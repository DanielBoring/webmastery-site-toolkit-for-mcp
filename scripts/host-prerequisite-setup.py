"""Shared CI driver for the two explicitly approved host PHP selections."""

import importlib.util
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import unittest


if __name__ == "__main__" and (
        sys.platform != "linux" or os.getuid() != os.geteuid() or os.geteuid() == 0):
    print("WSTM HOST setup refused: platform.")
    sys.exit(78)

SPEC = importlib.util.spec_from_file_location(
    "php_setup_guard", Path(__file__).with_name("provision-php82-permissions.py"))
GUARD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GUARD)
BASE = GUARD.BASE
MARKER = "WSTM_G1G2_ACQUISITION_V1 "
PUBLIC_LIMIT = 16384
TOOL_IDS = {"setpriv": "/usr/bin/setpriv", "python": "/usr/bin/python3",
            "php": "/usr/bin/php", "dpkg-query": "/usr/bin/dpkg-query",
            "readelf": "/usr/bin/readelf", "sudo": "/usr/bin/sudo", "chmod": "/usr/bin/chmod"}
PY_FUNCTIONS = ("socketpair", "sendmsg", "recvmsg")
PY_CONSTANTS = ("SCM_RIGHTS", "SCM_CREDENTIALS", "MSG_CMSG_CLOEXEC")
PHP_FUNCTION_IDS = BASE.FUNCTIONS + ("fcntl",)
PHP_CONSTANT_IDS = BASE.CONSTANTS + ("SOCK_CLOEXEC", "FD_CLOEXEC", "F_GETFD", "F_SETFD")
require = BASE.require


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def checked_digest(value):
    require(type(value) is str and re.fullmatch(r"[a-f0-9]{64}", value) is not None,
            "inventory-shape")
    return value


def flag(value):
    require(type(value) is bool, "inventory-shape")
    return value


def unknown_probe():
    return {"state": "unknown", "exit_known": False, "exit_zero": False,
            "stdout_eof": False, "stderr_eof": False, "retention_verified": False}


def retained_phase(capture, version, expected, kind):
    require(set(capture) == {"retained", "captured"}, "inventory-shape")
    require(type(capture["retained"]) is bytes and len(capture["retained"]) <= BASE.RECEIPT_LIMIT
            and type(capture["captured"]) is dict and len(capture["captured"]) <= 10
            and sum(len(raw) for raw in capture["captured"].values()) <= BASE.RECEIPT_LIMIT,
            "retention-budget")
    index = BASE.decode_object(capture["retained"])
    for name, raw in capture["captured"].items():
        require(name in index and type(raw) is bytes
                and index[name]["sha256"] == digest(raw)
                and index[name]["bytes"] == len(raw), "retention")
    raw = capture["captured"]["inventory.private.json"]
    receipt = BASE.decode_object(raw)
    require(receipt["selected_php"] == version and receipt["kind"] == kind, "inventory-shape")
    sources = receipt["ordinary_guard_sources"]
    require(type(sources) is list and len(sources) == 3
            and {row["source_id"] for row in sources} == set(BASE.SOURCE_NAMES), "inventory-shape")
    for row in sources:
        source_id = row["source_id"]
        require(checked_digest(row["sha256"]) == expected[source_id]["sha256"]
                and row["identity"] == expected[source_id]["identity"], "tool-link")
        pin = receipt["pin_ledger"][row["canonical"]]
        require(pin["state"] == "pinned" and pin["sha256"] == row["sha256"]
                and pin["identity"] == row["identity"], "tool-link")
    events = {}
    for number, command in enumerate(receipt["commands"], 1):
        operation = command["operation"]
        if operation not in BASE.PROJECTION_OPERATIONS:
            continue
        role = BASE.PROJECTION_OPERATIONS[operation]
        require(role not in events, "inventory-shape")
        event = BASE.decode_object(capture["captured"]["command-%d.observed.private.json" % number])
        require(event == command, "retention")
        if role == "guard":
            require(event["argv"] == list(GUARD.ROOT_ARGVS[version]), "unsafe-input")
        else:
            require(event["argv"][0] == BASE.PHP_PROFILES[version]["binary"], "php-config")
        stdout = capture["captured"]["command-%d.stdout.private" % number]
        stderr = capture["captured"]["command-%d.stderr.private" % number]
        require(len(stdout) == event["stdout_bytes"] and digest(stdout) == event["stdout_sha256"]
                and len(stderr) == event["stderr_bytes"] and digest(stderr) == event["stderr_sha256"],
                "retention")
        for label in ("stdout", "stderr"):
            saved = index["command-%d.%s.private" % (number, label)]
            require(all(saved["identity"][key] == value
                        for key, value in event[label + "_identity"].items()), "retention")
        exit_value = event["exit"]
        require(exit_value is None or type(exit_value) is int, "inventory-shape")
        eof1, eof2 = flag(event["stdout_eof"]), flag(event["stderr_eof"])
        retained = not flag(event["retention_failed"])
        require(event["failure"] in (None, "incomplete"), "inventory-shape")
        complete = exit_value == 0 and eof1 and eof2 and not stderr and retained \
            and event["failure"] is None
        probe = {"state": "completed" if complete else "refused",
                 "exit_known": exit_value is not None, "exit_zero": exit_value == 0,
                 "stdout_eof": eof1, "stderr_eof": eof2, "retention_verified": retained}
        events[role] = (probe, stdout)
    return receipt, events, digest(raw)


def api_facts(events, version):
    probe, raw = events.get("php-configured", (unknown_probe(), None))
    result = {"probe": probe, "sockets": None,
              "functions": {name: None for name in PHP_FUNCTION_IDS},
              "constants": {name: None for name in PHP_CONSTANT_IDS}}
    if probe["state"] != "completed":
        return result
    query = BASE.decode_object(raw)
    require(set(query) == {"version", "binary", "sockets", "functions", "constants", "modules",
                           "extension_dir", "FFI_class", "FFI_enable"}, "inventory-shape")
    BASE.validate_php_identity(query, BASE.PHP_PROFILES[version]["binary"], version)
    require(set(query["functions"]) == set(BASE.FUNCTIONS) | {"fcntl"}
            and set(query["constants"]) == set(BASE.CONSTANTS) |
            {"SOCK_CLOEXEC", "FD_CLOEXEC", "F_GETFD", "F_SETFD"}, "inventory-shape")
    result["sockets"] = flag(query["sockets"])
    for name in query["functions"]:
        flag(query["functions"][name])
    for name in PHP_FUNCTION_IDS:
        result["functions"][name] = query["functions"][name]
    for name, value in query["constants"].items():
        require(value is None or type(value) is int, "inventory-shape")
    for name in PHP_CONSTANT_IDS:
        value = query["constants"][name]
        result["constants"][name] = value is not None and value > 0
    return result


def tool_facts(receipts):
    result = {}
    for tool_id, path in TOOL_IDS.items():
        found = []
        for receipt in receipts:
            for canonical, entry in receipt["files"].items():
                if path in entry.get("aliases", {}):
                    found.append((canonical, entry, receipt))
        fact = {"state": "unknown", "sha256": None, "root_nonsymlink": None,
                "installed_digest_matches": None, "cache_verified": None}
        if found:
            hashes = set()
            for canonical, entry, receipt in found:
                sha = checked_digest(entry["sha256"])
                info = entry["identity"]
                require(all(type(info[key]) is int for key in ("mode", "uid", "nlink")),
                        "inventory-shape")
                regular = stat.S_ISREG(info["mode"]) and info["uid"] == 0 and info["nlink"] == 1
                origin = receipt["origins"].get(canonical)
                matches = origin is not None and origin["installed_digest_matches"] is True
                pin = receipt["pin_ledger"].get(canonical)
                cache = pin is not None and pin["state"] == "pinned" \
                    and pin["sha256"] == sha and pin["identity"] == info
                require(regular and cache, "tool-identity")
                if matches:
                    hashes.add(sha)
            if not hashes:
                result[tool_id] = fact
                continue
            require(len(hashes) == 1, "tool-link")
            fact.update(state="observed", sha256=hashes.pop(), root_nonsymlink=True,
                        installed_digest_matches=True, cache_verified=True)
        result[tool_id] = fact
    return result


def origin_failure(receipts):
    failures = [receipt["origin_failure"] for receipt in receipts if "origin_failure" in receipt]
    require(len(failures) <= 1, "inventory-shape")
    if not failures:
        return None
    value = failures[0]
    require(type(value) is dict and set(value) == {"tool", "subject", "check"}
            and value["tool"] in set(BASE.ORIGIN_TOOL_IDS.values()) | {"unknown"}
            and value["subject"] in BASE.ORIGIN_SUBJECT_IDS
            and value["check"] in BASE.ORIGIN_CHECK_IDS, "inventory-shape")
    require(any(receipt.get("reason") == "tool-origin" for receipt in receipts), "inventory-shape")
    return {key: value[key] for key in ("tool", "subject", "check")}


def project(captures, version, job, expected, input_sha):
    require(version in GUARD.ROOT_ARGVS and job in GUARD.APPROVED_JOBS[version],
            "inventory-shape")
    require(set(expected) == set(BASE.SOURCE_NAMES), "inventory-shape")
    require(type(captures) is list and 1 <= len(captures) <= 2, "inventory-shape")
    receipts, phases, hashes = [], {}, {}
    for offset, capture in enumerate(captures):
        role = "guard" if offset == 0 else "inventory"
        kind = GUARD.MODE if offset == 0 else "readonly-host-inventory"
        receipt, events, sha = retained_phase(capture, version, expected, kind)
        receipts.append(receipt)
        if offset == 0:
            require(receipt["acquisition_job"] == job, "inventory-shape")
        hashes[role] = sha
        probe_id = "guard" if offset == 0 else "php-configured"
        phases[role] = events.get(probe_id, (unknown_probe(), None))[0]
        if offset == 1:
            configured = api_facts(events, version)
            bare_php = events.get("php-bare", (unknown_probe(), None))[0]
    if len(captures) == 1:
        phases["inventory"] = unknown_probe()
        configured = api_facts({}, version)
        bare_php = unknown_probe()
    tools = tool_facts(receipts)
    complete = len(captures) == 2 and all(r["reason"] is None for r in receipts) \
        and receipts[0]["state"] == "permission-postcondition-verified" \
        and receipts[1]["state"] == "inventory-complete" \
        and all(p["state"] == "completed" for p in phases.values()) \
        and bare_php["state"] == "completed" and configured["sockets"] is True \
        and all(configured["functions"][name] is True for name in BASE.FUNCTIONS) \
        and all(configured["constants"][name] is True for name in BASE.CONSTANTS) \
        and all(t["state"] == "observed" for t in tools.values())
    return {"schema": "g1g2-acquisition-v1", "state": "observed" if complete else "refused",
            "selected_php": version, "job": job,
            "sources": {name: checked_digest(expected[name]["sha256"]) for name in BASE.SOURCE_NAMES},
            "input_sha256": checked_digest(input_sha), "inventory_sha256": hashes.get("inventory"),
            "guard_inventory_sha256": hashes["guard"], "phases": phases,
            "tools": tools, "php_configured": configured, "php_bare": bare_php,
            "python_bare": {"state": "unknown",
                            "functions": {name: None for name in PY_FUNCTIONS},
                            "constants": {name: None for name in PY_CONSTANTS}},
            "not_host_or_fd_proof": True, "origin_failure": origin_failure(receipts)}


def validate_public(value):
    require(set(value) == {"schema", "state", "selected_php", "job", "sources", "input_sha256",
                          "inventory_sha256", "guard_inventory_sha256", "phases", "tools",
                          "php_configured", "php_bare", "python_bare", "not_host_or_fd_proof",
                          "origin_failure"},
            "inventory-shape")
    require(value["schema"] == "g1g2-acquisition-v1" and value["state"] in ("observed", "refused")
            and value["selected_php"] in GUARD.ROOT_ARGVS
            and value["job"] in GUARD.APPROVED_JOBS[value["selected_php"]]
            and value["not_host_or_fd_proof"] is True, "inventory-shape")
    require(set(value["sources"]) == set(BASE.SOURCE_NAMES), "inventory-shape")
    for sha in value["sources"].values():
        checked_digest(sha)
    for name in ("input_sha256", "guard_inventory_sha256"):
        checked_digest(value[name])
    if value["inventory_sha256"] is not None:
        checked_digest(value["inventory_sha256"])
    require(set(value["tools"]) == set(TOOL_IDS) and set(value["phases"]) == {"guard", "inventory"},
            "inventory-shape")
    probes = list(value["phases"].values()) + [value["php_bare"], value["php_configured"]["probe"]]
    for probe in probes:
        require(set(probe) == set(unknown_probe()) and probe["state"] in
                ("completed", "refused", "unknown"), "inventory-shape")
        for name in ("exit_known", "exit_zero", "stdout_eof", "stderr_eof", "retention_verified"):
            flag(probe[name])
        require(not probe["exit_zero"] or probe["exit_known"], "inventory-shape")
        if probe["state"] == "completed":
            require(all(probe[name] is True for name in unknown_probe() if name != "state"),
                    "inventory-shape")
        if probe["state"] == "unknown":
            require(probe == unknown_probe(), "inventory-shape")
    for fact in value["tools"].values():
        require(set(fact) == {"state", "sha256", "root_nonsymlink", "installed_digest_matches",
                             "cache_verified"} and fact["state"] in ("observed", "unknown"),
                "inventory-shape")
        if fact["state"] == "observed":
            checked_digest(fact["sha256"])
            require(all(fact[name] is True for name in
                        ("root_nonsymlink", "installed_digest_matches", "cache_verified")),
                    "inventory-shape")
        else:
            require(all(fact[name] is None for name in ("sha256", "root_nonsymlink",
                        "installed_digest_matches", "cache_verified")), "inventory-shape")
    php = value["php_configured"]
    require(set(php) == {"probe", "sockets", "functions", "constants"}
            and set(php["functions"]) == set(PHP_FUNCTION_IDS)
            and set(php["constants"]) == set(PHP_CONSTANT_IDS), "inventory-shape")
    for boolean in [php["sockets"]] + list(php["functions"].values()) + list(php["constants"].values()):
        require(boolean is None or type(boolean) is bool, "inventory-shape")
    require(value["python_bare"] == {"state": "unknown",
            "functions": {name: None for name in PY_FUNCTIONS},
            "constants": {name: None for name in PY_CONSTANTS}}, "inventory-shape")
    if value["origin_failure"] is not None:
        require(value["state"] == "refused", "inventory-shape")
        origin_failure([{"origin_failure": value["origin_failure"], "reason": "tool-origin"}])


def projection_input(captures, version, job, expected):
    encoded_size = 0
    skeleton = []
    for capture in captures:
        require(set(capture) == {"retained", "captured"} and type(capture["retained"]) is bytes
                and len(capture["retained"]) <= BASE.RECEIPT_LIMIT
                and type(capture["captured"]) is dict and len(capture["captured"]) <= 10,
                "retention-budget")
        for name, raw in capture["captured"].items():
            require(type(raw) is bytes and len(raw) <= BASE.RECEIPT_LIMIT and
                    (name == "inventory.private.json" or re.fullmatch(
                        r"command-[1-9][0-9]{0,2}\.(observed\.private\.json|stdout\.private|stderr\.private)",
                        name) is not None), "inventory-shape")
        originals = [capture["retained"]] + list(capture["captured"].values())
        encoded_size += sum(4 * ((len(raw) + 2) // 3) for raw in originals)
        skeleton.append({"retained": "", "captured": {name: "" for name in capture["captured"]}})
    header = {"selected_php": version, "job": job, "expected": expected, "captures": skeleton}
    require(encoded_size + len(json.dumps(header, sort_keys=True).encode()) <= BASE.RECEIPT_LIMIT,
            "retention-budget")
    header["captures"] = [
        {"retained": base64.b64encode(c["retained"]).decode(),
         "captured": {name: base64.b64encode(raw).decode() for name, raw in c["captured"].items()}}
        for c in captures]
    return json.dumps(header, sort_keys=True).encode()


def publish_projection(phases, version, job):
    require(1 <= len(phases) <= 2, "retention")
    owner = phases[-1]
    owner.check()
    expected = {}
    for name, filename in BASE.SOURCE_NAMES.items():
        path = str(Path(__file__).with_name(filename))
        canonical = os.path.realpath(path)
        require(canonical == os.path.abspath(path), "tool-link")
        info = os.lstat(canonical)
        BASE.validate_file(info, os.geteuid(), BASE.FILE_LIMIT)
        require(owner.bytes + info.st_size <= BASE.TOTAL_LIMIT, "file-budget")
        owner.bytes += info.st_size
        actual, observed, raw = owner.read(path, os.geteuid(), info.st_size)
        require(actual == canonical and BASE.identity(info) == BASE.identity(observed), "tool-link")
        pin = owner.pins[canonical]
        require(pin["state"] == "pinned" and pin["identity"] == BASE.identity(info)
                and pin["sha256"] == digest(raw), "tool-link")
        expected[name] = {"identity": BASE.identity(info), "sha256": digest(raw)}
    captures = [phase.projection_capture for phase in phases]
    raw_input = projection_input(captures, version, job, expected)
    require(len(raw_input) <= BASE.RECEIPT_LIMIT, "retention-budget")
    require(owner.capture_bytes + len(raw_input) + 64 <= BASE.RECEIPT_LIMIT, "retention-budget")
    owner.capture_bytes += len(raw_input) + 64
    owner.write("projection-input.private.json", raw_input)
    owner.write("projection-input.sha256.private", digest(raw_input).encode("ascii"))
    owner.check()
    _, _, retained = owner.read(str(owner.directory / "projection-input.private.json"),
                                os.geteuid(), BASE.RECEIPT_LIMIT)
    require(retained == raw_input, "retention")
    _, _, retained_hash = owner.read(str(owner.directory / "projection-input.sha256.private"),
                                     os.geteuid(), 64)
    require(retained_hash == digest(retained).encode("ascii"), "retention")
    # Only the privately retained, byte/hash-bound input is parsed for public projection.
    input_value = BASE.decode_object(retained)
    decoded = [{"retained": base64.b64decode(c["retained"], validate=True),
                "captured": {name: base64.b64decode(raw, validate=True)
                             for name, raw in c["captured"].items()}}
               for c in input_value["captures"]]
    value = project(decoded, input_value["selected_php"], input_value["job"],
                    input_value["expected"], digest(retained))
    raw_public = json.dumps(value, sort_keys=True, separators=(",", ":")).encode()
    require(len(MARKER.encode()) + len(raw_public) + 1 <= PUBLIC_LIMIT, "retention-budget")
    require(owner.capture_bytes + len(raw_public) <= BASE.RECEIPT_LIMIT, "retention-budget")
    owner.capture_bytes += len(raw_public)
    owner.write("projection.public.json", raw_public)
    _, _, published = owner.read(str(owner.directory / "projection.public.json"),
                                 os.geteuid(), PUBLIC_LIMIT)
    require(published == raw_public, "retention")
    validate_public(BASE.decode_object(published))
    owner.check()
    sys.stdout.write(MARKER + published.decode("ascii") + "\n")
    sys.stdout.flush()
    owner.check()
    return value["state"] == "observed"


def run_units():
    folder = Path(__file__).resolve().parents[1] / "tests" / "unit"
    suite = unittest.TestSuite()
    for name in ("test_host_prerequisite_inventory.py", "test_provision_php82_permissions.py",
                 "test_host_prerequisite_setup.py"):
        suite.addTests(unittest.defaultTestLoader.discover(str(folder), pattern=name))
    return 0 if unittest.TextTestRunner(verbosity=2).run(suite).wasSuccessful() else 1


def main(argv=None, retained=None):
    argv = sys.argv if argv is None else argv
    units = len(argv) == 3 and argv[1] == "units"
    if (not units and len(argv) != 2) or argv[-1] not in GUARD.ROOT_ARGVS:
        print("WSTM HOST setup refused: unsafe-input.")
        return 78
    version = argv[-1]
    if units:
        return run_units()
    environment = {
        "PATH": "/usr/bin:/bin", "LC_ALL": "C",
        "WSTM_PROVISION_ROOT": os.environ.get("WSTM_HOST_SETUP_ROOT", ""),
        "WSTM_PREREQUISITE_ROOT": os.environ.get("WSTM_HOST_SETUP_ROOT", ""),
        "WSTM_PROVISION_RUNNER": os.environ.get("WSTM_HOST_SETUP_RUNNER", ""),
        "WSTM_PROVISION_ACTIONS": os.environ.get("GITHUB_ACTIONS", ""),
        "WSTM_PROVISION_JOB": os.environ.get("GITHUB_JOB", ""),
        "WSTM_PROVISION_INTERVAL": GUARD.MODE,
    }
    os.environ.clear()
    os.environ.update(environment)
    phases = []
    result = GUARD.main(["php-setup", GUARD.MODE, version], retained=phases)
    if result == 0:
        result = BASE.main(["readonly-inventory", version], retained=phases)
    if retained is not None:
        retained.extend(phases)
    try:
        observed = publish_projection(phases, version, environment["WSTM_PROVISION_JOB"])
    except Exception:
        print("WSTM G1/G2 acquisition projection refused; private originals retained where available.")
        return 78
    return 0 if result == 0 and observed else 78


if __name__ == "__main__":
    sys.exit(main())
