"""Tests for the memo wiring in scan.py's scan_site: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout.

A throwaway checks directory holds scripts that count how often they run,
so "remembered" is observed, not assumed. Site names are made up."""

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


class ScanMemoTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        root = tmp.name
        self.count = os.path.join(root, "count")
        self.here = os.path.join(root, "here")
        os.makedirs(os.path.join(self.here, "checks"))
        write(os.path.join(self.here, "lib.sh"), "# lib\n")
        self.work = os.path.join(root, "work")
        self.repo = os.path.join(self.work, "demo")
        os.makedirs(self.repo)
        subprocess.run([*GIT, "init", "-q", self.repo], check=True)
        self.commit("first")
        ssh = os.path.join(root, "fake-ssh.sh")
        write(ssh, f"#!/usr/bin/env bash\ngit -C '{self.repo}' rev-parse HEAD\n", executable=True)
        self.state = os.path.join(root, "state")
        self.patches = {"SCAN_SSH": ssh, "XDG_STATE_HOME": self.state, "COUNT_FILE": self.count}
        saved = {name: os.environ.get(name) for name in self.patches}
        os.environ.update(self.patches)
        self.addCleanup(self.restore, saved)
        saved_here = scan.HERE
        scan.HERE = self.here
        self.addCleanup(setattr, scan, "HERE", saved_here)
        self.site = {"shortcode": "demo", "checkout_path": "demo", "standard": "std",
                     "server": {"ssh_alias": "demo", "directory": "demo"}}

    @staticmethod
    def restore(saved):
        for name, value in saved.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value

    def commit(self, message):
        subprocess.run([*GIT, "-C", self.repo, "commit", "-q", "--allow-empty", "-m", message], check=True)

    def check(self, name, exit_code=0, memo_header=True):
        write(os.path.join(self.here, "checks", name + ".sh"),
              "#!/usr/bin/env bash\n" + ("# memo: commit\n" if memo_header else "")
              + f"echo ran >> \"$COUNT_FILE\"\necho \"{name} said {exit_code}\"\nexit {exit_code}\n",
              executable=True)
        return {f"std/track/{name}.md": f"---\ncheck: {name}\nparam: 8.5\n---\n"}

    def runs(self):
        try:
            with open(self.count, encoding="utf-8") as handle:
                return len(handle.read().splitlines())
        except FileNotFoundError:
            return 0

    def scan(self, files, tool_id="tools-1", fresh=False):
        err = io.StringIO()
        with contextlib.redirect_stderr(err):
            result = scan.scan_site(self.site, files, self.work, dict(os.environ), tool_id=tool_id, fresh=fresh)
        return result, err.getvalue()

    def test_second_scan_of_the_same_commit_reuses_the_answer(self):
        files = self.check("alpha")
        first, _ = self.scan(files)
        second, said = self.scan(files)
        self.assertEqual(self.runs(), 1)
        self.assertEqual(first["results"], second["results"])
        self.assertRegex(said, r"remembered from \d{4}-\d\d-\d\d \d\d:\d\d \S+")

    def test_remembered_answer_is_the_same_document_as_a_fresh_one(self):
        files = self.check("alpha", exit_code=1)
        first, _ = self.scan(files)
        second, _ = self.scan(files)
        self.assertEqual(second["results"], [{"step": "alpha", "outcome": "fail", "detail": "alpha said 1"}])
        self.assertEqual(first, second)

    def test_a_new_commit_runs_the_check_again(self):
        files = self.check("alpha")
        self.scan(files)
        self.commit("second")
        self.scan(files)
        self.assertEqual(self.runs(), 2)

    def test_a_changed_check_script_runs_again(self):
        files = self.check("alpha")
        self.scan(files)
        script = os.path.join(self.here, "checks", "alpha.sh")
        with open(script, encoding="utf-8") as handle:
            edited = handle.read() + "# edited\n"
        write(script, edited, executable=True)
        self.scan(files)
        self.assertEqual(self.runs(), 2)

    def test_a_changed_param_runs_again(self):
        files = self.check("alpha")
        self.scan(files)
        files = {path: text.replace("8.5", "8.6") for path, text in files.items()}
        self.scan(files)
        self.assertEqual(self.runs(), 2)

    def test_a_changed_fact_runs_again(self):
        files = self.check("alpha")
        self.scan(files)
        self.site["php_current"] = "8.4"
        self.scan(files)
        self.assertEqual(self.runs(), 2)

    def test_a_changed_tool_identity_runs_again(self):
        files = self.check("alpha")
        self.scan(files, tool_id="tools-1")
        self.scan(files, tool_id="tools-2")
        self.assertEqual(self.runs(), 2)

    def test_unknown_is_never_remembered(self):
        files = self.check("alpha", exit_code=2)
        self.scan(files)
        self.scan(files)
        self.assertEqual(self.runs(), 2)

    def test_a_check_without_the_header_runs_every_scan(self):
        files = self.check("alpha", memo_header=False)
        self.scan(files)
        self.scan(files)
        self.assertEqual(self.runs(), 2)

    def test_fresh_runs_everything_and_rewrites_the_memo(self):
        files = self.check("alpha")
        self.scan(files)
        self.scan(files, fresh=True)
        self.assertEqual(self.runs(), 2)
        self.scan(files)
        self.assertEqual(self.runs(), 2)

    def test_without_a_tool_identity_nothing_is_remembered(self):
        files = self.check("alpha")
        self.scan(files, tool_id=None)
        self.scan(files, tool_id=None)
        self.assertEqual(self.runs(), 2)
        self.assertFalse(os.path.exists(self.state))

    def test_a_damaged_memo_file_does_not_stop_the_scan(self):
        files = self.check("alpha")
        self.scan(files)
        memo_dir = os.path.join(self.state, "site-standards")
        for name in os.listdir(memo_dir):
            write(os.path.join(memo_dir, name), "{broken")
        result, _ = self.scan(files)
        self.assertEqual(result["results"][0]["outcome"], "pass")
        self.assertEqual(self.runs(), 2)

    def test_an_unwritable_state_directory_does_not_stop_the_scan(self):
        locked = os.path.join(os.path.dirname(self.state), "locked")
        os.mkdir(locked)
        os.chmod(locked, stat.S_IRUSR | stat.S_IXUSR)
        self.addCleanup(os.chmod, locked, stat.S_IRWXU)
        if os.access(locked, os.W_OK):
            self.skipTest("running as a user who can write anywhere")
        os.environ["XDG_STATE_HOME"] = os.path.join(locked, "state")
        files = self.check("alpha")
        result, said = self.scan(files)
        self.assertEqual(result["results"][0]["outcome"], "pass")
        self.assertIn("could not save the scan memo", said)


class ArgumentsTest(unittest.TestCase):
    def test_flags_and_shortcodes_are_told_apart(self):
        self.assertEqual(scan.parse_arguments(["--dry-run", "--fresh", "mg", "demo"]), (True, True, ["mg", "demo"]))

    def test_no_arguments_means_every_site_for_real(self):
        self.assertEqual(scan.parse_arguments([]), (False, False, []))

    def test_unknown_option_stops_with_usage(self):
        err = io.StringIO()
        with contextlib.redirect_stderr(err), self.assertRaises(SystemExit):
            scan.parse_arguments(["--fresh-ish"])
        self.assertIn("[--dry-run] [--fresh]", err.getvalue())


if __name__ == "__main__":
    unittest.main()
