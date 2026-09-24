"""Owned Linux-only #121 process control; never provisions WordPress or Docker.

Runtime use requires a separately granted environment with accessible, exclusively
owned storage roots and an already established durable private artifact mount.
There is deliberately no Windows, polling, public-upload, or unbounded fallback.
"""

from __future__ import annotations

import hashlib
import json
import os
import selectors
import signal
import subprocess
import sys
import time
from pathlib import Path


STREAM_BYTES = 4 * 1024 * 1024
DIAGNOSTIC_BYTES = 64 * 1024
NAMESPACE_BYTES = 8 * 1024**3
NATIVE_SECONDS = 18000
WORKER_SECONDS = 60
FINAL_RECORD_RESERVE = 34 * 1024 * 1024
PHASE_SECONDS = {
    "seed": 1020,
    "reference": 300,
    "projection": 300,
    "cache-faults": 300,
    "snapshot-before": 120,
    "traversal": 14400,
    "snapshot-after": 120,
    "cleanup": 600,
    "cleanup-readback": 120,
}
SHARDS = ("posts", "pages", "cpt", "media", "seo", "readability", "orphans")
CONTROLLER_WRITTEN = 0
CONTROL_RECORD_BYTES = 1024 * 1024
PROCESS_CAPTURE_RESERVE = 2 * (STREAM_BYTES + DIAGNOSTIC_BYTES + CONTROL_RECORD_BYTES)
CLEANUP_RESERVE = 64 * 1024 * 1024


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def unsigned(value, name):
    require(type(value) is int and value >= 0, f"Invalid nonnegative integer: {name}")
    return value


def digest(path):
    with Path(path).open("rb") as source:
        return hashlib.file_digest(source, "sha256").hexdigest()


def original(path):
    path = Path(path)
    require(path.is_file() and not path.is_symlink(), f"Missing/linked original: {path}")
    require(path.stat().st_size <= 32 * 1024**2, "Original record exceeds 32 MiB.")
    with path.open(encoding="utf-8") as source:
        return json.load(source)


def publish(path, value, maximum=CONTROL_RECORD_BYTES):
    """Exclusive records: retain earlier failures rather than overwriting them."""
    global CONTROLLER_WRITTEN
    data = json.dumps(value, ensure_ascii=True, indent=2).encode("utf-8")
    require(CONTROL_RECORD_BYTES <= maximum <= 32 * 1024**2 and len(data) <= maximum,
            "Controller record exceeds its reserved byte bound.")
    with Path(path).open("xb") as target:
        target.write(data)
        target.flush()
    CONTROLLER_WRITTEN += len(data)

def retain_receipt(binding, destination):
    """Retain bounded original receipt bytes, not a re-encoded JSON substitute."""
    global CONTROLLER_WRITTEN
    source = Path(binding["path"])
    require(source.is_file() and not source.is_symlink(), "Missing/linked receipt original.")
    with source.open("rb") as stream:
        data = stream.read(CONTROL_RECORD_BYTES + 1)
    require(len(data) <= CONTROL_RECORD_BYTES
            and hashlib.sha256(data).hexdigest() == binding["sha256"], "Receipt size/identity drift.")
    with Path(destination).open("xb") as target:
        target.write(data)
        target.flush()
    CONTROLLER_WRITTEN += len(data)


def owned_bytes(root):
    root = Path(root)
    require(root.is_dir() and not root.is_symlink(), "Owned storage root is absent/linked.")
    require(root != Path(root.anchor), "A filesystem root is not an owned fixture root.")
    total = 0
    for entry in root.iterdir():
        require(not entry.is_symlink(), f"Linked storage entry is not owned: {entry}")
        total += owned_bytes(entry) if entry.is_dir() else entry.stat().st_size
    return total


def custody_files(evidence, control):
    files = []
    for area, root in (("data", Path(evidence)), ("control", Path(control))):
        for path in sorted(root.rglob("*")):
            require(not path.is_symlink(), "Linked artifact cannot enter custody.")
            if path.is_dir():
                continue
            require(path.is_file(), "Unsupported artifact kind.")
            relative = path.relative_to(root).as_posix()
            if area == "control" and relative == "custody.json":
                continue
            files.append({"area": area, "path": relative, "bytes": path.stat().st_size,
                          "sha256": digest(path)})
    return files


