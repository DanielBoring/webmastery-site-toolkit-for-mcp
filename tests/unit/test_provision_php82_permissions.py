"""Provisioning models only: never launches local sudo, chmod or PHP."""

import importlib.util
import io
from pathlib import Path
import stat
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch


SOURCE = Path(__file__).resolve().parents[2] / "scripts" / "provision-php82-permissions.py"
SPEC = importlib.util.spec_from_file_location("php_setup_guard", SOURCE)
GUARD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GUARD)
BASE = GUARD.BASE


class Original(io.BytesIO):
    def fileno(self):
        return 5

    def close(self):
        self.flush()


class SetupPermissionGuardTest(unittest.TestCase):
    def info(self, **updates):
        values = dict(st_mode=stat.S_IFREG | 0o777, st_uid=0, st_gid=0,
                      st_nlink=1, st_size=64, st_dev=1, st_ino=2,
                      st_mtime_ns=3, st_ctime_ns=4)
        values.update(updates)
        return SimpleNamespace(**values)

    def pin(self, mode=0o777, **updates):
        return {"identity": BASE.identity(self.info(st_mode=stat.S_IFREG | mode, **updates)),
                "parents": {"root": {"uid": 0}}, "sha256": "a" * 64}

    def test_entry_requires_exact_mode_nonroot_and_owned_disposable_interval(self):
        env = {"WSTM_PROVISION_ACTIONS": "true", "WSTM_PROVISION_RUNNER": "github-hosted",
               "WSTM_PROVISION_JOB": "release-package-qa",
               "WSTM_PROVISION_INTERVAL": GUARD.MODE}
        with patch.object(GUARD.sys, "platform", "linux"), \
                patch.object(GUARD.subprocess, "Popen") as launch:
            GUARD.validate_entry(["guard", GUARD.MODE, "8.2"], env, 1000, 1000)
            for argv, uid, euid in ((["guard"], 1000, 1000),
                                    (["guard", GUARD.MODE, "/arbitrary"], 1000, 1000),
                                    (["guard", "runtime"], 1000, 1000),
                                    (["guard", GUARD.MODE, "8.2"], 0, 0),
                                    (["guard", GUARD.MODE, "8.2"], 1000, 0)):
                with self.assertRaises(GUARD.Refusal):
                    GUARD.validate_entry(argv, env, uid, euid)
            for key in env:
                wrong = dict(env, **{key: "false"})
                with self.assertRaises(GUARD.Refusal):
                    GUARD.validate_entry(["guard", GUARD.MODE, "8.2"], wrong, 1000, 1000)
            launch.assert_not_called()

    def test_two_only_literal_paths_and_finite_modes_ownership_links_and_bounds(self):
        for path in GUARD.TARGETS:
            GUARD.validate_setup_file(path, self.info())
            GUARD.validate_setup_file(path, self.info(st_mode=stat.S_IFREG | 0o644))
            for update in ({"st_uid": 1000}, {"st_nlink": 2},
                           {"st_mode": stat.S_IFLNK | 0o777},
                           {"st_mode": stat.S_IFREG | 0o4777},
                           {"st_mode": stat.S_IFREG | 0o666}, {"st_size": 1048577}):
                with self.subTest(path=path, update=update), self.assertRaises(GUARD.Refusal):
                    GUARD.validate_setup_file(path, self.info(**update))
        for path in ("/etc/php/8.4/cli/php.ini", "/etc/php/8.2/fpm/php.ini",
                     "/etc/php/8.2/cli/../php.ini", "/arbitrary"):
            with self.assertRaisesRegex(GUARD.Refusal, "unsafe-input"):
                GUARD.validate_setup_file(path, self.info())

    def test_sudo_only_setuid_exception_never_changes_readonly_gate_policy(self):
        info = self.info(st_mode=stat.S_IFREG | 0o4755)
        GUARD.validate_setup_file("/usr/bin/sudo", info)
        with self.assertRaises(GUARD.Refusal):
            BASE.validate_file(info, 0, BASE.FILE_LIMIT)
        for mode in (0o2775, 0o4777):
            with self.assertRaises(GUARD.Refusal):
                GUARD.validate_setup_file("/usr/bin/sudo", self.info(st_mode=stat.S_IFREG | mode))

    def test_symlink_caps_or_unsafe_parent_refuse_before_bytes_or_root(self):
        guard = GUARD.Provision()
        with patch.object(GUARD.os.path, "realpath", return_value="/outside"), \
                patch.object(GUARD.os, "open") as open_file:
            with self.assertRaises(GUARD.Refusal):
                guard.special_read(GUARD.TARGETS[0])
            open_file.assert_not_called()
        for kind in ("caps", "parent"):
            with patch.object(GUARD.os.path, "realpath", side_effect=lambda path: path), \
                    patch.object(GUARD.os, "lstat", return_value=self.info()), \
                    patch.object(BASE, "parent_pins", return_value={}, side_effect=(
                        GUARD.Refusal("tool-parent") if kind == "parent" else None)), \
                    patch.object(GUARD.os, "getxattr", return_value=b"caps", create=True), \
                    patch.object(GUARD.os, "open") as open_file:
                with self.assertRaises(GUARD.Refusal):
                    guard.special_read(GUARD.TARGETS[0])
                open_file.assert_not_called()

    def test_config_initial_and_repeat_hash_reads_share_original_pre_read_budget(self):
        guard = GUARD.Provision()
        for charge in (True, False):
            guard.bytes = BASE.TOTAL_LIMIT
            with patch.object(GUARD.os.path, "realpath", side_effect=lambda path: path), \
                    patch.object(GUARD.os, "lstat", return_value=self.info()), \
                    patch.object(BASE, "parent_pins", return_value={}), \
                    patch.object(GUARD, "no_capabilities"), \
                    patch.object(GUARD.os, "open") as open_file:
                with self.assertRaisesRegex(GUARD.Refusal, "file-budget"):
                    guard.special_read(GUARD.TARGETS[0], charge=charge)
                open_file.assert_not_called()

    def test_wrong_selected_php_version_refuses_before_config_or_root(self):
        guard = GUARD.Provision()
        with patch.object(guard, "system", return_value="/usr/bin/php8.4"), \
                patch.object(guard, "origin"), patch.object(guard, "dependencies"), \
                patch.object(guard, "pin_sudo_configuration"), \
                patch.object(guard, "special_read") as read, \
                patch.object(guard, "capture_root") as root:
            with self.assertRaisesRegex(GUARD.Refusal, "php-config"):
                guard.prepare()
            read.assert_not_called()
            root.assert_not_called()

    def test_sudo_loader_accepts_only_pinned_fixed_private_directories(self):
        names, loaders, search = GUARD.sudo_dynamic(
            b"(RUNPATH) Library runpath: [/usr/libexec/sudo]\n"
            b"(NEEDED) Shared library: [libsudo_util.so.0]")
        self.assertEqual((["libsudo_util.so.0"], [], ["/usr/libexec/sudo"]),
                         (names, loaders, search))
        for raw in (b"(RUNPATH) [$ORIGIN]", b"(RPATH) [/arbitrary]",
                    b"(RUNPATH) [/usr/libexec/sudo:]", b"(RUNPATH) no-path"):
            with self.assertRaises(GUARD.Refusal):
                GUARD.sudo_dynamic(raw)

    def test_custom_sudo_plugin_configuration_never_becomes_callback_authority(self):
        guard = GUARD.Provision()
        for raw in (b"Plugin arbitrary arbitrary.so\n", b"Path plugin_dir /arbitrary\n"):
            with patch.object(GUARD.os.path, "lexists", return_value=True), \
                    patch.object(GUARD.os.path, "realpath", side_effect=lambda path: path), \
                    patch.object(guard, "acquire", return_value=("", None, raw)), \
                    patch.object(guard, "origin") as origin:
                with self.assertRaisesRegex(GUARD.Refusal, "tool-loader"):
                    guard.pin_sudo_configuration()
                origin.assert_not_called()

    def test_policy_plugin_must_have_same_actual_installed_source_package_identity(self):
        guard = GUARD.Provision()
        guard.origins = {GUARD.SYSTEM[0]: {"package": ["sudo", "1"]},
                         GUARD.POLICY_MODULES[0]: {"package": ["foreign", "1"]}}
        with patch.object(GUARD.os.path, "lexists",
                          side_effect=lambda path: path == GUARD.POLICY_MODULES[0]), \
                patch.object(guard, "origin"), patch.object(guard, "dependencies"):
            with self.assertRaisesRegex(GUARD.Refusal, "tool-origin"):
                guard.pin_sudo_configuration()

    def test_original_config_handle_full_hash_predebit_and_no_symlink_follow_expansion(self):
        guard = GUARD.Provision()
        path = GUARD.TARGETS[0]
        info = self.info()
        with patch.object(GUARD.os.path, "realpath", side_effect=lambda value: value), \
                patch.object(GUARD.os, "lstat", return_value=info), \
                patch.object(BASE, "parent_pins", return_value={}), \
                patch.object(GUARD, "no_capabilities"), \
                patch.object(GUARD.os, "O_NOFOLLOW", 0x20000, create=True), \
                patch.object(GUARD.os, "O_CLOEXEC", 0x80000, create=True), \
                patch.object(GUARD.os, "open", return_value=5), \
                patch.object(GUARD.os, "fstat", return_value=info), \
                patch.object(GUARD.os, "read", side_effect=lambda fd, length: b"x" * length) as read, \
                patch.object(GUARD.os, "close") as close:
            _, raw, pin = guard.special_read(path)
            self.assertEqual((1, 64), (len(guard.pins), guard.bytes))
            self.assertEqual(GUARD.hashlib.sha256(raw).hexdigest(), pin["sha256"])
            self.assertEqual(5, guard.target_handles[path])
            read.assert_called_once_with(5, 64)
            close.assert_not_called()
            guard.close_targets()
            close.assert_called_once_with(5)

    def test_dynamic_command_or_configured_php_and_repeat_hardening_are_rejected(self):
        guard = GUARD.Provision()
        with patch.object(GUARD.subprocess, "Popen") as launch:
            for operation in ("sudo", "chmod", "php", "callback"):
                with self.assertRaises(GUARD.Refusal):
                    guard.command(operation, "/arbitrary")
            for mode in (GUARD.MODE, "chmod-0777", "/arbitrary"):
                with self.assertRaises(GUARD.Refusal):
                    guard.harden(mode)
            guard.ready = guard.root_used = True
            with self.assertRaises(GUARD.Refusal):
                guard.harden(GUARD.MODE)
            launch.assert_not_called()

    def test_0777_to_0644_model_keeps_content_identity_and_only_two_targets(self):
        guard = GUARD.Provision()
        guard.ready = True
        guard.receipt["configuration_before"] = {path: self.pin() for path in GUARD.TARGETS}
        guard.target_handles = {path: 5 for path in GUARD.TARGETS}
        modes = {path: 0o777 for path in GUARD.TARGETS}
        def chmod_model():
            self.assertEqual(GUARD.ROOT_ARGV[-2:], GUARD.TARGETS)
            for path in GUARD.TARGETS:
                modes[path] = 0o644
        def read_model(path, charge=False):
            pin = self.pin(modes[path])
            return self.info(st_mode=stat.S_IFREG | modes[path]), b"unchanged", pin
        with patch.object(guard, "recheck_before"), \
                patch.object(guard, "recheck_tools"), \
                patch.object(guard, "capture_root", side_effect=chmod_model) as root, \
                patch.object(guard, "special_read", side_effect=read_model), \
                patch.object(GUARD.os, "fstat", return_value=self.info(st_mode=stat.S_IFREG | 0o644)):
            guard.harden(GUARD.MODE)
            root.assert_called_once()
            self.assertEqual("permission-postcondition-verified", guard.receipt["state"])
            self.assertEqual(set(GUARD.TARGETS), set(guard.receipt["configuration_after"]))
            with self.assertRaises(GUARD.Refusal):
                guard.harden(GUARD.MODE)

    def test_post_hash_inode_owner_parent_or_mtime_race_refuses(self):
        before = self.pin()
        GUARD.unchanged_content(before, self.pin(0o644, st_ctime_ns=10))
        variants = [self.pin(0o644, st_ino=99), self.pin(0o644, st_uid=1000),
                    self.pin(0o644, st_mtime_ns=99)]
        variants += [dict(self.pin(0o644), sha256="b" * 64),
                     dict(self.pin(0o644), parents={})]
        for after in variants:
            with self.assertRaisesRegex(GUARD.Refusal, "tool-link"):
                GUARD.unchanged_content(before, after)

    def test_prelaunch_hash_race_or_original_deadline_never_launches_root_or_retries(self):
        guard = GUARD.Provision()
        guard.ready = True
        with patch.object(guard, "recheck_before", side_effect=GUARD.Refusal("tool-link")), \
                patch.object(guard, "capture_root") as root:
            with self.assertRaises(GUARD.Refusal):
                guard.harden(GUARD.MODE)
            with self.assertRaisesRegex(GUARD.Refusal, "unsafe-input"):
                guard.harden(GUARD.MODE)
            root.assert_not_called()
        with patch.object(BASE.time, "monotonic", return_value=guard.deadline), \
                patch.object(GUARD.subprocess, "Popen") as launch:
            with self.assertRaisesRegex(GUARD.Refusal, "inventory-deadline"):
                guard.capture_root()
            launch.assert_not_called()

    def test_source_pins_and_retention_failure_do_not_get_free_budget_or_launch(self):
        guard = GUARD.Provision()
        guard.bytes = BASE.TOTAL_LIMIT
        with patch.object(GUARD.os.path, "realpath", side_effect=lambda value: value), \
                patch.object(GUARD.os, "lstat", return_value=self.info(
                    st_uid=1000, st_mode=stat.S_IFREG | 0o644)), \
                patch.object(GUARD.os, "geteuid", return_value=1000, create=True), \
                patch.object(guard, "read") as read:
            with self.assertRaisesRegex(GUARD.Refusal, "file-budget"):
                guard.pin_source("/ordinary-guard-source.py")
            read.assert_not_called()
        guard.ready = guard.root_used = True
        with patch.object(guard, "write", side_effect=OSError("PRIVATE_SENTINEL")), \
                patch.object(GUARD.subprocess, "Popen") as launch:
            with self.assertRaises(OSError):
                guard.capture_root()
            launch.assert_not_called()

    def capture_model(self, exit_code=0, incomplete=False, retention=False, stderr=b"", version="8.2"):
        guard = GUARD.Provision(version)
        guard.ready = guard.root_used = True
        guard.directory = Path("/private-evidence")
        pipe1, pipe2 = Mock(), Mock()
        pipe1.fileno.return_value, pipe2.fileno.return_value = 31, 32
        child = Mock(stdout=pipe1, stderr=pipe2, pid=42)
        child.poll.return_value = exit_code
        events = [SimpleNamespace(fileobj=pipe1, data=1), SimpleNamespace(fileobj=pipe2, data=2)]
        selector = Mock()
        selector.get_map.side_effect = [True, False]
        selector.select.return_value = [(event, None) for event in events]
        observed = []
        def write(name, raw):
            observed.append((name, raw))
            if retention and "observed" in name:
                raise OSError("PRIVATE_SENTINEL")
        guard.write = Mock(side_effect=write)
        guard.original = Mock(side_effect=[Original(), Original()])
        guard.read = Mock(side_effect=lambda path, *args: (path, self.info(), b""))
        checks = Mock(side_effect=[None, None, GUARD.Refusal("inventory-deadline")] if incomplete else None)
        error = None
        with patch.object(guard, "check", checks), \
                patch.object(GUARD.selectors, "DefaultSelector", return_value=selector), \
                patch.object(GUARD.subprocess, "Popen", return_value=child) as launch, \
                patch.object(GUARD.os, "fstat", return_value=self.info()), \
                patch.object(GUARD.os, "geteuid", return_value=1000, create=True), \
                patch.object(GUARD.os, "fsync"), patch.object(GUARD.os, "set_blocking", create=True), \
                patch.object(GUARD.os, "read", side_effect=lambda fd, size: stderr if fd == 32 else b""), \
                patch.object(guard, "retain_chunk", side_effect=GUARD.Refusal("native-output")):
            try:
                guard.capture_root()
            except Exception as caught:
                error = caught
        child.kill.assert_not_called()
        child.terminate.assert_not_called()
        self.assertEqual(list(GUARD.ROOT_ARGVS[version]), launch.call_args.args[0])
        self.assertEqual({"PATH": "/usr/bin:/bin", "LC_ALL": "C"}, launch.call_args.kwargs["env"])
        self.assertTrue(launch.call_args.kwargs["close_fds"])
        self.assertLess(next(i for i, row in enumerate(observed) if "intent" in row[0]),
                        next(i for i, row in enumerate(observed) if "reserved" in row[0]))
        return guard, error, observed

    def test_original_root_capture_precedes_success_interpretation(self):
        guard, error, observed = self.capture_model()
        self.assertIsNone(error)
        record = guard.commands[-1]
        self.assertEqual(0, record["exit"])
        self.assertTrue(record["stdout_eof"] and record["stderr_eof"])
        self.assertFalse(record["root_cleanup_certified"])
        self.assertTrue(any("observed" in name for name, raw in observed))

    def test_nonzero_unknown_exit_missing_eof_and_stderr_never_certify_success(self):
        for kwargs in ({"exit_code": 1}, {"exit_code": None, "incomplete": True},
                       {"incomplete": True}, {"stderr": b"error"}):
            with self.subTest(kwargs=kwargs):
                guard, error, observed = self.capture_model(**kwargs)
                self.assertIsNotNone(error)
                self.assertFalse(guard.commands[-1]["root_cleanup_certified"])
                self.assertTrue(any("observed" in name for name, raw in observed))
                if kwargs.get("incomplete"):
                    self.assertFalse(guard.commands[-1]["stdout_eof"])
                    self.assertFalse(guard.commands[-1]["stderr_eof"])
        guard, error, _ = self.capture_model(exit_code=None, incomplete=True)
        self.assertIsNone(guard.commands[-1]["exit"])

    def test_observed_retention_failure_cannot_publish_postcondition_success(self):
        _, error, _ = self.capture_model(retention=True)
        self.assertIsInstance(error, OSError)

    def test_php84_uses_exact_approved_argv_and_original_capture_guards(self):
        guard, error, _ = self.capture_model(version="8.4")
        self.assertIsNone(error)
        self.assertEqual(list(GUARD.ROOT_ARGVS["8.4"]), guard.commands[-1]["argv"])
        self.assertEqual(BASE.PHP_PROFILES["8.4"]["targets"], tuple(guard.commands[-1]["argv"][-2:]))
        self.assertEqual("8.4", guard.receipt["selected_php"])

    def test_php84_0777_to_0644_model_preserves_content_and_refuses_after_D(self):
        guard = GUARD.Provision("8.4")
        guard.ready = True
        guard.receipt["configuration_before"] = {path: self.pin() for path in guard.targets}
        guard.target_handles = {path: 5 for path in guard.targets}
        with patch.object(guard, "recheck_before"), patch.object(guard, "recheck_tools"), \
                patch.object(guard, "capture_root") as root, \
                patch.object(guard, "special_read", return_value=(
                    self.info(st_mode=stat.S_IFREG | 0o644), b"unchanged", self.pin(0o644))), \
                patch.object(GUARD.os, "fstat", return_value=self.info(st_mode=stat.S_IFREG | 0o644)):
            guard.harden(GUARD.MODE)
            root.assert_called_once()
            self.assertEqual(set(guard.targets), set(guard.receipt["configuration_after"]))
        for excess in (0, 0.001):
            refused = GUARD.Provision("8.4")
            refused.ready = refused.root_used = True
            refused.directory = Path("/private-evidence")
            clock = [refused.deadline - 1]
            retained = []
            def write(name, raw):
                retained.append(name)
                if ".reserved." in name:
                    clock[0] = refused.deadline + excess
            with patch.object(BASE.time, "monotonic", side_effect=lambda: clock[0]), \
                    patch.object(refused, "write", side_effect=write), \
                    patch.object(refused, "original", side_effect=[Original(), Original()]), \
                    patch.object(refused, "read", side_effect=GUARD.Refusal("inventory-deadline")), \
                    patch.object(GUARD.os, "fstat", return_value=self.info()), \
                    patch.object(GUARD.os, "geteuid", return_value=1000, create=True), \
                    patch.object(GUARD.os, "fsync"), patch.object(GUARD.subprocess, "Popen") as launch:
                with self.assertRaises(GUARD.Refusal):
                    refused.capture_root()
                launch.assert_not_called()
            self.assertTrue(refused.root_used)
            self.assertEqual(1, len(refused.commands))
            self.assertIn("command-1.observed.private.json", retained)
            self.assertIsNone(refused.commands[0]["exit"])

    def test_retained_reservation_at_or_past_original_deadline_launches_no_root_or_system_child(self):
        for root in (True, False):
            for excess in (0, 0.001):
                with self.subTest(root=root, excess=excess):
                    guard = GUARD.Provision() if root else BASE.Inventory()
                    guard.directory = Path("/private-evidence")
                    guard.ready = guard.root_used = True
                    guard.files = {"/usr/bin/setpriv": {"elf": True},
                                   "/usr/bin/readelf": {"elf": True}}
                    original_deadline = guard.deadline
                    clock = [original_deadline - 1]
                    originals = {}
                    def original(name):
                        stream = Original()
                        originals[name] = stream
                        return stream
                    def write(name, raw):
                        originals[name] = bytes(raw)
                        if ".reserved." in name:
                            clock[0] = original_deadline + excess
                    def read(*args, **kwargs):
                        guard.check()
                        self.fail("Expired retention read cannot acquire new bytes")
                    guard.original = Mock(side_effect=original)
                    guard.write = Mock(side_effect=write)
                    with patch.object(BASE.time, "monotonic", side_effect=lambda: clock[0]), \
                            patch.object(guard, "system", side_effect=lambda path: path), \
                            patch.object(guard, "read", side_effect=read), \
                            patch.object(GUARD.os, "fstat", return_value=self.info()), \
                            patch.object(GUARD.os, "geteuid", return_value=1000, create=True), \
                            patch.object(GUARD.os, "fsync"), \
                            patch.object(GUARD.subprocess, "Popen") as launch:
                        with self.assertRaises(GUARD.Refusal):
                            if root:
                                guard.capture_root()
                            else:
                                guard.command("elf", "/usr/bin/setpriv")
                        launch.assert_not_called()
                    self.assertEqual(original_deadline, guard.deadline)
                    self.assertTrue(guard.root_used)
                    self.assertEqual(1, len(guard.commands))
                    record = guard.commands[0]
                    self.assertEqual("incomplete", record["failure"])
                    self.assertIsNone(record["exit"])
                    self.assertFalse(record["stdout_eof"] or record["stderr_eof"])
                    self.assertTrue(record["retention_failed"])
                    for suffix in ("intent.private.json", "reserved.private.json",
                                   "observed.private.json", "stdout.private", "stderr.private"):
                        self.assertIn("command-1." + suffix, originals)
                    for label in ("stdout", "stderr"):
                        self.assertEqual(b"", originals["command-1." + label + ".private"].getvalue())
                    if root:
                        self.assertIsNone(record["producer_pid"])
                        self.assertFalse(record["root_cleanup_certified"])
                        with self.assertRaisesRegex(GUARD.Refusal, "unsafe-input"):
                            guard.harden(GUARD.MODE)

    def test_workflow_hardening_before_first_candidate_php_and_failure_diagnostic_is_gated(self):
        workflow = (SOURCE.parents[1] / ".github/workflows/release-package-qa.yml").read_text()
        setup = workflow.index("- name: Set up PHP")
        provision = workflow.index("- name: Guard and acquire selected HOST prerequisites")
        custody = workflow.index("run: php tests/support/private-custody-context.php")
        self.assertLess(setup, provision)
        self.assertLess(provision, custody)
        self.assertIn("always() && steps.php-permissions.outcome == 'success'", workflow)
        self.assertIn("extensions: zip", workflow)
        self.assertNotIn("extensions: zip, sockets", workflow)
        self.assertEqual(GUARD.ROOT_ARGV,
                         ("/usr/bin/sudo", "-n", "--user=root", "--", "/usr/bin/chmod",
                          "--", "0644", *GUARD.TARGETS))


if __name__ == "__main__":
    unittest.main()
