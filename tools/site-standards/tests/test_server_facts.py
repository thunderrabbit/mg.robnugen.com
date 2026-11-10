"""Tests for server_facts.py: run with
python3 -I -B -m unittest discover -s tools/site-standards/tests
"""
import json
import os
import stat
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, ".."))
import server_facts  # noqa: E402

SHA_A = "a" * 40
SHA_B = "b" * 40


def fake_ssh(directory, facts_line, git_out="", git_code=0):
    """A stand-in for ssh: <alias>_facts prints facts_line, any other host prints git_out."""
    path = os.path.join(directory, "ssh")
    out = os.path.join(directory, "git-out")
    with open(out, "w", encoding="utf-8") as handle:
        handle.write(git_out)
    with open(path, "w", encoding="utf-8") as handle:
        handle.write("#!/usr/bin/env bash\n"
                     "for a in \"$@\"; do case \"$a\" in -*) ;; *) host=\"$a\"; break;; esac; done\n"
                     f"case \"$host\" in *_facts) printf '%s\\n' {json.dumps(facts_line)}; "
                     "echo 'PTY allocation request failed' >&2;; "
                     f"*) cat {out}; exit {git_code};; esac\n")
    os.chmod(path, os.stat(path).st_mode | stat.S_IXUSR)
    return path


def document(directory, **record):
    base = {"domain": "scan.invalid", "directory": directory, "head_commit": SHA_A,
            "head_branch": "main", "reflog": [{"commit": SHA_A, "unix": 200}, {"commit": SHA_B, "unix": 100}]}
    base.update(record)
    return json.dumps({"domains": [base]})


class ServerFactsTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.dir = tmp.name
        self.saved = os.environ.get("SCAN_SSH")
        self.addCleanup(self.restore)

    def restore(self):
        if self.saved is None:
            os.environ.pop("SCAN_SSH", None)
        else:
            os.environ["SCAN_SSH"] = self.saved

    def ssh(self, facts_line, git_out="", git_code=0):
        os.environ["SCAN_SSH"] = fake_ssh(self.dir, facts_line, git_out, git_code)

    def test_ec2_head_and_branch_come_from_the_facts_host(self):
        self.ssh(document("/var/www/site/current"))
        self.assertEqual((SHA_A, "main"), server_facts.head_and_branch("box", "/var/www/site/current"))

    def test_ec2_reflog_comes_from_the_facts_host_newest_first(self):
        self.ssh(document("/var/www/site/current"))
        self.assertEqual([(SHA_A, 200), (SHA_B, 100)], server_facts.reflog("box", "/var/www/site/current"))

    def test_facts_host_without_a_document_falls_back_to_git(self):
        self.ssh("not json", git_out=SHA_B + "\ndev\n")
        self.assertEqual((SHA_B, "dev"), server_facts.head_and_branch("box", "/var/www/site/current"))

    def test_relative_directory_never_asks_the_facts_host(self):
        self.ssh(document("site"), git_out=SHA_B + "\n")
        self.assertEqual((SHA_B, None), server_facts.head_and_branch("dh", "site"))

    def test_a_checkout_with_no_commit_says_so(self):
        self.ssh(document("/var/www/site/current", head_commit=None, head_branch=None))
        with self.assertRaises(server_facts.NoAnswer):
            server_facts.head_and_branch("box", "/var/www/site/current")

    def test_a_malformed_reflog_is_refused(self):
        self.ssh(document("/var/www/site/current", reflog=[{"commit": "nope", "unix": 1}]))
        with self.assertRaises(server_facts.NoAnswer):
            server_facts.reflog("box", "/var/www/site/current")

    def test_git_reflog_fallback_parses_lines(self):
        self.ssh("", git_out=f"{SHA_A} HEAD@{{300}}\n{SHA_B} HEAD@{{250}}\n")
        self.assertEqual([(SHA_A, 300), (SHA_B, 250)], server_facts.reflog("dh", "site"))

    def test_bad_names_are_refused(self):
        self.ssh("")
        with self.assertRaises(server_facts.NoAnswer):
            server_facts.head_and_branch("box; rm", "site")
        with self.assertRaises(server_facts.NoAnswer):
            server_facts.head_and_branch("box", "../site")


if __name__ == "__main__":
    unittest.main()
