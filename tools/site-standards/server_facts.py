#!/usr/bin/env python3
"""What a site's server says about a checkout, for the scanner and its checks.

    server_facts.py head   ALIAS DIRECTORY    the commit HEAD is at (one line)
    server_facts.py branch ALIAS DIRECTORY    the branch, or nothing on a detached HEAD
    server_facts.py reflog ALIAS DIRECTORY    "<commit> HEAD@{<unixtime>}" lines, newest first
    server_facts.py config ALIAS DIRECTORY LEGACY_FILE
                                              which config files are there: three lines,
                                              "site_config: yes|no", "legacy: yes|no", "kept: yes|no"
    server_facts.py where  ALIAS              whether the user's ~/.bash_aliases defines the
                                              alias `where`: one line, "where: yes|no"

An EC2 server (an absolute DIRECTORY) is asked first through the ssh host
<ALIAS>_facts, a forced-command key that runs deets.sh and prints one JSON
document. Any other server, or an EC2 one with no such host, is asked with git
over ALIAS, read-only. Exit 1, with the reason on stderr, when nothing answers.
SCAN_SSH replaces the ssh command, as it does for the scanner.

`config` is for the steps that move a site's configuration to site_config.php.
It asks with `test -f` only and never reads a file: site_config.php and
LEGACY_FILE (the old config file, a path inside the checkout) are looked for
in the checkout's top directory, and the copy of the old file that a
conversion keeps is looked for in the server user's home, as
<checkout's name>.Config.php.old or a hand-made Config.php.old. DreamHost
servers only.

`where` is for the step that gives every server user a `where` alias. It asks
with `grep -q` for a line that opens `alias where=` and never prints the file.
Whatever the user's own dotfiles print on the way in is ignored.
"""
import json
import os
import re
import shlex
import subprocess
import sys

TIMEOUT = 60
SHA = re.compile(r"\A[0-9a-f]{40}\Z")
BRANCH = re.compile(r"\A[A-Za-z0-9._/-]{1,100}\Z")
ALIAS = re.compile(r"\A[A-Za-z0-9][A-Za-z0-9._-]*\Z")
DIRECTORY = re.compile(r"\A/?[A-Za-z0-9_][A-Za-z0-9._-]*(?:/[A-Za-z0-9._-]+)*\Z")
LEGACY_FILE = re.compile(r"\A[A-Za-z0-9_][A-Za-z0-9._-]*(?:/[A-Za-z0-9._-]+)*\.php\Z")
SITE_CONFIG = "site_config.php"
KEPT_SUFFIX = ".Config.php.old"   # after the checkout's name, in the server user's home
KEPT_BY_HAND = "Config.php.old"   # what the first site converted, by hand, left there
WHERE_ANSWER = re.compile(r"^where-alias-answer: (yes|no)$", re.MULTILINE)


class NoAnswer(Exception):
    pass


def _ssh():
    return shlex.split(os.environ.get("SCAN_SSH", "ssh -o BatchMode=yes -o ConnectTimeout=15"))


def _run(host, command):
    try:
        return subprocess.run([*_ssh(), host, command], capture_output=True, text=True,
                              stdin=subprocess.DEVNULL, timeout=TIMEOUT)
    except subprocess.TimeoutExpired:
        raise NoAnswer("its server did not answer in time") from None


def _check(alias, directory):
    if not ALIAS.match(alias):
        raise NoAnswer("its ssh alias is not one the scanner will use")
    if not DIRECTORY.match(directory) or ".." in directory.split("/"):
        raise NoAnswer("its server directory is not one the scanner will use")


def _from_facts_host(alias, directory):
    """The directory's record from <alias>_facts, or None if that host gave no document."""
    asked = _run(alias + "_facts", "scan.invalid=" + directory)
    for line in asked.stdout.splitlines():
        if not line.startswith("{"):
            continue
        try:
            document = json.loads(line)
        except ValueError:
            return None
        for record in document.get("domains", []) if isinstance(document, dict) else []:
            if isinstance(record, dict) and record.get("directory") == directory:
                return record
        return None
    return None


def _git(alias, directory, *args):
    there = "git -c " + shlex.quote("safe.directory=*") + " --no-optional-locks -C " + shlex.quote(directory)
    return _run(alias, there + " " + " ".join(args))


def head_and_branch(alias, directory):
    """(commit, branch or None). Raises NoAnswer."""
    _check(alias, directory)
    if directory.startswith("/"):
        record = _from_facts_host(alias, directory)
        if record is not None:
            commit, branch = record.get("head_commit") or "", record.get("head_branch") or ""
            if not SHA.match(commit):
                raise NoAnswer("could not ask its server which commit it runs: no git checkout there")
            return commit, (branch if BRANCH.match(branch) else None)
    there = "git -c " + shlex.quote("safe.directory=*") + " --no-optional-locks -C " + shlex.quote(directory)
    asked = _run(alias, f"{there} rev-parse HEAD && {{ {there} symbolic-ref --short -q HEAD || true; }}")
    lines = asked.stdout.strip().splitlines()
    if asked.returncode != 0 or not lines or not SHA.match(lines[0].strip()):
        said = (asked.stderr.strip().splitlines() or ["no answer"])[-1]
        raise NoAnswer("could not ask its server which commit it runs: " + said)
    branch = lines[1].strip() if len(lines) > 1 else ""
    return lines[0].strip(), (branch if BRANCH.match(branch) else None)


