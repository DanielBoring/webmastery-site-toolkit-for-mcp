import importlib.util
import pathlib
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
                r"array\( '0\.6\.1', '0\.7\.0' \), true \)", source
            )
            self.assertIsNotNone(expression)
            probe = re.sub(r"\$plugin_data\['version'\]|\$summary\['adapter'\]\['version'\] \?\? null",
                           "$version", expression.group())
            for version, allowed in (("0.6.1", True), ("0.7.0", True), ("0.7.1", False),
                                     ("trunk", False), (None, False)):
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