def run_process(argv, directory, deadline_ns, env, storage_check):
    """One owned session/group with pidfd exit notification and bounded raw pipes.

    Keep the leader unreaped until shutdown: its reserved PID plus new session
    binds group termination, including descendants, without a process-name scan.
    Synchronous filesystem latency is not falsely described as a hard deadline.
    """
    global CONTROLLER_WRITTEN
    require(sys.version_info >= (3, 11) and sys.platform.startswith("linux") and hasattr(os, "pidfd_open")
            and hasattr(os, "P_PIDFD"), "Linux pidfd/waitid support is required.")
    directory = Path(directory)
    receipt = {
        "argv": argv, "start_ns": time.monotonic_ns(), "end_ns": None,
        "pid": None, "exit_code": None, "root_exit_observed": False,
        "stdout_eof": False, "stderr_eof": False, "truncated": False,
        "failure": None, "secondary_errors": [], "kill_requested": False,
        "filesystem_deadline": "cooperative checks; blocking filesystem latency is not bounded",
    }
    process = None
    pidfd = None
    streams = {}
    selector = selectors.DefaultSelector()
    exit_observed = False
    retained = 0

    def receive(key, finalizing=False):
        nonlocal retained
        global CONTROLLER_WRITTEN
        stream = streams[key.data]
        data = os.read(key.fileobj.fileno(), 65536)
        if not data:
            receipt[key.data + "_eof"] = True
            selector.unregister(key.fileobj)
            return
        stream["received"] += len(data)
        keep = min(len(data), STREAM_BYTES - stream["bytes"])
        stream["file"].write(data[:keep])
        stream["bytes"] += keep
        retained += keep
        CONTROLLER_WRITTEN += keep
        if keep != len(data):
            receipt["truncated"] = True
            extra = data[keep:][:DIAGNOSTIC_BYTES - stream["extra_bytes"]]
            stream["extra"].write(extra)
            stream["extra_bytes"] += len(extra)
            stream["not_retained"] += len(data) - keep - len(extra)
            retained += len(extra)
            CONTROLLER_WRITTEN += len(extra)
            if not finalizing:
                raise RuntimeError(f"Output cap exceeded: {key.data}; original prefix retained.")

    def observe_exit():
        nonlocal exit_observed
        status = os.waitid(os.P_PIDFD, pidfd, os.WEXITED | os.WNOWAIT)
        receipt["root_exit_observed"] = True
        receipt["exit_code"] = status.si_status if status.si_code == os.CLD_EXITED else -status.si_status
        exit_observed = True
        selector.unregister(pidfd)

    def close_resource(label, callback):
        try:
            callback()
        except OSError as error:
            receipt["secondary_errors"].append(f"{label}: {error}")

    try:
        for name in ("stdout", "stderr"):
            streams[name] = {
                "file": (directory / f"{name}.log").open("xb"),
                "extra": None, "bytes": 0, "extra_bytes": 0,
                "received": 0, "not_retained": 0, "pipe": None,
            }
            streams[name]["extra"] = (directory / f"{name}.overflow.bin").open("xb")
        storage_check()
        require(time.monotonic_ns() < deadline_ns, "Deadline expired before process launch.")
        # The child ceiling includes its append-only reservation journal. This
        # separate reservation covers both pipes and the two immutable receipts.
        launch_env = env.copy()
        ceiling = NAMESPACE_BYTES - CONTROLLER_WRITTEN - PROCESS_CAPTURE_RESERVE - FINAL_RECORD_RESERVE
        if env.get("WSTM_BOUNDED_CLEANUP_PHASE") != "1":
            ceiling -= CLEANUP_RESERVE
        require(ceiling > 0, "No reserved namespace capacity for this process.")
        launch_env["WSTM_BOUNDED_PHP_BYTE_CEILING"] = str(ceiling)
        process = subprocess.Popen(
            argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, env=launch_env, start_new_session=True,
        )
        receipt["pid"] = process.pid
        pidfd = os.pidfd_open(process.pid)
        selector.register(pidfd, selectors.EVENT_READ, "exit")
        for name, pipe in (("stdout", process.stdout), ("stderr", process.stderr)):
            streams[name]["pipe"] = pipe
            os.set_blocking(pipe.fileno(), False)
            selector.register(pipe, selectors.EVENT_READ, name)
        while selector.get_map():
            remaining = (deadline_ns - time.monotonic_ns()) / 1e9
            require(remaining > 0, "Owned process deadline exceeded.")
            events = selector.select(remaining)
            require(events, "Owned process deadline exceeded.")
            for key, _ in events:
                if key.data == "exit":
                    # WNOWAIT deliberately prevents leader-PID reuse before killpg.
                    observe_exit()
                    continue
                receive(key)
            storage_check()
        require(exit_observed, "Missing owned root exit observation.")
    except BaseException as error:
        receipt["failure"] = f"{type(error).__name__}: {error}"
    finally:
        if process is not None:
            # Even a normally exited leader can leave children after closing
            # inherited pipes. Its unreaped PID still identifies our own group.
            try:
                os.killpg(process.pid, signal.SIGKILL)
                receipt["kill_requested"] = True
            except ProcessLookupError:
                pass
            except OSError as error:
                receipt["secondary_errors"].append(f"group termination: {error}")
            shutdown_end = time.monotonic_ns() + 10 * 10**9
            try:
                while selector.get_map() and time.monotonic_ns() < shutdown_end:
                    events = selector.select(max(0, (shutdown_end - time.monotonic_ns()) / 1e9))
                    if not events:
                        break
                    for key, _ in events:
                        if key.data == "exit":
                            observe_exit()
                        else:
                            receive(key, True)
                if exit_observed:
                    # Already observed via pidfd/WNOWAIT; no timed-wait polling.
                    process.wait()
                    receipt["exit_code"] = process.returncode
                else:
                    receipt["secondary_errors"].append("Root termination is unproved after bounded shutdown.")
            except OSError as error:
                receipt["secondary_errors"].append(f"bounded shutdown: {error}")
            for name, stream in streams.items():
                if stream["pipe"] is not None:
                    close_resource(name + " pipe close", stream["pipe"].close)
        if pidfd is not None:
            close_resource("pidfd close", lambda: os.close(pidfd))
        close_resource("selector close", selector.close)
        for name, stream in streams.items():
            for handle in (stream["file"], stream["extra"]):
                if handle is not None:
                    try:
                        handle.flush()
                    except OSError as error:
                        receipt["secondary_errors"].append(f"{name} flush: {error}")
                    try:
                        handle.close()
                    except OSError as error:
                        receipt["secondary_errors"].append(f"{name} close: {error}")
            receipt[name + "_bytes"] = stream["bytes"]
            receipt[name + "_received"] = stream["received"]
            receipt[name + "_diagnostic_bytes"] = stream["extra_bytes"]
            receipt[name + "_observed_not_retained"] = stream["not_retained"]
        receipt["end_ns"] = time.monotonic_ns()
        # Retain terminal facts before hash or downstream verification failures.
        publish(directory / "terminal.json", receipt)
    for name in streams:
        receipt[name + "_sha256"] = digest(directory / f"{name}.log")
    publish(directory / "receipt.json", receipt)
    require(receipt["failure"] is None and not receipt["secondary_errors"]
            and receipt["exit_code"] == 0 and receipt["stdout_eof"]
            and receipt["stderr_eof"] and not receipt["truncated"],
            "Owned process failed; terminal/receipt and original partial captures retained.")
    return receipt, retained


