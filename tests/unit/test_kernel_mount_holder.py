"""Portable protocol controls. Mocks here do not certify any native mapping."""

import importlib.util
import io
import json
from pathlib import Path
import unittest
from unittest.mock import patch


SOURCE = Path(__file__).resolve().parents[2] / "scripts" / "untrusted-kernel-mount-holder.py"
SPEC = importlib.util.spec_from_file_location("kernel_mount_holder", SOURCE)
HOLDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HOLDER)


class KernelMountHolderTest(unittest.TestCase):
    def run_model(self, requests, expected_error=None, clock=1, root=False):
        output = []
        opened = []
        closed = []
        records = iter(requests)

        def emit(value):
            output.append(value)
            if value == {"ready": 1}:
                self.assertEqual([], opened)

        def open_path(path, flags):
            opened.append((path, flags))
            return len(opened) + 20

        with (
            patch.object(HOLDER.sys, "argv", ["holder", "9000000000"]),
            patch.object(HOLDER.os, "getuid", return_value=0 if root else 1000, create=True),
            patch.object(HOLDER.os, "geteuid", return_value=0 if root else 1000, create=True),
            patch.object(HOLDER.os, "O_PATH", 0x200000, create=True),
            patch.object(HOLDER.os, "O_CLOEXEC", 0x80000, create=True),
            patch.object(HOLDER.os, "O_NOFOLLOW", 0x20000, create=True),
            patch.object(HOLDER.time, "monotonic_ns", return_value=clock),
            patch.object(HOLDER, "line", side_effect=lambda deadline: next(records)),
            patch.object(HOLDER, "emit", side_effect=emit),
            patch.object(HOLDER.os, "open", side_effect=open_path),
            patch.object(HOLDER.os, "close", side_effect=closed.append),
        ):
            if expected_error:
                with self.assertRaises(expected_error):
                    HOLDER.main()
            else:
                HOLDER.main()
        self.assertEqual([number + 21 for number in range(len(opened))], closed)
        return output, opened

    def test_ready_has_no_targets_and_shutdown_closes_exact_holds(self):
        output, opened = self.run_model(
            [b'{"paths":["/","/stack/node"]}\n', b'{"close":1}\n']
        )
        self.assertEqual({"ready": 1}, output[0])
        self.assertEqual({"closed": 1}, output[-1])
        self.assertEqual([("/", 0x2A0000), ("/stack/node", 0x2A0000)], opened)

    def test_root_or_expired_precondition_has_no_ready_or_target(self):
        for options in ({"root": True}, {"clock": 9000000000}):
            output, opened = self.run_model([], ValueError, **options)
            self.assertEqual([], output)
            self.assertEqual([], opened)

    def test_bad_shapes_and_counts_have_no_target(self):
        requests = (
            b"true\n", b"[]\n", b"{}\n", b'{"paths":[]}\n',
            b'{"paths":["relative"]}\n', b'{"paths":[true]}\n',
            b'{"paths":["/bad\\u0000"]}\n',
            json.dumps({"paths": ["/" + "x" * 4096]}).encode(),
            json.dumps({"paths": ["/p" + str(n) for n in range(33)]}).encode(),
            b'{"paths":["/"],"proof":true}\n', b'{"close":true,"nonce":"retained"}\n',
            b'{"close":true}\n', b'{"close":1.0}\n',
            b'{"paths":[],"paths":["/"]}\n', b'{"paths":["/a/../b"]}\n',
            b'{"paths":["/a//b"]}\n', b'{"paths":["/a/"]}\n',
        )
        for request in requests:
            with self.subTest(request=request[:100]):
                _, opened = self.run_model([request], ValueError)
                self.assertEqual([], opened)

    def test_duplicate_path_cannot_replace_held_descriptor(self):
        _, opened = self.run_model(
            [b'{"paths":["/"]}\n', b'{"paths":["/"]}\n'], ValueError
        )
        self.assertEqual(1, len(opened))

    def test_chunk_ceiling_never_resets(self):
        requests = [
            json.dumps({"paths": ["/p" + str(n)]}).encode() for n in range(257)
        ]
        _, opened = self.run_model(requests, ValueError)
        self.assertEqual(256, len(opened))

    def test_complete_eof_overflow_and_expiry_are_not_frames(self):
        for data, clock in ((b"", 1), (b"x" * 262145, 1), (b"{}\n", 10)):
            stream = io.BytesIO(data)
            with (
                patch.object(HOLDER.time, "monotonic_ns", return_value=clock),
                patch.object(HOLDER.select, "select", return_value=([0], [], [])),
                patch.object(HOLDER.os, "read", side_effect=lambda fd, count: stream.read(count)),
            ):
                with self.assertRaises(ValueError):
                    HOLDER.line(10)

    def test_output_frame_matches_php_unicode_and_slash_canonical_form(self):
        result = bytearray()
        with patch.object(HOLDER.os, "write", side_effect=lambda fd, data: result.extend(data) or len(data)):
            HOLDER.emit({"opened": [{"path": "/caf\u00e9/\u2028", "fd": 3}]})
        self.assertEqual(
            '{"opened":[{"path":"/caf\u00e9/\u2028","fd":3}]}\n'.encode(),
            bytes(result),
        )


if __name__ == "__main__":
    unittest.main()
