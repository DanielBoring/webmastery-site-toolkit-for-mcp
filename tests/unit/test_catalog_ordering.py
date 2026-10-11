"""Execute the actual main ordering with all external acquisition mocked."""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


class CatalogOrderingTest(unittest.TestCase):
    def run_main(self, package=False, product_status=0):
        bash = shutil.which("bash")
        self.assertIsNotNone(bash, "The existing Bash workflow runner is required")
        with tempfile.TemporaryDirectory(prefix="wstm-catalog-order-") as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            scripts.mkdir()
            for name in ("e2e-test.sh", "qa-compose.sh", "qa-http-quiet.sh",
                         "destructive-retention.sh"):
                (scripts / name).write_text(
                    (ROOT / "scripts" / name).read_text(encoding="utf-8"),
                    encoding="utf-8",
                )
            wrappers = (
                "wstm116_require_no_retention start_compose wait_for_wordpress_files "
                "configure_owned_url qa_initialize_artifacts load_dependencies "
                "install_wp_cli configure_test_cron install_wordpress "
                "configure_http_auth_forwarding configure_application_passwords "
                "install_plugins run_php_lint run_ability_manifest "
                "run_database_privacy_qa run_trash_safety run_mcp_crud "
                "run_destructive_safety_qa run_parent_assignment_qa "
                "run_post_meta_authorization_qa run_error_contract_qa "
                "run_metadata_boundary_qa run_input_schema_qa run_debug_log_check"
            ).split()
            script = 'source scripts/e2e-test.sh all\n'
            script += "trace() { printf '%s\\n' \"$1\" >> trace.private; }\n"
            for name in wrappers:
                script += f"{name}() {{ trace {name}; }}\n"
            script += (
                "host_php() { trace \"host_php:$*\"; "
                "if [[ \"$1\" == scripts/source-identity.php ]]; then "
                "printf '%064d' 0; fi; }\n"
                "compose() { trace \"compose:$*\"; }\n"
                "cleanup_compose() { trace cleanup_compose; }\n"
                f"run_product_qa() {{ trace run_product_qa; return {product_status}; }}\n"
                "export COMPOSE_PROJECT_NAME=wstm-catalog-order\n"
            )
            if package:
                script += (
                    "export E2E_PACKAGE_ROOT=/owned-package "
                    "E2E_PACKAGE_ZIP=/original.zip E2E_MANAGE_COMPOSE=1\n"
                )
            script += "main\n"
            result = subprocess.run(
                [bash, "--noprofile", "--norc"], input=script.encode("utf-8"),
                cwd=root, capture_output=True, timeout=30,
                env={**os.environ, "BASH_ENV": os.devnull},
            )
            trace = (root / "trace.private").read_text(encoding="utf-8").splitlines()
            return result, trace

    def test_source_and_package_run_product_once_before_every_legacy_suite(self):
        for package in (False, True):
            with self.subTest(package=package):
                result, trace = self.run_main(package)
                self.assertEqual(0, result.returncode, result.stderr.decode("utf-8"))
                self.assertEqual(1, trace.count("run_product_qa"))
                product = trace.index("run_product_qa")
                self.assertLess(trace.index("install_plugins"), product)
                self.assertLess(trace.index("configure_application_passwords"), product)
                for later in ("run_ability_manifest", "run_mcp_crud",
                              "run_destructive_safety_qa", "run_parent_assignment_qa",
                              "run_input_schema_qa", "run_debug_log_check"):
                    self.assertGreater(trace.index(later), product)
                self.assertTrue(any("comments-runner.php" in x for x in trace))
                self.assertTrue(any("scheduling-runner.php" in x for x in trace))
                if package:
                    self.assertTrue(any("runtime-package /owned-package /original.zip" in x
                                        for x in trace))

    def test_failed_product_preserves_failure_and_does_not_enter_legacy_checks(self):
        for package in (False, True):
            result, trace = self.run_main(package, 37)
            self.assertEqual(37, result.returncode)
            self.assertEqual(1, trace.count("run_product_qa"))
            self.assertNotIn("run_ability_manifest", trace)
            self.assertNotIn("run_mcp_crud", trace)
            self.assertIn("cleanup_compose", trace)

    def test_actual_product_loop_and_receipt_checks_keep_untrusted_before_bounded(self):
        source = (ROOT / "scripts" / "e2e-test.sh").read_text(encoding="utf-8")
        body = source.split("run_product_qa() (", 1)[1].split("\nmain()", 1)[0]
        self.assertEqual(1, body.count("for stage in untrusted bounded; do"))
        self.assertIn('if [[ "$stage" == bounded ]]; then', body)
        self.assertIn("install-owned-wordpress.php\" --verify", body)
        self.assertIn("for name in fresh-install.private.json fresh-install.before.private.json "
                      "fresh-install.inserts.private.json fresh-install.after.private.json;", source)
        self.assertNotIn("qa_export_artifact /qa-artifacts/fresh-install", body)
        self.assertIn('cleanup_ready=0', body)


if __name__ == "__main__":
    unittest.main()
