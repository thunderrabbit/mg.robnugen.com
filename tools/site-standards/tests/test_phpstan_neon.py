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


if __name__ == "__main__":
    unittest.main()
