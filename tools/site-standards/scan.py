"""Scan each site's checkout and push the results to mg.

Run through scan.sh, which starts this with `python3 -I`. Standard library
only. What it does, and what it deliberately does not:

- Rob approves a change by deploying it. So the step files are read, with git,
  from the commit the mg server is running (asked over ssh), never from the
  working tree: a step that is not deployed on mg reaches no one.
- A site is checked only when its checkout is clean and at exactly the commit
  its server runs, asked over ssh (the server's branch name is reported, never
  trusted: servers keep old names and server-only branches). Otherwise it is
  skipped, with the reason, and keeps the results it had.
- One exception: a site preparing a long upgrade on its own line names that
  branch on mg (site.sh site-set --upgrade=). Its checkout is then checked
  clean at that branch's tip instead, the results describe that line, and
  the page says so beside what the server runs. The importer refuses an
  off-server scan on any other branch.
- A step file is <number>_<name>.md. The number is its position on the page
  and the name is the step, which is what a result is reported under.
- A step's check is run only if checks/<check>.sh exists. A step whose check
  has no script here is either one mg answers itself or an idea with no check
  yet, and nothing is reported for it.
- The check scripts in this checkout must be the ones in that deployed commit,
  or nothing is scanned.
- A check that opted in with `# memo: commit` is not rerun while its commit,
  script, inputs and tools are unchanged (memo.py); `--fresh` reruns it anyway.
- A step whose param is `target` is checked against the PHP the site is
  moving to: its `php_target` in standards-mcp's repos.yaml (the same
  registry the runner scripts pick their image from). A site the registry
  gives no target gets "could not tell" for those steps. The target is sent
  to mg with the site, so mg's own checks use the same one.
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
sys.path.insert(0, HERE)  # python3 -I leaves this directory off sys.path
import memo  # noqa: E402
import server_facts  # noqa: E402
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


def deployed_commit():
    """The commit the mg server is running, asked over ssh."""
    command = os.environ.get("SCAN_DEPLOYED_CMD",
                             "ssh mg " + shlex.quote("git -C ~/mg.robnugen.com rev-parse HEAD"))
    asked = subprocess.run(["bash", "-c", command], capture_output=True, text=True)
    commit = asked.stdout.strip()
    if asked.returncode != 0 or not re.fullmatch(r"[0-9a-f]{40}", commit):
        stop("Could not ask the mg server which commit it is running: " + (asked.stderr.strip() or commit))
    return commit


def ask_server(server):
    """(commit, branch, None) the site's server runs, or (None, None, why not).

    The directory is relative to the user's home, where ssh starts, on
    DreamHost, and absolute on EC2. branch is None on a detached HEAD.
    server_facts decides how to ask (an EC2 server's <alias>_facts host first).
    """
    try:
        commit, branch = server_facts.head_and_branch(server.get("ssh_alias", ""), server.get("directory", ""))
    except server_facts.NoAnswer as why:
        return None, None, str(why)
    return commit, branch, None


def step_files(mg_repo, ref):
    """Every file under the steps directory as it stands on `ref`, and that commit."""
    code, commit = git(mg_repo, "rev-parse", "--verify", ref + "^{commit}")
    if code != 0:
        stop(f"This checkout does not have {ref}, so it cannot read the step files the server "
             f"is running. Fetch first.")
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
    A check that is not deployed on mg must not tick a step on anyone's site.
    """
    paths = ["tools/site-standards/checks", "tools/site-standards/lib.sh",
             "tools/site-standards/scan.py", "tools/site-standards/scan.sh",
             "tools/site-standards/memo.py", "tools/site-standards/server_facts.py"]
    changed = subprocess.run(["git", "-C", mg_repo, "diff", "--quiet", ref, "--", *paths]).returncode != 0
    untracked = git(mg_repo, "ls-files", "--others", "--", *paths)[1].strip() != ""
    return not changed and not untracked


def php_targets(standards_mcp):
    """{absolute repo path: php_target} from standards-mcp's repo registry.

    The registry is the one runner-image.sh reads: ~/.config/standards-mcp/
    repos.yaml when present, else the committed repos.yaml in standards-mcp.
    Read the way runner-image.sh reads it, as flat lines: each alias block
    names its path before its php_target. A missing registry gives nothing.
    """
    registry = os.path.expanduser("~/.config/standards-mcp/repos.yaml")
    if not os.path.isfile(registry):
        registry = os.path.join(standards_mcp, "repos.yaml")
    try:
        with open(registry, encoding="utf-8") as handle:
            lines = handle.read().splitlines()
    except OSError:
        return {}
    targets, path = {}, None
    for line in lines:
        if re.match(r"\A[A-Za-z0-9_-]+:\s*\Z", line):
            path = None
            continue
        found = re.match(r"\A\s+(path|php_target):\s*(\S+)", line)
        if not found:
            continue
        value = found.group(2).strip("\"'")
        if found.group(1) == "path":
            path = os.path.normpath(os.path.expanduser(value))
        elif path is not None and re.fullmatch(r"\d+\.\d+", value):
            targets[path] = value
    return targets


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


def step_name(stem):
    """The step a file named <number>_<name>.md is: the name after the number.

    The number is the step's position on the page (038.9996), which mg reads
    from the filename itself; results are matched on the name. Anything not of
    that form comes back unchanged, for mg's importer to refuse.
    """
    found = re.match(r"\A\d+(?:\.\d{1,4})?_([a-z][a-z0-9_]{0,63})\Z", stem)
    return found.group(1) if found else stem


def checks_for(standard, files):
    """(step name, check, param) for each step file of a standard."""
    wanted = []
    for path in sorted(files):
        parts = path.split("/")
        if len(parts) != 3 or parts[0] != standard or not parts[2].endswith(".md"):
            continue
        stem = parts[2][:-3]
        if stem.startswith("_"):
            continue
        name = step_name(stem)
        fields = frontmatter(files[path])
        wanted.append((name, fields.get("check", ""), fields.get("param", "")))
    return wanted


def check_environment(site, env, server_commit=None, server_branch=None, php_target=None):
    """The environment a site's checks run in: the scan's, plus what mg knows
    and what the server just answered.

    Every variable is set, empty when there is nothing to say, so a check
    never has to tell "unset" from "none". A check reads these; the step's one
    `param` stays the target PHP (a `target` param is already replaced by the
    site's php_target, which is also SITE_PHP_TARGET).
    """
    test = site.get("test") or {}
    server = site.get("server") or {}
    facts = {
        "SITE_SHORTCODE": site["shortcode"],
        "SITE_PHP_CURRENT": site.get("php_current"),
        "SITE_PHP_TARGET": php_target,
        "SITE_TEST_DOMAIN": test.get("domain"),
        "SITE_TEST_PHP": test.get("php_version"),
        "SITE_TEST_SSH_ALIAS": test.get("ssh_alias"),
        "SITE_TEST_DIRECTORY": test.get("directory"),
        "SITE_SERVER_SSH_ALIAS": server.get("ssh_alias"),
        "SITE_SERVER_DIRECTORY": server.get("directory"),
        "SITE_SERVER_COMMIT": server_commit,
        "SITE_SERVER_BRANCH": server_branch,
        "SITE_UPGRADE_BRANCH": site.get("upgrade_branch"),
    }
    return dict(env, **{name: value or "" for name, value in facts.items()})


def run_check(script, repo, param, site_env):
    """(outcome, detail) of one check script run against a repo; detail is one printable line."""
    try:
        done = subprocess.run([script, repo, param], capture_output=True, text=True,
                              env=site_env, timeout=CHECK_TIMEOUT)
        outcome = OUTCOMES.get(done.returncode, "unknown")
        lines = done.stdout.strip().splitlines()
        detail = lines[0] if lines else ""
        if done.returncode not in OUTCOMES:
            detail = f"the check stopped with exit {done.returncode}"
    except subprocess.TimeoutExpired:
        outcome, detail = "unknown", "the check took too long"
    return outcome, "".join(ch for ch in detail if ch.isprintable())[:255]


def scan_site(site, files, work_root, env, tool_id=None, fresh=False, targets=None):
    """One site's entry in the document: its results, or why it was skipped.

    With a `tool_id`, a check that opted in (memo.is_memoizable) is not rerun
    while its key is unchanged: the answer the memo holds for the same commit,
    check, inputs and tools is reused, and said to be remembered on stderr.
    `fresh` ignores the memo's answers but still writes the new ones.
    `targets` is php_targets(): a `target` param becomes the site's own.
    """
    shortcode = site["shortcode"]

    server_commit, server_branch = None, None
    checkout = site.get("checkout_path")
    repo = os.path.join(work_root, checkout) if checkout else None
    php_target = (targets or {}).get(os.path.normpath(os.path.abspath(repo))) if repo else None

    def skipped(reason, branch=None):
        reason = "".join(ch for ch in reason if ch.isprintable())[:255]
        say(f"  {shortcode}: skipped, {reason}")
        return {"shortcode": shortcode, "branch": branch, "commit": None,
                "server_branch": server_branch, "server_commit": server_commit,
                "php_target": php_target, "skipped_reason": reason, "results": []}

    if repo is None or not os.path.isdir(repo) or git(repo, "rev-parse", "--git-dir")[0] != 0:
        return skipped("no local checkout")
    if site.get("server") is None:
        return skipped(site.get("server_problem") or "there is no server to ask")
    server_commit, server_branch, problem = ask_server(site["server"])
    if problem is not None:
        return skipped(problem)
    on = f"{server_commit[:7]}" + (f" ({server_branch})" if server_branch else "")

    code, branch = git(repo, "symbolic-ref", "--short", "-q", "HEAD")
    branch = branch.strip() if code == 0 and branch.strip() != "" else None
    commit = git(repo, "rev-parse", "HEAD")[1].strip()
    line = site.get("upgrade_branch")
    if line and (not server_facts.BRANCH.match(line) or ".." in line):
        return skipped(f"its upgrade branch on mg, {line!r}, is not a branch name", branch)
    if commit != server_commit:
        if line and branch == line:
            say(f"  {shortcode}: checking {line}, the line it is preparing; its server runs {on}")
        elif git(repo, "cat-file", "-e", server_commit + "^{commit}")[0] != 0:
            return skipped(f"its server runs {on}, which this checkout does not have", branch)
        else:
            hint = f", not on {line}" if line else ""
            return skipped(f"its server runs {on}; the checkout is at {commit[:7]}{hint}", branch)
    code, changes = git(repo, "status", "--porcelain")
    if code != 0 or changes.strip() != "":
        return skipped("uncommitted changes", branch)

    results = []
    site_env = check_environment(site, env, server_commit, server_branch, php_target)
    cache = None
    if tool_id is not None:
        try:
            cache = memo.Memo(memo.memo_path(shortcode))
        except ValueError:
            pass
    checks_dir = os.path.join(HERE, "checks")
    helpers = [os.path.join(checks_dir, name) for name in sorted(os.listdir(checks_dir))
               if not name.endswith(".sh") and os.path.isfile(os.path.join(checks_dir, name))]
    facts = {name: value for name, value in site_env.items() if name.startswith("SITE_")}
    for name, check, param in checks_for(site["standard"], files):
        script = os.path.join(checks_dir, check + ".sh")
        if not CHECK_NAME.match(check) or not os.path.isfile(script):
            continue
        if param == "target":
            if php_target is None:
                detail = "standards-mcp's repos.yaml gives this repo no php_target"
                say(f"  {shortcode}: {name}: unknown ({detail})")
                results.append({"step": name, "outcome": "unknown", "detail": detail})
                continue
            param = php_target
        key, note = None, ""
        if cache is not None and memo.is_memoizable(script):
            key = memo.check_key(commit=commit, script=script, lib=os.path.join(HERE, "lib.sh"),
                                 helpers=helpers, param=param, facts=facts, tool_id=tool_id)
        remembered = cache.lookup(key) if key is not None and not fresh else None
        if remembered is not None:
            outcome, detail = remembered["outcome"], remembered["detail"]
            note = f" (remembered from {memo.remembered_label(remembered['at_utc'])})"
        else:
            outcome, detail = run_check(script, repo, param, site_env)
            if key is not None:
                cache.record(key, commit, outcome, detail)
        say(f"  {shortcode}: {name}: {outcome}" + (f" ({detail})" if detail else "") + note)
        results.append({"step": name, "outcome": outcome, "detail": detail})
    if cache is not None:
        cache.save(commit)

    return {"shortcode": shortcode, "branch": branch, "commit": commit,
            "server_branch": server_branch, "server_commit": server_commit,
            "php_target": php_target, "skipped_reason": None, "results": results}


def parse_arguments(argv):
    """(dry_run, fresh, shortcodes) from the command line; stops with usage on an unknown option."""
    flags = {"--dry-run", "--fresh"}
    only = [a for a in argv if a not in flags]
    for arg in only:
        if arg.startswith("-"):
            stop(f"Unknown option: {arg}. Usage: scan.sh [--dry-run] [--fresh] [shortcode ...]")
    return "--dry-run" in argv, "--fresh" in argv, only


def main(argv):
    dry_run, fresh, only = parse_arguments(argv)

    mg_repo = git(HERE, "rev-parse", "--show-toplevel")[1].strip()
    if mg_repo == "":
        stop("scan.sh must live inside the mg checkout.")
    ref = os.environ.get("STEPS_REF")
    if ref:
        say(f"Reading steps from {ref}, not from the commit deployed on mg.")
    else:
        ref = deployed_commit()
    files, steps_commit = step_files(mg_repo, ref)
    if not checks_match(mg_repo, ref):
        stop(f"The check scripts in this checkout differ from {ref[:12]}, so a scan could tick a "
             f"step with a check that is not deployed. Nothing was scanned or sent.")

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
        say(f"Scanning {len(sites)} site(s); {len(files)} step files from {steps_commit[:7]}"
            + ("" if os.environ.get("STEPS_REF") else " (deployed on mg)") + ".")
        standards_mcp = os.environ.get("STANDARDS_MCP") or os.path.expanduser("~/work/rob/standards-mcp")
        tool_id = memo.tool_identity(standards_mcp) if sites else None
        targets = php_targets(standards_mcp)
        scanned = [scan_site(site, files, work_root, env, tool_id, fresh, targets) for site in sites]

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
