"""Lease-gated cleanup only. Never publishes benchmark execution/custody success."""

import importlib.util
import os
from pathlib import Path
import signal
import sys
import time


SOURCE = Path(__file__).with_name("bounded-list-controller.py")
MODULE = importlib.util.spec_from_file_location("recovery_controller", SOURCE)
controller = importlib.util.module_from_spec(MODULE)
MODULE.loader.exec_module(controller)
RECOVERY_SECONDS = 840


def private_tree(root):
    controller.require(root.is_dir() and not root.is_symlink(), "Missing/linked private root.")
    for path in [root, *root.rglob("*")]:
        controller.require(not path.is_symlink(), "Linked recovery artifact.")
        stat = path.stat()
        controller.require(stat.st_uid == os.getuid()
                           and stat.st_mode & (0o077 if path.is_dir() else 0o022) == 0,
                           "Recovery artifacts must be private and owned by this uid.")
        controller.require(path.is_dir() or (path.is_file() and stat.st_nlink == 1),
                           "Ambiguous recovery artifact ownership.")


def recover(environment_path, environment_sha256, state_sha256, journal_sha256):
    c = controller
    c.require(sys.platform.startswith("linux") and sys.version_info >= (3, 11)
              and hasattr(os, "pidfd_open") and hasattr(os, "P_PIDFD"),
              "Recovery requires Linux/Python 3.11 pidfd support.")
    import fcntl
    started = time.monotonic_ns()
    hard_end = started + RECOVERY_SECONDS * 10**9
    probe = os.pidfd_open(os.getpid())
    os.close(probe)
    environment_path = Path(environment_path)
    c.require(c.digest(environment_path) == environment_sha256, "Original environment drift.")
    spec = c.original(environment_path)
    roots, limit, _, artifact_root = c.validate_environment(spec)
    stat = artifact_root.stat()
    c.require(stat.st_uid == os.getuid() and stat.st_mode & 0o077 == 0,
              "Recovery artifact root must be private and owned.")
    evidence = artifact_root / spec["namespace"]
    control = artifact_root / (spec["namespace"] + ".controller")
    c.require(environment_path == control / "environment.json", "Not the original environment path.")
    private_tree(evidence)
    private_tree(control)
    for name in ("storage_receipt", "private_durability_receipt"):
        c.require(c.digest(control / (name + ".original.json")) == spec[name]["sha256"],
                  "Retained original owner receipt drift.")
    c.require(not (control / "custody.json").exists(), "Completed custody is not interrupted recovery.")
    state = evidence / "state.json"
    journal = control / "php-write-bytes.log"
    c.require(c.digest(state) == state_sha256 and c.digest(journal) == journal_sha256,
              "Original ownership state/write journal drift.")
    c.require(c.original(state)["namespace"] == spec["namespace"], "Ownership namespace drift.")
    source_root = Path(__file__).resolve().parents[2]
    hashes = c.original(state)["sources"]["sha256"]
    c.require(type(hashes) is dict and hashes, "Missing original source hashes.")
    for name, sha256 in hashes.items():
        relative = Path(name)
        c.require(not relative.is_absolute() and ".." not in relative.parts,
                  "Ambiguous original source path.")
        c.require(c.digest(source_root / relative) == sha256, "Immutable source drift.")
    # A persistent empty lock is not a receipt or grant. PHP retains its existing
    # run/worker locks and performs every live DB ownership check itself.
    with (control / "invocation.lock").open("r+b") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        c.require(c.digest(environment_path) == environment_sha256
                  and c.digest(state) == state_sha256 and c.digest(journal) == journal_sha256,
                  "Original identity drift before exclusive invocation ownership.")
        return recover_locked(spec, roots, limit, artifact_root, evidence, control,
                              state_sha256, journal, started, hard_end)


