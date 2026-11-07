#!/usr/bin/env bash
# js_build_reproducible REPO: a site without package.json passes. One with it
# has a committed lockfile and an exact Node version (.node-version, .nvmrc or
# engines.node), and, if it has dependencies the site runs, a `build` script.
# package.json for development tools alone needs no build script.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
out="$(python3 -I -B - "$HERE" "$REPO" <<'PY'
import json, re, subprocess, sys
sys.path.insert(0, sys.argv[1])
import js_scripts as j

LOCKFILES = ("package-lock.json", "yarn.lock", "pnpm-lock.yaml")
EXACT = re.compile(r"\Av?\d+\.\d+\.\d+\Z")


def check(repo):
    done = subprocess.run(["git", "--no-optional-locks", "-C", repo, "ls-files", "-z"],
                          capture_output=True, text=True)
    if done.returncode != 0:
        j.verdict(2, "could not list the checkout's files with git")
    tracked = set(done.stdout.split("\0"))
    if "package.json" not in tracked:
        j.verdict(0, "no package.json, so there is nothing to build")
    package = json.loads(j.contents(repo, "package.json") or "{}")
    lock = next((name for name in LOCKFILES if name in tracked), None)
    if lock is None:
        j.verdict(1, "package.json has no committed lockfile (package-lock.json, yarn.lock or pnpm-lock.yaml)")
    node = None
    for name in (".node-version", ".nvmrc"):
        if name in tracked:
            node = ((j.contents(repo, name) or "").strip(), name)
            break
    if node is None and isinstance(package.get("engines"), dict) and "node" in package["engines"]:
        node = (str(package["engines"]["node"]).strip(), "engines.node")
    if node is None:
        j.verdict(1, "no Node version named in .node-version, .nvmrc or engines.node")
    if not EXACT.match(node[0]):
        j.verdict(1, f"{node[1]} says \"{node[0]}\"; name an exact x.y.z version")
    scripts = package.get("scripts") if isinstance(package.get("scripts"), dict) else {}
    if j.dependencies(repo) and "build" not in scripts:
        j.verdict(1, "package.json has dependencies the site runs but no build script")
    what = "builds with npm run build" if "build" in scripts else "has development tools only"
    j.verdict(0, f"{lock} committed, Node {node[0].lstrip('v')} named, {what}")


j.run(check)
PY
)"
case $? in
  0) pass "$out" ;;
  1) not_yet "$out" ;;
  *) unknown "${out:-the check could not run}" ;;
esac
