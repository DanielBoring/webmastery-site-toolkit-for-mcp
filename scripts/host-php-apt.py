"""Fail-closed signed OS PHP provider for the three disposable HOST QA jobs."""

import hashlib
from contextlib import contextmanager
import io
import json
import lzma
import os
from pathlib import Path
import re
import stat
import sys
import tarfile
import time
import zlib


import importlib.util


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CAPTURE = load("signed_php_capture", "host-php-apt-capture.py")
SIGNATURE = load("signed_php_signature", "host-php-apt-signature.py")
TRUST = load("signed_php_trust", "host-php-apt-trust.py")
CA = load("signed_php_ca", "host-php-apt-ca.py")
DRIVER = load("signed_php_projection_driver", "host-prerequisite-setup.py")
POLICY, BASE, GUARD = CAPTURE.POLICY, CAPTURE.BASE, CAPTURE.GUARD
require = BASE.require

TOOLS = ("/usr/bin/python3", "/usr/bin/setpriv", "/usr/bin/readelf", "/usr/bin/dpkg-query",
         "/usr/bin/chmod", "/usr/bin/sudo", "/usr/bin/env", "/usr/bin/install",
         "/usr/bin/curl", "/usr/bin/gpg", "/usr/bin/gpgv", "/usr/bin/tee",
         "/usr/bin/apt-get", "/usr/bin/apt-cache", "/usr/bin/apt-config",
         "/usr/bin/dpkg-deb", "/usr/bin/update-alternatives", "/usr/bin/dpkg",
         "/usr/bin/ucf", "/usr/bin/ucfr", "/usr/bin/perl", "/usr/bin/systemctl",
         "/usr/bin/dash", "/usr/sbin/ldconfig", "/usr/sbin/start-stop-daemon")
TOOL_IDS = {path: path.rsplit("/", 1)[1] for path in TOOLS}
PREFLIGHT_STEPS = frozenset((
    "source-pins", "package-status", "platform", "tool-file", "tool-origin",
    "python-module", "sudo-policy", "apt-credentials", "apt-configuration",
    "loader", "apt-method", "ca-trust", "keyring", "install-paths",
    "dpkg-state", "apt-hooks", "source-recheck"))
SOURCES = ("host-php-apt.py", "host-php-apt-capture.py", "host-php-apt-policy.py",
           "host-php-apt-signature.py", "host-prerequisite-setup.py",
           "host-prerequisite-inventory.py", "provision-php82-permissions.py",
           "host-php-apt-trust.py", "host-php-apt-ca.py")
REPOSITORIES = {
    "ppa": "https://ppa.launchpadcontent.net/ondrej/php/ubuntu",
    "noble": "https://archive.ubuntu.com/ubuntu",
    "noble-updates": "https://archive.ubuntu.com/ubuntu",
    "noble-security": "https://security.ubuntu.com/ubuntu",
}
CONTROL_NAMES = {"control", "md5sums", "conffiles", "preinst", "postinst", "prerm",
                 "postrm", "triggers", "config", "templates", "shlibs", "symbols"}
DATA_PREFIXES = ("/usr/share/ca-certificates/", "/usr/share/php", "/usr/lib/php/",
                 "/usr/lib/python", "/usr/share/debconf/", "/usr/share/perl",
                 "/usr/lib/x86_64-linux-gnu/", "/usr/lib/command-not-found/",
                 "/usr/lib/cnf-update-db", "/usr/lib/systemd/", "/usr/share/man-db/",
                 "/usr/lib/apt/", "/usr/libexec/", "/usr/share/ucf/", "/usr/share/keyrings/")
SESSION_PRODUCERS = {
    "/usr/sbin/phpquery": (6389, "0e9daa717d1af9b4e069c1976e0aad9cb07141b26b7b9a680009fe322e1e0c89"),
    "/usr/lib/php/php-helper": (4845, "72445eb0e4d94093f9f5b78038370b01741222cb7498f15ccb5cf8d476dbd83a"),
    "/usr/lib/php/sessionclean": (2976, "872504901353a3d8291c0980b43e8490580c7aa62ce7607983436a4d9c767649"),
}


def signature_status(raw, fingerprints):
    require(type(raw) is bytes and not any(token in raw for token in (
        b"BADSIG", b"ERRSIG", b"EXPSIG", b"EXPKEYSIG", b"REVKEYSIG",
        b"KEYEXPIRED", b"SIGEXPIRED", b"NO_PUBKEY", b"FAILURE")), "tool-origin")
    rows = []
    for line in raw.splitlines():
        if line.startswith(b"[GNUPG:] VALIDSIG "):
            try:
                rows.append(line.decode("ascii").split())
            except UnicodeDecodeError:
                require(False, "tool-origin")
    require(bool(rows) and all(len(row) == 12 and row[-1] in fingerprints
                              and re.fullmatch("[A-F0-9]{40}", row[2])
                              and row[4].isdigit() and row[5].isdigit()
                              and (int(row[5]) == 0 or int(row[5]) > time.time())
                              for row in rows), "tool-origin")
    return rows


