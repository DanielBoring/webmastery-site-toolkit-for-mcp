"""Fixed nonroot inventory only. Never runs setpriv, sudo or a transport."""

import hashlib
import json
import os
from pathlib import Path
import re
import selectors
import stat
import struct
import subprocess
import sys
import time


SECONDS = 30
FILE_LIMIT = 67108864
TOTAL_LIMIT = 268435456
FILE_COUNT = 256
COMMAND_COUNT = 512
STDOUT_LIMIT = 65536
STDERR_LIMIT = 8192
RECEIPT_LIMIT = 16777216
TOOLS = ("/usr/bin/setpriv", "/usr/bin/python3", "/usr/bin/php",
         "/usr/bin/dpkg-query", "/usr/bin/readelf")
ORIGIN_TOOL_IDS = {"/usr/bin/setpriv": "setpriv", "/usr/bin/python3": "python",
                   "/usr/bin/php": "php", "/usr/bin/dpkg-query": "dpkg-query",
                   "/usr/bin/readelf": "readelf", "/usr/bin/sudo": "sudo",
                   "/usr/bin/chmod": "chmod"}
ORIGIN_CHECK_IDS = frozenset(("unknown", "owner-command", "owner-response-shape",
    "owner-target", "owner-missing", "owner-ambiguous", "manifest-link",
    "package-metadata", "manifest-identity", "manifest-encoding"))
ORIGIN_SUBJECT_IDS = frozenset(("tool", "dependency", "python-module"))
OWNER_SHAPE_VALUES = {
    "row_count": frozenset(("zero", "one", "many")),
    "owner_rows": frozenset(("zero", "one", "many")),
    "owner_domain": frozenset(("zero", "one", "many")),
    "target_relation": frozenset(("none", "all", "mixed", "foreign")),
    "diversion_rows": frozenset(("zero", "one", "many")),
    "other_rows": frozenset(("zero", "one", "many")),
}
PHP_PROFILES = {
    "8.2": {"binary": "/usr/bin/php8.2", "targets": (
        "/etc/php/8.2/cli/php.ini", "/etc/php/8.2/mods-available/sockets.ini")},
    "8.4": {"binary": "/usr/bin/php8.4", "targets": (
        "/etc/php/8.4/cli/php.ini", "/etc/php/8.4/mods-available/sockets.ini")},
}
SOURCE_NAMES = {"driver": "host-prerequisite-setup.py",
                "guard": "provision-php82-permissions.py",
                "gate": "host-prerequisite-inventory.py"}
PROJECTION_OPERATIONS = {"setup-php82-permissions-v1": "guard",
                         "php-bare": "php-bare", "php": "php-configured"}
LIBRARIES = ("/usr/lib/x86_64-linux-gnu", "/usr/lib/aarch64-linux-gnu")
PHP_MODULES = frozenset((
    "bcmath", "bz2", "calendar", "ctype", "curl", "dom", "exif", "ffi", "fileinfo",
    "ftp", "gd", "gettext", "gmp", "iconv", "intl", "mbstring", "mysqli", "mysqlnd",
    "opcache", "pcntl", "pdo", "pdo_mysql", "pdo_sqlite", "phar", "posix", "readline",
    "shmop", "simplexml", "soap", "sockets", "sodium", "sqlite3", "sysvmsg",
    "sysvsem", "sysvshm", "tidy", "tokenizer", "xdebug", "xml", "xmlreader",
    "xmlwriter", "xsl", "zip",
))
FUNCTIONS = ("socket_create_pair", "socket_import_stream", "socket_export_stream",
             "socket_sendmsg", "socket_recvmsg", "socket_set_option")
CONSTANTS = ("SOCK_SEQPACKET", "SCM_RIGHTS", "SCM_CREDENTIALS", "SO_PASSCRED",
             "SO_PEERCRED", "MSG_CMSG_CLOEXEC", "MSG_TRUNC", "MSG_CTRUNC",
             "SOL_SOCKET")
