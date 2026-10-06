"""Portable negative controls; these mocks are not genuine HOST evidence."""

import importlib.util
import io
from pathlib import Path
import stat
import struct
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch


SOURCE = Path(__file__).resolve().parents[2] / "scripts" / "host-prerequisite-inventory.py"
SPEC = importlib.util.spec_from_file_location("host_prerequisite_inventory", SOURCE)
GATE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GATE)


class Original(io.BytesIO):
    def fileno(self):
        return 5


class PrerequisiteInventoryTest(unittest.TestCase):
    def info(self, **changes):
        values = dict(st_mode=stat.S_IFREG | 0o755, st_uid=0, st_gid=0, st_nlink=1,
                      st_size=64, st_dev=1, st_ino=2, st_mtime_ns=3, st_ctime_ns=4)
        values.update(changes)
        return SimpleNamespace(**values)

    def valid_php(self):
        return {"sockets": True, "functions": {name: True for name in GATE.FUNCTIONS},
                "constants": {name: 1 for name in GATE.CONSTANTS}}

    def test_actual_missing_sockets_functions_constants_are_not_inferred_from_workflow_request(self):
        GATE.validate_php(self.valid_php())
        for category in ("sockets", "functions", "constants"):
            value = self.valid_php()
            if category == "sockets":
                value[category] = False
            elif category == "functions":
                value[category][GATE.FUNCTIONS[0]] = False
            else:
                value[category][GATE.CONSTANTS[0]] = None
            with self.subTest(category=category), self.assertRaises(GATE.Refusal):
                GATE.validate_php(value)
        value = self.valid_php()
        value["constants"][GATE.CONSTANTS[0]] = True
        with self.assertRaisesRegex(GATE.Refusal, "php-constants"):
            GATE.validate_php(value)
        value["constants"][GATE.CONSTANTS[0]] = 0
        with self.assertRaisesRegex(GATE.Refusal, "php-constants"):
            GATE.validate_php(value)

    def test_query_identifies_the_selected_real_php_binary_not_an_os_version_guess(self):
        GATE.validate_php_identity({"binary": "/usr/bin/php8.2", "version": "8.2.30"},
                                   "/usr/bin/php8.2", "8.2")
        for value in ({"binary": "/PRIVATE_SENTINEL", "version": "8.2.30"},
                      {"binary": "/usr/bin/php8.2", "version": "8.4.0"},
                      {"binary": "/usr/bin/php8.2", "version": 80230}):
            with self.assertRaisesRegex(GATE.Refusal, "php-config"):
                GATE.validate_php_identity(value, "/usr/bin/php8.2", "8.2")

    def test_wrong_owner_writable_setid_hardlink_or_nonregular_tools_refuse(self):
        GATE.validate_file(self.info(), 0, 64)
        for changes in (
            {"st_uid": 1000}, {"st_mode": stat.S_IFREG | 0o777},
            {"st_mode": stat.S_IFREG | 0o4755}, {"st_nlink": 2},
            {"st_mode": stat.S_IFLNK | 0o777}, {"st_size": 65},
        ):
            with self.subTest(changes=changes), self.assertRaises(GATE.Refusal):
                GATE.validate_file(self.info(**changes), 0, 64)

    def test_symlink_unsafe_parent_is_not_canonical_directory_authority(self):
        GATE.validate_parent(self.info(st_mode=stat.S_IFDIR | 0o755))
        for mode, uid in ((stat.S_IFLNK | 0o777, 0), (stat.S_IFDIR | 0o777, 0),
                          (stat.S_IFDIR | 0o755, 1000)):
            with self.assertRaisesRegex(GATE.Refusal, "tool-parent"):
                GATE.validate_parent(self.info(st_mode=mode, st_uid=uid))

    def test_full_installed_tool_digest_missing_foreign_duplicate_or_changed_origin_fails(self):
        digest = "a" * 32
        GATE.verify_manifest((digest + "  usr/bin/setpriv\n").encode(), "/usr/bin/setpriv", digest)
        for raw in (b"", (digest + "  usr/bin/foreign\n").encode(),
                    (digest + "  usr/bin/setpriv\n" + digest + "  bin/setpriv\n").encode(),
                    (("b" * 32) + "  usr/bin/setpriv\n").encode(), b"\xff"):
            with self.subTest(raw=raw), self.assertRaises(GATE.Refusal):
                GATE.verify_manifest(raw, "/usr/bin/setpriv", digest)

    def test_unavailable_tool_and_missing_origin_do_not_get_a_version_fallback(self):
        inventory = GATE.Inventory()
        with patch.object(GATE.os.path, "exists", return_value=False), \
                patch.object(GATE.os.path, "realpath", side_effect=lambda path: path):
            with self.assertRaisesRegex(GATE.Refusal, "tool-missing"):
                inventory.system("/usr/bin/setpriv")
        inventory.files["/usr/bin/setpriv"] = {"md5": "a" * 32}
        with patch.object(inventory, "system", return_value="/usr/bin/setpriv"), \
                patch.object(inventory, "command", return_value=b"foreign: /usr/bin/foreign\n"):
            with self.assertRaisesRegex(GATE.Refusal, "tool-origin"):
                inventory.origin("/usr/bin/setpriv")

    def test_elf_header_loader_paths_and_command_output_are_finite(self):
        raw = bytearray(64)
        raw[:7] = b"\x7fELF\x02\x01\x01"
        struct.pack_into("<HHI", raw, 16, 3, 62, 1)
        GATE.parse_elf(raw)
        for changed in (b"", b"\x7fELF", bytes(raw[:16]) + b"\xff" * 48):
            with self.assertRaises(GATE.Refusal):
                GATE.parse_elf(changed)
        self.assertEqual((["libc.so.6"], ["/lib64/ld-linux-x86-64.so.2"]),
                         GATE.parse_dynamic(
                             b"[Requesting program interpreter: /lib64/ld-linux-x86-64.so.2]\n"
                             b"(NEEDED) Shared library: [libc.so.6]\n"))
        for changed in (b"(RPATH) /PRIVATE_SENTINEL", b"(NEEDED) Shared library: [../x]",
                        b"[Requesting program interpreter: /PRIVATE_SENTINEL]"):
            with self.assertRaises(GATE.Refusal):
                GATE.parse_dynamic(changed)

    def test_unsafe_native_operation_or_arguments_never_launch_a_child(self):
        inventory = GATE.Inventory()
        with patch.object(GATE.subprocess, "Popen") as launch:
            for operation, argument in (("sudo", ""), ("elf", "/PRIVATE_SENTINEL"),
                                        ("owner", "/PRIVATE_SENTINEL"),
                                        ("package", "util-linux; arbitrary"), ("package", "../source")):
                with self.assertRaisesRegex(GATE.Refusal, "unsafe-input"):
                    inventory.command(operation, argument)
            launch.assert_not_called()

    def test_configured_php_cannot_load_arbitrary_paths_prepend_or_preload(self):
        self.assertEqual(["sockets.so", "opcache.so"], GATE.configured_modules(
            b'; comment\nextension=sockets\nzend_extension="opcache.so"\n', "/usr/lib/php/20220829"))
        for raw in (
            b"extension=/PRIVATE_SENTINEL/script", b"extension_dir=/PRIVATE_SENTINEL",
            b"opcache.preload=/PRIVATE_SENTINEL", b"auto_prepend_file=payload.php",
            b"ffi.preload=/PRIVATE_SENTINEL", b"extension=arbitrary_callback",
            b"[PATH=/PRIVATE_SENTINEL]", b"extension=${UNTRUSTED}",
        ):
            with self.assertRaisesRegex(GATE.Refusal, "php-config"):
                GATE.configured_modules(raw, "/usr/lib/php/20220829")

    def test_original_prefix_is_synced_before_overflow_rejection(self):
        inventory = GATE.Inventory()
        streams = {1: bytearray(), 2: bytearray()}
        original = Original()
        with patch.object(GATE.os, "fsync") as sync, patch.object(GATE, "verify_original"):
            with self.assertRaisesRegex(GATE.Refusal, "native-output"):
                inventory.retain_chunk(1, b"x" * (GATE.STDOUT_LIMIT + 1), original, streams, ("path", {}))
            sync.assert_called_once_with(5)
        self.assertEqual(GATE.STDOUT_LIMIT + 1, len(original.getvalue()))
        self.assertEqual(original.getvalue(), streams[1])

    def test_stderr_and_aggregate_capture_bounds_are_not_fresh_allowances(self):
        inventory = GATE.Inventory()
        with patch.object(GATE.os, "fsync"), patch.object(GATE, "verify_original"):
            with self.assertRaisesRegex(GATE.Refusal, "native-output"):
                inventory.retain_chunk(2, b"x" * (GATE.STDERR_LIMIT + 1),
                                       Original(), {1: bytearray(), 2: bytearray()}, ("path", {}))
            inventory.capture_bytes = GATE.RECEIPT_LIMIT
            with self.assertRaisesRegex(GATE.Refusal, "retention-budget"):
                inventory.retain_chunk(1, b"x", Original(), {1: bytearray(), 2: bytearray()}, ("path", {}))

    def test_exit_or_both_original_eofs_or_stderr_failure_has_no_native_success(self):
        good = {"exit": 0, "stdout_eof": True, "stderr_eof": True}
        GATE.validate_native_result(good, b"")
        for field, value in (("exit", None), ("exit", 1), ("exit", -9),
                             ("stdout_eof", False), ("stderr_eof", False)):
            failed = dict(good)
            failed[field] = value
            with self.assertRaisesRegex(GATE.Refusal, "native-command"):
                GATE.validate_native_result(failed, b"")
        with self.assertRaises(GATE.Refusal):
            GATE.validate_native_result(good, b"error")

    def test_original_file_link_change_and_bounded_read_refuse(self):
        inventory = GATE.Inventory()
        info = self.info()
        directory = self.info(st_mode=stat.S_IFDIR | 0o755)
        with patch.object(GATE.os.path, "realpath", return_value="/usr/bin/setpriv"), \
                patch.object(GATE.os, "lstat", side_effect=[info, directory, directory, directory]), \
                patch.object(GATE.os, "open", return_value=5), \
                patch.object(GATE.os, "O_NOFOLLOW", 0x20000, create=True), \
                patch.object(GATE.os, "O_CLOEXEC", 0x80000, create=True), \
                patch.object(GATE.os, "fstat", return_value=self.info(st_ino=99)), \
                patch.object(GATE.os, "close") as close:
            with self.assertRaisesRegex(GATE.Refusal, "tool-link"):
                inventory.read("/usr/bin/setpriv")
            close.assert_called_once_with(5)
        with self.assertRaisesRegex(GATE.Refusal, "file-budget"):
            GATE.validate_file(self.info(st_size=GATE.FILE_LIMIT + 1), 0, GATE.FILE_LIMIT)

    def test_receipt_duplicate_keys_and_nonobjects_refuse(self):
        self.assertEqual({"version": 1}, GATE.decode_object(b'{"version":1}'))
        for raw in (b'{"version":1,"version":2}', b"[]", b"\xff", b"truncated"):
            with self.assertRaises(GATE.Refusal):
                GATE.decode_object(raw)

    def run_main(self, collect_error=None, retention_error=None):
        fake = Mock()
        fake.directory = None
        fake.receipt = {}
        fake.read.return_value = ("/source", None, b"source")
        fake.collect.side_effect = collect_error
        def reserve(unused):
            fake.directory = "/PRIVATE_SENTINEL"
        fake.reserve.side_effect = reserve
        fake.finish.side_effect = retention_error
        output = io.StringIO()
        with patch.object(GATE, "Inventory", return_value=fake), \
                patch.object(GATE, "identity", return_value={}), \
                patch.object(GATE.sys, "argv", ["gate", "8.2"]), \
                patch.object(GATE.sys, "platform", "linux"), \
                patch.object(GATE.os, "getuid", return_value=1000, create=True), \
                patch.object(GATE.os, "geteuid", return_value=1000, create=True), \
                patch.object(GATE.os, "umask"), patch.object(GATE.sys, "stdout", output):
            exit_code = GATE.main()
        self.assertNotIn("PRIVATE_SENTINEL", output.getvalue())
        return exit_code, output.getvalue(), fake

    def test_failed_inventory_retains_originals_and_public_summary_is_closed(self):
        code, text, fake = self.run_main(GATE.Refusal("php-sockets"))
        self.assertEqual(78, code)
        self.assertIn("php-sockets", text)
        fake.finish.assert_called_once_with("php-sockets")
        code, text, unused = self.run_main(GATE.Refusal("PRIVATE_SENTINEL"))
        self.assertEqual(78, code)
        self.assertIn("inventory-io", text)

    def test_retention_io_error_does_not_publish_private_exception_or_success(self):
        code, text, unused = self.run_main(retention_error=OSError("PRIVATE_SENTINEL"))
        self.assertEqual(78, code)
        self.assertIn("retention", text)

    def test_success_is_inventory_only_not_launcher_or_host_certificate(self):
        code, text, fake = self.run_main()
        self.assertEqual(0, code)
        self.assertIn("no launcher, transport or HOST admission executed", text)
        fake.check.assert_called_once()

    def test_original_absolute_deadline_equality_and_command_count_exhaustion_refuse(self):
        inventory = GATE.Inventory()
        with patch.object(GATE.time, "monotonic", return_value=inventory.deadline):
            with self.assertRaisesRegex(GATE.Refusal, "inventory-deadline"):
                inventory.check()
        inventory.commands = [{}] * GATE.COMMAND_COUNT
        with self.assertRaisesRegex(GATE.Refusal, "command-budget"):
            inventory.command("package", "util-linux")

    def test_extra_entry_arguments_or_root_never_reserve_or_launch(self):
        for argv, uid in ((["gate", "/PRIVATE_SENTINEL"], 1000),
                          (["gate"], 1000), (["gate", "8.2"], 0)):
            output = io.StringIO()
            with patch.object(GATE, "Inventory") as factory, \
                    patch.object(GATE.sys, "argv", argv), \
                    patch.object(GATE.sys, "platform", "linux"), \
                    patch.object(GATE.os, "getuid", return_value=uid, create=True), \
                    patch.object(GATE.os, "geteuid", return_value=uid, create=True), \
                    patch.object(GATE.sys, "stdout", output):
                factory.return_value.directory = None
                self.assertEqual(78, GATE.main())
                factory.return_value.reserve.assert_not_called()
                self.assertNotIn("PRIVATE_SENTINEL", output.getvalue())

    def test_custody_unsafe_paths_never_create_evidence(self):
        inventory = GATE.Inventory()
        with patch.object(GATE.os, "mkdir") as create:
            for root in (None, "relative", "/private\nroot", "/private\x00root"):
                with self.assertRaises(GATE.Refusal):
                    inventory.reserve(root)
            create.assert_not_called()

    def test_fixed_public_alias_cannot_point_into_private_data(self):
        inventory = GATE.Inventory()
        with patch.object(GATE.os.path, "realpath", return_value="/PRIVATE_SENTINEL"), \
                patch.object(GATE.os, "lstat") as metadata:
            with self.assertRaisesRegex(GATE.Refusal, "unsafe-input"):
                inventory.system("/usr/bin/php")
            metadata.assert_not_called()

    def test_workflow_shared_gate_precedes_candidate_php_without_extension_install(self):
        workflow = (SOURCE.parents[1] / ".github" / "workflows" /
                    "release-package-qa.yml").read_text(encoding="utf-8")
        custody = workflow.index("run: php tests/support/private-custody-context.php")
        gate = workflow.index("Guard and acquire selected HOST prerequisites")
        package = workflow.index("- name: Run Release Package QA")
        self.assertLess(gate, custody)
        self.assertLess(gate, package)
        self.assertIn("extensions: zip", workflow)
        self.assertIn("/usr/bin/env -i PATH=/usr/bin:/bin LC_ALL=C", workflow)
        self.assertNotIn("extensions: zip, sockets", workflow)

    def test_retained_original_path_substitution_is_failure(self):
        expected = GATE.original_identity(self.info(st_uid=1000, st_mode=stat.S_IFREG | 0o600))
        with patch.object(GATE.os, "fstat", return_value=self.info(st_uid=1000, st_mode=stat.S_IFREG | 0o600)), \
                patch.object(GATE.os, "lstat", return_value=self.info(st_ino=99)), \
                patch.object(GATE.os.path, "realpath", return_value="/original"):
            with self.assertRaisesRegex(GATE.Refusal, "retention"):
                GATE.verify_original(Original(), "/original", expected)

    def test_tool_then_config_share_one_pre_read_file_and_byte_ledger(self):
        inventory = GATE.Inventory()
        info = self.info(st_size=64)
        with patch.object(GATE.os.path, "realpath", side_effect=lambda path: path), \
                patch.object(GATE.os.path, "exists", return_value=True), \
                patch.object(GATE.os, "lstat", return_value=info), \
                patch.object(GATE, "parent_pins", return_value=[]), \
                patch.object(GATE.os, "getxattr", return_value=b"", create=True), \
                patch.object(inventory, "read", side_effect=lambda path, **kwargs:
                             (path, info, b"x" * 64)) as read:
            inventory.system("/usr/bin/setpriv")
            self.assertEqual((1, 64), (len(inventory.pins), inventory.bytes))
            inventory.configuration("/etc/php/8.2/cli/php.ini", "8.2")
            self.assertEqual((2, 128), (len(inventory.pins), inventory.bytes))
            self.assertEqual(2, read.call_count)
            self.assertEqual(64, read.call_args.kwargs["limit"])

    def test_config_exhaustion_refuses_before_acquiring_bytes_and_also_blocks_tools(self):
        for exhausted in ("files", "bytes"):
            inventory = GATE.Inventory()
            if exhausted == "files":
                inventory.pins = {str(n): {} for n in range(GATE.FILE_COUNT)}
            else:
                inventory.bytes = GATE.TOTAL_LIMIT
            with patch.object(GATE.os.path, "realpath", side_effect=lambda path: path), \
                    patch.object(GATE.os.path, "exists", return_value=True), \
                    patch.object(GATE.os, "lstat", return_value=self.info()), \
                    patch.object(GATE, "parent_pins", return_value=[]), \
                    patch.object(inventory, "read") as read:
                for operation in (
                    lambda: inventory.configuration("/etc/php/8.2/cli/php.ini", "8.2"),
                    lambda: inventory.system("/usr/bin/setpriv"),
                ):
                    with self.subTest(exhausted=exhausted), \
                            self.assertRaisesRegex(GATE.Refusal, "file-budget"):
                        operation()
                read.assert_not_called()

    def test_config_debit_exists_before_read_and_failed_prefix_reservation_is_not_refunded(self):
        inventory = GATE.Inventory()
        def fail(path, **kwargs):
            self.assertEqual((1, 64), (len(inventory.pins), inventory.bytes))
            self.assertEqual("reserved", inventory.pins[path]["state"])
            raise GATE.Refusal("inventory-io")
        with patch.object(GATE.os.path, "realpath", side_effect=lambda path: path), \
                patch.object(GATE.os, "lstat", return_value=self.info()), \
                patch.object(GATE, "parent_pins", return_value=[]), \
                patch.object(inventory, "read", side_effect=fail) as read:
            with self.assertRaisesRegex(GATE.Refusal, "inventory-io"):
                inventory.configuration("/etc/php/8.2/cli/php.ini", "8.2")
            with self.assertRaisesRegex(GATE.Refusal, "tool-link"):
                inventory.configuration("/etc/php/8.2/cli/php.ini", "8.2")
            self.assertEqual(1, read.call_count)
            self.assertEqual(64, inventory.bytes)

    def test_config_cache_alias_is_once_charged_but_changed_identity_never_reused(self):
        inventory = GATE.Inventory()
        target = "/etc/php/8.2/mods-available/sockets.ini"
        alias = "/etc/php/8.2/cli/conf.d/20-sockets.ini"
        info = self.info()
        with patch.object(GATE.os.path, "realpath", return_value=target), \
                patch.object(GATE.os, "lstat", return_value=info) as metadata, \
                patch.object(GATE, "parent_pins", return_value=[]), \
                patch.object(inventory, "read", return_value=(target, info, b"x" * 64)) as read:
            inventory.configuration(target, "8.2")
            inventory.configuration(alias, "8.2")
            self.assertEqual((1, 64, 1), (len(inventory.pins), inventory.bytes, read.call_count))
            metadata.return_value = self.info(st_ino=99)
            with self.assertRaisesRegex(GATE.Refusal, "tool-link"):
                inventory.configuration(alias, "8.2")
            self.assertEqual(1, read.call_count)

    def test_exact_setup_php_0777_config_refuses_and_safe_0644_postcondition_is_eligible(self):
        path = "/etc/php/8.2/cli/php.ini"
        for mode, allowed in ((0o777, False), (0o644, True)):
            inventory = GATE.Inventory()
            info = self.info(st_mode=stat.S_IFREG | mode)
            with patch.object(GATE.os.path, "realpath", side_effect=lambda path: path), \
                    patch.object(GATE.os, "lstat", return_value=info), \
                    patch.object(GATE, "parent_pins", return_value=[]), \
                    patch.object(inventory, "read", return_value=(path, info, b"x" * 64)) as read:
                if allowed:
                    inventory.configuration(path, "8.2")
                    self.assertEqual(64, inventory.bytes)
                else:
                    with self.assertRaisesRegex(GATE.Refusal, "tool-identity"):
                        inventory.configuration(path, "8.2")
                    read.assert_not_called()
                    self.assertEqual(0, inventory.bytes)

    def test_config_wrong_owner_outside_tree_and_canonical_symlink_parent_refuse(self):
        path = "/etc/php/8.2/cli/php.ini"
        for kind in ("owner", "outside", "parent"):
            inventory = GATE.Inventory()
            info = self.info(st_uid=1000 if kind == "owner" else 0)
            with patch.object(GATE.os.path, "realpath",
                              side_effect=lambda value: "/outside/php.ini" if kind == "outside" else value), \
                    patch.object(GATE.os, "lstat", return_value=info), \
                    patch.object(GATE, "parent_pins",
                              side_effect=GATE.Refusal("tool-parent") if kind == "parent" else None,
                              return_value=[]), \
                    patch.object(inventory, "read") as read:
                with self.subTest(kind=kind), self.assertRaises(GATE.Refusal):
                    inventory.configuration(path, "8.2")
                read.assert_not_called()
                self.assertEqual(0, inventory.bytes)

    def test_last_config_reservation_uses_remaining_shared_bytes_not_a_free_pool(self):
        inventory = GATE.Inventory()
        inventory.bytes = GATE.TOTAL_LIMIT - 64
        info = self.info()
        with patch.object(GATE.os.path, "realpath", side_effect=lambda path: path), \
                patch.object(GATE.os, "lstat", return_value=info), \
                patch.object(GATE, "parent_pins", return_value=[]), \
                patch.object(inventory, "read", side_effect=lambda path, **kwargs:
                             (path, info, b"x" * 64)) as read:
            inventory.configuration("/etc/php/8.2/cli/php.ini", "8.2")
            self.assertEqual(GATE.TOTAL_LIMIT, inventory.bytes)
            with self.assertRaisesRegex(GATE.Refusal, "file-budget"):
                inventory.configuration("/etc/php/8.2/cli/conf.d/99-pecl.ini", "8.2")
            self.assertEqual(1, read.call_count)

    def test_reserved_length_has_no_unbudgeted_lookahead_and_growth_refuses(self):
        inventory = GATE.Inventory()
        info = self.info(st_size=64)
        with patch.object(GATE.os.path, "realpath", side_effect=lambda path: path), \
                patch.object(GATE.os, "lstat", return_value=info), \
                patch.object(GATE, "parent_pins", return_value=[]), \
                patch.object(GATE.os, "O_NOFOLLOW", 0x20000, create=True), \
                patch.object(GATE.os, "O_CLOEXEC", 0x80000, create=True), \
                patch.object(GATE.os, "open", return_value=5), \
                patch.object(GATE.os, "close"), \
                patch.object(GATE.os, "fstat", return_value=info) as metadata, \
                patch.object(GATE.os, "read", return_value=b"x" * 64) as read:
            inventory.read("/etc/php/8.2/cli/php.ini", limit=64)
            read.assert_called_once_with(5, 64)
            metadata.side_effect = [info, self.info(st_size=65)]
            read.reset_mock()
            with self.assertRaisesRegex(GATE.Refusal, "tool-link"):
                inventory.read("/etc/php/8.2/cli/php.ini", limit=64)
            read.assert_called_once_with(5, 64)


if __name__ == "__main__":
    unittest.main()
