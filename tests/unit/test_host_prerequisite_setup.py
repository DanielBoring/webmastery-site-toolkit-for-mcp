"""Three-surface driver/selection controls; no privileged execution."""

import importlib.util
import io
import copy
import json
import os
from pathlib import Path
import stat
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch


SOURCE = Path(__file__).resolve().parents[2] / "scripts" / "host-prerequisite-setup.py"
SPEC = importlib.util.spec_from_file_location("host_setup_driver", SOURCE)
DRIVER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DRIVER)
GUARD = DRIVER.GUARD
BASE = GUARD.BASE


class HostPrerequisiteSetupTest(unittest.TestCase):
    def projection_fixture(self, version="8.4"):
        expected, sources, pins = {}, [], {}
        for number, (name, filename) in enumerate(BASE.SOURCE_NAMES.items(), 10):
            identity = BASE.identity(SimpleNamespace(**dict(vars(self.info(0o644)),
                                     st_uid=1000, st_ino=number, st_size=len(name))))
            sha = DRIVER.digest(name.encode())
            path = "/private-source/" + filename
            expected[name] = {"sha256": sha, "identity": identity}
            sources.append(dict(expected[name], source_id=name, canonical=path))
            pins[path] = dict(expected[name], state="pinned")
        files, origins = {}, {}
        for name, alias in DRIVER.TOOL_IDS.items():
            canonical = "/usr/bin/php" + version if name == "php" else alias
            identity = BASE.identity(self.info(0o755))
            sha = DRIVER.digest(name.encode())
            files[canonical] = {"identity": identity, "sha256": sha, "aliases": {alias: identity}}
            origins[canonical] = {"installed_digest_matches": True, "untrusted_text": "SECRET_CANARY"}
            pins[canonical] = {"state": "pinned", "identity": identity, "sha256": sha}
        query = {"version": version + ".SECRET_CANARY", "binary": "/usr/bin/php" + version,
                 "sockets": True, "functions": {name: True for name in (*BASE.FUNCTIONS, "fcntl")},
                 "constants": {name: 1 for name in (*BASE.CONSTANTS, "SOCK_CLOEXEC",
                                                   "FD_CLOEXEC", "F_GETFD", "F_SETFD")},
                 "modules": {"SECRET_CANARY": "SECRET_CANARY"},
                 "extension_dir": "/PRIVATE_SECRET_CANARY", "FFI_class": False, "FFI_enable": ""}
        receipts = []
        for guard in (True, False):
            receipt = {"kind": GUARD.MODE if guard else "readonly-host-inventory",
                       "state": "permission-postcondition-verified" if guard else "inventory-complete",
                       "selected_php": version, "reason": None,
                       "ordinary_guard_sources": copy.deepcopy(sources), "pin_ledger": copy.deepcopy(pins),
                       "files": copy.deepcopy(files), "origins": copy.deepcopy(origins), "commands": [],
                       "acquisition_job": GUARD.APPROVED_JOBS[version][0]}
            operations = (GUARD.MODE,) if guard else ("php-bare", "php")
            streams = []
            for operation in operations:
                stdout = b"" if guard else json.dumps(query).encode()
                event = {"operation": operation, "exit": 0, "stdout_eof": True, "stderr_eof": True,
                         "retention_failed": False, "failure": None,
                         "argv": list(GUARD.ROOT_ARGVS[version]) if guard else ["/usr/bin/php" + version]}
                for label, raw in (("stdout", stdout), ("stderr", b"")):
                    event.update({label + "_bytes": len(raw), label + "_sha256": DRIVER.digest(raw),
                                  label + "_identity": BASE.identity(self.info(0o600))})
                receipt["commands"].append(event)
                streams.append((stdout, b""))
            receipts.append((receipt, streams))
        return expected, receipts

    def pack_capture(self, receipt, streams):
        captured = {"inventory.private.json": json.dumps(receipt).encode()}
        for number, (event, (stdout, stderr)) in enumerate(zip(receipt["commands"], streams), 1):
            captured["command-%d.observed.private.json" % number] = json.dumps(event).encode()
            captured["command-%d.stdout.private" % number] = stdout
            captured["command-%d.stderr.private" % number] = stderr
        index = {name: {"sha256": DRIVER.digest(raw), "bytes": len(raw),
                        "identity": BASE.identity(self.info(0o600))} for name, raw in captured.items()}
        return {"retained": json.dumps(index).encode(), "captured": captured}

    def projected(self, expected, receipts, version="8.4", job=None):
        return DRIVER.project([self.pack_capture(*r) for r in receipts], version,
                              job or GUARD.APPROVED_JOBS[version][0], expected, "a" * 64)

    def test_projection_both_versions_closed_scalars_and_no_secret_or_private_operands(self):
        for version in ("8.2", "8.4"):
            expected, receipts = self.projection_fixture(version)
            result = self.projected(expected, receipts, version)
            DRIVER.validate_public(result)
            self.assertEqual("observed", result["state"])
            self.assertLess(len(json.dumps(result)), DRIVER.PUBLIC_LIMIT)
            self.assertNotIn("SECRET_CANARY", json.dumps(result))
            self.assertNotIn("/usr", json.dumps(result))
            self.assertEqual("unknown", result["python_bare"]["state"])

    def test_projection_source_hash_identity_and_id_drift_refuse(self):
        for field, wrong in (("sha256", "b" * 64), ("identity", {}),
                             ("source_id", "arbitrary-secret-id")):
            expected, receipts = self.projection_fixture()
            receipts[0][0]["ordinary_guard_sources"][0][field] = wrong
            with self.assertRaises((BASE.Refusal, KeyError)):
                self.projected(expected, receipts)

    def test_projection_source_missing_duplicate_and_digest_paths_refuse(self):
        for replacement in ([], [{"source_id": "gate"}] * 3):
            expected, receipts = self.projection_fixture()
            receipts[0][0]["ordinary_guard_sources"] = replacement
            with self.assertRaises(BASE.Refusal):
                self.projected(expected, receipts)
        expected, receipts = self.projection_fixture()
        expected["driver"]["sha256"] = "/SECRET_CANARY"
        with self.assertRaises(BASE.Refusal):
            self.projected(expected, receipts)

    def test_projection_selected_job_source_mismatch_and_unsupported_version_refuse(self):
        expected, receipts = self.projection_fixture()
        for version, job in (("8.2", "release-package-qa"), ("8.4", "release-package-qa"),
                             ("8.0", "ability-contract-qa"), ("8.4", "../SECRET_CANARY")):
            with self.assertRaises(BASE.Refusal):
                self.projected(expected, receipts, version, job)
        receipts[0][0]["acquisition_job"] = "full-mcp-e2e-qa"
        with self.assertRaises(BASE.Refusal):
            self.projected(expected, receipts)

    def test_projection_original_unknown_nonzero_missing_eof_retention_failure_stays_refused(self):
        for field, bad in (("exit", None), ("exit", 1), ("exit", -9),
                           ("stdout_eof", False), ("stderr_eof", False),
                           ("retention_failed", True), ("failure", "incomplete")):
            expected, receipts = self.projection_fixture()
            receipts[0][0]["commands"][0][field] = bad
            receipts[0][0]["reason"] = "native-command"
            result = self.projected(expected, receipts)
            DRIVER.validate_public(result)
            self.assertEqual("refused", result["state"])
            self.assertEqual("refused", result["phases"]["guard"]["state"])

    def test_projection_missing_sockets_constants_or_functions_are_actual_false_not_pass(self):
        for section, key, bad in (("functions", BASE.FUNCTIONS[0], False),
                                  ("constants", BASE.CONSTANTS[0], None),
                                  (None, "sockets", False)):
            expected, receipts = self.projection_fixture()
            query = json.loads(receipts[1][1][1][0])
            (query[section] if section else query)[key] = bad
            raw = json.dumps(query).encode()
            receipts[1][1][1] = (raw, b"")
            receipts[1][0]["commands"][1].update(stdout_bytes=len(raw), stdout_sha256=DRIVER.digest(raw))
            receipts[1][0]["reason"] = "php-sockets"
            result = self.projected(expected, receipts)
            DRIVER.validate_public(result)
            self.assertEqual("refused", result["state"])
            self.assertIs(False, (result["php_configured"][section] if section
                                 else result["php_configured"])[key])

    def test_projection_original_php_json_malformed_duplicate_unknown_id_and_missing_boolean(self):
        for bad in (b"{", b'{"sockets":true,"sockets":false}', b"[]"):
            expected, receipts = self.projection_fixture()
            receipts[1][1][1] = (bad, b"")
            receipts[1][0]["commands"][1].update(stdout_bytes=len(bad), stdout_sha256=DRIVER.digest(bad))
            with self.assertRaises(BASE.Refusal):
                self.projected(expected, receipts)
        for change in ("unknown-id", "missing", "string", "bool-constant", "wrong-version"):
            expected, receipts = self.projection_fixture()
            query = json.loads(receipts[1][1][1][0])
            if change == "unknown-id":
                query["functions"]["SECRET_CANARY"] = True
            elif change == "missing":
                del query["functions"][BASE.FUNCTIONS[0]]
            elif change == "string":
                query["sockets"] = "true"
            elif change == "bool-constant":
                query["constants"][BASE.CONSTANTS[0]] = True
            else:
                query["version"] = "8.2.1"
            raw = json.dumps(query).encode()
            receipts[1][1][1] = (raw, b"")
            receipts[1][0]["commands"][1].update(stdout_bytes=len(raw), stdout_sha256=DRIVER.digest(raw))
            with self.assertRaises(BASE.Refusal):
                self.projected(expected, receipts)

    def test_projection_index_digest_byte_event_pipe_identity_tampering_refuses(self):
        expected, receipts = self.projection_fixture()
        original = self.pack_capture(*receipts[0])
        for kind in ("raw", "index", "event", "pipe"):
            capture = copy.deepcopy(original)
            if kind == "raw":
                capture["captured"]["command-1.stdout.private"] = b"SECRET_CANARY"
            elif kind == "index":
                index = json.loads(capture["retained"])
                index["inventory.private.json"]["bytes"] += 1
                capture["retained"] = json.dumps(index).encode()
            elif kind == "pipe":
                index = json.loads(capture["retained"])
                index["command-1.stdout.private"]["identity"]["ino"] += 1
                capture["retained"] = json.dumps(index).encode()
            else:
                capture["captured"]["command-1.observed.private.json"] = b"{}"
                index = json.loads(capture["retained"])
                index["command-1.observed.private.json"].update(bytes=2, sha256=DRIVER.digest(b"{}"))
                capture["retained"] = json.dumps(index).encode()
            with self.assertRaises(BASE.Refusal):
                DRIVER.project([capture], "8.4", GUARD.APPROVED_JOBS["8.4"][0], expected, "a" * 64)

    def test_projection_tool_origin_incomplete_unknown_not_success_and_cache_hash_owner_refuse(self):
        expected, receipts = self.projection_fixture()
        for receipt, _ in receipts:
            del receipt["origins"]["/usr/bin/setpriv"]
        result = self.projected(expected, receipts)
        self.assertEqual("unknown", result["tools"]["setpriv"]["state"])
        self.assertEqual("refused", result["state"])
        for mutation in ("owner", "hash", "cache"):
            expected, receipts = self.projection_fixture()
            entry = receipts[0][0]["files"]["/usr/bin/setpriv"]
            if mutation == "owner":
                entry["identity"]["uid"] = 1000
            elif mutation == "hash":
                entry["sha256"] = "/SECRET_CANARY"
            else:
                receipts[0][0]["pin_ledger"]["/usr/bin/setpriv"]["state"] = "reserved"
            with self.assertRaises(BASE.Refusal):
                self.projected(expected, receipts)

    def test_projection_partial_guard_does_not_invent_inventory_or_python_observations(self):
        expected, receipts = self.projection_fixture()
        result = self.projected(expected, receipts[:1])
        DRIVER.validate_public(result)
        self.assertEqual("refused", result["state"])
        self.assertIsNone(result["inventory_sha256"])
        self.assertEqual("unknown", result["phases"]["inventory"]["state"])
        self.assertIsNone(result["php_configured"]["sockets"])

    def test_public_closed_schema_unknown_ids_path_digests_and_nonboolean_flags_refuse(self):
        expected, receipts = self.projection_fixture()
        original = self.projected(expected, receipts)
        for kind in ("key", "tool", "api", "digest", "boolean", "python"):
            public = copy.deepcopy(original)
            if kind == "key":
                public["raw_secret"] = "SECRET_CANARY"
            elif kind == "tool":
                public["tools"]["SECRET_CANARY"] = {}
            elif kind == "api":
                public["php_configured"]["functions"]["unknown"] = True
            elif kind == "digest":
                public["sources"]["gate"] = "/SECRET_CANARY"
            elif kind == "boolean":
                public["phases"]["guard"]["stdout_eof"] = 1
            else:
                public["python_bare"]["state"] = "completed"
            with self.assertRaises(BASE.Refusal):
                DRIVER.validate_public(public)

    def test_projection_input_raw_truncation_and_byte_bound_refuse_without_subprocess(self):
        expected, receipts = self.projection_fixture()
        capture = self.pack_capture(*receipts[0])
        for raw in (capture["retained"][:-1], b"x" * (BASE.RECEIPT_LIMIT + 1)):
            bad = dict(capture, retained=raw)
            with patch.object(BASE.subprocess, "Popen", side_effect=AssertionError("native forbidden")):
                with self.assertRaises(BASE.Refusal):
                    DRIVER.project([bad], "8.4", GUARD.APPROVED_JOBS["8.4"][0], expected, "a" * 64)

    def test_projection_input_expanded_bound_is_checked_before_base64_acquisition(self):
        capture = {"retained": b"{}", "captured": {"inventory.private.json": b"x" * 1024}}
        with patch.object(BASE, "RECEIPT_LIMIT", 1024), \
                patch.object(DRIVER.base64, "b64encode", side_effect=AssertionError("late bound")) as encoding:
            with self.assertRaisesRegex(BASE.Refusal, "retention-budget"):
                DRIVER.projection_input([capture], "8.4", "ability-contract-qa", {})
            encoding.assert_not_called()

    def test_optional_presence_false_is_reported_without_changing_inventory_eligibility(self):
        expected, receipts = self.projection_fixture()
        query = json.loads(receipts[1][1][1][0])
        query["functions"]["fcntl"] = False
        query["constants"]["FD_CLOEXEC"] = None
        raw = json.dumps(query).encode()
        receipts[1][1][1] = (raw, b"")
        receipts[1][0]["commands"][1].update(stdout_bytes=len(raw), stdout_sha256=DRIVER.digest(raw))
        result = self.projected(expected, receipts)
        DRIVER.validate_public(result)
        self.assertEqual("observed", result["state"])
        self.assertIs(False, result["php_configured"]["functions"]["fcntl"])
        self.assertIs(False, result["php_configured"]["constants"]["FD_CLOEXEC"])

    def mock_publisher(self):
        expected, receipts = self.projection_fixture()
        private, trace, infos, pins = {}, [], {}, {}
        for name, filename in BASE.SOURCE_NAMES.items():
            path = str(SOURCE.with_name(filename))
            raw = name.encode()
            info = SimpleNamespace(**{"st_" + key: value for key, value in expected[name]["identity"].items()})
            infos[os.path.abspath(path)] = info
            private[path] = raw
            pins[os.path.abspath(path)] = dict(expected[name], state="pinned")
        owner = SimpleNamespace(directory=Path("/owned"), bytes=0, capture_bytes=0, pins=pins,
                                projection_capture=self.pack_capture(*receipts[1]))
        def check():
            trace.append("check")
        def write(name, raw):
            trace.append("write:" + name)
            private[str(owner.directory / name)] = raw
        def read(path, uid, limit):
            trace.append("read:" + Path(path).name)
            info = infos.get(os.path.abspath(path), self.info(0o600))
            return os.path.abspath(path), info, private[path]
        owner.check, owner.write, owner.read = Mock(side_effect=check), Mock(side_effect=write), Mock(side_effect=read)
        first = SimpleNamespace(projection_capture=self.pack_capture(*receipts[0]))
        return [first, owner], infos, private, trace

    def test_publisher_original_input_hash_and_public_retained_before_single_bounded_log(self):
        phases, infos, _, trace = self.mock_publisher()
        output = io.StringIO()
        with patch.object(BASE.os, "geteuid", return_value=1000, create=True), \
                patch.object(BASE.os.path, "realpath", side_effect=os.path.abspath), \
                patch.object(BASE.os, "lstat", side_effect=lambda p: infos[os.path.abspath(p)]), \
                patch.object(DRIVER.sys, "stdout", output), \
                patch.object(BASE.subprocess, "Popen", side_effect=AssertionError("native forbidden")):
            self.assertTrue(DRIVER.publish_projection(phases, "8.4", GUARD.APPROVED_JOBS["8.4"][0]))
        lines = output.getvalue().splitlines()
        self.assertEqual(1, len(lines))
        self.assertTrue(lines[0].startswith(DRIVER.MARKER))
        self.assertLess(len(output.getvalue().encode()), DRIVER.PUBLIC_LIMIT)
        self.assertNotIn("SECRET_CANARY", output.getvalue())
        self.assertLess(trace.index("write:projection-input.private.json"),
                        trace.index("write:projection.public.json"))
        self.assertLess(trace.index("read:projection-input.sha256.private"),
                        trace.index("write:projection.public.json"))
        self.assertGreater(phases[-1].bytes, 0)
        self.assertGreater(phases[-1].capture_bytes, 0)

    def test_publisher_write_read_drift_and_existing_budget_failure_emit_no_projection(self):
        for kind in ("write", "read", "source", "bytes", "capture", "deadline"):
            phases, infos, _, _ = self.mock_publisher()
            owner = phases[-1]
            if kind == "write":
                owner.write.side_effect = OSError("SECRET_CANARY")
            elif kind == "read":
                owner.read.side_effect = OSError("SECRET_CANARY")
            elif kind == "source":
                next(iter(owner.pins.values()))["sha256"] = "b" * 64
            elif kind == "bytes":
                owner.bytes = BASE.TOTAL_LIMIT
            elif kind == "capture":
                owner.capture_bytes = BASE.RECEIPT_LIMIT
            else:
                owner.check.side_effect = BASE.Refusal("inventory-deadline")
            output = io.StringIO()
            with patch.object(BASE.os, "geteuid", return_value=1000, create=True), \
                    patch.object(BASE.os.path, "realpath", side_effect=os.path.abspath), \
                    patch.object(BASE.os, "lstat", side_effect=lambda p: infos[os.path.abspath(p)]), \
                    patch.object(DRIVER.sys, "stdout", output):
                with self.assertRaises((BASE.Refusal, OSError)):
                    DRIVER.publish_projection(phases, "8.4", GUARD.APPROVED_JOBS["8.4"][0])
            self.assertEqual("", output.getvalue())

    def test_driver_projection_refusal_withholds_success_and_never_forwards_exception(self):
        for result in (0, 78):
            output = io.StringIO()
            with patch.dict(os.environ, {}, clear=True), \
                    patch.object(GUARD, "main", return_value=result), \
                    patch.object(BASE, "main", return_value=0) as inventory, \
                    patch.object(DRIVER, "publish_projection", side_effect=OSError("SECRET_CANARY")), \
                    patch.object(DRIVER.sys, "stdout", output):
                self.assertEqual(78, DRIVER.main(["setup", "8.4"]))
                self.assertNotIn("SECRET_CANARY", output.getvalue())
                self.assertNotIn(DRIVER.MARKER, output.getvalue())
                self.assertEqual(result == 0, inventory.called)

    def test_driver_units_do_not_emit_native_projection_or_call_inventory(self):
        with patch.object(DRIVER, "run_units", return_value=0), \
                patch.object(DRIVER, "publish_projection") as projection, \
                patch.object(BASE, "main") as inventory:
            self.assertEqual(0, DRIVER.main(["setup", "units", "8.4"]))
            projection.assert_not_called()
            inventory.assert_not_called()

    def info(self, mode=0o777):
        return SimpleNamespace(st_mode=stat.S_IFREG | mode, st_uid=0, st_gid=0,
                               st_nlink=1, st_size=64, st_dev=1, st_ino=2,
                               st_mtime_ns=3, st_ctime_ns=4)

    def test_exact_two_approved_argv_maps_no_version_or_environment_fallback(self):
        self.assertEqual({"8.2", "8.4"}, set(GUARD.ROOT_ARGVS))
        for version in ("8.2", "8.4"):
            targets = ("/etc/php/" + version + "/cli/php.ini",
                       "/etc/php/" + version + "/cli/conf.d/99-pecl.ini")
            guard = GUARD.Provision(version)
            self.assertEqual(targets, guard.targets)
            self.assertEqual(("/usr/bin/sudo", "-n", "--user=root", "--", "/usr/bin/chmod",
                              "--", "0644", *targets), guard.root_argv)
            self.assertEqual("/usr/bin/php" + version, guard.profile["binary"])
            other = "8.4" if version == "8.2" else "8.2"
            with self.assertRaises(GUARD.Refusal):
                GUARD.validate_setup_file(BASE.PHP_PROFILES[other]["targets"][0],
                                          self.info(), version)
        for version in ("8.0", "8.3", "8.5", "", "../8.4", "8.4; chmod"):
            with self.assertRaises(GUARD.Refusal):
                GUARD.Provision(version)
            with self.assertRaises(BASE.Refusal):
                BASE.Inventory(version)

    def test_missing_wrong_or_extra_selector_refuses_before_both_phases(self):
        with patch.object(GUARD, "main") as provision, \
                patch.object(BASE, "main") as inventory, \
                patch.object(DRIVER, "run_units") as units, \
                patch.object(DRIVER.sys, "stdout", io.StringIO()):
            for argv in ([], ["setup"], ["setup", "8.0"], ["setup", "8.4", "/arbitrary"],
                         ["setup", "units"], ["setup", "units", "8.5"]):
                self.assertEqual(78, DRIVER.main(argv))
            provision.assert_not_called()
            inventory.assert_not_called()
            units.assert_not_called()
        env = {"WSTM_PROVISION_ACTIONS": "true", "WSTM_PROVISION_RUNNER": "github-hosted",
               "WSTM_PROVISION_INTERVAL": GUARD.MODE}
        with patch.object(GUARD.sys, "platform", "linux"):
            for version in ("8.2", "8.4"):
                for job in GUARD.APPROVED_JOBS[version]:
                    selected = dict(env, WSTM_PROVISION_JOB=job)
                    self.assertEqual(version, GUARD.validate_entry(
                        ["guard", GUARD.MODE, version], selected, 1000, 1000))
                for job in ("other-job", "release-package-qa" if version == "8.4"
                            else "ability-contract-qa"):
                    with self.assertRaises(GUARD.Refusal):
                        GUARD.validate_entry(["guard", GUARD.MODE, version],
                                             dict(env, WSTM_PROVISION_JOB=job), 1000, 1000)
            with self.assertRaises(GUARD.Refusal):
                GUARD.validate_entry(["guard", GUARD.MODE], env, 1000, 1000)

    def test_shared_driver_provision_then_readonly_inventory_with_exact_same_selection(self):
        for version in ("8.2", "8.4"):
            trace = []
            environment = {"WSTM_HOST_SETUP_ROOT": "/owned-custody",
                           "WSTM_HOST_SETUP_RUNNER": "github-hosted", "GITHUB_ACTIONS": "true",
                           "GITHUB_JOB": GUARD.APPROVED_JOBS[version][0],
                           "PHPRC": "/arbitrary", "LD_PRELOAD": "callback", "WSTM_PHP_VERSION": "8.0"}
            with patch.dict(os.environ, environment, clear=True), \
                    patch.object(GUARD, "main", side_effect=lambda argv, retained:
                                 trace.append(("provision", argv)) or 0), \
                    patch.object(BASE, "main", side_effect=lambda argv, retained:
                                 trace.append(("inventory", argv)) or 0), \
                    patch.object(DRIVER, "publish_projection", return_value=True):
                self.assertEqual(0, DRIVER.main(["setup", version]))
                self.assertEqual([("provision", ["php-setup", GUARD.MODE, version]),
                                  ("inventory", ["readonly-inventory", version])], trace)
                self.assertNotIn("PHPRC", os.environ)
                self.assertNotIn("LD_PRELOAD", os.environ)
                self.assertNotIn("WSTM_PHP_VERSION", os.environ)
                self.assertEqual("/owned-custody", os.environ["WSTM_PREREQUISITE_ROOT"])

    def test_failed_provisioning_never_invokes_inventory_or_candidate_php(self):
        for version in ("8.2", "8.4"):
            with patch.dict(os.environ, {}, clear=True), \
                    patch.object(GUARD, "main", return_value=78), \
                    patch.object(BASE, "main") as inventory, \
                    patch.object(DRIVER.sys, "stdout", io.StringIO()):
                self.assertEqual(78, DRIVER.main(["setup", version]))
                inventory.assert_not_called()
        with patch.object(DRIVER, "run_units", return_value=0) as units, \
                patch.object(GUARD, "main") as provision:
            self.assertEqual(0, DRIVER.main(["setup", "units", "8.4"]))
            units.assert_called_once()
            provision.assert_not_called()

    def test_selected_interpreter_mismatch_precedes_config_query_and_root(self):
        for version in ("8.2", "8.4"):
            other = "8.4" if version == "8.2" else "8.2"
            inventory = BASE.Inventory(version)
            with patch.object(inventory, "system", return_value="/usr/bin/php" + other), \
                    patch.object(inventory, "command") as command:
                with self.assertRaises(BASE.Refusal):
                    inventory.collect()
                command.assert_not_called()
            guard = GUARD.Provision(version)
            with patch.object(guard, "system", return_value="/usr/bin/php" + other), \
                    patch.object(guard, "origin"), patch.object(guard, "dependencies"), \
                    patch.object(guard, "pin_sudo_configuration"), \
                    patch.object(guard, "special_read") as configuration, \
                    patch.object(guard, "capture_root") as root:
                with self.assertRaises(GUARD.Refusal):
                    guard.prepare()
                configuration.assert_not_called()
                root.assert_not_called()

    def test_both_config_trees_still_refuse_writable_inventory_and_escaped_symlink(self):
        for version in ("8.2", "8.4"):
            inventory = BASE.Inventory(version)
            path = inventory.profile["targets"][0]
            with patch.object(BASE.os.path, "realpath", side_effect=lambda p: p), \
                    patch.object(BASE.os, "lstat", return_value=self.info()), \
                    patch.object(inventory, "read") as read:
                with self.assertRaises(BASE.Refusal):
                    inventory.configuration(path, version)
                read.assert_not_called()
            guard = GUARD.Provision(version)
            with patch.object(GUARD.os.path, "realpath", return_value="/outside"), \
                    patch.object(GUARD.os, "open") as opening:
                with self.assertRaises(GUARD.Refusal):
                    guard.special_read(path)
                opening.assert_not_called()

    def test_php84_configuration_uses_same_predebit_cache_identity_and_exhaustion(self):
        inventory = BASE.Inventory("8.4")
        target = "/etc/php/8.4/mods-available/sockets.ini"
        alias = "/etc/php/8.4/cli/conf.d/20-sockets.ini"
        info = self.info(0o644)
        with patch.object(BASE.os.path, "realpath", return_value=target), \
                patch.object(BASE.os, "lstat", return_value=info) as metadata, \
                patch.object(BASE, "parent_pins", return_value={}), \
                patch.object(inventory, "read", return_value=(target, info, b"x" * 64)) as read:
            inventory.configuration(target, "8.4")
            inventory.configuration(alias, "8.4")
            self.assertEqual((1, 64, 1), (len(inventory.pins), inventory.bytes, read.call_count))
            metadata.return_value = SimpleNamespace(**dict(vars(info), st_ino=99))
            with self.assertRaises(BASE.Refusal):
                inventory.configuration(alias, "8.4")
            self.assertEqual(1, read.call_count)
        inventory = BASE.Inventory("8.4")
        inventory.bytes = BASE.TOTAL_LIMIT
        with patch.object(BASE.os.path, "realpath", side_effect=lambda p: p), \
                patch.object(BASE.os, "lstat", return_value=info), \
                patch.object(BASE, "parent_pins", return_value={}), \
                patch.object(inventory, "read") as read:
            with self.assertRaisesRegex(BASE.Refusal, "file-budget"):
                inventory.configuration(target, "8.4")
            read.assert_not_called()

    def test_three_workflow_surfaces_keep_host_versions_extensions_and_first_php_order(self):
        package = (SOURCE.parents[1] / ".github/workflows/release-package-qa.yml").read_text()
        e2e = (SOURCE.parents[1] / ".github/workflows/e2e-qa.yml").read_text()
        contract = e2e.split("  ability-contract-qa:\n", 1)[1].split("  full-mcp-e2e-qa:\n", 1)[0]
        full = e2e.split("  full-mcp-e2e-qa:\n", 1)[1].split("  docker-qa-gate:\n", 1)[0]
        for body, version, extension in ((package, "8.2", "zip"),
                                         (contract, "8.4", "posix"), (full, "8.4", "posix")):
            self.assertIn("php-version: '" + version + "'", body)
            self.assertIn("extensions: " + extension, body)
            setup = body.index("coverage: none")
            units = body.index("host-prerequisite-setup.py units " + version)
            guard = body.index("- name: Guard and acquire selected HOST prerequisites")
            custody = body.index("run: php tests/support/private-custody-context.php")
            qa = body.index("id: qa")
            self.assertLess(setup, units)
            self.assertLess(units, guard)
            self.assertLess(guard, custody)
            self.assertLess(custody, qa)
            self.assertEqual(1, body.count("- name: Guard and acquire selected HOST prerequisites"))
            self.assertEqual(1, body.count("setup-php@f3e473d116dcccaddc5834248c87452386958240"))
            self.assertIn("host-prerequisite-setup.py " + version, body)
            self.assertIn("always() && steps.php-permissions.outcome == 'success'", body)
            self.assertNotIn("extensions: " + extension + ", sockets", body)
            # Every always-running direct PHP diagnostic/summary has the explicit success gate.
            for step in body.split("      - name:")[1:]:
                if "run: php" in step and "always()" in step:
                    self.assertIn("steps.php-permissions.outcome == 'success'", step)


if __name__ == "__main__":
    unittest.main()