REASONS = frozenset((
    "tool-identity", "tool-parent", "tool-link", "tool-missing", "tool-origin",
    "tool-hash", "tool-elf", "tool-loader", "file-budget", "command-budget",
    "inventory-shape", "inventory-deadline", "inventory-io", "unsafe-input",
    "custody", "retention", "retention-budget", "native-output", "native-command",
    "php-config", "php-sockets", "php-api", "php-constants", "platform",
))
PHP_QUERY = r'''
$functions = array(); foreach (array("socket_create_pair", "socket_import_stream",
 "socket_export_stream", "socket_sendmsg", "socket_recvmsg", "socket_set_option",
 "fcntl") as $name) { $functions[$name] = function_exists($name); }
$constants = array(); foreach (array("SOCK_SEQPACKET", "SCM_RIGHTS", "SCM_CREDENTIALS",
 "SO_PASSCRED", "SO_PEERCRED", "MSG_CMSG_CLOEXEC", "MSG_TRUNC", "MSG_CTRUNC",
 "SOL_SOCKET", "SOCK_CLOEXEC", "FD_CLOEXEC", "F_GETFD", "F_SETFD") as $name) {
 $constants[$name] = defined($name) ? constant($name) : null;
}
$modules = array(); foreach (get_loaded_extensions() as $name) {
 $extension = new ReflectionExtension($name);
 $modules[$name] = $extension->getVersion();
}
echo json_encode(array("version" => PHP_VERSION, "binary" => PHP_BINARY,
 "sockets" => extension_loaded("sockets"), "functions" => $functions,
 "constants" => $constants, "modules" => $modules,
 "extension_dir" => ini_get("extension_dir"),
 "FFI_class" => class_exists("FFI", false), "FFI_enable" => ini_get("ffi.enable")),
 JSON_THROW_ON_ERROR), "\n";
'''


class Refusal(Exception):
    pass


def require(condition, reason, *, origin_check=None, owner_response_shape=None):
    if not condition:
        error = Refusal(reason)
        if reason == "tool-origin" and origin_check is not None:
            error.origin_check = origin_check
        if reason == "tool-origin" and owner_response_shape is not None:
            error.owner_response_shape = owner_response_shape
        raise error


def owner_response_shape(lines, query):
    """Describe acquired rows without accepting any owner or exposing their text."""
    def count(value):
        return "zero" if value == 0 else "one" if value == 1 else "many"
    owners, targets = set(), []
    diversions = other = 0
    for line in lines:
        if line.startswith(("diversion by ", "local diversion ")):
            diversions += 1
            continue
        if ": " in line:
            names, target = line.split(": ", 1)
            names = names.split(", ")
            if all(re.fullmatch(r"[a-z0-9][a-z0-9+.-]{0,127}(?::[a-z0-9-]{1,128})?", name)
                   for name in names):
                owners.update(names)
                targets.append(target == query)
                continue
        other += 1
    relation = ("none" if not targets else "all" if all(targets)
                else "mixed" if any(targets) else "foreign")
    return {"row_count": count(len(lines)), "owner_rows": count(len(targets)),
            "owner_domain": count(len(owners)), "target_relation": relation,
            "diversion_rows": count(diversions), "other_rows": count(other)}


def identity(info):
    return {key: getattr(info, "st_" + key) for key in (
        "dev", "ino", "uid", "gid", "mode", "nlink", "size", "mtime_ns", "ctime_ns")}


def original_identity(info):
    return {key: getattr(info, "st_" + key) for key in ("dev", "ino", "uid", "gid", "mode", "nlink")}


def verify_original(stream, path, expected):
    require(original_identity(os.fstat(stream.fileno())) == expected
            and original_identity(os.lstat(path)) == expected
            and os.path.realpath(path) == str(path), "retention")


IDENTITY_CHECKS = ("regular-file", "expected-owner", "safe-mode", "single-link")


def validate_file(info, owner, limit):
    for check, accepted in zip(IDENTITY_CHECKS, (
            stat.S_ISREG(info.st_mode), info.st_uid == owner,
            not info.st_mode & 0o6022, info.st_nlink == 1)):
        if not accepted:
            error = Refusal("tool-identity")
            error.identity_check = check
            raise error
    require(0 <= info.st_size <= limit, "file-budget")


def validate_parent(info):
    require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0
            and not info.st_mode & 0o022, "tool-parent")


def parent_pins(canonical):
    parents = {}
    parent = Path(canonical).parent
    while True:
        info = os.lstat(parent)
        validate_parent(info)
        parents[str(parent)] = identity(info)
        if parent == parent.parent:
            break
        parent = parent.parent
    return parents


def decode_object(raw):
    def pairs(values):
        result = {}
        for key, value in values:
            require(key not in result, "inventory-shape")
            result[key] = value
        return result
    try:
        value = json.loads(raw, object_pairs_hook=pairs)
    except (ValueError, UnicodeError):
        raise Refusal("inventory-shape") from None
    require(isinstance(value, dict), "inventory-shape")
    return value


def validate_php(value):
    require(value.get("sockets") is True, "php-sockets")
    require(isinstance(value.get("functions"), dict)
            and all(value["functions"].get(name) is True for name in FUNCTIONS), "php-api")
    require(isinstance(value.get("constants"), dict)
            and all(type(value["constants"].get(name)) is int
                    and value["constants"][name] > 0
                    for name in CONSTANTS), "php-constants")


def validate_php_identity(value, binary, version):
    require(value.get("binary") == binary and isinstance(value.get("version"), str)
            and len(value["version"]) <= 64 and value["version"].startswith(version + "."),
            "php-config")