def recover_locked(spec, roots, limit, artifact_root, evidence, control,
                   state_sha256, journal, started, hard_end):
    c = controller
    baseline = c.custody_files(evidence, control)
    journal_prefix_bytes = journal.stat().st_size
    journal_prefix_sha256 = c.digest(journal)
    # Prior controller publications are exclusive, but an interrupted controller
    # cannot publish its final counter. Charge ALL retained originals again,
    # including PHP outputs; never infer a smaller cumulative counter.
    c.CONTROLLER_WRITTEN = c.owned_bytes(evidence) + c.owned_bytes(control)
    outcome = {"kind": "cleanup-recovery", "shard_success": False,
               "start_ns": started, "failure": None, "phases": {},
               "prior_inventory": baseline, "state_sha256": state_sha256,
               "journal_prefix_bytes": journal_prefix_bytes,
               "journal_prefix_sha256": journal_prefix_sha256}

    def storage_check():
        c.require(sum(c.owned_bytes(root) for root in roots) <= limit,
                  "Whole-job recovery storage exhausted.")
        reserved = 0
        journal_bytes = 0
        c.require(0 < journal.stat().st_size <= 16 * 1024**2,
                  "Missing/oversized original PHP write journal.")
        with journal.open("rb") as source:
            for line in source:
                c.require(line.endswith(b"\n") and line[:-1].isdigit() and len(line) <= 9
                          and not line.startswith(b"0"),
                          "Incomplete original PHP write journal; refusing reset.")
                c.require(0 < int(line) <= 32 * 1024**2, "Malformed PHP byte reservation.")
                journal_bytes += len(line)
                reserved += int(line)
        retained = c.owned_bytes(evidence) + c.owned_bytes(control)
        cumulative = reserved + journal_bytes + c.CONTROLLER_WRITTEN
        c.require(max(retained, cumulative) + c.FINAL_RECORD_RESERVE <= c.NAMESPACE_BYTES,
                  "Recovery namespace/final-record capacity exhausted.")
        c.require(sum(c.owned_bytes(root) for root in roots) + c.FINAL_RECORD_RESERVE <= limit,
                  "Recovery final-record whole-job capacity exhausted.")
        return cumulative

    storage_check()
    c.require(time.monotonic_ns() < hard_end, "Recovery preflight deadline exceeded.")
    index = 1
    while (control / f"recovery-{index}").exists():
        index += 1
    attempt = control / f"recovery-{index}"
    attempt.mkdir(mode=0o700)
    c.publish(attempt / "request.json", outcome)
    env = os.environ.copy()
    env.update({
        "WSTM_BOUNDED_BENCHMARK": "1", "WSTM_BOUNDED_NAMESPACE": spec["namespace"],
        "WSTM_BOUNDED_WP_LOAD": spec["wp_load"], "WSTM_BOUNDED_SOURCE_SHA": spec["source_sha"],
        "WSTM_BOUNDED_ARTIFACT_ROOT": str(artifact_root), "WSTM_BOUNDED_SHARD": spec["shard"],
        "WSTM_BOUNDED_CLEANUP_PHASE": "1", "WSTM_BOUNDED_RECOVERY_OUTPUT": str(attempt),
    })
    try:
        for name in ("cleanup", "cleanup-readback"):
            directory = attempt / name
            directory.mkdir(mode=0o700)
            deadline = min(hard_end, time.monotonic_ns() + c.PHASE_SECONDS[name] * 10**9)
            receipt, _ = c.run_process(
                [spec["php"], str(Path(__file__).with_name("bounded-list-benchmark.php")), name],
                directory, deadline, env, storage_check)
            outcome["phases"][name] = receipt
        c.require(c.original(attempt / "cleanup.json") == {"passed": True},
                  "Missing cleanup output.")
        remaining = c.original(attempt / "cleanup-readback.json")["remaining"]
        c.require(type(remaining) is dict and set(remaining) == {"posts", "actor", "actor_meta", "control"}
                  and all(type(value) is int and value == 0 for value in remaining.values()),
                  "Missing/failed cleanup readback.")
    except BaseException as error:
        outcome["failure"] = f"{type(error).__name__}: {error}"
    finally:
        try:
            for entry in baseline:
                path = (evidence if entry["area"] == "data" else control) / entry["path"]
                if path == journal:
                    with journal.open("rb") as source:
                        import hashlib
                        remaining = journal_prefix_bytes
                        prefix = hashlib.sha256()
                        while remaining:
                            data = source.read(min(65536, remaining))
                            c.require(data, "Original journal prefix was truncated.")
                            prefix.update(data)
                            remaining -= len(data)
                        c.require(prefix.hexdigest() == journal_prefix_sha256,
                                  "Original journal prefix changed.")
                else:
                    c.require(not path.is_symlink() and c.digest(path) == entry["sha256"],
                              "Prior original changed during recovery.")
            outcome["cumulative_write_bytes"] = storage_check()
            c.require(time.monotonic_ns() < hard_end, "Recovery deadline exceeded.")
        except BaseException as error:
            outcome["preservation_or_budget_failure"] = f"{type(error).__name__}: {error}"
        outcome["end_ns"] = time.monotonic_ns()
        c.publish(attempt / "outcome.json", outcome)
    c.require(outcome["failure"] is None and "preservation_or_budget_failure" not in outcome,
              "Recovery failed; prior originals and recovery captures retained.")
    return outcome


if __name__ == "__main__":
    controller.require(len(sys.argv) == 5,
                       "Usage: python3 bounded-list-recovery.py <original-environment.json> "
                       "<environment-sha256> <state-sha256> <journal-sha256>")
    cancelled = []

    def cancel_once(signum, _frame):
        if not cancelled:
            cancelled.append(signum)
            raise InterruptedError(f"Recovery received signal {signum}.")

    previous = signal.signal(signal.SIGTERM, cancel_once)
    try:
        recover(*sys.argv[1:])
        print("Owned cleanup/readback completed; interrupted shard remains unsuccessful.", flush=True)
    finally:
        signal.signal(signal.SIGTERM, previous)
