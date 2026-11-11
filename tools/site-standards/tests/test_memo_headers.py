"""Guards for the `# memo: commit` header on checks: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout.

The header promises that a check's answer depends only on the repo at one
commit, the SITE_* facts, lib.sh, the helper files beside the checks and the
standards-mcp tools. These tests keep that promise honest by name and by text.
"""

import glob
import os
import re
import sys
import unittest

CHECKS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "checks")
sys.path.insert(0, os.path.join(CHECKS, ".."))
import memo  # noqa: E402

# Repo-only checks: their answer is a function of the commit and the key's inputs.
OPTED_IN = {
    "admin_link_admins_only", "code_runs_on_php", "codeception_layout", "composer_platform_pinned", "deploy_script", "js_build_reproducible", "js_inventory", "js_pinned",
    "js_shims_removed", "js_visual_baseline", "mlaphp_autoloader", "mlaphp_functions_in_classes",
    "mlaphp_no_global", "mlaphp_no_includes_in_classes", "mlaphp_print_rob_in_class",
    "mlaphp_print_roblog_in_class", "mlaphp_csrf_helper", "mlaphp_print_debug_in_class",
    "mlaphp_unused_function_removed",
    "phpunit_under_codeception",
    "mlaphp_public_split", "mlaphp_register_via_user_repository", "mlaphp_sql_in_gateways",
    "mysql84_reserved_words", "no_dynamic_properties", "phpstan_php_range", "phpstan_top",
    "rector_level", "rector_types",
}

# Never opted in, and why.
OPTED_OUT = {
    "checks_in_place": "reads the checkout's own .git/config (core.hooksPath)",
    "css_cascade_guarded": "reads the checkout's own .git/config (core.hooksPath)",
    "js_checks_in_place": "reads the checkout's own .git/config (core.hooksPath)",
    "js_hotfixes_ported": "compares the server's line with other branches",
    "js_libraries_current": "asks the npm registry, which changes without a commit",
    "js_soaked_on_production": "asks the server over ssh",
    "js_deprecations_clear": "asks a test domain; keeps its own memo",
    "suite_passes_on_php": "asks a test domain; keeps its own memo",
    "tooling_on_php": "reads the checkout's own .git/config (core.hooksPath)",
}

# What a memoized check must never do: look outside the repo and the key's inputs.
FORBIDDEN = [
    (r"\bssh\b", "talks to a server"),
    (r"\bcurl\b|\bwget\b|\burllib\b", "reads the network"),
    (r"npm view|docker pull", "reads the network"),
    (r"checks/", "runs another check, whose script is not in the key"),
    (r"\$\(date|\bdate \+|\btime\.time|datetime\.now", "reads the clock"),
    (r"need_hooks|git[^\n]*\bconfig\b", "reads the checkout's .git/config, which is not in the commit"),
    (r"git[^\n]*\b(log|branch|reflog|rev-list|merge-base|for-each-ref|fetch|remote|tag)\b",
     "reads history or refs beyond the commit"),
]


def code_of(name):
    """A check's text without its full-line comments."""
    with open(os.path.join(CHECKS, name + ".sh"), encoding="utf-8") as handle:
        return "\n".join(line for line in handle.read().splitlines() if not line.lstrip().startswith("#"))


def script(name):
    return os.path.join(CHECKS, name + ".sh")


class MemoHeaderTest(unittest.TestCase):
    def test_every_expected_check_exists_and_is_opted_in(self):
        for name in sorted(OPTED_IN):
            with self.subTest(check=name):
                self.assertTrue(os.path.isfile(script(name)), "no such check script")
                self.assertTrue(memo.is_memoizable(script(name)), "missing '# memo: commit'")

    def test_no_other_check_is_opted_in(self):
        every = {os.path.basename(path)[:-3] for path in glob.glob(os.path.join(CHECKS, "*.sh"))}
        self.assertEqual({name for name in every if memo.is_memoizable(script(name))}, OPTED_IN)

    def test_known_outside_checks_are_not_opted_in(self):
        for name, why in OPTED_OUT.items():
            with self.subTest(check=name, why=why):
                self.assertFalse(memo.is_memoizable(script(name)))

    def test_opted_in_checks_stay_inside_the_repo(self):
        for name in sorted(OPTED_IN):
            text = code_of(name)
            for pattern, why in FORBIDDEN:
                with self.subTest(check=name, rule=why):
                    self.assertIsNone(re.search(pattern, text), f"{name} {why}")

    def test_the_opt_in_and_opt_out_lists_do_not_overlap(self):
        self.assertEqual(OPTED_IN & set(OPTED_OUT), set())


if __name__ == "__main__":
    unittest.main()
