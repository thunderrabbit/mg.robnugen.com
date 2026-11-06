"""Scan each site's checkout and push the results to mg.

Run through scan.sh, which starts this with `python3 -I`. Standard library
only. What it does, and what it deliberately does not:

- Step files are read from `master` of this repo with git, never from the
  working tree, so a step on an open branch reaches no one.
- A site is checked only when its checkout is on its trunk branch with nothing
  uncommitted. Otherwise it is skipped, with the reason, and keeps the results
  it had.
- A step's check is run only if checks/<check>.sh exists. A step whose check
  has no script here is either one mg answers itself or an idea with no check
  yet, and nothing is reported for it.
- The check scripts in this checkout must be the ones on that same ref, or
  nothing is scanned.
- Nothing in a site's repo is written, committed or deployed.

The document it sends is described in tools/import_site_standards.php.
"""

import datetime
import json
import os
import re
import shlex
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
STEPS_DIR = "tools/site-standards/steps/"
CHECK_NAME = re.compile(r"\A[a-z0-9][a-z0-9_]*\Z")
OUTCOMES = {0: "pass", 1: "fail", 2: "unknown"}
CHECK_TIMEOUT = 1800


def say(message):
    print(message, file=sys.stderr, flush=True)


def stop(message):
    say(message)
    sys.exit(2)


def git(repo, *args):
    """(exit code, stdout) of a read-only git command in a repo."""
    # --no-optional-locks: even `git status` must not refresh a site's index.
    done = subprocess.run(["git", "--no-optional-locks", "-C", repo, *args], capture_output=True, text=True)
    return done.returncode, done.stdout


def step_files(mg_repo, ref):
    """Every file under the steps directory as it stands on `ref`, and that commit."""
    code, commit = git(mg_repo, "rev-parse", "--verify", ref + "^{commit}")
    if code != 0:
        stop(f"This repo has no {ref} to read the step files from.")
    code, listing = git(mg_repo, "ls-tree", "-r", "--name-only", ref, "--", STEPS_DIR)
    if code != 0:
        stop(f"Could not list the step files on {ref}.")
    files = {}
    for path in listing.splitlines():
        code, content = git(mg_repo, "show", f"{ref}:{path}")
        if code != 0:
            stop(f"Could not read {path} on {ref}.")
        files[path[len(STEPS_DIR):]] = content
    if not files:
        stop(f"There are no step files on {ref}. Nothing was scanned or sent.")
    return files, commit.strip()


def checks_match(mg_repo, ref):
    """Whether the check scripts in this checkout are the ones on `ref`.

    Step files are read from `ref`, but a check is run from the working tree.
    A check that has not reached `ref` must not tick a step on anyone's site.
    """
    paths = ["tools/site-standards/checks", "tools/site-standards/lib.sh",
             "tools/site-standards/scan.py", "tools/site-standards/scan.sh"]
    changed = subprocess.run(["git", "-C", mg_repo, "diff", "--quiet", ref, "--", *paths]).returncode != 0
    untracked = git(mg_repo, "ls-files", "--others", "--", *paths)[1].strip() != ""
    return not changed and not untracked


def frontmatter(content):
    """The flat key: value pairs at the top of a step file.

    Only `check` and `param` are used here. The importer on mg is the judge
    of whether a step file is well formed; this reads just enough to know
    which script to run.
    """
    lines = content.replace("\r\n", "\n").split("\n")
    fields = {}
    if not lines or lines[0].rstrip() != "---":
        return fields
    for line in lines[1:]:
        line = line.rstrip()
        if line == "---":
            break
        found = re.match(r"\A([a-z_]+):\s*(.*)\Z", line)
        if found:
            value = found.group(2)
            if len(value) >= 2 and value[0] == '"' and value[-1] == '"':
                value = value[1:-1]
            fields[found.group(1)] = value
    return fields


def checks_for(standard, files):
    """(step name, check, param) for each step file of a standard."""
    wanted = []
    for path in sorted(files):
        parts = path.split("/")
        if len(parts) != 3 or parts[0] != standard or not parts[2].endswith(".md"):
            continue
        name = parts[2][:-3]
        if name.startswith("_"):
            continue
        fields = frontmatter(files[path])
        wanted.append((name, fields.get("check", ""), fields.get("param", "")))
    return wanted


