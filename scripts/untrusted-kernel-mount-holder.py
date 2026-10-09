"""Fixed nonroot O_PATH holder. Reports fd numbers, never authoritative identities."""

import json
import os
import re
import select
import sys
import time


def require(condition):
    if not condition:
        raise ValueError("kernel holder refused")


def line(deadline):
    data = bytearray()
    while True:
        remaining = (deadline - time.monotonic_ns()) / 1_000_000_000
        require(remaining > 0)
        require(select.select([0], [], [], min(remaining, 1))[0])
        value = os.read(0, 1)
        require(value)
        data.extend(value)
        require(len(data) <= 262144)
        if value == b"\n":
            return bytes(data)


def emit(value):
    data = (json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8")
    require(len(data) <= 262144)
    offset = 0
    while offset < len(data):
        written = os.write(1, data[offset:])
        require(written > 0)
        offset += written


def object_pairs(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result)
        result[key] = value
    return result


def main():
    require(len(sys.argv) == 2 and sys.argv[1].isdigit())
    deadline = int(sys.argv[1])
    require(os.getuid() == os.geteuid() and os.geteuid() > 0)
    require(hasattr(os, "O_PATH") and time.monotonic_ns() < deadline)
    descriptors = {}
    chunks = 0
    total = 0
    try:
        # No target is supplied in argv or opened before the parent's ready check.
        emit({"ready": 1})
        while True:
            request = json.loads(line(deadline), object_pairs_hook=object_pairs)
            require(isinstance(request, dict))
            if list(request) == ["close"]:
                require(type(request["close"]) is int and request["close"] == 1)
                require(time.monotonic_ns() < deadline)
                emit({"closed": 1})
                return
            require(list(request) == ["paths"])
            paths = request["paths"]
            require(isinstance(paths, list) and 1 <= len(paths) <= 32)
            chunks += 1
            require(chunks <= 256)
            rows = []
            for path in paths:
                require(isinstance(path, str) and path.startswith("/"))
                require(not re.search(r"[\x00-\x1f\x7f]|//|(?:^|/)\.\.?(?:/|$)", path))
                require(path == "/" or not path.endswith("/"))
                require(len(path.encode("utf-8")) <= 4096)
                require(path not in descriptors and time.monotonic_ns() < deadline)
                total += 1
                require(total <= 8192)
                fd = os.open(path, os.O_PATH | os.O_CLOEXEC | os.O_NOFOLLOW)
                descriptors[path] = fd
                rows.append({"path": path, "fd": fd})
            emit({"opened": rows})
    finally:
        for fd in descriptors.values():
            os.close(fd)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, UnicodeError, TypeError, KeyError):
        os.write(2, b"WSTM108 kernel holder refused.\n")
        sys.exit(78)
