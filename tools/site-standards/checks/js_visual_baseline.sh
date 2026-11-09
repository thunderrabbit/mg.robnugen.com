#!/usr/bin/env bash
# memo: commit
# js_visual_baseline REPO: a visual differ is in place. JS_LIBRARIES.md names
# its capture ("Visual capture: <command>") and its ledger of accepted
# differences ("Visual ledger: <path>"), and the ledger is a tracked JSON
# file whose every entry has reason, who and date. A site that runs no
# JavaScript passes. Names in ../NAMES.md.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

out="$(python3 -I -B - "$HERE" "$REPO" <<'PY'
import json, os, re, subprocess, sys
sys.path.insert(0, sys.argv[1])
import js_scripts as j

CAPTURE = re.compile(r"^Visual capture:[ \t]*(\S.*?)[ \t]*$", re.M)
LEDGER = re.compile(r"^Visual ledger:[ \t]*(\S+)[ \t]*$", re.M)


def check(repo):
    found = j.read(repo)
    own = sum(1 for p in j.tracked_files(repo) if p.endswith((".js", ".mjs")))
    if not (found["scripts"] or found["importmap"] or found["inline"] or found["named"] or own):
        j.verdict(0, "the site runs no JavaScript")
    doc = j.contents(repo, "JS_LIBRARIES.md")
    if doc is None:
        j.verdict(1, "no JS_LIBRARIES.md to name the visual differ in")
    captures = CAPTURE.findall(doc)
    if not captures:
        j.verdict(1, 'JS_LIBRARIES.md has no "Visual capture: <command>" line')
    ledgers = LEDGER.findall(doc)
    if not ledgers:
        j.verdict(1, 'JS_LIBRARIES.md has no "Visual ledger: <path>" line')
    path = ledgers[-1]
    if path.startswith("/") or ".." in path.split("/"):
        j.verdict(1, f"the visual ledger {path} is not a path inside the repo")
    tracked = subprocess.run(["git", "--no-optional-locks", "-C", repo, "ls-files", "--", path],
                             capture_output=True, text=True).stdout.strip()
    if not tracked:
        j.verdict(1, f"the visual ledger {path} is not a tracked file")
    try:
        entries = json.loads(j.contents(repo, path) or "")
    except ValueError:
        j.verdict(1, f"the visual ledger {path} is not valid JSON")
    if isinstance(entries, dict):
        # An object may carry a _README beside its one list of entries.
        lists = [v for k, v in entries.items() if isinstance(v, list) and not str(k).startswith("_")]
        entries = lists[0] if len(lists) == 1 else None
    if not isinstance(entries, list):
        j.verdict(1, f"the visual ledger {path} is not a list of accepted differences (or an object holding one)")
    for n, entry in enumerate(entries, 1):
        if not isinstance(entry, dict) or any(not str(entry.get(k, "")).strip() for k in ("reason", "who", "date")):
            j.verdict(1, f"entry {n} of the visual ledger {path} lacks reason, who or date")
    j.verdict(0, f"visual differ in place: {captures[-1].split()[0]}, {len(entries)} accepted difference(s) in {path}")


j.run(check)
PY
)"
case $? in
  0) pass "$out" ;;
  1) not_yet "$out" ;;
  *) unknown "${out:-the check could not run}" ;;
esac
