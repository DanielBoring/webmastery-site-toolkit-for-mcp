"""Protocol regressions for an exact pending-3.0 original production package."""

import hashlib
import importlib.util
import json
import os
import pathlib
import subprocess
import sys
import zipfile

SPEC = importlib.util.spec_from_file_location(
    "released_assessment", pathlib.Path(__file__).with_name("adapter-release-assessment.py")
)
RELEASED = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RELEASED)
SHA = "115b577bf59e4d3c33b0ccc78d9317e09efab3e1"
PREFIX = "webmastery-site-toolkit-for-mcp/"


def canonical(wire, code, reason=None):
    result = wire["result"]
    if result.get("isError") is not True or result.get("structuredContent") is not None:
        raise AssertionError("Canonical error requires isError and no structured payload.")
    if len(result["content"]) != 1 or result["content"][0]["type"] != "text":
        raise AssertionError("Canonical error requires exactly one text block.")
    value = RELEASED.payload(wire)
    error = value["error"]
    if (set(value) != {"success", "error"} or value["success"] is not False or
            set(error) != {"code", "reason", "message", "details"} or
            error["code"] != code or not isinstance(error["details"], dict) or
            not isinstance(error["message"], str) or not error["message"] or
            not isinstance(error["reason"], str) or not error["reason"] or
            (reason is not None and error["reason"] != reason)):
        raise AssertionError("Unexpected canonical error: " + str(value))
    return value


def verify_package(repo, archive):
    root = pathlib.Path(repo)
    commit = subprocess.check_output(["git", "-C", str(root), "rev-parse", "HEAD"], text=True).strip()
    if commit != SHA:
        raise RuntimeError("Candidate source commit differs.")
    tracked = subprocess.check_output(
        ["git", "-C", str(root), "ls-tree", "-r", "--name-only", SHA, "--", "includes"], text=True
    ).splitlines()
    expected = set(tracked) | {"webmastery-site-toolkit-for-mcp.php", "readme.txt", "LICENSE"}
    with zipfile.ZipFile(archive) as package:
        count = 0
        observed = set()
        for member in package.namelist():
            if member.endswith("/"):
                continue
            relative = member.removeprefix("webmastery-site-toolkit-for-mcp/")
            if relative == member or ".." in pathlib.PurePosixPath(relative).parts:
                raise RuntimeError("Unexpected package member.")
            original = subprocess.check_output(["git", "-C", str(root), "show", SHA + ":" + relative])
            if original != package.read(member):
                raise RuntimeError("Packaged file differs from exact Git blob: " + relative)
            if relative in observed:
                raise RuntimeError("Duplicate package member.")
            observed.add(relative)
            count += 1
        if observed != expected:
            raise RuntimeError("Package production inventory differs from exact snapshot.")
    return {"candidate_commit": SHA, "production_files_verified": count,
            "package_sha256": hashlib.sha256(pathlib.Path(archive).read_bytes()).hexdigest()}