class Provider(CAPTURE.Capture):
    BASE = BASE
    def __init__(self, version):
        super().__init__(version)
        self.metadata_bytes = 0
        self.archive_bytes = 0
        self.default_configuration = {}
        self.originals = {}
        self.retained_bindings = {}
        self.metadata_frames = []
        self.metadata_native_inputs = {}
        self.derived_input_pending = False
        self.future_aliases = {}
        self.metadata_locations = {}
        self.ca_owners = {}
        self.ca_owner_ticket = None
        self.query_ticket = None
        self.session_data_pins = {}
        self.sessionclean_bindings = None
        self._authenticated_lock = None
        self._transaction = None
        self._transaction_scope = None
        self._retired_pins = 0
        self.stage = "preflight"
        self.receipt.update(state="reserved", historical_chmod_performed=False,
                            standard_configuration_hardened=False,
                            native_inventory_completed=False, stage=self.stage,
                            apt_original_accepted=False, selected_signature_verified=False)
        self.receipt["installed_authority_verified"] = False
        self.receipt["signed_transaction_authorized"] = False

    def check(self):
        super().check()
        require(len(self.pins) + len(self.session_data_pins) + self._retired_pins
                <= BASE.FILE_COUNT, "file-budget")

    def session_data_pin(self, path, binding, size=0):
        self.check()
        before = self.session_data_pins.get(path)
        if before is not None:
            require(before == binding, "tool-link")
            return
        require(len(self.pins) + len(self.session_data_pins) + self._retired_pins < BASE.FILE_COUNT
                and self.bytes + size <= BASE.TOTAL_LIMIT, "file-budget")
        self.bytes += size
        self.session_data_pins[path] = binding

    def acquire(self, path, limit=BASE.FILE_LIMIT):
        if os.path.realpath(path) not in self.pins:
            require(len(self.pins) + len(self.session_data_pins) + self._retired_pins < BASE.FILE_COUNT,
                    "file-budget")
        return super().acquire(path, limit)

    def extraction_parents(self, path, root):
        self.custody()
        require(root.parent == self.directory and str(path).startswith(str(root) + "/"), "tool-link")
        result = {}
        parent = path.parent
        while True:
            self.check()
            info = os.lstat(parent)
            require(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid()
                    and not info.st_mode & 0o022 and os.path.realpath(parent) == str(parent),
                    "tool-parent")
            result[str(parent)] = BASE.identity(info)
            require(self.extracted_directory_pins.get(str(parent)) == BASE.identity(info),
                    "tool-link")
            if parent == root:
                return result
            require(parent != parent.parent, "tool-parent")
            parent = parent.parent

    def prospective_data(self, path, expected_package=None):
        """Authenticate DATA bytes; this never enters the helper/runtime catalog."""
        self.check()
        require(path.startswith(("/usr/bin/", "/usr/sbin/") + DATA_PREFIXES)
                or path == "/etc/cron.d/php", "unsafe-input")
        require(not re.search(r"[\s\x00-\x1f\x7f]", path)
                and all(part not in ("", ".", "..") for part in path.split("/")[1:]),
                "unsafe-input")
        candidates = [(name, root / path.removeprefix("/"))
                      for name, root in self.extractions.items()
                      if os.path.lexists(root / path.removeprefix("/"))]
        require(len(candidates) == 1, "tool-origin")
        name, file = candidates[0]
        require(expected_package is None or name == expected_package, "tool-origin")
        rows = [row for row in self.lock if row["package"] == name]
        require(len(rows) == 1 and self.archives[name][1] == rows[0]["sha256"], "tool-origin")
        row = rows[0]
        archive = str(self.archives[name][0])
        require(BASE.identity(os.lstat(archive)) == self.archive_pins[archive], "tool-link")
        require(str(file) in self.extracted_pins, "tool-link")
        extracted = self.extracted_pins[str(file)]
        size = extracted["identity"]["size"]
        require(type(size) is int and 0 <= size <= BASE.FILE_LIMIT, "file-budget")
        reservation = {
            "kind": "prospective-data", "installed_origin": False, "runtime_authorized": False,
            "path": path, "extracted_path": str(file), "bytes": size,
            "sha256": extracted["sha256"], "identity": dict(extracted["identity"]),
            "archive_sha256": row["sha256"], "archive_identity": dict(self.archive_pins[archive]),
            "package": [name, row["version"], row["architecture"], row["source"], row["source_version"]],
        }
        pin = self.session_data_pins.get(str(file))
        fresh = pin is None
        if fresh:
            pin = dict(reservation, state="reserved")
            self.session_data_pin(str(file), pin, size)
        else:
            require(pin.get("state") == "pinned"
                    and all(pin.get(key) == value for key, value in reservation.items()), "tool-link")
        parents = self.extraction_parents(file, self.extractions[name])
        if fresh:
            pin["parents"] = parents
        else:
            require(pin["parents"] == parents, "tool-link")
        require(BASE.identity(os.lstat(file)) == pin["identity"], "tool-link")
        raw = self.extracted_file(file, limit=size)
        require(len(raw) == size and BASE.identity(os.lstat(file)) == pin["identity"], "tool-link")
        control = self.archives[name][2]
        require("md5sums" in control, "tool-origin")
        try:
            matches = [line.split() for line in control["md5sums"].decode("ascii").splitlines()
                       if line.split() and line.split()[-1] == path.removeprefix("/")]
        except UnicodeError:
            raise BASE.Refusal("tool-origin") from None
        md5 = hashlib.md5(raw).hexdigest()
        require(matches == [[md5, path.removeprefix("/")]], "tool-origin")
        manifest = POLICY.digest(control["md5sums"])
        if fresh:
            label = "session-data-" + str(len(self.originals))
            self.retain(label, raw)
        else:
            require(pin["md5"] == md5 and pin["manifest_sha256"] == manifest, "tool-origin")
            label = pin["retained_label"]
        _, retained = self.retained_metadata(label)
        require(retained == raw and self.extraction_parents(file, self.extractions[name]) == parents
                and self.extracted_file(file, limit=size) == raw
                and BASE.identity(os.lstat(archive)) == self.archive_pins[archive], "tool-link")
        pin.update(state="pinned", md5=md5, manifest_sha256=manifest, retained_label=label)
        return dict(pin), raw

    def session_installed_binding(self, path, binding):
        canonical = binding["canonical"]
        require(os.path.realpath(path) == canonical, "tool-link")
        observed = {
            "canonical": canonical, "identity": dict(binding["identity"]),
            "requested_identity": BASE.identity(os.lstat(path)),
            "parents": BASE.parent_pins(canonical),
            "requested_parents": BASE.parent_pins(path),
            "bytes": len(binding["raw"]), "sha256": binding["sha256"],
        }
        require(observed["parents"] == self.pins[canonical]["parents"], "tool-parent")
        self.verify_session_installed_binding(path, observed)
        return observed

    def verify_session_installed_binding(self, path, binding):
        self.check()
        canonical = binding["canonical"]
        require(os.path.realpath(path) == canonical
                and BASE.identity(os.lstat(path)) == binding["requested_identity"], "tool-link")
        require(BASE.parent_pins(canonical) == binding["parents"]
                and BASE.parent_pins(path) == binding["requested_parents"], "tool-parent")
        actual, info, raw = self.read(canonical, limit=binding["bytes"])
        require(actual == canonical and BASE.identity(info) == binding["identity"]
                and len(raw) == binding["bytes"] and POLICY.digest(raw) == binding["sha256"]
                and os.path.realpath(path) == canonical
                and BASE.identity(os.lstat(path)) == binding["requested_identity"], "tool-link")
        require(BASE.parent_pins(canonical) == binding["parents"]
                and BASE.parent_pins(path) == binding["requested_parents"], "tool-parent")

    def session_directory(self, path, extraction=None):
        """Bounded immediate listing, including entries GNU find would reject later."""
        self.check()
        path = Path(path)
        probe = path
        absent = []
        while True:
            try:
                info = os.lstat(probe)
                break
            except FileNotFoundError:
                absent.append(str(probe))
                require(probe != probe.parent, "tool-parent")
                probe = probe.parent
                self.check()
        if extraction is None:
            parents = BASE.parent_pins(str(probe / "session-data-placeholder"))
        else:
            parents = self.extraction_parents(probe / "session-data-placeholder", extraction)
        require(stat.S_ISDIR(info.st_mode) and os.path.realpath(probe) == str(probe), "tool-parent")
        result = {"path": str(path), "absent": absent, "parents": parents,
                  "identity": None if absent else BASE.identity(info), "entries": []}
        if absent:
            return result
        self.session_data_pin(str(path), {"kind": "directory-data", "identity": BASE.identity(info),
                                        "parents": parents})
        with os.scandir(str(path)) as stream:
            for entry in stream:
                self.check()
                require(len(result["entries"]) < 8192, "file-budget")
                candidate = path / entry.name
                current = os.lstat(candidate)
                result["entries"].append({"name": entry.name, "identity": BASE.identity(current)})
        result["entries"].sort(key=lambda entry: entry["name"])
        require(BASE.identity(os.lstat(path)) == result["identity"], "tool-link")
        after = (BASE.parent_pins(str(path / "session-data-placeholder")) if extraction is None
                 else self.extraction_parents(path / "session-data-placeholder", extraction))
        require(after == parents, "tool-parent")
        return result

    def session_domain(self, listing):
        versions = []
        for entry in listing["entries"]:
            name = entry["name"]
            # GNU find's default Emacs regex matches the entire full path. There
            # is no -type predicate; validate every match before shell splitting.
            if re.fullmatch(r".*[0-9]\.[0-9]", "/usr/lib/php/" + name, re.DOTALL):
                require(re.fullmatch(r"[0-9]\.[0-9]", name)
                        and stat.S_ISDIR(entry["identity"]["mode"]), "php-config")
                versions.append(name)
        return sorted(set(versions), key=lambda version: tuple(map(int, version.split("."))),
                      reverse=True)

    def session_producer(self, path):
        if "php-common" in self.extractions:
            binding, raw = self.prospective_data(path, "php-common")
            binding["producer_role"] = "incoming-transaction-data"
        else:
            row = self.bound_file(path, "php-common")
            require(row["package"][1] == self.installed["php-common"], "tool-origin")
            raw = row["raw"]
            binding = dict(self.session_installed_binding(path, row),
                           package=row["package"], manifest_sha256=row["manifest_sha256"],
                           bytes=len(raw),
                           producer_role="unchanged-installed-producer")
        require((len(raw), POLICY.digest(raw)) == SESSION_PRODUCERS[path], "tool-origin")
        return binding, raw

    def session_worker_guard(self, version):
        binary = "/usr/bin/php" + version
        guards = {}
        for path in (binary,) + tuple("/etc/php/" + version + "/" + sapi + "/php.ini"
                                     for sapi in ("apache2", "apache2filter", "cgi", "fpm", "cli")):
            try:
                info = os.lstat(path)
            except FileNotFoundError:
                ancestor = Path(path).parent
                missing = []
                while not os.path.lexists(ancestor):
                    self.check()
                    missing.append(str(ancestor))
                    require(ancestor != ancestor.parent, "tool-parent")
                    ancestor = ancestor.parent
                guards[path] = {
                    "present": False, "absent_parents": missing,
                    "parents": BASE.parent_pins(str(ancestor / "session-guard-placeholder")),
                }
                continue
            canonical = os.path.realpath(path)
            try:
                actual = os.lstat(canonical)
            except FileNotFoundError:
                raise BASE.Refusal("tool-link") from None
            guards[path] = {
                "present": True, "canonical": canonical, "identity": BASE.identity(info),
                "target_identity": BASE.identity(actual), "parents": BASE.parent_pins(path),
                "executable": bool(actual.st_mode & 0o111) if path == binary else False,
            }
        active = guards[binary]["present"] and guards[binary]["executable"] and any(
            row["present"] for path, row in guards.items() if path != binary)
        return {"active": bool(active), "guards": guards}

    def verify_worker_guards(self, guards):
        for version, before in guards.items():
            require(self.session_worker_guard(version) == before, "tool-link")

    def observe_session_verification(self):
        evidence = {
            "schema": "session-verification-data-v1", "state": "UNRESOLVED",
            "runtime_authorized": False, "root_scheduler_authorized": True,
            "observation_scope": "pre-lifecycle-data-only",
            "unresolved": ["live-loader-resolution", "effective-INI-and-module-loading",
                           "live-service-timer-cron-process-state",
                           "post-install-domain-and-identities"],
            "before": {"workers": {}}, "planned": {}, "producers": {}, "data": [],
        }
        self.receipt["session_verification"] = evidence
        try:
            before = self.session_directory("/usr/lib/php")
            evidence["before"]["listing"] = before
            evidence["before"]["versions"] = self.session_domain(before)
            planned = {}
            for name, root in self.extractions.items():
                listing = self.session_directory(root / "usr/lib/php", root)
                planned[name] = listing
                evidence["planned"][name] = {
                    "listing": listing, "versions": self.session_domain(listing),
                    "archive_sha256": self.archives[name][1],
                }
            for path, (size, sha) in SESSION_PRODUCERS.items():
                binding, raw = self.session_producer(path)
                evidence["producers"][path] = dict(binding, status="UNRESOLVED")
                require((len(raw), POLICY.digest(raw)) == (size, sha), "tool-origin")
                evidence["producers"][path]["status"] = "COMPLETE"
            evidence["domain_status"] = "COMPLETE"
            evidence["query_semantics"] = {
                "root": "/usr/lib/php", "immediate_children": True,
                "fullpath_emacs_regex": r".*[0-9]\.[0-9]", "type_filter": None,
                "shell_word_split_then_sort": "-rn",
                "noncanonical_matching_entries": "refuse",
            }
            for version in evidence["before"]["versions"]:
                worker = self.session_worker_guard(version)
                evidence["before"].setdefault("workers", {})[version] = worker
                if not worker["active"]:
                    continue
                binary = "/usr/bin/php" + version
                binding = self.bound_file(binary, "php" + version + "-cli")
                require(binding["package"][3] == "php" + version, "tool-origin")
                BASE.parse_elf(binding["raw"])
                binary_binding = {
                    key: value for key, value in binding.items() if key != "raw"}
                binary_binding.update(self.session_installed_binding(binary, binding))
                evidence["before"].setdefault("binaries", {})[version] = binary_binding
                configs = evidence["before"].setdefault("configs", {})
                for sapi in ("apache2", "apache2filter", "cgi", "fpm", "cli"):
                    ini = "/etc/php/" + version + "/" + sapi + "/php.ini"
                    try:
                        os.lstat(ini)
                    except FileNotFoundError:
                        configs[ini] = {"status": "absent-at-observation"}
                        continue
                    canonical = os.path.realpath(ini)
                    require(canonical.startswith("/etc/php/" + version + "/"), "php-config")
                    raw = self.protected(canonical)
                    extension = re.findall(
                        rb"(?mi)^\s*extension_dir\s*=\s*[\"']?(/usr/lib/php/[0-9]{8})", raw)
                    require(len(extension) <= 1, "php-config")
                    BASE.configured_modules(raw, extension[0].decode() if extension
                                            else "/usr/lib/php/00000000")
                    configs[ini] = dict(self.session_installed_binding(ini, {
                        "canonical": canonical, "identity": self.pins[canonical]["identity"],
                        "sha256": POLICY.digest(raw), "raw": raw}),
                        status="custody-only", effective_config_status="UNRESOLVED")
            paths = set()
            for file in self.extracted_pins:
                for root in self.extractions.values():
                    prefix = str(root) + "/"
                    if file.startswith(prefix):
                        path = "/" + file[len(prefix):]
                        if (path == "/usr/bin/php" + self.version
                                or re.fullmatch(r"/usr/lib/php/" + re.escape(self.version)
                                                + r"/php\.ini-production(?:\.[a-z0-9]+)?", path)
                                or re.fullmatch(r"/usr/share/php" + re.escape(self.version)
                                                + r"-[a-z0-9-]+/[^/]+/[^/]+\.ini", path)
                                or re.fullmatch(r"/usr/lib/php/[0-9]{8}/[^/]+\.so", path)):
                            paths.add(path)
            require("/usr/bin/php" + self.version in paths, "tool-origin")
            require(any("/php.ini-production" in path for path in paths), "tool-origin")
            for path in sorted(paths):
                binding, raw = self.prospective_data(
                    path, "php" + self.version + "-cli"
                    if path == "/usr/bin/php" + self.version else None)
                if path.endswith(".so") or path == "/usr/bin/php" + self.version:
                    BASE.parse_elf(raw)
                    binding["ELF_header_status"] = "COMPLETE"
                    binding["loader_closure_status"] = "UNRESOLVED"
                evidence["data"].append(binding)
            for path in ("/usr/lib/systemd/system/phpsessionclean.service",
                         "/usr/lib/systemd/system/phpsessionclean.timer", "/etc/cron.d/php"):
                if any(os.path.lexists(root / path.removeprefix("/"))
                       for root in self.extractions.values()):
                    binding, _ = self.prospective_data(path, "php-common")
                    evidence.setdefault("scheduler_planned", {})[path] = binding
            for path in ("/etc/cron.d/php", "/usr/lib/systemd/system/phpsessionclean.service",
                         "/usr/lib/systemd/system/phpsessionclean.timer"):
                try:
                    os.lstat(path)
                except FileNotFoundError:
                    evidence.setdefault("scheduler_static", {})[path] = {"status": "absent"}
                    continue
                raw = self.protected(path)
                evidence.setdefault("scheduler_static", {})[path] = dict(
                    self.session_installed_binding(path, {
                        "canonical": path, "identity": self.pins[path]["identity"],
                        "sha256": POLICY.digest(raw), "raw": raw}),
                    status="custody-only", does_not_prove_nonexecution=True)
            require(self.session_directory("/usr/lib/php") == before, "tool-link")
            for name, root in self.extractions.items():
                require(self.session_directory(root / "usr/lib/php", root) == planned[name],
                        "tool-link")
            for path, binding in self.session_data_pins.items():
                require(BASE.identity(os.lstat(path)) == binding["identity"], "tool-link")
                if binding["kind"] == "prospective-data":
                    name = binding["package"][0]
                    require(self.extraction_parents(Path(path), self.extractions[name])
                            == binding["parents"]
                            and POLICY.digest(self.extracted_file(
                                Path(path), limit=binding["bytes"])) == binding["sha256"],
                            "tool-link")
            for version, binding in evidence["before"].get("binaries", {}).items():
                self.verify_session_installed_binding(
                    "/usr/bin/php" + version, binding)
            self.verify_worker_guards(evidence["before"].get("workers", {}))
            for path, binding in evidence.get("scheduler_static", {}).items():
                if binding["status"] == "absent":
                    try:
                        os.lstat(path)
                    except FileNotFoundError:
                        continue
                    require(False, "tool-link")
                else:
                    self.verify_session_installed_binding(path, binding)
            for path, binding in evidence["before"].get("configs", {}).items():
                if binding["status"] == "absent-at-observation":
                    try:
                        os.lstat(path)
                    except FileNotFoundError:
                        continue
                    require(False, "tool-link")
                else:
                    self.verify_session_installed_binding(path, binding)
            evidence["data_status"] = "COMPLETE"
            evidence["observation_interval_status"] = "COMPLETE"
        except Exception as error:
            evidence["failure"] = {"type": type(error).__name__, "reason": str(error)}
            raise
        finally:
            evidence["data_pin_ledger"] = json.loads(json.dumps(self.session_data_pins))
            evidence["resource_counts"] = {
                "physical_pins": len(self.pins), "data_pins": len(self.session_data_pins),
                "combined_pins": len(self.pins) + len(self.session_data_pins),
                "acquisition_bytes": self.bytes, "metadata_bytes": self.metadata_bytes,
                "record_bytes_before_evidence": self.record_bytes,
                "metadata_frames": len(self.metadata_frames),
                "metadata_native_inputs": len(self.metadata_native_inputs),
                "captured_stream_bytes": self.capture_bytes,
                "commands": len(self.commands), "root_launches": self.root_launches,
            }
            self.write("session-verification.private.json", json.dumps(evidence, sort_keys=True).encode())

    def sessionclean_runtime_bindings(self):
        """Bind the genuine cleanup loop to observed installed authority only."""
        if self.sessionclean_bindings is not None:
            self.verify_sessionclean_runtime_bindings()
            return self.sessionclean_bindings["versions"]
        evidence = self.receipt["session_verification"]
        require(evidence["data_status"] == "COMPLETE"
                and evidence["domain_status"] == "COMPLETE", "tool-origin")
        for path, expected in SESSION_PRODUCERS.items():
            row = evidence["producers"][path]
            require(row["status"] == "COMPLETE"
                    and (row["bytes"], row["sha256"]) == expected, "tool-origin")
            binding, raw = self.session_producer(path)
            require((len(raw), POLICY.digest(raw)) == expected
                    and binding["producer_role"] == row["producer_role"]
                    and binding.get("archive_sha256") == row.get("archive_sha256"), "tool-origin")
        listing = evidence["before"]["listing"]
        require(self.session_directory("/usr/lib/php") == listing, "tool-link")
        versions = self.session_domain(listing)
        planned = set()
        for package, record in evidence["planned"].items():
            current = self.session_directory(self.extractions[package] / "usr/lib/php",
                                             self.extractions[package])
            require(current == record["listing"], "tool-link")
            planned.update(self.session_domain(current))
        missing = sorted(planned - set(versions))
        if missing:
            self.receipt["sessionclean_missing_installed_versions"] = missing
            require(False, "tool-origin")
        self.snapshot_php_state({version for version in versions
                                 if evidence["before"]["workers"][version]["active"]})
        bindings = {}
        observed = {}
        for record in evidence["data"]:
            path = record["path"]
            _, future = self.prospective_data(path)
            installed = self.bound_file(path, record["package"][0])
            require(installed["canonical"] == path and future == installed["raw"],
                    "tool-origin")
            if record.get("ELF_header_status") == "COMPLETE":
                self.dependencies(path)
            observed[path] = self.session_installed_binding(path, installed)
        for version in versions:
            if not evidence["before"]["workers"][version]["active"]:
                continue
            binary = "/usr/bin/php" + version
            binding = self.bound_file(binary, "php" + version + "-cli")
            require(binding["canonical"] == binary
                    and binding["package"][3] == "php" + version, "tool-origin")
            BASE.parse_elf(binding["raw"])
            self.dependencies(binary)
            observed[binary] = self.session_installed_binding(binary, binding)
            # An authenticated future ELF is DATA, not an observed installed
            # executable. A replacement cannot inherit the old runtime pin.
            for root in self.extractions.values():
                candidate = root / binary.removeprefix("/")
                if os.path.lexists(candidate):
                    _, future = self.prospective_data(binary, "php" + version + "-cli")
                    require(future == binding["raw"], "tool-origin")
            bindings[version] = {
                "binary": binary, "installed_origin_verified": True,
                "configuration_verified": True,
                "package": binding["package"], "sha256": binding["sha256"],
            }
        self.sessionclean_bindings = {
            "listing": listing, "versions": bindings, "installed_files": observed,
            "configs": dict(self.receipt["all_sapi_before"]),
            "directories": dict(self.receipt["sessionclean_configuration_directories"]),
            "absent_configs": [
                path for path, row in evidence["before"].get("configs", {}).items()
                if row["status"] == "absent-at-observation"],
            "worker_guards": evidence["before"]["workers"],
        }
        self.verify_sessionclean_runtime_bindings()
        self.receipt["sessionclean_runtime_bindings"] = {
            "versions": bindings, "prospective_origin_used_as_installed": False,
            "scheduler_execution_observed": False,
        }
        return bindings

    def verify_sessionclean_runtime_bindings(self):
        require(self.sessionclean_bindings is not None, "tool-origin")
        bindings = self.sessionclean_bindings
        self.verify_worker_guards(bindings["worker_guards"])
        require(self.session_directory("/usr/lib/php") == bindings["listing"], "tool-link")
        for path, binding in bindings["installed_files"].items():
            self.verify_session_installed_binding(path, binding)
        for path, binding in bindings["configs"].items():
            self.verify_session_installed_binding(path, binding)
        for path, listing in bindings["directories"].items():
            require(self.session_directory(path) == listing, "tool-link")
        for path in bindings["absent_configs"]:
            try:
                os.lstat(path)
            except FileNotFoundError:
                continue
            require(False, "tool-link")
        for path in tuple(self.files):
            self.system(path)
        TRUST.verify_loader(self)
        self.check()

    def transaction_snapshot(self):
        return {
            "lock": self.lock,
            "archives": {name: {
                "path": str(item[0]), "sha256": item[1],
                "identity": self.archive_pins[str(item[0])],
                "controls": {key: POLICY.digest(raw) for key, raw in item[2].items()},
            } for name, item in self.archives.items()},
            "extractions": {name: str(path) for name, path in self.extractions.items()},
            "extracted_files": self.extracted_pins,
            "extracted_directories": self.extracted_directory_pins,
            "sources": self.receipt["producer_sources"],
            "apt_configuration": self.receipt["effective_apt_config_sha256"],
        }

    def close_transaction(self):
        require(self._transaction is None
                and self._authenticated_lock == POLICY.digest(
                    json.dumps(self.lock, sort_keys=True).encode()), "tool-origin")
        POLICY.lock_tokens(self.lock, self.protected_packages)
        require(set(self.archives) == set(self.extractions)
                == {row["package"] for row in self.lock}, "tool-origin")
        for row in self.lock:
            control = POLICY.deb822(self.archives[row["package"]][2]["control"])[0]
            require((control["Package"], control["Version"], control["Architecture"])
                    == (row["package"], row["version"], row["architecture"]), "tool-origin")
        raw = json.dumps(self.transaction_snapshot(), sort_keys=True).encode()
        self.retain("closed-install-transaction", raw)
        self._transaction = {"nonce": object(), "raw": raw, "lifecycle_verified": False,
                             "consumed": False}
        self.verify_transaction()

    def parent_pins_for_control(self, path):
        self.check()
        require(path.startswith("/var/lib/dpkg/info/") and ".." not in path.split("/"),
                "unsafe-input")
        return BASE.parent_pins(path)

    def verify_transaction(self):
        self.check()
        POLICY.entry(self.version, os.environ, os.getuid(), os.geteuid(), sys.platform)
        require(self.setup_ready and self._transaction is not None
                and not self._transaction["consumed"]
                and self.root_cursor == POLICY.OPERATIONS.index("apt-install")
                and self.receipt["accepted_root_operations"]
                == list(POLICY.OPERATIONS[:self.root_cursor])
                and self.receipt["apt_original_accepted"] is True
                and self.receipt["selected_signature_verified"] is True, "tool-origin")
        self.verify_sources()
        self.custody()
        _, raw = self.retained_metadata("closed-install-transaction")
        require(raw == self._transaction["raw"]
                == json.dumps(self.transaction_snapshot(), sort_keys=True).encode(),
                "tool-link")
        POLICY.lock_tokens(self.lock, self.protected_packages)
        for row in self.lock:
            path, sha, _ = self.archives[row["package"]]
            _, info, body = self.read(str(path), limit=row["size"])
            require(BASE.identity(info) == self.archive_pins[str(path)]
                    and len(body) == row["size"] and POLICY.digest(body) == sha == row["sha256"],
                    "tool-origin")
        for path, before in self.extracted_pins.items():
            require(BASE.identity(os.lstat(path)) == before["identity"], "tool-link")
            require(POLICY.digest(self.extracted_file(Path(path))) == before["sha256"], "tool-link")
            roots = [root for root in self.extractions.values()
                     if path.startswith(str(root) + "/")]
            require(len(roots) == 1, "tool-link")
            self.extraction_parents(Path(path), roots[0])
        evidence = self.receipt["session_verification"]
        require(evidence["domain_status"] == evidence["data_status"] == "COMPLETE",
                "tool-origin")
        require(self.session_directory("/usr/lib/php") == evidence["before"]["listing"],
                "tool-link")
        self.verify_worker_guards(evidence["before"]["workers"])
        for path in SESSION_PRODUCERS:
            binding, _ = self.session_producer(path)
            recorded = evidence["producers"][path]
            require(binding["producer_role"] == recorded["producer_role"]
                    and binding.get("archive_sha256") == recorded.get("archive_sha256"),
                    "tool-origin")
        for version, binding in evidence["before"].get("binaries", {}).items():
            self.verify_session_installed_binding("/usr/bin/php" + version, binding)
        for path, binding in self.receipt["all_sapi_before"].items():
            self.verify_session_installed_binding(path, binding)
        for path, listing in self.receipt["sessionclean_configuration_directories"].items():
            require(self.session_directory(path) == listing, "tool-link")
        for path in tuple(self.files):
            self.system(path)
        for path, pin in tuple(self.pins.items()):
            require(os.path.realpath(path) == path, "tool-link")
            self.acquire(path, limit=pin["bytes"])
            for alias in tuple(pin["aliases"]):
                require(os.path.realpath(alias) == path, "tool-link")
                self.acquire(alias, limit=pin["bytes"])
        for package, basename in self.receipt.get("installed_control_basenames", {}).items():
            require(TRUST.installed_control_basename(self, package) == basename, "tool-origin")
        for path, before in self.receipt.get("installed_lifecycle_presence", {}).items():
            require(os.path.lexists(path) is before["exists"]
                    and BASE.parent_pins(path) == before["parents"], "tool-link")
            if before["exists"]:
                pin = self.pins[path]
                actual, info, raw = self.read(path, limit=pin["bytes"])
                require(actual == path and BASE.identity(info) == pin["identity"]
                        and POLICY.digest(raw) == pin["sha256"]
                        and BASE.identity(os.lstat(path)) == pin["identity"]
                        and BASE.parent_pins(path) == before["parents"], "tool-link")
        TRUST.verify_loader(self)

    def transaction_active(self):
        if self._transaction_scope is None:
            return False
        require(self._transaction is not None and not self._transaction["consumed"]
                and self._transaction_scope is self._transaction["nonce"], "tool-origin")
        return True

    @contextmanager
    def transaction_admission(self, phase):
        require(phase in ("lifecycle", "apt-install") and self._transaction_scope is None,
                "unsafe-input")
        self.verify_transaction()
        if phase == "apt-install":
            require(self._transaction["lifecycle_verified"] is self._transaction["nonce"],
                    "tool-origin")
        self._transaction_scope = self._transaction["nonce"]
        try:
            yield
            if phase == "lifecycle":
                self.verify_transaction()
                expected = {
                    name: {key: POLICY.digest(raw) for key, raw in item[2].items()
                           if key in ("preinst", "postinst", "prerm", "postrm", "triggers", "config")}
                    for name, item in self.archives.items()}
                require(self.receipt.get("accepted_package_lifecycle") == expected
                        and "activated_installed_triggers" in self.receipt, "tool-origin")
                self._transaction["lifecycle_verified"] = self._transaction["nonce"]
                self.receipt["signed_transaction_authorized"] = True
            else:
                require(self.root_cursor == POLICY.OPERATIONS.index("select-php")
                        and self.receipt["accepted_root_operations"]
                        == list(POLICY.OPERATIONS[:self.root_cursor]), "native-command")
                self._transaction["consumed"] = True
        finally:
            self._transaction_scope = None

    def transaction_helper_rows(self, path, expected_package=None):
        require(self.transaction_active(), "tool-origin")
        rows = []
        if os.path.lexists(path):
            rows.append(self.bound_file(path, expected_package))
        if any(os.path.lexists(root / path.removeprefix("/"))
               for root in self.extractions.values()):
            if path == "/usr/sbin/phpdismod":
                self.prospective_data("/usr/sbin/phpenmod", "php-common")
                row = self.future_helper(path, expected_package)
            else:
                binding, raw = self.prospective_data(path, expected_package)
                row = dict(binding, canonical=path, raw=raw, future=True)
            require(row["package"][0] in {item["package"] for item in self.lock}, "tool-origin")
            if row["raw"].startswith(b"\x7fELF"):
                BASE.parse_elf(row["raw"])
            row["authority_role"] = "transaction-authorized-prospective-code"
            rows.append(row)
        require(rows, "tool-origin")
        for row in rows:
            self.receipt.setdefault("transaction_code", {}).setdefault(path, {})[
                row.get("authority_role", "verified-retained-installed-code")] = {
                    "sha256": row["sha256"], "package": row["package"],
                    "manifest_sha256": row["manifest_sha256"],
                    "observed_installed_origin": not row.get("future", False),
                    "direct_execution_authorized": False,
                }
        return rows

    def transaction_sessionclean_paths(self):
        require(self.transaction_active(), "tool-origin")
        evidence = self.receipt["session_verification"]
        versions = set(evidence["before"]["versions"])
        for record in evidence["planned"].values():
            versions.update(record["versions"])
        require(len(versions) <= 64, "file-budget")
        paths = []
        for version in sorted(versions):
            if any(os.path.lexists(root / ("usr/bin/php" + version))
                   for root in self.extractions.values()) or (
                    version in evidence["before"]["workers"]
                    and evidence["before"]["workers"][version]["active"]):
                path = "/usr/bin/php" + version
                self.transaction_helper_rows(path, "php" + version + "-cli")
                paths.append(path)
        self.receipt["sessionclean_transaction"] = {
            "query_domain": sorted(versions), "eligible_transaction_paths": paths,
            "authority_role": "fixed-signed-apt-transaction",
            "installed_origin_inferred_from_prospective": False,
            "scheduler_execution_observed": False,
        }
        return paths

    def system(self, path):
        special = path.startswith(("/usr/sbin/", "/usr/share/ca-certificates/",
                                   "/usr/share/debconf/", "/usr/share/perl",
                                   "/usr/share/ucf/", "/usr/share/keyrings/"))
        if not special:
            return super().system(path)
        canonical = os.path.realpath(path)
        require(canonical.startswith(("/usr/sbin/",) + DATA_PREFIXES), "tool-link")
        requested = os.lstat(path)
        require(requested.st_uid == 0 and (
            stat.S_ISLNK(requested.st_mode) or not requested.st_mode & 0o022), "tool-link")
        if canonical in self.files:
            entry = self.files[canonical]
            require(BASE.identity(os.lstat(canonical)) == entry["identity"]
                    and BASE.parent_pins(canonical) == entry["parents"], "tool-link")
            if path in entry["aliases"]:
                require(BASE.identity(requested) == entry["aliases"][path], "tool-link")
            return canonical
        actual, info, raw = self.acquire(path)
        GUARD.no_capabilities(actual)
        elf = raw.startswith(b"\x7fELF")
        if elf:
            BASE.parse_elf(raw)
        self.files[actual] = {"identity": BASE.identity(info), "parents": BASE.parent_pins(actual),
                             "sha256": POLICY.digest(raw), "md5": hashlib.md5(raw).hexdigest(),
                             "elf": elf, "header64_hex": raw[:64].hex(),
                             "aliases": {path: BASE.identity(os.lstat(path))}}
        return actual

    def bound_file(self, path, expected_package=None):
        require(path == "/bin/sh"
                or path.startswith(("/usr/bin/", "/usr/sbin/") + DATA_PREFIXES), "unsafe-input")
        canonical = self.system(path)
        self.origin(canonical)
        entry = self.files[canonical]
        origin = self.origins[canonical]
        package = origin["package"]
        if expected_package is not None:
            require(package[0].split(":")[0] == expected_package, "tool-origin")
        _, info, raw = self.read(canonical, limit=BASE.FILE_LIMIT)
        require(BASE.identity(info) == entry["identity"]
                and POLICY.digest(raw) == entry["sha256"], "tool-link")
        binding = {"raw": raw, "canonical": canonical, "identity": BASE.identity(info),
                   "sha256": POLICY.digest(raw), "package": list(package),
                   "manifest_sha256": origin["manifest_sha256"]}
        pin = json.dumps({name: value for name, value in binding.items() if name != "raw"},
                         sort_keys=True).encode("ascii")
        before = self.retained_bindings.get(canonical)
        if before is None:
            label = "bound-file-" + str(len(self.originals))
            self.retain(label, raw)
        else:
            require(before["pin"] == pin, "tool-origin")
            label = before["label"]
        retained_identity, retained = self.retained_metadata(label)
        require(self.originals.get(label) == raw and retained == raw, "retention")
        self.check()
        if before is None:
            self.retained_bindings[canonical] = {
                "pin": pin, "label": label, "identity": retained_identity}
        else:
            require(retained_identity == before["identity"], "retention")
        return binding

    def read_metadata_frame(self, frame):
        self.check()
        path = str(self.directory / frame["name"])
        _, info, raw = self.read(path, os.geteuid(), CAPTURE.RECORDS)
        require(BASE.original_identity(info) == frame["identity"]
                and len(raw) == frame["length"]
                and POLICY.digest(raw) == frame["sha256"] and not frame["dirty"], "retention")
        self.check()
        return raw

    @contextmanager
    def append_metadata_frame(self, frame):
        self.read_metadata_frame(frame)
        path = self.directory / frame["name"]
        descriptor = os.open(path, os.O_WRONLY | os.O_APPEND | os.O_NOFOLLOW | os.O_CLOEXEC)
        with os.fdopen(descriptor, "ab") as stream:
            BASE.verify_original(stream, path, frame["identity"])
            yield stream
            BASE.verify_original(stream, path, frame["identity"])

    def retained_metadata(self, label):
        require(label in self.metadata_locations, "retention")
        location = self.metadata_locations[label]
        frame = self.metadata_frames[location["frame"]]
        raw = self.read_metadata_frame(frame)
        body = raw[location["offset"]:location["offset"] + location["length"]]
        require(len(body) == location["length"] and POLICY.digest(body) == location["sha256"]
                and self.originals.get(label) == body, "retention")
        return dict(frame["identity"]), body

    def retain_metadata(self, name, raw):
        self.check()
        require(type(raw) is bytes and type(name) is str
                and name not in self.originals and re.fullmatch(r"[a-z0-9.-]+", name), "retention")
        header = (json.dumps({"label": name, "length": len(raw), "sha256": POLICY.digest(raw)},
                             sort_keys=True, separators=(",", ":")) + "\n").encode("ascii")
        encoded = header + raw + b"\n"
        require(self.metadata_bytes + len(raw) <= 67108864
                and self.record_bytes + len(header) + 1 <= CAPTURE.RECORDS
                and len(encoded) <= CAPTURE.RECORDS, "file-budget")
        fresh = (not self.metadata_frames or self.metadata_frames[-1]["records"] == 32
                 or self.metadata_frames[-1]["length"] + len(encoded) > CAPTURE.RECORDS)
        if fresh:
            require(len(self.metadata_frames) + len(self.metadata_native_inputs) < 256,
                    "file-budget")
            frame = {"name": "metadata-frame-%d.original.private" % len(self.metadata_frames),
                     "identity": None, "length": 0, "records": 0,
                     "sha256": POLICY.digest(b""), "dirty": False}
            self.metadata_frames.append(frame)
        else:
            frame = self.metadata_frames[-1]
        previous = b"" if fresh else self.read_metadata_frame(frame)
        offset = frame["length"] + len(header)
        self.metadata_bytes += len(raw)
        self.record_bytes += len(header) + 1
        stream_context = self.original(frame["name"]) if fresh else self.append_metadata_frame(frame)
        with stream_context as stream:
            if fresh:
                frame["identity"] = BASE.original_identity(os.fstat(stream.fileno()))
            frame["dirty"] = True
            for position in range(0, len(encoded), 65536):
                self.check()
                chunk = encoded[position:position + 65536]
                require(stream.write(chunk) == len(chunk), "retention")
            stream.flush()
            os.fsync(stream.fileno())
            BASE.verify_original(stream, self.directory / frame["name"], frame["identity"])
        frame.update(length=len(previous) + len(encoded), records=frame["records"] + 1,
                     sha256=POLICY.digest(previous + encoded), dirty=False)
        observed = self.read_metadata_frame(frame)
        require(observed[offset:offset + len(raw)] == raw, "retention")
        self.originals[name] = raw
        self.metadata_locations[name] = {
            "frame": len(self.metadata_frames) - 1, "offset": offset,
            "length": len(raw), "sha256": POLICY.digest(raw)}
        self.check()
        return raw

    def metadata_witness(self):
        require(not self.derived_input_pending, "retention")
        for name in self.metadata_native_inputs:
            self.verify_metadata_native_input(name)
        self.check()
        seen = set()
        for number, frame in enumerate(self.metadata_frames):
            raw = self.read_metadata_frame(frame)
            offset, count = 0, 0
            while offset < len(raw):
                self.check()
                end = raw.find(b"\n", offset)
                require(end >= 0 and end - offset <= 4096, "retention")
                header = BASE.decode_object(raw[offset:end])
                require(set(header) == {"label", "length", "sha256"}
                        and type(header["label"]) is str
                        and type(header["length"]) is int and header["length"] >= 0
                        and header["label"] not in seen, "retention")
                label, start = header["label"], end + 1
                finish = start + header["length"]
                require(finish < len(raw) and raw[finish:finish + 1] == b"\n"
                        and self.metadata_locations.get(label) == {
                            "frame": number, "offset": start, "length": header["length"],
                            "sha256": header["sha256"]}
                        and POLICY.digest(raw[start:finish]) == header["sha256"]
                        and self.originals.get(label) == raw[start:finish], "retention")
                seen.add(label)
                count += 1
                offset = finish + 1
            require(count == frame["records"] and count <= 32, "retention")
        require(seen == set(self.metadata_locations), "retention")
        witness = {"schema": "retained-metadata-frames-v1", "frames": self.metadata_frames,
                   "native_inputs": self.metadata_native_inputs,
                   "observations": self.metadata_locations, "metadata_bytes": self.metadata_bytes,
                   "record_bytes_before_witness": self.record_bytes,
                   "frame_limit": 256, "observations_per_frame": 32,
                   "metadata_byte_limit": 67108864, "record_byte_limit": CAPTURE.RECORDS,
                   "same_setup_deadline": self.deadline}
        encoded = json.dumps(witness, sort_keys=True).encode("ascii")
        self.write("metadata-frame-ledger.private.json", encoded)
        _, _, observed = self.read(str(self.directory / "metadata-frame-ledger.private.json"),
                                  os.geteuid(), CAPTURE.RECORDS)
        require(observed == encoded, "retention")
        self.check()
        return witness

    def verify_metadata_native_input(self, name):
        entry = self.metadata_native_inputs[name]
        require(entry.get("dirty") is False, "retention")
        require(os.path.realpath(entry["path"]) == entry["path"], "retention")
        _, info, raw = self.read(entry["path"], os.geteuid(), entry["bytes"])
        require(BASE.identity(info) == entry["identity"]
                and stat.S_IMODE(info.st_mode) == 0o600 and info.st_nlink == 1
                and raw == self.originals[name] and POLICY.digest(raw) == entry["sha256"],
                "retention")
        if "derivation" in entry:
            derivation = entry["derivation"]
            intent_path = str(self.directory / "selected-signature.intent.private.json")
            require(os.path.realpath(intent_path) == intent_path, "retention")
            _, intent_info, intent = self.read(intent_path, os.geteuid(), CAPTURE.RECORDS)
            require(BASE.identity(intent_info) == entry["intent_identity"]
                    and intent == json.dumps(derivation, sort_keys=True).encode("ascii")
                    and derivation["kind"] == "DERIVEDINPUT"
                    and derivation["derived_sha256"] == POLICY.digest(raw)
                    and derivation["original_cli_stdout"] is False
                    and derivation["native_acquisition"] is False, "retention")
        self.custody()
        self.check()
        return entry["path"]

    def retain(self, name, raw, category="metadata"):
        self.check()
        require(category in ("metadata", "archive", "metadata-native-input"), "unsafe-input")
        if category == "metadata":
            return self.retain_metadata(name, raw)
        native_input = category == "metadata-native-input"
        if native_input:
            require(name == "selected-signature" and name not in self.originals
                    and name not in self.metadata_native_inputs
                    and type(raw) is bytes and 0 < len(raw) <= 1048576, "unsafe-input")
            require(len(self.metadata_frames) + len(self.metadata_native_inputs) < 256,
                    "file-budget")
        limit = 67108864 if native_input else 268435456
        attribute = "metadata_bytes" if native_input else "archive_bytes"
        require(getattr(self, attribute) + len(raw) <= limit, "file-budget")
        setattr(self, attribute, getattr(self, attribute) + len(raw))
        if native_input:
            self.metadata_native_inputs[name] = {"dirty": True}
        path = str(self.directory / (name + ".original.private"))
        with self.original(name + ".original.private") as stream:
            binding = (path, BASE.original_identity(os.fstat(stream.fileno())))
            BASE.verify_original(stream, *binding)
            for offset in range(0, len(raw), 65536):
                self.check()
                self.custody()
                BASE.verify_original(stream, *binding)
                chunk = raw[offset:offset + 65536]
                require(stream.write(chunk) == len(chunk), "retention")
                BASE.verify_original(stream, *binding)
                self.check()
            stream.flush()
            self.check()
            os.fsync(stream.fileno())
            BASE.verify_original(stream, *binding)
            self.custody()
            self.check()
        self.write(name + ".sha256.private", POLICY.digest(raw).encode("ascii"))
        _, info, retained = self.read(path, os.geteuid(), len(raw))
        require(retained == raw and BASE.original_identity(info) == binding[1]
                and os.path.realpath(path) == path, "retention")
        self.check()
        self.originals[name] = retained
        if native_input:
            self.metadata_native_inputs[name] = {
                "dirty": False, "path": path, "identity": BASE.identity(info),
                "sha256": POLICY.digest(retained), "bytes": len(retained)}
            self.verify_metadata_native_input(name)
        return retained

    def pin_sources(self):
        result = {}
        for name in SOURCES:
            path = str(Path(__file__).with_name(name).resolve())
            _, info, raw = self.read(path, os.geteuid(), BASE.FILE_LIMIT)
            require(os.path.abspath(path) == path, "tool-link")
            result[name] = {"sha256": POLICY.digest(raw), "identity": BASE.identity(info)}
            self.retain("source-" + str(len(result)), raw)
        self.receipt["producer_sources"] = result
        self.write("producer-source-ledger.private.json", json.dumps(result, sort_keys=True).encode())

    def verify_sources(self):
        self.verify_future_aliases()
        require(set(self.receipt.get("producer_sources", {})) == set(SOURCES), "tool-link")
        for name, before in self.receipt["producer_sources"].items():
            _, info, raw = self.read(str(Path(__file__).with_name(name)),
                                    os.geteuid(), BASE.FILE_LIMIT)
            require(BASE.identity(info) == before["identity"]
                    and POLICY.digest(raw) == before["sha256"], "tool-link")

    def admit_capture(self):
        self.verify_future_aliases()
        require(not self.derived_input_pending, "retention")
        for name in self.metadata_native_inputs:
            self.verify_metadata_native_input(name)

    def native(self, operation, argv, accepted=(0,)):
        self.admit_capture()
        record, stdout, stderr = self.capture(operation, tuple(argv))
        require(set(("exit", "failure", "retention_failed", "stdout_eof", "stderr_eof"))
                <= set(record), "native-command")
        require(type(record["exit"]) is int and record["exit"] in accepted
                and record["stdout_eof"] is True and record["stderr_eof"] is True
                and record["failure"] is None and record["retention_failed"] is False,
                "native-command")
        self.check()
        self.verify_future_aliases()
        return stdout, stderr, record

    def command(self, operation, argument):
        if operation not in ("owner", "package", "elf"):
            return super().command(operation, argument)
        self.check()
        if operation == "owner":
            require(argument in self.files or (
                argument.startswith(("/lib/", "/bin/"))
                and os.path.realpath(argument) in self.files), "unsafe-input")
            argv = ("/usr/bin/dpkg-query", "-S", argument)
        elif operation == "package":
            require(re.fullmatch(r"[a-z0-9][a-z0-9+.-]{0,127}(?::[a-z0-9-]+)?",
                                 argument) is not None, "unsafe-input")
            argv = ("/usr/bin/dpkg-query", "-W",
                    "-f=${binary:Package}\t${Version}\t${Architecture}\t${source:Package}\t${source:Version}\n",
                    argument)
        else:
            require(argument in self.files and self.files[argument]["elf"], "unsafe-input")
            argv = ("/usr/bin/readelf", "-l", "-d", argument)
        self.query_ticket = (operation, argv)
        record, stdout, stderr = self.capture(operation, argv)
        BASE.validate_native_result(record, stderr, operation == "owner")
        require(type(record["exit"]) is int
                and record["exit"] in ((0, 1) if operation == "owner" else (0,))
                and record["stdout_eof"] is True and record["stderr_eof"] is True
                and record["failure"] is None and record["retention_failed"] is False,
                "native-command")
        require(operation != "owner" or record["exit"] != 1 or not stdout, "tool-origin")
        self.check()
        return stdout

    def protected(self, path, limit=1048576):
        require(os.path.realpath(path) == path, "tool-link")
        GUARD.no_capabilities(path)
        canonical, info, pinned = self.acquire(path, limit=limit)
        actual, current, raw = self.read(canonical, limit=limit)
        require(actual == canonical and BASE.identity(current) == BASE.identity(info)
                and raw == pinned, "tool-link")
        self.retain("protected-" + str(len(self.originals)), raw)
        return raw

    def conffile(self, path):
        raw = self.protected(path)
        if path == "/etc/ca-certificates.conf":
            helper = self.bound_file("/usr/sbin/update-ca-certificates", "ca-certificates")
            generation = CA.verify_generation(self, raw, helper, POLICY, TRUST)
            self.default_configuration[path] = {
                "kind": "generated-package-ca-selection", "package": helper["package"][0],
                "manifest_sha256": helper["manifest_sha256"], "sha256": POLICY.digest(raw),
                "text": raw.decode("utf-8"), "generation": generation}
            return raw
        # Configuration is admitted only if the actual bytes match the installed
        # package's conffile record. Unreadable/locally altered policy is not inferred.
        matches = []
        for row in self.installed_records:
            for line in row.get("Conffiles", "").splitlines():
                fields = line.split()
                if len(fields) == 2 and fields[0] == path:
                    matches.append((fields[1], row["Package"]))
        require(len(matches) == 1 and matches[0][0] == hashlib.md5(raw).hexdigest(), "tool-origin")
        self.default_configuration[path] = {
            "package": matches[0][1], "sha256": POLICY.digest(raw),
            "text": raw.decode("utf-8")}
        return raw

    def prime_ca_owners(self, CA):
        raw = self.conffile(CA.CONFIG)
        selected, disabled = CA._configuration(self, raw)
        paths = tuple(CA.SHARE + "/" + name for name in selected + disabled)
        for path in paths:
            require(self.system(path) == path, "tool-link")
        for start in range(0, len(paths), 32):
            batch = paths[start:start + 32]
            self.ca_owner_ticket = ("/usr/bin/dpkg-query", "-S", *batch)
            stdout, stderr, _ = self.native("ca-owner-batch", self.ca_owner_ticket)
            require(not stderr, "tool-origin")
            observed = {}
            for line in stdout.decode("utf-8").splitlines():
                require(": " in line, "tool-origin")
                package, path = line.split(": ", 1)
                require(package == "ca-certificates" and path in batch
                        and path not in observed and os.path.realpath(path) == path, "tool-origin")
                observed[path] = package
            require(set(observed) == set(batch), "tool-origin")
            self.ca_owners.update(observed)
            self.check()

    def checked_origin(self, path):
        canonical = self.system(path)
        if canonical in self.origins:
            before = self.origins[canonical]
            manifest = before["manifest"]
            actual, info, raw = self.read(manifest, limit=4194304)
            require(actual == manifest and os.path.realpath(manifest) == manifest
                    and BASE.identity(info) == before["manifest_identity"]
                    and POLICY.digest(raw) == before["manifest_sha256"], "tool-origin")
            package = before["package"][0]
            require(package in self.packages, "tool-origin")
            metadata, cached_path, cached_info, cached_raw = self.packages[package]
            require(list(metadata) == list(before["package"])
                    and cached_path == manifest and BASE.identity(cached_info) == BASE.identity(info)
                    and cached_raw == raw, "tool-origin")
            self.check()
            return
        helper = "/usr/sbin/update-ca-certificates"
        if path not in self.ca_owners and path != helper:
            return super().checked_origin(path)
        require(canonical == path, "tool-origin")
        package = "ca-certificates"
        manifest = "/var/lib/dpkg/info/ca-certificates.md5sums"
        if path == helper:
            owner = self.command("owner", canonical)
            require(owner == ("ca-certificates: " + canonical + "\n").encode("ascii"),
                    "tool-origin")
        else:
            require(self.ca_owners[canonical] == package and package in self.packages,
                    "tool-origin")
        if package not in self.packages:
            metadata = self.command("package", package).decode("ascii").strip().split("\t")
            require(len(metadata) == 5 and all(re.fullmatch(r"[A-Za-z0-9.:+~_-]{1,128}", field)
                                              for field in metadata), "tool-origin")
            actual, info, raw = self.read(manifest, limit=4194304)
            self.package_bytes += len(raw)
            require(self.package_bytes <= BASE.RECEIPT_LIMIT, "file-budget")
            self.packages[package] = (metadata, actual, info, raw)
        metadata, actual, info, raw = self.packages[package]
        require(metadata[0] == package and metadata[3] == package
                and actual == manifest and os.path.realpath(actual) == actual
                and BASE.identity(os.lstat(actual)) == BASE.identity(info), "tool-origin")
        original_name = (CA.SHARE + "/" + CA.NETLOCK_ANCHOR).lstrip("/")
        surrogate = "usr/share/ca-certificates/mozilla/wstm-netlock-manifest-projection.crt"
        require(surrogate.encode("ascii") not in raw and surrogate not in canonical, "tool-origin")
        # Preserve original manifest bytes and digests; project only this exact
        # package path into the unchanged ASCII manifest checker.
        projected = raw.replace(original_name.encode("utf-8"), surrogate.encode("ascii"))
        projected_path = "/" + surrogate if canonical == "/" + original_name else canonical
        BASE.verify_manifest(projected, projected_path, self.files[canonical]["md5"])
        self.origins[canonical] = {
            "package": list(metadata), "manifest": actual,
            "manifest_identity": BASE.identity(info), "manifest_sha256": POLICY.digest(raw),
            "installed_digest_matches": True, "source_archive_sha256": None}
        self.check()

    def preflight(self):
        self._preflight_context = ("source-pins", None)
        self.receipt["preflight_refusal"] = None
        try:
            self._preflight()
        except BASE.Refusal as error:
            if str(error) == "tool-identity":
                step, tool = self._preflight_context
                self.receipt["preflight_refusal"] = {
                    "step": step, "requested_tool": tool,
                    "identity_check": getattr(error, "identity_check", None)}
            raise

    def _preflight(self):
        self.pin_sources()
        self._preflight_context = ("package-status", None)
        status = self.protected("/var/lib/dpkg/status", 16777216)
        self.installed_records = POLICY.deb822(status)
        self.installed = {}
        for row in self.installed_records:
            if row.get("Status") == "install ok installed":
                require(row["Package"] not in self.installed, "inventory-shape")
                self.installed[row["Package"]] = row["Version"]
        self._preflight_context = ("platform", None)
        _, _, os_release = self.acquire("/usr/lib/os-release", limit=65536)
        require(b"ID=ubuntu\n" in os_release and b'VERSION_ID="24.04"\n' in os_release,
                "platform")
        for tool in TOOLS:
            self._preflight_context = ("tool-file", TOOL_IDS[tool])
            self.system(tool)
        for tool in TOOLS:
            self._preflight_context = ("tool-origin", TOOL_IDS[tool])
            self.origin(tool)
            self.dependencies(tool)
        self._preflight_context = ("sudo-policy", None)
        self.pin_sudo_configuration()
        self._preflight_context = ("python-module", None)
        for module in tuple(sys.modules.values()):
            path = getattr(module, "__file__", None)
            if path and os.path.realpath(path).startswith(("/usr/lib/python", "/usr/lib/x86_64-linux-gnu/")):
                self.origin(path)
                self.dependencies(path)
        # Reuse the historical protected sudo-policy boundary, including the
        # image-generated runner rule; it is not an APT package-default claim.
        self._preflight_context = ("sudo-policy", None)
        self.protected("/etc/sudoers")
        for path in sorted(Path("/etc/sudoers.d").iterdir()):
            require(path.is_file() and not path.is_symlink(), "tool-link")
            self.protected(str(path))
        self._preflight_context = ("apt-credentials", None)
        authfiles = [Path("/etc/apt/auth.conf")]
        if Path("/etc/apt/auth.conf.d").exists():
            authfiles.extend(sorted(Path("/etc/apt/auth.conf.d").iterdir()))
        for path in authfiles:
            if os.path.lexists(path):
                require(not self.protected(str(path)).strip(), "tool-loader")
        self._preflight_context = ("apt-configuration", None)
        for path in sorted(Path("/etc/apt/apt.conf.d").iterdir()):
            require(path.is_file() and not path.is_symlink(), "tool-link")
            self.conffile(str(path))
        if os.path.lexists("/etc/apt/apt.conf"):
            self.conffile("/etc/apt/apt.conf")
        if os.path.lexists("/etc/ucf.conf"):
            self.conffile("/etc/ucf.conf")
        self._preflight_context = ("loader", None)
        for path in ("/etc/ld.so.conf", "/etc/ca-certificates.conf"):
            self.conffile(path)
        for path in sorted(Path("/etc/ld.so.conf.d").iterdir()):
            self.conffile(str(path))
        TRUST.verify_loader(self)
        self._preflight_context = ("apt-method", None)
        for method in ("/usr/lib/apt/methods/http", "/usr/lib/apt/methods/https",
                       "/usr/lib/apt/methods/gpgv", "/usr/lib/apt/methods/store"):
            self.origin(method)
            self.dependencies(method)
        self._preflight_context = ("ca-trust", None)
        self.prime_ca_owners(CA)
        self.receipt["ca_trust"] = CA.verify_ca(self)
        self._preflight_context = ("keyring", None)
        self.system("/usr/share/keyrings/ubuntu-archive-keyring.gpg")
        self.origin("/usr/share/keyrings/ubuntu-archive-keyring.gpg")
        self._preflight_context = ("install-paths", None)
        for path in (POLICY.ROOT, POLICY.KEYRING, POLICY.SOURCE):
            require(not os.path.lexists(path), "tool-link")
            BASE.parent_pins(path)
        self._preflight_context = ("dpkg-state", None)
        for path in ("/var/lib/dpkg/diversions", "/var/lib/dpkg/statoverride"):
            if os.path.lexists(path):
                raw = self.protected(path, 1048576)
                require(not raw.strip(), "tool-loader")
        self._preflight_context = ("apt-hooks", None)
        config, stderr, _ = self.native("apt-config", ("/usr/bin/apt-config", "dump"))
        require(not stderr, "tool-loader")
        TRUST.verify_hooks(self, config, self.default_configuration)
        self.receipt["effective_apt_config_sha256"] = POLICY.digest(config)
        for entry in self.origins.values():
            self.protected_packages.add(entry["package"][0].split(":")[0])
        self._preflight_context = ("source-recheck", None)
        self.verify_sources()
        self.setup_ready = True

    def root(self, operation):
        self.admit_capture()
        require(self.setup_ready, "unsafe-input")
        self.verify_sources()
        for path in tuple(self.files):
            self.system(path)
        if operation == "apt-install":
            require(self.transaction_active()
                    and self._transaction["lifecycle_verified"] is self._transaction["nonce"],
                    "tool-origin")
        result = super().root(operation)
        self.verify_future_aliases()
        changed_parents = {
            "directories": {"/var/cache"},
            "key-install": {"/usr/share/keyrings"},
            "source-descriptor": {"/etc/apt/sources.list.d"},
        }.get(operation, set())
        if changed_parents:
            for path, pin in self.pins.items():
                current = BASE.parent_pins(path)
                for parent, before in pin["parents"].items():
                    require(parent in current, "tool-parent")
                    keys = ("dev", "ino", "uid", "gid", "mode") if parent in changed_parents else tuple(before)
                    require(all(current[parent][key] == before[key] for key in keys), "tool-parent")
                pin["parents"] = current
                if path in self.files:
                    self.files[path]["parents"] = current
        return result

    def bootstrap(self):
        self.stage = "bootstrap"
        for operation in POLICY.OPERATIONS[:3]:
            self.root(operation)
        ascii_key = self.protected(POLICY.ROOT + "/publisher-key.asc", 1048576)
        require(stat.S_IMODE(os.lstat(POLICY.ROOT + "/publisher-key.asc").st_mode) == 0o644,
                "tool-identity")
        require(len(ascii_key) == 1688 and POLICY.digest(ascii_key) == POLICY.KEY_SHA256,
                "tool-origin")
        self.root("key-dearmor")
        key = self.protected(POLICY.ROOT + "/publisher-key.gpg", 1048576)
        require(stat.S_IMODE(os.lstat(POLICY.ROOT + "/publisher-key.gpg").st_mode) == 0o644,
                "tool-identity")
        require(len(key) == 1154 and POLICY.digest(key) == POLICY.KEYRING_SHA256, "tool-origin")
        home = self.directory / "key-inspection"
        home.mkdir(mode=0o700)
        stdout, _, _ = self.native("key-inspection", (
            "/usr/bin/gpg", "--no-options", "--homedir", str(home), "--batch",
            "--with-colons", "--import-options", "show-only", "--import",
            POLICY.ROOT + "/publisher-key.asc"))
        fields = [line.split(b":") for line in stdout.splitlines()]
        primaries = [row for row in fields if row[0] == b"pub"]
        fingerprints = [row[9] for row in fields if row[0] == b"fpr" and len(row) > 9]
        require(len(primaries) == 1 and fingerprints == [POLICY.FINGERPRINT.encode("ascii")]
                and len(primaries[0]) > 6, "tool-origin")
        expires = primaries[0][6]
        require(expires == b"" or re.fullmatch(rb"[0-9]{1,12}", expires) is not None,
                "tool-origin")
        require(expires == b"" or int(expires) == 0 or int(expires) > time.time(),
                "tool-origin")
        self.root("key-install")
        require(self.protected(POLICY.KEYRING) == key, "tool-origin")
        self.root("source-descriptor")
        require(stat.S_IMODE(os.lstat(POLICY.SOURCE).st_mode) == 0o644, "tool-identity")
        require(self.protected(POLICY.SOURCE) == POLICY.DESCRIPTOR, "tool-origin")
        self.root("apt-update")
        self.receipt["apt_original_accepted"] = True
        self.verify_sources()

    def cache_release(self, repository):
        uri = REPOSITORIES[repository]
        suite = "noble" if repository == "ppa" else repository
        host = uri.removeprefix("https://").replace("/", "_")
        path = POLICY.ROOT + "/lists/" + host + "_dists_" + suite + "_InRelease"
        raw = self.protected(path, 1048576)
        key = POLICY.KEYRING if repository == "ppa" else "/usr/share/keyrings/ubuntu-archive-keyring.gpg"
        home = self.directory / ("verify-" + repository)
        home.mkdir(mode=0o700)
        argv = ("/usr/bin/gpgv", "--homedir", str(home), "--status-fd", "1", "--keyring", key, path)
        stdout, _, record = self.native("signature-original", argv,
                                       (0, 2) if repository == "ppa" else (0,))
        if repository == "ppa":
            self.receipt["original_signature_exit"] = record["exit"]
            self.receipt["original_signature_verified"] = record["exit"] == 0
            require(not any(x in stdout for x in (b"BADSIG", b"EXPSIG", b"EXPKEYSIG", b"REVKEYSIG",
                                                  b"SIGEXPIRED", b"KEYEXPIRED")), "tool-origin")
            derived, prefix, packet, count = SIGNATURE.derive(raw, POLICY.FINGERPRINT)
            derivation = {
                "schema": "derived-approved-signature-input-v1", "kind": "DERIVEDINPUT",
                "source_path": path, "source_sha256": POLICY.digest(raw),
                "signed_prefix_sha256": POLICY.digest(prefix),
                "copied_signature_sha256": POLICY.digest(packet),
                "derived_sha256": POLICY.digest(derived), "signature_packets": count,
                "original_cli_stdout": False, "native_acquisition": False,
            }
            self.derived_input_pending = True
            self.write("selected-signature.intent.private.json",
                       json.dumps(derivation, sort_keys=True).encode("ascii"))
            _, intent_info, intent = self.read(
                str(self.directory / "selected-signature.intent.private.json"),
                os.geteuid(), CAPTURE.RECORDS)
            require(intent == json.dumps(derivation, sort_keys=True).encode("ascii"), "retention")
            self.retain("selected-signature", derived, "metadata-native-input")
            self.metadata_native_inputs["selected-signature"]["derivation"] = derivation
            self.metadata_native_inputs["selected-signature"]["intent_identity"] = BASE.identity(intent_info)
            derived_path = self.verify_metadata_native_input("selected-signature")
            self.derived_input_pending = False
            verified, _, _ = self.native("signature-selected", argv[:-1] + (str(derived_path),))
            self.verify_metadata_native_input("selected-signature")
            require(self.protected(path, 1048576) == raw, "tool-origin")
            valid = signature_status(verified, (POLICY.FINGERPRINT,))
            require(len(valid) == 1 and valid[0][2] == POLICY.FINGERPRINT
                    and valid[0][-1] == POLICY.FINGERPRINT, "tool-origin")
            self.receipt.update(selected_signature_verified=True, signature_packets=count,
                                signed_prefix_sha256=POLICY.digest(prefix),
                                copied_signature_sha256=POLICY.digest(packet))
        else:
            signature_status(stdout, (
                "F6ECB3762474EDA9D21B7022871920D1991BC93C",
                "790BC7277767219C42C86F933B4FE6ACC0B21F32"))
        return POLICY.release(raw, repository, time.time())

    def fetch(self, repository, filename, expected, label, category="metadata"):
        require(repository in REPOSITORIES and re.fullmatch("[A-Za-z0-9._+~/-]+", filename)
                and not filename.startswith("/") and ".." not in filename.split("/"), "unsafe-input")
        require(set(expected) == {"sha256", "size"} and POLICY.DIGEST.fullmatch(expected["sha256"])
                and type(expected["size"]) is int and 0 < expected["size"] <= 268435456,
                "inventory-shape")
        destination = self.directory / (label + ".download.private")
        require(not destination.exists(), "custody")
        _, _, _ = self.native("metadata-fetch" if category == "metadata" else "source-artifact-fetch", (
            "/usr/bin/curl", "--disable", "--fail", "--silent", "--show-error", "--proto",
            "=https", "--tlsv1.2", "--max-time", "60", "--max-filesize", str(expected["size"]),
            "--output", str(destination), REPOSITORIES[repository] + "/" + filename))
        _, _, raw = self.read(str(destination), os.geteuid(), expected["size"])
        require(len(raw) == expected["size"] and POLICY.digest(raw) == expected["sha256"],
                "tool-origin")
        return self.retain(label, raw, category)

    def index(self, repository, component, kind, releases):
        require(repository in REPOSITORIES and component in ("main", "universe")
                and kind in ("binary", "source"), "unsafe-input")
        suite = "noble" if repository == "ppa" else repository
        uncompressed = component + ("/binary-amd64/Packages" if kind == "binary" else "/source/Sources")
        checksums = releases[repository][1]
        require(uncompressed in checksums and checksums[uncompressed]["size"] > 0,
                "inventory-shape")
        suffix = next((uncompressed + extension for extension in (".gz", ".xz")
                       if uncompressed + extension in checksums
                       and checksums[uncompressed + extension]["size"] > 0), None)
        require(suffix is not None, "inventory-shape")
        checksum = checksums[suffix]
        label = repository + "-" + component + "-" + kind
        raw = self.fetch(repository, "dists/" + suite + "/" + suffix, checksum, label)
        decoded = bytearray()
        try:
            if suffix.endswith(".gz"):
                decoder = zlib.decompressobj(16 + zlib.MAX_WBITS)
                offset, pending = 0, b""
                while offset < len(raw) or pending:
                    self.check()
                    chunk = pending or raw[offset:offset + 65536]
                    if not pending:
                        offset += len(chunk)
                    decoded.extend(decoder.decompress(chunk, 67108865 - len(decoded)))
                    pending = decoder.unconsumed_tail
                    require(len(decoded) <= 67108864, "file-budget")
            else:
                decoder = lzma.LZMADecompressor(memlimit=67108864)
                offset = 0
                while offset < len(raw):
                    self.check()
                    decoded.extend(decoder.decompress(raw[offset:offset + 65536],
                                                       max_length=67108865 - len(decoded)))
                    offset += 65536
                    require(len(decoded) <= 67108864, "file-budget")
                while not decoder.needs_input and not decoder.eof:
                    self.check()
                    decoded.extend(decoder.decompress(b"", max_length=67108865 - len(decoded)))
                    require(len(decoded) <= 67108864, "file-budget")
        except (lzma.LZMAError, zlib.error):
            require(False, "inventory-shape")
        require(decoder.eof and not decoder.unused_data, "inventory-shape")
        require(len(decoded) == checksums[uncompressed]["size"]
                and POLICY.digest(decoded) == checksums[uncompressed]["sha256"],
                "tool-origin")
        return POLICY.deb822(bytes(decoded)), POLICY.digest(decoded)

    def compile_transaction(self):
        self.stage = "transaction"
        releases = {name: self.cache_release(name) for name in REPOSITORIES}
        suffixes = ("cli", "common", "opcache", "readline", "zip") if self.version == "8.2" else (
            "cli", "common", "opcache", "readline")
        wanted = {"php" + self.version + "-" + suffix: POLICY.VERSIONS[self.version]
                  for suffix in suffixes}
        common_min = "1:81~" if self.version == "8.2" else "2:95~"
        if POLICY.compare_versions(self.installed.get("php-common", "0"), common_min) < 0:
            wanted["php-common"] = "2:101~+ubuntu24.04.1+deb.sury.org+1"
        tokens = tuple(name + "=" + version for name, version in sorted(wanted.items()))
        simulation, stderr, _ = self.native("apt-simulation", ("/usr/bin/apt-get",)
                                             + POLICY.AUTH + POLICY.SOURCES
                                             + ("--simulate", "--no-install-recommends",
                                                "--no-remove", "install") + tokens)
        require(not stderr, "native-command")
        names = set(re.findall(rb"^Inst ([a-z0-9+.-]+)", simulation, re.M))
        require(1 <= len(names) <= 64, "file-budget")
        names = {name.decode("ascii") for name in names}
        observed_versions = {}
        for line in simulation.decode("ascii").splitlines():
            if line.startswith("Inst "):
                match = re.fullmatch(
                    r"Inst ([a-z0-9+.-]+)(?::amd64)?(?: \[[^]]+\])? "
                    r"\(([A-Za-z0-9.+:~_-]+) .+ \[(?:amd64|all)\]\)", line)
                require(match is not None and match[1] not in observed_versions, "inventory-shape")
                observed_versions[match[1]] = match[2]
        authenticated, binary_records, source_records = {}, {}, {}
        for repository in REPOSITORIES:
            for component in (("main",) if repository == "ppa" else ("main", "universe")):
                if set(authenticated) == names:
                    break
                binaries, binary_sha = self.index(repository, component, "binary", releases)
                selected = [row for row in binaries if row.get("Package") in names
                            and row.get("Version") == observed_versions[row["Package"]]
                            and row.get("Architecture") in ("amd64", "all")]
                if not selected:
                    continue
                sources, source_sha = self.index(repository, component, "source", releases)
                for binary in selected:
                    matches = []
                    for source in sources:
                        try:
                            row = POLICY.source_binding(binary, source, binary_sha, source_sha, repository)
                        except (POLICY.PolicyError, KeyError, ValueError):
                            continue
                        matches.append((row, source))
                    require(len(matches) == 1 and binary["Package"] not in authenticated, "tool-origin")
                    row, source = matches[0]
                    authenticated[binary["Package"]] = row
                    binary_records[binary["Package"]] = binary
                    source_records[(repository, source["Package"], source["Version"])] = source
        transacted_wanted = {name: version for name, version in wanted.items()
                             if name in binary_records}
        necessary = POLICY.necessary_closure(transacted_wanted, binary_records, self.installed)
        self.lock = POLICY.simulation(simulation, wanted, authenticated,
                                      self.installed, self.protected_packages, necessary)
        require(set(row["package"] for row in self.lock) == names, "inventory-shape")
        self._authenticated_lock = POLICY.digest(json.dumps(self.lock, sort_keys=True).encode())
        # Source artifacts are verified data; nothing downloaded here is executed.
        for (repository, _, _), source in source_records.items():
            require(re.fullmatch(r"pool/[A-Za-z0-9+./_-]+", source["Directory"])
                    and ".." not in source["Directory"].split("/"), "inventory-shape")
            for filename, expected in POLICY.checksums(source["Checksums-Sha256"]).items():
                self.fetch(repository, source["Directory"] + "/" + filename, expected,
                           "source-artifact-" + str(len(self.originals)), "archive")
        self.write("actual-lock.private.json", json.dumps(self.lock, sort_keys=True).encode())
        self.verify_sources()
        self.root("apt-download")
        self.archives = {}
        self.extractions = {}
        self.extracted_pins = {}
        self.extracted_directory_pins = {}
        self.archive_pins = {}
        all_paths = []
        installed_size = 0
        cache = {}
        for path in sorted(Path(POLICY.ROOT + "/archives").glob("*.deb")):
            require(len(cache) < 64, "file-budget")
            _, info, raw = self.read(str(path), limit=268435456)
            sha = POLICY.digest(raw)
            require(sha not in cache, "tool-origin")
            GUARD.no_capabilities(str(path))
            self.retain("archive-original-" + str(len(cache)), raw, "archive")
            cache[sha] = (path, len(raw), BASE.identity(info))
        require(set(cache) == {row["sha256"] for row in self.lock}, "tool-origin")
        for row in self.lock:
            path, size, identity = cache[row["sha256"]]
            require(size == row["size"] and BASE.identity(os.lstat(path)) == identity, "tool-link")
            self.archive_pins[str(path)] = identity
            stdout, _, _ = self.native("deb-control", ("/usr/bin/dpkg-deb", "--ctrl-tarfile", str(path)))
            with tarfile.open(fileobj=io.BytesIO(stdout), mode="r:") as archive:
                controls = {}
                for member in archive:
                    name = member.name.removeprefix("./")
                    if name in ("", "."):
                        require(member.isdir(), "inventory-shape")
                        continue
                    require(member.isfile() and name in CONTROL_NAMES and name not in controls
                            and 0 <= member.size <= CAPTURE.STDOUT, "inventory-shape")
                    controls[name] = archive.extractfile(member).read()
            control = POLICY.deb822(controls["control"])[0]
            require(control["Package"] == row["package"] and control["Version"] == row["version"]
                    and control["Architecture"] == row["architecture"], "tool-origin")
            installed_size += int(control["Installed-Size"]) * 1024
            require(installed_size <= 1073741824, "file-budget")
            self.archives[row["package"]] = (path, row["sha256"], controls)
            listing, _, _ = self.native("deb-list", ("/usr/bin/dpkg-deb", "--contents", str(path)))
            declared_paths = POLICY.archive_listing(listing)
            if row["package"] == "php-common":
                self.retain("php-common-archive-listing", listing)
            extraction = self.directory / ("package-" + str(len(self.archives)))
            extraction.mkdir(mode=0o700)
            self.native("deb-extract", ("/usr/bin/dpkg-deb", "--extract", str(path), str(extraction)))
            require(BASE.identity(os.lstat(path)) == identity, "tool-link")
            self.extractions[row["package"]] = extraction
            self.extracted_directory_pins[str(extraction)] = BASE.identity(os.lstat(extraction))
            observed_paths = []
            for item in extraction.rglob("*"):
                require(len(all_paths) < 8192, "file-budget")
                relative = "/" + item.relative_to(extraction).as_posix()
                require(not ".." in relative.split("/"), "inventory-shape")
                all_paths.append(relative)
                observed_paths.append(relative)
                if item.is_file() and not item.is_symlink():
                    _, info, body = self.read(str(item), os.geteuid(), BASE.FILE_LIMIT)
                    self.extracted_pins[str(item)] = {
                        "identity": BASE.identity(info), "sha256": POLICY.digest(body)}
                elif item.is_dir() and not item.is_symlink():
                    self.extracted_directory_pins[str(item)] = BASE.identity(os.lstat(item))
            require(set(observed_paths) == set(declared_paths), "tool-link")
        self.write("extracted-source-ledger.private.json",
                   json.dumps(self.extracted_pins, sort_keys=True).encode())
        self.write("accepted-control-ledger.private.json", json.dumps({
            name: {key: POLICY.digest(value) for key, value in item[2].items()}
            for name, item in self.archives.items()}, sort_keys=True).encode())
        self.observe_session_verification()
        selected_versions = set(self.receipt["session_verification"]["before"]["versions"])
        for record in self.receipt["session_verification"]["planned"].values():
            selected_versions.update(record["versions"])
        self.snapshot_php_state(selected_versions)
        self.close_transaction()
        with self.transaction_admission("lifecycle"):
            TRUST.verify_lifecycle(self, {name: item[2] for name, item in self.archives.items()},
                                   all_paths)
            for name, extraction in self.extractions.items():
                for path in TRUST.INSTALL_HELPERS:
                    candidate = extraction / path.removeprefix("/")
                    if candidate.is_file() and not candidate.is_symlink():
                        raw = self.extracted_file(candidate)
                        require(raw.startswith(b"#!") or raw.startswith(b"\x7fELF")
                                or path in TRUST.SUPPORT_FILES, "tool-loader")
                        if raw.startswith(b"#!"):
                            TRUST.script_dependencies(self, raw)
                        self.receipt.setdefault("authenticated_future_helpers", {})[path] = {
                            "package": name, "sha256": POLICY.digest(raw),
                            "authority_role": "transaction-authorized-prospective-code"}
        self.verify_sources()

    def refresh_install_parents(self):
        require(self._transaction is not None and self._transaction["consumed"]
                and self.root_cursor == POLICY.OPERATIONS.index("select-php"), "tool-origin")
        # Only the successful authenticated installation interval may change
        # directory timestamps and authenticated incoming helper replacements.
        # Unchanged tools/aliases and directory custody remain immutable.
        for path, entry in self.files.items():
            owner = self.origins[path]["package"][0].split(":")[0]
            if owner in {row["package"] for row in self.lock}:
                original = dict(entry)
                original_aliases = dict(self.pins[path]["aliases"])
                parents = BASE.parent_pins(path)
                require(set(parents) == set(original["parents"])
                        and all(all(parents[parent][key] == before[key]
                                    for key in ("dev", "ino", "uid", "gid", "mode"))
                                for parent, before in original["parents"].items()), "tool-parent")
                self.pins.pop(path)
                self._pin_bytes.pop(path)
                self._retired_pins += 1
                self.acquire(path)
                current = self.authenticated_file(path)
                info = os.lstat(path)
                require(stat.S_ISREG(info.st_mode) and os.path.realpath(path) == path, "tool-link")
                aliases = {}
                for alias, before in original_aliases.items():
                    require(os.path.realpath(alias) == path, "tool-link")
                    observed = BASE.identity(os.lstat(alias))
                    require(alias == path or observed == before, "tool-link")
                    aliases[alias] = observed
                entry.update(identity=BASE.identity(info), sha256=POLICY.digest(current),
                             md5=hashlib.md5(current).hexdigest(), parents=BASE.parent_pins(path),
                             elf=current.startswith(b"\x7fELF"), header64_hex=current[:64].hex(),
                             aliases=aliases)
                self.receipt.setdefault("authorized_helper_replacements", {})[path] = {
                    "before_sha256": original["sha256"], "after_sha256": entry["sha256"],
                    "before_identity": original["identity"], "after_identity": entry["identity"],
                    "after_aliases": aliases, "package": owner}
                self.pins[path].update(identity=entry["identity"], parents=entry["parents"],
                                      sha256=entry["sha256"], aliases=aliases)
                self._pin_bytes[path] = current
                continue
            require(BASE.identity(os.lstat(path)) == entry["identity"], "tool-link")
            _, _, raw = self.read(path, limit=BASE.FILE_LIMIT)
            require(POLICY.digest(raw) == entry["sha256"], "tool-link")
            current = BASE.parent_pins(path)
            before = entry["parents"]
            require(set(current) == set(before), "tool-parent")
            for parent in before:
                require(all(before[parent][key] == current[parent][key] for key in
                            ("dev", "ino", "uid", "gid", "mode")), "tool-parent")
            for alias, identity in entry["aliases"].items():
                require(BASE.identity(os.lstat(alias)) == identity, "tool-link")
            entry["parents"] = current
            self.pins[path]["parents"] = current
        for path, pin in self.session_data_pins.items():
            if (pin["kind"] != "directory-data"
                    or not (path == "/usr/lib/php" or path.startswith("/etc/php/"))):
                continue
            require(os.path.realpath(path) == path, "tool-parent")
            current = BASE.identity(os.lstat(path))
            parents = BASE.parent_pins(str(Path(path) / "session-data-placeholder"))
            require(set(parents) == set(pin["parents"])
                    and all(current[key] == pin["identity"][key]
                            for key in ("dev", "ino", "uid", "gid", "mode"))
                    and all(all(parents[parent][key] == old[key]
                                for key in ("dev", "ino", "uid", "gid", "mode"))
                            for parent, old in pin["parents"].items()), "tool-parent")
            self.receipt.setdefault("pre_install_directory_pins", {})[path] = dict(pin)
            pin.update(identity=current, parents=parents)
        changed = {row["package"] for row in self.lock}
        for path, pin in self.pins.items():
            if path in self.files:
                continue
            current = BASE.parent_pins(path)
            before = pin["parents"]
            require(set(current) == set(before) and all(
                all(before[parent][key] == current[parent][key]
                    for key in ("dev", "ino", "uid", "gid", "mode"))
                for parent in before), "tool-parent")
            mutable = (path in ("/var/lib/dpkg/status", "/var/lib/dpkg/triggers/File",
                                "/var/lib/ucf/registry", "/var/lib/ucf/hashfile", "/etc/ld.so.cache")
                       or path.startswith("/etc/php/" + self.version + "/")
                       or any(path.startswith("/var/lib/dpkg/info/" + name + ".")
                              or path.startswith("/var/lib/dpkg/info/" + name + ":")
                              for name in changed))
            if not mutable:
                _, info, raw = self.read(path, limit=BASE.FILE_LIMIT)
                require(BASE.identity(info) == pin["identity"]
                        and POLICY.digest(raw) == pin["sha256"], "tool-link")
            pin["parents"] = current

    def snapshot_php_state(self, observed_versions=None):
        state = {}
        configuration_directories = {}
        self.ucf_before = {}
        for path in ("/var/lib/ucf/registry", "/var/lib/ucf/hashfile"):
            if os.path.lexists(path):
                raw = self.protected(path, 4194304)
                self.ucf_before[path] = raw
                self.receipt.setdefault("ucf_before", {})[path] = POLICY.digest(raw)
        versions = sorted(Path("/etc/php").iterdir()) if Path("/etc/php").exists() else []
        for version_dir in versions:
            require(re.fullmatch(r"[0-9]+\.[0-9]+", version_dir.name), "php-config")
            if observed_versions is not None and version_dir.name not in observed_versions:
                continue
            configuration_directories[str(version_dir)] = self.session_directory(version_dir)
            binary = "/usr/bin/php" + version_dir.name
            if self.session_worker_guard(version_dir.name)["active"]:
                self.origin(binary)
                self.dependencies(binary)
            for path in sorted(version_dir.rglob("*")):
                self.check()
                if path.is_dir():
                    configuration_directories[str(path)] = self.session_directory(path)
                    continue
                require(path.name.endswith(".ini") and len(state) < 256, "php-config")
                config_canonical = os.path.realpath(path)
                require(config_canonical.startswith(str(version_dir) + "/"), "php-config")
                raw = self.protected(config_canonical)
                if path.name == "php.ini":
                    template_path = "/usr/lib/php/" + version_dir.name + "/php.ini-production"
                    suffix = "." + path.parent.name
                    if os.path.lexists(template_path + suffix):
                        template_path += suffix
                    template = self.bound_file(template_path)["raw"]
                    require(raw == template, "php-config")
                    target = str(path)
                    require(any(line.split() == ["php" + version_dir.name + "-" + path.parent.name,
                                                 target]
                                for line in self.ucf_before.get("/var/lib/ucf/registry", b"")
                                .decode("ascii").splitlines()), "php-config")
                    require(any(line.split() == [hashlib.md5(raw).hexdigest(), target]
                                for line in self.ucf_before.get("/var/lib/ucf/hashfile", b"")
                                .decode("ascii").splitlines()), "php-config")
                else:
                    candidates = list(Path("/usr/share").glob(
                        "php" + version_dir.name + "-*/*/" + path.name.split("-", 1)[-1]))
                    templates = [self.bound_file(str(item))["raw"] for item in candidates
                                 if item.is_file() and not item.is_symlink()]
                    require(len(templates) == 1 and raw == templates[0], "php-config")
                # This also rejects prepend, append, preload, FFI and unknown
                # module/config directives before a SYSTEM session-clean helper.
                extension = re.findall(rb"(?mi)^\s*extension_dir\s*=\s*[\"']?(/usr/lib/php/[0-9]{8})",
                                       raw)
                require(len(extension) <= 1, "php-config")
                extension_dir = extension[0].decode() if extension else "/usr/lib/php/00000000"
                modules = BASE.configured_modules(raw, extension_dir)
                for module in modules:
                    candidates = []
                    for file in Path("/usr/lib/php").glob("[0-9]" * 8 + "/" + module):
                        module_canonical = self.system(str(file))
                        self.origin(module_canonical)
                        if self.origins[module_canonical]["package"][3] == "php" + version_dir.name:
                            candidates.append(module_canonical)
                    require(len(candidates) == 1, "php-config")
                    self.dependencies(candidates[0])
                state[str(path)] = dict(self.session_installed_binding(str(path), {
                    "canonical": config_canonical,
                    "identity": self.pins[config_canonical]["identity"],
                    "raw": raw, "sha256": POLICY.digest(raw)}),
                    symlink=path.is_symlink())
        sessions = Path("/var/lib/php/sessions")
        if sessions.exists():
            BASE.parent_pins(str(sessions))
            info = os.lstat(sessions)
            require(stat.S_ISDIR(info.st_mode) and info.st_uid == info.st_gid == 0
                    and stat.S_IMODE(info.st_mode) == 0o1733, "php-config")
        self.receipt["all_sapi_before"] = state
        self.receipt["sessionclean_configuration_directories"] = configuration_directories

    def verify_php_scope(self):
        module_names = {candidate.name for extraction in self.extractions.values()
                        for candidate in extraction.glob(
                            "usr/share/php" + self.version + "-*/*/*.ini")}
        for path, entry in self.receipt["all_sapi_before"].items():
            if entry["symlink"] and not os.path.lexists(path):
                require("/conf.d/" in path
                        and Path(path).name.split("-", 1)[-1] in module_names, "php-config")
                continue
            require(os.path.realpath(path) == entry["canonical"], "php-config")
            _, _, raw = self.read(entry["canonical"], limit=1048576)
            if POLICY.digest(raw) != entry["sha256"]:
                require(path.startswith("/etc/php/" + self.version + "/"), "php-config")
                self.check_generated_config(Path(path), raw)
        known_versions = {Path(path).parts[3] for path in self.receipt["all_sapi_before"]}
        known_versions.update(self.receipt["session_verification"]["before"]["versions"])
        known_versions.add(self.version)
        scope = {}
        for version in Path("/etc/php").iterdir():
            require(version.name in known_versions, "php-config")
            for path in version.rglob("*"):
                if path.is_dir() and not path.is_symlink():
                    BASE.parent_pins(str(path) + "/placeholder")
                    continue
                require(path.name.endswith(".ini") and len(scope) < 256, "php-config")
                canonical = os.path.realpath(path)
                require(canonical.startswith(str(version) + "/"), "php-config")
                if path.is_symlink():
                    require("/conf.d/" in str(path)
                            and os.lstat(path).st_uid == 0
                            and canonical.startswith(str(version / "mods-available") + "/"),
                            "php-config")
                _, _, raw = self.read(canonical, limit=1048576)
                if str(path) not in self.receipt["all_sapi_before"]:
                    if version.name == self.version:
                        self.check_generated_config(path, raw)
                    else:
                        require(path.is_symlink() and path.name.split("-", 1)[-1] in module_names
                                and canonical in {row["canonical"] for row in
                                                  self.receipt["all_sapi_before"].values()
                                                  if row["sha256"] == POLICY.digest(raw)},
                                "php-config")
                scope[str(path)] = {"canonical": canonical, "sha256": POLICY.digest(raw)}
        self.write("all-sapi-after.private.json", json.dumps(scope, sort_keys=True).encode())
        for path, before in self.ucf_before.items():
            _, _, after = self.read(path, limit=4194304)
            self.retain("ucf-after-" + str(len(self.originals)), after)
            prefix = "/etc/php/" + self.version + "/"
            preserved = lambda raw: sorted(line for line in raw.splitlines()
                                           if not line.split()
                                           or not line.split()[-1].startswith(prefix.encode()))
            require(preserved(before) == preserved(after), "php-config")
        sessions = Path("/var/lib/php/sessions")
        if sessions.exists():
            BASE.parent_pins(str(sessions))
            info = os.lstat(sessions)
            require(stat.S_ISDIR(info.st_mode) and info.st_uid == info.st_gid == 0
                    and stat.S_IMODE(info.st_mode) == 0o1733, "php-config")
        self.receipt["all_sapi_existing_contents_preserved"] = True

    def check_generated_config(self, path, raw):
        if path.name == "php.ini":
            prefix = "/usr/lib/php/" + self.version + "/php.ini-production"
            suffix = "." + path.parent.name
            candidates = [extraction / (prefix + suffix).removeprefix("/")
                          for extraction in self.extractions.values()
                          if (extraction / (prefix + suffix).removeprefix("/")).is_file()]
            if not candidates:
                candidates = [extraction / prefix.removeprefix("/")
                              for extraction in self.extractions.values()
                              if (extraction / prefix.removeprefix("/")).is_file()]
        else:
            name = path.name.split("-", 1)[-1]
            candidates = [candidate for extraction in self.extractions.values()
                          for candidate in extraction.glob(
                              "usr/share/php" + self.version + "-*/*/" + name)]
        require(len(candidates) == 1 and raw == self.extracted_file(candidates[0]), "php-config")

    def authenticated_file(self, installed):
        candidates = []
        for extraction in self.extractions.values():
            path = extraction / installed.removeprefix("/")
            if path.is_file() and not path.is_symlink():
                raw = self.extracted_file(path)
                candidates.append(raw)
        require(len(candidates) == 1, "tool-origin")
        _, _, actual = self.read(installed, limit=BASE.FILE_LIMIT)
        self.retain("authenticated-file-" + str(len(self.originals)), actual)
        require(actual == candidates[0], "tool-origin")
        return actual

    def extracted_file(self, path, limit=BASE.FILE_LIMIT):
        require(str(path) in self.extracted_pins and os.path.realpath(path) == str(path),
                "tool-link")
        before = self.extracted_pins[str(path)]
        _, info, raw = self.read(str(path), os.geteuid(), limit)
        require(BASE.identity(info) == before["identity"]
                and POLICY.digest(raw) == before["sha256"], "tool-link")
        return raw

    def future_helper(self, path, expected_package=None):
        require(path.startswith(("/usr/bin/", "/usr/sbin/") + DATA_PREFIXES), "unsafe-input")
        if path == "/usr/sbin/phpdismod":
            require(expected_package in (None, "php-common"), "tool-origin")
            require("php-common" in self.extractions, "tool-origin")
            root = self.extractions["php-common"]
            alias = str(root / path.removeprefix("/"))
            target = "/usr/sbin/phpenmod"
            _, listing = self.retained_metadata("php-common-archive-listing")
            matches = [line.split(None, 5)[5] for line in listing.decode("utf-8").splitlines()
                       if line.startswith("l") and len(line.split(None, 5)) == 6
                       and line.split(None, 5)[5].split(" -> ", 1)[0] == "." + path]
            require(matches == ["." + path + " -> phpenmod"], "tool-link")
            before = BASE.identity(os.lstat(alias))
            require(stat.S_ISLNK(os.lstat(alias).st_mode)
                    and os.lstat(alias).st_uid == os.geteuid()
                    and os.readlink(alias) == "phpenmod"
                    and os.path.realpath(alias) == str(root / target.removeprefix("/")),
                    "tool-link")
            binding = {"alias": str(alias), "target": "phpenmod", "identity": before,
                       "canonical": str(root / target.removeprefix("/")),
                       "listing_sha256": POLICY.digest(listing),
                       "archive_sha256": self.archives["php-common"][1]}
            previous = self.future_aliases.get(path)
            if previous is None:
                self.retain("php-common-future-alias",
                            json.dumps(binding, sort_keys=True).encode("ascii"))
                self.future_aliases[path] = binding
            else:
                require(previous == binding, "tool-link")
            result = self.future_helper(target, "php-common")
            self.verify_future_aliases()
            return result
        candidates = [(name, root / path.removeprefix("/"))
                      for name, root in self.extractions.items()
                      if (root / path.removeprefix("/")).is_file()
                      and not (root / path.removeprefix("/")).is_symlink()]
        require(len(candidates) == 1, "tool-origin")
        name, file = candidates[0]
        require(expected_package is None or name == expected_package, "tool-origin")
        rows = [row for row in self.lock if row["package"] == name]
        require(len(rows) == 1, "tool-origin")
        row = rows[0]
        raw = self.extracted_file(file)
        control = self.archives[name][2]
        require("md5sums" in control and any(
            line.split() == [hashlib.md5(raw).hexdigest(), path.removeprefix("/")]
            for line in control["md5sums"].decode("ascii").splitlines()), "tool-origin")
        self.retain("future-helper-" + str(len(self.originals)), raw)
        return {"canonical": path, "raw": raw, "sha256": POLICY.digest(raw), "future": True,
                "package": [name, row["version"], row["architecture"],
                            row["source"], row["source_version"]],
                "manifest_sha256": POLICY.digest(control["md5sums"])}

    def verify_future_aliases(self):
        for binding in self.future_aliases.values():
            self.check()
            alias = binding["alias"]
            _, retained = self.retained_metadata("php-common-future-alias")
            _, listing = self.retained_metadata("php-common-archive-listing")
            require(retained == json.dumps(binding, sort_keys=True).encode("ascii")
                    and POLICY.digest(listing) == binding["listing_sha256"]
                    and self.archives["php-common"][1] == binding["archive_sha256"]
                    and BASE.identity(os.lstat(alias)) == binding["identity"]
                    and os.readlink(alias) == binding["target"]
                    and os.path.realpath(alias) == binding["canonical"], "tool-link")
            self.extracted_file(Path(binding["canonical"]))
            require(BASE.identity(os.lstat(alias)) == binding["identity"]
                    and os.readlink(alias) == binding["target"]
                    and os.path.realpath(alias) == binding["canonical"], "tool-link")

    def verify_installed_status(self):
        _, _, status = self.read("/var/lib/dpkg/status", limit=16777216)
        self.retain("installed-status-after", status)
        after = {}
        for row in POLICY.deb822(status):
            if row.get("Status") == "install ok installed":
                require(row["Package"] not in after, "inventory-shape")
                after[row["Package"]] = row["Version"]
        expected = dict(self.installed)
        expected.update({row["package"]: row["version"] for row in self.lock})
        require(after == expected, "tool-origin")
        return POLICY.deb822(status)

    def refresh_installed_authority(self, records):
        require(self._transaction is not None and self._transaction["consumed"]
                and self.root_cursor == POLICY.OPERATIONS.index("select-php"), "tool-origin")
        changed = {row["package"] for row in self.lock}
        mutable = set()
        for package in tuple(self.packages):
            if package.split(":")[0] in changed:
                self.packages.pop(package)
        for path, entry in self.origins.items():
            if entry["package"][0].split(":")[0] in changed:
                mutable.add(path)
        for path in self.pins:
            if (path in ("/var/lib/dpkg/status", "/var/lib/dpkg/triggers/File",
                         "/var/lib/ucf/registry", "/var/lib/ucf/hashfile", "/etc/ld.so.cache")
                    or path.startswith("/etc/php/" + self.version + "/")
                    or any(path.startswith("/var/lib/dpkg/info/" + name + ".")
                           or path.startswith("/var/lib/dpkg/info/" + name + ":")
                           for name in changed)):
                mutable.add(path)
        for path in mutable:
            before_binding = self.retained_bindings.pop(path, None)
            if before_binding is not None:
                self.receipt.setdefault("pre_install_retained_bindings", {})[path] = {
                    "pin_sha256": POLICY.digest(before_binding["pin"]),
                    "retained_label": before_binding["label"],
                    "retained_identity": before_binding["identity"],
                }
            refreshed = self.receipt.get("authorized_helper_replacements", {}).get(path)
            preserve_pin = (refreshed is not None and path in self.pins
                            and refreshed["after_identity"] == self.pins[path]["identity"])
            if path in self.pins and not preserve_pin:
                self.pins.pop(path)
                self._retired_pins += 1
            if not preserve_pin:
                self._pin_bytes.pop(path, None)
            self.files.pop(path, None)
            self.origins.pop(path, None)
        self.installed_records = records
        TRUST.verify_loader(self)
        observed = {}
        for row in self.lock:
            root = self.extractions[row["package"]]
            for file in self.extracted_pins:
                if not file.startswith(str(root) + "/"):
                    continue
                candidate = Path(file)
                self.check()
                path = "/" + candidate.relative_to(root).as_posix()
                if not path.startswith(("/usr/bin/", "/usr/sbin/", "/usr/lib/php/",
                                        "/usr/share/php")):
                    continue
                incoming = self.extracted_file(candidate)
                binding = self.bound_file(path, row["package"])
                require(binding["canonical"] == path and binding["raw"] == incoming
                        and binding["package"] == [
                            row["package"], row["version"], row["architecture"],
                            row["source"], row["source_version"]], "tool-origin")
                if incoming.startswith(b"\x7fELF"):
                    BASE.parse_elf(incoming)
                    self.dependencies(path)
                observed[path] = {
                    "sha256": binding["sha256"], "package": binding["package"],
                    "manifest_sha256": binding["manifest_sha256"],
                    "authority_role": "observed-installed-package-data",
                }
        for path in SESSION_PRODUCERS:
            binding = self.bound_file(path, "php-common")
            require((len(binding["raw"]), binding["sha256"]) == SESSION_PRODUCERS[path],
                    "tool-origin")
        self.receipt["post_install_package_bindings"] = observed
        domain = self.session_domain(self.session_directory("/usr/lib/php"))
        workers = {version: self.session_worker_guard(version) for version in domain}
        require(self.version in workers and workers[self.version]["active"], "php-config")
        self.snapshot_php_state(set(domain))
        self.receipt["post_install_query_domain"] = domain
        self.receipt["post_install_workers"] = workers
        self.verify_sources()

    def configure(self):
        self.stage = "configuration"
        for path, sha, _ in self.archives.values():
            require(BASE.identity(os.lstat(path)) == self.archive_pins[str(path)], "tool-link")
            _, _, raw = self.read(str(path), limit=268435456)
            require(POLICY.digest(raw) == sha, "tool-origin")
        with self.transaction_admission("apt-install"):
            self.root("apt-install")
        records = self.verify_installed_status()
        self.refresh_install_parents()
        self.verify_php_scope()
        cache_before = self.pins.get("/etc/ld.so.cache")
        self.refresh_installed_authority(records)
        self.receipt["loader_cache_before"] = cache_before["sha256"] if cache_before else None
        self.root("select-php")
        require(os.path.realpath("/usr/bin/php") == "/usr/bin/php" + self.version, "php-config")
        template = self.authenticated_file("/usr/lib/php/" + self.version + "/php.ini-production.cli")
        def installed_data(path):
            GUARD.no_capabilities(path)
            _, _, raw = self.read(path, limit=4194304)
            return self.retain("installed-data-" + str(len(self.originals)), raw)
        generated = installed_data("/etc/php/" + self.version + "/cli/php.ini")
        registry = installed_data("/var/lib/ucf/registry")
        hashes = installed_data("/var/lib/ucf/hashfile")
        POLICY.ucf_binding(self.version, generated, template, registry, hashes)
        paths = sorted(Path("/etc/php/" + self.version + "/cli/conf.d").iterdir())
        require(len(paths) <= 64, "file-budget")
        for path in paths:
            require(path.name.endswith(".ini") and path.is_symlink(), "php-config")
            canonical = os.path.realpath(path)
            require(canonical.startswith("/etc/php/" + self.version + "/mods-available/"),
                    "php-config")
            require(os.lstat(path).st_uid == 0, "php-config")
            contents = installed_data(canonical)
            module = path.name.split("-", 1)[-1]
            templates = []
            for extraction in self.extractions.values():
                for candidate in extraction.glob("usr/share/php" + self.version + "-*/*/" + module):
                    require(candidate.is_file() and not candidate.is_symlink(), "php-config")
                    raw = self.extracted_file(candidate)
                    templates.append(raw)
            require(len(templates) == 1 and contents == templates[0], "php-config")
        self.verify_sources()
        self.verify_installed_status()
        self.receipt["installed_authority_verified"] = True
        self.receipt.update(state="signed-package-postcondition-verified",
                            historical_chmod_performed=False)

    def provision(self):
        self.preflight()
        self.bootstrap()
        self.compile_transaction()
        self.configure()


