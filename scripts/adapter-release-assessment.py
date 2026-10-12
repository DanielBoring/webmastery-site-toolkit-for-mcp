"""Probe only the loopback endpoint of the owned released-package assessment."""

import base64
import json
import os
import pathlib
import sys
import zipfile
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        raise RuntimeError("Assessment HTTP redirects are forbidden.")


class Client:
    def __init__(self, url, actor, revision, records):
        self.url = url
        self.actor = actor
        self.revision = revision
        self.records = records
        self.session = None
        self.next_id = 0
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def request(self, method, params=None, batch=False, anonymous=False):
        self.next_id += 1
        params = {} if params is None else dict(params)
        if self.revision == "2026-07-28":
            params["_meta"] = {
                "io.modelcontextprotocol/protocolVersion": self.revision,
                "io.modelcontextprotocol/clientCapabilities": {},
                "io.modelcontextprotocol/clientInfo": {"name": "release-assessment", "version": "1"},
            }
        message = {"jsonrpc": "2.0", "id": self.next_id, "method": method, "params": params}
        body = [message] if batch else message
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "MCP-Protocol-Version": self.revision,
        }
        if not anonymous:
            credential = self.actor["login"] + ":" + self.actor["password"]
            headers["Authorization"] = "Basic " + base64.b64encode(credential.encode()).decode()
        if self.session:
            headers["Mcp-Session-Id"] = self.session
        if self.revision == "2026-07-28":
            headers["Mcp-Method"] = method
            if method == "tools/call":
                headers["Mcp-Name"] = params["name"]
        request = urllib.request.Request(self.url, json.dumps(body).encode(), headers)
        try:
            response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            raw = response.read(2 * 1024 * 1024 + 1).decode()
            if len(raw.encode()) > 2 * 1024 * 1024:
                raise RuntimeError("Assessment response exceeds 2 MiB.")
            self.session = response.headers.get("Mcp-Session-Id", self.session)
            try:
                wire = json.loads(raw)
            except json.JSONDecodeError:
                events = [line[5:].strip() for line in raw.splitlines() if line.startswith("data:")]
                if len(events) != 1:
                    raise RuntimeError("Expected one JSON-RPC response: " + raw[:200])
                wire = json.loads(events[0])
            self.records.append({
                "route": urllib.parse.urlsplit(self.url).query,
                "actor": "anonymous" if anonymous else self.actor["login"],
                "revision": self.revision, "request": body,
                "status": response.status, "wire": wire, "raw": raw,
            })
        return wire

    def start(self):
        if self.revision == "2026-07-28":
            return self.request("server/discover")
        return self.request("initialize", {
            "protocolVersion": self.revision, "capabilities": {},
            "clientInfo": {"name": "release-assessment", "version": "1"},
        })

    def call(self, name, arguments):
        return self.request("tools/call", {"name": name, "arguments": arguments})


def payload(wire):
    result = wire["result"]
    texts = [block["text"] for block in result["content"] if block["type"] == "text"]
    if len(texts) != 1:
        raise AssertionError("Expected exactly one JSON text content block.")
    value = json.loads(texts[0])
    if "structuredContent" in result and result["structuredContent"] is not None:
        if result["structuredContent"] != value:
            raise AssertionError("Text/structured content mismatch.")
    return value


def error_text(wire):
    result = wire["result"]
    if result.get("isError") is not True or "structuredContent" in result:
        raise AssertionError("Released error must set isError and omit structured content.")
    if len(result["content"]) != 1 or result["content"][0]["type"] != "text":
        raise AssertionError("Released error must contain one text block.")
    return result["content"][0]["text"]


def validate_target(url, owner, expected_owner):
    parsed = urllib.parse.urlsplit(url)
    if (parsed.scheme != "http" or parsed.hostname != "127.0.0.1" or
            not parsed.port or parsed.path or parsed.query or parsed.fragment or parsed.username or
            not expected_owner or owner != expected_owner):
        raise RuntimeError("Only the owned loopback installation is permitted.")