def run(fixture_path, output, manifest_path):
    fixture = json.loads(pathlib.Path(fixture_path).read_text())
    manifest = json.loads(pathlib.Path(manifest_path).read_text())
    expected = {case["ability"] for case in manifest}
    names = set(fixture["abilities"])
    if expected != names or len(names) != 85:
        raise RuntimeError("Candidate registration must match the current 85-ability manifest.")
    url = os.environ["WSTM_ASSESSMENT_URL"]
    RELEASED.validate_target(url, fixture["owner"], os.environ["WSTM_PRODUCT_TOKEN"])
    records, cases = [], []
    revisions = ["2024-11-05", "2025-06-18", "2025-11-25"]
    if fixture["adapter"] == "0.7.0":
        revisions.append("2026-07-28")

    def require(condition, message):
        if not condition:
            raise AssertionError(message)

    def check(label, callback):
        try:
            callback()
        except (AssertionError, KeyError, ValueError) as error:
            cases.append({"case": label, "passed": False, "error": str(error)})
        else:
            cases.append({"case": label, "passed": True})

    def success(wire):
        require("result" in wire and not wire["result"].get("isError", False), str(wire))
        return RELEASED.payload(wire)

    complete = False
    try:
        for revision in revisions:
            clients = {}
            for role, actor in fixture["actors"].items():
                for direct, route in ((False, "mcp/mcp-adapter-default-server"),
                                      (True, "assessment/individual")):
                    client = RELEASED.Client(url + "/?rest_route=/" + route, actor, revision, records)
                    clients[role, direct] = client
                    def start(client=client):
                        wire = client.start()
                        require("result" in wire, str(wire))
                        if revision != "2026-07-28":
                            require(wire["result"]["protocolVersion"] == revision, "Negotiation mismatch.")
                    check(f"{revision}/{role}/{direct}/handshake", start)

            gateway = clients["administrator", False]
            individual = clients["administrator", True]
            def inventory():
                advertised = gateway.request("tools/list")["result"]["tools"]
                require({tool["name"] for tool in advertised} == {
                    "mcp-adapter-discover-abilities", "mcp-adapter-get-ability-info",
                    "mcp-adapter-execute-ability"}, "Gateway inventory mismatch.")
                abilities = success(gateway.call("mcp-adapter-discover-abilities", {}))["abilities"]
                require({a["name"] for a in abilities if a["name"].startswith(PREFIX)} == expected,
                        "Current manifest discovery mismatch.")
            check(revision + "/manifest-discovery", inventory)
            for name in sorted(expected):
                def info(name=name):
                    value = success(gateway.call("mcp-adapter-get-ability-info", {"ability_name": name}))
                    require(value["name"] == name, "Ability-info identity mismatch.")
                    require(value["meta"]["annotations"] == fixture["abilities"][name],
                            "Ability-info annotations differ from native registration.")
                check(revision + "/info/" + name, info)

            def projection():
                tools = individual.request("tools/list")["result"]["tools"]
                require({t["name"] for t in tools} == {n.replace("/", "-") for n in expected},
                        "Candidate tool rejected by selected schema.")
                for tool in tools:
                    native = fixture["abilities"][next(n for n in expected if n.replace("/", "-") == tool["name"])]
                    require(tool["inputSchema"].get("type") == "object", "Tool root must be object.")
                    for key, hint in (("readonly", "readOnlyHint"), ("destructive", "destructiveHint"),
                                      ("idempotent", "idempotentHint")):
                        require(isinstance(tool["annotations"][hint], bool) and
                                tool["annotations"][hint] == native[key], "Annotation bool mismatch.")
            check(revision + "/all-manifest-projections", projection)

            for direct in (False, True):
                admin = clients["administrator", direct]
                subscriber = clients["subscriber", direct]

                def execute(client, name, parameters):
                    if direct:
                        return client.call((PREFIX + name).replace("/", "-"), parameters)
                    return client.call("mcp-adapter-execute-ability", {
                        "ability_name": PREFIX + name, "parameters": parameters})

                def logical(wire):
                    value = success(wire)
                    return value if direct else value["data"]

                label = f"{revision}/{'individual' if direct else 'gateway'}"
                def reads():
                    value = logical(execute(admin, "get-site-info", {}))
                    require(value["success"] is True, "Site info failed.")
                    value = logical(execute(admin, "get-environment-info", {}))
                    require(value["data"]["wordpress_version"] == fixture["wordpress"], "Runtime differs.")
                    canonical(execute(subscriber, "get-environment-info", {}), "forbidden")
                    canonical(execute(admin, "get-post", {"post_id": 2147483647}), "not_found")
                check(label + "/read-permission-callback-errors", reads)

                def strict_and_mutation():
                    post = fixture["post"]
                    title = label
                    logical(execute(admin, "update-post", {"post_id": post, "title": title}))
                    for parameters in (
                        {"post_id": post, "title": ["invalid"]},
                        {"post_id": post, "title": "not written", "typo": True},
                        {"post_id": post, "title": "not written", "parent": 0},
                        {"post_id": post, "status": "publish!"},
                    ):
                        canonical(execute(admin, "update-post", parameters), "invalid_input")
                    canonical(execute(subscriber, "update-post", {"post_id": post, "title": "denied"}), "forbidden")
                    value = logical(execute(admin, "get-post", {"post_id": post}))
                    require(value["data"]["title"] == title and value["data"]["status"] == "draft",
                            "Invalid/denied write mutated draft.")
                    require("content" in value["data"]["untrusted_fields"], "Full record content marker missing.")
                check(label + "/strict-input-persisted-no-write", strict_and_mutation)

                def windows():
                    value = logical(execute(admin, "list-posts", {
                        "status": "draft", "per_page": 1, "page": 1}))
                    data = value["data"]
                    require(set(("items", "page", "per_page", "next_page")) <= set(data) and
                            "total" not in data and "total_pages" not in data, "Bounded contract mismatch.")
                    require(all("content" not in item and "content" not in item.get("untrusted_fields", [])
                                for item in data["items"]), "Summary leaked content.")
                    full = logical(execute(admin, "list-posts", {
                        "status": "draft", "per_page": 1, "page": 1, "fields": "full"}))
                    require(all("content" in item and "content" in item["untrusted_fields"]
                                for item in full["data"]["items"]), "Full content/marker missing.")
                    canonical(execute(admin, "list-posts", {"fields": "FULL"}), "invalid_input")
                    canonical(execute(admin, "list-posts", {"page": None}), "invalid_input")
                check(label + "/bounded-summary-full", windows)

                def destructive():
                    post = fixture["post"]
                    before = logical(execute(admin, "get-post", {"post_id": post}))["data"]
                    for parameters in ({"ids": [post]}, {"ids": [post], "confirm": False},
                                       {"ids": [post], "confirm": "true"},
                                       {"ids": [post] * 101, "confirm": True}):
                        canonical(execute(admin, "bulk-trash-posts", parameters), "invalid_input")
                    canonical(execute(subscriber, "bulk-trash-posts",
                                      {"ids": [post], "confirm": True}), "forbidden")
                    preview = logical(execute(admin, "bulk-trash-posts",
                                              {"ids": [post], "confirm": True, "dry_run": True}))
                    require(preview["data"]["dry_run"] is True and preview["data"]["success_count"] == 1,
                            "Confirmed preview failed.")
                    after = logical(execute(admin, "get-post", {"post_id": post}))["data"]
                    require(before == after, "Destructive guard modified full record.")
                check(label + "/destructive-confirmation-no-write", destructive)

            check(revision + "/anonymous-denial", lambda: require(
                "result" not in gateway.request("tools/list", anonymous=True), "Anonymous accepted."))
            if fixture["adapter"] == "0.7.0":
                check(revision + "/batch-rejection", lambda: require(
                    gateway.request("tools/list", batch=True)["error"]["code"] == -32600, "Batch accepted."))
            if revision == "2026-07-28":
                def modern():
                    for record in records:
                        request = record["request"]
                        if (record["revision"] == revision and isinstance(request, dict) and
                                request["method"] == "tools/call" and "result" in record["wire"]):
                            result = record["wire"]["result"]
                            require(result["resultType"] == "complete" and
                                    isinstance(result["_meta"]["io.modelcontextprotocol/serverInfo"], dict),
                                    "Modern wire fields missing.")
                check(revision + "/modern-results", modern)
        complete = True
    finally:
        summary = {key: fixture[key] for key in ("wordpress", "php", "plugin", "adapter", "abilities")}
        summary.update({"candidate_commit": SHA, "package_sha256": os.environ["WSTM_ASSESSMENT_PACKAGE_SHA256"],
                        "manifest_sha256": hashlib.sha256(pathlib.Path(manifest_path).read_bytes()).hexdigest(),
                        "manifest_cases": len(manifest), "complete": complete,
                        "cases": cases, "responses": records, "passed": sum(c["passed"] for c in cases),
                        "failed": sum(not c["passed"] for c in cases)})
        with pathlib.Path(output).open("x", encoding="utf-8") as stream:
            json.dump(summary, stream, indent=2)
        print(json.dumps({k: summary[k] for k in ("candidate_commit", "adapter", "complete", "passed", "failed")}))
        for case in cases:
            if not case["passed"]:
                print(json.dumps(case))
    return int(bool(summary["failed"]))


if __name__ == "__main__":
    if sys.argv[1] == "--verify-package":
        print(json.dumps(verify_package(*sys.argv[2:])))
    else:
        sys.exit(run(*sys.argv[1:]))