def validate_native_result(record, stderr, lookup_miss=False):
    require(record["stdout_eof"] is True and record["stderr_eof"] is True
            and ((record["exit"] == 0 and not stderr)
                 or (lookup_miss and record["exit"] == 1)), "native-command")


def verify_manifest(raw, canonical, md5):
    candidates = {canonical.lstrip("/")}
    if canonical.startswith("/usr/"):
        candidates.add(canonical[5:])
    entries = []
    try:
        for line in raw.decode("ascii").splitlines():
            match = re.fullmatch(r"([a-f0-9]{32})  (\S+)", line)
            if match and match[2] in candidates:
                entries.append(match[1])
    except UnicodeError:
        raise Refusal("tool-origin") from None
    require(len(entries) == 1 and entries[0] == md5, "tool-hash")


def parse_elf(raw):
    require(len(raw) >= 64 and raw[:7] == b"\x7fELF\x02\x01\x01"
            and raw[7] in (0, 3), "tool-elf")
    kind, machine, version = struct.unpack_from("<HHI", raw, 16)
    require(kind in (2, 3) and machine in (62, 183) and version == 1, "tool-elf")


def parse_dynamic(raw):
    try:
        text = raw.decode("ascii")
    except UnicodeError:
        raise Refusal("tool-elf") from None
    require("(RUNPATH)" not in text and "(RPATH)" not in text, "tool-loader")
    names = re.findall(r"\(NEEDED\).*Shared library: \[([^\]]+)\]", text)
    require(len(names) <= 64 and all(re.fullmatch(r"[A-Za-z0-9_.+-]{1,128}", name)
                                    for name in names), "tool-loader")
    loaders = re.findall(r"Requesting program interpreter: ([^\]]+)\]", text)
    require(len(loaders) <= 1 and all(path in (
        "/lib64/ld-linux-x86-64.so.2", "/lib/ld-linux-aarch64.so.1") for path in loaders),
        "tool-loader")
    return names, loaders


def configured_modules(raw, extension_dir):
    try:
        text = raw.decode("utf-8")
    except UnicodeError:
        raise Refusal("php-config") from None
    modules = []
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith(";"):
            continue
        require(not line.startswith(("[PATH", "[HOST")), "php-config")
        if "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip().lower()
        value = value.split(";", 1)[0].strip().strip("\"'")
        if key in ("auto_prepend_file", "auto_append_file", "opcache.preload",
                   "opcache.preload_user", "ffi.preload"):
            require(value == "", "php-config")
        elif key == "extension_dir":
            require(value == extension_dir, "php-config")
        elif key in ("extension", "zend_extension"):
            require(re.fullmatch(r"[a-zA-Z0-9_-]+(?:\.so)?", value) is not None, "php-config")
            require(value.removesuffix(".so") in PHP_MODULES, "php-config")
            modules.append(value if value.endswith(".so") else value + ".so")
    return modules


