"""Run exactly ten synthetic Linux controller components, never a benchmark."""

import argparse
import contextlib
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import traceback
import unittest
import zipfile


METHODS = (
    "test_both_binary_pipes_are_complete_and_original",
    "test_deadline_kills_owned_root_and_retains_failure",
    "test_output_overflow_keeps_prefix_and_bounded_diagnostic",
    "test_no_launch_after_preflight_deadline_or_storage_failure",
    "test_storage_failure_is_not_success_shaped",
    "test_nonzero_exit_preserves_both_streams",
    "test_closed_pipe_descendant_is_terminated_with_the_owned_group",
    "test_exclusive_records_cannot_overwrite_an_earlier_failure",
    "test_external_receipt_keeps_original_typed_bytes_and_refuses_drift",
    "test_external_receipt_size_and_link_are_rejected_before_retention",
)
EXPECTED = sorted("controller_components.BoundedControllerTest." + name for name in METHODS)
PROCESS_FILES = {"stdout.log", "stderr.log", "stdout.overflow.bin", "stderr.overflow.bin",
                 "terminal.json", "receipt.json"}
FILES = {name: PROCESS_FILES for name in METHODS[:7]}
FILES.update({
    METHODS[7]: {"original.json"},
    METHODS[8]: {"approved.json", "retained.original.json"},
    METHODS[9]: {"oversized.json", "linked.json"},
})
MAX_FILE = 8 * 1024**2
MAX_TOTAL = 64 * 1024**2


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def publish(path, value):
    with path.open("x", encoding="utf-8") as output:
        json.dump(value, output, ensure_ascii=True, indent=2)
        output.write("\n")


def private_directory(path):
    info = path.lstat()
    require(stat.S_ISDIR(info.st_mode) and info.st_uid == os.getuid()
            and stat.S_IMODE(info.st_mode) == 0o700, f"Not a private owned directory: {path}")


def precheck():
    require(sys.version_info >= (3, 11) and sys.platform.startswith("linux"),
            "Python >=3.11 on Linux is required; skipping is not acceptance.")
    require(all(hasattr(os, name) for name in
                ("pidfd_open", "waitid", "P_PIDFD", "WNOWAIT", "WEXITED", "WNOHANG")),
            "pidfd/waitid APIs are required.")
    descriptor = os.pidfd_open(os.getpid())
    try:
        try:
            os.waitid(os.P_PIDFD, descriptor, os.WEXITED | os.WNOWAIT | os.WNOHANG)
        except ChildProcessError:
            # Our own process is not our child. ECHILD proves P_PIDFD was accepted.
            pass
        else:
            raise RuntimeError("Self-pidfd waitid did not report ECHILD.")
    finally:
        os.close(descriptor)


class ObservedResult(unittest.TextTestResult):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.started = []
        self.finished = []
        self.successes = []

    def startTest(self, test):
        self.started.append(test.id())
        super().startTest(test)

    def stopTest(self, test):
        self.finished.append(test.id())
        super().stopTest(test)

    def addSuccess(self, test):
        self.successes.append(test.id())
        super().addSuccess(test)


def result_record(result):
    record = {"testsRun": 0, "started": [], "finished": [], "successes": [],
              "failures": [], "errors": [], "skipped": [], "expectedFailures": [],
              "unexpectedSuccesses": []}
    if result is not None:
        for key in ("testsRun", "started", "finished", "successes"):
            record[key] = getattr(result, key)
        for key in ("failures", "errors", "skipped", "expectedFailures"):
            record[key] = [{"id": test.id(), "detail": detail}
                           for test, detail in getattr(result, key)]
        record["unexpectedSuccesses"] = [test.id() for test in result.unexpectedSuccesses]
    return record


def require_complete(record):
    require(record["testsRun"] == 10
            and all(sorted(record[key]) == EXPECTED for key in ("started", "finished", "successes"))
            and all(not record[key] for key in
                    ("failures", "errors", "skipped", "expectedFailures", "unexpectedSuccesses")),
            "Exactly ten expected started, finished and passing tests are required, without skips.")


