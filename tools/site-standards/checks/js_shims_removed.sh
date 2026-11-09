#!/usr/bin/env bash
# memo: commit
# js_shims_removed REPO: the site loads none of the shims that keep old code
# running on new libraries: jQuery Migrate, Vue's compat build, core-js,
# babel-polyfill, or polyfill.io and its copies, nor any shim of its own that
# JS_LIBRARIES.md declares as "Shim: <path or url> -- <why>" (../NAMES.md).
# Read from the same scripts as the inventory step, the scripts PHP names,
# and package.json's dependencies.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
out="$(python3 -I -B - "$HERE" "$REPO" <<'PY'
import re, sys
sys.path.insert(0, sys.argv[1])
import js_scripts as j

SHIMS = [
    (re.compile(r"jquery[-.]migrate", re.I), "jQuery Migrate"),
    (re.compile(r"@vue/compat", re.I), "Vue's compat build"),
    (re.compile(r"(?:\A|[/@:])core-js(?:[/@-]|\Z)", re.I), "core-js"),
    (re.compile(r"babel[-/](?:polyfill)|@babel/polyfill", re.I), "babel-polyfill"),
    (re.compile(r"polyfill(?:-fastly)?\.(?:io|net)|/polyfill/v\d|polyfill\.min\.js\?features", re.I), "polyfill.io"),
]
DECLARED = re.compile(r"^Shim: (\S+) -- (\S.*)$", re.M)


def check(repo):
    found = j.read(repo)
    if found["problems"]:
        j.verdict(2, found["problems"][0])
    items = [(s["src"], j.where(s)) for s in found["scripts"] if not s["dynamic"]]
    items += [(e["url"], j.where(e)) for e in found["importmap"]]
    items += [(i["spec"], j.where(i)) for i in found["imports"] if not i["dynamic"]]
    items += [(n["path"] or n["tail"], j.where(n)) for n in found["named"]]
    items += [(name, "package.json") for name in j.dependencies(repo)]
    for text, where in items:
        for pattern, shim in SHIMS:
            if pattern.search(text):
                j.verdict(1, f"the site still loads {shim}: {text} ({where})")
    doc = j.contents(repo, "JS_LIBRARIES.md") or ""
    tracked = set(j.tracked_files(repo, minified=True, skip={".git"}))
    declared = DECLARED.findall(doc)
    for what, why in declared:
        if what in tracked:
            j.verdict(1, f"the site still has its own shim {what}: {why}")
        for text, where in items:
            if text == what or text.endswith("/" + what.lstrip("/")):
                j.verdict(1, f"the site still loads its own shim {what} ({where}): {why}")
    gone = f"; the {len(declared)} it declared are gone" if declared else ""
    j.verdict(0, f"the site loads no compatibility shims{gone}")


j.run(check)
PY
)"
case $? in
  0) pass "$out" ;;
  1) not_yet "$out" ;;
  *) unknown "${out:-the check could not run}" ;;
esac
