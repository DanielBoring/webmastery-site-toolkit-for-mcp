import importlib.util
import os
import pathlib
import shutil
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "adapter_assessment", ROOT / "scripts" / "adapter-release-assessment.py"
)
ASSESSMENT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ASSESSMENT)
CANDIDATE_SPEC = importlib.util.spec_from_file_location(
    "candidate_assessment", ROOT / "scripts" / "adapter-candidate-assessment.py"
)
CANDIDATE = importlib.util.module_from_spec(CANDIDATE_SPEC)
CANDIDATE_SPEC.loader.exec_module(CANDIDATE)


class AdapterReleaseAssessmentTest(unittest.TestCase):
    def test_assessment_freshness_and_lifecycle_use_selected_engine(self):
        import re
        import subprocess

        source = (ROOT / "scripts" / "adapter-release-assessment.sh").read_text()
        freshness = re.search(r"for resource in container network volume; do\n.*?\ndone\n", source, re.S)
        override = re.search(r"qa_compose_command\(\) \{\n.*?\n\}", source, re.S)
        self.assertIsNotNone(freshness)
        self.assertIsNotNone(override)
        script = (
            'set -Eeuo pipefail\nCOMPOSE_PROJECT_NAME=owned-assessment\n'
            'qa_engine() { printf "selected:%s\\n" "$*" >&2; }\n'
            'docker() { printf "default-engine\\n" >&2; return 95; }\n'
            + freshness.group() + override.group() + '\nqa_compose_command up -d wordpress\n'
        )
        result = subprocess.run(
            [shutil.which("bash"), "--noprofile", "--norc"], input=script.encode(), cwd=ROOT,
            env={**os.environ, "BASH_ENV": os.devnull}, capture_output=True, timeout=30,
        )
        self.assertEqual(0, result.returncode, result.stderr.decode())
        self.assertEqual(
            ["selected:" + resource + " ls -q --filter label=com.docker.compose.project=owned-assessment"
             for resource in ("container", "network", "volume")] +
            ["selected:compose --project-name owned-assessment -f docker-compose.yml "
             "-f tests/e2e/adapter-release-compose.yml up -d wordpress"],
            result.stderr.decode().splitlines(),
        )
        failed = subprocess.run(
            [shutil.which("bash"), "--noprofile", "--norc"],
            input=script.replace(
                'qa_engine() { printf "selected:%s\\n" "$*" >&2; }',
                'qa_engine() { printf "selected:%s\\n" "$*" >&2; return 42; }',
            ).encode(), cwd=ROOT, env={**os.environ, "BASH_ENV": os.devnull},
            capture_output=True, timeout=30,
        )
        self.assertEqual(42, failed.returncode, failed.stderr.decode())
        self.assertEqual(
            ["selected:container ls -q --filter label=com.docker.compose.project=owned-assessment"],
            failed.stderr.decode().splitlines(),
        )

    def test_each_adapter_restarts_and_waits_before_http_probe(self):
        import re
        import subprocess
        import tempfile

        source = (ROOT / "scripts" / "adapter-release-assessment.sh").read_text()
        loop = re.search(r"for adapter in 0\.6\.1 0\.7\.0; do\n.*?\ndone\n", source, re.S)
        self.assertIsNotNone(loop)
        with tempfile.TemporaryDirectory() as directory:
            script = (
                'set -Eeuo pipefail\nprivate="$ASSESSMENT_TEST_PRIVATE"; candidate=""; assessment_status=0\n'
                 'if command -v cygpath >/dev/null 2>&1; then private="$(cygpath -u "$private")"; fi\n'
                 'download() { :; }\nqa_wp() { :; }\n'
                 'compose() {\n'
                 'if [[ "$1" == port ]]; then\n'
                 'if [[ "$adapter" == 0.6.1 ]]; then printf "127.0.0.1:10061\\n"; '
                 'else printf "127.0.0.1:10070\\n"; fi\n'
                 'elif [[ "$1" == restart || "$1" == up ]]; then printf "%s\\n" "$*"; fi\n'
                 '}\npython() { printf "probe %s\\n" "$WSTM_ASSESSMENT_URL"; }\n' + loop.group()
            )
            result = subprocess.run(
                [shutil.which("bash"), "--noprofile", "--norc"], input=script.encode(), cwd=ROOT,
                env={**os.environ, "BASH_ENV": os.devnull, "ASSESSMENT_TEST_PRIVATE": directory},
                capture_output=True, timeout=30,
            )
            self.assertEqual(0, result.returncode, result.stderr.decode())
            self.assertEqual(
                ["restart wordpress", "up -d --wait --wait-timeout 180 wordpress",
                 "probe http://127.0.0.1:10061", "restart wordpress",
                 "up -d --wait --wait-timeout 180 wordpress", "probe http://127.0.0.1:10070"],
                result.stdout.decode().splitlines(),
            )

    @unittest.skipUnless(os.name == "posix", "Requires genuine POSIX permission semantics")
    def test_public_assets_are_readable_without_exposing_private_parent(self):
        import re
        import subprocess
        import tempfile
        import zipfile

        source = (ROOT / "scripts" / "adapter-release-assessment.sh").read_text()
        download = re.search(r"download\(\) \{.*?\n\}", source, re.S)
        extract = re.search(
            r"host_php -r '(.*?)' \"\$private/release/plugin.zip\" \"\$private/release\"",
            source, re.S,
        )
        self.assertIsNotNone(download)
        self.assertIsNotNone(extract)
        with tempfile.TemporaryDirectory() as directory:
            private = pathlib.Path(directory)
            private.chmod(0o700)
            release = private / "release"
            release.mkdir(mode=0o700)
            archive = private / "plugin.zip"
            with zipfile.ZipFile(archive, "w") as package:
                package.writestr("webmastery-site-toolkit-for-mcp/includes/fixture.php", "<?php\n")
            subprocess.run(
                ["php", "-r", "umask(0077);\n" + extract.group(1), str(archive), str(release)],
                check=True,
            )
            production = release / "webmastery-site-toolkit-for-mcp"
            self.assertEqual(0o755, production.stat().st_mode & 0o777)
            self.assertEqual(0o755, (production / "includes").stat().st_mode & 0o777)
            self.assertEqual(0o644, (production / "includes" / "fixture.php").stat().st_mode & 0o777)
            asset = private / "wp.phar"
            subprocess.run(
                ["bash", "-c", 'set -Eeuo pipefail; umask 077\n'
                 'bash() { printf fixture > "$5"; }\n' + download.group() +
                 '\ndownload url digest sha256 "$1"', "asset-mode-test", str(asset)],
                check=True,
            )
            self.assertEqual(0o644, asset.stat().st_mode & 0o777)
            self.assertEqual(0o700, private.stat().st_mode & 0o777)
            self.assertEqual(0o700, release.stat().st_mode & 0o777)

    def test_target_is_owned_loopback_only(self):
        ASSESSMENT.validate_target("http://127.0.0.1:12345", "owner", "owner")
        for url in (
            "https://127.0.0.1:12345", "http://example.com:12345",
            "http://localhost:12345", "http://127.0.0.1", "http://127.0.0.1:12345/",
            "http://user@127.0.0.1:12345", "http://127.0.0.1:12345?site=other",
            "http://127.0.0.1:12345#other",
        ):
            with self.subTest(url=url), self.assertRaises(RuntimeError):
                ASSESSMENT.validate_target(url, "owner", "owner")
        for owners in (("other", "owner"), ("", "")):
            with self.assertRaises(RuntimeError):
                ASSESSMENT.validate_target("http://127.0.0.1:12345", *owners)

    def test_redirects_are_never_followed(self):
        with self.assertRaises(RuntimeError):
            ASSESSMENT.NoRedirect().redirect_request(None, None, 302, "", {}, "https://example.com")

    def test_released_plain_text_error_is_not_canonical_json(self):
        wire = {"result": {"content": [{"type": "text", "text": "Post not found."}], "isError": True}}
        self.assertEqual("Post not found.", ASSESSMENT.error_text(wire))
        with self.assertRaises(ValueError):
            ASSESSMENT.payload(wire)
        wire["result"]["structuredContent"] = None
        with self.assertRaises(AssertionError):
            ASSESSMENT.error_text(wire)
        wire["result"]["structuredContent"] = {"leaked": True}
        with self.assertRaises(AssertionError):
            ASSESSMENT.error_text(wire)

    def test_success_requires_matching_json_and_structured_content(self):
        wire = {"result": {"content": [{"type": "text", "text": '{"success":true}'}],
                           "structuredContent": {"success": True}}}
        self.assertEqual({"success": True}, ASSESSMENT.payload(wire))
        wire["result"]["structuredContent"] = {"success": False}
        with self.assertRaises(AssertionError):
            ASSESSMENT.payload(wire)

    def test_candidate_canonical_error_requires_json_object_details(self):
        import json
        value = {"success": False, "error": {
            "code": "invalid_input", "reason": "ability_invalid_input", "message": "Invalid input.", "details": {},
        }}
        wire = {"result": {"isError": True, "content": [{"type": "text", "text": json.dumps(value)}]}}
        self.assertEqual(value, CANDIDATE.canonical(wire, "invalid_input", "ability_invalid_input"))
        wire["result"]["structuredContent"] = None
        with self.assertRaises(AssertionError):
            CANDIDATE.canonical(wire, "invalid_input")
        del wire["result"]["structuredContent"]
        value["error"]["details"] = []
        wire["result"]["content"][0]["text"] = json.dumps(value)
        with self.assertRaises(AssertionError):
            CANDIDATE.canonical(wire, "invalid_input")
        wire["result"]["content"][0]["text"] = "Invalid input."
        with self.assertRaises(ValueError):
            CANDIDATE.canonical(wire, "invalid_input")
        value["error"]["details"] = {}
        value["error"]["reason"] = []
        wire["result"]["content"][0]["text"] = json.dumps(value)
        with self.assertRaises(AssertionError):
            CANDIDATE.canonical(wire, "invalid_input")
        value["error"]["reason"] = "ability_invalid_input"
        wire["result"]["content"][0]["text"] = json.dumps(value)
        wire["result"]["content"].append({"type": "image", "data": "unexpected"})
        with self.assertRaises(AssertionError):
            CANDIDATE.canonical(wire, "invalid_input")

    def test_candidate_adapter_version_guards_remain_closed(self):
        import subprocess
        import re
        for name in ("error-contract-runner.php", "untrusted-content-runner.php"):
            source = (ROOT / "tests" / "e2e" / name).read_text()
            expression = re.search(
                r"in_array\( (\$plugin_data\['version'\]|\$summary\['adapter'\]\['version'\] \?\? null), "
                r"array\( '[0-9.]+'(?:, '[0-9.]+')* \), true \)", source
            )
            self.assertIsNotNone(expression)
            probe = re.sub(r"\$plugin_data\['version'\]|\$summary\['adapter'\]\['version'\] \?\? null",
                           "$version", expression.group())
            # The scheduled 0.7.1 lanes used to stop at this guard before
            # exercising any transport assertions. Admission stays explicit:
            # neither a future release nor a malformed version is assessed.
            for version, allowed in (("0.6.1", True), ("0.7.0", True), ("0.7.1", True),
                                     ("0.7.2", False), ("0.8.0", False),
                                     ("0.7.1-rc1", False), ("trunk", False), (None, False)):
                value = "null" if version is None else "'" + version + "'"
                actual = subprocess.check_output(
                    ["php", "-r", "$version=" + value + ";echo " + probe + ' ? "yes" : "no";'], text=True
                )
                self.assertEqual("yes" if allowed else "no", actual)

    def test_candidate_package_requires_exact_commit_bytes_and_inventory(self):
        import subprocess
        import tempfile
        import warnings
        import zipfile
        from unittest.mock import patch

        files = {
            "includes/fixture.php": b"<?php\n",
            "webmastery-site-toolkit-for-mcp.php": b"<?php\n",
            "readme.txt": b"Stable tag: 2.6.0\n",
            "LICENSE": b"Fixture license\n",
        }

        def git(command, **kwargs):
            if "rev-parse" in command:
                return CANDIDATE.SHA + "\n"
            if "ls-tree" in command:
                return "includes/fixture.php\n"
            relative = command[-1].removeprefix(CANDIDATE.SHA + ":")
            if relative not in files:
                raise subprocess.CalledProcessError(128, command)
            return files[relative]

        with tempfile.TemporaryDirectory() as directory, patch.object(
            CANDIDATE.subprocess, "check_output", side_effect=git
        ):
            archive = pathlib.Path(directory) / "candidate.zip"

            def write(members):
                with warnings.catch_warnings(), zipfile.ZipFile(archive, "w") as package:
                    warnings.simplefilter("ignore", UserWarning)
                    for name, value in members:
                        package.writestr(CANDIDATE.PREFIX + name, value)

            write(files.items())
            self.assertEqual(4, CANDIDATE.verify_package(directory, archive)["production_files_verified"])
            for members in (
                list(files.items())[:-1],
                [(name, b"changed" if name == "LICENSE" else value) for name, value in files.items()],
                list(files.items()) + [("LICENSE", files["LICENSE"])],
                list(files.items()) + [("../outside", b"unexpected")],
            ):
                write(members)
                with self.assertRaises(RuntimeError):
                    CANDIDATE.verify_package(directory, archive)
            with patch.object(CANDIDATE.subprocess, "check_output", return_value="different-commit\n"):
                with self.assertRaises(RuntimeError):
                    CANDIDATE.verify_package(directory, archive)

    def test_empty_discovery_tool_arguments_encode_as_object(self):
        import re
        import subprocess

        source = (ROOT / "tests" / "e2e" / "mcp-crud-runner.php").read_text()
        helper = re.search(r"function webmastery_mcp_e2e_call_tool\(.*?\n\}\n", source, re.S)
        self.assertIsNotNone(helper)
        stub = (
            "class Webmastery_MCP_E2E_Client {"
            "function call($method,$params) {echo json_encode($params['arguments']);return array();}}"
            "function webmastery_mcp_e2e_extract_tool_payload($result,$label) {return $result;}"
        )
        for arguments, expected in (("array()", "{}"), ("array('limit'=>1)", '{"limit":1}')):
            result = subprocess.check_output(
                ["php", "-r", stub + helper.group() +
                 "webmastery_mcp_e2e_call_tool(new Webmastery_MCP_E2E_Client(),'discover'," +
                 arguments + ",'fixture');"], text=True
            )
            self.assertEqual(expected, result)


if __name__ == "__main__":
    unittest.main()
