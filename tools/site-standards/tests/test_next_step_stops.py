"""Tests for next_step.py, up to the stops that need no step: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout.

The repo's path, the question to mg and every full stop before a step is
named. Each sentence is pinned word for word. Site names, hosts and paths are
made up."""

import contextlib
import io
import json
import os
import subprocess
import sys
import unittest
import unittest.mock

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE_TESTS, ".."))
sys.path.insert(0, HERE_TESTS)
import next_step  # noqa: E402
from next_step_case import NextStepCase, GIT, write  # noqa: E402


class NextStepStopsTest(NextStepCase):
    def test_scan_still_has_what_next_step_borrows(self):
        import scan
        for name in ("git", "check_environment", "run_check", "php_targets", "frontmatter",
                     "step_name", "CHECK_NAME", "server_facts"):
            self.assertTrue(hasattr(scan, name), name)

    def test_a_directory_outside_work_stops(self):
        outside = os.path.join(self.root, "elsewhere")
        os.makedirs(outside)
        subprocess.run([*GIT, "init", "-q", outside], check=True)
        self.assertEqual(self.run_it(cwd=outside), {
            "outcome": "say",
            "say": "This repo isn't under ~/work, so it can't be on mg's standards page."})

    def test_a_directory_that_is_no_repo_stops_the_same_way(self):
        plain = os.path.join(self.work, "plain")
        os.makedirs(plain)
        self.assertEqual(self.run_it(cwd=plain)["say"],
                         "This repo isn't under ~/work, so it can't be on mg's standards page.")

    def test_mg_is_asked_by_checkout_path_from_a_subdirectory(self):
        self.mg_says(None)
        sub = os.path.join(self.repo, "deep", "er")
        os.makedirs(sub)
        self.run_it(cwd=sub)
        self.assertEqual(self.site_asked(), ["next-step --checkout=rob/demo"])

    def test_a_shortcode_is_passed_instead_of_the_path(self):
        self.mg_says(None)
        self.run_it("--shortcode=demo-two")
        self.assertEqual(self.site_asked(), ["next-step --shortcode=demo-two"])

    def test_ssh_failing_stops_with_its_last_line(self):
        self.mg_fails(255, "Warning: something\nssh: connect to host mg port 22: Connection refused\n")
        self.assertEqual(self.run_it(), {
            "outcome": "say",
            "say": "I can't reach mg from here (`ssh: connect to host mg port 22: Connection refused`), "
                   "so /next-mg-step can't look up the step."})

    def test_mg_refusing_stops_with_its_sentence(self):
        self.mg_fails(1, "Unknown command: next-step. Use one of: sites, site-add.\n")
        self.assertEqual(self.run_it()["say"],
                         "mg refused the lookup: `Unknown command: next-step. Use one of: sites, site-add.`")

    def test_an_answer_that_is_not_the_object_is_a_refusal(self):
        for text in ("PHP Notice: something\n", "[]", '{"site": null}'):
            with self.subTest(text=text):
                write(os.path.join(self.root, "mg-answer"), text)
                self.assertEqual(self.run_it()["say"],
                                 "mg refused the lookup: `its answer was not the one this script reads`")

    def test_a_site_of_the_wrong_shape_is_a_refusal_too(self):
        for site in ("demo", {"shortcode": "demo"}, self.site("not a list"), self.site(["not a step"])):
            with self.subTest(site=site):
                self.mg_says(site, self.facts())
                self.assertEqual(self.run_it()["say"],
                                 "mg refused the lookup: `its answer was not the one this script reads`")

    def test_several_sites_on_one_checkout_is_a_question(self):
        self.mg_says(None, shortcodes=["demo", "demo-two"])
        self.assertEqual(self.run_it(), {
            "outcome": "ask", "say": "",
            "ask": "More than one site uses this checkout: `demo`, `demo-two`. Which shortcode is meant?"})

    def test_no_site_stops(self):
        self.mg_says(None)
        self.assertEqual(self.run_it(), {
            "outcome": "say",
            "say": "This repo isn't on mg's standards page, so /next-mg-step has nothing for it."})

    def test_nothing_open_stops(self):
        self.mg_says(self.site([]))
        self.assertEqual(self.run_it(), {"outcome": "say", "say": "`demo` is up to date on mg's standards page."})

    def test_nobody_can_reach_it_lists_robs_steps_only(self):
        steps = [self.step(slug="one", title="Enter the domain", whose_move="rob", how_to="Type it in."),
                 self.step(slug="two", title="An agent step"),
                 self.step(slug="three", title="Prove the login", whose_move="rob", how_to="Run the sweep.")]
        self.mg_says(self.site(steps, standing={"mark": "face", "text": "unreachable"}))
        self.assertEqual(self.run_it(), {
            "outcome": "say",
            "say": "Nobody can reach `demo` yet, so these are yours first:\n\n"
                   "**`Enter the domain`**\n\nType it in.\n\n"
                   "**`Prove the login`**\n\nRun the sweep."})

    def test_robs_step_gives_title_detail_and_how_to(self):
        self.mg_says(self.site([self.step(whose_move="rob", title="Switch the panel", detail="still on 8.3",
                                          how_to="Open the panel.\nPick 8.5.")]))
        got = self.run_it()
        self.assertEqual(got["outcome"], "say")
        self.assertEqual(got["say"], "`demo`'s next step is yours: **`Switch the panel`** (`still on 8.3`).\n\n"
                                     "Open the panel.\nPick 8.5.")
        self.assertNotIn("after", got)

    def test_a_skipped_scan_is_said_first(self):
        self.mg_says(self.site([self.step(whose_move="rob")],
                               skipped_reason="the latest scan skipped this site; the standards page says why"))
        self.assertTrue(self.run_it()["say"].startswith(
            "These steps come from the scan of `2026-11-20 01:02:03` UTC: "
            "the latest scan skipped this site; the standards page says why\n\n`demo`'s next step is yours"))

    def test_unknown_options_are_a_usage_error_not_an_answer(self):
        for argv in (["--nope"], ["--shortcode=Not A Code"], ["--confirm"], ["--base=abc1234"],
                     ["--confirm", "--base=zzz"], ["--session-model=gpt"], ["stray"],
                     ["--shortcode=" + "a" * 25]):
            with self.subTest(argv=argv):
                with self.assertRaises(next_step.Usage):
                    next_step.answer(argv, cwd=self.repo)

    def test_main_prints_the_answer_as_one_json_object(self):
        self.mg_says(None)
        out = io.StringIO()
        with unittest.mock.patch("os.getcwd", return_value=self.repo), contextlib.redirect_stdout(out):
            code = next_step.main([])
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(out.getvalue()), {
            "outcome": "say",
            "say": "This repo isn't on mg's standards page, so /next-mg-step has nothing for it."})

    def test_main_answers_an_unknown_option_with_usage_on_stderr(self):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = next_step.main(["--nope"])
        self.assertEqual(code, 2)
        self.assertEqual(out.getvalue(), "")
        self.assertTrue(err.getvalue().startswith(
            "Usage: next-step.sh [--shortcode=<code>] [--leave-upgrade] [--session-model=<model>] "
            "[--confirm --base=<commit>]"))


if __name__ == "__main__":
    unittest.main()