def terminal_object(pairs):
    record = {}
    for key, value in pairs:
        require(key not in record, "Duplicate terminal ledger field.")
        record[key] = value
    return record


def validate_terminal(data):
    """A complete failed terminal is retainable; malformed evidence is not."""
    try:
        record = json.loads(data, object_pairs_hook=terminal_object)
    except (json.JSONDecodeError, UnicodeDecodeError) as error:
        raise RuntimeError("Invalid terminal ledger JSON.") from error
    fields = set(result_record(None)) | {"accepted", "failure", "expected", "scope"}
    require(type(record) is dict and set(record) == fields,
            "Terminal ledger fields are missing or unexpected.")
    require(record["scope"] == "synthetic controller components" and record["expected"] == EXPECTED,
            "Terminal ledger scope or expected test IDs differ.")
    require(type(record["accepted"]) is bool and type(record["testsRun"]) is int
            and 0 <= record["testsRun"] <= 10, "Invalid terminal ledger acceptance/count types.")
    for key in ("started", "finished", "successes", "unexpectedSuccesses"):
        values = record[key]
        require(type(values) is list and all(type(value) is str and value in EXPECTED for value in values)
                and len(values) == len(set(values)), f"Invalid terminal ledger {key} IDs.")
    require(record["testsRun"] == len(record["started"])
            and set(record["successes"]) <= set(record["finished"]) <= set(record["started"])
            and set(record["unexpectedSuccesses"]) <= set(record["finished"]),
            "Terminal ledger execution counts or ordering are inconsistent.")
    for key in ("failures", "errors", "skipped", "expectedFailures"):
        require(type(record[key]) is list and all(
            type(row) is dict and set(row) == {"id", "detail"}
            and type(row["id"]) is str and bool(row["id"]) and type(row["detail"]) is str
            for row in record[key]), f"Invalid terminal ledger {key} details.")
    if record["accepted"]:
        require(record["failure"] is None, "Accepted terminal ledger contains a failure.")
        require_complete(record)
    else:
        require(type(record["failure"]) is str and bool(record["failure"]),
                "Failed terminal ledger lacks its failure diagnostic.")
    return record


def run(root):
    require(root.is_absolute() and root.parent.resolve(strict=True) == root.parent,
            "An absolute, unlinked parent is required.")
    root.mkdir(mode=0o700)  # Exclusive: an existing directory or dangling link is an error.
    control = root / "control"
    control.mkdir(mode=0o700)
    captures = root / "synthetic"
    result = None
    accepted = False
    publish(control / "startup.json", {
        "scope": "synthetic components only; not WordPress/benchmark/custody acceptance",
        "python": sys.version, "executable": sys.executable, "platform": sys.platform,
        "expected": EXPECTED, "captures": str(captures),
    })
    try:
        with (control / "unittest.log").open("x", encoding="utf-8") as log:
            with contextlib.redirect_stdout(log), contextlib.redirect_stderr(log):
                precheck()
                require(not os.path.lexists(captures), "Test-owned capture child must not exist.")
                os.environ["WSTM_CONTROLLER_TEST_ARTIFACTS"] = str(captures)
                source = Path(__file__).resolve().parents[1] / "tests" / "unit" / "test_bounded_controller.py"
                spec = importlib.util.spec_from_file_location("controller_components", source)
                module = importlib.util.module_from_spec(spec)
                spec.loader.exec_module(module)
                suite = unittest.defaultTestLoader.loadTestsFromTestCase(module.BoundedControllerTest)
                require(sorted(test.id() for test in suite) == EXPECTED,
                        "Discovered controller test IDs differ from the explicit ten.")
                # Keep the result even if suite setup or runner execution raises.
                runner = unittest.TextTestRunner(stream=log, verbosity=2, resultclass=ObservedResult)
                result = ObservedResult(runner.stream, True, 2)
                runner.resultclass = lambda *_args, **_kwargs: result
                runner.run(suite)
                require_complete(result_record(result))
                accepted = True
    finally:
        failure = traceback.format_exc() if sys.exc_info()[0] is not None else None
        record = result_record(result)
        record.update({"accepted": accepted, "failure": failure, "expected": EXPECTED,
                       "scope": "synthetic controller components"})
        publish(control / "result.json", record)
        print(json.dumps(record, ensure_ascii=True))