def validate_environment(spec):
    """No provision/install or inferred custody. A grant must bind real paths."""
    require(spec["shard"] in SHARDS, "Unknown shard.")
    require(Path(spec["php"]).is_absolute() and Path(spec["wp_load"]).is_file(), "Explicit existing runtime paths required.")
    require(digest(spec["php"]) == spec["php_sha256"], "PHP binary drift.")
    require(digest(spec["wp_load"]) == spec["wp_load_sha256"], "WordPress entrypoint drift.")
    require(spec["namespace"].startswith("w121_") and len(spec["namespace"]) == 17
            and all(c in "0123456789abcdef" for c in spec["namespace"][5:]), "Invalid unique namespace.")
    # These owner-supplied originals must be independently accepted by the
    # coordinator; their identities are retained, not replaced by a boolean.
    for name in ("storage_receipt", "private_durability_receipt"):
        path = Path(spec[name]["path"])
        require(path.is_file() and not path.is_symlink() and path.stat().st_size <= CONTROL_RECORD_BYTES,
                f"Missing/linked/oversized {name}.")
        require(digest(spec[name]["path"]) == spec[name]["sha256"], f"Unbound {name}.")
    require(type(spec["owned_storage_roots"]) is list and spec["owned_storage_roots"], "No owned whole-job storage roots.")
    roots = [Path(root).resolve(strict=True) for root in spec["owned_storage_roots"]]
    for index, root in enumerate(roots):
        for other in roots[index + 1:]:
            require(root != other and root not in other.parents and other not in root.parents,
                    "Storage roots overlap; byte accounting would be false.")
    limit = unsigned(spec["whole_job_bytes"], "whole-job storage limit")
    reserve = unsigned(spec["cleanup_reserve_bytes"], "cleanup storage reserve")
    require(0 < reserve < limit, "Cleanup reserve must fit the separately declared whole-job storage bound.")
    artifact_root = Path(spec["artifact_root"]).resolve(strict=True)
    wp_root = Path(spec["wp_load"]).resolve(strict=True).parent
    require(artifact_root != wp_root and wp_root not in artifact_root.parents, "Private artifacts must be outside the web root.")
    require(any(artifact_root == root or root in artifact_root.parents for root in roots), "Artifact storage is not accounted for.")
    return roots, limit, reserve, artifact_root