def run(fixture_path, output):
    fixture = json.loads(pathlib.Path(fixture_path).read_text())
    url = os.environ["WSTM_ASSESSMENT_URL"]
    validate_target(url, fixture["owner"], os.environ["WSTM_PRODUCT_TOKEN"])
    if fixture["plugin"] != "2.6.0" or fixture["adapter"] not in ("0.6.1", "0.7.0"):
        raise RuntimeError("Assessment must use the exact released plugin/Adapter versions.")
    records, cases = [], []
    names = set(fixture["abilities"])
    if len(names) != 75:
        raise RuntimeError("Expected the 75-ability released inventory on a native installation.")
    prefix = "webmastery-site-toolkit-for-mcp/"

    def check(label, callback):
        try:
            callback()
        except (AssertionError, KeyError, ValueError) as error:
            cases.append({"case": label, "passed": False, "error": str(error)})
        else:
            cases.append({"case": label, "passed": True})

    def require(condition, message):
        if not condition:
            raise AssertionError(message)

    def success(wire):
        require("result" in wire and not wire["result"].get("isError", False), str(wire))
        return payload(wire)

    revisions = ["2024-11-05", "2025-06-18", "2025-11-25"]
    if fixture["adapter"] == "0.7.0":
        revisions.append("2026-07-28")
    complete = False
    try:
        for revision in revisions:
            clients = {}
            for role, actor in fixture["actors"].items():
                for route in ("mcp/mcp-adapter-default-server", "assessment/individual"):
                    client = Client(url + "/?rest_route=/" + route, actor, revision, records)
                    # Query routing works on the fresh installation without rewrite-rule setup.
                    clients[role, route] = client
                    def handshake(client=client):
                        wire = client.start()
                        require("result" in wire, str(wire))
                        if revision != "2026-07-28":
                            require(wire["result"]["protocolVersion"] == revision, "Protocol negotiation changed.")
                        else:
                            require(isinstance(wire["result"]["_meta"]["io.modelcontextprotocol/serverInfo"], dict),
                                    "Missing sessionless server metadata.")
                    check(revision + "/" + role + "/" + route + "/handshake", handshake)

            gateway = clients["administrator", "mcp/mcp-adapter-default-server"]
            subscriber = clients["subscriber", "mcp/mcp-adapter-default-server"]
            individual = clients["administrator", "assessment/individual"]
            denied_individual = clients["subscriber", "assessment/individual"]

            def gateway_list():
                wire = gateway.request("tools/list")
                require({t["name"] for t in wire["result"]["tools"]} == {
                    "mcp-adapter-discover-abilities", "mcp-adapter-get-ability-info",
                    "mcp-adapter-execute-ability",
                }, "Default gateway tools changed.")
            check(revision + "/gateway-tools", gateway_list)

            def discovery():
                found = success(gateway.call("mcp-adapter-discover-abilities", {}))["abilities"]
                require({a["name"] for a in found if a["name"].startswith(prefix)} == names,
                        "Released ability inventory differs from MCP discovery.")
            check(revision + "/discovery", discovery)

            for name in sorted(names):
                def info(name=name):
                    value = success(gateway.call("mcp-adapter-get-ability-info", {"ability_name": name}))
                    require(value["name"] == name, "Ability info lost the name.")
                    if name == prefix + "get-site-info":
                        expected = [] if fixture["adapter"] == "0.6.1" else {}
                        require(value["input_schema"] == expected, "Unexpected no-input schema representation.")
                check(revision + "/info/" + name, info)

            def projection():
                tools = individual.request("tools/list")["result"]["tools"]
                require({t["name"] for t in tools} == {name.replace("/", "-") for name in names},
                        "One or more released individual tools were rejected.")
                by_name = {t["name"]: t for t in tools}
                for name, annotations in fixture["abilities"].items():
                    actual = by_name[name.replace("/", "-")]["annotations"]
                    for native, mcp in (("readonly", "readOnlyHint"), ("destructive", "destructiveHint"),
                                        ("idempotent", "idempotentHint")):
                        require(actual[mcp] == annotations[native], name + ": annotation mismatch.")
            check(revision + "/all-individual-projections-and-annotations", projection)

            def execute(client, name, parameters, direct=False):
                if direct:
                    return client.call((prefix + name).replace("/", "-"), parameters)
                return client.call("mcp-adapter-execute-ability", {
                    "ability_name": prefix + name, "parameters": parameters,
                })

            for direct, allowed, denied in ((False, gateway, subscriber),
                                            (True, individual, denied_individual)):
                boundary = "individual" if direct else "gateway"
                def read(allowed=allowed, direct=direct):
                    value = success(execute(allowed, "get-site-info", {}, direct))
                    if not direct:
                        require(value["success"] is True, "Gateway success missing.")
                        value = value["data"]
                    require(value["success"] is True and isinstance(value["data"]["blog_name"], str),
                            "Released success payload changed.")
                check(revision + "/" + boundary + "/success-shape", read)

                def permitted(allowed=allowed, direct=direct):
                    value = success(execute(allowed, "get-environment-info", {}, direct))
                    if not direct:
                        value = value["data"]
                    require(value["success"] is True and value["data"]["wordpress_version"] == fixture["wordpress"],
                            "Administrator permission or runtime result failed.")
                check(revision + "/" + boundary + "/admin-allowed", permitted)

                def refusal(denied=denied, direct=direct):
                    wire = execute(denied, "get-environment-info", {}, direct)
                    require(error_text(wire) == "Requires manage_options capability.", "Wrong permission error.")
                check(revision + "/" + boundary + "/subscriber-denied", refusal)

                def invalid(allowed=allowed, direct=direct):
                    wire = execute(allowed, "get-post", {"post_id": "not-an-integer"}, direct)
                    require(wire.get("result", {}).get("isError") is True or "error" in wire,
                            "Malformed input was accepted.")
                check(revision + "/" + boundary + "/invalid-input", invalid)

                def missing(allowed=allowed, direct=direct):
                    wire = execute(allowed, "get-post", {"post_id": 2147483647}, direct)
                    require(error_text(wire) == "Post not found.", "Missing-post error lost.")
                check(revision + "/" + boundary + "/callback-error", missing)

                def mutation(allowed=allowed, denied=denied, direct=direct):
                    title = revision + "-" + boundary
                    success(execute(allowed, "update-post", {"post_id": fixture["post"], "title": title}, direct))
                    refused = execute(denied, "update-post",
                                      {"post_id": fixture["post"], "title": "forbidden"}, direct)
                    require(bool(error_text(refused)), "Subscriber mutation denial missing.")
                    invalid = execute(allowed, "update-post",
                                      {"post_id": fixture["post"], "title": ["invalid"]}, direct)
                    require(invalid.get("result", {}).get("isError") is True or "error" in invalid,
                            "Invalid mutation accepted.")
                    value = success(execute(allowed, "get-post", {"post_id": fixture["post"]}, direct))
                    if not direct:
                        value = value["data"]
                    require(value["data"]["title"] == title, "Denied or invalid mutation changed the draft.")
                check(revision + "/" + boundary + "/write-denial-invalid-no-write", mutation)

            def anonymous():
                wire = gateway.request("tools/list", anonymous=True)
                require("result" not in wire, "Anonymous access was allowed.")
            check(revision + "/anonymous-denied", anonymous)

            if fixture["adapter"] == "0.7.0":
                def batch():
                    wire = gateway.request("tools/list", batch=True)
                    require(wire["error"]["code"] == -32600, "Batch not rejected as invalid request.")
                check(revision + "/batch-rejected", batch)
            if revision == "2026-07-28":
                def modern_results():
                    for record in records:
                        request = record["request"]
                        if (record["revision"] == revision and isinstance(request, dict) and
                                request["method"] == "tools/call" and "result" in record["wire"]):
                            result = record["wire"]["result"]
                            require(result["resultType"] == "complete", "Missing complete result type.")
                            require(isinstance(result["_meta"]["io.modelcontextprotocol/serverInfo"], dict),
                                    "Missing per-request server information.")
                check(revision + "/modern-tool-result-fields", modern_results)
        complete = True
    finally:
        summary = {key: fixture[key] for key in ("wordpress", "php", "plugin", "adapter", "abilities")}
        summary.update({"complete": complete, "cases": cases, "passed": sum(c["passed"] for c in cases),
                        "failed": sum(not c["passed"] for c in cases), "responses": records})
        with pathlib.Path(output).open("x", encoding="utf-8") as stream:
            json.dump(summary, stream, indent=2)
        print(json.dumps({k: summary[k] for k in ("plugin", "adapter", "complete", "passed", "failed")}))
        for case in cases:
            if not case["passed"]:
                print(json.dumps(case))
    return 1 if summary["failed"] else 0


if __name__ == "__main__":
    if sys.argv[1] == "--verify-packages":
        with zipfile.ZipFile(sys.argv[2]) as github, zipfile.ZipFile(sys.argv[3]) as wordpress:
            left = {name: github.read(name) for name in github.namelist() if not name.endswith("/")}
            right = {name: wordpress.read(name) for name in wordpress.namelist() if not name.endswith("/")}
            if left != right:
                raise RuntimeError("GitHub and WordPress.org production files differ.")
            print(json.dumps({"identical_production_files": len(left)}))
    else:
        sys.exit(run(*sys.argv[1:]))
