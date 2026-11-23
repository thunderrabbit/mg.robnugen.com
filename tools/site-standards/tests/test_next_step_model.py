"""Tests for next_step.py choosing who does an agent's step: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout.

The step's model, read from the step files at the commit mg last scanned; the
brief and the rules built from the template; and the outcomes `dispatch` and
`do`. Site names, hosts and paths are made up."""

import os
import re
import subprocess
import sys
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE_TESTS, ".."))
sys.path.insert(0, HERE_TESTS)
import next_step  # noqa: E402
from next_step_case import NextStepCase, STEP, TRACK, write  # noqa: E402


class NextStepModelTest(NextStepCase):
    def setUp(self):
        super().setUp()
        self.check("echo", 1, "not yet")
        self.server_runs(self.first)

    def restep(self, step=None, track=None):
        """Rewrite the scratch mg's step files, commit, and return the commit."""
        if track is not None:
            write(os.path.join(self.steps, "_track.md"), track)
        if step is not None:
            write(os.path.join(self.steps, "010_a_step.md"), step)
        return self.commit_all(self.mg, "restep")

    def test_the_tracks_model_is_the_steps_default(self):
        self.mg_says(self.site([self.step()]))
        got = self.run_it()
        self.assertEqual((got["outcome"], got["model"], got["base"]), ("dispatch", "sonnet", self.second))
        self.assertEqual(sorted(got), ["base", "brief", "model", "outcome", "say"])

    def test_the_steps_own_model_wins(self):
        commit = self.restep(step=STEP.replace("check: echo\n", "check: echo\nmodel: opus\n"))
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        got = self.run_it()
        self.assertEqual((got["outcome"], got["model"], got["base"]), ("do", "opus", self.second))
        self.assertEqual(sorted(got), ["base", "model", "outcome", "rules", "say"])

    def test_each_model_goes_where_the_spec_sends_it(self):
        for model, outcome in (("haiku", "dispatch"), ("sonnet", "dispatch"), ("opus", "do"), ("fable", "do")):
            with self.subTest(model=model):
                commit = self.restep(track=TRACK.replace("sonnet", model))
                self.mg_says(self.site([self.step()]), steps_commit=commit)
                self.assertEqual(self.run_it()["outcome"], outcome)

    def test_the_file_is_found_by_the_name_after_the_number(self):
        os.rename(os.path.join(self.steps, "010_a_step.md"), os.path.join(self.steps, "038.9996_a_step.md"))
        commit = self.commit_all(self.mg, "renumber")
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        self.assertEqual(self.run_it()["model"], "sonnet")

    def test_the_model_is_read_at_the_scanned_commit_not_the_working_tree(self):
        self.restep(track=TRACK.replace("sonnet", "opus"))          # newer than the scan
        self.mg_says(self.site([self.step()]))                       # steps_commit: the first one
        self.assertEqual(self.run_it()["model"], "sonnet")

    def test_no_model_anywhere_stops_and_never_guesses(self):
        no_model = "mg's last scan has no model for **`A step`**; a scan will bring it."
        cases = {
            "no line": self.restep(track=TRACK.replace("model: sonnet\n", "")),
            "not a model": self.restep(track=TRACK.replace("sonnet", "gpt")),
            "no scan": None,
            "not a commit name": "HEAD",
            "a commit this checkout lacks": "d" * 40,
        }
        for why, commit in cases.items():
            with self.subTest(why=why):
                self.mg_says(self.site([self.step()]), steps_commit=commit)
                got = self.run_it()
                self.assertEqual(got["outcome"], "say")
                self.assertEqual(got["say"].split("\n")[-1], no_model)
                self.assertNotIn("model", got)

    def test_a_standard_that_is_not_a_name_gives_no_model(self):
        self.mg_says(self.site([self.step()], standard="../.."))
        self.assertEqual(self.run_it()["outcome"], "say")

    def test_a_step_already_done_never_needs_a_model(self):
        self.check("echo", 0, "all there")
        self.mg_says(self.site([self.step()]), steps_commit=None)
        self.assertEqual(self.run_it()["say"].split("\n")[-1], "Already done here; it's waiting on a deploy.")

    def test_the_brief_carries_the_step_the_check_and_all_three_parts(self):
        self.mg_says(self.site([self.step(explanation="Because.", how_to="1. Edit it.")]))
        brief = self.run_it()["brief"]
        top = os.path.realpath(self.repo)
        for piece in (f"for the site `demo`, in the checkout `{top}`", "- Track: Track",
                      "- Step: **A step** (its name on mg is `a_step`)", "- Where it stands: not yet",
                      "- PHP: served on PHP `8.4`", "Because.", "1. Edit it.",
                      "an issue titled\n   `a_step.demo`", f"run-rector.sh {top}", f"run-unit.sh {top}",
                      "## Rules for this step", "## Because you are a subagent", "**needs Rob**",
                      "Just now it said: `not yet`"):
            self.assertIn(piece, brief)
        self.assertNotIn("{{", brief)
        self.assertNotIn("<!--", brief)

    def test_the_check_command_in_the_brief_is_the_one_the_script_ran(self):
        self.mg_says(self.site([self.step(check_param="8.5")]))
        brief = self.run_it()["brief"]
        script = os.path.join(self.here, "checks", "echo.sh")
        line = next(l for l in brief.split("\n") if script in l)
        self.assertTrue(line.strip().startswith("env SITE_"))
        self.assertTrue(line.strip().endswith(f"{script} {os.path.realpath(self.repo)} 8.5"))
        for name in ("SITE_SHORTCODE=demo", "SITE_PHP_CURRENT=8.4", f"SITE_SERVER_COMMIT={self.first}",
                     "SITE_TEST_DOMAIN=''", "SITE_UPGRADE_BRANCH=''"):
            self.assertIn(name, line)
        done = subprocess.run(["bash", "-c", line], capture_output=True, text=True)
        self.assertEqual((done.returncode, done.stdout.strip()), (1, "not yet"))

    def test_a_check_with_no_script_and_a_safe_suite_say_so_in_the_brief(self):
        self.mg_says(self.site([self.step(check="dh_serves_php")]))
        self.assertIn("mg answers this step's check from its own tables; there is nothing to run here.",
                      self.run_it()["brief"])
        self.check("suite_passes_on_php", 0, "passed")
        self.mg_says(self.site([self.step(check="suite_passes_on_php")]))
        self.assertIn("Only the scan runs this step's check (a site's safe suite). Do not run it.",
                      self.run_it()["brief"])

    def test_a_check_that_could_not_tell_is_quoted_in_the_brief_and_the_rules(self):
        self.check("echo", 2, "vendor/ is not installed")
        self.mg_says(self.site([self.step()]))
        self.assertIn("Just now it could not tell: `vendor/ is not installed`", self.run_it()["brief"])
        commit = self.restep(track=TRACK.replace("sonnet", "opus"))
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        self.assertIn("Just now it could not tell: `vendor/ is not installed`", self.run_it()["rules"])

    def test_rules_are_the_rules_part_only_plus_the_check(self):
        commit = self.restep(track=TRACK.replace("sonnet", "opus"))
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        rules = self.run_it()["rules"]
        self.assertTrue(rules.startswith("## Rules for this step"))
        self.assertIn("`a_step.demo`", rules)
        self.assertNotIn("subagent", rules)
        self.assertNotIn("needs Rob", rules)
        self.assertNotIn("{{", rules)

    def test_text_from_mg_is_never_read_as_a_placeholder(self):
        self.mg_says(self.site([self.step(how_to="Write {{title}} and $HOME and \\1 as they are.",
                                          title="{{how_to}}")]))
        brief = self.run_it()["brief"]
        self.assertIn("Write {{title}} and $HOME and \\1 as they are.", brief)
        self.assertIn("- Step: **{{how_to}}**", brief)

    def test_the_template_uses_exactly_the_known_placeholders(self):
        with open(next_step.BRIEF, encoding="utf-8") as handle:
            text = handle.read()
        self.assertEqual(set(re.findall(r"\{\{([a-z_]+)\}\}", text)), set(next_step.PLACEHOLDERS))
        self.assertEqual(text.count("<!-- rules -->\n"), 1)
        self.assertEqual(text.count("<!-- subagent -->\n"), 1)
        self.assertLess(text.index("<!-- rules -->"), text.index("<!-- subagent -->"))

    def test_a_cheaper_session_is_told_which_model_the_step_asks_for(self):
        commit = self.restep(track=TRACK.replace("sonnet", "opus"))
        self.mg_says(self.site([self.step()]), steps_commit=commit)
        line = "This step asks for `opus`; this session runs on `sonnet`."
        self.assertEqual(self.run_it("--session-model=sonnet")["say"].split("\n")[-1], line)
        self.assertEqual(self.run_it("--session-model=sonnet")["outcome"], "do")
        for flag in ((), ("--session-model=opus",), ("--session-model=fable",)):
            self.assertNotIn("asks for", self.run_it(*flag)["say"])

    def test_a_dispatch_never_gets_that_line(self):
        self.mg_says(self.site([self.step()]))
        self.assertNotIn("asks for", self.run_it("--session-model=haiku")["say"])


if __name__ == "__main__":
    unittest.main()
