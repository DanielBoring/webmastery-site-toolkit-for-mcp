"""Nonroot CI setup guard; only the fixed system chmod may be elevated."""

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import selectors
import stat
import subprocess
import sys


if __name__ == "__main__" and (
        sys.platform != "linux" or os.getuid() != os.geteuid() or os.geteuid() == 0):
    print("WSTM PHP setup permission guard refused: platform.")
    sys.exit(78)


SPEC = importlib.util.spec_from_file_location(
    "readonly_inventory", Path(__file__).with_name("host-prerequisite-inventory.py"))
BASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BASE)
MODE = "setup-php82-permissions-v1"
TARGETS = ("/etc/php/8.2/cli/php.ini", "/etc/php/8.2/cli/conf.d/99-pecl.ini")
ROOT_ARGV = ("/usr/bin/sudo", "-n", "--user=root", "--", "/usr/bin/chmod",
             "--", "0644", "/etc/php/8.2/cli/php.ini",
             "/etc/php/8.2/cli/conf.d/99-pecl.ini")
ROOT_ARGVS = {
    "8.2": ROOT_ARGV,
    "8.4": ("/usr/bin/sudo", "-n", "--user=root", "--", "/usr/bin/chmod",
            "--", "0644", "/etc/php/8.4/cli/php.ini",
            "/etc/php/8.4/cli/conf.d/99-pecl.ini"),
}
APPROVED_JOBS = {
    "8.2": ("release-package-qa",),
    "8.4": ("ability-contract-qa", "full-mcp-e2e-qa"),
}
SYSTEM = ("/usr/bin/sudo", "/usr/bin/chmod")
POLICY_MODULES = ("/usr/libexec/sudo/sudoers.so", "/usr/lib/sudo/sudoers.so")
SUDO_LIBRARIES = ("/usr/libexec/sudo", "/usr/lib/sudo")
require = BASE.require
Refusal = BASE.Refusal


def validate_setup_file(path, info, version="8.2"):
    require(version in BASE.PHP_PROFILES, "php-config")
    targets = BASE.PHP_PROFILES[version]["targets"]
    require(path in targets or path == SYSTEM[0], "unsafe-input")
    require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1,
            "tool-identity")
    if path in targets:
        require(stat.S_IMODE(info.st_mode) in (0o777, 0o644), "tool-identity")
        require(0 <= info.st_size <= 1048576, "file-budget")
    else:
        require(not info.st_mode & 0o2022, "tool-identity")
        require(0 <= info.st_size <= BASE.FILE_LIMIT, "file-budget")


def no_capabilities(path):
    require(hasattr(os, "getxattr"), "tool-identity")
    try:
        raw = os.getxattr(path, "security.capability")
    except OSError as error:
        require(error.errno in (61, 95), "tool-identity")
        raw = b""
    require(not raw, "tool-identity")


def unchanged_content(before, after):
    keys = ("dev", "ino", "uid", "gid", "nlink", "size", "mtime_ns")
    require(all(before["identity"][key] == after["identity"][key] for key in keys)
            and before["sha256"] == after["sha256"]
            and before["parents"] == after["parents"], "tool-link")


def sudo_dynamic(raw):
    lines = raw.decode("ascii").splitlines()
    paths = []
    remaining = []
    for line in lines:
        if "(RUNPATH)" in line or "(RPATH)" in line:
            match = re.search(r"\[([^\]]+)\]", line)
            require(match is not None, "tool-loader")
            values = match[1].split(":")
            require(len(values) <= 2 and all(p in SUDO_LIBRARIES for p in values),
                    "tool-loader")
            paths.extend(values)
        else:
            remaining.append(line)
    names, loaders = BASE.parse_dynamic("\n".join(remaining).encode("ascii"))
    return names, loaders, paths


