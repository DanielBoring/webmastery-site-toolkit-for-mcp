"""Original capture for the closed signed-PHP SYSTEM installation interval."""

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import selectors
import subprocess
import time


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


POLICY = load("host_php_apt_policy", "host-php-apt-policy.py")
GUARD = load("historical_php_permission_guard", "provision-php82-permissions.py")
BASE = GUARD.BASE
require = BASE.require

SETUP_SECONDS = 600
COMMANDS = 512
ROOT_LAUNCHES = 16
STDOUT = 131072
STDERR = 32768
STREAMS = 8388608
RECORDS = 16777216


class Capture(GUARD.Provision):
    def __init__(self, version):
        start = time.monotonic()
        super().__init__(version)
        self.deadline = start + SETUP_SECONDS
        self.root_launches = 0
        self.root_cursor = 0
        self.record_bytes = 0
        self.lock = []
        self.protected_packages = set()
        self.setup_ready = False
        self.root_ticket = None
        self.receipt.update(kind=POLICY.MODE, setup_deadline=self.deadline,
                            accepted_root_operations=[], root_cleanup_certified=False)

    def write(self, name, raw):
        if name.endswith((".json", ".sha256.private")):
            require(self.record_bytes + len(raw) <= RECORDS, "retention-budget")
            self.record_bytes += len(raw)
        return super().write(name, raw)

    def retain_chunk(self, descriptor, raw, stream, buffers, binding):
        BASE.verify_original(stream, *binding)
        require(stream.write(raw) == len(raw), "retention")
        stream.flush()
        os.fsync(stream.fileno())
        BASE.verify_original(stream, *binding)
        buffers[descriptor].extend(raw)
        self.capture_bytes += len(raw)
        require(len(buffers[descriptor]) <= (STDOUT if descriptor == 1 else STDERR),
                "native-output")
        require(self.capture_bytes <= STREAMS, "retention-budget")

    def root(self, operation):
        self.admit_capture()
        require(self.setup_ready and self.root_cursor < len(POLICY.OPERATIONS)
                and operation == POLICY.OPERATIONS[self.root_cursor], "unsafe-input")
        require(self.root_launches < ROOT_LAUNCHES, "command-budget")
        lock = self.lock if operation in ("apt-download", "apt-install") else ()
        try:
            argv = POLICY.root_argv(operation, self.version, lock, self.protected_packages)
        except POLICY.PolicyError as error:
            raise BASE.Refusal(str(error)) from None
        stdin = POLICY.DESCRIPTOR if operation == "source-descriptor" else None
        # Reservation is conserved even when retention or launch subsequently fails.
        self.root_launches += 1
        self.root_ticket = (operation, argv)
        record, stdout, stderr = self.capture(operation, argv, stdin)
        require(set(("exit", "failure", "retention_failed", "stdout_eof", "stderr_eof"))
                <= set(record), "native-command")
        BASE.validate_native_result(record, stderr)
        require(type(record["exit"]) is int and record["failure"] is None
                and record["retention_failed"] is False
                and record["stdout_eof"] is True and record["stderr_eof"] is True,
                "native-command")
        if operation == "select-php":
            expected = ("update-alternatives: using /usr/bin/php" + self.version
                        + " to provide /usr/bin/php (php) in manual mode\n").encode("ascii")
            require(stdout in (b"", expected), "native-command")
        elif operation not in ("apt-update", "apt-download", "apt-install"):
            require(stdout == (POLICY.DESCRIPTOR if stdin is not None else b""), "native-command")
        self.root_cursor += 1
        self.receipt["accepted_root_operations"].append(operation)
        self.check()
        return record

    def admit_capture(self):
        pass

    def reserve_capture(self, operation, argv, stdin=None):
        self.admit_capture()
        self.check()
        require(len(self.commands) < COMMANDS, "command-budget")
        require(type(argv) is tuple and argv and all(type(x) is str for x in argv),
                "unsafe-input")
        privileged = operation in POLICY.OPERATIONS
        if privileged:
            require(self.setup_ready and self.root_cursor < len(POLICY.OPERATIONS)
                    and operation == POLICY.OPERATIONS[self.root_cursor], "unsafe-input")
            expected = POLICY.root_argv(
                operation, self.version,
                self.lock if operation in ("apt-download", "apt-install") else (),
                self.protected_packages)
            require(argv == expected, "unsafe-input")
            require(self.root_ticket == (operation, argv), "unsafe-input")
            self.root_ticket = None
        else:
            require(operation in ("key-inspection", "signature-original", "signature-selected",
                                  "apt-config", "apt-simulation", "binary-stanza",
                                  "source-stanza", "deb-control", "metadata-fetch",
                                  "source-artifact-fetch", "deb-extract", "deb-list",
                                  "ca-owner-batch", "owner", "package", "elf"), "unsafe-input")
            if operation == "elf":
                executables = ("/usr/bin/readelf",)
            elif operation in ("ca-owner-batch", "owner", "package"):
                executables = ("/usr/bin/dpkg-query",)
            else:
                executables = ("/usr/bin/gpg", "/usr/bin/gpgv", "/usr/bin/apt-config",
                               "/usr/bin/apt-get", "/usr/bin/apt-cache",
                               "/usr/bin/dpkg-deb", "/usr/bin/curl")
            require(argv[0] in executables, "unsafe-input")
            require(stdin is None, "unsafe-input")
            if operation == "ca-owner-batch":
                require(argv == getattr(self, "ca_owner_ticket", None)
                        and argv[:2] == ("/usr/bin/dpkg-query", "-S")
                        and 1 <= len(argv) - 2 <= 32, "unsafe-input")
                self.ca_owner_ticket = None
            elif operation in ("owner", "package", "elf"):
                require(getattr(self, "query_ticket", None) == (operation, argv), "unsafe-input")
                self.query_ticket = None
        require(stdin is None or (operation == "source-descriptor"
                                 and stdin == POLICY.DESCRIPTOR), "unsafe-input")
        self.system(argv[0])
        number = len(self.commands) + 1
        diagnostic = (getattr(self, "gpg_diagnostic_active", False)
                      or (operation == "owner" and getattr(self, "_preflight_context", None)
                          == ("tool-origin", "gpg")))
        prefix = ("gpg-diagnostic-command-" if diagnostic else "command-") + str(number)
        record = {"operation": operation, "argv": list(argv),
                  "environment": {"PATH": "/usr/bin:/bin", "LC_ALL": "C"},
                  "exit": None, "stdout_eof": False, "stderr_eof": False,
                  "failure": None, "producer_pid": None, "wait_owner": "original-direct-child",
                  "root_cleanup_certified": False, "privileged": privileged,
                  "source_sha256": self.receipt.get("producer_sources", {}),
                  "stdout_bytes": 0, "stderr_bytes": 0}
        if diagnostic:
            require(not privileged and operation in ("owner", "package", "elf"), "unsafe-input")
            record["capture_prefix"] = prefix
        record["requested_child_umask"] = (
            0o022 if privileged and operation in
            ("key-download", "key-dearmor", "source-descriptor") else None)
        self.commands.append(record)
        self.write(prefix + ".intent.private.json", json.dumps(record, sort_keys=True).encode())
        return record

    def capture(self, operation, argv, stdin=None):
        """Only callers with a closed argv policy may use this non-public primitive."""
        record = self.reserve_capture(operation, argv, stdin)
        privileged = record["privileged"]
        prefix = record.get("capture_prefix", "command-%d" % len(self.commands))
        streams, pipes, buffers = {}, {}, {1: bytearray(), 2: bytearray()}
        process = input_stream = None
        selector = selectors.DefaultSelector()
        try:
            if stdin is not None:
                self.write(prefix + ".stdin.private", stdin)
                path = self.directory / (prefix + ".stdin.private")
                _, info, observed = self.read(str(path), os.geteuid(), len(stdin))
                require(observed == stdin, "retention")
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
                input_stream = os.fdopen(fd, "rb")
                require(BASE.identity(os.fstat(fd)) == BASE.identity(info), "retention")
                record["stdin_sha256"] = hashlib.sha256(observed).hexdigest()
            for fd, label in ((1, "stdout"), (2, "stderr")):
                streams[fd] = self.original(prefix + "." + label + ".private")
                record[label + "_identity"] = BASE.original_identity(os.fstat(streams[fd].fileno()))
                streams[fd].flush()
                os.fsync(streams[fd].fileno())
            self.write(prefix + ".reserved.private.json", json.dumps(record, sort_keys=True).encode())
            previous_mask = None
            try:
                if record["requested_child_umask"] is not None:
                    previous_mask = os.umask(record["requested_child_umask"])
                self.check()
                process = subprocess.Popen(
                    argv, stdin=input_stream if input_stream is not None else subprocess.DEVNULL,
                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd="/",
                    env=record["environment"], close_fds=True)
            finally:
                if previous_mask is not None:
                    os.umask(previous_mask)
            record["producer_pid"] = process.pid
            for fd, pipe in ((1, process.stdout), (2, process.stderr)):
                pipes[fd] = pipe
                os.set_blocking(pipe.fileno(), False)
                selector.register(pipe, selectors.EVENT_READ, fd)
            while selector.get_map() or record["exit"] is None:
                self.check()
                for key, _ in selector.select(min(0.05, max(0, self.deadline - time.monotonic()))):
                    fd = key.data
                    limit = STDOUT if fd == 1 else STDERR
                    raw = os.read(key.fileobj.fileno(), min(8192, limit + 1 - len(buffers[fd])))
                    label = "stdout" if fd == 1 else "stderr"
                    if not raw:
                        record[label + "_eof"] = True
                        selector.unregister(key.fileobj)
                    else:
                        self.retain_chunk(fd, raw, streams[fd], buffers,
                                          (self.directory / (prefix + "." + label + ".private"),
                                           record[label + "_identity"]))
                record["exit"] = process.poll()
        except BaseException:
            record["failure"] = "incomplete"
            raise
        finally:
            selector.close()
            if process is not None:
                record["exit"] = process.poll()
            for pipe in pipes.values():
                pipe.close()
            if input_stream is not None:
                input_stream.close()
            retention_failed = False
            for fd, stream in streams.items():
                label = "stdout" if fd == 1 else "stderr"
                record[label + "_bytes"] = len(buffers[fd])
                record[label + "_sha256"] = hashlib.sha256(buffers[fd]).hexdigest()
                try:
                    stream.flush()
                    os.fsync(stream.fileno())
                    _, info, raw = self.read(
                        str(self.directory / (prefix + "." + label + ".private")),
                        os.geteuid(), STDOUT if fd == 1 else STDERR)
                    require(BASE.original_identity(info) == record[label + "_identity"]
                            and raw == bytes(buffers[fd]), "retention")
                except Exception:
                    retention_failed = True
                finally:
                    stream.close()
            record["retention_failed"] = retention_failed
            # No signal, detach, retry, drain deadline or privileged-child teardown claim.
            self.write(prefix + ".observed.private.json", json.dumps(record, sort_keys=True).encode())
            require(not retention_failed, "retention")
        self.check()
        return record, bytes(buffers[1]), bytes(buffers[2])
