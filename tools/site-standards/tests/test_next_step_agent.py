"""Tests for next_step.py when the first open step is an agent's: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout.

The line that says which step it is, the second-line question, and the step's
check run here as a scan would run it. Site names, hosts and paths are made
up."""

import os
import subprocess
import sys
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE_TESTS, ".."))
sys.path.insert(0, HERE_TESTS)
import next_step  # noqa: E402
from next_step_case import NextStepCase, GIT, write  # noqa: E402


class NextStepAgentTest(NextStepCase):
    def setUp(self):
        super().setUp()
        self.check("echo", 1, "not yet")

    def first_line(self, **site):
        self.mg_says(self.site([self.step()], **site))
        self.server_runs(self.first)
        return self.run_it()["say"].split("\n")[0]

    def test_the_step_line_with_the_served_php(self):
        self.assertEqual(self.first_line(),
                         "`demo`, track `Track`, **`A step`** (`not yet`), served on PHP `8.4`.")

    def test_the_step_line_with_a_test_domains_php(self):
        self.assertEqual(self.first_line(test_php="8.5"),
                         "`demo`, track `Track`, **`A step`** (`not yet`), served on PHP `8.4`, "
                         "test domain on PHP `8.5`.")

    def test_the_step_line_with_no_sweep(self):
        self.assertEqual(self.first_line(php_current=None, test_php="8.5"),
                         "`demo`, track `Track`, **`A step`** (`not yet`); mg has no sweep for its PHP yet.")

    def test_one_line_site_gets_no_note(self):
        self.mg_says(self.site([self.step()]))
        self.server_runs(self.first)          # an ancestor of HEAD
        got = self.run_it()
        self.assertNotEqual(got["outcome"], "ask")
        self.assertEqual(len(got["say"].split("\n")), 1)

    def test_a_test_domain_and_no_upgrade_branch_gets_the_deploy_note(self):
        test = {"domain": "demo-test.example", "php_version": "8.5", "ssh_alias": "stage", "directory": "t"}
        self.mg_says(self.site([self.step()]), self.facts(test=test))
        self.server_runs(self.second)         # HEAD itself
        self.assertIn("mg will scan `demo` once this commit is deployed to `demo-test.example`; "
                      "production can wait.", self.run_it()["say"].split("\n"))

    def test_an_upgrade_branch_on_a_one_line_site_is_mentioned_and_left(self):
        self.mg_says(self.site([self.step()]), self.facts(upgrade_branch="green"))
        self.server_runs(self.first)
        got = self.run_it()
        self.assertNotEqual(got["outcome"], "ask")
        self.assertIn("`demo` has `upgrade_branch` set to `green` on mg; clearing it "
                      "(`site-set --shortcode=demo --upgrade=`) is your call.", got["say"].split("\n"))

    def test_a_second_line_asks_before_setting_the_upgrade_branch(self):
        self.mg_says(self.site([self.step()]))
        self.server_runs("c" * 40)            # a commit this branch lacks
        got = self.run_it()
        self.assertEqual(got["outcome"], "ask")
        self.assertEqual(got["ask"],
                         "The server runs commits this branch lacks, so `topic` is a second line being "
                         "prepared beside the live one. May I run "
                         "`~/work/rob/mg.robnugen.com/tools/site-standards/site.sh site-set --shortcode=demo "
                         "--upgrade=topic`, so that mg scans `topic`'s tip for `demo`?")
        self.assertTrue(got["say"].startswith("`demo`, track `Track`"))
        self.assertEqual(sorted(got), ["ask", "outcome", "say"])

    def test_a_server_on_a_side_branch_is_also_a_second_line(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "-b", "side", self.first], check=True)
        side = self.commit(self.repo, "a fix on the server's line only")
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "topic"], check=True)
        self.mg_says(self.site([self.step()]))
        self.server_runs(side)
        self.assertEqual(self.run_it()["outcome"], "ask")

    def test_leave_upgrade_skips_the_question_and_goes_on(self):
        self.mg_says(self.site([self.step()]))
        self.server_runs("c" * 40)
        got = self.run_it("--leave-upgrade")
        self.assertNotEqual(got["outcome"], "ask")
        self.assertNotIn("mg will scan", got["say"])

    def test_a_second_line_already_named_is_said_and_not_asked(self):
        self.mg_says(self.site([self.step()]), self.facts(upgrade_branch="topic"))
        self.server_runs("c" * 40)
        got = self.run_it()
        self.assertNotEqual(got["outcome"], "ask")
        self.assertIn("mg will scan `topic`'s tip for `demo`.", got["say"].split("\n"))

    def test_a_detached_head_is_never_asked_about(self):
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "--detach"], check=True)
        self.mg_says(self.site([self.step()]))
        self.server_runs("c" * 40)
        self.assertNotEqual(self.run_it()["outcome"], "ask")

    def test_a_server_that_does_not_answer_is_quoted_and_the_run_goes_on(self):
        self.mg_says(self.site([self.step()]))      # no file for host "prod"
        got = self.run_it()
        self.assertNotEqual(got["outcome"], "ask")
        self.assertIn("Its server did not say what it runs "
                      "(`could not ask its server which commit it runs: no route to host`).",
                      got["say"].split("\n"))

    def test_a_site_mg_could_choose_no_server_for_goes_on_too(self):
        self.mg_says(self.site([self.step()]),
                     self.facts(server=None, server_problem="its domains have different checkouts"))
        self.assertIn("Its server did not say what it runs (`its domains have different checkouts`).",
                      self.run_it()["say"].split("\n"))


if __name__ == "__main__":
    unittest.main()
