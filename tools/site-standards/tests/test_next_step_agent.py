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

    def test_the_never_run_list_is_exactly_the_safe_suite(self):
        self.assertEqual(next_step.NEVER_RUN_IN_A_LOOKUP,
                         {"suite_passes_on_php": "only the scan runs a site's safe suite"})

    def test_a_passing_check_is_already_done_with_the_scan_reminder(self):
        self.server_runs(self.first)
        self.check("echo", 0, "all there")
        self.mg_says(self.site([self.step()]))
        got = self.run_it()
        self.assertEqual(got["outcome"], "say")
        self.assertEqual(got["say"].split("\n")[-1], "Already done here; it's waiting on a deploy.")
        self.assertEqual(got["after"], "Once you've approved and deployed this, run on Lemur 13:\n"
                                       "`~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh demo`")

    def test_the_reminder_names_the_test_domain_when_that_is_enough(self):
        self.server_runs(self.first)
        self.check("echo", 0, "all there")
        test = {"domain": "demo-test.example", "php_version": "8.5", "ssh_alias": "stage", "directory": "t"}
        self.mg_says(self.site([self.step()]), self.facts(test=test))
        self.assertEqual(self.run_it()["after"],
                         "Once you've approved and deployed this to `demo-test.example`, run on Lemur 13:\n"
                         "`~/work/rob/mg.robnugen.com/tools/site-standards/scan.sh demo`")

    def test_a_test_domain_beside_an_upgrade_branch_keeps_the_plain_reminder(self):
        self.server_runs(self.first)
        self.check("echo", 0, "all there")
        test = {"domain": "demo-test.example", "php_version": "8.5", "ssh_alias": "stage", "directory": "t"}
        self.mg_says(self.site([self.step()]), self.facts(test=test, upgrade_branch="green"))
        self.assertTrue(self.run_it()["after"].startswith("Once you've approved and deployed this, run"))

    def test_a_failing_check_goes_on_without_a_word(self):
        self.server_runs(self.first)
        self.mg_says(self.site([self.step()]))      # echo exits 1 from setUp
        got = self.run_it()
        self.assertIn(got["outcome"], ("dispatch", "do"))
        self.assertNotIn("after", got)
        self.assertNotIn("could not tell", got["say"])

    def test_a_check_that_could_not_tell_is_quoted_and_the_run_goes_on(self):
        self.server_runs(self.first)
        self.check("echo", 2, "vendor/ is not installed")
        self.mg_says(self.site([self.step()]))
        got = self.run_it()
        self.assertIn(got["outcome"], ("dispatch", "do"))
        self.assertIn("The check could not tell: `vendor/ is not installed`", got["say"].split("\n"))

    def test_the_check_gets_the_scanners_environment_and_the_param(self):
        self.server_runs(self.first)
        self.check("echo", 2, "$2|$SITE_SHORTCODE|$SITE_PHP_CURRENT|$SITE_TEST_DOMAIN|$SITE_TEST_PHP|"
                              "$SITE_TEST_SSH_ALIAS|$SITE_TEST_DIRECTORY|$SITE_SERVER_SSH_ALIAS|"
                              "$SITE_SERVER_DIRECTORY|$SITE_SERVER_COMMIT|$SITE_UPGRADE_BRANCH|$1")
        test = {"domain": "demo-test.example", "php_version": "8.5", "ssh_alias": "stage", "directory": "t"}
        self.mg_says(self.site([self.step(check_param="8.5")]), self.facts(test=test, upgrade_branch="green"))
        said = self.run_it()["say"]
        self.assertIn(f"`8.5|demo|8.4|demo-test.example|8.5|stage|t|prod|demo.example|{self.first}|green|"
                      f"{os.path.realpath(self.repo)}`", said)

    def test_a_null_param_reaches_the_check_as_nothing(self):
        self.server_runs(self.first)
        self.check("echo", 2, "param=[$2]")
        self.mg_says(self.site([self.step(check_param=None)]))
        self.assertIn("`param=[]`", self.run_it()["say"])

    def test_the_sites_php_target_comes_from_the_registry(self):
        self.server_runs(self.first)
        write(os.path.join(self.mcp, "repos.yaml"),
              f"demo:\n  path: {os.path.realpath(self.repo)}\n  php_target: \"8.5\"\n")
        self.check("echo", 2, "target=$SITE_PHP_TARGET")
        self.mg_says(self.site([self.step()]))
        self.assertIn("`target=8.5`", self.run_it()["say"])

    def test_a_safe_suite_is_never_run_in_a_lookup(self):
        self.server_runs(self.first)
        marker = os.path.join(self.root, "suite-ran")
        self.check("suite_passes_on_php", 0, "passed", body=f"touch '{marker}'")
        self.mg_says(self.site([self.step(check="suite_passes_on_php", check_param="8.5")]))
        got = self.run_it()
        self.assertFalse(os.path.exists(marker))
        self.assertIn(got["outcome"], ("dispatch", "do"))
        self.assertIn("The check could not tell: `only the scan runs a site's safe suite`",
                      got["say"].split("\n"))

    def test_a_check_with_no_script_here_is_skipped(self):
        self.server_runs(self.first)
        self.mg_says(self.site([self.step(check="dh_serves_php")]))
        got = self.run_it()
        self.assertIn(got["outcome"], ("dispatch", "do"))
        self.assertEqual(len(got["say"].split("\n")), 1)

    def test_a_check_name_that_is_not_a_name_is_never_run(self):
        self.server_runs(self.first)
        self.mg_says(self.site([self.step(check="../lib")]))
        self.assertIn(self.run_it()["outcome"], ("dispatch", "do"))


if __name__ == "__main__":
    unittest.main()
