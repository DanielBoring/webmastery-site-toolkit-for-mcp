"""Finite source-bound cleanup admission; filesystem/native acquisitions only."""

from contextlib import ExitStack, contextmanager
import ast
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import stat
import struct
from types import SimpleNamespace
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[2] / "scripts" / "host-php-apt.py"
SPEC = importlib.util.spec_from_file_location("session_loop_provider", SOURCE)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
BASE, TRUST = MODULE.BASE, MODULE.TRUST
FIXTURES = Path(__file__).with_name("fixtures") / "signed-php-producers"
PRODUCERS = {"/usr/sbin/phpquery": (FIXTURES / "phpquery").read_bytes(),
             "/usr/lib/php/php-helper": (FIXTURES / "php-helper").read_bytes(),
             "/usr/lib/php/sessionclean": (FIXTURES / "sessionclean").read_bytes()}


def elf():
    body = bytearray(64)
    body[:7] = b"\x7fELF\x02\x01\x01"
    struct.pack_into("<HHI", body, 16, 2, 62, 1)
    return bytes(body)


class FileSystem:
    def __init__(self):
        self.nodes = {}
        self.packages = {}
        self.serial = 1

    def add(self, path, raw=b"", mode=stat.S_IFREG | 0o644, uid=0, target=None):
        path = str(path)
        parent = str(PurePosixPath(path).parent)
        if path != "/" and parent not in self.nodes:
            self.add(parent, mode=stat.S_IFDIR | 0o755,
                     uid=1000 if parent.startswith("/owned") else 0)
        info = SimpleNamespace(st_mode=mode, st_uid=uid, st_gid=uid, st_nlink=1,
                               st_dev=1, st_ino=self.serial, st_size=len(raw),
                               st_mtime_ns=self.serial, st_ctime_ns=self.serial)
        self.serial += 1
        self.nodes[path] = (info, raw, target)
        return info

    def canonical(self, path):
        path = str(path)
        if path in self.nodes and self.nodes[path][2] is not None:
            target = self.nodes[path][2]
            return target
        return path

    def info(self, path):
        try:
            return self.nodes[str(path)][0]
        except KeyError:
            raise FileNotFoundError(str(path)) from None

    def parents(self, path):
        result = {}
        parent = PurePosixPath(path).parent
        while True:
            info = self.info(str(parent))
            BASE.validate_parent(info)
            result[str(parent)] = BASE.identity(info)
            if parent == parent.parent:
                return result
            parent = parent.parent

    def read(self, path, uid=0, limit=BASE.FILE_LIMIT):
        canonical = self.canonical(path)
        info, raw, _ = self.nodes[canonical]
        BASE.validate_file(info, uid, limit)
        return canonical, info, raw

    def scandir(self, path):
        names = [PurePosixPath(name).name for name in self.nodes
                 if str(PurePosixPath(name).parent) == str(path) and name != str(path)]
        class Listing:
            def __enter__(self):
                return iter(SimpleNamespace(name=name) for name in names)
            def __exit__(self, *_):
                return False
        return Listing()

    def path_class(self):
        fs = self
        class FixturePath(PurePosixPath):
            def resolve(self):
                return FixturePath(fs.canonical(self))
            def exists(self):
                return str(self) in fs.nodes
            def is_dir(self):
                return self.exists() and stat.S_ISDIR(fs.info(self).st_mode)
            def is_file(self):
                return self.exists() and stat.S_ISREG(fs.info(self).st_mode)
            def is_symlink(self):
                return self.exists() and stat.S_ISLNK(fs.info(self).st_mode)
            def iterdir(self):
                return iter(FixturePath(name) for name in sorted(fs.nodes)
                            if str(PurePosixPath(name).parent) == str(self) and name != str(self))
            def rglob(self, _):
                return iter(FixturePath(name) for name in sorted(fs.nodes)
                            if name.startswith(str(self) + "/"))
            def glob(self, pattern):
                return iter(FixturePath(name) for name in sorted(fs.nodes)
                            if PurePosixPath(name).match(str(self) + "/" + pattern))
        return FixturePath