class Inventory:
    def __init__(self, version="8.2"):
        require(version in PHP_PROFILES, "php-config")
        self.version = version
        self.profile = PHP_PROFILES[version]
        self.deadline = time.monotonic() + SECONDS
        self.files = {}
        self.pins = {}
        self._pin_bytes = {}
        self.origins = {}
        self.packages = {}
        self.commands = []
        self.directory = None
        self.directory_identity = None
        self.bytes = 0
        self.capture_bytes = 0
        self.package_bytes = 0
        self.receipt = {"version": 1, "state": "reserved", "kind": "readonly-host-inventory",
                        "selected_php": version,
                        "files": self.files, "origins": self.origins, "commands": self.commands,
                        "pin_ledger": self.pins,
                        "source_archive_digests": "not acquired",
                        "consumer_duplicate_CLOEXEC": "not behaviorally established",
                        "launcher_transport_admission_or_cleanup_certified": False}

    def check(self):
        require(time.monotonic() < self.deadline, "inventory-deadline")

    def pin_source(self, path):
        self.check()
        canonical = os.path.realpath(path)
        info = os.lstat(canonical)
        validate_file(info, os.geteuid(), FILE_LIMIT)
        require(canonical not in self.pins and len(self.pins) < FILE_COUNT
                and self.bytes + info.st_size <= TOTAL_LIMIT, "file-budget")
        require(canonical == os.path.abspath(path), "tool-link")
        ids = [name for name, filename in SOURCE_NAMES.items()
               if canonical == os.path.realpath(Path(__file__).with_name(filename))]
        require(len(ids) == 1, "unsafe-input")
        self.bytes += info.st_size
        self.pins[canonical] = {"state": "reserved", "bytes": info.st_size,
                                "identity": identity(info)}
        actual, observed, raw = self.read(path, os.geteuid(), info.st_size)
        require(actual == canonical and identity(observed) == identity(info), "tool-link")
        digest = hashlib.sha256(raw).hexdigest()
        self.pins[canonical].update(state="pinned", sha256=digest)
        self.receipt.setdefault("ordinary_guard_sources", []).append(
            {"source_id": ids[0], "sha256": digest, "identity": identity(info),
             "canonical": canonical})

    def reserve(self, root):
        require(isinstance(root, str) and root.startswith("/") and len(root) <= 4096
                and not re.search(r"[\x00-\x1f\x7f]", root), "custody")
        require(os.path.realpath(root) == root, "custody")
        info = os.lstat(root)
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid()
                and not info.st_mode & 0o022, "custody")
        self.directory = Path(root) / ("wstm-prerequisite-" + os.urandom(16).hex())
        os.mkdir(self.directory, 0o700)
        info = os.lstat(self.directory)
        require(stat.S_ISDIR(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o700
                and info.st_uid == os.geteuid(), "custody")
        self.directory_identity = (info.st_dev, info.st_ino, info.st_uid, info.st_mode)
        self.write("reserved.private.json", json.dumps(self.receipt).encode())

    def custody(self):
        require(self.directory is not None, "custody")
        info = os.lstat(self.directory)
        require((info.st_dev, info.st_ino, info.st_uid, info.st_mode) == self.directory_identity
                and os.path.realpath(self.directory) == str(self.directory), "custody")

    def original(self, name):
        self.custody()
        require(re.fullmatch(r"[a-z0-9.-]+", name) is not None, "custody")
        descriptor = os.open(self.directory / name,
                             os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                             0o600)
        try:
            info = os.fstat(descriptor)
            require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid()
                    and stat.S_IMODE(info.st_mode) == 0o600 and info.st_nlink == 1, "custody")
        except BaseException:
            os.close(descriptor)
            raise
        return os.fdopen(descriptor, "wb")

    def write(self, name, raw):
        require(len(raw) <= RECEIPT_LIMIT, "retention-budget")
        with self.original(name) as stream:
            require(stream.write(raw) == len(raw), "retention")
            stream.flush()
            os.fsync(stream.fileno())
        self.custody()

    def read(self, path, owner=0, limit=FILE_LIMIT):
        self.check()
        canonical = os.path.realpath(path)
        info = os.lstat(canonical)
        validate_file(info, owner, limit)
        parents = parent_pins(canonical) if owner == 0 else None
        descriptor = os.open(canonical, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            require(identity(os.fstat(descriptor)) == identity(info), "tool-link")
            raw = bytearray()
            while len(raw) < info.st_size:
                self.check()
                chunk = os.read(descriptor, min(65536, info.st_size - len(raw)))
                if not chunk:
                    break
                raw.extend(chunk)
                require(len(raw) <= limit, "file-budget")
            require(identity(os.fstat(descriptor)) == identity(info)
                    and identity(os.lstat(canonical)) == identity(info)
                    and os.path.realpath(path) == canonical
                    and len(raw) == info.st_size, "tool-link")
            if parents is not None:
                require(parent_pins(canonical) == parents, "tool-parent")
        finally:
            os.close(descriptor)
        return canonical, info, bytes(raw)

    def acquire(self, path, limit=FILE_LIMIT):
        self.check()
        canonical = os.path.realpath(path)
        requested = os.lstat(path)
        info = os.lstat(canonical)
        validate_file(info, 0, limit)
        parents = parent_pins(canonical)
        if canonical in self.pins:
            pin = self.pins[canonical]
            require(pin["state"] == "pinned" and identity(info) == pin["identity"]
                    and parents == pin["parents"], "tool-link")
            if path in pin["aliases"]:
                require(identity(requested) == pin["aliases"][path], "tool-link")
            pin["aliases"][path] = identity(requested)
            return canonical, info, self._pin_bytes[canonical]
        require(len(self.pins) < FILE_COUNT
                and self.bytes + info.st_size <= TOTAL_LIMIT, "file-budget")
        self.bytes += info.st_size
        self.pins[canonical] = {
            "state": "reserved", "identity": identity(info), "parents": parents,
            "aliases": {path: identity(requested)}, "bytes": info.st_size,
        }
        actual, observed, raw = self.read(path, limit=info.st_size)
        require(actual == canonical and identity(observed) == identity(info)
                and len(raw) == info.st_size
                and identity(os.lstat(path)) == identity(requested)
                and parent_pins(canonical) == parents, "tool-link")
        self._pin_bytes[canonical] = raw
        self.pins[canonical].update(state="pinned", sha256=hashlib.sha256(raw).hexdigest())
        return canonical, observed, raw

    def configuration(self, path, version):
        require(version == self.version and version in PHP_PROFILES, "php-config")
        prefix = "/etc/php/" + version + "/"
        require(path.startswith(prefix)
                and os.path.realpath(path).startswith(prefix), "php-config")
        require(os.lstat(path).st_uid == 0, "php-config")
        return self.acquire(path, limit=1048576)

    def system(self, path):
        self.check()
        canonical = os.path.realpath(path)
        if canonical in self.files:
            entry = self.files[canonical]
            require(identity(os.lstat(canonical)) == entry["identity"]
                    and parent_pins(canonical) == entry["parents"], "tool-link")
            if path in entry["aliases"]:
                require(identity(os.lstat(path)) == entry["aliases"][path], "tool-link")
            return canonical
        require(path in TOOLS or path in (
            "/lib64/ld-linux-x86-64.so.2", "/lib/ld-linux-aarch64.so.1")
            or canonical.startswith(("/usr/lib/", "/usr/bin/", "/usr/share/php")),
                "unsafe-input")
        require(canonical.startswith(("/usr/lib/", "/usr/bin/", "/usr/share/php")), "unsafe-input")
        require(os.path.exists(path), "tool-missing")
        # Fixed public aliases may be symlinks; canonical parents may not.
        requested = os.lstat(path)
        require(requested.st_uid == 0 and not requested.st_mode & 0o022
                if not stat.S_ISLNK(requested.st_mode) else requested.st_uid == 0, "tool-link")
        canonical, info, raw = self.acquire(path)
        if hasattr(os, "getxattr"):
            try:
                capability = os.getxattr(canonical, "security.capability")
            except OSError as error:
                require(error.errno in (61, 95), "tool-identity")
                capability = b""
            require(not capability, "tool-identity")
        self.files[canonical] = {
            "requested": path, "identity": identity(info), "sha256": hashlib.sha256(raw).hexdigest(),
            "md5": hashlib.md5(raw).hexdigest(), "elf": raw.startswith(b"\x7fELF"),
            "header64_hex": raw[:64].hex(),
            "parents": parent_pins(canonical), "aliases": {path: identity(requested)},
        }
        if self.files[canonical]["elf"]:
            parse_elf(raw)
        return canonical

    def retain_chunk(self, descriptor, raw, stream, buffers, binding):
        verify_original(stream, *binding)
        require(stream.write(raw) == len(raw), "retention")
        stream.flush()
        os.fsync(stream.fileno())
        verify_original(stream, *binding)
        buffers[descriptor].extend(raw)
        self.capture_bytes += len(raw)
        limit = STDOUT_LIMIT if descriptor == 1 else STDERR_LIMIT
        require(len(buffers[descriptor]) <= limit, "native-output")
        require(self.capture_bytes <= RECEIPT_LIMIT, "retention-budget")

    def command(self, operation, argument):
        self.check()
        require(len(self.commands) < COMMAND_COUNT, "command-budget")
        if operation == "owner":
            require(argument in self.files or (
                argument.startswith(("/lib/", "/bin/"))
                and os.path.realpath(argument) in self.files), "unsafe-input")
            argv = ["/usr/bin/dpkg-query", "-S", argument]
        elif operation == "package":
            require(re.fullmatch(r"[a-z0-9][a-z0-9+.-]{0,127}(?::[a-z0-9-]+)?", argument)
                    is not None, "unsafe-input")
            argv = ["/usr/bin/dpkg-query", "-W",
                    "-f=${binary:Package}\t${Version}\t${Architecture}\t${source:Package}\t${source:Version}\n",
                    argument]
        elif operation == "elf":
            require(argument in self.files and self.files[argument]["elf"], "unsafe-input")
            argv = ["/usr/bin/readelf", "-l", "-d", argument]
        elif operation in ("php", "php-bare"):
            require(argument == self.system("/usr/bin/php")
                    and argument == self.profile["binary"], "php-config")
            # System CLI config only; no user HOME, PHPRC, prepend, logging or env.
            version = re.fullmatch(r"/usr/bin/php([0-9]+\.[0-9]+)", argument)
            require(version is not None, "php-config")
            configuration = ["-n"] if operation == "php-bare" else [
                "-c", "/etc/php/" + version[1] + "/cli/php.ini"]
            argv = [argument] + configuration + [
                "-d", "auto_prepend_file=", "-d", "auto_append_file=",
                "-d", "opcache.preload=", "-d", "opcache.enable_cli=0",
                "-d", "opcache.file_cache=", "-d", "session.auto_start=0",
                "-d", "xdebug.mode=off", "-d", "xdebug.start_with_request=no",
                "-d", "log_errors=0", "-r", PHP_QUERY]
        else:
            raise Refusal("unsafe-input")
        executable = self.system(argv[0])
        require(self.files[executable]["elf"], "tool-elf")
        environment = {"PATH": "/usr/bin:/bin", "LC_ALL": "C"}
        if operation == "php":
            environment["PHP_INI_SCAN_DIR"] = "/etc/php/" + version[1] + "/cli/conf.d"
        number = len(self.commands) + 1
        record = {"operation": operation, "argv": argv, "environment": environment,
                  "exit": None, "stdout_eof": False, "stderr_eof": False,
                  "failure": None, "stdout_bytes": 0, "stderr_bytes": 0}
        self.commands.append(record)
        self.write("command-%d.intent.private.json" % number, json.dumps(record).encode())
        streams = {}
        pipes = {}
        buffers = {1: bytearray(), 2: bytearray()}
        process = None
        selector = selectors.DefaultSelector()
        try:
            for descriptor, label in ((1, "stdout"), (2, "stderr")):
                streams[descriptor] = self.original("command-%d.%s.private" % (number, label))
                record[label + "_identity"] = original_identity(os.fstat(streams[descriptor].fileno()))
                streams[descriptor].flush()
                os.fsync(streams[descriptor].fileno())
            self.write("command-%d.reserved.private.json" % number, json.dumps(record).encode())
            self.check()
            process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                       stderr=subprocess.PIPE, cwd="/", env=environment, close_fds=True)
            for descriptor, pipe in ((1, process.stdout), (2, process.stderr)):
                pipes[descriptor] = pipe
                os.set_blocking(pipe.fileno(), False)
                selector.register(pipe, selectors.EVENT_READ, descriptor)
            while selector.get_map() or record["exit"] is None:
                self.check()
                for key, unused in selector.select(min(0.05, max(0, self.deadline - time.monotonic()))):
                    descriptor = key.data
                    limit = STDOUT_LIMIT if descriptor == 1 else STDERR_LIMIT
                    raw = os.read(key.fileobj.fileno(), min(8192, limit + 1 - len(buffers[descriptor])))
                    if not raw:
                        record["stdout_eof" if descriptor == 1 else "stderr_eof"] = True
                        selector.unregister(key.fileobj)
                        continue
                    label = "stdout" if descriptor == 1 else "stderr"
                    binding = (self.directory / ("command-%d.%s.private" % (number, label)),
                               record[label + "_identity"])
                    self.retain_chunk(descriptor, raw, streams[descriptor], buffers, binding)
                record["exit"] = process.poll()
            validate_native_result(record, buffers[2], operation == "owner")
            require(operation != "owner" or record["exit"] != 1 or not buffers[1], "tool-origin")
            self.system(executable)
            self.check()
        except BaseException:
            record["failure"] = "incomplete"
            raise
        finally:
            selector.close()
            if process is not None and process.poll() is None:
                # Only our fixed, non-set-ID, no-file-capability nonroot child.
                try:
                    process.kill()
                except ProcessLookupError:
                    pass
                record["exit"] = process.poll()
            for pipe in pipes.values():
                pipe.close()
            retention_failed = False
            for descriptor, stream in streams.items():
                label = "stdout" if descriptor == 1 else "stderr"
                path = self.directory / ("command-%d.%s.private" % (number, label))
                record[label + "_bytes"] = len(buffers[descriptor])
                record[label + "_sha256"] = hashlib.sha256(buffers[descriptor]).hexdigest()
                try:
                    stream.flush()
                    os.fsync(stream.fileno())
                    # The prefix remains retained even when this final guard refuses.
                    canonical, info, raw = self.read(str(path), os.geteuid(), RECEIPT_LIMIT)
                    require(original_identity(info) == record[label + "_identity"]
                            and raw == bytes(buffers[descriptor]), "retention")
                except Exception:
                    retention_failed = True
                finally:
                    stream.close()
            record["retention_failed"] = retention_failed
            self.write("command-%d.observed.private.json" % number, json.dumps(record).encode())
            require(not retention_failed, "retention")
        return bytes(buffers[1])

    def origin(self, path):
        if path in ORIGIN_TOOL_IDS:
            self.origin_tool = ORIGIN_TOOL_IDS[path]
            subject = "tool"
        elif path.startswith("/usr/lib/python"):
            self.origin_tool = "python"
            subject = "python-module"
        else:
            subject = "dependency"
        self.origin_check = "unknown"
        try:
            self.checked_origin(path)
        except Refusal as error:
            if str(error) == "tool-origin":
                if not hasattr(error, "origin_check"):
                    error.origin_check = self.origin_check
                self.receipt["origin_failure"] = {
                    "tool": getattr(self, "origin_tool", "unknown"),
                    "subject": subject, "check": self.origin_check}
            raise

    def checked_origin(self, path):
        canonical = self.system(path)
        if canonical in self.origins:
            return
        queries = [canonical]
        if canonical.startswith(("/usr/lib/", "/usr/bin/")):
            alias = "/" + canonical[5:]
            if os.path.exists(alias) and os.path.realpath(alias) == canonical:
                queries.append(alias)
        owners = set()
        for query in queries:
            self.origin_check = "owner-command"
            raw = self.command("owner", query)
            lines = raw.decode("utf-8").strip().splitlines()
            if not lines:
                continue
            self.origin_check = "owner-response-shape"
            require(len(lines) == 1 and ": " in lines[0], "tool-origin",
                    owner_response_shape=owner_response_shape(lines, query))
            package, owned = lines[0].split(": ", 1)
            self.origin_check = "owner-target"
            require(owned == query and os.path.realpath(owned) == canonical, "tool-origin")
            owners.add(package)
        self.origin_check = "owner-missing" if not owners else "owner-ambiguous"
        require(len(owners) == 1, "tool-origin")
        package = owners.pop()
        manifest = "/var/lib/dpkg/info/" + package + ".md5sums"
        self.origin_check = "manifest-link"
        require(os.path.realpath(manifest) == manifest, "tool-origin")
        if package not in self.packages:
            self.origin_check = "unknown"
            metadata = self.command("package", package).decode("utf-8").strip().split("\t")
            self.origin_check = "package-metadata"
            require(len(metadata) == 5 and all(re.fullmatch(r"[A-Za-z0-9.:+~_-]{1,128}", field)
                                              for field in metadata), "tool-origin")
            actual, info, raw = self.read(manifest, limit=4194304)
            self.package_bytes += len(raw)
            require(self.package_bytes <= RECEIPT_LIMIT, "file-budget")
            self.packages[package] = (metadata, actual, info, raw)
        metadata, actual, info, raw = self.packages[package]
        self.origin_check = "manifest-identity"
        require(identity(os.lstat(manifest)) == identity(info), "tool-origin")
        self.origin_check = "manifest-encoding"
        verify_manifest(raw, canonical, self.files[canonical]["md5"])
        self.origins[canonical] = {
            "package": metadata, "manifest": actual, "manifest_identity": identity(info),
            "manifest_sha256": hashlib.sha256(raw).hexdigest(), "installed_digest_matches": True,
            "source_archive_sha256": None,
        }

    def dependencies(self, path):
        canonical = self.system(path)
        entry = self.files[canonical]
        if entry.get("dependencies_done") or not entry["elf"]:
            return
        entry["dependencies_done"] = True
        names, loaders = parse_dynamic(self.command("elf", canonical))
        children = []
        for name in names:
            candidates = {os.path.realpath(str(Path(root) / name)) for root in LIBRARIES
                          if os.path.isfile(Path(root) / name)}
            require(len(candidates) == 1, "tool-loader")
            children.append(candidates.pop())
        children.extend(loaders)
        entry["declared_dependencies"] = children
        for child in children:
            self.origin(child)
            self.dependencies(child)

    def collect(self):
        require(self.system("/usr/bin/php") == self.profile["binary"], "php-config")
        for path in TOOLS:
            self.system(path)
        for path in TOOLS:
            self.origin(path)
            self.dependencies(path)
        # Loaded isolated Python modules, never an ambient site/user search path.
        for module in tuple(sys.modules.values()):
            file = getattr(module, "__file__", None)
            if file and os.path.realpath(file).startswith("/usr/lib/python"):
                self.origin(file)
                self.dependencies(file)
        bare = decode_object(self.command("php-bare", self.system("/usr/bin/php")))
        self.receipt["php_without_config"] = bare
        extension_dir = bare.get("extension_dir")
        require(isinstance(extension_dir, str)
                and re.fullmatch(r"/usr/lib/php/[0-9]{8}", extension_dir), "php-config")
        version = re.fullmatch(r"/usr/bin/php([0-9]+\.[0-9]+)", self.system("/usr/bin/php"))
        validate_php_identity(bare, self.system("/usr/bin/php"), version[1])
        configuration = Path("/etc/php") / version[1] / "cli"
        require(configuration.is_dir() and (configuration / "conf.d").is_dir(), "php-config")
        for directory in (configuration, configuration / "conf.d"):
            parent = Path(os.path.realpath(directory))
            while True:
                validate_parent(os.lstat(parent))
                if parent == parent.parent:
                    break
                parent = parent.parent
        configs = [configuration / "php.ini"] + sorted((configuration / "conf.d").glob("*.ini"))
        require(len(configs) <= 65, "php-config")
        pins = {}
        modules = set()
        for file in configs:
            path, info, raw = self.configuration(str(file), version[1])
            pins[path] = {"identity": identity(info), "sha256": hashlib.sha256(raw).hexdigest()}
            modules.update(configured_modules(raw, extension_dir))
        self.receipt["php_configuration_pins"] = pins
        self.receipt["php_configured_native_modules"] = sorted(modules)
        self.origin_tool = "php"
        for module in sorted(modules):
            file = str(Path(extension_dir) / module)
            self.origin(file)
            self.dependencies(file)
        php = decode_object(self.command("php", self.system("/usr/bin/php")))
        self.receipt["php"] = php
        validate_php_identity(php, self.system("/usr/bin/php"), version[1])
        require(php["version"] == bare["version"], "php-config")
        require(php.get("extension_dir") == extension_dir, "php-config")
        for path, pin in pins.items():
            require(identity(os.lstat(path)) == pin["identity"], "tool-link")
        validate_php(php)
        for canonical in tuple(self.files):
            self.system(canonical)
        self.check()
        self.receipt["state"] = "inventory-complete"
        self.receipt["interpretation"] = "API/origin inventory only; HOST admission and FD behavior unproved"

    def finish(self, reason):
        self.receipt["reason"] = reason
        if reason is not None:
            self.receipt["state"] = "failed"
        self.receipt["elapsed_seconds"] = SECONDS - max(0, self.deadline - time.monotonic())
        self.receipt["limits"] = {
            "absolute_seconds": SECONDS, "pinned_files": FILE_COUNT, "tool_bytes": TOTAL_LIMIT,
            "native_commands": COMMAND_COUNT, "stdout_bytes": STDOUT_LIMIT,
            "stderr_bytes": STDERR_LIMIT, "aggregate_capture_bytes": RECEIPT_LIMIT,
        }
        self.custody()
        self.write("inventory.private.json", json.dumps(self.receipt, sort_keys=True).encode())
        names = sorted(path.name for path in self.directory.iterdir())
        require(len(names) <= 4096, "retention-budget")
        files = {}
        captured = {}
        wanted = {"inventory.private.json"}
        for number, command in enumerate(self.commands, 1):
            if command["operation"] in PROJECTION_OPERATIONS:
                wanted.update("command-%d.%s" % (number, suffix) for suffix in
                              ("observed.private.json", "stdout.private", "stderr.private"))
        for name in names:
            path, info, raw = self.read(str(self.directory / name), os.geteuid(), RECEIPT_LIMIT)
            require(stat.S_IMODE(info.st_mode) == 0o600, "custody")
            files[name] = {"identity": identity(info), "sha256": hashlib.sha256(raw).hexdigest(),
                           "bytes": len(raw)}
            if name in wanted:
                captured[name] = raw
        for number, command in enumerate(self.commands, 1):
            for label in ("stdout", "stderr"):
                name = "command-%d.%s.private" % (number, label)
                if name not in files or label + "_identity" not in command:
                    require(reason is not None, "retention")
                    continue
                observed = files[name]
                require(all(observed["identity"][key] == value
                            for key, value in command[label + "_identity"].items()), "retention")
                if label + "_sha256" in command:
                    require(observed["sha256"] == command[label + "_sha256"]
                            and observed["bytes"] == command[label + "_bytes"], "retention")
        raw_index = json.dumps(files, sort_keys=True).encode()
        self.write("retained.private.json", raw_index)
        _, _, observed_index = self.read(str(self.directory / "retained.private.json"),
                                        os.geteuid(), RECEIPT_LIMIT)
        require(observed_index == raw_index, "retention")
        self.projection_capture = {"retained": observed_index, "captured": captured}


