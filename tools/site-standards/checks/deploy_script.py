"""deploy_script.py REPO: does the repo's deploy script deploy by git?

A helper for deploy_script.sh, run with `python3 -I -B`. It prints one line
and exits 0 (done), 1 (not yet) or 2 (could not tell), reading only tracked
files at HEAD and the SITE_SERVER_SSH_ALIAS and SITE_SERVER_DIRECTORY facts.

A script is judged by what it does, never by what it is called or how it
spells its variables: it pushes with git, then pulls with git over ssh in a
directory on a server, and copies nothing with rsync or scp. The name only
decides which script is read first: one named for its site
(deploy_<slug>_to_dh.sh), then deploy_to_dh.sh, then any other deploy*.sh.
"""

import os
import re
import subprocess
import sys

DONE, NOT_YET, UNKNOWN = 0, 1, 2

NAMED_FOR_SITE = re.compile(r"\Adeploy_.+_to_dh\.sh\Z")
GENERIC = "deploy_to_dh.sh"
PREFERRED = "deploy_<slug>_to_dh.sh"

ASSIGNMENT = re.compile(r"\A\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)\Z")
LITERALS = [
    re.compile(r'\A"([^"$`\\]*)"\s*(?:#.*)?\Z'),
    re.compile(r"\A'([^']*)'\s*(?:#.*)?\Z"),
    re.compile(r"\A([A-Za-z0-9._/@:~-]*)\s*(?:#.*)?\Z"),
]
VARIABLE = re.compile(r"\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)")
PUSHES = re.compile(r"(?:\A|[;&|]\s*)\s*git\s+push\b")
COPIES = re.compile(r"""(?:\A\s*|[;&|"'(]\s*)(rsync|scp)\s""")
OVER_SSH = re.compile(r"""\A\s*ssh\s+(?:-[A-Za-z0-9]+\s+)*(["']?)([^\s"']+)\1\s+(.+)\Z""")
PULLS = re.compile(r"\bgit\s+pull\b")
GOES_TO = re.compile(r"""\bcd\s+(["']?)([^\s;&"']+)\1""")


def order(names):
    """The deploy scripts among tracked root files, the one to read first first."""
    def tier(name):
        if NAMED_FOR_SITE.match(name):
            return 0
        return 1 if name == GENERIC else 2
    found = [n for n in names if "/" not in n and n.startswith("deploy") and n.endswith(".sh")]
    return sorted(found, key=lambda name: (tier(name), name))


def code_lines(body):
    return [line for line in body.splitlines() if line.strip() and not line.lstrip().startswith("#")]


def literal(text):
    for shape in LITERALS:
        found = shape.match(text.strip())
        if found:
            return found.group(1)
    return None


def literals(lines):
    """Variables given one plain value and never assigned again."""
    values, spoiled = {}, set()
    for line in lines:
        found = ASSIGNMENT.match(line)
        if not found:
            continue
        name, value = found.group(1), literal(found.group(2))
        if value is None or name in values:
            spoiled.add(name)
        else:
            values[name] = value
    return {name: value for name, value in values.items() if name not in spoiled}


def expand(line, values):
    def swap(found):
        name = found.group(1) or found.group(2)
        return values.get(name, found.group(0))
    return VARIABLE.sub(swap, line)


def judge(name, body, alias="", directory=""):
    """(exit code, line) for one deploy script's text."""
    lines = code_lines(body)
    values = literals(lines)
    lines = [expand(line, values) for line in lines if not ASSIGNMENT.match(line)]

    if not any(PUSHES.search(line) for line in lines):
        return NOT_YET, f"{name} does not git push"
    for line in lines:
        copies = COPIES.search(line)
        if copies:
            return NOT_YET, f"{name} copies files with {copies.group(1)}; a deploy is git push, then git pull over ssh"
    if "rsync -a ./" in body:
        return NOT_YET, f"{name} still tells the reader to install a site with rsync; the first install is by git too"

    pulls = [found for found in map(OVER_SSH.match, lines) if found and PULLS.search(found.group(3))]
    if not pulls:
        return NOT_YET, f"{name} does not git pull over ssh on the server"
    host, remote = pulls[0].group(2), pulls[0].group(3)
    if host == "example":
        return NOT_YET, f"{name} still deploys to \"example\"; fill in the site's ssh alias and directory"
    goes_to = GOES_TO.search(remote)
    if not goes_to:
        return NOT_YET, f"{name} pulls on {host} without a cd to the site's directory"
    path = goes_to.group(2).rstrip("/")
    path = path[2:] if path.startswith("~/") else path
    if "$" in host or "$" in path:
        return UNKNOWN, f"could not read from {name} which server and directory it deploys to"

    hint = "" if NAMED_FOR_SITE.match(name) else f" ({PREFERRED} is the preferred name)"
    if not alias or not directory:
        return DONE, f"{name} pushes, then pulls by git on {host}:{path} (not compared with a server){hint}"
    if host != alias:
        return NOT_YET, f"{name} deploys to {host}, but the site's server is {alias}"
    directory = directory.rstrip("/")
    if directory != path and not directory.startswith(path + "/"):
        return NOT_YET, f"{name} pulls in {path}, but the site's server directory is {directory}"
    return DONE, f"{name} pushes, then pulls by git on {host}:{path}, the site's server{hint}"


def choose(scripts, alias="", directory=""):
    """(exit code, line) for a repo: scripts is [(name, mode, body or None)],
    already in reading order. The first script that deploys by git answers;
    when none does, the first one read says what is missing."""
    if not scripts:
        return NOT_YET, f"no tracked deploy script; add {PREFERRED}: git push, then git pull over ssh"
    verdicts = []
    for name, mode, body in scripts:
        if body is None:
            verdicts.append((UNKNOWN, f"could not read {name} at HEAD"))
        elif mode != "100755":
            verdicts.append((NOT_YET, f"{name} is tracked but not executable: git update-index --chmod=+x {name}"))
        else:
            verdicts.append(judge(name, body, alias, directory))
    for verdict in verdicts:
        if verdict[0] == DONE:
            return verdict
    return verdicts[0]


def git(repo, *args):
    done = subprocess.run(["git", "-C", repo, *args], capture_output=True, text=True, check=False)
    return done.stdout if done.returncode == 0 else None


def tracked(repo):
    """{name: mode} for the files tracked at the repo's root."""
    listing = git(repo, "ls-files", "-s", "--", "deploy*.sh")
    modes = {}
    for line in (listing or "").splitlines():
        meta, _, name = line.partition("\t")
        modes[name] = meta.split(" ")[0]
    return modes


def main(argv):
    if len(argv) != 2:
        print("usage: deploy_script.py REPO")
        return UNKNOWN
    repo = argv[1]
    modes = tracked(repo)
    scripts = [(name, modes[name], git(repo, "show", f"HEAD:{name}")) for name in order(modes)]
    code, line = choose(scripts, os.environ.get("SITE_SERVER_SSH_ALIAS", ""),
                        os.environ.get("SITE_SERVER_DIRECTORY", ""))
    print(line)
    return code


if __name__ == "__main__":
    sys.exit(main(sys.argv))