def retained_entries(root):
    private_directory(root)
    require(root.resolve(strict=True) == root, "Linked capture ancestry.")
    require({entry.name for entry in root.iterdir()} <= {"control", "synthetic"},
            "Unexpected capture-root entry; refusing broad retention.")
    control = root / "control"
    private_directory(control)
    for entry in sorted(control.iterdir()):
        require(entry.name in {"startup.json", "unittest.log", "result.json"},
                "Unexpected control output.")
        yield entry
    captures = root / "synthetic"
    if os.path.lexists(captures):
        private_directory(captures)
        for directory in sorted(captures.iterdir()):
            require(directory.name in FILES, "Unexpected component directory.")
            private_directory(directory)
            for entry in sorted(directory.iterdir()):
                require(entry.name in FILES[directory.name], "Unexpected component output.")
                yield entry


def retain(root):
    entries = list(retained_entries(root))
    require(len(entries) <= 128, "Synthetic retention file-count limit exceeded.")
    output = root / "upload"
    output.mkdir(mode=0o700)
    inventory = []
    total = 0
    complete = False
    terminal = None
    try:
        with zipfile.ZipFile(output / "synthetic-components.zip", "x", zipfile.ZIP_DEFLATED) as bundle:
            for path in entries:
                relative = path.relative_to(root).as_posix()
                info = path.lstat()
                if stat.S_ISLNK(info.st_mode):
                    require(relative == "synthetic/" + METHODS[9] + "/linked.json",
                            "Unexpected symlink; refusing to follow it.")
                    inventory.append({"path": relative, "type": "symlink",
                                      "target": os.readlink(path), "content_retained": False})
                    continue
                require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                        and info.st_uid == os.getuid() and stat.S_IMODE(info.st_mode) & 0o077 == 0
                        and info.st_size <= MAX_FILE,
                        "Unsupported, unowned, hardlinked or oversized synthetic output.")
                descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
                with os.fdopen(descriptor, "rb") as stream:
                    opened = os.fstat(stream.fileno())
                    require((opened.st_dev, opened.st_ino, opened.st_size, opened.st_mtime_ns)
                            == (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns),
                            "Output identity changed before retention.")
                    data = stream.read(MAX_FILE + 1)
                    after = os.fstat(stream.fileno())
                require(len(data) == info.st_size
                        and (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns,
                             after.st_ctime_ns, after.st_nlink, after.st_mode)
                        == (opened.st_dev, opened.st_ino, opened.st_size, opened.st_mtime_ns,
                            opened.st_ctime_ns, opened.st_nlink, opened.st_mode),
                        "Output changed during retention.")
                total += len(data)
                require(total <= MAX_TOTAL, "Synthetic retention byte limit exceeded.")
                bundle.writestr(relative, data)
                if relative == "control/result.json":
                    terminal = data
                inventory.append({"path": relative, "type": "regular", "bytes": len(data),
                                  "sha256": hashlib.sha256(data).hexdigest()})
        require(terminal is not None,
                "Missing complete test outcome; available originals retained as failed evidence.")
        validate_terminal(terminal)
        complete = True
    finally:
        publish(output / "retention.json", {
            "scope": "synthetic components only", "complete": complete,
            "failure": traceback.format_exc() if sys.exc_info()[0] is not None else None,
            "regular_bytes": total, "entries": inventory,
        })


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("run", "retain"))
    parser.add_argument("--root", type=Path, required=True)
    arguments = parser.parse_args()
    os.umask(0o077)
    {"run": run, "retain": retain}[arguments.action](arguments.root)