class Provision(BASE.Inventory):
    def __init__(self, version="8.2"):
        super().__init__(version)
        self.targets = self.profile["targets"]
        self.root_argv = ROOT_ARGVS[version]
        self.receipt["kind"] = MODE
        self.receipt["root_cleanup_certified"] = False
        self.ready = False
        self.root_used = False
        self.target_handles = {}

    def pin_source(self, path):
        return super().pin_source(path)

    def special_read(self, path, charge=True):
        self.check()
        require(path in self.targets or path == SYSTEM[0], "unsafe-input")
        require(os.path.realpath(path) == path, "tool-link")
        info = os.lstat(path)
        validate_setup_file(path, info, self.version)
        parents = BASE.parent_pins(path)
        no_capabilities(path)
        if charge:
            require(path not in self.pins, "tool-link")
            require(len(self.pins) < BASE.FILE_COUNT
                    and self.bytes + info.st_size <= BASE.TOTAL_LIMIT, "file-budget")
            self.bytes += info.st_size
            self.pins[path] = {"state": "reserved", "identity": BASE.identity(info),
                               "bytes": info.st_size, "parents": parents}
        else:
            require(self.bytes + info.st_size <= BASE.TOTAL_LIMIT, "file-budget")
            self.bytes += info.st_size
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            require(BASE.identity(os.fstat(descriptor)) == BASE.identity(info), "tool-link")
            raw = bytearray()
            while len(raw) < info.st_size:
                self.check()
                chunk = os.read(descriptor, min(65536, info.st_size - len(raw)))
                require(bool(chunk), "tool-link")
                raw.extend(chunk)
            require(BASE.identity(os.fstat(descriptor)) == BASE.identity(info)
                    and BASE.identity(os.lstat(path)) == BASE.identity(info)
                    and BASE.parent_pins(path) == parents
                    and os.path.realpath(path) == path, "tool-link")
            no_capabilities(path)
            pin = {"identity": BASE.identity(info), "parents": parents,
                   "sha256": hashlib.sha256(raw).hexdigest()}
            if charge:
                self.pins[path].update(state="pinned", sha256=pin["sha256"])
            if path in self.targets and path not in self.target_handles:
                self.target_handles[path] = descriptor
                descriptor = None
            return info, bytes(raw), pin
        finally:
            if descriptor is not None:
                os.close(descriptor)

    def system(self, path):
        canonical = os.path.realpath(path)
        private = canonical.startswith(tuple(p + "/" for p in SUDO_LIBRARIES))
        if path in POLICY_MODULES or private:
            require(private and (canonical in POLICY_MODULES or re.fullmatch(
                r"libsudo_[a-z0-9_-]+\.so(?:\.[0-9]+)*", Path(canonical).name)), "unsafe-input")
            if path in POLICY_MODULES:
                require(canonical == path, "tool-link")
            requested = os.lstat(path)
            require(requested.st_uid == 0 and (
                stat.S_ISLNK(requested.st_mode) or not requested.st_mode & 0o022), "tool-link")
            if canonical not in self.files:
                _, info, raw = self.acquire(path)
                no_capabilities(canonical)
                BASE.parse_elf(raw)
                self.files[canonical] = {
                    "identity": BASE.identity(info), "parents": BASE.parent_pins(canonical),
                    "sha256": hashlib.sha256(raw).hexdigest(),
                    "md5": hashlib.md5(raw).hexdigest(), "elf": True,
                    "header64_hex": raw[:64].hex(), "aliases": {path: BASE.identity(requested)},
                }
            else:
                entry = self.files[canonical]
                require(BASE.identity(os.lstat(canonical)) == entry["identity"]
                        and BASE.parent_pins(canonical) == entry["parents"], "tool-link")
                if path in entry["aliases"]:
                    require(BASE.identity(requested) == entry["aliases"][path], "tool-link")
                no_capabilities(canonical)
            return canonical
        if path != SYSTEM[0]:
            require(path not in self.targets, "unsafe-input")
            return super().system(path)
        if path not in self.files:
            info, raw, pin = self.special_read(path)
            BASE.parse_elf(raw)
            self.files[path] = dict(pin, requested=path, md5=hashlib.md5(raw).hexdigest(),
                                    elf=True, header64_hex=raw[:64].hex(),
                                    aliases={path: BASE.identity(info)})
        else:
            require(BASE.identity(os.lstat(path)) == self.files[path]["identity"]
                    and BASE.parent_pins(path) == self.files[path]["parents"]
                    and os.path.realpath(path) == path, "tool-link")
            no_capabilities(path)
        return path

    def dependencies(self, path):
        canonical = self.system(path)
        if canonical != SYSTEM[0] and not canonical.startswith(
                tuple(p + "/" for p in SUDO_LIBRARIES)):
            return super().dependencies(path)
        entry = self.files[canonical]
        if entry.get("dependencies_done"):
            return
        entry["dependencies_done"] = True
        names, loaders, search = sudo_dynamic(self.command("elf", canonical))
        children = []
        for name in names:
            candidates = {os.path.realpath(str(Path(root) / name))
                          for root in BASE.LIBRARIES + SUDO_LIBRARIES
                          if os.path.isfile(Path(root) / name)}
            require(len(candidates) == 1, "tool-loader")
            children.append(candidates.pop())
        children.extend(loaders)
        entry["declared_dependencies"] = children
        entry["trusted_sudo_search_directories"] = search
        for child in children:
            self.origin(child)
            self.dependencies(child)

    def command(self, operation, argument):
        require(operation in ("owner", "package", "elf", "php-bare"), "unsafe-input")
        return super().command(operation, argument)

    def prepare(self):
        for path in BASE.TOOLS + SYSTEM:
            self.system(path)
        for path in BASE.TOOLS + SYSTEM:
            self.origin(path)
            self.dependencies(path)
        self.origin_tool = "sudo"
        self.pin_sudo_configuration()
        # Pin the ordinary guard interpreter's isolated loaded modules as well.
        for module in tuple(sys.modules.values()):
            file = getattr(module, "__file__", None)
            if file and os.path.realpath(file).startswith("/usr/lib/python"):
                self.origin(file)
                self.dependencies(file)
        binary = self.system("/usr/bin/php")
        require(binary == self.profile["binary"], "php-config")
        bare = BASE.decode_object(self.command("php-bare", binary))
        BASE.validate_php_identity(bare, binary, self.version)
        self.receipt["selected_bare_php"] = bare
        pins = {}
        for path in self.targets:
            _, _, pins[path] = self.special_read(path)
        self.receipt["configuration_before"] = pins
        self.recheck_before()
        self.ready = True

    def pin_sudo_configuration(self):
        path = "/etc/sudo.conf"
        exists = os.path.lexists(path)
        if exists:
            require(os.path.realpath(path) == path, "tool-link")
            _, _, raw = self.acquire(path, limit=65536)
            lines = [line.strip() for line in raw.decode("ascii").splitlines()
                     if line.strip() and not line.lstrip().startswith("#")]
            allowed = {"Plugin sudoers_" + kind + " sudoers.so"
                       for kind in ("policy", "io", "audit")}
            require(all(line in allowed for line in lines), "tool-loader")
        self.receipt["sudo_conf_exists"] = exists
        modules = [p for p in POLICY_MODULES if os.path.lexists(p)]
        require(len(modules) == 1, "tool-loader")
        module = modules[0]
        self.origin(module)
        self.dependencies(module)
        require(self.origins[module]["package"] == self.origins[SYSTEM[0]]["package"],
                "tool-origin")
        self.receipt["sudo_policy_module"] = module

    def recheck_before(self):
        for path in self.targets:
            _, _, current = self.special_read(path, charge=False)
            before = self.receipt["configuration_before"][path]
            require(current == before
                    and BASE.identity(os.fstat(self.target_handles[path])) == before["identity"],
                    "tool-link")
        self.recheck_tools()
        self.check()

    def recheck_tools(self):
        for path in tuple(self.files):
            self.system(path)
        require(os.path.lexists("/etc/sudo.conf") == self.receipt["sudo_conf_exists"],
                "tool-link")
        if self.receipt["sudo_conf_exists"]:
            self.acquire("/etc/sudo.conf", limit=65536)

    def recheck_after(self):
        after = {}
        for path in self.targets:
            info, _, current = self.special_read(path, charge=False)
            require(stat.S_IMODE(info.st_mode) == 0o644, "tool-identity")
            unchanged_content(self.receipt["configuration_before"][path], current)
            require(BASE.identity(os.fstat(self.target_handles[path])) == current["identity"],
                    "tool-link")
            after[path] = current
        self.receipt["configuration_after"] = after
        self.recheck_tools()
        self.check()

    def harden(self, mode):
        require(mode == MODE and self.ready and not self.root_used, "unsafe-input")
        self.root_used = True
        self.recheck_before()
        self.capture_root()
        self.recheck_after()
        self.receipt["state"] = "permission-postcondition-verified"

    def capture_root(self):
        self.check()
        require(self.ready and self.root_used
                and len(self.commands) < BASE.COMMAND_COUNT, "command-budget")
        require(not any(c["operation"] == MODE for c in self.commands), "unsafe-input")
        number = len(self.commands) + 1
        record = {"operation": MODE, "argv": list(self.root_argv),
                  "environment": {"PATH": "/usr/bin:/bin", "LC_ALL": "C"},
                  "exit": None, "stdout_eof": False, "stderr_eof": False,
                  "failure": None, "stdout_bytes": 0, "stderr_bytes": 0,
                  "producer_pid": None, "wait_owner": "original-direct-child",
                  "root_cleanup_certified": False}
        self.commands.append(record)
        self.write("command-%d.intent.private.json" % number, json.dumps(record).encode())
        streams, pipes, buffers = {}, {}, {1: bytearray(), 2: bytearray()}
        process = None
        selector = selectors.DefaultSelector()
        try:
            for fd, label in ((1, "stdout"), (2, "stderr")):
                streams[fd] = self.original("command-%d.%s.private" % (number, label))
                record[label + "_identity"] = BASE.original_identity(os.fstat(streams[fd].fileno()))
                streams[fd].flush()
                os.fsync(streams[fd].fileno())
            self.write("command-%d.reserved.private.json" % number, json.dumps(record).encode())
            self.check()
            process = subprocess.Popen(list(self.root_argv), stdin=subprocess.DEVNULL,
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                       cwd="/", env=record["environment"], close_fds=True)
            record["producer_pid"] = process.pid
            for fd, pipe in ((1, process.stdout), (2, process.stderr)):
                pipes[fd] = pipe
                os.set_blocking(pipe.fileno(), False)
                selector.register(pipe, selectors.EVENT_READ, fd)
            while selector.get_map() or record["exit"] is None:
                self.check()
                for key, _ in selector.select(0.01):
                    fd = key.data
                    limit = BASE.STDOUT_LIMIT if fd == 1 else BASE.STDERR_LIMIT
                    raw = os.read(key.fileobj.fileno(), min(8192, limit + 1 - len(buffers[fd])))
                    if not raw:
                        record["stdout_eof" if fd == 1 else "stderr_eof"] = True
                        selector.unregister(key.fileobj)
                        continue
                    label = "stdout" if fd == 1 else "stderr"
                    self.retain_chunk(fd, raw, streams[fd], buffers,
                                      (self.directory / ("command-%d.%s.private" % (number, label)),
                                       record[label + "_identity"]))
                record["exit"] = process.poll()
        except BaseException:
            record["failure"] = "incomplete"
            raise
        finally:
            selector.close()
            # No signal authority over a privileged child, no drain clock or detach.
            if process is not None:
                record["exit"] = process.poll()
            for pipe in pipes.values():
                pipe.close()
            retention_failed = False
            for fd, stream in streams.items():
                label = "stdout" if fd == 1 else "stderr"
                path = self.directory / ("command-%d.%s.private" % (number, label))
                record[label + "_bytes"] = len(buffers[fd])
                record[label + "_sha256"] = hashlib.sha256(buffers[fd]).hexdigest()
                try:
                    stream.flush()
                    os.fsync(stream.fileno())
                    _, info, raw = self.read(str(path), os.geteuid(), BASE.RECEIPT_LIMIT)
                    require(BASE.original_identity(info) == record[label + "_identity"]
                            and raw == bytes(buffers[fd]), "retention")
                except Exception:
                    retention_failed = True
                finally:
                    stream.close()
            record["retention_failed"] = retention_failed
            self.write("command-%d.observed.private.json" % number, json.dumps(record).encode())
            require(not retention_failed, "retention")
        # Original capture and observed exit/EOF receipt precede interpretation.
        BASE.validate_native_result(record, buffers[2])
        require(not buffers[1], "native-command")
        self.check()

    def close_targets(self):
        for fd in self.target_handles.values():
            os.close(fd)
        self.target_handles.clear()


