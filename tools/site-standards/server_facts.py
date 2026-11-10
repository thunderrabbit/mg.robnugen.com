#!/usr/bin/env python3
"""What a site's server says about a checkout, for the scanner and its checks.

    server_facts.py head   ALIAS DIRECTORY    the commit HEAD is at (one line)
    server_facts.py branch ALIAS DIRECTORY    the branch, or nothing on a detached HEAD
    server_facts.py reflog ALIAS DIRECTORY    "<commit> HEAD@{<unixtime>}" lines, newest first

An EC2 server (an absolute DIRECTORY) is asked first through the ssh host
<ALIAS>_facts, a forced-command key that runs deets.sh and prints one JSON
document. Any other server, or an EC2 one with no such host, is asked with git
over ALIAS, read-only. Exit 1, with the reason on stderr, when nothing answers.
SCAN_SSH replaces the ssh command, as it does for the scanner.
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


def main(argv):
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
