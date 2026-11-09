"""Tests for memo.py: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout."""

import json
import os
import re
import stat
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
import memo  # noqa: E402


def write(path, text):
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text)
    return path


class Files(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.dir = self.tmp.name

    def path(self, name):
        return os.path.join(self.dir, name)


class HeaderTest(Files):
    def test_header_found_in_first_twenty_lines(self):
        script = write(self.path("a.sh"), "#!/usr/bin/env bash\n# a check\n# memo: commit\nexit 0\n")
        self.assertTrue(memo.is_memoizable(script))

    def test_header_absent_is_not_memoizable(self):
        script = write(self.path("a.sh"), "#!/usr/bin/env bash\n# a check\nexit 0\n")
        self.assertFalse(memo.is_memoizable(script))

    def test_header_after_line_twenty_is_ignored(self):
        script = write(self.path("a.sh"), "#!/usr/bin/env bash\n" + "# filler\n" * 25 + "# memo: commit\n")
        self.assertFalse(memo.is_memoizable(script))


class KeyTest(Files):
    def setUp(self):
        super().setUp()
        self.script = write(self.path("check.sh"), "echo one\n")
        self.lib = write(self.path("lib.sh"), "lib one\n")
        self.helper = write(self.path("helper.py"), "helper one\n")

    def key(self, **changes):
        arguments = dict(commit="a" * 40, script=self.script, lib=self.lib, helpers=[self.helper],
                         param="8.5", facts={"SITE_SHORTCODE": "mg", "SITE_PHP_CURRENT": "8.5"},
                         tool_id="tools-1")
        arguments.update(changes)
        return memo.check_key(**arguments)

    def test_key_is_stable(self):
        self.assertEqual(self.key(), self.key())

    def test_key_changes_with_commit(self):
        self.assertNotEqual(self.key(), self.key(commit="b" * 40))

    def test_key_changes_with_script_bytes(self):
        before = self.key()
        write(self.script, "echo two\n")
        self.assertNotEqual(before, self.key())

    def test_key_changes_with_lib_bytes(self):
        before = self.key()
        write(self.lib, "lib two\n")
        self.assertNotEqual(before, self.key())

    def test_key_changes_with_a_helper_file(self):
        before = self.key()
        write(self.helper, "helper two\n")
        self.assertNotEqual(before, self.key())

    def test_key_changes_with_param(self):
        self.assertNotEqual(self.key(), self.key(param="8.4"))

    def test_key_changes_with_a_fact(self):
        self.assertNotEqual(self.key(), self.key(facts={"SITE_SHORTCODE": "mg", "SITE_PHP_CURRENT": "8.4"}))

    def test_key_changes_with_tool_id(self):
        self.assertNotEqual(self.key(), self.key(tool_id="tools-2"))

    def test_key_same_for_none_and_empty_fact(self):
        self.assertEqual(self.key(facts={"SITE_TEST_DOMAIN": None}), self.key(facts={"SITE_TEST_DOMAIN": ""}))

    def test_key_stable_across_fact_order(self):
        self.assertEqual(self.key(facts={"A": "1", "B": "2"}), self.key(facts={"B": "2", "A": "1"}))

    def test_key_does_not_confuse_fact_boundaries(self):
        self.assertNotEqual(self.key(facts={"A": "1", "B": "2"}), self.key(facts={"A": "1B", "": "2"}))


class ToolIdentityTest(Files):
    def test_tool_identity_changes_with_a_runner_script(self):
        mcp = os.path.join(self.dir, "mcp")
        os.mkdir(mcp)
        write(os.path.join(mcp, "run-phpstan.sh"), "one\n")
        before = memo.tool_identity(mcp)
        write(os.path.join(mcp, "run-phpstan.sh"), "two\n")
        self.assertNotEqual(before, memo.tool_identity(mcp))

    def make_mcp(self):
        mcp = os.path.join(self.dir, "mcp")
        os.mkdir(mcp)
        write(os.path.join(mcp, "run-phpstan.sh"), "one\n")
        write(os.path.join(mcp, "runner-image.sh"), "image one\n")
        write(os.path.join(mcp, "repos.yaml"), "mg:\n  php_target: \"8.3\"\n")
        return mcp, os.path.join(self.dir, "override.yaml")

    def test_tool_identity_changes_with_the_runner_image_chooser(self):
        mcp, none = self.make_mcp()
        before = memo.tool_identity(mcp, none)
        write(os.path.join(mcp, "runner-image.sh"), "image two\n")
        self.assertNotEqual(before, memo.tool_identity(mcp, none))

    def test_tool_identity_changes_with_the_committed_registry(self):
        mcp, none = self.make_mcp()
        before = memo.tool_identity(mcp, none)
        write(os.path.join(mcp, "repos.yaml"), "mg:\n  php_target: \"8.5\"\n")
        self.assertNotEqual(before, memo.tool_identity(mcp, none))

    def test_tool_identity_changes_when_a_personal_registry_appears_or_changes(self):
        mcp, override = self.make_mcp()
        absent = memo.tool_identity(mcp, override)
        write(override, "mg:\n  php_target: \"8.4\"\n")
        present = memo.tool_identity(mcp, override)
        write(override, "mg:\n  php_target: \"8.5\"\n")
        self.assertEqual(len({absent, present, memo.tool_identity(mcp, override)}), 3)

    def test_tool_identity_ignores_other_files(self):
        mcp = os.path.join(self.dir, "mcp")
        os.mkdir(mcp)
        write(os.path.join(mcp, "run-phpstan.sh"), "one\n")
        before = memo.tool_identity(mcp)
        write(os.path.join(mcp, "README.md"), "words\n")
        self.assertEqual(before, memo.tool_identity(mcp))


class MemoTest(Files):
    def setUp(self):
        super().setUp()
        self.file = self.path("state/memo-mg.json")

    def test_record_then_lookup_roundtrip(self):
        first = memo.Memo(self.file)
        first.record("k1", "c" * 40, "fail", "3 not current")
        self.assertTrue(first.save("c" * 40))
        again = memo.Memo(self.file).lookup("k1")
        self.assertEqual((again["outcome"], again["detail"]), ("fail", "3 not current"))
        self.assertRegex(again["at_utc"], r"^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$")

    def test_unknown_and_other_outcomes_are_not_recorded(self):
        memory = memo.Memo(self.file)
        for outcome in ("unknown", "timeout", ""):
            memory.record("k1", "c" * 40, outcome, "could not tell")
        self.assertIsNone(memory.lookup("k1"))

    def test_a_fail_with_no_detail_is_not_recorded(self):
        # A check that crashed with exit 1 before printing anything says nothing worth keeping.
        memory = memo.Memo(self.file)
        memory.record("k1", "c" * 40, "fail", "")
        self.assertIsNone(memory.lookup("k1"))

    def test_a_pass_with_no_detail_is_recorded(self):
        memory = memo.Memo(self.file)
        memory.record("k1", "c" * 40, "pass", "")
        self.assertEqual(memory.lookup("k1")["outcome"], "pass")

    def test_save_drops_other_commits(self):
        memory = memo.Memo(self.file)
        memory.record("old", "1" * 40, "pass", "fine")
        memory.record("new", "2" * 40, "pass", "fine")
        memory.save("2" * 40)
        reloaded = memo.Memo(self.file)
        self.assertIsNone(reloaded.lookup("old"))
        self.assertIsNotNone(reloaded.lookup("new"))

    def test_malformed_file_is_empty_and_save_rewrites_it(self):
        os.makedirs(os.path.dirname(self.file))
        write(self.file, '{"trunc')
        memory = memo.Memo(self.file)
        self.assertIsNone(memory.lookup("k1"))
        memory.record("k1", "c" * 40, "pass", "fine")
        self.assertTrue(memory.save("c" * 40))
        with open(self.file, encoding="utf-8") as handle:
            self.assertEqual(json.load(handle)["k1"]["outcome"], "pass")

    def test_wrong_shaped_json_is_empty(self):
        os.makedirs(os.path.dirname(self.file))
        write(self.file, '["not", "an", "object"]')
        self.assertIsNone(memo.Memo(self.file).lookup("k1"))

    def test_entry_without_an_outcome_is_not_returned(self):
        os.makedirs(os.path.dirname(self.file))
        write(self.file, json.dumps({"k1": {"commit": "c" * 40, "detail": "x"}}))
        self.assertIsNone(memo.Memo(self.file).lookup("k1"))

    def test_unwritable_state_dir_returns_false(self):
        locked = self.path("locked")
        os.mkdir(locked)
        os.chmod(locked, stat.S_IRUSR | stat.S_IXUSR)
        self.addCleanup(os.chmod, locked, stat.S_IRWXU)
        if os.access(locked, os.W_OK):
            self.skipTest("running as a user who can write anywhere")
        memory = memo.Memo(os.path.join(locked, "sub", "memo-mg.json"))
        memory.record("k1", "c" * 40, "pass", "fine")
        self.assertFalse(memory.save("c" * 40))

    def test_save_leaves_no_temp_files_behind(self):
        memory = memo.Memo(self.file)
        memory.record("k1", "c" * 40, "pass", "fine")
        memory.save("c" * 40)
        self.assertEqual(os.listdir(os.path.dirname(self.file)), ["memo-mg.json"])


class PathTest(unittest.TestCase):
    def test_memo_path_honours_xdg_state_home(self):
        self.assertEqual(memo.memo_path("mg", "/x/state"), "/x/state/site-standards/memo-mg.json")

    def test_memo_path_defaults_under_home(self):
        saved = {name: os.environ.pop(name, None) for name in ("XDG_STATE_HOME",)}
        try:
            self.assertEqual(memo.memo_path("mg"),
                             os.path.join(os.path.expanduser("~"), ".local/state/site-standards/memo-mg.json"))
        finally:
            for name, value in saved.items():
                if value is not None:
                    os.environ[name] = value

    def test_memo_path_refuses_a_shortcode_that_is_not_a_plain_name(self):
        with self.assertRaises(ValueError):
            memo.memo_path("../evil", "/x/state")


class LabelTest(unittest.TestCase):
    def test_remembered_label_shape(self):
        self.assertRegex(memo.remembered_label("2026-11-09 06:19:00"), r"^\d{4}-\d\d-\d\d \d\d:\d\d \S+$")


if __name__ == "__main__":
    unittest.main()