def reflog(alias, directory):
    """[(commit, unixtime)] newest first. Raises NoAnswer."""
    _check(alias, directory)
    if directory.startswith("/"):
        record = _from_facts_host(alias, directory)
        if record is not None:
            entries = record.get("reflog")
            if not isinstance(entries, list) or not all(
                    isinstance(e, dict) and SHA.match(str(e.get("commit", ""))) and isinstance(e.get("unix"), int)
                    for e in entries):
                raise NoAnswer("its server gave no reflog")
            return [(e["commit"], e["unix"]) for e in entries]
    asked = _git(alias, directory, "reflog", "show", "--date=unix", "--format=" + shlex.quote("%H %gd"), "-200", "HEAD")
    found = []
    for line in asked.stdout.splitlines():
        match = re.fullmatch(r"([0-9a-f]{40}) HEAD@\{(\d{1,12})\}", line.strip())
        if match is None:
            break
        found.append((match.group(1), int(match.group(2))))
    if not found:
        raise NoAnswer("its server has no record of when HEAD last moved")
    return found


def config(alias, directory, legacy_file):
    """{"site_config": bool, "legacy": bool, "kept": bool} for a DreamHost checkout. Raises NoAnswer."""
    _check(alias, directory)
    if directory.startswith("/"):
        raise NoAnswer("its server is not a DreamHost one")
    if not LEGACY_FILE.match(legacy_file) or ".." in legacy_file.split("/"):
        raise NoAnswer("the old config file's path is not one the scanner will use")
    there = "git -c " + shlex.quote("safe.directory=*") + " --no-optional-locks -C " + shlex.quote(directory)
    asked = _run(alias, (
        f"root=$({there} rev-parse --show-toplevel) || exit 3; "
        'has() { if test -f "$1"; then echo yes; else echo no; fi; }; '
        f'has "$root"/{SITE_CONFIG}; has "$root"/{shlex.quote(legacy_file)}; '
        f'if test -f "$HOME/$(basename "$root"){KEPT_SUFFIX}" || test -f "$HOME"/{KEPT_BY_HAND}; '
        "then echo yes; else echo no; fi"))
    words = asked.stdout.split()
    if asked.returncode != 0 or len(words) != 3 or not all(word in ("yes", "no") for word in words):
        said = (asked.stderr.strip().splitlines() or ["no answer"])[-1]
        raise NoAnswer("could not ask its server which config files it has: " + said)
    return dict(zip(("site_config", "legacy", "kept"), (word == "yes" for word in words)))


def where(alias):
    """True when the user's ~/.bash_aliases has an `alias where=` line. Raises NoAnswer."""
    if not ALIAS.match(alias):
        raise NoAnswer("its ssh alias is not one the scanner will use")
    asked = _run(alias, (
        "if grep -q '^[[:space:]]*alias where=' \"$HOME\"/.bash_aliases 2>/dev/null; "
        "then echo 'where-alias-answer: yes'; else echo 'where-alias-answer: no'; fi"))
    answers = WHERE_ANSWER.findall(asked.stdout)
    if asked.returncode != 0 or len(answers) != 1:
        said = (asked.stderr.strip().splitlines() or ["no answer"])[-1]
        raise NoAnswer("could not ask its server about ~/.bash_aliases: " + said)
    return answers[0] == "yes"


def main(argv):
    if len(argv) == 3 and argv[1] == "where":
        try:
            print("where: " + ("yes" if where(argv[2]) else "no"))
        except NoAnswer as why:
            print(why, file=sys.stderr)
            return 1
        return 0
    if len(argv) == 5 and argv[1] == "config":
        try:
            for name, there in config(*argv[2:]).items():
                print(f"{name}: {'yes' if there else 'no'}")
        except NoAnswer as why:
            print(why, file=sys.stderr)
            return 1
        return 0
    if len(argv) != 4 or argv[1] not in ("head", "branch", "reflog"):
        print(__doc__, file=sys.stderr)
        return 2
    what, alias, directory = argv[1:]
    try:
        if what == "reflog":
            for commit, unix in reflog(alias, directory):
                print(f"{commit} HEAD@{{{unix}}}")
        else:
            commit, branch = head_and_branch(alias, directory)
            print(commit if what == "head" else (branch or ""))
    except NoAnswer as why:
        print(why, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