def execute(spec):
    global CONTROLLER_WRITTEN
    CONTROLLER_WRITTEN = 0
    started = time.monotonic_ns()
    hard_end = started + NATIVE_SECONDS * 10**9
    require(sys.version_info >= (3, 11) and sys.platform.startswith("linux")
            and hasattr(os, "pidfd_open") and hasattr(os, "P_PIDFD"),
            "Linux/Python 3.11 pidfd support is required before creating artifacts.")
    probe = os.pidfd_open(os.getpid())
    os.close(probe)
    roots, limit, reserve, artifact_root = validate_environment(spec)
    require(time.monotonic_ns() < hard_end, "Preflight exhausted the native deadline.")
    root = Path(__file__).resolve().parents[2]
    evidence = artifact_root / spec["namespace"]
    control = artifact_root / (spec["namespace"] + ".controller")
    require(not evidence.exists() and not control.exists(), "Namespace/evidence collision.")
    control.mkdir(mode=0o700)
    publish(control / "environment.json", spec)
    for name in ("storage_receipt", "private_durability_receipt"):
        retain_receipt(spec[name], control / (name + ".original.json"))
    env = os.environ.copy()
    env.update({
        "WSTM_BOUNDED_BENCHMARK": "1", "WSTM_BOUNDED_NAMESPACE": spec["namespace"],
        "WSTM_BOUNDED_WP_LOAD": spec["wp_load"], "WSTM_BOUNDED_SOURCE_SHA": spec["source_sha"],
        "WSTM_BOUNDED_ARTIFACT_ROOT": str(artifact_root), "WSTM_BOUNDED_SHARD": spec["shard"],
    })
    execution = {"controller_start_ns": started, "phases": {}, "failure": None, "namespace_storage": {}}

    def storage_check(cleanup=False):
        observed = sum(owned_bytes(path) for path in roots)
        require(observed <= limit - (0 if cleanup else reserve), "Whole-job storage/cleanup reserve exhausted.")
        current = {}
        for folder in (evidence, control):
            if folder.exists():
                for file in folder.rglob("*"):
                    require(not file.is_symlink(), "Linked evidence path.")
                    if file.is_file():
                        current[str(file)] = file.stat().st_size
        journal = control / "php-write-bytes.log"
        php_written = 0
        if journal.exists():
            with journal.open("rb") as source:
                for line in source:
                    require(line.endswith(b"\n") and line[:-1].isdigit(), "Incomplete PHP write journal.")
                    php_written += int(line)
        journal_bytes = journal.stat().st_size if journal.exists() else 0
        cumulative = php_written + journal_bytes + CONTROLLER_WRITTEN
        retained = sum(current.values())
        require(retained <= NAMESPACE_BYTES and cumulative <= NAMESPACE_BYTES, "Namespace storage budget exceeded.")
        execution["namespace_storage"] = {
            "retained_bytes": retained, "cumulative_write_bytes": cumulative,
            "php_reserved_bytes": php_written, "php_journal_bytes": journal_bytes,
            "controller_written_bytes": CONTROLLER_WRITTEN,
            "cumulative_method": "PHP pre-write reservations plus journal bytes and controller accepted writes; conservative for failed writes, includes overwritten JSON",
            "whole_job_observed_bytes": observed, "whole_job_limit_bytes": limit,
        }

    def phase(name, cleanup=False):
        directory = control / name
        directory.mkdir(mode=0o700)
        reserve_seconds = 0 if cleanup else 840
        deadline = min(hard_end - reserve_seconds * 10**9, time.monotonic_ns() + PHASE_SECONDS[name] * 10**9)
        argv = [spec["php"], str(root / "tests" / "e2e" / "bounded-list-benchmark.php"), name]
        phase_env = env.copy()
        phase_env["WSTM_BOUNDED_CLEANUP_PHASE"] = "1" if cleanup else "0"
        receipt, _ = run_process(argv, directory, deadline, phase_env, lambda: storage_check(cleanup))
        execution["phases"][name] = receipt

    try:
        for name in ("seed", "reference", "projection", "cache-faults", "snapshot-before"):
            phase(name)
        plan = original(evidence / "plan.json")
        require(plan["shard"] == spec["shard"], "Seed plan uses a different shard.")
        traversal_start = time.monotonic_ns()
        traversal_end = min(hard_end - 840 * 10**9, traversal_start + PHASE_SECONDS["traversal"] * 10**9)
        for index, job in enumerate(plan["jobs"], 1):
            directory = evidence / f"operation-{index}"
            directory.mkdir(mode=0o700)
            publish(directory / "job.json", job)
            deadline = min(traversal_end, time.monotonic_ns() + WORKER_SECONDS * 10**9)
            argv = [spec["php"], str(root / "tests" / "e2e" / "bounded-list-benchmark.php"), "worker", str(directory)]
            run_process(argv, directory, deadline, env, storage_check)
        # Traversal is a serial collection of independently retained process
        # receipts, not a fabricated extra native process.
        execution["phases"]["traversal"] = {
            "start_ns": traversal_start, "end_ns": time.monotonic_ns(),
            "kind": "worker-sequence", "workers": len(plan["jobs"]),
        }
        phase("snapshot-after")
    except BaseException as error:
        execution["failure"] = f"{type(error).__name__}: {error}"
    finally:
        try:
            if (evidence / "state.json").is_file():
                phase("cleanup", True)
                phase("cleanup-readback", True)
        except BaseException as error:
            execution["cleanup_failure"] = f"{type(error).__name__}: {error}"
        try:
            storage_check(True)
            counters = execution["namespace_storage"]
            require(counters["retained_bytes"] + FINAL_RECORD_RESERVE <= NAMESPACE_BYTES
                    and counters["cumulative_write_bytes"] + FINAL_RECORD_RESERVE <= NAMESPACE_BYTES,
                    "Final immutable records lack namespace capacity.")
            require(counters["whole_job_observed_bytes"] + FINAL_RECORD_RESERVE <= limit,
                    "Final immutable records lack whole-job capacity.")
            counters["final_record_reserve_bytes"] = FINAL_RECORD_RESERVE
            counters["observation"] = "Before final outcome/execution records; verifier charges their exact retained bytes."
        except BaseException as error:
            execution["storage_failure"] = f"{type(error).__name__}: {error}"
        execution["controller_end_ns"] = time.monotonic_ns()
        if execution["controller_end_ns"] > hard_end:
            execution["deadline_failure"] = "Whole native deadline exceeded before final publication."
        # Preserve the first execution failure even when quota/readback also fails.
        publish(control / "outcome.json", execution)
        if evidence.is_dir():
            publish(evidence / "execution.json", execution)
    require(execution["failure"] is None and "cleanup_failure" not in execution
            and "storage_failure" not in execution and "deadline_failure" not in execution,
            "Benchmark failed; private originals retained.")
    files = custody_files(evidence, control)
    require(time.monotonic_ns() < hard_end, "Custody finalization exceeded the native deadline.")
    custody = {
        "namespace": spec["namespace"], "shard": spec["shard"], "source_sha": spec["source_sha"],
        "controller_start_ns": started, "custody_ready_ns": time.monotonic_ns(),
        "files": files,
        "trust": "Pin the custody file SHA-256 outside this artifact set; a self-reported digest is not independent attestation.",
    }
    publish(control / "custody.json", custody, 32 * 1024**2)
    require(time.monotonic_ns() < hard_end, "Final custody publication exceeded the native deadline.")
    # Source/host/package identity and private durability still require owner
    # attestation. This digest binds originals; it is not numeric acceptance.
    print(json.dumps({"namespace": spec["namespace"], "shard": spec["shard"],
                      "custody_sha256": digest(control / "custody.json")}), flush=True)
    # This is measured completion only. Independent PHP verifier plus the
    # coordinator's storage/durability/platform review remain mandatory.
    return execution


if __name__ == "__main__":
    require(len(sys.argv) == 2, "Usage: python3 bounded-list-controller.py <granted-environment.json>")
    cancellation = []

    def cancel_once(signum, _frame):
        if not cancellation:
            cancellation.append(signum)
            raise InterruptedError(f"Controller received signal {signum}; bounded owned cleanup required.")

    previous = signal.signal(signal.SIGTERM, cancel_once)
    try:
        execute(original(sys.argv[1]))
    finally:
        signal.signal(signal.SIGTERM, previous)
