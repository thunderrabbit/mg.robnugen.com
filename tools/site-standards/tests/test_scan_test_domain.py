"""Tests for the test-domain rule in scan.py: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout.

A site with a test domain and no upgrade branch is read when its checkout is
at the commit the test domain runs and the server's commit is inside it.
Site names, hosts and paths are made up."""

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


class ScanTestDomainTest(unittest.TestCase):
    """scan_site() with a server on one commit and a test domain on another."""

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
        self.first = self.commit("first")
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "-b", "side"], check=True)
        self.side = self.commit("a fix made on the server's line only")
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "-b", "work", self.first], check=True)
        self.second = self.commit("second")

        # The fake ssh answers by host: answers/<host> holds what it says, and
        # a host with no file is one that cannot be reached.
        self.answers = os.path.join(root, "answers")
        os.makedirs(self.answers)
        self.log = os.path.join(root, "asked.log")
        ssh = os.path.join(root, "fake-ssh.sh")
        write(ssh, f"#!/usr/bin/env bash\necho \"$1\" >> '{self.log}'\n"
                   f"[ -f '{self.answers}'/\"$1\" ] || {{ echo 'no route to host' >&2; exit 255; }}\n"
                   f"cat '{self.answers}'/\"$1\"\n", executable=True)
        saved = os.environ.get("SCAN_SSH")
        os.environ["SCAN_SSH"] = ssh
        self.addCleanup(self.restore, saved)
        saved_here = scan.HERE
        scan.HERE = self.here
        self.addCleanup(setattr, scan, "HERE", saved_here)
        write(os.path.join(self.here, "checks", "echo.sh"), "#!/usr/bin/env bash\necho ran\n", executable=True)
        self.site = {"shortcode": "demo", "checkout_path": "demo", "standard": "std",
                     "server": {"ssh_alias": "prod", "directory": "demo"},
                     "test": {"domain": "shop-test.example", "ssh_alias": "stage", "directory": "demo-test"}}

    @staticmethod
    def restore(saved):
        if saved is None:
            os.environ.pop("SCAN_SSH", None)
        else:
            os.environ["SCAN_SSH"] = saved

    def commit(self, message):
        subprocess.run([*GIT, "-C", self.repo, "commit", "-q", "--allow-empty", "-m", message], check=True)
        done = subprocess.run([*GIT, "-C", self.repo, "rev-parse", "HEAD"], check=True, capture_output=True, text=True)
        return done.stdout.strip()

    def answer(self, host, commit):
        write(os.path.join(self.answers, host), commit + "\n")

    def asked(self):
        if not os.path.exists(self.log):
            return []
        with open(self.log, encoding="utf-8") as handle:
            return handle.read().split()

    def scan(self):
        files = {"std/track/10_a_step.md": "---\ncheck: echo\n---\n"}
        self.said = io.StringIO()
        with contextlib.redirect_stderr(self.said):
            return scan.scan_site(self.site, files, self.work, dict(os.environ))

    def test_checkout_at_the_test_domains_commit_is_scanned(self):
        self.answer("prod", self.first)
        self.answer("stage", self.second)
        result = self.scan()
        self.assertIsNone(result["skipped_reason"])
        self.assertEqual(result["commit"], self.second)
        self.assertEqual(result["server_commit"], self.first)
        self.assertEqual(result["test_commit"], self.second)
        self.assertEqual(result["results"][0]["outcome"], "pass")
        self.assertIn("checking what shop-test.example runs", self.said.getvalue())

    def test_test_domain_on_another_commit_skips(self):
        self.answer("prod", self.first)
        self.answer("stage", self.first)
        result = self.scan()
        self.assertIn("its test domain runs " + self.first[:7], result["skipped_reason"])
        self.assertIn("the checkout is at " + self.second[:7], result["skipped_reason"])
        self.assertEqual(result["test_commit"], self.first)
        self.assertEqual(result["results"], [])

    def test_test_domain_that_cannot_be_asked_skips(self):
        self.answer("prod", self.first)
        result = self.scan()
        self.assertIn("its test domain could not be asked", result["skipped_reason"])
        self.assertIsNone(result["test_commit"])
        self.assertEqual(result["results"], [])

    def test_server_commit_outside_the_checkout_skips(self):
        self.answer("prod", self.side)
        self.answer("stage", self.second)
        result = self.scan()
        self.assertIn("does not include", result["skipped_reason"])
        self.assertEqual(result["results"], [])

    def test_server_commit_the_checkout_lacks_skips(self):
        self.answer("prod", "c" * 40)
        self.answer("stage", self.second)
        result = self.scan()
        self.assertIsNotNone(result["skipped_reason"])
        self.assertEqual(result["results"], [])

    def test_upgrade_branch_site_never_asks_the_test_domain(self):
        self.site["upgrade_branch"] = "green"
        self.answer("prod", self.first)
        self.answer("stage", self.second)
        result = self.scan()
        self.assertIn("not on green", result["skipped_reason"])
        self.assertIsNone(result["test_commit"])
        self.assertNotIn("stage", self.asked())

    def test_checkout_at_the_servers_commit_never_asks_the_test_domain(self):
        self.answer("prod", self.second)
        self.answer("stage", self.first)
        result = self.scan()
        self.assertIsNone(result["skipped_reason"])
        self.assertIsNone(result["test_commit"])
        self.assertNotIn("stage", self.asked())

    def test_site_without_a_test_domain_skips_as_before(self):
        self.site["test"] = None
        self.answer("prod", self.first)
        result = self.scan()
        self.assertIn("the checkout is at", result["skipped_reason"])
        self.assertIsNone(result["test_commit"])

    def test_uncommitted_changes_still_skip(self):
        self.answer("prod", self.first)
        self.answer("stage", self.second)
        write(os.path.join(self.repo, "stray.txt"), "not committed\n")
        result = self.scan()
        self.assertEqual(result["skipped_reason"], "uncommitted changes")


if __name__ == "__main__":
    unittest.main()
