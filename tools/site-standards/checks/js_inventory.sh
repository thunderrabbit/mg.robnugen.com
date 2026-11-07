#!/usr/bin/env bash
# js_inventory REPO: JS_LIBRARIES.md at the repo root names every script the
# site loads: each <script src>, import-map target and non-relative import,
# exactly as the code spells it, and each script PHP names (by the tracked
# file's path when the name resolves to one, else as PHP spells it). A site
# that loads none passes without it.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
out="$(python3 -I -B - "$HERE" "$REPO" <<'PY'
import os, sys
sys.path.insert(0, sys.argv[1])
import js_scripts as j


def check(repo):
    found = j.read(repo)
    if found["problems"]:
        j.verdict(2, found["problems"][0])
    named = [(s["src"], s) for s in found["scripts"] if not s["dynamic"]]
    named += [(e["url"], e) for e in found["importmap"]]
    keys = {e["key"]: e["url"] for e in found["importmap"]}
    bare = [i for i in found["imports"] if not i["dynamic"]]
    php_named = {}
    for n in found["named"]:
        php_named.setdefault(n["path"] or n["tail"], n)
    unresolved = sum(1 for n in found["named"] if n["path"] is None and not j.is_external(n["tail"]))
    if not named and not bare and not php_named:
        j.verdict(0, "the site loads no scripts")
    path = os.path.join(repo, "JS_LIBRARIES.md")
    total = len(named) + len(bare) + len(php_named)
    if not os.path.isfile(path):
        j.verdict(1, f"no JS_LIBRARIES.md, and the site loads {total} scripts")
    with open(path, encoding="utf-8", errors="replace") as f:
        doc = f.read()
    for text, item in named:
        if text not in doc:
            j.verdict(1, f"JS_LIBRARIES.md does not name {text} ({j.where(item)})")
    for item in bare:
        spec = item["spec"]
        if spec in doc:
            continue
        # A bare import the import map resolves is named by its map entry.
        if any(spec == k or (k.endswith("/") and spec.startswith(k)) for k in keys):
            continue
        j.verdict(1, f"JS_LIBRARIES.md does not name the import \"{spec}\" ({j.where(item)})")
    for text, item in php_named.items():
        if text not in doc:
            how = "names" if item["path"] is not None else "writes"
            j.verdict(1, f"JS_LIBRARIES.md does not name {text}, which PHP {how} ({j.where(item)})")
    extra = f"; {unresolved} named by PHP match no tracked file" if unresolved else ""
    j.verdict(0, f"JS_LIBRARIES.md names all {total} scripts the site loads{extra}")


j.run(check)
PY
)"
case $? in
  0) pass "$out" ;;
  1) not_yet "$out" ;;
  *) unknown "${out:-the check could not run}" ;;
esac
