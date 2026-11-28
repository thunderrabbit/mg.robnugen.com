"""Tests for checks/phpstan_level.sh and the PHPStan line of
checks/checks_in_place.sh, against made-up repos: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout. PHPStan itself is a stand-in that only says how each
config's run ended."""

import os
import subprocess
import tempfile
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
CHECKS = os.path.join(HERE_TESTS, "..", "checks")

LEVEL = "parameters:\n    level: {}\n"
EMPTY = "parameters:\n\tignoreErrors: []\n"
ENTRY = "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^A$#'\n\t\t\tcount: 2\n"


class PhpstanChecksTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.repo = os.path.join(tmp.name, "repo")
        self.tools = os.path.join(tmp.name, "tools")
        self.scan_tmp = os.path.join(tmp.name, "scan")
        for made in (self.repo, self.tools, self.scan_tmp):
            os.mkdir(made)

    def write(self, name, text):
        path = os.path.join(self.repo, name)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(text)

    def runs_end(self, *ends):
        """Install PHPStan, with a runner whose runs end with these exits."""
        self.write("vendor/bin/phpstan", "")
        runner = os.path.join(self.tools, "run-phpstan.sh")
        lines = "".join(f"echo '##PHPSTAN_END made-up-{i}.neon rc={rc}'\n" for i, rc in enumerate(ends))
        with open(runner, "w", encoding="utf-8") as handle:
            handle.write("#!/usr/bin/env bash\n" + lines)
        os.chmod(runner, 0o755)

    def check(self, name, *params):
        env = dict(os.environ, STANDARDS_MCP=self.tools, SCAN_TMP=self.scan_tmp)
        done = subprocess.run([os.path.join(CHECKS, name + ".sh"), self.repo, *params],
                              capture_output=True, text=True, env=env, check=False)
        return done.returncode, done.stdout.strip()

    def test_no_config_at_all(self):
        self.assertEqual(self.check("phpstan_level", "0"), (1, "no phpstan.neon"))

    def test_one_config_below_the_level_asked(self):
        self.write("phpstan.neon", LEVEL.format(3))
        self.assertEqual(self.check("phpstan_level", "4"), (1, "PHPStan is at level 3"))

    def test_one_config_passes_its_level_and_those_below(self):
        self.write("phpstan.neon", LEVEL.format("max"))
        self.runs_end(0)
        self.assertEqual(self.check("phpstan_level", "10"), (0, "level 10, no baseline, run clean"))
        self.assertEqual(self.check("phpstan_level", "4"),
                         (0, "level 4 (phpstan.neon is at level max), no baseline, run clean"))

    def test_tiers_are_at_the_lowest_of_them(self):
        self.write("phpstan-level-2.neon", LEVEL.format(2))
        self.write("phpstan-level-5.neon", LEVEL.format(5))
        self.runs_end(0, 0)
        self.assertEqual(self.check("phpstan_level", "3"),
                         (1, "PHPStan is at level 2, its lowest tier (phpstan-level-2.neon)"))
        self.assertEqual(self.check("phpstan_level", "2"), (0, "level 2, no baseline, run clean"))
        self.assertEqual(
            self.check("phpstan_level", "0"),
            (0, "level 0 (its lowest tier, phpstan-level-2.neon, is at level 2), no baseline, run clean"))

    def test_an_empty_baseline_file_is_not_a_baseline(self):
        self.write("phpstan-level-0.neon", "includes:\n    - phpstan-level-0-baseline.neon\n\n" + LEVEL.format(0))
        self.write("phpstan-level-0-baseline.neon", EMPTY)
        self.runs_end(0)
        self.assertEqual(self.check("phpstan_level", "0"), (0, "level 0, no baseline, run clean"))

    def test_a_baseline_in_any_tier_fails_every_level(self):
        self.write("phpstan-level-0.neon", LEVEL.format(0))
        self.write("phpstan-level-1.neon", "includes:\n    - phpstan-level-1-baseline.neon\n\n" + LEVEL.format(1))
        self.write("phpstan-level-0-baseline.neon", EMPTY)
        self.write("phpstan-level-1-baseline.neon", ENTRY)
        self.runs_end(0, 0)
        self.assertEqual(self.check("phpstan_level", "0"),
                         (1, "PHPStan has a baseline: phpstan-level-1-baseline.neon hides 2 findings"))

    def test_a_baseline_beside_one_config_still_fails_it(self):
        self.write("phpstan.neon", LEVEL.format(6))
        self.write("phpstan-baseline.neon", ENTRY.replace("\t\t\tcount: 2\n", ""))
        self.assertEqual(self.check("phpstan_level", "6"),
                         (1, "PHPStan has a baseline: phpstan-baseline.neon hides 1 finding"))

    def test_findings_in_any_tier_fail(self):
        self.write("phpstan-level-0.neon", LEVEL.format(0))
        self.write("phpstan-level-1.neon", LEVEL.format(1))
        self.runs_end(0, 1)
        self.assertEqual(self.check("phpstan_level", "0"), (1, "PHPStan reports findings"))

    def test_a_tier_with_no_level(self):
        self.write("phpstan-level-0.neon", LEVEL.format(0))
        self.write("phpstan-level-1.neon", "parameters:\n    paths: [classes]\n")
        self.assertEqual(self.check("phpstan_level", "0"), (1, "phpstan-level-1.neon sets no level"))

    def test_checks_in_place_counts_tiers_as_phpstan(self):
        self.write("phpcs.xml", "")
        self.assertEqual(self.check("checks_in_place"), (1, "no phpstan.neon"))
        self.write("phpstan-level-0-baseline.neon", EMPTY)
        self.assertEqual(self.check("checks_in_place"), (1, "no phpstan.neon"))
        self.write("phpstan-level-0.neon", LEVEL.format(0))
        self.assertEqual(self.check("checks_in_place"), (1, "no Unit suite under Tests/Unit/"))


if __name__ == "__main__":
    unittest.main()
