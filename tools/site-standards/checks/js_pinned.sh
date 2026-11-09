#!/usr/bin/env bash
# memo: commit
# js_pinned REPO: every script loaded from another host names an exact x.y.z
# version, and carries an integrity hash where the browser can check one (a
# <script src> with crossorigin, or an import-map entry for one file). A copy
# served from a vendor/, lib/ or libs/ directory has x.y.z in its path.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
out="$(python3 -I -B - "$HERE" "$REPO" <<'PY'
import sys
sys.path.insert(0, sys.argv[1])
import js_scripts as j

COPY_DIRS = {"vendor", "lib", "libs"}


def check(repo):
    found = j.read(repo)
    if found["problems"]:
        j.verdict(2, found["problems"][0])
    seen = 0
    for s in found["scripts"]:
        if s["dynamic"]:
            continue
        src = s["src"]
        if j.is_external(src):
            seen += 1
            if not j.has_version(src):
                j.verdict(1, f"{src} names no exact version ({j.where(s)})")
            if not s["integrity"]:
                j.verdict(1, f"{src} has no integrity hash ({j.where(s)})")
            if not s["crossorigin"]:
                j.verdict(1, f"{src} has an integrity hash but no crossorigin ({j.where(s)})")
        elif COPY_DIRS.intersection(src.split("?")[0].split("/")[:-1]):
            seen += 1
            if not j.has_version(src):
                j.verdict(1, f"the copy {src} has no version in its path ({j.where(s)})")
    for e in found["importmap"]:
        if not j.is_external(e["url"]):
            continue
        seen += 1
        if not j.has_version(e["url"]):
            j.verdict(1, f"the import map's \"{e['key']}\" names no exact version ({j.where(e)})")
        if not e["prefix"] and not e["integrity"]:
            j.verdict(1, f"the import map has no integrity hash for \"{e['key']}\" ({j.where(e)})")
    for i in found["imports"]:
        if j.is_external(i["spec"]) and not i["dynamic"]:
            seen += 1
            if not j.has_version(i["spec"]):
                j.verdict(1, f"the import {i['spec']} names no exact version ({j.where(i)})")
    if seen == 0:
        j.verdict(0, "the site loads no third-party scripts")
    dynamic = sum(1 for s in found["scripts"] if s["dynamic"])
    extra = f"; {dynamic} written by PHP, not checked" if dynamic else ""
    j.verdict(0, f"all {seen} third-party scripts are pinned{extra}")


j.run(check)
PY
)"
case $? in
  0) pass "$out" ;;
  1) not_yet "$out" ;;
  *) unknown "${out:-the check could not run}" ;;
esac
