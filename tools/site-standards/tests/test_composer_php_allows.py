"""Tests for checks/composer_php_allows.py: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout."""

import os
import sys
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE_TESTS, "..", "checks"))
import composer_php_allows as c  # noqa: E402


class ComposerPhpAllowsTest(unittest.TestCase):
    def test_a_floor_allows_the_target_above_it(self):
        self.assertTrue(c.allows(">=8.1", "8.3"))
        self.assertTrue(c.allows(">=8.1.2", "8.3"))
        self.assertFalse(c.allows(">=8.5", "8.3"))

    def test_a_floor_inside_the_target_line_still_allows_it(self):
        self.assertTrue(c.allows(">=8.3.4", "8.3"))

    def test_caret_and_tilde(self):
        self.assertTrue(c.allows("^8.1", "8.3"))
        self.assertFalse(c.allows("^7.4", "8.3"))
        self.assertTrue(c.allows("~8.1", "8.5"))
        self.assertFalse(c.allows("~8.1.2", "8.3"))
        self.assertTrue(c.allows("~8.3.0", "8.3"))

    def test_ranges_and_alternatives(self):
        self.assertTrue(c.allows(">=8.1 <8.4", "8.3"))
        self.assertFalse(c.allows(">=8.1 <8.3", "8.3"))
        self.assertTrue(c.allows(">=8.1,<8.4", "8.3"))
        self.assertTrue(c.allows("^7.4 || ^8.1", "8.3"))
        self.assertTrue(c.allows("8.1 - 8.3", "8.3"))
        self.assertFalse(c.allows("8.1 - 8.2", "8.3"))

    def test_wildcards_and_exact(self):
        self.assertTrue(c.allows("8.3.*", "8.3"))
        self.assertFalse(c.allows("8.1.*", "8.3"))
        self.assertTrue(c.allows("8.*", "8.3"))
        self.assertTrue(c.allows("*", "8.3"))
        self.assertTrue(c.allows("8.3", "8.3"))
        self.assertFalse(c.allows("8.1.2", "8.3"))

    def test_a_shape_it_cannot_read_is_an_error(self):
        with self.assertRaises(ValueError):
            c.allows(">=eight", "8.3")
        self.assertEqual(c.main(["x", ">=eight", "8.3"]), 3)
        self.assertEqual(c.main(["x", ">=8.1", "8.3"]), 0)
        self.assertEqual(c.main(["x", ">=8.5", "8.3"]), 1)


if __name__ == "__main__":
    unittest.main()