class SessionLoopTest(unittest.TestCase):
    def test_gpg_status_nonfinal_continuation_space_preserves_canonical_records(self):
        for selected in (False, True):
            with self.subTest(selected=selected), self.gpg_dependency_model() as (item, fs, _, _, _):
                rows = [dict(row) for row in item.installed_records]
                row = next(value for value in rows if (
                    value["Package"] == "libreadline8t64") == selected)
                rows.remove(row)
                rows.insert(0, row)
                row["Description"] = "description\n continuation "
                chunks = ["\n".join(key + ": " + value for key, value in record.items()).encode()
                          for record in rows]
                raw = b"\n\n".join(chunks) + b"\n\n"
                canonical = MODULE.POLICY.deb822(raw)
                self.assertEqual("description\ncontinuation ", canonical[0]["Description"])
                self.assertNotEqual(canonical[0], MODULE.POLICY.deb822(chunks[0])[0])
                fs.add("/var/lib/dpkg/status", raw)
                report = self.diagnostic_refusal(item, fs)
                self.assertEqual("collected", report["state"], report)
                self.assertEqual(canonical, item.installed_records)
                record = report["status_record"]
                exported = fs.nodes[str(item.directory / record["retained"]["file"])][1]
                self.assertEqual(exported, raw[record["offset"]:record["offset"] + record["bytes"]])
                self.assertEqual(MODULE.POLICY.digest(exported), record["sha256"])
                if selected:
                    self.assertEqual(chunks[0], exported)
                self.assertEqual("complete", report["diagnostic_observation_status"])
                self.assertFalse(report["runtime_authorized"] or item.root_launches or item.setup_ready)

    def test_gpg_status_nonfinal_space_only_continuation_preserves_canonical_records(self):
        for selected in (False, True):
            with self.subTest(selected=selected), self.gpg_dependency_model() as (item, fs, _, _, _):
                rows = [dict(row) for row in item.installed_records]
                row = next(value for value in rows if (
                    value["Package"] == "libreadline8t64") == selected)
                rows.remove(row)
                rows.insert(0, row)
                row["Description"] = "description\n "
                chunks = ["\n".join(key + ": " + value for key, value in record.items()).encode()
                          for record in rows]
                raw = b"\n\n".join(chunks) + b"\n\n"
                canonical = MODULE.POLICY.deb822(raw)
                self.assertEqual("description\n", canonical[0]["Description"])
                self.assertNotEqual(canonical[0], MODULE.POLICY.deb822(chunks[0])[0])
                fs.add("/var/lib/dpkg/status", raw)
                report = self.diagnostic_refusal(item, fs)
                self.assertEqual("collected", report["state"], report)
                self.assertEqual(canonical, item.installed_records)
                record = report["status_record"]
                exported = fs.nodes[str(item.directory / record["retained"]["file"])][1]
                self.assertEqual(exported, raw[record["offset"]:record["offset"] + record["bytes"]])
                self.assertEqual(MODULE.POLICY.digest(raw), record["whole_file_sha256"])
                if selected:
                    self.assertEqual(chunks[0], exported)
                self.assertEqual("complete", report["diagnostic_observation_status"])
                self.assertFalse(report["runtime_authorized"] or item.root_launches or item.setup_ready)

    def test_gpg_status_terminal_forms_preserve_raw_offsets_and_whole_file_binding(self):
        for ending in (b"", b"\n", b"\n\n", b"\n\n\n\n", b"\n\n \t\n"):
            for position in ("first", "middle", "last"):
                with self.subTest(ending=ending, position=position), \
                        self.gpg_dependency_model() as (item, fs, canonical, alias, saved):
                    rows = [dict(row) for row in item.installed_records]
                    selected = next(row for row in rows if row["Package"] == "libreadline8t64")
                    rows.remove(selected)
                    rows[0]["Description"] = "caf\u00e9"
                    index = 0 if position == "first" else len(rows) if position == "last" else len(rows) // 2
                    rows.insert(index, selected)
                    chunks = ["\n".join(key + ": " + value for key, value in row.items()).encode()
                              for row in rows]
                    raw = b"\n\n".join(chunks) + ending
                    fs.add("/var/lib/dpkg/status", raw)
                    report = self.diagnostic_refusal(item, fs)
                    self.assertEqual("collected", report["state"], report)
                    record = report["status_record"]
                    offset = sum(len(chunk) + 2 for chunk in chunks[:index])
                    expected = chunks[index]
                    if position == "last" and b"\n\n" not in ending:
                        expected += ending
                    self.assertEqual(offset, record["offset"])
                    self.assertEqual(expected, raw[offset:offset + record["bytes"]])
                    self.assertEqual(MODULE.POLICY.digest(expected), record["sha256"])
                    self.assertEqual(MODULE.POLICY.digest(raw), record["whole_file_sha256"])
                    self.assertEqual(record["whole_file_sha256"], record["pin"]["sha256"])
                    retained = record["retained"]
                    self.assertEqual(expected, fs.nodes[str(item.directory / retained["file"])][1])
                    self.assertEqual(record["sha256"], retained["sha256"])
                    self.assertEqual(record["bytes"], retained["bytes"])
                    self.assertEqual("complete", report["diagnostic_observation_status"])
                    self.assertEqual(report["files"][canonical]["identity"], report["files"][alias]["identity"])
                    self.assertFalse(report["files"][saved]["present"])
                    self.assertFalse(report["runtime_authorized"] or item.setup_ready or item.root_launches)
                    before = (item.bytes, len(item.session_data_pins), len(report["commands"]))
                    number = report["subject"]["failed_command"]
                    item.gpg_diagnostic_query_capture(number, item.commands[number - 1], report)
                    self.assertEqual(before, (item.bytes, len(item.session_data_pins), len(report["commands"])))

    def test_gpg_status_malformed_interior_and_duplicate_fields_keep_original_refusal(self):
        for fault in ("interior", "empty-interior", "duplicate-field", "bad-terminal"):
            with self.subTest(fault=fault), self.gpg_dependency_model() as (item, fs, _, _, _):
                collect = item.gpg_diversion_diagnostic
                def corrupt():
                    raw = fs.nodes["/var/lib/dpkg/status"][1]
                    if fault == "interior":
                        raw = raw.replace(b"\n\n", b"\n\nMALFORMED_PRIVATE\n\n", 1)
                    elif fault == "empty-interior":
                        raw = raw.replace(b"\n\n", b"\n\n\n\n", 1)
                    elif fault == "duplicate-field":
                        raw = raw.replace(b"Package: libreadline8t64\n",
                                          b"Package: libreadline8t64\nPackage: libreadline8t64\n")
                    else:
                        raw += b"\nMALFORMED_PRIVATE"
                    protected = item.protected
                    with patch.object(item, "protected", side_effect=lambda path, limit=BASE.FILE_LIMIT:
                                      raw if path == "/var/lib/dpkg/status" else protected(path, limit)):
                        collect()
                with patch.object(item, "gpg_diversion_diagnostic", side_effect=corrupt):
                    report = self.diagnostic_refusal(item, fs)
                self.assertEqual("partial", report["state"])
                self.assertEqual("inventory-shape", report["reason"])
                self.assertNotIn("status_record", report)
                self.assertNotIn("diagnostic_observation_status", report)
                self.assertTrue(report["commands"])
                self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_status_duplicate_mismatched_and_qualified_records_refuse(self):
        for fault in ("duplicate", "mismatch", "qualified-name", "architecture", "multi-arch"):
            with self.subTest(fault=fault), self.gpg_dependency_model() as (item, fs, _, _, _):
                collect = item.gpg_diversion_diagnostic
                def corrupt():
                    rows = [dict(row) for row in item.installed_records]
                    selected = next(row for row in rows if row["Package"] == "libreadline8t64")
                    if fault == "duplicate":
                        rows.append(dict(selected))
                    elif fault == "mismatch":
                        selected["Version"] = "2"
                    elif fault == "qualified-name":
                        selected["Package"] += ":amd64"
                    elif fault == "architecture":
                        selected["Architecture"] = "arm64"
                    else:
                        selected["Multi-Arch"] = "no"
                    raw = b"\n\n".join(
                        "\n".join(key + ": " + value for key, value in row.items()).encode()
                        for row in rows) + b"\n\n"
                    if fault != "mismatch":
                        item.installed_records = MODULE.POLICY.deb822(raw)
                    protected = item.protected
                    with patch.object(item, "protected", side_effect=lambda path, limit=BASE.FILE_LIMIT:
                                      raw if path == "/var/lib/dpkg/status" else protected(path, limit)):
                        collect()
                with patch.object(item, "gpg_diversion_diagnostic", side_effect=corrupt):
                    report = self.diagnostic_refusal(item, fs)
                self.assertEqual("partial", report["state"])
                self.assertEqual("tool-origin", report["reason"])
                self.assertNotIn("status_record", report)
                self.assertFalse(report["runtime_authorized"] or item.setup_ready or item.root_launches)

    def test_gpg_status_stanza_pass_obeys_shared_deadline(self):
        with self.gpg_dependency_model() as (item, fs, _, _, _):
            fs.add("/var/lib/dpkg/status", fs.nodes["/var/lib/dpkg/status"][1] + b"\n")
            parse = MODULE.POLICY.deb822
            hit = []
            whole_document_calls = []
            def expire(raw, *args, **kwargs):
                result = parse(raw, *args, **kwargs)
                if raw == fs.nodes["/var/lib/dpkg/status"][1]:
                    whole_document_calls.append(True)
                    if len(whole_document_calls) == 3:
                        hit.append(True)
                        item.deadline = 0
                return result
            with patch.object(MODULE.POLICY, "deb822", side_effect=expire):
                report = self.diagnostic_refusal(item, fs)
            self.assertEqual([True], hit)
            self.assertEqual("partial", report["state"])
            self.assertEqual("inventory-deadline", report["reason"])
            self.assertNotIn("status_record", report)
            self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_status_export_exhaustion_and_write_failure_retain_debits_and_captures(self):
        for fault in ("records", "metadata", "write"):
            with self.subTest(fault=fault), self.gpg_dependency_model() as (item, fs, _, _, _):
                export = item.gpg_diagnostic_export
                held = {}
                def fail(label, raw):
                    if label == "installed-status":
                        if fault == "records":
                            item.record_bytes = MODULE.CAPTURE.RECORDS
                        elif fault == "metadata":
                            item.metadata_bytes = 67108864
                        held["before"] = (item.bytes, len(item.session_data_pins), item.record_bytes)
                        held["size"] = len(raw)
                        if fault == "write":
                            write = item.write.side_effect
                            def broken(name, body):
                                if name == "gpg-diagnostic-installed-status.original.private":
                                    raise PermissionError("PRIVATE_STATUS_SENTINEL")
                                return write(name, body)
                            with patch.object(item, "write", side_effect=broken):
                                return export(label, raw)
                        if fault == "metadata":
                            with patch.object(item, "retain", side_effect=lambda name, body:
                                              MODULE.Provider.retain_metadata(item, name, body)):
                                return export(label, raw)
                    return export(label, raw)
                with patch.object(item, "gpg_diagnostic_export", side_effect=fail):
                    if fault == "records":
                        with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                                self.assertRaisesRegex(BASE.Refusal, "^tool-origin$") as refusal:
                            item.preflight()
                        self.assertEqual("owner-response-shape", refusal.exception.origin_check)
                        self.assertEqual({"state": "retention-failed", "reason": "retention"},
                                         item.receipt["gpg_diversion_diagnostic"])
                        self.assertNotIn(str(item.directory / "gpg-diagnostic-result.private.json"), fs.nodes)
                    else:
                        report = self.diagnostic_refusal(item, fs)
                self.assertEqual(held["before"][:2], (item.bytes, len(item.session_data_pins)))
                self.assertTrue(any(path.endswith(".stdout.private") for path in fs.nodes))
                self.assertFalse(item.setup_ready or item.root_launches)
                if fault == "records":
                    self.assertEqual(MODULE.CAPTURE.RECORDS, item.record_bytes)
                    continue
                self.assertEqual("partial", report["state"])
                self.assertEqual(15, len(report["commands"]))
                if fault == "write":
                    self.assertGreaterEqual(item.record_bytes, held["before"][2] + held["size"])
                self.assertNotIn("diagnostic_observation_status", report)
                self.assertTrue(any(path.endswith(".stdout.private") for path in fs.nodes))
                with patch("builtins.print") as output:
                    self.assertFalse(MODULE.projection(item, None, "tool-origin"))
                self.assertNotIn("PRIVATE_STATUS_SENTINEL", output.call_args.args[0])
                self.assertNotIn("libreadline8t64", output.call_args.args[0])
                self.assertFalse(report["runtime_authorized"] or item.setup_ready or item.root_launches)

    def test_gpg_status_final_whole_file_drift_prevents_complete_observation(self):
        with self.gpg_dependency_model() as (item, fs, canonical, _, _):
            capture = item.capture.side_effect
            def mutate(operation, argv, stdin=None):
                result = capture(operation, argv, stdin)
                if operation == "elf" and argv[-1] == canonical:
                    fs.add("/var/lib/dpkg/status", fs.nodes["/var/lib/dpkg/status"][1] + b"\n")
                return result
            with patch.object(item, "capture", side_effect=mutate):
                report = self.diagnostic_refusal(item, fs)
            self.assertEqual("partial", report["state"])
            self.assertEqual("custody-recheck", report["phase"])
            self.assertIn("status_record", report)
            self.assertNotIn("diagnostic_observation_status", report)
            self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_capture_exhaustion_reserves_before_any_body_read(self):
        for exhausted in ("slots", "bytes"):
            with self.subTest(exhausted=exhausted), self.gpg_dependency_model() as (item, fs, _, _, _):
                collect = item.gpg_diversion_diagnostic
                reads = []
                debits = []
                def limited():
                    if exhausted == "slots":
                        item._retired_pins = BASE.FILE_COUNT - len(item.pins) - len(item.session_data_pins)
                    else:
                        item.bytes = BASE.TOTAL_LIMIT
                    before = (item.bytes, len(item.session_data_pins))
                    read = item.read.side_effect
                    def observed(path, owner=0, limit=BASE.FILE_LIMIT):
                        if "gpg-diagnostic-command-" in str(path):
                            reads.append(path)
                        return read(path, owner, limit)
                    with patch.object(item, "read", side_effect=observed):
                        collect()
                    debits.append((before, (item.bytes, len(item.session_data_pins))))
                with patch.object(item, "gpg_diversion_diagnostic", side_effect=limited):
                    report = self.diagnostic_refusal(item, fs)
                self.assertEqual([], reads)
                self.assertEqual(debits[0][0], debits[0][1])
                self.assertEqual("partial", report["state"])
                self.assertEqual("file-budget", report["reason"])
                self.assertEqual([], report["commands"])
                self.assertFalse(item.root_launches or item.setup_ready)

    def test_gpg_capture_failed_acquisition_keeps_debit_and_reserved_slot(self):
        for failure in ("read", "custody"):
            with self.subTest(failure=failure), self.gpg_dependency_model() as (item, fs, _, _, _):
                collect = item.gpg_diversion_diagnostic
                held = {}
                def failing():
                    read = item.read.side_effect
                    held["before"] = (item.bytes, len(item.session_data_pins))
                    def observe(path, owner=0, limit=BASE.FILE_LIMIT):
                        if "gpg-diagnostic-command-" in str(path):
                            pin = item.session_data_pins[str(path)]
                            self.assertEqual("reserved", pin["state"])
                            self.assertEqual(fs.info(path).st_size, pin["bytes"])
                            held["path"] = str(path)
                            held["size"] = pin["bytes"]
                            if failure == "read":
                                raise PermissionError("PRIVATE_SENTINEL")
                            result = read(path, owner, limit)
                            fs.info(path).st_ctime_ns += 1
                            return result
                        return read(path, owner, limit)
                    with patch.object(item, "read", side_effect=observe):
                        collect()
                with patch.object(item, "gpg_diversion_diagnostic", side_effect=failing):
                    report = self.diagnostic_refusal(item, fs)
                path = held["path"]
                self.assertEqual("reserved", item.session_data_pins[path]["state"])
                self.assertEqual(held["before"][0] + held["size"], item.bytes)
                self.assertEqual(held["before"][1] + 1, len(item.session_data_pins))
                self.assertEqual("partial", report["state"])
                self.assertFalse(item.root_launches or item.setup_ready)

    def test_gpg_capture_completed_reuse_has_no_duplicate_debit(self):
        with self.gpg_dependency_model() as (item, fs, _, _, _):
            report = self.diagnostic_refusal(item, fs)
            self.assertEqual("collected", report["state"], report)
            number = report["subject"]["failed_command"]
            before = (item.bytes, len(item.session_data_pins), len(report["commands"]))
            item.gpg_diagnostic_query_capture(number, item.commands[number - 1], report)
            self.assertEqual(before, (item.bytes, len(item.session_data_pins), len(report["commands"])))

    def test_gpg_capture_required_field_matrix_is_explicit_retention_refusal(self):
        common = (
            "operation", "argv", "environment", "exit", "stdout_eof", "stderr_eof", "failure",
            "producer_pid", "wait_owner", "root_cleanup_certified", "privileged", "source_sha256",
            "stdout_bytes", "stderr_bytes", "capture_prefix", "requested_child_umask")
        for phase in ("intent", "reserved", "observed", "command"):
            fields = common + (() if phase == "intent" else ("stdout_identity", "stderr_identity"))
            if phase in ("observed", "command"):
                fields += ("stdout_sha256", "stderr_sha256", "retention_failed")
            for field in fields:
                for mutation in ("missing", "wrong-type"):
                    with self.subTest(phase=phase, field=field, mutation=mutation), \
                            self.gpg_dependency_model() as (item, fs, _, _, _):
                        collect = item.gpg_diversion_diagnostic
                        def corrupt():
                            command = item.commands[-1]
                            if phase == "command":
                                value = json.loads(json.dumps(command))
                            else:
                                path = str(item.directory / (
                                    command["capture_prefix"] + "." + phase + ".private.json"))
                                value = json.loads(fs.nodes[path][1])
                            if mutation == "missing":
                                del value[field]
                            else:
                                value[field] = "WRONG_TYPE"
                            if phase == "command":
                                item.commands[-1] = value
                            else:
                                fs.add(path, json.dumps(value).encode(), mode=stat.S_IFREG | 0o600, uid=1000)
                            collect()
                        with patch.object(item, "gpg_diversion_diagnostic", side_effect=corrupt):
                            report = self.diagnostic_refusal(item, fs)
                        self.assertEqual("partial", report["state"], report)
                        self.assertEqual("retention", report["reason"])
                        self.assertNotIn("diagnostic_observation_status", report)
                        self.assertFalse(item.root_launches or item.setup_ready)

    def test_gpg_capture_nested_identity_source_and_argv_matrix_refuses(self):
        identity_fields = ("dev", "ino", "uid", "gid", "mode", "nlink")
        cases = [("stdout_identity", key) for key in identity_fields]
        cases += [("stderr_identity", key) for key in identity_fields]
        cases += [("source-identity", key) for key in identity_fields + ("size", "mtime_ns", "ctime_ns")]
        cases += [("source-row", key) for key in ("identity", "sha256")]
        cases += [("argv", "element"), ("environment", "PATH"), ("environment", "LC_ALL")]
        for phase in ("reserved", "observed", "command"):
            for domain, key in cases:
                for mutation in ("missing", "wrong-type"):
                    with self.subTest(phase=phase, domain=domain, key=key, mutation=mutation), \
                            self.gpg_dependency_model() as (item, fs, _, _, _):
                        collect = item.gpg_diversion_diagnostic
                        def corrupt():
                            command = item.commands[-1]
                            if phase == "command":
                                value = json.loads(json.dumps(command))
                            else:
                                path = str(item.directory / (
                                    command["capture_prefix"] + "." + phase + ".private.json"))
                                value = json.loads(fs.nodes[path][1])
                            if domain.startswith("source"):
                                row = value["source_sha256"][next(iter(value["source_sha256"]))]
                                container = row["identity"] if domain == "source-identity" else row
                            elif domain == "argv":
                                if mutation == "missing":
                                    value["argv"] = []
                                else:
                                    value["argv"][0] = None
                                container = None
                            else:
                                container = value[domain]
                            if container is not None:
                                if mutation == "missing":
                                    del container[key]
                                else:
                                    container[key] = False
                            if phase == "command":
                                item.commands[-1] = value
                            else:
                                fs.add(path, json.dumps(value).encode(), mode=stat.S_IFREG | 0o600, uid=1000)
                            collect()
                        with patch.object(item, "gpg_diversion_diagnostic", side_effect=corrupt):
                            report = self.diagnostic_refusal(item, fs)
                        self.assertEqual("partial", report["state"], report)
                        self.assertEqual("retention", report["reason"])
                        self.assertFalse(item.root_launches or item.setup_ready)

    @contextmanager
    def gpg_dependency_model(self, saved_present=False):
        with self.model() as (item, fs):
            canonical = "/usr/lib/x86_64-linux-gnu/libreadline.so.8.2"
            alias = "/lib/x86_64-linux-gnu/libreadline.so.8.2"
            saved = alias + ".usr-is-merged"
            fs.add(canonical, elf() + b"readline", mode=stat.S_IFREG | 0o644)
            fs.add(canonical.removesuffix(".2"), target=canonical)
            fs.add("/lib", mode=stat.S_IFLNK | 0o777, target="/usr/lib")
            fs.nodes["/lib/x86_64-linux-gnu"] = fs.nodes["/usr/lib/x86_64-linux-gnu"]
            fs.nodes[alias] = fs.nodes[canonical]
            realpath = fs.canonical
            def canonical_path(path):
                path = str(path)
                return realpath("/usr" + path) if path.startswith("/lib/") else realpath(path)
            fs.canonical = canonical_path
            if saved_present:
                fs.add("/usr" + saved, elf() + b"saved", mode=stat.S_IFREG | 0o644)
                fs.nodes[saved] = fs.nodes["/usr" + saved]
            package = "libreadline8t64:amd64"
            fs.packages[canonical] = package
            row = {"Package": "libreadline8t64", "Version": "1", "Architecture": "amd64",
                   "Multi-Arch": "same", "Status": "install ok installed", "Source": "readline"}
            item.installed_records.append(row)
            fs.add("/var/lib/dpkg/info/" + package + ".list", (alias + "\n").encode())
            fs.add("/var/lib/dpkg/info/" + package + ".md5sums",
                   (hashlib.md5(fs.nodes[canonical][1]).hexdigest() + "  " + alias[1:] + "\n").encode())
            fs.add("/var/lib/dpkg/diversions", (
                alias + "\n" + saved + "\nlibreadline8t64\n"
                "/usr/bin/parallel\n/usr/bin/parallel.moreutils\nparallel\n").encode())
            fs.add("/usr/share/extra-placeholder", b"unrelated")
            fs.packages["/usr/bin/gpg"] = "gpg"
            if not any(record["Package"] == "gpg" for record in item.installed_records):
                item.installed_records.append({
                    "Package": "gpg", "Version": "1", "Architecture": "amd64",
                    "Status": "install ok installed"})
            fs.add("/var/lib/dpkg/info/gpg.md5sums",
                   (hashlib.md5(fs.nodes["/usr/bin/gpg"][1]).hexdigest() + "  usr/bin/gpg\n").encode())
            self.preflight_inputs(item, fs)
            def store(name, raw):
                if name.endswith(".json"):
                    BASE.require(item.record_bytes + len(raw) <= MODULE.CAPTURE.RECORDS,
                                 "retention-budget")
                    item.record_bytes += len(raw)
                fs.add(str(item.directory / name), raw, mode=stat.S_IFREG | 0o600, uid=1000)
            def capture(operation, argv, stdin=None):
                record = MODULE.CAPTURE.Capture.reserve_capture(item, operation, argv, stdin)
                argument = argv[-1]
                if operation == "owner":
                    if argument == alias:
                        stdout = ("diversion by libreadline8t64 from: " + alias + "\n"
                                  "diversion by libreadline8t64 to: " + saved + "\n").encode()
                    else:
                        owner = fs.packages.get(argument)
                        stdout = (owner + ": " + argument + "\n").encode() if owner else b""
                    stdout = getattr(fs, "owner_responses", {}).get(argument, stdout)
                elif operation == "package":
                    base = argument.split(":")[0]
                    installed = next(record for record in item.installed_records
                                     if record["Package"] == base)
                    stdout = ("\t".join((argument, installed["Version"], installed["Architecture"],
                                         installed.get("Source", base), installed["Version"])) + "\n").encode()
                    stdout = getattr(fs, "package_responses", {}).get(argument, stdout)
                elif operation == "elf":
                    stdout = b"(NEEDED) Shared library: [libreadline.so.8]\n" if argument == "/usr/bin/gpg" else b""
                    stdout = getattr(fs, "elf_responses", {}).get(argument, stdout)
                else:
                    raise AssertionError("Unapproved dependency diagnostic operation")
                prefix = record.get("capture_prefix", "command-%d" % len(item.commands))
                for label in ("stdout", "stderr"):
                    store(prefix + "." + label + ".private", b"")
                    record[label + "_identity"] = BASE.original_identity(
                        fs.info(str(item.directory / (prefix + "." + label + ".private"))))
                store(prefix + ".reserved.private.json", json.dumps(record, sort_keys=True).encode())
                record.update(exit=0, stdout_eof=True, stderr_eof=True, retention_failed=False)
                for label, raw in (("stdout", stdout), ("stderr", b"")):
                    path = str(item.directory / (prefix + "." + label + ".private"))
                    info, _, target = fs.nodes[path]
                    info.st_size = len(raw)
                    fs.nodes[path] = (info, raw, target)
                    record[label + "_bytes"] = len(raw)
                    record[label + "_sha256"] = MODULE.POLICY.digest(raw)
                    item.capture_bytes += len(raw)
                    BASE.require(item.capture_bytes <= MODULE.CAPTURE.STREAMS, "retention-budget")
                store(prefix + ".observed.private.json", json.dumps(record, sort_keys=True).encode())
                return record, stdout, b""
            with patch.object(os.path, "realpath", side_effect=canonical_path), \
                    patch.object(item, "write", side_effect=store), \
                    patch.object(item, "capture", side_effect=capture), \
                    patch.object(item, "command", side_effect=lambda op, arg:
                                 MODULE.Provider.command(item, op, arg)):
                yield item, fs, canonical, alias, saved

    def test_gpg_dependency_actual_preflight_retains_failed_pair_and_protected_absence(self):
        with self.gpg_dependency_model() as (item, fs, canonical, alias, saved):
            deadline = item.deadline
            report = self.diagnostic_refusal(item, fs)
            self.assertEqual("collected", report["state"], report)
            self.assertEqual("complete", report["diagnostic_observation_status"])
            self.assertEqual(alias, report["subject"]["alias"])
            self.assertEqual("gpg", item.origins["/usr/bin/gpg"]["package"][0])
            self.assertEqual("libreadline8t64:amd64", report["installed_association"]["owning_binary_package"])
            self.assertEqual(25, len(report["commands"]))
            self.assertFalse(report["files"][saved]["present"])
            self.assertEqual(report["files"][alias]["identity"], report["files"][canonical]["identity"])
            self.assertIn("/lib", report["files"][alias]["alias_parents"])
            self.assertEqual(62, report["files"][canonical]["ELF_header"]["machine"])
            self.assertEqual("unresolved", report["static_loader_facts"]["dependency_resolution_state"])
            self.assertNotIn(canonical, item.origins)
            self.assertEqual(deadline, item.deadline)
            self.assertFalse(report["runtime_authorized"] or item.setup_ready or item.root_launches
                             or item.receipt["signed_transaction_authorized"]
                             or item.receipt["installed_authority_verified"])
            self.assertEqual(False, item.gpg_diagnostic_active)
            self.assertEqual((), item._gpg_diagnostic_paths)

    def test_gpg_dependency_present_saved_bytes_are_observation_only(self):
        with self.gpg_dependency_model(saved_present=True) as (item, fs, _, _, saved):
            report = self.diagnostic_refusal(item, fs)
            self.assertEqual("collected", report["state"], report)
            self.assertTrue(report["files"][saved]["present"])
            self.assertEqual("complete", report["files"][saved]["ELF_header_status"])
            self.assertNotIn("/usr" + saved, item.origins)
            self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_dependency_original_record_binding_refuses_substitution(self):
        for fault in ("intent", "reserved", "observed", "stream", "duplicate-pair", "foreign-last",
                      "declared-dependency", "source"):
            with self.subTest(fault=fault), self.gpg_dependency_model() as (item, fs, canonical, alias, _):
                collect = item.gpg_diversion_diagnostic
                def corrupt():
                    failed = item.commands[-1]
                    prefix = failed["capture_prefix"]
                    if fault in ("intent", "reserved", "observed"):
                        path = str(item.directory / (prefix + "." + fault + ".private.json"))
                        value = json.loads(fs.nodes[path][1])
                        value["argv"] = ["/usr/bin/dpkg-query", "-S", "/lib/foreign"]
                        fs.add(path, json.dumps(value).encode(), mode=stat.S_IFREG | 0o600, uid=1000)
                    elif fault == "stream":
                        path = str(item.directory / (prefix + ".stdout.private"))
                        fs.add(path, b"foreign", mode=stat.S_IFREG | 0o600, uid=1000)
                    elif fault == "duplicate-pair":
                        item.commands.insert(-1, dict(item.commands[-2]))
                    elif fault == "foreign-last":
                        item.commands.append({"operation": "owner", "argv": ["/usr/bin/dpkg-query", "-S", "/lib/foreign"]})
                    elif fault == "declared-dependency":
                        item.files["/usr/bin/gpg"]["declared_dependencies"] = []
                    else:
                        failed["source_sha256"] = {}
                    collect()
                with patch.object(item, "gpg_diversion_diagnostic", side_effect=corrupt):
                    report = self.diagnostic_refusal(item, fs)
                self.assertEqual("partial", report["state"], report)
                self.assertNotIn("diagnostic_observation_status", report)
                self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_dependency_rejects_notice_declaration_and_installed_qualification(self):
        for fault in ("foreign", "local", "ambiguous", "notice", "canonical-owner", "architecture",
                      "multiarch", "duplicate-installed", "metadata", "list", "md5"):
            with self.subTest(fault=fault), self.gpg_dependency_model() as (item, fs, canonical, alias, saved):
                if fault in ("foreign", "local", "ambiguous"):
                    table = alias + "\n" + saved + "\n" + (
                        "foreign" if fault == "foreign" else ":" if fault == "local" else "libreadline8t64") + "\n"
                    fs.add("/var/lib/dpkg/diversions", (table * (2 if fault == "ambiguous" else 1)).encode())
                elif fault == "notice":
                    fs.owner_responses = {alias: ("diversion by libreadline8t64 from: " + alias
                        + "\ndiversion by libreadline8t64 to: " + saved + ".foreign\n").encode()}
                elif fault == "canonical-owner":
                    fs.owner_responses = {canonical: ("foreign: " + canonical + "\n").encode()}
                elif fault in ("architecture", "multiarch", "duplicate-installed"):
                    row = next(row for row in item.installed_records if row["Package"] == "libreadline8t64")
                    if fault == "duplicate-installed":
                        item.installed_records.append(dict(row))
                    else:
                        row["Architecture" if fault == "architecture" else "Multi-Arch"] = (
                            "arm64" if fault == "architecture" else "foreign")
                    self.preflight_inputs(item, fs)
                elif fault == "metadata":
                    fs.package_responses = {"libreadline8t64:amd64": b"libreadline8t64:amd64\t2\tamd64\treadline\t2\n"}
                elif fault == "list":
                    fs.add("/var/lib/dpkg/info/libreadline8t64:amd64.list", b"/usr/bin/foreign\n")
                else:
                    fs.add("/var/lib/dpkg/info/libreadline8t64:amd64.md5sums",
                           ("0" * 32 + "  " + alias[1:] + "\n").encode())
                if fault == "duplicate-installed":
                    with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                            self.assertRaisesRegex(BASE.Refusal, "^inventory-shape$"):
                        item.preflight()
                    self.assertEqual([], item.commands)
                    continue
                report = self.diagnostic_refusal(item, fs)
                self.assertEqual("partial", report["state"], report)
                self.assertNotIn("diagnostic_observation_status", report)
                self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_dependency_unreadable_is_not_absence_and_alias_escape_refuses(self):
        for fault in ("unreadable", "alias-parent", "saved-symlink", "missing-list", "unsafe-file"):
            with self.subTest(fault=fault), self.gpg_dependency_model() as (item, fs, canonical, alias, saved):
                if fault == "alias-parent":
                    fs.info("/lib").st_uid = 1000
                elif fault == "saved-symlink":
                    fs.add("/usr" + saved, mode=stat.S_IFLNK | 0o777, target="/usr/bin/gpg")
                    fs.nodes[saved] = fs.nodes["/usr" + saved]
                elif fault == "missing-list":
                    del fs.nodes["/var/lib/dpkg/info/libreadline8t64:amd64.list"]
                elif fault == "unsafe-file":
                    fs.info(canonical).st_mode |= 0o002
                if fault == "unsafe-file":
                    with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                            self.assertRaisesRegex(BASE.Refusal, "^tool-link$"):
                        item.preflight()
                    continue
                info = fs.info
                def lstat(path):
                    if fault == "unreadable" and str(path) == saved:
                        raise PermissionError("PRIVATE_SENTINEL")
                    return info(path)
                with patch.object(os, "lstat", side_effect=lstat):
                    report = self.diagnostic_refusal(item, fs)
                self.assertEqual("partial", report["state"], report)
                if fault == "unreadable":
                    self.assertEqual("unreadable", report["failure_kind"])
                    self.assertNotIn(saved, report["files"])
                self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_dependency_final_recheck_rejects_file_absence_parent_metadata_and_query_drift(self):
        for fault in ("file", "file-bytes", "absent-to-present", "alias-parent", "metadata",
                      "metadata-bytes", "query", "source"):
            with self.subTest(fault=fault), self.gpg_dependency_model() as (item, fs, canonical, alias, saved):
                capture = item.capture.side_effect
                def mutate(operation, argv, stdin=None):
                    result = capture(operation, argv, stdin)
                    if operation == "elf" and argv[-1] == canonical:
                        if fault == "file":
                            fs.info(canonical).st_ctime_ns += 1
                        elif fault in ("file-bytes", "metadata-bytes"):
                            path = canonical if fault == "file-bytes" else "/var/lib/dpkg/info/libreadline8t64:amd64.md5sums"
                            info, raw, target = fs.nodes[path]
                            fs.nodes[path] = (info, bytes([raw[0] ^ 1]) + raw[1:], target)
                        elif fault == "absent-to-present":
                            fs.add("/usr" + saved, elf(), mode=stat.S_IFREG | 0o644)
                            fs.nodes[saved] = fs.nodes["/usr" + saved]
                        elif fault == "alias-parent":
                            fs.info("/lib").st_ctime_ns += 1
                        elif fault == "metadata":
                            fs.info("/var/lib/dpkg/info/libreadline8t64:amd64.md5sums").st_ctime_ns += 1
                        elif fault == "query":
                            command = next(command for command in item.commands if command["argv"] == [
                                "/usr/bin/dpkg-query", "-S", alias])
                            fs.info(str(item.directory / (command["capture_prefix"] + ".stdout.private"))).st_ctime_ns += 1
                        else:
                            fs.info("/owned/source/host-php-apt.py").st_ctime_ns += 1
                    return result
                with patch.object(item, "capture", side_effect=mutate):
                    report = self.diagnostic_refusal(item, fs)
                self.assertEqual("partial", report["state"], report)
                self.assertEqual("custody-recheck", report["phase"])
                self.assertNotIn("diagnostic_observation_status", report)
                self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_dependency_new_query_custody_rechecks_reject_late_stream_drift(self):
        for operation in ("package", "elf"):
            with self.subTest(operation=operation), self.gpg_dependency_model() as (item, fs, canonical, _, _):
                capture = item.capture.side_effect
                def corrupt(op, argv, stdin=None):
                    result = capture(op, argv, stdin)
                    if op == "package" and item.gpg_diagnostic_active and any(
                            command["operation"] == "elf" and command["argv"][-1] == canonical
                            for command in item.commands):
                        command = next(command for command in item.commands
                                       if command["operation"] == operation
                                       and command["argv"][-1] in (canonical, "libreadline8t64:amd64"))
                        path = str(item.directory / (command["capture_prefix"] + ".stdout.private"))
                        fs.info(path).st_ctime_ns += 1
                    return result
                with patch.object(item, "capture", side_effect=corrupt):
                    report = self.diagnostic_refusal(item, fs)
                self.assertEqual("partial", report["state"], report)
                self.assertEqual("custody-recheck", report["phase"])
                self.assertNotIn("diagnostic_observation_status", report)
                self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_dependency_existing_budgets_and_failure_retention_remain_closed(self):
        for budget in ("pins", "records", "metadata", "commands", "deadline"):
            with self.subTest(budget=budget), self.gpg_dependency_model() as (item, fs, _, _, _):
                collect = item.gpg_diversion_diagnostic
                def exhaust():
                    if budget == "pins":
                        item._retired_pins = BASE.FILE_COUNT - len(item.pins) - len(item.session_data_pins)
                    elif budget == "records":
                        item.record_bytes = MODULE.CAPTURE.RECORDS
                    elif budget == "metadata":
                        item.metadata_bytes = 67108864
                    elif budget == "deadline":
                        item.deadline = 0
                    if budget == "commands":
                        with patch.object(MODULE.CAPTURE, "COMMANDS", len(item.commands)):
                            collect()
                    elif budget == "metadata":
                        with patch.object(item, "retain", side_effect=lambda label, raw:
                                          MODULE.Provider.retain_metadata(item, label, raw)):
                            collect()
                    else:
                        collect()
                with patch.object(item, "gpg_diversion_diagnostic", side_effect=exhaust):
                    with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                            self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                        item.preflight()
                self.assertIn(item.receipt["gpg_diversion_diagnostic"]["state"],
                              ("partial", "retention-failed"))
                self.assertTrue(any("gpg-diagnostic-command-" in path and path.endswith(".stdout.private")
                                    for path in fs.nodes))
                self.assertFalse(item.setup_ready or item.root_launches)

    def test_gpg_dependency_public_privacy_and_original_parser_table_guard_remain_closed(self):
        with self.gpg_dependency_model() as (item, fs, canonical, alias, saved):
            report = self.diagnostic_refusal(item, fs)
            with patch("builtins.print") as output:
                self.assertFalse(MODULE.projection(item, None, "tool-origin"))
            public = output.call_args.args[0]
            for secret in (canonical, alias, saved, "libreadline8t64", "readline", "argv", "PRIVATE_SENTINEL"):
                self.assertNotIn(secret, public)
            self.assertFalse(report["runtime_authorized"] or item.setup_ready)
            with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                item.origin(canonical)
            self.assertNotIn(canonical, item.origins)
            tree = ast.parse(SOURCE.read_bytes())
            preflight = next(node for node in ast.walk(tree) if isinstance(node, ast.FunctionDef)
                             and node.name == "_preflight")
            self.assertIn('require(not raw.strip(), "tool-loader")',
                          ast.get_source_segment(SOURCE.read_text(), preflight))

    def test_gpg_dependency_loader_and_result_retention_failures_keep_original_refusal(self):
        with self.gpg_dependency_model() as (item, fs, canonical, _, _):
            fs.elf_responses = {canonical: b"(RPATH) Library rpath: [/PRIVATE_SENTINEL]\n"}
            report = self.diagnostic_refusal(item, fs)
            self.assertEqual("partial", report["state"])
            self.assertEqual("loader-observation", report["phase"])
            self.assertEqual("tool-loader", report["reason"])
            self.assertNotIn("diagnostic_observation_status", report)
            self.assertFalse(report["runtime_authorized"] or item.root_launches)
        with self.gpg_dependency_model() as (item, fs, _, _, _):
            write = item.write.side_effect
            def broken_result(name, raw):
                if name == "gpg-diagnostic-result.private.json":
                    raise PermissionError("PRIVATE_SENTINEL")
                return write(name, raw)
            with patch.object(item, "write", side_effect=broken_result), \
                    patch.object(os.path, "abspath", side_effect=fs.canonical), \
                    self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                item.preflight()
            self.assertEqual({"state": "retention-failed", "reason": "retention"},
                             item.receipt["gpg_diversion_diagnostic"])
            self.assertTrue(any("gpg-diagnostic-command-" in path and path.endswith(".stdout.private")
                                for path in fs.nodes))
            self.assertFalse(item.setup_ready or item.root_launches)

    @contextmanager
    def gpg_diagnostic_model(self, local=False):
        with self.model() as (item, fs):
            self.preflight_inputs(item, fs)
            saved = "/usr/bin/gpg.saved"
            fs.add(saved, elf() + b"original", mode=stat.S_IFREG | 0o755)
            fs.packages[saved] = "gpg"
            for package in ("gpg", "image-diverter"):
                item.installed[package] = "1"
                item.installed_records.append({"Package": package, "Version": "1",
                    "Architecture": "amd64", "Status": "install ok installed"})
                fs.add("/var/lib/dpkg/info/" + package + ".list", b"/usr/bin/gpg\n")
                fs.add("/var/lib/dpkg/info/" + package + ".md5sums",
                    (hashlib.md5(fs.nodes[saved][1]).hexdigest() + "  usr/bin/gpg\n").encode())
            fs.add("/var/lib/dpkg/status", b"".join(
                ("\n".join(key + ": " + value for key, value in row.items()) + "\n\n").encode()
                for row in item.installed_records))
            declaring = ":" if local else "image-diverter"
            fs.add("/var/lib/dpkg/diversions",
                   ("/usr/bin/gpg\n" + saved + "\n" + declaring + "\n").encode())
            def store(name, raw):
                if name.endswith((".json", ".sha256.private")):
                    BASE.require(item.record_bytes + len(raw) <= MODULE.CAPTURE.RECORDS,
                                 "retention-budget")
                    item.record_bytes += len(raw)
                fs.add(str(item.directory / name), raw, mode=stat.S_IFREG | 0o600, uid=1000)
            def capture(operation, argv, stdin=None):
                record = MODULE.CAPTURE.Capture.reserve_capture(item, operation, argv, stdin)
                if operation == "owner":
                    argument = argv[-1]
                    if argument == "/usr/bin/gpg":
                        stdout = (("local diversion " if local else "diversion by image-diverter ")
                                  + "from: /usr/bin/gpg\n"
                                  + ("local diversion " if local else "diversion by image-diverter ")
                                  + "to: " + saved + "\n").encode()
                    else:
                        owner = fs.packages.get(argument)
                        stdout = (owner + ": " + argument + "\n").encode() if owner else b""
                    stdout = getattr(fs, "owner_responses", {}).get(argument, stdout)
                elif operation == "package":
                    package = argv[-1]
                    row = next(row for row in item.installed_records if row["Package"] == package)
                    stdout = ("\t".join((package, row["Version"], row["Architecture"],
                                         package, row["Version"])) + "\n").encode()
                    if package == "gpg" and getattr(fs, "bad_metadata", False):
                        stdout = b"gpg\t2\tamd64\tgpg\t2\n"
                elif operation == "elf":
                    stdout = getattr(fs, "elf_responses", {}).get(argv[-1], b"")
                else:
                    raise AssertionError("Unapproved diagnostic operation")
                prefix = record.get("capture_prefix", "command-%d" % len(item.commands))
                record.update(exit=0, stdout_eof=True, stderr_eof=True, retention_failed=False)
                for label, raw in (("stdout", stdout), ("stderr", b"")):
                    store(prefix + "." + label + ".private", raw)
                    record[label + "_identity"] = BASE.original_identity(
                        fs.info(str(item.directory / (prefix + "." + label + ".private"))))
                    record[label + "_bytes"] = len(raw)
                    record[label + "_sha256"] = MODULE.POLICY.digest(raw)
                store(prefix + ".observed.private.json", json.dumps(record, sort_keys=True).encode())
                return record, stdout, b""
            with patch.object(item, "write", side_effect=store), \
                    patch.object(item, "capture", side_effect=capture), \
                    patch.object(item, "command", side_effect=lambda op, arg:
                                 MODULE.Provider.command(item, op, arg)):
                yield item, fs, saved

    def diagnostic_refusal(self, item, fs):
        with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                self.assertRaisesRegex(BASE.Refusal, "^tool-origin$") as refusal:
            item.preflight()
        self.assertEqual("owner-response-shape", refusal.exception.origin_check)
        return json.loads(fs.nodes[str(item.directory / "gpg-diagnostic-result.private.json")][1])

    def test_gpg_diagnostic_collects_current_bound_data_without_admission(self):
        with self.gpg_diagnostic_model() as (item, fs, saved):
            deadline = item.deadline
            report = self.diagnostic_refusal(item, fs)
            self.assertEqual("collected", report["state"])
            self.assertEqual(saved, report["mapping"]["saved"])
            self.assertEqual({"gpg", "image-diverter"}, set(report["packages"]))
            self.assertEqual("current-invocation", report["observation"])
            self.assertFalse(report["historical_state_reconstructed"] or report["runtime_authorized"])
            self.assertEqual("unresolved", report["original_package_authority"])
            self.assertTrue(any(row["digest_matches"] for row in report["manifest_associations"]))
            self.assertEqual(deadline, item.deadline)
            self.assertEqual(0, item.root_launches)
            self.assertFalse(item.setup_ready or item.receipt["installed_authority_verified"]
                             or item.receipt["signed_transaction_authorized"])
            self.assertEqual(4, len(report["commands"]))
            self.assertTrue(any("capture_prefix" in command for command in item.commands))
            self.assertFalse(item.gpg_diagnostic_active)
            self.assertEqual((), item._gpg_diagnostic_paths)

    def test_gpg_diagnostic_local_mapping_is_observation_not_authority(self):
        with self.gpg_diagnostic_model(local=True) as (item, fs, _):
            report = self.diagnostic_refusal(item, fs)
            self.assertEqual("collected", report["state"])
            self.assertTrue(report["mapping"]["local"])
            self.assertEqual({"gpg"}, set(report["packages"]))
            self.assertFalse(report["runtime_authorized"] or item.setup_ready)

    def test_gpg_diagnostic_rejects_malformed_ambiguous_and_escaping_domains(self):
        cases = (
            b"/usr/bin/gpg\n/saved\n",
            b"/usr/bin/gpg\n/usr/bin/gpg\n:\n",
            b"/usr/bin/gpg\n/PRIVATE_SENTINEL\n:\n",
            b"/usr/bin/gpg\n/usr/bin/../gpg.saved\n:\n",
            b"/usr/bin/gpg\n/usr/bin/gpg.saved\n:\n" * 2,
            b"/usr/bin/gpg\n/usr/bin/gpg.saved\nbad package\n",
            b"/usr/bin/gpg\n/usr/bin/gpg.saved\n:\n/usr/bin/gpg.saved\n/usr/bin/chain\n:\n",
            b"/usr/bin/foreign\n/usr/bin/foreign.saved\n:\n",
        )
        for table in cases:
            with self.subTest(table=table), self.gpg_diagnostic_model() as (item, fs, _):
                fs.add("/var/lib/dpkg/diversions", table)
                report = self.diagnostic_refusal(item, fs)
                self.assertEqual("partial", report["state"])
                self.assertEqual("diversion-table", report["phase"])
                self.assertIn("diversion_table", report)
                self.assertFalse(report["runtime_authorized"])
                self.assertEqual({}, report["packages"])

    def test_gpg_diagnostic_preserves_prefix_on_metadata_and_identity_failure(self):
        for fault in ("missing-list", "metadata-version", "symlink", "unsafe-mode"):
            with self.subTest(fault=fault), self.gpg_diagnostic_model() as (item, fs, saved):
                if fault == "missing-list":
                    del fs.nodes["/var/lib/dpkg/info/gpg.list"]
                elif fault == "metadata-version":
                    fs.bad_metadata = True
                elif fault == "symlink":
                    fs.add(saved, mode=stat.S_IFLNK | 0o777, target="/PRIVATE_SENTINEL")
                else:
                    fs.info(saved).st_mode |= 0o002
                report = self.diagnostic_refusal(item, fs)
                self.assertEqual("partial", report["state"])
                self.assertEqual(4, len(report["commands"]))
                self.assertIn("diversion_table", report)
                self.assertTrue(all(str(item.directory / row["file"]) in fs.nodes
                                    for row in report["commands"]))
                self.assertFalse(item.setup_ready or item.root_launches)

    def test_gpg_diagnostic_rejects_drift_at_final_recheck(self):
        with self.gpg_diagnostic_model() as (item, fs, saved):
            read = item.read.side_effect
            def drift(path, owner=0, limit=BASE.FILE_LIMIT):
                if path == saved and item.gpg_diagnostic_active:
                    fs.info(saved).st_ctime_ns += 1
                return read(path, owner, limit)
            with patch.object(item, "read", side_effect=drift):
                report = self.diagnostic_refusal(item, fs)
            self.assertEqual("partial", report["state"])
            self.assertFalse(item.setup_ready or item.root_launches)

    def test_gpg_diagnostic_limits_are_conserved_and_native_prefix_retained(self):
        for budget in ("commands", "pins", "records", "metadata"):
            with self.subTest(budget=budget), self.gpg_diagnostic_model() as (item, fs, _):
                original = item.gpg_diversion_diagnostic
                def constrained():
                    if budget == "commands":
                        while len(item.commands) < MODULE.CAPTURE.COMMANDS:
                            item.commands.append({"operation": "placeholder", "argv": []})
                    elif budget == "pins":
                        item._retired_pins = BASE.FILE_COUNT - len(item.pins)
                    else:
                        if budget == "records":
                            item.record_bytes = MODULE.CAPTURE.RECORDS
                        else:
                            item.metadata_bytes = 67108864
                    if budget == "metadata":
                        with patch.object(item, "retain", side_effect=lambda label, raw:
                                          MODULE.Provider.retain_metadata(item, label, raw)):
                            original()
                    else:
                        original()
                with patch.object(item, "gpg_diversion_diagnostic", side_effect=constrained):
                    with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                            self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                        item.preflight()
                self.assertIn(item.receipt["gpg_diversion_diagnostic"]["state"],
                              ("partial", "retention-failed"))
                self.assertEqual(0, item.root_launches)
                self.assertFalse(item.setup_ready)
                self.assertTrue(any(name.endswith(".stdout.private") for name in fs.nodes))

    def test_gpg_diagnostic_capture_rejects_root_and_foreign_alias_queries(self):
        with self.gpg_diagnostic_model() as (item, fs, _):
            item.gpg_diagnostic_active = True
            item._gpg_diagnostic_paths = ("/usr/bin/gpg", "/usr/bin/gpg.saved")
            with self.assertRaisesRegex(BASE.Refusal, "^unsafe-input$"):
                item.command("owner", "/usr/bin/unbound")
            for operation in MODULE.POLICY.OPERATIONS:
                with self.subTest(operation=operation), self.assertRaises(BASE.Refusal):
                    MODULE.CAPTURE.Capture.reserve_capture(item, operation, ("/usr/bin/sudo",))
            self.assertEqual(0, item.root_launches)
            self.assertEqual([], item.commands)

    def test_gpg_diagnostic_exact_table_alias_query_does_not_change_normal_owner_policy(self):
        with self.gpg_diagnostic_model() as (item, fs, saved):
            fs.add("/usr/bin/gpg", mode=stat.S_IFLNK | 0o777, target=saved)
            fs.owner_responses = {
                saved: ("diversion by image-diverter from: /usr/bin/gpg\n"
                        "diversion by image-diverter to: " + saved + "\n").encode(),
                "/usr/bin/gpg": b"gpg: /usr/bin/gpg\n",
            }
            report = self.diagnostic_refusal(item, fs)
            self.assertEqual("collected", report["state"])
            self.assertEqual(saved, report["files"]["/usr/bin/gpg"]["canonical"])
            self.assertIn("/usr/bin/gpg", report["files"]["/usr/bin/gpg"]["aliases"])
            with self.assertRaisesRegex(BASE.Refusal, "^unsafe-input$"):
                item.command("owner", "/usr/bin/gpg")
            self.assertFalse(item.setup_ready or item.root_launches)

    def test_gpg_diagnostic_deadline_failure_keeps_original_scoped_capture(self):
        with self.gpg_diagnostic_model() as (item, fs, _):
            collect = item.gpg_diversion_diagnostic
            def expire():
                item.deadline = 0
                collect()
            with patch.object(item, "gpg_diversion_diagnostic", side_effect=expire):
                with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                        self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                    item.preflight()
            self.assertEqual(0, item.deadline)
            self.assertEqual("partial", item.receipt["gpg_diversion_diagnostic"]["state"])
            self.assertTrue(any("gpg-diagnostic-command-" in name and name.endswith(".stdout.private")
                                for name in fs.nodes))
            self.assertFalse(item.setup_ready or item.root_launches)

    def test_gpg_diagnostic_static_loader_records_reuse_owner_manifest_and_package_checks(self):
        with self.gpg_diagnostic_model() as (item, fs, saved):
            library = "/usr/lib/x86_64-linux-gnu/libdiagnostic.so.1"
            fs.add(library, elf() + b"library", mode=stat.S_IFREG | 0o755)
            fs.packages[library] = "test-library"
            row = {"Package": "test-library", "Status": "install ok installed",
                   "Version": "1", "Architecture": "amd64"}
            item.installed_records.append(row)
            item.installed["test-library"] = "1"
            fs.add("/var/lib/dpkg/info/test-library.list", (library + "\n").encode())
            fs.add("/var/lib/dpkg/info/test-library.md5sums",
                   (hashlib.md5(fs.nodes[library][1]).hexdigest() + "  " + library[1:] + "\n").encode())
            fs.add("/var/lib/dpkg/status", b"".join(
                ("\n".join(key + ": " + value for key, value in record.items()) + "\n\n").encode()
                for record in item.installed_records))
            fs.elf_responses = {saved: b"(NEEDED) Shared library: [libdiagnostic.so.1]\n"}
            report = self.diagnostic_refusal(item, fs)
            self.assertEqual("collected", report["state"], report)
            self.assertIn(library, report["loader_files"])
            self.assertIn("test-library", report["packages"])
            self.assertEqual("unresolved", report["live_loader_state"])
            self.assertFalse(report["runtime_authorized"] or item.root_launches)

    def test_gpg_diagnostic_loader_refusal_does_not_replace_original_refusal(self):
        with self.gpg_diagnostic_model() as (item, fs, saved):
            fs.elf_responses = {saved: b"(RPATH) Library rpath: [/PRIVATE_SENTINEL]\n"}
            report = self.diagnostic_refusal(item, fs)
            self.assertEqual("partial", report["state"])
            self.assertEqual("tool-loader", report["reason"])
            self.assertEqual("loader-observation", report["phase"])
            self.assertEqual(4, len(report["commands"]))
            self.assertFalse(item.setup_ready or item.root_launches)

    def test_gpg_diagnostic_public_summary_and_projection_are_closed(self):
        for state, reason in (("collected", None), ("partial", "file-budget"),
                              ("retention-failed", "retention")):
            public = MODULE.gpg_diagnostic_summary({"state": state, "reason": reason})
            self.assertFalse(public["runtime_authorized"] or public["historical_state_reconstructed"])
            self.assertNotIn("/usr/bin", json.dumps(public))
        for foreign in ({"state": "collected", "reason": "tool-origin"},
                        {"state": "partial", "reason": None},
                        {"state": "/PRIVATE_SENTINEL", "reason": None},
                        {"state": "partial", "reason": "file-budget", "path": "/PRIVATE_SENTINEL"}):
            with self.assertRaisesRegex(BASE.Refusal, "^inventory-shape$"):
                MODULE.gpg_diagnostic_summary(foreign)
        with self.gpg_diagnostic_model() as (item, fs, _):
            self.diagnostic_refusal(item, fs)
            with patch("builtins.print") as output:
                self.assertFalse(MODULE.projection(item, None, "tool-origin"))
            text = output.call_args.args[0]
            public = json.loads(text.split(" ", 1)[1])
            self.assertEqual("collected", public["gpg_diversion_diagnostic"]["state"])
            for secret in ("gpg.saved", "image-diverter", "/usr/bin", "raw", "argv"):
                self.assertNotIn(secret, text)
            self.assertFalse(public["signed_transaction_authorized"]
                             or public["installed_authority_verified"]
                             or public["native_inventory_completed"])

    def test_gpg_diagnostic_is_only_wired_to_exact_diversion_refusal(self):
        with self.model() as (item, fs):
            self.preflight_inputs(item, fs)
            del fs.packages["/usr/bin/gpg"]
            with patch.object(item, "gpg_diversion_diagnostic") as collect:
                with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                        self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                    item.preflight()
                collect.assert_not_called()
        workflow_root = SOURCE.parents[1] / ".github" / "workflows"
        e2e = (workflow_root / "e2e-qa.yml").read_text()
        package = (workflow_root / "release-package-qa.yml").read_text()
        self.assertEqual(2, e2e.count("name: Retain scoped private GPG diagnostic evidence"))
        self.assertEqual(1, package.count("name: Retain scoped private GPG diagnostic evidence"))
        for text in (e2e, package):
            self.assertIn("always() && steps.php-permissions.outcome == 'failure'", text)
            self.assertNotIn("path: ${{ runner.temp }}/wstm-prerequisite-*/\n", text)

    @contextmanager
    def perl_model(self, version="5.38.2-3.2ubuntu0.2"):
        with self.model() as (item, fs), ExitStack() as stack:
            row = {"Package": "perl-base", "Status": "install ok installed",
                   "Architecture": "amd64", "Version": version, "Source": "perl",
                   "Multi-Arch": "foreign"}
            item.installed_records.append(row)
            item.installed["perl-base"] = version
            peer = "/usr/bin/perl" + version.split(":")[-1].split("-")[0]
            info, raw, _ = fs.nodes["/usr/bin/perl"]
            info.st_nlink = 2
            fs.nodes[peer] = (info, raw, None)
            fs.packages["/usr/bin/perl"] = "perl-base"
            fs.packages[peer] = "perl-base"
            fs.add("/var/lib/dpkg/info/perl-base.list",
                   ("/.\n/usr\n/usr/bin\n/usr/bin/perl\n" + peer + "\n").encode())
            fs.add("/var/lib/dpkg/info/perl-base.md5sums",
                   b"".join((hashlib.md5(raw).hexdigest() + "  " + path[1:] + "\n").encode()
                            for path in ("/usr/bin/perl", peer)))
            old_command = item.command.side_effect
            queries = []
            def command(operation, argument):
                queries.append((operation, argument))
                if operation == "package" and argument == "perl-base":
                    return ("\t".join(item.perl_installed_package()) + "\n").encode()
                return old_command(operation, argument)
            stack.enter_context(patch.object(item, "command", side_effect=command))
            def read(path, owner=0, limit=BASE.FILE_LIMIT):
                if item.perl_alias_binding is not None and path in item.perl_alias_binding["paths"]:
                    return MODULE.Provider.read(item, path, owner, limit)
                return fs.read(path, owner, limit)
            stack.enter_context(patch.object(item, "read", side_effect=read))
            descriptors = {}
            acquisitions = []
            def opening(path, flags):
                self.assertEqual(os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, flags)
                acquisitions.append(path)
                descriptor = len(acquisitions) + 10
                node = fs.nodes[path]
                descriptors[descriptor] = [node[0], node[1], 0]
                return descriptor
            def reading(descriptor, size):
                held = descriptors[descriptor]
                raw = held[1][held[2]:held[2] + size]
                held[2] += len(raw)
                return raw
            stack.enter_context(patch.object(os, "O_NOFOLLOW", 0x20000, create=True))
            stack.enter_context(patch.object(os, "O_CLOEXEC", 0x80000, create=True))
            stack.enter_context(patch.object(os, "open", side_effect=opening))
            stack.enter_context(patch.object(os, "fstat", side_effect=lambda fd: descriptors[fd][0]))
            stack.enter_context(patch.object(os, "read", side_effect=reading))
            stack.enter_context(patch.object(os, "close", side_effect=lambda fd: descriptors.pop(fd)))
            yield item, fs, peer, acquisitions, queries
            self.assertEqual({}, descriptors)

    def test_perl_alias_actual_preflight_pins_both_then_requires_origin_and_loader(self):
        with self.perl_model() as (item, fs, peer, acquisitions, queries):
            self.preflight_inputs(item, fs)
            command = item.command.side_effect
            def failed_loader(operation, argument):
                if (operation, argument) == ("elf", "/usr/bin/perl"):
                    raise BASE.Refusal("tool-loader")
                return command(operation, argument)
            deadline = item.deadline
            with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                    patch.object(item, "command", side_effect=failed_loader), \
                    self.assertRaisesRegex(BASE.Refusal, "^tool-loader$"):
                item.preflight()
            self.assertEqual(["/usr/bin/perl", peer], acquisitions)
            self.assertTrue(item.receipt["perl_alias_domain"]["installed_origin_verified"])
            for path in ("/usr/bin/perl", peer):
                self.assertIn(("owner", path), queries)
                self.assertEqual(item.perl_installed_package(), item.origins[path]["package"])
                self.assertIsNone(item.origins[path]["source_archive_sha256"])
            self.assertEqual(deadline, item.deadline)
            self.assertFalse(item.setup_ready or item.receipt["signed_transaction_authorized"]
                             or item.receipt["installed_authority_verified"])
            self.assertEqual(0, item.root_launches)
            self.assertEqual([], item.commands)

    def test_perl_alias_binding_is_data_until_both_owner_and_package_checks(self):
        for version in ("5.38.2-3.2ubuntu0.2", "1:5.40.1-2"):
            with self.subTest(version=version), self.perl_model(version) as (
                    item, fs, peer, acquisitions, queries):
                item.system("/usr/bin/perl")
                self.assertFalse(item.receipt["perl_alias_domain"]["installed_origin_verified"])
                self.assertNotIn("/usr/bin/perl", item.origins)
                self.assertEqual(fs.nodes[peer][1], item.read(peer)[2])
                item.origin("/usr/bin/perl")
                self.assertTrue(item.receipt["perl_alias_domain"]["installed_origin_verified"])
                self.assertEqual(2, len([q for q in queries if q[0] == "owner"]))
                self.assertEqual(1, len([q for q in queries if q[0] == "package"]))
                self.assertEqual(fs.nodes[peer][1], item.acquire(peer)[2])
                with self.assertRaisesRegex(BASE.Refusal, "^tool-identity$"):
                    BASE.validate_file(fs.info(peer), 0, BASE.FILE_LIMIT)

    def test_perl_alias_rejects_unbound_owner_and_metadata(self):
        for fault in ("missing-owner", "foreign-owner", "foreign-source", "wrong-version",
                      "wrong-arch", "duplicate-installed", "qualified", "malformed-version"):
            with self.subTest(fault=fault), self.perl_model() as (item, fs, peer, _, _):
                if fault == "missing-owner":
                    del fs.packages[peer]
                elif fault == "foreign-owner":
                    fs.packages[peer] = "coreutils"
                    fs.add("/var/lib/dpkg/info/coreutils.md5sums",
                           (hashlib.md5(elf()).hexdigest() + "  " + peer[1:] + "\n").encode())
                elif fault in ("foreign-source", "wrong-arch", "qualified", "malformed-version"):
                    row = next(r for r in item.installed_records if r["Package"] == "perl-base")
                    key, value = {"foreign-source": ("Source", "foreign"),
                                  "wrong-arch": ("Architecture", "arm64"),
                                  "qualified": ("Multi-Arch", "same"),
                                  "malformed-version": ("Version", "../5.38.2")}[fault]
                    row[key] = value
                elif fault == "duplicate-installed":
                    item.installed_records.append(dict(
                        next(r for r in item.installed_records if r["Package"] == "perl-base")))
                else:
                    command = item.command.side_effect
                    def wrong_version(operation, argument):
                        if (operation, argument) == ("package", "perl-base"):
                            return b"perl-base\t5.38.2-foreign\tamd64\tperl\t5.38.2-foreign\n"
                        return command(operation, argument)
                    item.command.side_effect = wrong_version
                with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                    item.origin("/usr/bin/perl")
                self.assertFalse(item.receipt.get("perl_alias_domain", {}).get(
                    "installed_origin_verified", False))

    def test_perl_alias_rejects_incomplete_or_foreign_listing_and_digest(self):
        for fault in ("missing", "extra", "duplicate", "whitespace", "dotdot",
                      "nonascii", "foreign-md5", "missing-md5", "duplicate-md5"):
            with self.subTest(fault=fault), self.perl_model() as (item, fs, peer, _, _):
                path = "/var/lib/dpkg/info/perl-base.list"
                raw = fs.nodes[path][1]
                if fault == "missing":
                    raw = raw.replace((peer + "\n").encode(), b"")
                elif fault == "extra":
                    raw += b"/usr/bin/perl-foreign\n"
                elif fault == "duplicate":
                    raw += (peer + "\n").encode()
                elif fault == "whitespace":
                    raw += b"/usr/bin/perl foreign\n"
                elif fault == "dotdot":
                    raw += b"/usr/bin/../perl\n"
                elif fault == "nonascii":
                    raw += b"/usr/bin/\xff\n"
                else:
                    path = "/var/lib/dpkg/info/perl-base.md5sums"
                    raw = fs.nodes[path][1]
                    if fault == "foreign-md5":
                        raw = raw.replace(hashlib.md5(elf()).hexdigest().encode(), b"0" * 32)
                    elif fault == "missing-md5":
                        raw = raw.splitlines(keepends=True)[0]
                    else:
                        raw += raw.splitlines(keepends=True)[0]
                fs.add(path, raw)
                with self.assertRaises(BASE.Refusal):
                    item.system("/usr/bin/perl")

    def test_perl_alias_rejects_identity_parent_capability_and_architecture_faults(self):
        for fault in ("third-link", "different-inode", "symlink", "owner", "mode",
                      "parent", "capability", "elf-arch"):
            with self.subTest(fault=fault), self.perl_model() as (item, fs, peer, _, _):
                if fault == "third-link":
                    fs.info(peer).st_nlink = 3
                elif fault == "different-inode":
                    fs.add(peer, elf(), mode=stat.S_IFREG | 0o755).st_nlink = 2
                elif fault == "symlink":
                    fs.nodes[peer] = (fs.info(peer), elf(), "/usr/bin/perl")
                elif fault == "owner":
                    fs.info(peer).st_uid = 1000
                elif fault == "mode":
                    fs.info(peer).st_mode |= 0o2000
                elif fault == "parent":
                    fs.info("/usr/bin").st_mode |= 0o002
                elif fault == "elf-arch":
                    raw = bytearray(elf())
                    struct.pack_into("<H", raw, 18, 183)
                    for path in ("/usr/bin/perl", peer):
                        fs.nodes[path] = (fs.info(path), bytes(raw), None)
                    md5 = hashlib.md5(raw).hexdigest()
                    fs.add("/var/lib/dpkg/info/perl-base.md5sums",
                           (md5 + "  usr/bin/perl\n" + md5 + "  " + peer[1:] + "\n").encode())
                with ExitStack() as stack:
                    if fault == "capability":
                        stack.enter_context(patch.object(os, "getxattr", return_value=b"cap"))
                    with self.assertRaises(BASE.Refusal):
                        item.system("/usr/bin/perl")

    def test_perl_alias_rechecks_body_metadata_members_and_absence_of_extra_links(self):
        for fault in ("body", "manifest", "list", "metadata", "identity", "parent",
                      "extra-link", "retired-pin"):
            with self.subTest(fault=fault), self.perl_model() as (item, fs, peer, _, _):
                item.origin("/usr/bin/perl")
                if fault == "body":
                    info, raw, target = fs.nodes[peer]
                    fs.nodes[peer] = (info, raw[:-1] + b"x", target)
                elif fault in ("manifest", "list"):
                    path = "/var/lib/dpkg/info/perl-base." + (
                        "md5sums" if fault == "manifest" else "list")
                    info, raw, target = fs.nodes[path]
                    fs.nodes[path] = (info, raw[:-1] + b"x", target)
                elif fault == "metadata":
                    next(r for r in item.installed_records if r["Package"] == "perl-base")[
                        "Version"] = "5.38.2-foreign"
                elif fault == "identity":
                    fs.info(peer).st_ctime_ns += 1
                elif fault == "parent":
                    fs.info("/usr/bin").st_ctime_ns += 1
                elif fault == "extra-link":
                    fs.info(peer).st_nlink = 3
                else:
                    item.pins.pop(peer)
                with self.assertRaises(BASE.Refusal):
                    item.read(peer)
                self.assertEqual(0, item.root_launches)

    def test_perl_alias_reserves_original_budgets_before_body_reads_without_refunds(self):
        for fault in ("bytes", "pins", "deadline"):
            with self.subTest(fault=fault), self.perl_model() as (item, fs, peer, reads, _):
                for suffix in ("list", "md5sums"):
                    item.protected("/var/lib/dpkg/info/perl-base." + suffix, 4194304)
                if fault == "bytes":
                    item.bytes = BASE.TOTAL_LIMIT - 127
                elif fault == "pins":
                    item._retired_pins = BASE.FILE_COUNT - len(item.pins) - len(item.session_data_pins) - 1
                else:
                    item.deadline = 0
                before = item.bytes
                with self.assertRaises(BASE.Refusal):
                    item.system("/usr/bin/perl")
                self.assertEqual([], reads)
                self.assertGreaterEqual(item.bytes, before)
                self.assertNotIn(peer, item.pins)
        with self.perl_model() as (item, fs, peer, reads, _):
            path = "/var/lib/dpkg/info/perl-base.md5sums"
            fs.add(path, b"0" * 32 + b"  usr/bin/perl\n")
            before = item.bytes
            with self.assertRaises(BASE.Refusal):
                item.system("/usr/bin/perl")
            self.assertEqual(["/usr/bin/perl"], reads)
            self.assertEqual("reserved", item.pins[peer]["state"])
            self.assertGreaterEqual(item.bytes - before, 128)
            self.assertFalse(item.receipt.get("perl_alias_domain", {}).get(
                "installed_origin_verified", False))

    def test_perl_alias_never_admits_unrelated_multilink_tools(self):
        for path in ("/usr/bin/python3", "/usr/bin/perl-foreign"):
            with self.subTest(path=path), self.perl_model() as (item, fs, _, _, _):
                fs.add(path, elf(), mode=stat.S_IFREG | 0o755).st_nlink = 2
                with self.assertRaisesRegex(BASE.Refusal, "^tool-identity$"):
                    item.system(path)

    def test_perl_parent_timestamps_refresh_only_after_actual_authorized_install(self):
        for changed in (("/usr/bin",), ("/var/lib/dpkg/info",),
                        ("/usr/bin", "/var/lib/dpkg/info")):
            with self.subTest(changed=changed), self.perl_model() as (
                    item, fs, peer, _, _), self.transaction(item):
                self.scan_transaction(item)
                deadline, acquired = item.deadline, item.bytes
                identity = dict(item.pins[peer]["identity"])
                with self.modeled_apt_capture(item, fs):
                    capture = item.capture.side_effect
                    def installed(operation, argv, stdin=None):
                        result = capture(operation, argv, stdin)
                        if operation == "apt-install":
                            records = MODULE.POLICY.deb822(fs.nodes["/var/lib/dpkg/status"][1])
                            perl = next(row for row in records if row["Package"] == "perl-base")
                            perl.update(Architecture="amd64", Source="perl",
                                        **{"Multi-Arch": "foreign"})
                            raw = "\n\n".join("\n".join(k + ": " + v for k, v in row.items())
                                              for row in records).encode() + b"\n"
                            fs.add("/var/lib/dpkg/status", raw)
                            for parent in changed:
                                fs.info(parent).st_ctime_ns += 1
                                fs.info(parent).st_mtime_ns += 1
                        return result
                    with patch.object(item, "capture", side_effect=installed):
                        item.configure()
                self.assertTrue(item.receipt["installed_authority_verified"])
                self.assertEqual(identity, item.pins[peer]["identity"])
                self.assertEqual(deadline, item.deadline)
                self.assertGreaterEqual(item.bytes, acquired)
                self.assertEqual(["apt-install", "select-php"],
                                 [row["operation"] for row in item.commands])
                self.assertEqual(2, item.root_launches)
                self.assertTrue(all(row["producer_pid"] is None for row in item.commands))
                for path in (*item.perl_alias_binding["paths"], *item.perl_alias_binding["metadata"]):
                    self.assertEqual(fs.parents(path), item.pins[path]["parents"])
                item.read(peer)
                item.origin("/usr/bin/perl")
                fs.info("/usr/bin").st_ctime_ns += 1
                with self.assertRaisesRegex(BASE.Refusal, "^tool-link$"):
                    item.read(peer)

    def test_perl_parent_refresh_rejects_unauthorized_or_unconsumed_scope(self):
        for scope in ("none", "unconsumed", "wrong-operation"):
            with self.subTest(scope=scope), self.perl_model() as (item, fs, peer, _, _):
                with ExitStack() as stack:
                    if scope != "none":
                        stack.enter_context(self.transaction(item))
                        self.scan_transaction(item)
                    else:
                        item.origin("/usr/bin/perl")
                    if scope == "wrong-operation":
                        with self.modeled_apt_capture(item, fs), item.transaction_admission("apt-install"):
                            item.root("apt-install")
                        item.root_cursor += 1
                    before = item.pins[peer]["parents"]
                    fs.info("/usr/bin").st_ctime_ns += 1
                    fs.info("/var/lib/dpkg/info").st_ctime_ns += 1
                    with self.assertRaisesRegex(BASE.Refusal, "^tool-link$"):
                        item.read(peer)
                    with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                        item.refresh_install_parents()
                    self.assertIs(before, item.pins[peer]["parents"])
                    self.assertFalse(item.receipt["installed_authority_verified"])

    def test_perl_authorized_parent_refresh_retains_substitution_and_drift_rejections(self):
        faults = ("body", "manifest", "listing", "inode", "symlink", "nlink",
                  "capability", "metadata", "parent-inode", "parent-mode", "parent-owner")
        for fault in faults:
            with self.subTest(fault=fault), self.perl_model() as (
                    item, fs, peer, _, _), self.transaction(item):
                self.scan_transaction(item)
                with self.modeled_apt_capture(item, fs), item.transaction_admission("apt-install"):
                    item.root("apt-install")
                paths = (*item.perl_alias_binding["paths"], *item.perl_alias_binding["metadata"])
                before = {path: item.pins[path]["parents"] for path in paths}
                old_metadata = {path: data["parents"] for path, data in
                                item.perl_alias_binding["metadata"].items()}
                fs.info("/usr/bin").st_ctime_ns += 1
                fs.info("/var/lib/dpkg/info").st_ctime_ns += 1
                if fault in ("body", "manifest", "listing"):
                    path = (peer if fault == "body" else "/var/lib/dpkg/info/perl-base."
                            + ("md5sums" if fault == "manifest" else "list"))
                    info, raw, target = fs.nodes[path]
                    fs.nodes[path] = (info, raw[:-1] + b"x", target)
                elif fault == "inode":
                    fs.add(peer, elf(), mode=stat.S_IFREG | 0o755).st_nlink = 2
                elif fault == "symlink":
                    fs.nodes[peer] = (fs.info(peer), elf(), "/usr/bin/perl")
                elif fault == "nlink":
                    fs.info(peer).st_nlink = 3
                elif fault == "metadata":
                    next(row for row in item.installed_records if row["Package"] == "perl-base")[
                        "Version"] = "5.38.2-foreign"
                elif fault == "parent-inode":
                    fs.info("/var/lib/dpkg/info").st_ino += 1
                elif fault == "parent-mode":
                    fs.info("/usr/bin").st_mode |= 0o002
                elif fault == "parent-owner":
                    fs.info("/usr/bin").st_uid = 1000
                with ExitStack() as stack:
                    if fault == "capability":
                        stack.enter_context(patch.object(os, "getxattr", return_value=b"cap"))
                    with self.assertRaises(BASE.Refusal):
                        item.refresh_install_parents()
                for path in paths:
                    self.assertIs(before[path], item.pins[path]["parents"])
                for path, data in item.perl_alias_binding["metadata"].items():
                    self.assertIs(old_metadata[path], data["parents"])
                self.assertFalse(item.receipt["installed_authority_verified"])

    def preflight_inputs(self, item, fs):
        raw = "\n\n".join("\n".join(key + ": " + value for key, value in row.items())
                          for row in item.installed_records).encode() + b"\n"
        fs.add("/var/lib/dpkg/status", raw)
        fs.add("/usr/lib/os-release", b'ID=ubuntu\nVERSION_ID="24.04"\n')

    def test_actual_preflight_attributes_identity_predicates_without_launches(self):
        faults = (("st_mode", stat.S_IFDIR | 0o755, "regular-file"),
                  ("st_mode", stat.S_IFREG | 0o4755, "safe-mode"),
                  ("st_nlink", 2, "single-link"))
        for field, value, check in faults:
            with self.subTest(check=check), self.model() as (item, fs):
                self.preflight_inputs(item, fs)
                path = MODULE.TOOLS[0]
                self.assertNotIn(path, item.files)
                setattr(fs.info(path), field, value)
                deadline = item.deadline
                with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                        self.assertRaisesRegex(BASE.Refusal, "^tool-identity$"):
                    item.preflight()
                self.assertEqual({"step": "tool-file", "requested_tool": "python3",
                                  "identity_check": check}, item.receipt["preflight_refusal"])
                self.assertEqual(deadline, item.deadline)
                self.assertEqual(0, item.root_launches)
                self.assertEqual([], item.commands)
                self.assertFalse(item.setup_ready)
                self.assertFalse(item.receipt["signed_transaction_authorized"])

    def test_requested_tool_owner_guard_is_not_relaxed_for_attribution(self):
        with self.model() as (item, fs):
            self.preflight_inputs(item, fs)
            fs.info(MODULE.TOOLS[0]).st_uid = 1000
            with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                    self.assertRaisesRegex(BASE.Refusal, "^tool-link$"):
                item.preflight()
            self.assertIsNone(item.receipt["preflight_refusal"])
            self.assertEqual(0, item.root_launches)

    def test_normal_tool_identity_continues_to_existing_origin_boundary(self):
        with self.model() as (item, fs):
            self.preflight_inputs(item, fs)
            with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                    patch.object(item, "command", side_effect=BASE.Refusal("tool-origin")), \
                    self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                item.preflight()
            self.assertTrue(all(os.path.realpath(path) in item.files for path in MODULE.TOOLS))
            self.assertEqual({"step": "tool-origin", "requested_tool": "python3",
                              "identity_check": None, "origin_check": "owner-command"},
                             item.receipt["preflight_refusal"])
            self.assertFalse(item.setup_ready)
            self.assertEqual(0, item.root_launches)

    def test_origin_witness_attributes_every_fixed_root_tool_without_launches(self):
        for path in MODULE.TOOLS:
            with self.subTest(tool=MODULE.TOOL_IDS[path]), self.model() as (item, fs):
                self.preflight_inputs(item, fs)
                fs.packages.pop(path, None)
                deadline = item.deadline
                with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                        self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                    item.preflight()
                self.assertEqual({"step": "tool-origin", "requested_tool": MODULE.TOOL_IDS[path],
                                  "identity_check": None, "origin_check": "owner-missing"},
                                 item.receipt["preflight_refusal"])
                self.assertEqual(deadline, item.deadline)
                self.assertEqual(0, item.root_launches)
                self.assertFalse(item.setup_ready or item.receipt["signed_transaction_authorized"])

    def test_standard_and_cached_origin_checks_retain_exact_predicates(self):
        cases = ("owner-response-shape", "owner-target", "owner-ambiguous",
                 "package-metadata", "manifest-link", "manifest-identity",
                 "cached-manifest", "cached-package", "cached-association")
        for check in cases:
            with self.subTest(check=check), self.model() as (item, fs):
                path = "/usr/bin/python3"
                package = fs.packages[path]
                manifest = "/var/lib/dpkg/info/" + package + ".md5sums"
                command = item.command.side_effect
                if check.startswith("cached-"):
                    item.origin(path)
                    if check == "cached-manifest":
                        item.origins[path]["manifest_identity"] = dict(
                            item.origins[path]["manifest_identity"], ino=-1)
                    elif check == "cached-package":
                        item.packages.pop(package)
                    else:
                        data = item.packages[package]
                        item.packages[package] = (["foreign", *data[0][1:]], *data[1:])
                elif check == "manifest-link":
                    fs.nodes[manifest] = (*fs.nodes[manifest][:2], "/foreign-manifest")
                elif check == "manifest-identity":
                    metadata = [package, "1", "amd64", "source", "1"]
                    info, raw, _ = fs.nodes[manifest]
                    item.packages[package] = (metadata, manifest, info, raw)
                    fs.add(manifest, raw)
                else:
                    if check == "owner-ambiguous":
                        fs.add("/bin/python3", mode=stat.S_IFLNK | 0o777, target=path)
                    def query(operation, argument):
                        if operation == "owner":
                            if check == "owner-response-shape":
                                return b"not-an-owner-row\n"
                            if check == "owner-target":
                                return (package + ": /PRIVATE_SENTINEL\n").encode()
                            if check == "owner-ambiguous" and argument == "/bin/python3":
                                return b"foreign: /bin/python3\n"
                        if operation == "package" and check == "package-metadata":
                            return b"not-five-fields\n"
                        return command(operation, argument)
                    item.command.side_effect = query
                with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$") as caught:
                    item.origin(path)
                self.assertEqual(check, caught.exception.origin_check)

    def test_actual_perl_preflight_origin_predicates_remain_distinct(self):
        for check in ("perl-installed-metadata", "perl-installed-source",
                      "perl-list-shape", "perl-list-domain"):
            with self.subTest(check=check), self.perl_model() as (item, fs, peer, _, _):
                row = next(r for r in item.installed_records if r["Package"] == "perl-base")
                if check == "perl-installed-metadata":
                    row["Architecture"] = "arm64"
                elif check == "perl-installed-source":
                    row["Source"] = "foreign"
                else:
                    path = "/var/lib/dpkg/info/perl-base.list"
                    fs.add(path, fs.nodes[path][1] + (
                        b"/usr/bin/perl foreign\n" if check == "perl-list-shape"
                        else b"/usr/bin/perl-foreign\n"))
                self.preflight_inputs(item, fs)
                with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                        self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                    item.preflight()
                self.assertEqual({"step": "tool-file", "requested_tool": "perl",
                                  "identity_check": None, "origin_check": check},
                                 item.receipt["preflight_refusal"])
                self.assertFalse(item.setup_ready or item.receipt["signed_transaction_authorized"])
                self.assertEqual(0, item.root_launches)

    def test_origin_labels_preserve_exception_classes_reasons_and_success(self):
        for check in MODULE.ORIGIN_CHECKS:
            with self.subTest(check=check):
                self.assertIsNone(BASE.require(True, "tool-origin", origin_check=check))
                with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$") as base_error:
                    BASE.require(False, "tool-origin", origin_check=check)
                self.assertEqual(check, base_error.exception.origin_check)
                with self.assertRaisesRegex(TRUST.TrustError, "^tool-origin$") as trust_error:
                    TRUST.require(False, "tool-origin", origin_check=check)
                self.assertEqual(check, trust_error.exception.origin_check)
                with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$") as ca_error:
                    MODULE.CA._require(SimpleNamespace(BASE=BASE), False, origin_check=check)
                self.assertEqual(check, ca_error.exception.origin_check)
        with self.assertRaisesRegex(BASE.Refusal, "^file-budget$") as budget:
            BASE.require(False, "file-budget", origin_check="owner-target")
        self.assertFalse(hasattr(budget.exception, "origin_check"))

    def test_origin_witness_schema_is_closed_for_all_phases_tools_and_predicates(self):
        for step in MODULE.PREFLIGHT_STEPS:
            tools = MODULE.PREFLIGHT_TOOLS.get(step, (None,))
            for tool in tools:
                for check in MODULE.ORIGIN_CHECKS:
                    value = {"step": step, "requested_tool": tool, "identity_check": None,
                             "origin_check": check}
                    self.assertEqual(value, MODULE.preflight_witness(value, "preflight", "tool-origin"))
        value = {"step": "tool-origin", "requested_tool": "perl", "identity_check": None,
                 "origin_check": "owner-missing"}
        for foreign in (dict(value, path="/PRIVATE_SENTINEL"), dict(value, argv=["secret"]),
                        dict(value, package="SECRET_PACKAGE"), dict(value, origin_check="/PRIVATE_SENTINEL"),
                        dict(value, origin_check=None), dict(value, origin_check=[]),
                        dict(value, identity_check="single-link"), dict(value, requested_tool="/usr/bin/perl"),
                        dict(value, step="ca-trust", requested_tool="perl")):
            with self.subTest(value=foreign), self.assertRaisesRegex(BASE.Refusal, "^inventory-shape$"):
                MODULE.preflight_witness(foreign, "preflight", "tool-origin")
        for stage, reason in (("configuration", "tool-origin"), ("preflight", "tool-identity"),
                              ("preflight", None)):
            with self.assertRaisesRegex(BASE.Refusal, "^inventory-shape$"):
                MODULE.preflight_witness(value, stage, reason)

    def test_actual_origin_refusal_projection_has_no_private_data_or_authority(self):
        with self.model() as (item, fs):
            self.preflight_inputs(item, fs)
            del fs.packages["/usr/bin/python3"]
            with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                    self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                item.preflight()
            def store(name, raw):
                fs.add(str(item.directory / name), raw, mode=stat.S_IFREG | 0o600, uid=1000)
            with patch.object(item, "write", side_effect=store), patch("builtins.print") as output:
                self.assertFalse(MODULE.projection(item, None, "tool-origin"))
            public = json.loads(output.call_args.args[0].split(" ", 1)[1])
            self.assertEqual(item.receipt["preflight_refusal"], public["preflight_refusal"])
            self.assertEqual("owner-missing", public["preflight_refusal"]["origin_check"])
            self.assertFalse(any(public[name] for name in ("apt_original_accepted",
                "selected_signature_verified", "signed_transaction_authorized",
                "installed_authority_verified", "native_inventory_completed")))
            for private in ("/owned", "/usr/bin/", "SECRET_PACKAGE", "stderr", "argv"):
                self.assertNotIn(private, output.call_args.args[0])

    def test_preflight_origin_guard_annotations_cover_source_local_branches(self):
        targets = {
            "host-php-apt.py": {"perl_installed_package", "verify_perl_aliases", "bind_perl_aliases",
                "bound_file", "command", "conffile", "prime_ca_owners", "checked_origin", "future_helper"},
            "host-php-apt-trust.py": {"python_support", "perl_support", "verify_hooks"},
            "host-php-apt-ca.py": {"_bound", "verify_generation", "verify_ca", "one"},
            "provision-php82-permissions.py": {"pin_sudo_configuration"},
        }
        observed = set()
        for filename, functions in targets.items():
            tree = ast.parse(SOURCE.with_name(filename).read_bytes())
            for function in ast.walk(tree):
                if not isinstance(function, ast.FunctionDef) or function.name not in functions:
                    continue
                for call in ast.walk(function):
                    if not isinstance(call, ast.Call) or not isinstance(call.func, ast.Name):
                        continue
                    if call.func.id not in ("require", "_require"):
                        continue
                    index = 2 if call.func.id == "_require" else 1
                    reason = call.args[index] if len(call.args) > index else ast.Constant(
                        "tool-origin" if call.func.id == "_require" else "tool-loader")
                    if not isinstance(reason, ast.Constant) or reason.value != "tool-origin":
                        continue
                    markers = [kw.value for kw in call.keywords if kw.arg == "origin_check"]
                    self.assertEqual(1, len(markers), (filename, function.name, call.lineno))
                    self.assertIsInstance(markers[0], ast.Constant)
                    self.assertIn(markers[0].value, MODULE.ORIGIN_CHECKS)
                    observed.add(markers[0].value)
        self.assertTrue({"ca-bundle-content", "ca-generated-content", "sudo-policy-package",
                         "perl-list-domain", "cached-association", "apt-hook-owner",
                         "future-helper-domain"} <= observed)

    def test_owner_shape_classifies_only_finite_structural_facts(self):
        query = "/usr/bin/gpg"
        cases = (
            (["gpg: " + query], ("one", "one", "one", "all", "zero", "zero")),
            (["gpg: " + query, "gpg: " + query], ("many", "many", "one", "all", "zero", "zero")),
            (["gpg: " + query, "foreign: " + query], ("many", "many", "many", "all", "zero", "zero")),
            (["gpg, foreign: " + query], ("one", "one", "many", "all", "zero", "zero")),
            (["gpg: " + query, "foreign: /PRIVATE_SENTINEL"],
             ("many", "many", "many", "mixed", "zero", "zero")),
            (["gpg: /PRIVATE_SENTINEL"], ("one", "one", "one", "foreign", "zero", "zero")),
            (["diversion by SECRET_PACKAGE from: " + query,
              "diversion by SECRET_PACKAGE to: /PRIVATE_SENTINEL", "gpg: " + query],
             ("many", "one", "one", "all", "many", "zero")),
            (["local diversion from: " + query, "local diversion to: /PRIVATE_SENTINEL"],
             ("many", "zero", "zero", "none", "many", "zero")),
            (["unknown SECRET_PACKAGE /PRIVATE_SENTINEL"],
             ("one", "zero", "zero", "none", "zero", "one")),
            (["gpg: " + query, "unknown row"], ("many", "one", "one", "all", "zero", "one")),
            ([], ("zero", "zero", "zero", "none", "zero", "zero")),
            (["gpg:amd64: " + query], ("one", "one", "one", "all", "zero", "zero")),
        )
        keys = tuple(BASE.OWNER_SHAPE_VALUES)
        for lines, expected in cases:
            with self.subTest(expected=expected):
                shape = BASE.owner_response_shape(lines, query)
                self.assertEqual(dict(zip(keys, expected)), shape)
                for key, value in shape.items():
                    self.assertIn(value, BASE.OWNER_SHAPE_VALUES[key])
                for secret in ("SECRET_PACKAGE", "/PRIVATE_SENTINEL", "/usr/bin/gpg", "gpg:amd64"):
                    self.assertNotIn(secret, json.dumps(shape))

    def test_actual_gpg_shape_refusals_are_not_normalized_or_admitted(self):
        query = "/usr/bin/gpg"
        responses = (
            b"gpg: /usr/bin/gpg\ngpg: /usr/bin/gpg\n",
            b"gpg: /usr/bin/gpg\nforeign: /usr/bin/gpg\n",
            b"diversion by SECRET_PACKAGE from: /usr/bin/gpg\n"
            b"diversion by SECRET_PACKAGE to: /PRIVATE_SENTINEL\ngpg: /usr/bin/gpg\n",
            b"local diversion from: /usr/bin/gpg\nlocal diversion to: /PRIVATE_SENTINEL\n",
            b"not an owner response SECRET_PACKAGE\n",
            b"gpg: /usr/bin/gpg\nforeign: /PRIVATE_SENTINEL\n",
            b"gpg: /usr/bin/gpg\nunknown row\n",
        )
        for raw in responses:
            with self.subTest(raw=raw), self.model() as (item, fs):
                self.preflight_inputs(item, fs)
                command = item.command.side_effect
                def query_response(operation, argument):
                    if (operation, argument) == ("owner", query):
                        return raw
                    return command(operation, argument)
                deadline, roots = item.deadline, item.root_launches
                with patch.object(item, "command", side_effect=query_response), \
                        patch.object(os.path, "abspath", side_effect=fs.canonical), \
                        self.assertRaisesRegex(BASE.Refusal, "^tool-origin$") as caught:
                    item.preflight()
                expected = BASE.owner_response_shape(raw.decode().strip().splitlines(), query)
                self.assertEqual(expected, caught.exception.owner_response_shape)
                self.assertEqual({"step": "tool-origin", "requested_tool": "gpg",
                                  "identity_check": None, "origin_check": "owner-response-shape",
                                  "owner_response_shape": expected}, item.receipt["preflight_refusal"])
                self.assertNotIn(query, item.origins)
                self.assertEqual(deadline, item.deadline)
                self.assertEqual(roots, item.root_launches)
                self.assertFalse(item.setup_ready or item.receipt["signed_transaction_authorized"])

    def test_owner_shape_annotation_preserves_valid_missing_and_foreign_target_checks(self):
        for raw, expected in ((b"coreutils: /usr/bin/gpg\n", None),
                              (b"", "owner-missing"),
                              (b"coreutils: /PRIVATE_SENTINEL\n", "owner-target")):
            with self.subTest(expected=expected), self.model() as (item, fs):
                command = item.command.side_effect
                def query_response(operation, argument):
                    if (operation, argument) == ("owner", "/usr/bin/gpg"):
                        return raw
                    return command(operation, argument)
                with patch.object(item, "command", side_effect=query_response):
                    if expected is None:
                        item.origin("/usr/bin/gpg")
                        self.assertTrue(item.origins["/usr/bin/gpg"]["installed_digest_matches"])
                    else:
                        with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$") as caught:
                            item.origin("/usr/bin/gpg")
                        self.assertEqual(expected, caught.exception.origin_check)
                        self.assertFalse(hasattr(caught.exception, "owner_response_shape"))

    def test_owner_shape_projection_is_closed_optional_and_privacy_safe(self):
        value = {"step": "tool-origin", "requested_tool": "gpg", "identity_check": None,
                 "origin_check": "owner-response-shape"}
        shape = BASE.owner_response_shape(["gpg: /usr/bin/gpg", "unknown row"], "/usr/bin/gpg")
        for extra in ({}, {"owner_response_shape": None}, {"owner_response_shape": shape}):
            self.assertEqual(dict(value, **extra), MODULE.preflight_witness(
                dict(value, **extra), "preflight", "tool-origin"))
        for bad in (dict(shape, path="/PRIVATE_SENTINEL"), dict(shape, owner_rows=2),
                    dict(shape, owner_domain="SECRET_PACKAGE"), dict(shape, target_relation=[]),
                    dict(shape, row_count="one"), dict(shape, owner_domain="zero"),
                    dict(shape, owner_rows="one", target_relation="mixed"),
                    BASE.owner_response_shape([], "/usr/bin/gpg"),
                    {key: val for key, val in shape.items() if key != "other_rows"}, [],
                    "/PRIVATE_SENTINEL"):
            with self.subTest(bad=bad), self.assertRaisesRegex(BASE.Refusal, "^inventory-shape$"):
                MODULE.preflight_witness(dict(value, owner_response_shape=bad), "preflight", "tool-origin")
        with self.assertRaisesRegex(BASE.Refusal, "^inventory-shape$"):
            MODULE.preflight_witness(dict(value, origin_check="owner-missing", owner_response_shape=shape),
                                     "preflight", "tool-origin")
        with self.model() as (item, fs):
            self.preflight_inputs(item, fs)
            command = item.command.side_effect
            def response(operation, argument):
                if (operation, argument) == ("owner", "/usr/bin/gpg"):
                    return b"gpg: /usr/bin/gpg\nunknown SECRET_PACKAGE /PRIVATE_SENTINEL\n"
                return command(operation, argument)
            with patch.object(item, "command", side_effect=response), \
                    patch.object(os.path, "abspath", side_effect=fs.canonical), \
                    self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                item.preflight()
            def store(name, raw):
                fs.add(str(item.directory / name), raw, mode=stat.S_IFREG | 0o600, uid=1000)
            with patch.object(item, "write", side_effect=store), patch("builtins.print") as output:
                self.assertFalse(MODULE.projection(item, None, "tool-origin"))
            public = json.loads(output.call_args.args[0].split(" ", 1)[1])
            self.assertEqual(item.receipt["preflight_refusal"], public["preflight_refusal"])
            for private in ("/PRIVATE_SENTINEL", "SECRET_PACKAGE", "/usr/bin/gpg", "argv", "stdout"):
                self.assertNotIn(private, output.call_args.args[0])
            self.assertFalse(any(public[key] for key in ("apt_original_accepted",
                "selected_signature_verified", "signed_transaction_authorized", "installed_authority_verified")))

    def test_identity_annotation_preserves_file_budget_and_unannotated_refusal(self):
        info = FileSystem().add("/file", b"data")
        BASE.validate_file(info, 0, 4)
        with self.assertRaisesRegex(BASE.Refusal, "^tool-identity$") as owner:
            BASE.validate_file(info, 1000, 4)
        self.assertEqual("expected-owner", owner.exception.identity_check)
        with self.assertRaisesRegex(BASE.Refusal, "^file-budget$") as caught:
            BASE.validate_file(info, 0, 3)
        self.assertFalse(hasattr(caught.exception, "identity_check"))
        with self.model() as (item, fs):
            self.preflight_inputs(item, fs)
            item.deadline = 0
            with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                    self.assertRaisesRegex(BASE.Refusal, "^inventory-deadline$"):
                item.preflight()
            self.assertIsNone(item.receipt["preflight_refusal"])

    def test_preflight_witness_is_closed_and_unresolved_predicate_is_explicit(self):
        value = {"step": "tool-file", "requested_tool": "perl", "identity_check": "single-link"}
        self.assertEqual(value, MODULE.preflight_witness(value, "preflight", "tool-identity"))
        unresolved = dict(value, identity_check=None)
        self.assertEqual(unresolved, MODULE.preflight_witness(unresolved, "preflight", "tool-identity"))
        self.assertIsNone(MODULE.preflight_witness(None, "configuration", None))
        for foreign in (dict(value, path="/PRIVATE_SENTINEL"), dict(value, step="/PRIVATE_SENTINEL"),
                        dict(value, requested_tool="/usr/bin/perl"),
                        dict(value, identity_check="SECRET_EXCEPTION"),
                        dict(value, step="ca-trust"), dict(value, requested_tool=None),
                        dict(value, identity_check=[])):
            with self.subTest(value=foreign), self.assertRaisesRegex(BASE.Refusal, "^inventory-shape$"):
                MODULE.preflight_witness(foreign, "preflight", "tool-identity")
        for stage, reason in (("configuration", "tool-identity"), ("preflight", None),
                              ("preflight", "tool-origin")):
            with self.subTest(stage=stage, reason=reason), self.assertRaisesRegex(BASE.Refusal, "^inventory-shape$"):
                MODULE.preflight_witness(value, stage, reason)

    def test_real_projection_emits_only_closed_refusal_witness_and_no_authority(self):
        with self.model() as (item, fs):
            self.preflight_inputs(item, fs)
            fs.info(MODULE.TOOLS[0]).st_nlink = 2
            with patch.object(os.path, "abspath", side_effect=fs.canonical), \
                    self.assertRaisesRegex(BASE.Refusal, "^tool-identity$"):
                item.preflight()
            def store(name, raw):
                fs.add(str(item.directory / name), raw, mode=stat.S_IFREG | 0o600, uid=1000)
            with patch.object(item, "write", side_effect=store), patch("builtins.print") as output:
                self.assertFalse(MODULE.projection(item, None, "tool-identity"))
            public = json.loads(output.call_args.args[0].split(" ", 1)[1])
            self.assertEqual(item.receipt["preflight_refusal"], public["preflight_refusal"])
            self.assertEqual("refused", public["state"])
            self.assertFalse(public["apt_original_accepted"] or public["selected_signature_verified"]
                             or public["signed_transaction_authorized"] or public["installed_authority_verified"])
            self.assertIsNone(public["php_api_presence"])
            self.assertNotIn("/owned", output.call_args.args[0])
            self.assertEqual(0, item.root_launches)

    @contextmanager
    def model(self, versions=("8.2",), planned=("8.2",), unowned=None, future_binary=None,
              future_template=b"; production\n", common_selected=True, inactive=()):
        fs = FileSystem()
        fs.add("/owned", mode=stat.S_IFDIR | 0o700, uid=1000)
        fs.add("/usr/lib/php", mode=stat.S_IFDIR | 0o755)
        for version in versions:
            fs.add("/usr/lib/php/" + version, mode=stat.S_IFDIR | 0o755)
            binary = "/usr/bin/php" + version
            if version not in inactive:
                fs.add(binary, elf(), mode=stat.S_IFREG | 0o755)
                fs.packages[binary] = "php" + version + "-cli"
            ini = "/etc/php/" + version + "/cli/php.ini"
            fs.add(ini, b"; production\n")
            fs.add("/etc/php/" + version + "/cli/conf.d", mode=stat.S_IFDIR | 0o755)
            template = "/usr/lib/php/" + version + "/php.ini-production.cli"
            fs.add(template, b"; production\n")
            fs.packages[template] = "php" + version + "-cli"
        registry = b"".join(("php%s-cli /etc/php/%s/cli/php.ini\n" % (v, v)).encode() for v in versions)
        hashes = b"".join((hashlib.md5(b"; production\n").hexdigest()
                          + " /etc/php/" + v + "/cli/php.ini\n").encode() for v in versions)
        fs.add("/var/lib/ucf/registry", registry)
        fs.add("/var/lib/ucf/hashfile", hashes)
        fs.add("/var/lib/dpkg/triggers/File", b"")
        fs.add("/var/lib/php/sessions", mode=stat.S_IFDIR | 0o1733)
        fs.add("/var/lib/php/sessions/sess_expired", b"existing session")
        fs.add("/etc/ld.so.conf", b"/usr/lib\n")
        fs.add("/etc/ld.so.conf.d", mode=stat.S_IFDIR | 0o755)
        strings = b"libc.so.6\0/usr/lib/libc.so.6\0"
        cache = bytearray(72)
        cache[:20] = b"glibc-ld.so.cache1.1"
        struct.pack_into("<II", cache, 20, 1, len(strings))
        struct.pack_into("<IIIIQ", cache, 48, 0, 72, 82, 0, 0)
        fs.add("/etc/ld.so.cache", bytes(cache) + strings)
        for name in TRUST.UTILITIES | {"dash", "phpenmod", "phpdismod"}:
            path = "/usr/bin/" + name
            fs.add(path, elf(), mode=stat.S_IFREG | 0o755)
            fs.packages[path] = TRUST.INSTALL_HELPERS.get(path, "coreutils")
        for path in MODULE.TOOLS:
            if path not in fs.nodes:
                fs.add(path, elf(), mode=stat.S_IFREG | 0o755)
                fs.packages[path] = TRUST.INSTALL_HELPERS.get(path, "coreutils")
        for path, package in TRUST.INSTALL_HELPERS.items():
            if path in fs.nodes:
                fs.packages[path] = package
        for path, raw in PRODUCERS.items():
            fs.add(path, raw, mode=stat.S_IFREG | 0o755)
            fs.packages[path] = "php-common"
        fs.add("/usr/bin/sh", mode=stat.S_IFLNK | 0o777, target="/usr/bin/dash")
        if unowned is not None:
            del fs.packages["/usr/bin/php" + unowned]
        for path, raw in PRODUCERS.items():
            fs.add("/owned/common" + path, raw, uid=1000)
        for version in planned:
            fs.add("/owned/cli" + version + "/usr/lib/php/" + version, mode=stat.S_IFDIR | 0o755, uid=1000)
            fs.add("/owned/cli" + version + "/usr/bin/php" + version,
                   elf() if future_binary is None else future_binary, uid=1000)
            fs.add("/owned/cli" + version + "/usr/lib/php/" + version + "/php.ini-production.cli",
                   future_template, uid=1000)
        source_bytes = {name: SOURCE.with_name(name).read_bytes() for name in MODULE.SOURCES}
        for name, raw in source_bytes.items():
            fs.add("/owned/source/" + name, raw, uid=1000)
        with ExitStack() as stack:
            P = fs.path_class()
            stack.enter_context(patch.object(MODULE, "Path", P))
            stack.enter_context(patch.object(MODULE, "__file__", "/owned/source/host-php-apt.py"))
            stack.enter_context(patch.object(BASE, "Path", P))
            stack.enter_context(patch.object(TRUST, "Path", P))
            stack.enter_context(patch.object(os, "getuid", return_value=1000, create=True))
            stack.enter_context(patch.object(os, "geteuid", return_value=1000, create=True))
            stack.enter_context(patch.object(os, "lstat", side_effect=fs.info))
            stack.enter_context(patch.object(os, "scandir", side_effect=fs.scandir))
            stack.enter_context(patch.object(os.path, "realpath", side_effect=fs.canonical))
            stack.enter_context(patch.object(os.path, "lexists", side_effect=lambda p: str(p) in fs.nodes))
            stack.enter_context(patch.object(os.path, "exists", side_effect=lambda p: str(p) in fs.nodes))
            stack.enter_context(patch.object(os.path, "isfile", side_effect=lambda p: str(p) in fs.nodes and stat.S_ISREG(fs.info(p).st_mode)))
            stack.enter_context(patch.object(os, "getxattr", return_value=b"", create=True))
            stack.enter_context(patch.object(BASE, "parent_pins", side_effect=fs.parents))
            launch = stack.enter_context(patch.object(MODULE.GUARD.subprocess, "Popen",
                                                     side_effect=AssertionError("Native launch forbidden")))
            item = MODULE.Provider("8.2")
            item.directory = P("/owned")
            item.extractions = {"php-common": P("/owned/common")} if common_selected else {}
            item.extractions.update({"php" + v + "-cli": P("/owned/cli" + v) for v in planned})
            item.extracted_directory_pins = {
                name: BASE.identity(info) for name, (info, _, _) in fs.nodes.items()
                if any(name == str(root) or name.startswith(str(root) + "/")
                       for root in item.extractions.values()) and stat.S_ISDIR(info.st_mode)}
            item.extracted_pins = {
                name: {"identity": BASE.identity(info), "sha256": MODULE.POLICY.digest(raw)}
                for name, (info, raw, _) in fs.nodes.items()
                if any(name.startswith(str(root) + "/") for root in item.extractions.values())
                and stat.S_ISREG(info.st_mode)}
            item.archives = {}
            for package, root in item.extractions.items():
                archive = "/owned/" + package + ".deb"
                fs.add(archive, b"archive")
                md5 = b"".join((hashlib.md5(raw).hexdigest() + "  " + name[len(str(root)) + 1:] + "\n").encode()
                               for name, (_, raw, _) in fs.nodes.items()
                               if name.startswith(str(root) + "/") and name in item.extracted_pins)
                control = ("Package: %s\nVersion: 1\nArchitecture: amd64\n" % package).encode()
                item.archives[package] = (P(archive), MODULE.POLICY.digest(b"archive"),
                                          {"md5sums": md5, "control": control})
                item.archive_pins = getattr(item, "archive_pins", {})
                item.archive_pins[archive] = BASE.identity(fs.info(archive))
                item.lock.append({"package": package, "version": "1", "architecture": "amd64",
                                  "source": package.removesuffix("-cli"), "source_version": "1",
                                  "sha256": MODULE.POLICY.digest(b"archive"), "size": 7,
                                  "repository": "ppa", "index_sha256": "a" * 64,
                                  "source_index_sha256": "b" * 64, "action": "install"})
            item.installed = {package: "1" for package in fs.packages.values()}
            item.installed["libc-bin"] = "1"
            item.receipt["producer_sources"] = {
                name: {"identity": BASE.identity(fs.info("/owned/source/" + name)),
                       "sha256": MODULE.POLICY.digest(raw)}
                for name, raw in source_bytes.items()}
            item.installed_records = [{
                "Package": package, "Version": version, "Architecture": "amd64",
                "Status": "install ok installed",
            } for package, version in sorted(item.installed.items())]
            next(row for row in item.installed_records if row["Package"] == "libc-bin")[
                "Conffiles"] = "/etc/ld.so.conf " + hashlib.md5(b"/usr/lib\n").hexdigest()
            def command(operation, argument):
                if operation == "elf":
                    return b""
                if operation == "owner":
                    owner = fs.packages.get(str(argument))
                    return (owner + ": " + str(argument) + "\n").encode() if owner else b""
                if operation == "package":
                    re_version = argument.removeprefix("php").removesuffix("-cli")
                    return (argument + "\t1\tamd64\tphp" + re_version + "\t1\n").encode()
                raise AssertionError("Candidate PHP/unknown native operation: " + operation)
            for package in set(fs.packages.values()):
                manifest = b"".join((hashlib.md5(fs.nodes[name][1]).hexdigest() + "  " + name.lstrip("/") + "\n").encode()
                                    for name, owner in fs.packages.items() if owner == package)
                fs.add("/var/lib/dpkg/info/" + package + ".md5sums", manifest)
            stack.enter_context(patch.object(item, "read", side_effect=fs.read))
            def special_read(path):
                canonical, info, raw = item.acquire(path)
                return info, raw, item.pins[canonical]
            stack.enter_context(patch.object(item, "special_read", side_effect=special_read))
            stack.enter_context(patch.object(item, "command", side_effect=command))
            def retain(label, raw):
                item.originals[label] = raw
                return raw
            stack.enter_context(patch.object(item, "retain", side_effect=retain))
            stack.enter_context(patch.object(item, "retained_metadata",
                                             side_effect=lambda label: ({"ino": 1}, item.originals[label])))
            stack.enter_context(patch.object(item, "extracted_file",
                                             side_effect=lambda p, limit=BASE.FILE_LIMIT: fs.read(str(p), 1000, limit)[2]))
            stack.enter_context(patch.object(item, "write"))
            stack.enter_context(patch.object(item, "custody"))
            item.observe_session_verification()
            yield item, fs
            launch.assert_not_called()

    @contextmanager
    def transaction(self, item):
        environment = {
            "GITHUB_JOB": "release-package-qa", "GITHUB_ACTIONS": "true",
            "RUNNER_ENVIRONMENT": "github-hosted", "RUNNER_OS": "Linux",
            "GITHUB_EVENT_NAME": "pull_request", "GITHUB_SHA": "a" * 40,
            "WSTM_HOST_SETUP_RUNNER": "github-hosted",
        }
        with patch.dict(os.environ, environment, clear=True), patch.object(MODULE.sys, "platform", "linux"):
            # Model the external signed-index/simulation and preceding capture
            # results; closing, scope checks and all source validation are real.
            item._authenticated_lock = MODULE.POLICY.digest(
                MODULE.json.dumps(item.lock, sort_keys=True).encode())
            item.setup_ready = True
            item.root_cursor = MODULE.POLICY.OPERATIONS.index("apt-install")
            item.receipt.update(
                accepted_root_operations=list(MODULE.POLICY.OPERATIONS[:item.root_cursor]),
                apt_original_accepted=True, selected_signature_verified=True,
                effective_apt_config_sha256="c" * 64)
            for path in MODULE.TOOLS:
                item.origin(path)
                item.dependencies(path)
            item.snapshot_php_state(set(
                item.receipt["session_verification"]["before"]["versions"]) | {item.version})
            item.close_transaction()
            yield

    def scan_transaction(self, item):
        with item.transaction_admission("lifecycle"):
            TRUST.verify_lifecycle(item, {name: value[2] for name, value in item.archives.items()},
                                   ["/usr/bin/php8.2"])

    def test_absent_selected_and_different_signed_replacement_are_transaction_only(self):
        for versions, future in (((), None), (("8.2",), elf() + b"replacement")):
            with self.subTest(versions=versions), self.model(
                    versions, future_binary=future) as (item, fs), self.transaction(item):
                self.scan_transaction(item)
                with item.transaction_admission("apt-install"):
                    rows = item.transaction_helper_rows("/usr/bin/php8.2", "php8.2-cli")
                    self.assertTrue(any(row.get("future") for row in rows))
                    self.assertFalse(item.receipt["installed_authority_verified"])
                    item.root_cursor += 1
                    item.receipt["accepted_root_operations"].append("apt-install")
                self.assertTrue(item._transaction["consumed"])
                with self.assertRaisesRegex(TRUST.TrustError, "^tool-loader$"):
                    TRUST.helper(item, "/usr/bin/php8.2", "php8.2-cli") if not versions else (
                        TRUST.helper_source(item, "/usr/bin/php8.2", rows[-1], set(), False))

    def test_inactive_common_directory_keeps_query_domain_without_cli_authority(self):
        with self.model(("8.2", "8.4"), inactive=("8.4",)) as (item, fs):
            self.assertEqual(["8.4", "8.2"], item.receipt["session_verification"]["before"]["versions"])
            self.assertFalse(item.receipt["session_verification"]["before"]["workers"]["8.4"]["active"])
            bindings = item.sessionclean_runtime_bindings()
            self.assertEqual({"8.2"}, set(bindings))
            fs.add("/usr/bin/php8.4", elf(), mode=stat.S_IFREG | 0o755)
            with self.assertRaisesRegex(BASE.Refusal, "^tool-link$"):
                item.verify_sessionclean_runtime_bindings()

    def test_unchanged_installed_producers_do_not_require_common_archive(self):
        with self.model(common_selected=False) as (item, fs), self.transaction(item):
            self.assertNotIn("php-common", item.extractions)
            self.assertTrue(all(row["producer_role"] == "unchanged-installed-producer"
                                for row in item.receipt["session_verification"]["producers"].values()))
            self.scan_transaction(item)

    def test_forged_and_cached_wrong_producer_roles_refuse(self):
        for common in (True, False):
            with self.model(common_selected=common) as (item, fs):
                row = item.receipt["session_verification"]["producers"]["/usr/sbin/phpquery"]
                row["producer_role"] = "unchanged-installed-producer" if common else "incoming-transaction-data"
                with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                    item.sessionclean_runtime_bindings()

    def test_unsigned_wrong_job_and_stale_or_forged_scope_refuse(self):
        for fault in ("signature", "job", "lock", "scope", "lifecycle"):
            with self.subTest(fault=fault), self.model() as (item, fs), self.transaction(item):
                if fault == "signature":
                    item.receipt["selected_signature_verified"] = False
                elif fault == "job":
                    os.environ["GITHUB_JOB"] = "foreign-job"
                elif fault == "lock":
                    item.lock[0]["source"] = "foreign"
                elif fault == "scope":
                    item._transaction_scope = object()
                    with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                        item.transaction_active()
                    continue
                else:
                    item._transaction["lifecycle_verified"] = True
                with self.assertRaises((BASE.Refusal, MODULE.POLICY.PolicyError)):
                    with item.transaction_admission("apt-install"):
                        self.fail("Unverified transaction entered install scope")

    def test_archive_control_source_and_custody_drift_refuse_transaction(self):
        for fault in ("archive", "control", "source", "extracted", "parent", "producer"):
            with self.subTest(fault=fault), self.model() as (item, fs), self.transaction(item):
                if fault == "archive":
                    fs.add(str(item.archives["php8.2-cli"][0]), b"foreign")
                elif fault == "control":
                    item.archives["php8.2-cli"][2]["postinst"] = b"#!/bin/sh\nforeign\n"
                elif fault == "source":
                    fs.add("/owned/source/host-php-apt.py", b"foreign", uid=1000)
                elif fault == "extracted":
                    fs.add("/owned/cli8.2/usr/bin/php8.2", elf() + b"foreign", uid=1000)
                elif fault == "parent":
                    fs.add("/owned/cli8.2/usr/bin", mode=stat.S_IFDIR | 0o777, uid=1000)
                else:
                    item.receipt["session_verification"]["producers"]["/usr/sbin/phpquery"]["producer_role"] = "foreign"
                with self.assertRaisesRegex(BASE.Refusal, "^tool-(?:origin|link|parent)$"):
                    item.verify_transaction()

    def test_unverified_retained_trigger_or_old_maintainer_refuses(self):
        for path, raw in (("/var/lib/dpkg/triggers/File", b"/etc/php foreign-trigger\n"),
                          ("/var/lib/dpkg/info/php8.2-cli.prerm",
                           b"#!/bin/sh\n/usr/bin/php${arbitrary}\n")):
            with self.model() as (item, fs), self.transaction(item):
                fs.add(path, raw)
                with self.assertRaises((TRUST.TrustError, BASE.Refusal)):
                    self.scan_transaction(item)

    def test_transaction_budget_exhaustion_is_conserved(self):
        with self.model(()) as (item, fs), self.transaction(item):
            item.bytes = BASE.TOTAL_LIMIT
            with self.assertRaisesRegex(BASE.Refusal, "^file-budget$"):
                self.scan_transaction(item)
            self.assertEqual(BASE.TOTAL_LIMIT, item.bytes)
            self.assertEqual(0, item.root_launches)

    def test_retained_control_presence_and_bytes_are_rechecked_before_apt(self):
        for fault in ("absent-to-present", "bytes"):
            with self.subTest(fault=fault), self.model() as (item, fs), self.transaction(item):
                path = "/var/lib/dpkg/info/php8.2-cli.prerm"
                if fault == "bytes":
                    fs.add(path, b"#!/bin/sh\nexit 0\n")
                self.scan_transaction(item)
                fs.add(path, b"#!/bin/sh\nexit 1\n")
                with self.assertRaisesRegex(BASE.Refusal, "^tool-link$"):
                    with item.transaction_admission("apt-install"):
                        self.fail("Changed retained control entered native install")
                self.assertEqual(0, item.root_launches)

    def test_inactive_nonselected_configuration_survives_actual_postconditions(self):
        with self.model(("8.2", "8.4"), inactive=("8.4",)) as (item, fs), self.transaction(item):
            self.scan_transaction(item)
            with self.modeled_apt_capture(item, fs):
                item.configure()
            self.assertTrue(item.receipt["installed_authority_verified"])
            self.assertFalse(item.receipt["post_install_workers"]["8.4"]["active"])
            self.assertNotIn("/usr/bin/php8.4", item.receipt["post_install_package_bindings"])

    def add_retained_upgrade(self, item, fs, multiarch="same", architecture="amd64"):
        package = "libmodel1"
        item.installed[package] = "1"
        item.installed_records.append({
            "Package": package, "Version": "1", "Architecture": architecture,
            "Multi-Arch": multiarch, "Status": "install ok installed",
        })
        status = "\n\n".join("\n".join(key + ": " + value for key, value in row.items())
                             for row in item.installed_records).encode() + b"\n"
        fs.add("/var/lib/dpkg/status", status)
        item.installed_records = MODULE.POLICY.deb822(
            item.protected("/var/lib/dpkg/status", 16777216))
        root = item.directory / package
        fs.add(str(root), mode=stat.S_IFDIR | 0o700, uid=1000)
        item.extractions[package] = root
        item.extracted_directory_pins[str(root)] = BASE.identity(fs.info(root))
        archive = item.directory / (package + ".deb")
        fs.add(str(archive), b"archive")
        # Installed v1 is Multi-Arch:same, incoming v2 is not. Only the
        # protected installed status can select the old control basename.
        control = b"Package: libmodel1\nVersion: 2\nArchitecture: amd64\nMulti-Arch: no\n"
        item.archives[package] = (archive, MODULE.POLICY.digest(b"archive"),
                                  {"control": control, "md5sums": b""})
        item.archive_pins[str(archive)] = BASE.identity(fs.info(archive))
        item.lock.append(dict(item.lock[0], package=package, version="2",
                              architecture="amd64", source=package, source_version="2",
                              action="necessary-upgrade", repository="noble"))
        return package

    def test_qualified_retained_control_rejects_arbitrary_expansions(self):
        for name in ("preinst", "postinst", "prerm", "postrm", "config", "triggers"):
            with self.subTest(name=name), self.model() as (item, fs):
                package = self.add_retained_upgrade(item, fs)
                path = "/var/lib/dpkg/info/" + package + ":amd64." + name
                raw = (b"activate ${arbitrary}\n" if name == "triggers"
                       else b"#!/bin/sh\n/usr/bin/php${arbitrary}\n")
                fs.add(path, raw)
                with self.transaction(item), self.assertRaisesRegex(
                        TRUST.TrustError, "^tool-loader$"):
                    self.scan_transaction(item)
                self.assertIn(path, item.pins)
                self.assertEqual(0, item.root_launches)
                self.assertFalse(item.receipt["signed_transaction_authorized"])

    def test_qualified_control_drift_and_absent_to_present_refuse_before_apt(self):
        for name in ("preinst", "postinst", "prerm", "postrm", "config", "triggers"):
            for fault in ("bytes", "bytes-with-preserved-stat", "absent-to-present", "alias"):
                with self.subTest(name=name, fault=fault), self.model() as (item, fs):
                    package = self.add_retained_upgrade(item, fs)
                    path = "/var/lib/dpkg/info/" + package + ":amd64." + name
                    raw = (b"interest-noawait /usr/share/libmodel1\n" if name == "triggers"
                           else b"#!/bin/sh\nexit 0\n")
                    if fault != "absent-to-present":
                        fs.add(path, raw)
                    with self.transaction(item):
                        self.scan_transaction(item)
                        before = item.bytes
                        if fault == "alias":
                            fs.add("/var/lib/dpkg/info/foreign-control", raw)
                            fs.add(path, mode=stat.S_IFLNK | 0o777,
                                   target="/var/lib/dpkg/info/foreign-control")
                        elif fault == "bytes":
                            fs.add(path, raw.replace(b"exit 0", b"exit 1").replace(
                                b"libmodel1", b"libmodel2"))
                        elif fault == "bytes-with-preserved-stat":
                            info, _, target = fs.nodes[path]
                            fs.nodes[path] = (info, raw.replace(b"exit 0", b"exit 1").replace(
                                b"libmodel1", b"libmodel2"), target)
                        else:
                            fs.add(path, raw + b"# changed\n")
                        with self.assertRaisesRegex(BASE.Refusal, "^tool-link$"):
                            with item.transaction_admission("apt-install"):
                                self.fail("Qualified control drift reached apt-install")
                        self.assertGreaterEqual(item.bytes, before)
                        self.assertEqual(0, item.root_launches)

    def test_clean_qualified_and_ordinary_installed_controls_are_pinned(self):
        for multiarch, architecture in (("same", "amd64"), ("no", "amd64"),
                                        ("foreign", "amd64"), ("allowed", "amd64"),
                                        ("no", "all")):
            with self.subTest(multiarch=multiarch, architecture=architecture), self.model() as (item, fs):
                package = self.add_retained_upgrade(item, fs, multiarch, architecture)
                basename = package + ":amd64" if multiarch == "same" else package
                for name in ("preinst", "postinst", "prerm", "postrm", "config", "triggers"):
                    raw = (b"interest-noawait /usr/share/libmodel1\n" if name == "triggers"
                           else b"#!/bin/sh\nexit 0\n")
                    fs.add("/var/lib/dpkg/info/" + basename + "." + name, raw)
                with self.transaction(item):
                    deadline = item.deadline
                    self.scan_transaction(item)
                    item.verify_transaction()
                    self.assertEqual(basename, item.receipt["installed_control_basenames"][package])
                    for name in ("preinst", "postinst", "prerm", "postrm", "config", "triggers"):
                        path = "/var/lib/dpkg/info/" + basename + "." + name
                        self.assertIn(path, item.pins)
                        self.assertTrue(item.receipt["installed_lifecycle_presence"][path]["exists"])
                    self.assertEqual(deadline, item.deadline)
                    self.assertEqual(0, item.root_launches)

    def test_installed_control_metadata_ambiguity_mismatch_and_architecture_refuse(self):
        for fault in ("duplicate", "missing-architecture", "unsupported-architecture",
                      "all-same", "malformed-multiarch", "malformed-version", "wrong-version",
                      "qualified-status-package", "qualified-cache", "ambiguous-cache"):
            with self.subTest(fault=fault), self.model() as (item, fs):
                package = self.add_retained_upgrade(item, fs)
                row = next(row for row in item.installed_records if row["Package"] == package)
                if fault == "duplicate":
                    item.installed_records.append(dict(row))
                elif fault == "missing-architecture":
                    del row["Architecture"]
                elif fault == "unsupported-architecture":
                    row["Architecture"] = "arm64"
                elif fault == "all-same":
                    row["Architecture"] = "all"
                elif fault == "malformed-multiarch":
                    row["Multi-Arch"] = "same/../../foreign"
                elif fault == "malformed-version":
                    row["Version"] = item.installed[package] = "1;foreign"
                elif fault == "wrong-version":
                    row["Version"] = "2"
                elif fault == "qualified-status-package":
                    row["Package"] = package + ":amd64"
                else:
                    item.packages[package + ":i386"] = (
                        [package + ":i386", "1", "amd64", package, "1"], None, None, None)
                    if fault == "ambiguous-cache":
                        item.packages[package + ":amd64"] = (
                            [package + ":amd64", "1", "amd64", package, "1"], None, None, None)
                with self.assertRaisesRegex(TRUST.TrustError, "^tool-origin$"):
                    TRUST.installed_control_basename(item, package)
                self.assertEqual(0, item.root_launches)

    def test_actual_binary_package_identifier_is_checked_against_installed_metadata(self):
        with self.model() as (item, fs):
            package = self.add_retained_upgrade(item, fs)
            path = "/usr/bin/qualified-model-data"
            fs.add(path, elf(), mode=stat.S_IFREG | 0o755)
            fs.packages[path] = package + ":amd64"
            manifest = (hashlib.md5(elf()).hexdigest() + "  usr/bin/qualified-model-data\n").encode()
            fs.add("/var/lib/dpkg/info/" + package + ":amd64.md5sums", manifest)
            ordinary_query = item.command.side_effect
            def query(operation, argument):
                if operation == "package" and argument == package + ":amd64":
                    return (package + ":amd64\t1\tamd64\t" + package + "\t1\n").encode()
                return ordinary_query(operation, argument)
            with patch.object(item, "command", side_effect=query):
                item.bound_file(path, package)
                with self.transaction(item):
                    self.scan_transaction(item)
                    self.assertEqual(package + ":amd64",
                                     TRUST.installed_control_basename(item, package))
                    item.packages[package + ":amd64"][0][1] = "2"
                    with self.assertRaisesRegex(TRUST.TrustError, "^tool-origin$"):
                        item.verify_transaction()
            self.assertEqual(0, item.root_launches)

    def test_installed_multiarch_basename_drift_is_not_incoming_authority(self):
        with self.model() as (item, fs):
            package = self.add_retained_upgrade(item, fs)
            with self.transaction(item):
                self.scan_transaction(item)
                row = next(row for row in item.installed_records if row["Package"] == package)
                row["Multi-Arch"] = "no"
                with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                    item.verify_transaction()
            self.assertEqual(0, item.root_launches)

    def test_qualified_control_byte_and_combined_pin_caps_precede_acquisition(self):
        for exhausted in ("bytes", "pins"):
            with self.subTest(exhausted=exhausted), self.model() as (item, fs):
                package = self.add_retained_upgrade(item, fs)
                path = "/var/lib/dpkg/info/" + package + ":amd64.prerm"
                fs.add(path, b"#!/bin/sh\nexit 0\n")
                self.assertEqual(package + ":amd64", TRUST.installed_control_basename(item, package))
                if exhausted == "bytes":
                    item.bytes = BASE.TOTAL_LIMIT
                else:
                    item._retired_pins = BASE.FILE_COUNT - len(item.pins) - len(item.session_data_pins)
                before = item.bytes, item._retired_pins
                item.read.reset_mock()
                with self.assertRaisesRegex(BASE.Refusal, "^file-budget$"):
                    item.protected(path)
                self.assertFalse(any(str(call.args[0]) == path for call in item.read.call_args_list))
                self.assertEqual(before, (item.bytes, item._retired_pins))
                self.assertNotIn(path, item.pins)
                self.assertEqual(0, item.root_launches)

    def test_activated_trigger_postinst_uses_installed_qualified_basename(self):
        with self.model() as (item, fs):
            row = next(row for row in item.installed_records if row["Package"] == "libc-bin")
            row["Multi-Arch"] = "same"
            self.add_retained_upgrade(item, fs)
            path = "/usr/sbin/ldconfig"
            fs.add(path, elf(), mode=stat.S_IFREG | 0o755)
            fs.packages[path] = "libc-bin:amd64"
            manifest = (hashlib.md5(elf()).hexdigest() + "  usr/sbin/ldconfig\n").encode()
            fs.add("/var/lib/dpkg/info/libc-bin:amd64.md5sums", manifest)
            fs.add("/var/lib/dpkg/triggers/File", b"/usr/bin libc-bin\n")
            script = "/var/lib/dpkg/info/libc-bin:amd64.postinst"
            fs.add(script, b"#!/bin/sh\nexit 0\n")
            ordinary_query = item.command.side_effect
            def query(operation, argument):
                if operation == "package" and argument == "libc-bin:amd64":
                    return b"libc-bin:amd64\t1\tamd64\tglibc\t1\n"
                return ordinary_query(operation, argument)
            with patch.object(item, "command", side_effect=query), self.transaction(item):
                self.scan_transaction(item)
                self.assertIn("libc-bin", item.receipt["activated_installed_triggers"])
                self.assertEqual("libc-bin:amd64", item.receipt["installed_control_basenames"]["libc-bin"])
                self.assertIn(script, item.pins)
                fs.add(script, b"#!/bin/sh\nexit 1\n")
                with self.assertRaisesRegex(BASE.Refusal, "^tool-link$"):
                    item.verify_transaction()
            self.assertEqual(0, item.root_launches)

    @contextmanager
    def modeled_apt_capture(self, item, fs, fault=None):
        def capture(operation, argv, stdin=None):
            record = item.reserve_capture(operation, argv, stdin)
            if operation == "apt-install":
                for package, root in item.extractions.items():
                    for file in item.extracted_pins:
                        if not file.startswith(str(root) + "/"):
                            continue
                        path = "/" + file[len(str(root)) + 1:]
                        fs.add(path, fs.nodes[file][1],
                               mode=stat.S_IFREG | (0o755 if path.startswith(
                                   ("/usr/bin/", "/usr/sbin/")) else 0o644))
                        fs.packages[path] = package
                fs.add("/etc/php/8.2/cli/php.ini", b"; production\n")
                if "/etc/php/8.2/cli/conf.d" not in fs.nodes:
                    fs.add("/etc/php/8.2/cli/conf.d", mode=stat.S_IFDIR | 0o755)
                for path, selected in (
                    ("/var/lib/ucf/registry", b"php8.2-cli /etc/php/8.2/cli/php.ini\n"),
                    ("/var/lib/ucf/hashfile", (hashlib.md5(b"; production\n").hexdigest()
                                             + " /etc/php/8.2/cli/php.ini\n").encode()),
                ):
                    retained = b"".join(line + b"\n" for line in fs.nodes[path][1].splitlines()
                                        if b"/etc/php/8.2/cli/php.ini" not in line)
                    fs.add(path, retained + selected)
                installed = dict(item.installed)
                installed.update({row["package"]: row["version"] for row in item.lock})
                if fault == "status":
                    installed["php8.2-cli"] = "foreign-version"
                lines = []
                for package, version in sorted(installed.items()):
                    text = "Package: %s\nVersion: %s\nStatus: install ok installed\n" % (package, version)
                    if package == "libc-bin":
                        text += "Conffiles: /etc/ld.so.conf " + hashlib.md5(b"/usr/lib\n").hexdigest() + "\n"
                    lines.append(text + "\n")
                fs.add("/var/lib/dpkg/status", "".join(lines).encode())
                for package in {row["package"] for row in item.lock}:
                    raw = b"".join((hashlib.md5(fs.nodes[path][1]).hexdigest() + "  "
                                    + path.lstrip("/") + "\n").encode()
                                   for path, owner in fs.packages.items() if owner == package)
                    fs.add("/var/lib/dpkg/info/" + package + ".md5sums", raw)
                if fault == "owner":
                    del fs.packages["/usr/bin/php8.2"]
                elif fault == "bytes":
                    fs.add("/usr/bin/php8.2", elf() + b"foreign", mode=stat.S_IFREG | 0o755)
                elif fault == "loader":
                    fs.add("/etc/ld.so.cache", b"unknown loader cache")
                elif fault == "config":
                    fs.add("/etc/php/8.2/cli/php.ini", b"auto_prepend_file=/evil\n")
                elif fault == "alias":
                    fs.add("/etc/php/8.2/cli/conf.d/20-foreign.ini", mode=stat.S_IFLNK | 0o777,
                           target="/etc/php/8.2/cli/php.ini")
            elif operation == "select-php":
                fs.add("/usr/bin/php", mode=stat.S_IFLNK | 0o777, target="/usr/bin/php8.2")
            else:
                raise AssertionError("Unexpected ROOT family")
            record.update(exit=0, failure=None, retention_failed=False,
                          stdout_eof=True, stderr_eof=True)
            return record, b"", b""
        with patch.object(item, "capture", side_effect=capture):
            yield

    def test_actual_fixed_apt_install_and_postconditions_support_bootstrap(self):
        for versions, future, common in (((), None, True), ((), None, False),
                                        (("8.2",), elf() + b"signed replacement", True)):
            with self.subTest(versions=versions), self.model(
                    versions, future_binary=future, common_selected=common
                    ) as (item, fs), self.transaction(item):
                self.scan_transaction(item)
                with self.modeled_apt_capture(item, fs):
                    before_bytes, before_roots = item.bytes, item.root_launches
                    item.configure()
                self.assertTrue(item.receipt["installed_authority_verified"])
                self.assertGreaterEqual(item.bytes, before_bytes)
                self.assertGreaterEqual(item._retired_pins, 1)
                self.assertLessEqual(len(item.pins) + len(item.session_data_pins)
                                     + item._retired_pins, BASE.FILE_COUNT)
                self.assertEqual(["apt-install", "select-php"],
                                 [row["operation"] for row in item.commands])
                self.assertEqual(2, item.root_launches)
                self.assertEqual(before_roots + 2, item.root_launches)
                self.assertTrue(all(row["producer_pid"] is None for row in item.commands))

    def test_caller_flag_cannot_authorize_a_future_elf(self):
        with self.model(()) as (item, fs):
            row = item.future_helper("/usr/bin/php8.2", "php8.2-cli")
            with self.assertRaisesRegex(TRUST.TrustError, "^tool-origin$"):
                TRUST.helper_source(item, "/usr/bin/php8.2", row, set(), True)

    def test_original_metadata_and_root_exhaustion_are_not_refunded(self):
        with self.model() as (item, fs):
            item.metadata_bytes = 67108864
            with self.assertRaisesRegex(BASE.Refusal, "^file-budget$"):
                MODULE.Provider.retain(item, "metadata-exhausted", b"x")
            self.assertEqual(67108864, item.metadata_bytes)
        with self.model(()) as (item, fs), self.transaction(item):
            self.scan_transaction(item)
            item.root_launches = 16
            with self.modeled_apt_capture(item, fs), self.assertRaisesRegex(
                    BASE.Refusal, "^command-budget$"):
                item.configure()
            self.assertEqual(16, item.root_launches)
            self.assertFalse(item.receipt["installed_authority_verified"])

    def test_post_apt_owner_bytes_loader_config_alias_and_status_block_eligibility(self):
        for fault in ("owner", "bytes", "loader", "config", "alias", "status"):
            with self.subTest(fault=fault), self.model(()) as (item, fs), self.transaction(item):
                self.scan_transaction(item)
                with self.modeled_apt_capture(item, fs, fault), self.assertRaises(
                        (BASE.Refusal, TRUST.TrustError)):
                    item.configure()
                self.assertFalse(item.receipt["installed_authority_verified"])
                self.assertNotIn("select-php", [row["operation"] for row in item.commands])

    def test_genuine_loop_binds_two_installed_versions_not_only_selected(self):
        with self.model(("8.2", "8.4")) as (item, fs):
            TRUST.literal_commands(item, PRODUCERS["/usr/lib/php/sessionclean"].decode())
            self.assertEqual({"8.2", "8.4"}, set(item.sessionclean_bindings["versions"]))
            self.assertFalse(item.receipt["sessionclean_runtime_bindings"]["prospective_origin_used_as_installed"])
            self.assertFalse(item.receipt["sessionclean_runtime_bindings"]["scheduler_execution_observed"])

    def test_prospective_only_selected_version_does_not_become_installed_authority(self):
        with self.model((), ("8.2",)) as (item, fs):
            with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                TRUST.literal_commands(item, PRODUCERS["/usr/lib/php/sessionclean"].decode())
            self.assertEqual(["8.2"], item.receipt["sessionclean_missing_installed_versions"])
            self.assertIsNone(item.sessionclean_bindings)

    def test_foreign_helper_and_arbitrary_expansions_keep_exact_refusal(self):
        for raw in (PRODUCERS["/usr/lib/php/sessionclean"] + b"\nforeign",
                    b"#!/bin/sh\n/usr/bin/php${arbitrary}\n",
                    b"#!/bin/sh\n/usr/bin/php${version}\n"):
            with self.assertRaisesRegex(TRUST.TrustError, "^tool-loader$"):
                TRUST.literal_commands(object(), raw.decode())

    def test_future_binary_replacement_does_not_inherit_installed_authority(self):
        with self.model(future_binary=elf() + b"replacement") as (item, fs):
            with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                item.sessionclean_runtime_bindings()

    def test_future_configuration_template_does_not_inherit_installed_authority(self):
        with self.model(future_template=b"; different signed template\n") as (item, fs):
            with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                item.sessionclean_runtime_bindings()
            self.assertIsNone(item.sessionclean_bindings)

    def test_unowned_nonselected_interpreter_refuses(self):
        with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
            with self.model(("8.2", "8.4"), unowned="8.4") as (item, fs):
                item.sessionclean_runtime_bindings()

    def test_changed_binary_config_alias_parent_and_domain_are_rechecked(self):
        for kind in ("binary", "config", "alias", "parent", "domain"):
            with self.subTest(kind=kind), self.model() as (item, fs):
                item.sessionclean_runtime_bindings()
                if kind == "binary":
                    fs.add("/usr/bin/php8.2", elf() + b"drift")
                elif kind == "config":
                    fs.add("/etc/php/8.2/cli/php.ini", b"auto_prepend_file=/evil\n")
                elif kind == "alias":
                    fs.add("/usr/bin/php8.2", mode=stat.S_IFLNK | 0o777, target="/usr/bin/foreign")
                elif kind == "parent":
                    fs.add("/etc/php/8.2/cli", mode=stat.S_IFDIR | 0o777)
                else:
                    fs.add("/usr/lib/php/8.4", mode=stat.S_IFDIR | 0o755)
                with self.assertRaises(BASE.Refusal):
                    item.verify_sessionclean_runtime_bindings()

    def test_noncanonical_matching_domains_refuse_instead_of_being_dropped(self):
        with self.model() as (item, fs):
            for name, mode in (("prefix8.4", stat.S_IFDIR), ("8.4", stat.S_IFREG),
                               ("8.4", stat.S_IFLNK), (" 8.4", stat.S_IFDIR)):
                with self.subTest(name=name, mode=mode), self.assertRaises(BASE.Refusal):
                    item.session_domain({"entries": [{"name": name, "identity": {"mode": mode}}]})

    def test_combined_pin_and_byte_exhaustion_precede_body_acquisition(self):
        for budget in ("pins", "bytes"):
            with self.subTest(budget=budget), self.model() as (item, fs):
                item.session_data_pins.clear()
                if budget == "pins":
                    item.pins.update({"/pin/" + str(n): {} for n in range(BASE.FILE_COUNT - len(item.pins))})
                else:
                    item.bytes = BASE.TOTAL_LIMIT
                with patch.object(item, "extracted_file") as acquire:
                    with self.assertRaisesRegex(BASE.Refusal, "^file-budget$"):
                        item.prospective_data("/usr/sbin/phpquery", "php-common")
                    acquire.assert_not_called()

    def test_unknown_query_bytes_and_ambiguous_manifest_are_not_loop_authority(self):
        for kind in ("producer", "manifest"):
            with self.subTest(kind=kind), self.model() as (item, fs):
                if kind == "producer":
                    item.receipt["session_verification"]["producers"]["/usr/sbin/phpquery"]["sha256"] = "f" * 64
                else:
                    controls = item.archives["php-common"][2]
                    controls["md5sums"] += (
                        hashlib.md5(PRODUCERS["/usr/sbin/phpquery"]).hexdigest()
                        + "  usr/sbin/phpquery\n").encode()
                with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                    item.sessionclean_runtime_bindings()

    def test_future_elf_is_still_not_a_helper_or_installed_runtime(self):
        with self.model((), ("8.2",)) as (item, fs):
            with self.assertRaisesRegex(TRUST.TrustError, "^tool-loader$"):
                TRUST.helper(item, "/usr/bin/php8.2", "php8.2-cli")

    def test_absent_configuration_and_loader_changes_invalidate_loop_binding(self):
        for kind in ("absent-config", "loader"):
            with self.subTest(kind=kind), self.model() as (item, fs):
                item.sessionclean_runtime_bindings()
                if kind == "absent-config":
                    fs.add("/etc/php/8.2/fpm/php.ini", b"; newly present\n")
                else:
                    fs.add("/etc/ld.so.cache", b"foreign loader cache")
                with self.assertRaises(BASE.Refusal):
                    item.verify_sessionclean_runtime_bindings()

    def test_completed_prospective_reuse_does_not_refund_or_reserve_again(self):
        with self.model() as (item, fs):
            counts = (item.bytes, len(item.session_data_pins))
            item.prospective_data("/usr/sbin/phpquery", "php-common")
            self.assertEqual(counts, (item.bytes, len(item.session_data_pins)))


if __name__ == "__main__":
    unittest.main()