def validate_entry(argv, environment, uid, euid):
    require(len(argv) == 3 and argv[1] == MODE and argv[2] in ROOT_ARGVS, "unsafe-input")
    require(sys.platform == "linux" and uid == euid > 0, "platform")
    require(environment.get("WSTM_PROVISION_ACTIONS") == "true"
            and environment.get("WSTM_PROVISION_RUNNER") == "github-hosted"
            and environment.get("WSTM_PROVISION_JOB") in APPROVED_JOBS[argv[2]]
            and environment.get("WSTM_PROVISION_INTERVAL") == MODE, "platform")
    return argv[2]


def main(argv=None, retained=None):
    argv = sys.argv if argv is None else argv
    provision = None
    reason = None
    try:
        version = validate_entry(argv, os.environ, os.getuid(), os.geteuid())
        provision = Provision(version)
        provision.receipt["acquisition_job"] = os.environ["WSTM_PROVISION_JOB"]
        os.umask(0o077)
        provision.reserve(os.environ.get("WSTM_PROVISION_ROOT"))
        for path in (__file__, BASE.__file__, str(Path(__file__).with_name("host-prerequisite-setup.py"))):
            provision.pin_source(path)
        provision.prepare()
        provision.harden(MODE)
    except Refusal as error:
        reason = str(error)
    except Exception:
        reason = "inventory-io"
    try:
        if provision is not None and provision.directory is not None:
            provision.finish(reason)
            if reason is None:
                provision.check()
            if retained is not None:
                retained.append(provision)
    except Exception:
        reason = "retention"
    finally:
        if provision is not None:
            provision.close_targets()
    if reason is not None and reason not in BASE.REASONS:
        reason = "inventory-io"
    print("WSTM PHP setup permission guard " +
          ("retained; two-file0644 postcondition verified." if reason is None
           else "refused: " + reason + "."))
    return 0 if reason is None else 78


if __name__ == "__main__":
    sys.exit(main())
