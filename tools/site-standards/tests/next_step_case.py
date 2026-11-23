"""What every next_step test needs: a scratch mg checkout, a scratch site
checkout under a scratch ~/work, and stand-ins for `site.sh` and ssh.
Site names, hosts and paths are made up."""

import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE_TESTS, ".."))
sys.path.insert(0, HERE_TESTS)
import next_step  # noqa: E402

GIT = ["git", "-c", "user.name=t", "-c", "user.email=t@example.invalid"]
TRACK = "---\nname: Track\nposition: 10\nmodel: sonnet\n---\n"
STEP = "---\ntitle: A step\nstatus: proven\nwhose_move: agent\ncheck: echo\n---\nWhy.\n\n## How to do it\n\nDo it.\n"


def write(path, text, executable=False):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text)
    if executable:
        os.chmod(path, os.stat(path).st_mode | stat.S_IXUSR)


class NextStepCase(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = root = tmp.name

        # A scratch mg checkout: next_step.HERE points into it, so the checks
        # it runs and the step files it reads with git are the test's own.
        self.mg = os.path.join(root, "mg")
        self.here = os.path.join(self.mg, "tools", "site-standards")
        os.makedirs(os.path.join(self.here, "checks"))
        subprocess.run([*GIT, "init", "-q", "-b", "topic", self.mg], check=True)
        self.steps = os.path.join(self.here, "steps", "std", "track")
        write(os.path.join(self.here, "steps", "std", "_standard.md"), "---\nname: Std\n---\n")
        write(os.path.join(self.steps, "_track.md"), TRACK)
        write(os.path.join(self.steps, "010_a_step.md"), STEP)
        self.steps_commit = self.commit_all(self.mg, "steps")

        # A scratch site checkout under a scratch ~/work.
        self.work = os.path.join(root, "work")
        self.repo = os.path.join(self.work, "rob", "demo")
        os.makedirs(self.repo)
        subprocess.run([*GIT, "init", "-q", "-b", "master", self.repo], check=True)
        self.first = self.commit(self.repo, "first")
        subprocess.run([*GIT, "-C", self.repo, "checkout", "-q", "-b", "topic"], check=True)
        self.second = self.commit(self.repo, "second")

        # `site.sh`: logs its arguments; prints mg-answer, or fails as told.
        self.site_log = os.path.join(root, "site.log")
        site = os.path.join(root, "fake-site.sh")
        write(site, "#!/usr/bin/env bash\n"
                    f"printf '%s\\n' \"$*\" >> '{self.site_log}'\n"
                    f"if [ -f '{root}/mg-exit' ]; then cat '{root}/mg-stderr' >&2; exit \"$(cat '{root}/mg-exit')\"; fi\n"
                    f"cat '{root}/mg-answer'\n", executable=True)

        # ssh: answers by host; a host with no file cannot be reached.
        self.hosts = os.path.join(root, "hosts")
        os.makedirs(self.hosts)
        ssh = os.path.join(root, "fake-ssh.sh")
        write(ssh, "#!/usr/bin/env bash\n"
                   f"[ -f '{self.hosts}'/\"$1\" ] || {{ echo 'no route to host' >&2; exit 255; }}\n"
                   f"cat '{self.hosts}'/\"$1\"\n", executable=True)

        # standards-mcp: gates are written by a test that wants them.
        self.mcp = os.path.join(root, "standards-mcp")
        os.makedirs(self.mcp)
        home = os.path.join(root, "home")   # so the real repos.yaml is never read
        os.makedirs(home)

        patches = {"WORK_ROOT": self.work, "SCAN_SITE_CMD": site, "SCAN_SSH": ssh,
                   "STANDARDS_MCP": self.mcp, "HOME": home}
        saved = {name: os.environ.get(name) for name in patches}
        os.environ.update(patches)
        self.addCleanup(self.restore, saved)
        saved_here = next_step.HERE
        next_step.HERE = self.here
        self.addCleanup(setattr, next_step, "HERE", saved_here)

    @staticmethod
    def restore(saved):
        for name, value in saved.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value

    @staticmethod
    def commit(repo, message):
        subprocess.run([*GIT, "-C", repo, "commit", "-q", "--allow-empty", "-m", message], check=True)
        return subprocess.run([*GIT, "-C", repo, "rev-parse", "HEAD"], check=True,
                              capture_output=True, text=True).stdout.strip()

    def commit_all(self, repo, message):
        subprocess.run([*GIT, "-C", repo, "add", "-A"], check=True)
        return self.commit(repo, message)

    # ---- what mg says -------------------------------------------------
    @staticmethod
    def step(**over):
        base = {"slug": "a_step", "track": "Track", "title": "A step", "whose_move": "agent",
                "detail": "not yet", "check": "echo", "check_param": None,
                "explanation": "Why.", "how_to": "Do it."}
        return dict(base, **over)

    @staticmethod
    def site(steps, **over):
        base = {"shortcode": "demo", "standard": "std", "standing": {"mark": "info", "text": "1 step to go"},
                "php_current": "8.4", "test_php": None, "last_checked_utc": "2026-11-20 01:02:03",
                "skipped_reason": None, "open_steps": steps}
        return dict(base, **over)

    @staticmethod
    def facts(**over):
        base = {"shortcode": "demo", "standard": "std", "checkout_path": "rob/demo",
                "server": {"ssh_alias": "prod", "directory": "demo.example"}, "server_problem": None,
                "php_current": "8.4", "test": None, "upgrade_branch": None}
        return dict(base, **over)

    def mg_says(self, site, facts=None, shortcodes=(), steps_commit="scanned"):
        """What `site.sh next-step` prints. facts defaults to the usual ones when there is a site."""
        if site is not None and facts is None:
            facts = self.facts()
        commit = self.steps_commit if steps_commit == "scanned" else steps_commit
        write(os.path.join(self.root, "mg-answer"),
              json.dumps({"site": site, "shortcodes": list(shortcodes), "facts": facts, "steps_commit": commit}))

    def mg_fails(self, code, stderr):
        write(os.path.join(self.root, "mg-stderr"), stderr)
        write(os.path.join(self.root, "mg-exit"), str(code))

    def site_asked(self):
        with open(self.site_log, encoding="utf-8") as handle:
            return handle.read().splitlines()

    # ---- the server, the checks, the gates ----------------------------
    def server_runs(self, commit, host="prod"):
        write(os.path.join(self.hosts, host), commit + "\n")

    def check(self, name, exit_code, line, body=""):
        write(os.path.join(self.here, "checks", name + ".sh"),
              f"#!/usr/bin/env bash\n{body}\necho \"{line}\"\nexit {exit_code}\n", executable=True)

    def gate(self, name, exit_code=0, out="ran"):
        write(os.path.join(self.mcp, name),
              f"#!/usr/bin/env bash\necho \"$1\" >> '{self.root}/gates.log'\necho \"{out}\"\nexit {exit_code}\n",
              executable=True)

    def run_it(self, *argv, cwd=None):
        return next_step.answer(list(argv), cwd=cwd or self.repo)