def main(argv=None, retained=None):
    argv = sys.argv if argv is None else argv
    inventory = None
    reason = None
    try:
        require(len(argv) == 2 and argv[1] in PHP_PROFILES, "unsafe-input")
        require(sys.platform == "linux" and os.getuid() == os.geteuid() > 0, "platform")
        inventory = Inventory(argv[1])
        os.umask(0o077)
        inventory.reserve(os.environ.get("WSTM_PREREQUISITE_ROOT"))
        for filename in SOURCE_NAMES.values():
            inventory.pin_source(str(Path(__file__).with_name(filename)))
        inventory.collect()
    except Refusal as error:
        reason = str(error)
    except Exception:
        reason = "inventory-io"
    try:
        if inventory is not None and inventory.directory is not None:
            inventory.finish(reason)
            if reason is None:
                inventory.check()
            if retained is not None:
                retained.append(inventory)
    except Exception:
        reason = "retention"
    # No exception, command bytes, environment, path or private receipt is public.
    if reason is not None and reason not in REASONS:
        reason = "inventory-io"
    if reason is None:
        print("WSTM prerequisite inventory retained; no launcher, transport or HOST admission executed.")
    else:
        print("WSTM prerequisite inventory refused: " + reason + ".")
    return 0 if reason is None else 78


if __name__ == "__main__":
    sys.exit(main())