def scan_site(site, files, work_root, env):
    """One site's entry in the document: its results, or why it was skipped."""
    shortcode = site["shortcode"]

    def skipped(reason, branch=None):
        say(f"  {shortcode}: skipped, {reason}")
        return {"shortcode": shortcode, "branch": branch, "commit": None,
                "skipped_reason": reason, "results": []}

    checkout = site.get("checkout_path")
    repo = os.path.join(work_root, checkout) if checkout else None
    if repo is None or not os.path.isdir(repo) or git(repo, "rev-parse", "--git-dir")[0] != 0:
        return skipped("no local checkout")
    code, branch = git(repo, "symbolic-ref", "--short", "-q", "HEAD")
    branch = branch.strip()
    if code != 0 or branch == "":
        return skipped("not on a branch")
    if branch != site["trunk_branch"]:
        return skipped(f"on branch {branch}"[:255], branch)
    code, changes = git(repo, "status", "--porcelain")
    if code != 0 or changes.strip() != "":
        return skipped("uncommitted changes", branch)
    commit = git(repo, "rev-parse", "HEAD")[1].strip()

    results = []
    for name, check, param in checks_for(site["standard"], files):
        script = os.path.join(HERE, "checks", check + ".sh")
        if not CHECK_NAME.match(check) or not os.path.isfile(script):
            continue
        try:
            done = subprocess.run([script, repo, param], capture_output=True, text=True,
                                  env=env, timeout=CHECK_TIMEOUT)
            outcome = OUTCOMES.get(done.returncode, "unknown")
            lines = done.stdout.strip().splitlines()
            detail = lines[0] if lines else ""
            if done.returncode not in OUTCOMES:
                detail = f"the check stopped with exit {done.returncode}"
        except subprocess.TimeoutExpired:
            outcome, detail = "unknown", "the check took too long"
        detail = "".join(ch for ch in detail if ch.isprintable())[:255]
        say(f"  {shortcode}: {name}: {outcome}" + (f" ({detail})" if detail else ""))
        results.append({"step": name, "outcome": outcome, "detail": detail})

    return {"shortcode": shortcode, "branch": branch, "commit": commit,
            "skipped_reason": None, "results": results}


def main(argv):
    dry_run = "--dry-run" in argv
    only = [a for a in argv if a != "--dry-run"]
    for arg in only:
        if arg.startswith("-"):
            stop(f"Unknown option: {arg}. Usage: scan.sh [--dry-run] [shortcode ...]")

    mg_repo = git(HERE, "rev-parse", "--show-toplevel")[1].strip()
    if mg_repo == "":
        stop("scan.sh must live inside the mg checkout.")
    ref = os.environ.get("STEPS_REF", "master")
    if ref != "master":
        say(f"Reading steps from {ref}, not master.")
    files, steps_commit = step_files(mg_repo, ref)
    if not checks_match(mg_repo, ref):
        stop(f"The check scripts in this checkout differ from {ref}, so a scan could tick a step "
             f"with a check nobody has approved. Nothing was scanned or sent.")

    site_command = os.environ.get("SCAN_SITE_CMD", shlex.quote(os.path.join(HERE, "site.sh")))
    listed = subprocess.run(["bash", "-c", site_command + " sites"], capture_output=True, text=True)
    if listed.returncode != 0:
        stop("Could not get the site list from mg: " + listed.stderr.strip())
    sites = json.loads(listed.stdout)
    known = {site["shortcode"] for site in sites}
    for shortcode in only:
        if shortcode not in known:
            stop(f"No site has the shortcode {shortcode}.")
    if only:
        sites = [site for site in sites if site["shortcode"] in only]

    work_root = os.environ.get("WORK_ROOT", os.path.expanduser("~/work"))
    with tempfile.TemporaryDirectory() as scan_tmp:
        env = dict(os.environ, SCAN_TMP=scan_tmp)
        say(f"Scanning {len(sites)} site(s); {len(files)} step files from {ref} ({steps_commit[:7]}).")
        scanned = [scan_site(site, files, work_root, env) for site in sites]

    document = json.dumps({
        "scanned_at_utc": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
        "steps_commit": steps_commit,
        "files": files,
        "sites": scanned,
    })
    import_command = os.environ.get(
        "SCAN_IMPORT_CMD",
        "ssh mg " + shlex.quote("/usr/local/php85/bin/php ~/mg.robnugen.com/tools/import_site_standards.php"),
    )
    if dry_run:
        import_command += " --dry-run"
    return subprocess.run(["bash", "-c", import_command], input=document, text=True).returncode


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
