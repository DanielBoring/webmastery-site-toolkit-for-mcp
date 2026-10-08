"""Finite source-bound cleanup admission; filesystem/native acquisitions only."""

from contextlib import ExitStack, contextmanager
import hashlib
import importlib.util
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
