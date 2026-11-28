"""Tests for checks/phpstan_neon.py: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout. File names and classes are made up."""

import os
import sys
import tempfile
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
# No __pycache__ in checks/: the scanner refuses to run beside a file it did not deploy.
sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(HERE_TESTS, "..", "checks"))
import phpstan_neon as n  # noqa: E402


def write(path, text):
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text)


class PhpstanNeonTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.repo = tmp.name

    def test_configs_are_the_ones_the_runner_runs(self):
        self.assertEqual(n.configs(self.repo), [])
        write(os.path.join(self.repo, "phpstan-level-0.neon"), "")
        write(os.path.join(self.repo, "phpstan-level-1.neon"), "")
        write(os.path.join(self.repo, "phpstan-level-0-baseline.neon"), "")
        self.assertEqual(n.configs(self.repo), ["phpstan-level-0.neon", "phpstan-level-1.neon"])
        write(os.path.join(self.repo, "phpstan.neon.dist"), "")
        self.assertEqual(n.configs(self.repo), ["phpstan.neon.dist"])
        write(os.path.join(self.repo, "phpstan.neon"), "")
        self.assertEqual(n.configs(self.repo), ["phpstan.neon"])

    def test_php_version_as_a_number(self):
        self.assertEqual(n.php_version("parameters:\n    level: 0\n    phpVersion: 80100\n"), (80100, 80100))

    def test_php_version_as_a_range(self):
        inline = "parameters:\n\tphpVersion: {min: 80100, max: 80300}\n"
        block = "parameters:\n    phpVersion:\n        min: 80100\n        max: 80399\n    level: 1\n"
        self.assertEqual(n.php_version(inline), (80100, 80300))
        self.assertEqual(n.php_version(block), (80100, 80399))

    def test_no_php_version(self):
        self.assertIsNone(n.php_version("parameters:\n    level: 0\n"))
        self.assertIsNone(n.php_version("parameters:\n    phpVersion: {min: 80100}\n"))

    def test_level(self):
        self.assertEqual(n.level("parameters:\n    level: 10\n"), "10")
        self.assertEqual(n.level("parameters:\n\tlevel: max\n"), "max")
        self.assertIsNone(n.level("parameters:\n    paths: [.]\n"))

    def test_counts_the_undefined_properties_a_baseline_hides(self):
        baseline = (
            "parameters:\n\tignoreErrors:\n"
            "\t\t-\n\t\t\tmessage: '#^Access to an undefined property Demo\\\\Thing\\:\\:\\$a\\.$#'\n"
            "\t\t\tidentifier: property.notFound\n\t\t\tcount: 3\n\t\t\tpath: classes/Demo/Thing.php\n\n"
            "\t\t-\n\t\t\tmessage: '#^Variable \\$x might not be defined\\.$#'\n"
            "\t\t\tidentifier: variable.undefined\n\t\t\tcount: 2\n\t\t\tpath: wwwroot/a.php\n\n"
            "\t\t-\n\t\t\tmessage: '#^Access to an undefined property Demo\\\\Thing\\:\\:\\$b\\.$#'\n"
            "\t\t\tpath: classes/Demo/Thing.php\n"
        )
        self.assertEqual(n.undefined_properties(baseline), 4)

    def test_baselined_undefined_follows_the_configs_includes(self):
        write(os.path.join(self.repo, "phpstan.neon"),
              "includes:\n    - phpstan-baseline.neon\n    - vendor/x/extension.neon\n\nparameters:\n    level: 0\n")
        write(os.path.join(self.repo, "phpstan-baseline.neon"),
              "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tidentifier: property.notFound\n\t\t\tcount: 5\n")
        self.assertEqual(n.included_baselines(open(os.path.join(self.repo, "phpstan.neon")).read()),
                         ["phpstan-baseline.neon"])
        self.assertEqual(n.main(["x", "baselined-undefined", self.repo, "phpstan.neon"]), 0)

    def tiers(self, levels):
        for name, body in levels.items():
            write(os.path.join(self.repo, name), body)

    def test_lowest_level_of_one_config(self):
        self.assertIsNone(n.lowest_level(self.repo))
        self.tiers({"phpstan.neon": "parameters:\n    level: max\n"})
        self.assertEqual(n.lowest_level(self.repo), ("max", "phpstan.neon"))

    def test_lowest_level_is_the_lowest_tier(self):
        self.tiers({
            "phpstan-level-2.neon": "parameters:\n    level: 2\n",
            "phpstan-level-10.neon": "parameters:\n    level: 10\n",
            "phpstan-level-2-baseline.neon": "parameters:\n\tignoreErrors: []\n",
        })
        self.assertEqual(n.lowest_level(self.repo), ("2", "phpstan-level-2.neon"))

    def test_a_tier_with_no_level_or_an_unknown_one_is_the_answer(self):
        self.tiers({
            "phpstan-level-0.neon": "parameters:\n    level: 0\n",
            "phpstan-level-1.neon": "parameters:\n    level: strictest\n",
        })
        self.assertEqual(n.lowest_level(self.repo), ("strictest", "phpstan-level-1.neon"))
        self.tiers({"phpstan-level-3.neon": "parameters:\n    paths: [.]\n"})
        self.assertEqual(n.lowest_level(self.repo), (None, "phpstan-level-3.neon"))
        self.assertEqual(n.main(["x", "lowest-level", self.repo]), 0)

    def test_an_empty_baseline_hides_nothing(self):
        for text in ("", "parameters:\n\tignoreErrors: []\n", "# none yet\nparameters:\n    ignoreErrors: [] # none\n",
                     "parameters:\n\tignoreErrors:\n"):
            self.assertIsNone(n.hidden(text), text)

    def test_hidden_counts_findings_not_entries(self):
        baseline = (
            "parameters:\n\tignoreErrors:\n"
            "\t\t-\n\t\t\tmessage: '#^Variable \\$x might not be defined\\.$#'\n"
            "\t\t\tidentifier: variable.undefined\n\t\t\tcount: 3\n\t\t\tpath: wwwroot/a.php\n\n"
            "\t\t-\n\t\t\tmessage: '#^Other\\.$#'\n"
            "\t\t\tpaths:\n\t\t\t\t- wwwroot/a.php\n\t\t\t\t- wwwroot/b.php\n"
            "\t\t- '#^Written by hand$#'\n"
        )
        self.assertEqual(n.hidden(baseline), 5)

    def test_a_baseline_this_cannot_count_still_counts(self):
        self.assertEqual(n.hidden("parameters:\n    ignoreErrors: ['#^Anything#']\n"), 0)

    def test_baselines_lists_only_the_ones_that_hide_something(self):
        entry = "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^A$#'\n\t\t\tcount: 2\n"
        os.mkdir(os.path.join(self.repo, "build"))
        self.tiers({
            "phpstan-level-0.neon": "includes:\n    - phpstan-level-0-baseline.neon\n\nparameters:\n    level: 0\n",
            "phpstan-level-1.neon": "includes:\n    - build/old-baseline.neon\n\nparameters:\n    level: 1\n",
            "phpstan-level-0-baseline.neon": "parameters:\n\tignoreErrors: []\n",
            "phpstan-level-1-baseline.neon": entry,
            "build/old-baseline.neon": entry + "\t\t-\n\t\t\tmessage: '#^B$#'\n",
        })
        self.assertEqual(n.baselines(self.repo),
                         [("build/old-baseline.neon", 3), ("phpstan-level-1-baseline.neon", 2)])

    def test_an_included_baseline_that_is_missing_cannot_be_read(self):
        self.tiers({"phpstan.neon": "includes:\n    - phpstan-baseline.neon\n\nparameters:\n    level: 0\n"})
        self.assertEqual(n.main(["x", "baselines", self.repo]), 3)


if __name__ == "__main__":
    unittest.main()
