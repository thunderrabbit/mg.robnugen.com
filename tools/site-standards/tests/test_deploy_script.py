"""Tests for checks/deploy_script.py: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout. Site names, aliases and directories are made up."""

import os
import sys
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
# No __pycache__ in checks/: the scanner refuses to run beside a file it did not deploy.
sys.dont_write_bytecode = True
sys.path.insert(0, os.path.join(HERE_TESTS, "..", "checks"))
import deploy_script as d  # noqa: E402

# Values written straight into the ssh line.
INLINE = """#!/bin/bash
# Deploy current branch to DreamHost via git push + ssh pull
set -e

cd "$(dirname "$0")"
BRANCH=$(git rev-parse --abbrev-ref HEAD)

echo "Pushing $BRANCH to origin..."
git push -u origin "$BRANCH"

echo "Pulling $BRANCH on DreamHost..."
ssh pond "cd pond.example.com; git restore .; git pull origin $BRANCH"

echo "Deployed $BRANCH to DreamHost."
"""

# The same deploy with the values in variables at the top.
VARIABLES = """#!/bin/bash
set -e

DEST="pond"                 # an ssh Host from ~/.ssh/config
DEST_PATH="pond.example.com"    # project root on the server, relative to ~
PULL_URL=""

cd "$(dirname "$0")"

if [ "$DEST" = "example" ]; then
    echo "Set DEST and DEST_PATH in $0 first." >&2
    exit 1
fi

if [ -z "$PULL_URL" ]; then
    PULL_URL=$(git remote get-url origin | sed -E 's#^git@github\\.com:#https://github.com/#')
fi

BRANCH=$(git rev-parse --abbrev-ref HEAD)
git push -u origin "$BRANCH"
ssh "$DEST" "cd $DEST_PATH; git restore .; git pull $PULL_URL $BRANCH"
echo "Deployed $BRANCH to $DEST."
"""