def preflight_witness(value, stage, reason):
    if value is None:
        return None
    require(stage == "preflight" and reason == "tool-identity"
            and type(value) is dict
            and set(value) == {"step", "requested_tool", "identity_check"}, "inventory-shape")
    require(type(value["step"]) is str and value["step"] in PREFLIGHT_STEPS
            and (value["requested_tool"] is None or (
                type(value["requested_tool"]) is str
                and value["requested_tool"] in TOOL_IDS.values()))
            and (value["identity_check"] is None or (
                type(value["identity_check"]) is str
                and value["identity_check"] in BASE.IDENTITY_CHECKS)), "inventory-shape")
    require((value["requested_tool"] is not None)
            == (value["step"] in ("tool-file", "tool-origin")), "inventory-shape")
    return dict(value)


def projection(provider, inventory, reason):
    provider.verify_sources()
    receipt = dict(provider.receipt, reason=reason, stage=provider.stage)
    raw = json.dumps(receipt, sort_keys=True).encode()
    provider.retain("provider-receipt", raw)
    provider.metadata_witness()
    retained = provider.originals["provider-receipt"]
    value = BASE.decode_object(retained)
    require(all(type(value[field]) is bool for field in
                ("historical_chmod_performed", "standard_configuration_hardened", "apt_original_accepted",
                 "installed_authority_verified",
                 "signed_transaction_authorized",
                 "selected_signature_verified", "native_inventory_completed"))
            and value["historical_chmod_performed"] is False, "inventory-shape")
    require(value["producer_sources"] == provider.receipt["producer_sources"], "tool-link")
    sources = {name: row["sha256"] for name, row in value["producer_sources"].items()}
    require(set(sources) == set(SOURCES)
            and all(POLICY.DIGEST.fullmatch(sha) for sha in sources.values()), "tool-link")
    complete = (reason is None and value["state"] == "signed-package-postcondition-verified"
                and value["apt_original_accepted"] and value["selected_signature_verified"]
                and value.get("standard_configuration_hardened") is True
                and value["installed_authority_verified"] is True
                and value["signed_transaction_authorized"] is True
                and provider._transaction is not None and provider._transaction["consumed"] is True
                and provider.root_cursor == len(POLICY.OPERATIONS)
                and inventory is not None and inventory.receipt["state"] == "inventory-complete")
    apis = None
    if inventory is not None:
        capture = inventory.projection_capture
        ledger = BASE.decode_object(capture["retained"])
        captured = capture["captured"]
        original = captured["inventory.private.json"]
        require(POLICY.digest(original) == ledger["inventory.private.json"]["sha256"], "retention")
        acquisition = BASE.decode_object(original)
        require(acquisition["selected_php"] == provider.version, "php-config")
        require(acquisition["state"] == inventory.receipt["state"], "inventory-shape")
        rows = {}
        for operation, key in (("php-bare", "php_without_config"), ("php", "php")):
            commands = [(number, row) for number, row in enumerate(acquisition["commands"], 1)
                        if row["operation"] == operation]
            require(len(commands) <= 1, "inventory-shape")
            if commands:
                number, command = commands[0]
                for label in ("stdout", "stderr"):
                    name = "command-%d.%s.private" % (number, label)
                    require(POLICY.digest(captured[name]) == ledger[name]["sha256"]
                            == command[label + "_sha256"]
                            and len(captured[name]) == ledger[name]["bytes"]
                            == command[label + "_bytes"], "retention")
                observed_name = "command-%d.observed.private.json" % number
                require(POLICY.digest(captured[observed_name]) == ledger[observed_name]["sha256"]
                        and len(captured[observed_name]) == ledger[observed_name]["bytes"],
                        "retention")
                observed = BASE.decode_object(captured[observed_name])
                require(observed == command, "retention")
                require(command["exit"] is None or type(command["exit"]) is int, "inventory-shape")
                for flag in ("stdout_eof", "stderr_eof", "retention_failed"):
                    require(type(command[flag]) is bool, "inventory-shape")
                require(command["failure"] in (None, "incomplete"), "inventory-shape")
                known = (type(command["exit"]) is int and command["exit"] == 0
                         and command["stdout_eof"] is True and command["stderr_eof"] is True
                         and command["failure"] is None and command["retention_failed"] is False
                         and not captured["command-%d.stderr.private" % number])
                row = {"original_exit_zero": command["exit"] == 0,
                       "stdout_eof": command["stdout_eof"], "stderr_eof": command["stderr_eof"],
                       "functions": None, "constants": None}
                if not known:
                    complete = False
                if known:
                    actual = BASE.decode_object(captured["command-%d.stdout.private" % number])
                    require(actual == acquisition[key], "retention")
                    try:
                        facts = DRIVER.api_facts(
                            {"php-configured": ({"state": "completed"},
                                                captured["command-%d.stdout.private" % number])},
                            provider.version)
                    except DRIVER.BASE.Refusal as error:
                        raise BASE.Refusal(str(error)) from None
                    if complete:
                        BASE.validate_php(actual)
                    row["functions"] = facts["functions"]
                    # The shared historical driver validates the producer shape,
                    # but its positive-value facts are eligibility, not presence.
                    row["constants"] = {
                        name: actual["constants"][name] is not None
                        for name in DRIVER.PHP_CONSTANT_IDS}
                rows[operation] = row
        if complete:
            require(set(rows) == {"php-bare", "php"}, "inventory-shape")
        apis = rows
    public = {
        "schema": "signed-host-php-acquisition-v1", "selected_php": provider.version,
        "state": "observed" if complete else "refused",
        "stage": provider.stage, "reason": reason,
        "preflight_refusal": preflight_witness(value.get("preflight_refusal"),
                                              provider.stage, reason),
        "source_sha256": {name: sources[filename] for name, filename in zip(
            ("provider", "capture", "policy", "signature", "driver", "gate", "guard",
             "trust", "ca"), SOURCES)},
        "provider_receipt_sha256": POLICY.digest(retained),
        "historical_chmod_performed": False,
        "standard_configuration_hardened": value.get("standard_configuration_hardened", False),
        "standard_package_housekeeping_authorized": True,
        "signed_transaction_authorized": value["signed_transaction_authorized"],
        "transaction_consumed": (provider._transaction is not None
                                 and provider._transaction["consumed"] is True),
        "installed_authority_verified": value["installed_authority_verified"],
        "prospective_data_establishes_installed_authority": False,
        "live_scheduler_observation": "UNRESOLVED",
        "signed_package_postcondition_verified": value["state"] == "signed-package-postcondition-verified",
        "apt_original_accepted": value["apt_original_accepted"],
        "selected_signature_verified": value["selected_signature_verified"],
        "native_inventory_completed": complete, "php_api_presence": apis,
        "host_admission_proved": False, "fd_behavior_proved": False,
        "root_cleanup_certified": False,
    }
    require(public["stage"] in ("preflight", "bootstrap", "transaction", "configuration")
            and (reason is None or reason in BASE.REASONS), "inventory-shape")
    encoded = json.dumps(public, sort_keys=True, separators=(",", ":")).encode("ascii")
    require(len(encoded) <= 16384, "retention-budget")
    provider.write("projection.public.json", encoded)
    _, _, observed = provider.read(str(provider.directory / "projection.public.json"),
                                  os.geteuid(), 16384)
    require(observed == encoded, "retention")
    provider.check()
    print("WSTM_SIGNED_HOST_PHP_ACQUISITION_V1 " + encoded.decode("ascii"), flush=True)
    return complete


