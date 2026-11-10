"""Tests for the `target` param in scan.py: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout.

A step whose param is `target` is checked against the site's php_target in
standards-mcp's repos.yaml. Site names and paths are made up."""

import contextlib
import io
import os
import stat
import subprocess
import sys
import tempfile
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE_TESTS, ".."))
import scan  # noqa: E402

GIT = ["git", "-c", "user.name=t", "-c", "user.email=t@example.invalid"]


def write(path, text, executable=False):
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text)
    if executable:
        os.chmod(path, os.stat(path).st_mode | stat.S_IXUSR)


class PhpTargetsTest(unittest.TestCase):
    """php_targets() reads the registry the runner scripts read."""

    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.mcp = os.path.join(tmp.name, "standards-mcp")
        os.makedirs(self.mcp)
        home = os.path.join(tmp.name, "home")
        os.makedirs(home)
        saved = os.environ.get("HOME")
        os.environ["HOME"] = home
        self.addCleanup(self.restore_home, saved)
        self.home = home

    @staticmethod
    def restore_home(saved):
        if saved is None:
            os.environ.pop("HOME", None)
        else:
            os.environ["HOME"] = saved

    def test_reads_each_alias_path_and_target(self):
        write(os.path.join(self.mcp, "repos.yaml"),
              "# a comment\n"
              "demo:\n  path: ~/work/demo\n  php_target: \"8.3\"\n"
              "other:\n  path: /srv/other/\n  php_target: '8.5'\n"
              "bare:\n  path: ~/work/bare\n")
        targets = scan.php_targets(self.mcp)
        self.assertEqual(targets, {os.path.join(self.home, "work/demo"): "8.3", "/srv/other": "8.5"})

    def test_a_target_is_not_carried_into_the_next_alias(self):
        write(os.path.join(self.mcp, "repos.yaml"),
              "first:\n  php_target: \"8.3\"\n  path: ~/work/first\n"
              "second:\n  path: ~/work/second\n")
        self.assertEqual(scan.php_targets(self.mcp), {})

    def test_the_per_machine_registry_wins(self):
        write(os.path.join(self.mcp, "repos.yaml"), "demo:\n  path: /srv/demo\n  php_target: \"8.3\"\n")
        os.makedirs(os.path.join(self.home, ".config/standards-mcp"))
        write(os.path.join(self.home, ".config/standards-mcp/repos.yaml"),
              "demo:\n  path: /srv/demo\n  php_target: \"8.4\"\n")
        self.assertEqual(scan.php_targets(self.mcp), {"/srv/demo": "8.4"})

    def test_no_registry_gives_nothing(self):
        self.assertEqual(scan.php_targets(self.mcp), {})

    def test_a_target_that_is_not_major_minor_is_ignored(self):
        write(os.path.join(self.mcp, "repos.yaml"), "demo:\n  path: /srv/demo\n  php_target: latest\n")
        self.assertEqual(scan.php_targets(self.mcp), {})


class ScanTargetTest(unittest.TestCase):
    """scan_site() hands a `target` step the site's own php_target."""

    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        root = tmp.name
        self.here = os.path.join(root, "here")
        os.makedirs(os.path.join(self.here, "checks"))
        write(os.path.join(self.here, "lib.sh"), "# lib\n")
        self.work = os.path.join(root, "work")
        self.repo = os.path.join(self.work, "demo")
        os.makedirs(self.repo)
        subprocess.run([*GIT, "init", "-q", self.repo], check=True)
        subprocess.run([*GIT, "-C", self.repo, "commit", "-q", "--allow-empty", "-m", "first"], check=True)
        ssh = os.path.join(root, "fake-ssh.sh")
        write(ssh, f"#!/usr/bin/env bash\ngit -C '{self.repo}' rev-parse HEAD\n", executable=True)
        saved = os.environ.get("SCAN_SSH")
        os.environ["SCAN_SSH"] = ssh
        self.addCleanup(self.restore, saved)
        saved_here = scan.HERE
        scan.HERE = self.here
        self.addCleanup(setattr, scan, "HERE", saved_here)
        write(os.path.join(self.here, "checks", "echo.sh"),
              "#!/usr/bin/env bash\necho \"param=$2 target=$SITE_PHP_TARGET\"\n", executable=True)
        self.site = {"shortcode": "demo", "checkout_path": "demo", "standard": "std",
                     "server": {"ssh_alias": "demo", "directory": "demo"}}

    @staticmethod
    def restore(saved):
        if saved is None:
            os.environ.pop("SCAN_SSH", None)
        else:
            os.environ["SCAN_SSH"] = saved

    def scan(self, param, targets):
        files = {"std/track/a_step.md": f"---\ncheck: echo\nparam: {param}\n---\n"}
        with contextlib.redirect_stderr(io.StringIO()):
            return scan.scan_site(self.site, files, self.work, dict(os.environ), targets=targets)

    def test_a_target_param_becomes_the_sites_php_target(self):
        result = self.scan("target", {self.repo: "8.3"})
        self.assertEqual(result["results"], [{"step": "a_step", "outcome": "pass", "detail": "param=8.3 target=8.3"}])
        self.assertEqual(result["php_target"], "8.3")

    def test_a_fixed_param_stays_as_written(self):
        result = self.scan("8.5", {self.repo: "8.3"})
        self.assertEqual(result["results"][0]["detail"], "param=8.5 target=8.3")

    def test_no_target_in_the_registry_cannot_tell(self):
        result = self.scan("target", {})
        self.assertEqual(result["results"][0]["outcome"], "unknown")
        self.assertIn("no php_target", result["results"][0]["detail"])
        self.assertIsNone(result["php_target"])

    def test_a_skipped_site_still_reports_its_target(self):
        self.site["server"] = None
        result = self.scan("target", {self.repo: "8.3"})
        self.assertIsNotNone(result["skipped_reason"])
        self.assertEqual(result["php_target"], "8.3")


if __name__ == "__main__":
    unittest.main()