class JudgeTest(unittest.TestCase):
    def test_values_written_inline_are_enough(self):
        code, line = d.judge("deploy_pond_to_dh.sh", INLINE, "pond", "pond.example.com")
        self.assertEqual(code, d.DONE)
        self.assertEqual(line, "deploy_pond_to_dh.sh pushes, then pulls by git on pond:pond.example.com, the site's server")

    def test_values_in_variables_are_read_the_same(self):
        code, line = d.judge("deploy_pond_to_dh.sh", VARIABLES, "pond", "pond.example.com")
        self.assertEqual(code, d.DONE)
        self.assertIn("pond:pond.example.com, the site's server", line)

    def test_the_name_never_fails_a_script_but_the_line_says_which_is_preferred(self):
        for name in ("deploy_to_dh.sh", "deploy_on_dh.sh"):
            with self.subTest(name=name):
                code, line = d.judge(name, INLINE, "pond", "pond.example.com")
                self.assertEqual(code, d.DONE)
                self.assertIn("deploy_<slug>_to_dh.sh is the preferred name", line)

    def test_a_server_directory_below_the_project_root_matches(self):
        code, _ = d.judge("deploy_pond_to_dh.sh", INLINE, "pond", "pond.example.com/wwwroot/")
        self.assertEqual(code, d.DONE)

    def test_run_by_hand_the_server_is_not_compared(self):
        code, line = d.judge("deploy_pond_to_dh.sh", INLINE)
        self.assertEqual(code, d.DONE)
        self.assertIn("(not compared with a server)", line)

    def test_another_sites_alias_or_directory_is_not_yet(self):
        code, line = d.judge("deploy_pond_to_dh.sh", INLINE, "lake", "pond.example.com")
        self.assertEqual((code, line), (d.NOT_YET, "deploy_pond_to_dh.sh deploys to pond, but the site's server is lake"))
        code, line = d.judge("deploy_pond_to_dh.sh", INLINE, "pond", "lake.example.com")
        self.assertEqual(code, d.NOT_YET)
        self.assertIn("pulls in pond.example.com", line)
        code, _ = d.judge("deploy_pond_to_dh.sh", INLINE, "pond", "pond.example.com.old")
        self.assertEqual(code, d.NOT_YET)

    def test_a_template_not_filled_in_is_not_yet(self):
        body = VARIABLES.replace('DEST="pond"', 'DEST="example"')
        code, line = d.judge("deploy_to_dh.sh", body, "pond", "pond.example.com")
        self.assertEqual(code, d.NOT_YET)
        self.assertIn('still deploys to "example"', line)

    def test_no_push_is_not_yet(self):
        body = INLINE.replace('git push -u origin "$BRANCH"\n', "")
        self.assertEqual(d.judge("deploy_pond_to_dh.sh", body), (d.NOT_YET, "deploy_pond_to_dh.sh does not git push"))

    def test_no_pull_over_ssh_is_not_yet(self):
        body = INLINE.replace("git pull origin $BRANCH", "touch restart.txt")
        code, line = d.judge("deploy_pond_to_dh.sh", body)
        self.assertEqual((code, line), (d.NOT_YET, "deploy_pond_to_dh.sh does not git pull over ssh on the server"))

    def test_a_pull_without_a_cd_is_not_yet(self):
        body = INLINE.replace("cd pond.example.com; ", "")
        code, line = d.judge("deploy_pond_to_dh.sh", body)
        self.assertEqual(code, d.NOT_YET)
        self.assertIn("without a cd", line)

    def test_copying_files_is_not_yet(self):
        for copy in ("rsync -a ./ pond:pond.example.com/", "scp -r classes pond:pond.example.com/"):
            with self.subTest(copy=copy):
                code, line = d.judge("deploy_pond_to_dh.sh", INLINE + copy + "\n")
                self.assertEqual(code, d.NOT_YET)
                self.assertIn("copies files with " + copy.split(" ")[0], line)

    def test_an_old_header_that_installs_with_rsync_is_not_yet(self):
        body = INLINE.replace("# Deploy current", "# First install: rsync -a ./ <DEST>:<DEST_PATH>/\n# Deploy current")
        code, line = d.judge("deploy_pond_to_dh.sh", body)
        self.assertEqual(code, d.NOT_YET)
        self.assertIn("install a site with rsync", line)

    def test_a_word_in_a_comment_or_a_message_is_not_a_copy(self):
        body = INLINE.replace("set -e", 'set -e\n# never scp the tree\necho "no rsync here"')
        self.assertEqual(d.judge("deploy_pond_to_dh.sh", body)[0], d.DONE)

    def test_a_server_worked_out_at_run_time_could_not_be_told(self):
        body = VARIABLES.replace('DEST="pond" ', "DEST=$(cat .deploy_host) ")
        code, line = d.judge("deploy_pond_to_dh.sh", body, "pond", "pond.example.com")
        self.assertEqual(code, d.UNKNOWN)
        self.assertIn("could not read", line)

    def test_a_home_relative_directory_and_ssh_options_are_read(self):
        body = INLINE.replace('ssh pond "cd pond.example.com;', 'ssh -A pond "cd ~/pond.example.com/ &&')
        self.assertEqual(d.judge("deploy_pond_to_dh.sh", body, "pond", "pond.example.com")[0], d.DONE)


class ChooseTest(unittest.TestCase):
    def test_the_script_named_for_its_site_is_read_first(self):
        names = ["deploy.sh", "deploy_to_dh.sh", "deploy_pond_to_dh.sh", "tools/deploy_x_to_dh.sh", "scp_files_to_dh.sh"]
        self.assertEqual(d.order(names), ["deploy_pond_to_dh.sh", "deploy_to_dh.sh", "deploy.sh"])

    def test_no_deploy_script_is_not_yet(self):
        code, line = d.choose([])
        self.assertEqual(code, d.NOT_YET)
        self.assertIn("no tracked deploy script", line)

    def test_a_script_that_is_not_executable_is_not_yet(self):
        code, line = d.choose([("deploy_pond_to_dh.sh", "100644", INLINE)])
        self.assertEqual(code, d.NOT_YET)
        self.assertIn("not executable", line)

    def test_a_script_that_could_not_be_read_could_not_be_told(self):
        self.assertEqual(d.choose([("deploy_pond_to_dh.sh", "100755", None)])[0], d.UNKNOWN)

    def test_a_later_script_that_deploys_by_git_answers(self):
        code, line = d.choose([("deploy_pond_to_dh.sh", "100644", INLINE), ("deploy_to_dh.sh", "100755", INLINE)])
        self.assertEqual(code, d.DONE)
        self.assertTrue(line.startswith("deploy_to_dh.sh "))

    def test_when_none_deploys_by_git_the_first_one_says_why(self):
        code, line = d.choose([("deploy_pond_to_dh.sh", "100755", "echo hi\n"), ("deploy.sh", "100755", "echo hi\n")])
        self.assertEqual((code, line), (d.NOT_YET, "deploy_pond_to_dh.sh does not git push"))


if __name__ == "__main__":
    unittest.main()
