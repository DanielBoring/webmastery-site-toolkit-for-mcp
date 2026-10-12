"""Private, read-only Docker discovery through the existing pidfd supervisor."""

import importlib.util
import hashlib
import errno
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent
MAX_REQUEST = 65536
MAX_DIRECTORY = 16 * 1024**2
QUERY_SECONDS = 10


class ObservedQueryFailure(RuntimeError):
    def __init__(self, code):
        super().__init__("Bounded admission query failed.")
        self.code = code


def executable_identity(info):
    return (info.st_dev, info.st_ino, info.st_uid, info.st_gid, info.st_mode,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def require(condition):
    if not condition:
        raise RuntimeError("Bounded admission query refused.")


def readonly(argv):
    """Closed discovery verbs; selectors/formats remain the caller's exact bytes."""
    require(isinstance(argv, list) and 2 <= len(argv) <= 64
            and all(isinstance(arg, str) and 0 < len(arg) <= 4096
                    and "\0" not in arg for arg in argv))
    if len(argv) == 3 and argv[1] == str(ROOT / "scripts/qa-runtime.php"):
        require(re.fullmatch(r"php(?:[0-9]+(?:\.[0-9]+)?)?", Path(argv[0]).name)
                and Path(argv[0]) == Path(argv[0]).resolve(strict=True)
                and argv[2] in ("shell", "frame", "package"))
        return
    require(argv[0] == str(Path("/usr/bin/docker").resolve(strict=True)))
    args = argv[1:]
    if args[:1] == ["--host"]:
        require(len(args) >= 3 and args[1] in
                ("unix:///run/docker.sock", "unix:///var/run/docker.sock"))
        args = args[2:]
    if args[:2] == ["context", "inspect"]:
        require(len(args) in (4, 5) and args[2:4] ==
                ["--format", "{{json .Endpoints.docker.Host}}"]
                and (len(args) == 4 or re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}", args[4])))
        return
    if args[:1] == ["compose"]:
        args = args[1:]
        require(args[:1] == ["--project-name"] and len(args) >= 3
                and re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,127}", args[1]))
        args = args[2:]
        if args[:1] == ["--file"]:
            require(len(args) >= 3 and args[1] ==
                    str(ROOT / "tests/fixtures/php80-floor/compose.yml"))
            args = args[2:]
        elif args[:1] == ["-f"]:
            require(args[:4] == ["-f", "docker-compose.yml", "-f", "docker-compose.release.yml"])
            args = args[4:]
        require(args in (["config", "--format", "json", "--no-env-resolution"],
                         ["ps", "--all", "--format", "json"]))
        return
    if args[:1] == ["info"]:
        require(len(args) == 3 and args[1] == "--format"
                and args[2] in ("{{json .ID}}", "{{json .DockerRootDir}}"))
        return
    if args[:1] == ["ps"]:
        require(len(args) == 6 and args[:3] == ["ps", "--all", "--filter"]
                and re.fullmatch(r"label=com.docker.compose.project=[a-z0-9][a-z0-9_-]{0,127}", args[3])
                and args[4:] == ["--format", '{"ID":"{{.ID}}"}'])
        return
    if args[:2] == ["image", "inspect"]:
        require(len(args) == 5 and args[2:4] ==
                ["--format", '{"id":{{json .Id}},"digests":{{json .RepoDigests}}}']
                and re.fullmatch(r"mysql:8\.0\.36@sha256:[a-f0-9]{64}", args[4]))
        return
    prefix = ["volume", "inspect"] if args[:2] == ["volume", "inspect"] else ["inspect"]
    offset = len(prefix)
    require(args[:offset] == prefix and len(args) == offset + 3
            and args[offset] == "--format")
    if offset == 1:
        require(args[offset + 1] in (
                '{"project":{{json (index .Config.Labels "com.docker.compose.project")}},"mounts":{{json .Mounts}}}',
                '{"id":{{json .Id}},"project":{{json (index .Config.Labels "com.docker.compose.project")}},"mounts":{{json .Mounts}},"service":{{json (index .Config.Labels "com.docker.compose.service")}},"oneoff":{{json (index .Config.Labels "com.docker.compose.oneoff")}},"image":{{json .Image}},"configured_image":{{json .Config.Image}}}')
                and re.fullmatch(r"[a-f0-9]{12,64}", args[-1]))
    else:
        require(args[offset + 1] ==
                '{"name":{{json .Name}},"driver":{{json .Driver}},"mountpoint":{{json .Mountpoint}},"project":{{json (index .Labels "com.docker.compose.project")}},"options":{{json .Options}}}'
                and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", args[-1]))


def private(path, directory=False):
    info = path.lstat()
    require(path == path.resolve(strict=True) and info.st_uid == os.getuid()
            and stat.S_IMODE(info.st_mode) == (0o700 if directory else 0o600)
            and (stat.S_ISDIR(info.st_mode) if directory else
                 stat.S_ISREG(info.st_mode) and info.st_nlink == 1))
    return info


def supervisor():
    source = ROOT / "tests/e2e/bounded-list-controller.py"
    require(source == source.resolve(strict=True))
    spec = importlib.util.spec_from_file_location("wstm108_query_supervisor", source)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def execute(request_path, announce=True):
    private(request_path)
    require(request_path.stat().st_size <= MAX_REQUEST)
    request = json.loads(request_path.read_bytes())
    require(isinstance(request, dict) and set(request) == {"argv", "cwd", "deadline_ns"})
    require(type(request["deadline_ns"]) is int)
    directory = request_path.parent
    private(directory, True)
    require(request["cwd"] == str(ROOT))
    readonly(request["argv"])
    binary = Path(request["argv"][0])
    identity = binary.stat()
    require(stat.S_ISREG(identity.st_mode) and identity.st_uid == 0
            and not identity.st_mode & 0o022 and identity.st_mode & 0o111)
    module = supervisor()
    class DescriptorPath(type(Path())):
        def open(self, mode="r", *args, **kwargs):
            descriptor = {str(directory / "stdout.log"): 10,
                          str(directory / "stderr.log"): 11}.get(str(self))
            if descriptor is not None and mode == "xb":
                info = private(Path(self))
                require(executable_identity(os.fstat(descriptor)) == executable_identity(info)
                        and info.st_size == 0 and os.lseek(descriptor, 0, os.SEEK_CUR) == 0)
                return os.fdopen(os.dup(descriptor), "wb")
            return super().open(mode, *args, **kwargs)

    # Only the two raw-stream opens use inherited originals. Every other
    # reservation still uses the unchanged supervisor's exclusive-create path.
    module.Path = DescriptorPath
    os.chdir(ROOT)

    def storage():
        private(directory, True)
        require(module.owned_bytes(directory) <= MAX_DIRECTORY)
        require(executable_identity(binary.stat()) == executable_identity(identity))

    deadline = min(request["deadline_ns"], time.monotonic_ns() + QUERY_SECONDS * 10**9)
    # This is a capture limit, not a fabricated owner storage grant. Existing
    # supervisor cleanup reservations and per-pipe limits remain unchanged.
    failure = None
    try:
        module.run_process(request["argv"], directory, deadline, os.environ.copy(), storage)
    except RuntimeError as error:
        failure = error
    for name in ("stdout.log", "stderr.log", "stdout.overflow.bin", "stderr.overflow.bin",
                 "terminal.json", "receipt.json"):
        path = directory / name
        require(path.exists())
        private(path)
        with path.open("rb") as original:
            os.fsync(original.fileno())
    storage()
    if failure is not None:
        receipt = module.original(directory / "receipt.json")
        code = receipt.get("exit_code")
        if receipt.get("root_exit_observed") is True and type(code) is int and 0 < code <= 255:
            raise ObservedQueryFailure(code) from failure
        raise failure
    if announce:
        print("WSTM108_QUERY_COMPLETE_V1", flush=True)


def runtime_interpreter():
    if "WSTM108_HOST_PHP" in os.environ:
        original = os.environ["WSTM108_HOST_PHP"]
        path = Path(original)
        require(path.is_absolute() and original == str(path.resolve(strict=True)))
        return path
    selected = shutil.which("php")
    require(selected is not None)
    return Path(selected).resolve(strict=True)


def runtime_selection(mode):
    """Retain finite original selection/refusal streams before any fixture action."""
    require(mode in ("shell", "package"))
    root = Path(os.environ["WSTM108_HOST_AUTHORITY_ROOT"])
    private(root, True)
    php = runtime_interpreter()
    argv = [str(php), str(ROOT / "scripts/qa-runtime.php"), mode]
    readonly(argv)
    mask = os.umask(0o077)
    try:
        directory = Path(tempfile.mkdtemp(prefix="runtime-selection-", dir=root))
        private(directory, True)
        request = directory / "request.private.json"
        request.write_text(json.dumps({"argv": argv, "cwd": str(ROOT),
                                      "deadline_ns": time.monotonic_ns() + int(QUERY_SECONDS * 10**9)}))
        request_identity = executable_identity(private(request))
        request_hash = hashlib.sha256(request.read_bytes()).hexdigest()
        handles = []
        originals = {}
        saved = {}
        try:
            for fd, stream in ((10, "stdout"), (11, "stderr")):
                try:
                    saved[fd] = os.dup(fd)
                except OSError as error:
                    require(error.errno == errno.EBADF)
                    saved[fd] = None
                    placeholder = os.open(os.devnull, os.O_RDWR)
                    os.dup2(placeholder, fd)
                    if placeholder != fd:
                        os.close(placeholder)
            for fd, stream in ((10, "stdout"), (11, "stderr")):
                handle = (directory / (stream + ".log")).open("x+b")
                handles.append(handle)
                os.dup2(handle.fileno(), fd)
                originals[stream] = executable_identity(os.fstat(fd))
            execute(request, announce=False)
            require(executable_identity(private(request)) == request_identity
                    and hashlib.sha256(request.read_bytes()).hexdigest() == request_hash)
            receipt = json.loads((directory / "receipt.json").read_bytes())
            require(receipt["argv"] == argv and receipt["exit_code"] == 0
                    and receipt["root_exit_observed"] is True
                    and receipt["stdout_eof"] is True and receipt["stderr_eof"] is True
                    and receipt["truncated"] is False and receipt["failure"] is None
                    and receipt["secondary_errors"] == [])
            output = {}
            for stream in ("stdout", "stderr"):
                path = directory / (stream + ".log")
                info = private(path)
                # Writes change size/times, not the reserved original inode/mode.
                require(executable_identity(info)[:6] == originals[stream][:6])
                output[stream] = path.read_bytes()
                require(len(output[stream]) == receipt[stream + "_bytes"]
                        and hashlib.sha256(output[stream]).hexdigest() == receipt[stream + "_sha256"]
                        and receipt[stream + "_diagnostic_bytes"] == 0
                        and receipt[stream + "_observed_not_retained"] == 0)
            require(output["stderr"] == b"" and mode == "shell")
            terminal(1, output["stdout"])
        finally:
            for fd, original in saved.items():
                os.close(fd)
                if original is not None:
                    os.dup2(original, fd)
                    os.close(original)
            for handle in handles:
                handle.close()
    finally:
        os.umask(mask)


def terminal(descriptor, record):
    while record:
        written = os.write(descriptor, record)
        if written <= 0:
            raise OSError("Diagnostic write refused.")
        record = record[written:]


def refused(code):
    try:
        terminal(2, b"WSTM108_QUERY_REFUSED_V1\n")
    except OSError:
        try:
            terminal(1, b"WSTM108_QUERY_DIAGNOSTIC_IO_REFUSED_V1\n")
        except OSError:
            # Neither channel is writable; the observed exit remains failure.
            os._exit(code)
    os._exit(code)


if __name__ == "__main__":
    try:
        if len(sys.argv) == 3 and sys.argv[1] == "--runtime-selection":
            runtime_selection(sys.argv[2])
        else:
            require(len(sys.argv) == 2)
            execute(Path(sys.argv[1]))
    except BaseException as error:
        # The supervisor retains original failures/streams privately. This
        # terminal is closed, unsuccessful, and carries no exception or argv.
        refused(error.code if isinstance(error, ObservedQueryFailure) else 78)
