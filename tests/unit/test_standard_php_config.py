"""Package-native socket INI provenance and the exact configuration guard."""

from contextlib import ExitStack, contextmanager
import hashlib
import os
import stat
import unittest
from unittest.mock import patch

from test_host_php_apt_session_loop import MODULE, FileSystem, FIXTURES, elf

GUARD = MODULE.GUARD
BASE = GUARD.BASE
SOCKETS = (FIXTURES / "sockets.ini").read_bytes()


class StandardConfigurationTest(unittest.TestCase):
    @contextmanager
    def model(self, version="8.2"):
        fs = FileSystem()
        guard = GUARD.Provision(version)
        template = "/usr/share/php" + version + "-common/common/sockets.ini"
        binary = "/usr/bin/php" + version
        alias = "/etc/php/" + version + "/cli/conf.d/20-sockets.ini"
        fs.add(binary, elf(), mode=stat.S_IFREG | 0o755)
        fs.add(template, SOCKETS)
        fs.add(guard.targets[0], b"; production\n")
        fs.add(guard.targets[1], SOCKETS)
        fs.add(alias, mode=stat.S_IFLNK | 0o777, target=guard.targets[1])
        packages = {binary: "php" + version + "-cli", template: "php" + version + "-common"}
        for path, package in packages.items():
            fs.add("/var/lib/dpkg/info/" + package + ".md5sums",
                   (hashlib.md5(fs.nodes[path][1]).hexdigest() + "  " + path.lstrip("/") + "\n").encode())
        descriptors = {}
        offsets = {}
        def open_file(path, flags):
            descriptor = len(descriptors) + 1
            descriptors[descriptor] = path
            offsets[descriptor] = 0
            return descriptor
        def read_file(fd, length):
            raw = fs.nodes[descriptors[fd]][1]
            offset = offsets[fd]
            offsets[fd] += length
            return raw[offset:offset + length]
        def command(operation, argument):
            if operation == "owner":
                package = packages.get(argument)
                return (package + ": " + argument + "\n").encode() if package else b""
            if operation == "package":
                return (argument + "\t1\tamd64\tphp" + version + "\t1\n").encode()
            raise AssertionError("Native command forbidden: " + operation)
        with ExitStack() as stack:
            stack.enter_context(patch.object(os.path, "realpath", side_effect=fs.canonical))
            stack.enter_context(patch.object(os.path, "exists", side_effect=lambda p: str(p) in fs.nodes))
            stack.enter_context(patch.object(os, "lstat", side_effect=fs.info))
            stack.enter_context(patch.object(os, "readlink", return_value="../../mods-available/sockets.ini"))
            stack.enter_context(patch.object(os, "getxattr", return_value=b"", create=True))
            stack.enter_context(patch.object(os, "open", side_effect=open_file))
            stack.enter_context(patch.object(os, "read", side_effect=read_file))
            stack.enter_context(patch.object(os, "fstat", side_effect=lambda fd: fs.info(descriptors[fd])))
            stack.enter_context(patch.object(os, "close"))
            stack.enter_context(patch.object(os, "O_NOFOLLOW", 0x20000, create=True))
            stack.enter_context(patch.object(os, "O_CLOEXEC", 0x80000, create=True))
            stack.enter_context(patch.object(BASE, "parent_pins", side_effect=fs.parents))
            stack.enter_context(patch.object(guard, "read", side_effect=fs.read))
            stack.enter_context(patch.object(guard, "command", side_effect=command))
            launch = stack.enter_context(patch.object(GUARD.subprocess, "Popen",
                                                     side_effect=AssertionError("Root/PHP launch forbidden")))
            guard.origin(binary)
            for target in guard.targets:
                guard.special_read(target)
            yield guard, fs, packages
            launch.assert_not_called()

    def test_both_literal_pairs_and_root_argv_exclude_legacy_target(self):
        for version in ("8.2", "8.4"):
            guard = GUARD.Provision(version)
            expected = ("/etc/php/" + version + "/cli/php.ini",
                        "/etc/php/" + version + "/mods-available/sockets.ini")
            self.assertEqual(expected, guard.targets)
            self.assertEqual(("/usr/bin/sudo", "-n", "--user=root", "--", "/usr/bin/chmod",
                              "--", "0644", *expected), guard.root_argv)
            self.assertNotIn("99-pecl.ini", " ".join(guard.root_argv))

    def test_actual_socket_binder_verifies_package_template_digest_and_fixed_alias(self):
        for version in ("8.2", "8.4"):
            with self.subTest(version=version), self.model(version) as (guard, fs, packages):
                guard.verify_socket_configuration(initial=True)
                guard.verify_socket_configuration()
                self.assertEqual("php" + version + "-common",
                                 guard.receipt["socket_configuration"]["package"][0])
                self.assertEqual(GUARD.SOCKETS_SHA256,
                                 guard.receipt["socket_configuration"]["template_sha256"])

    def test_unowned_socket_template_refuses_before_root(self):
        with self.model() as (guard, fs, packages):
            del packages["/usr/share/php8.2-common/common/sockets.ini"]
            with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                guard.verify_socket_configuration(initial=True)

    def test_template_package_and_source_version_must_match_selected_interpreter(self):
        for kind in ("package", "source-version"):
            with self.subTest(kind=kind), self.model() as (guard, fs, packages):
                template = "/usr/share/php8.2-common/common/sockets.ini"
                guard.origin(template)
                metadata = guard.origins[template]["package"]
                metadata[0 if kind == "package" else 4] = "foreign"
                with self.assertRaisesRegex(BASE.Refusal, "^tool-origin$"):
                    guard.verify_socket_configuration(initial=True)

    def test_socket_content_and_manifest_md5_must_match_genuine_bytes(self):
        for kind in ("content", "manifest"):
            with self.subTest(kind=kind), self.model() as (guard, fs, packages):
                template = "/usr/share/php8.2-common/common/sockets.ini"
                if kind == "content":
                    fs.add(template, b"extension=foreign.so\n")
                else:
                    fs.add("/var/lib/dpkg/info/php8.2-common.md5sums",
                           b"00000000000000000000000000000000  usr/share/php8.2-common/common/sockets.ini\n")
                with self.assertRaisesRegex(BASE.Refusal, "^tool-hash$"):
                    guard.verify_socket_configuration(initial=True)

    def test_symlink_retarget_inode_owner_and_parent_drift_refuse(self):
        for kind in ("retarget", "inode", "owner", "parent"):
            with self.subTest(kind=kind), self.model() as (guard, fs, packages):
                guard.verify_socket_configuration(initial=True)
                alias = guard.receipt["socket_configuration"]["alias"]
                if kind == "parent":
                    fs.add("/etc/php/8.2/cli/conf.d", mode=stat.S_IFDIR | 0o777)
                else:
                    fs.add(alias, mode=stat.S_IFLNK | 0o777,
                           uid=1000 if kind == "owner" else 0,
                           target="/outside" if kind == "retarget" else guard.targets[1])
                with self.assertRaises(BASE.Refusal):
                    guard.verify_socket_configuration()

    def test_ordinary_file_alias_is_not_blanket_symlink_acceptance(self):
        with self.model() as (guard, fs, packages):
            alias = "/etc/php/8.2/cli/conf.d/20-sockets.ini"
            fs.add(alias, SOCKETS)
            with self.assertRaisesRegex(BASE.Refusal, "^tool-link$"):
                guard.verify_socket_configuration(initial=True)

    def test_real_guard_target_reservation_keeps_original_budget(self):
        with self.model() as (guard, fs, packages):
            self.assertEqual(len(b"; production\n") + len(SOCKETS), sum(
                guard.pins[target]["bytes"] for target in guard.targets))
            guard.bytes = BASE.TOTAL_LIMIT
            with self.assertRaisesRegex(BASE.Refusal, "^file-budget$"):
                guard.special_read(guard.targets[1], charge=False)
            self.assertEqual(BASE.TOTAL_LIMIT, guard.bytes)


if __name__ == "__main__":
    unittest.main()