def main(argv=None):
    argv = sys.argv if argv is None else argv
    if len(argv) == 2 and argv[1] == "units":
        import unittest
        folder = Path(__file__).resolve().parents[1] / "tests" / "unit"
        suite = unittest.TestSuite()
        for name in ("test_host_prerequisite_inventory.py",
                     "test_provision_php82_permissions.py", "test_host_prerequisite_setup.py",
                     "test_host_php_apt_session_loop.py", "test_standard_php_config.py"):
            suite.addTests(unittest.defaultTestLoader.discover(str(folder), pattern=name))
        return 0 if unittest.TextTestRunner(verbosity=2).run(suite).wasSuccessful() else 1
    provider = None
    inventory = None
    reason = None
    root = os.environ.get("WSTM_HOST_SETUP_ROOT", "")
    guard_environment = {
        "PATH": "/usr/bin:/bin", "LC_ALL": "C",
        "GITHUB_ACTIONS": os.environ.get("GITHUB_ACTIONS", ""),
        "GITHUB_JOB": os.environ.get("GITHUB_JOB", ""),
        "WSTM_HOST_SETUP_ROOT": root,
        "WSTM_HOST_SETUP_RUNNER": os.environ.get("WSTM_HOST_SETUP_RUNNER", ""),
    }
    try:
        require(len(argv) == 2, "unsafe-input")
        POLICY.entry(argv[1], os.environ, os.getuid(), os.geteuid(), sys.platform)
        provider = Provider(argv[1])
        os.umask(0o077)
        provider.reserve(root)
        provider.provision()
        os.environ.clear()
        os.environ.update(guard_environment)
        phases = []
        result = DRIVER.main(["signed-php-permission-inventory", provider.version], retained=phases)
        inventory = next((phase for phase in phases
                          if phase.receipt["kind"] == "readonly-host-inventory"), None)
        provider.receipt["standard_configuration_hardened"] = (
            result == 0 and len(phases) == 2
            and phases[0].receipt["state"] == "permission-postcondition-verified")
        require(result == 0, "native-command")
    except (BASE.Refusal, POLICY.PolicyError, SIGNATURE.SignatureError, TRUST.TrustError) as error:
        reason = str(error) if str(error) in BASE.REASONS else "inventory-shape"
    except Exception:
        reason = "inventory-io"
    try:
        if provider is not None and provider.directory is not None:
            if projection(provider, inventory, reason):
                return 0
    except Exception:
        print("WSTM signed PHP projection refused; private originals retained where available.")
        return 78
    print("WSTM signed PHP setup refused: " + (reason or "native-command") + ".")
    return 78


if __name__ == "__main__":
    sys.exit(main())
