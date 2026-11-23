"""Tests for next_step.py --confirm: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout.

After the work, the script reports what it finds itself in a scratch repo:
the branch, the tree, the commits since the base, the step's check, the gates
and what the diff added. Each fact's line is pinned, and each thing that makes
`clean` false. Site names, hosts and paths are made up."""

import os
import subprocess
import sys
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE_TESTS, ".."))
sys.path.insert(0, HERE_TESTS)
import next_step  # noqa: E402
from next_step_case import NextStepCase, GIT, write  # noqa: E402


class NextStepConfirmTest(NextStepCase):
    def setUp(self):
        super().setUp()
        self.check("echo", 0, "all there")
        for name in next_step.GATES:
            self.gate(name)
        self.server_runs(self.first)
        self.mg_says(self.site([self.step()]))
        self.base = self.second

    def add(self, path, text, message):
        write(os.path.join(self.repo, path), text)
        return self.commit_all(self.repo, message)

    def confirm(self):
        return self.run_it("--confirm", "--base=" + self.base)

    def lines(self):
        return self.confirm()["say"].split("\n")

    def test_work_done_cleanly_is_clean(self):
        one = self.add("classes/A.php", "<?php\n", "Add A")
        two = self.add("classes/B.php", "<?php\n", "Add B")
        got = self.confirm()
        self.assertEqual(got["say"].split("\n"), [
            "On branch `topic`.",
            "The working tree is clean.",
            f"2 commits since `{self.base[:7]}`:",
            f"- `{one[:7]}` Add A",
            f"- `{two[:7]}` Add B",
            "Check `echo`: pass (`all there`).",
            "`run-unit.sh`: exit 0.",
            "`run-phpcs.sh`: exit 0.",
            "`run-phpstan.sh`: exit 0.",
            "In the diff: 0 added `@phpstan-ignore` lines, 0 baseline files added or grown, "
            "0 test files deleted, 0 tests newly marked skipped or incomplete.",
        ])
        self.assertIs(got["clean"], True)
        self.assertEqual(got["after"], "Once you've approved and deployed this, run on Lemur 13:\n"
                                       "`~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh demo`")
        self.assertEqual(sorted(got), ["after", "clean", "outcome", "say"])

    def test_the_gates_are_given_the_checkout(self):
        self.confirm()
        with open(os.path.join(self.root, "gates.log"), encoding="utf-8") as handle:
            self.assertEqual(handle.read().split(), [os.path.realpath(self.repo)] * 3)

    def test_a_lookup_never_runs_a_gate(self):
        self.check("echo", 1, "not yet")
        self.run_it()
        self.assertFalse(os.path.exists(os.path.join(self.root, "gates.log")))

    def test_the_trunk_is_not_clean(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "master"], check=True)
        self.base = self.first
        self.assertIn("On `master`, which is the trunk.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_the_branch_origin_calls_its_head_is_the_trunk_too(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "-b", "trunk"], check=True)
        subprocess.run([*GIT, "-C", self.repo, "update-ref", "refs/remotes/origin/trunk", self.first], check=True)
        subprocess.run([*GIT, "-C", self.repo, "symbolic-ref", "refs/remotes/origin/HEAD",
                        "refs/remotes/origin/trunk"], check=True)
        self.assertIn("On `trunk`, which is the trunk.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_detached_head_is_not_clean(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "--detach"], check=True)
        self.assertIn("Not on a branch.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_uncommitted_changes_are_not_clean(self):
        write(os.path.join(self.repo, "stray.txt"), "x\n")
        self.assertIn("The working tree has uncommitted changes.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_no_commits_is_said(self):
        self.assertIn(f"No commits since `{self.base[:7]}`.", self.lines())

    def test_one_commit_is_said_in_the_singular(self):
        one = self.add("classes/A.php", "<?php\n", "Add A")
        lines = self.lines()
        self.assertIn(f"1 commit since `{self.base[:7]}`:", lines)
        self.assertIn(f"- `{one[:7]}` Add A", lines)

    def test_a_base_outside_head_is_not_clean(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "-b", "other", self.first], check=True)
        self.assertIn(f"HEAD does not contain `{self.base[:7]}`.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_base_that_is_no_commit_is_a_usage_error(self):
        self.base = "e" * 40
        with self.assertRaises(next_step.Usage):
            self.confirm()

    def test_four_commits_opened_by_a_begin_commit(self):
        self.add("version.php", "<?php\n", "BEGIN the step")
        for name in ("A", "B", "C"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertIn("The first is a `BEGIN ` commit touching one file.", self.lines())
        self.assertIs(self.confirm()["clean"], True)

    def test_four_commits_not_opened_by_a_begin_commit(self):
        for name in ("A", "B", "C", "D"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertIn("The first is not a `BEGIN ` commit touching one file.", self.lines())

    def test_four_commits_with_no_begin_first_is_not_clean(self):
        for name in ("A", "B", "C", "D"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertIs(self.confirm()["clean"], False)

    def test_three_commits_with_no_begin_first_are_clean(self):
        for name in ("A", "B", "C"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertIs(self.confirm()["clean"], True)

    def test_a_begin_commit_touching_two_files_is_not_one(self):
        write(os.path.join(self.repo, "other.php"), "<?php\n")
        self.add("version.php", "<?php\n", "BEGIN the step")
        for name in ("A", "B", "C"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertIn("The first is not a `BEGIN ` commit touching one file.", self.lines())

    def test_three_commits_say_nothing_about_begin(self):
        for name in ("A", "B", "C"):
            self.add(f"classes/{name}.php", "<?php\n", "Add " + name)
        self.assertFalse([l for l in self.lines() if "BEGIN" in l])

    def test_a_failing_check_is_not_clean(self):
        self.check("echo", 1, "still missing")
        self.assertIn("Check `echo`: fail (`still missing`).", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_check_that_could_not_tell_is_not_clean(self):
        self.check("echo", 2, "vendor/ is not installed")
        self.assertIn("Check `echo`: could not tell (`vendor/ is not installed`).", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_mgs_own_check_has_no_script_and_does_not_count_against_clean(self):
        self.mg_says(self.site([self.step(check="dh_serves_php")]))
        self.assertIn("Check `dh_serves_php` is mg's own; it has no script here.", self.lines())
        self.assertIs(self.confirm()["clean"], True)

    def test_a_safe_suite_is_not_run_by_confirm_either(self):
        marker = os.path.join(self.root, "suite-ran")
        self.check("suite_passes_on_php", 0, "passed", body=f"touch '{marker}'")
        self.mg_says(self.site([self.step(check="suite_passes_on_php")]))
        got = self.confirm()
        self.assertFalse(os.path.exists(marker))
        self.assertIn("Check `suite_passes_on_php`: could not tell "
                      "(`only the scan runs a site's safe suite`).", got["say"].split("\n"))
        self.assertIs(got["clean"], False)

    def test_a_failing_gate_is_not_clean(self):
        self.gate("run-phpstan.sh", exit_code=1)
        self.assertIn("`run-phpstan.sh`: exit 1.", self.lines())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_gate_the_repo_is_not_set_up_for_is_said_and_does_not_count(self):
        self.gate("run-phpcs.sh", exit_code=0, out="GATE SKIP: no phpcs config in /somewhere")
        os.remove(os.path.join(self.mcp, "run-unit.sh"))
        lines = self.lines()
        self.assertIn("`run-phpcs.sh`: not set up here.", lines)
        self.assertIn("`run-unit.sh`: not found in standards-mcp.", lines)
        self.assertIs(self.confirm()["clean"], True)

    def diff_line(self):
        return [l for l in self.lines() if l.startswith("In the diff:")][0]

    def test_an_added_phpstan_ignore_is_counted_in_php_only(self):
        self.add("classes/A.php", "<?php\n$x = f(); // @phpstan-ignore argument.type (reason)\n", "Add A")
        self.add("notes.md", "how to write @phpstan-ignore\n", "Notes")
        self.assertIn("1 added `@phpstan-ignore` lines", self.diff_line())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_php_file_that_is_not_utf8_is_still_read(self):
        os.makedirs(os.path.join(self.repo, "classes"))
        with open(os.path.join(self.repo, "classes", "Old.php"), "wb") as handle:
            handle.write(b"<?php\n// caf\xe9\n$x = f(); // @phpstan-ignore argument.type\n")
        self.commit_all(self.repo, "Add an old file")
        self.assertIn("1 added `@phpstan-ignore` lines", self.diff_line())

    def test_a_removed_phpstan_ignore_is_not_counted(self):
        self.base = self.add("classes/A.php", "<?php\n$x = f(); // @phpstan-ignore argument.type\n", "Add A")
        self.add("classes/A.php", "<?php\n$x = f();\n", "Fix A")
        self.assertIn("0 added `@phpstan-ignore` lines", self.diff_line())

    def test_a_new_or_grown_baseline_is_counted(self):
        self.add("phpstan-baseline.neon", "parameters:\n", "Add a baseline")
        self.assertIn("1 baseline files added or grown", self.diff_line())
        self.base = self.commit(self.repo, "mark")
        self.add("phpstan-baseline.neon", "parameters:\n  ignoreErrors:\n    - one\n", "Grow it")
        self.assertIn("1 baseline files added or grown", self.diff_line())
        self.base = self.commit(self.repo, "mark again")
        self.add("phpstan-baseline.neon", "parameters:\n", "Shrink it")
        self.assertIn("0 baseline files added or grown", self.diff_line())

    def test_a_deleted_test_file_is_counted_and_a_moved_one_is_not(self):
        body = "<?php\nfinal class ATest { public function testA(): void { $this->assertTrue(true); } }\n"
        self.base = self.add("Tests/Unit/ATest.php", body, "Add a test")
        subprocess.run([*GIT, "-C", self.repo, "mv", "Tests/Unit/ATest.php", "Tests/Unit/BTest.php"], check=True)
        self.commit_all(self.repo, "Move it")
        self.assertIn("0 test files deleted", self.diff_line())
        os.remove(os.path.join(self.repo, "Tests/Unit/BTest.php"))
        self.commit_all(self.repo, "Delete it")
        self.assertIn("1 test files deleted", self.diff_line())
        self.assertIs(self.confirm()["clean"], False)

    def test_tests_newly_skipped_or_incomplete_are_counted(self):
        self.add("Tests/Unit/ATest.php",
                 "<?php\n$this->markTestSkipped('later');\n$this->markTestIncomplete();\n"
                 "#[Skip]\n$scenario->skip();\n * @skip\n", "Add a skipped test")
        self.assertIn("5 tests newly marked skipped or incomplete", self.diff_line())
        self.assertIs(self.confirm()["clean"], False)

    def test_a_stop_in_confirm_is_not_clean(self):
        self.mg_fails(255, "ssh: connect to host mg port 22: Connection refused\n")
        got = self.confirm()
        self.assertTrue(got["say"].startswith("I can't reach mg from here"))
        self.assertIs(got["clean"], False)
        self.assertNotIn("after", got)


if __name__ == "__main__":
    unittest.main()
