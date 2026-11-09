#!/usr/bin/env bash
# memo: commit
# mysql84_reserved_words REPO: no query breaks on MySQL 8.4's new reserved
# words (MANUAL, PARALLEL, QUALIFY, TABLESAMPLE).
#
# Done when no table or column in the repo's .sql files has one of those
# names, or when one does and every PHP mention of it is backticked. A PHP
# line that merely says the word (in a comment, in English) also counts
# against the site: the line is named, so look and backtick it or say NAh.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

out="$(python3 -I - "$REPO" <<'PY'
import os, re, sys

repo = sys.argv[1]
words = ("manual", "parallel", "qualify", "tablesample")
skip_dirs = {".git", "vendor", "node_modules"}

def files(ext):
    for root, dirs, names in os.walk(repo):
        dirs[:] = sorted(d for d in dirs if d not in skip_dirs)
        for name in sorted(names):
            if name.endswith(ext):
                yield os.path.join(root, name)

def read(path):
    try:
        with open(path, encoding="utf-8", errors="replace") as f:
            return f.read()
    except OSError:
        return ""

# SQL with comments and quoted strings blanked out, so only names remain.
strip = re.compile(r"/\*.*?\*/|--[^\n]*|#[^\n]*|'(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\"", re.S)
named = re.compile(r"(?<![\w$])(" + "|".join(words) + r")(?![\w$])", re.I)

sql = list(files(".sql"))
if not sql:
    print("UNKNOWN|no .sql files to read the schema from")
    sys.exit()

found = {}
for path in sql:
    for m in named.finditer(strip.sub(" ", read(path))):
        found.setdefault(m.group(1).lower(), os.path.relpath(path, repo))
if not found:
    print("PASS|no table or column is named manual, parallel, qualify or tablesample")
    sys.exit()

# A bare mention: not backticked, not a $variable, ->property or ::constant.
bare = re.compile(r"(?<![\w$`>:])(" + "|".join(found) + r")(?![\w`])", re.I)
hits = []
for path in files(".php"):
    for n, line in enumerate(read(path).splitlines(), 1):
        if bare.search(line):
            hits.append(f"{os.path.relpath(path, repo)}:{n}")

names = ", ".join(f"{w} ({found[w]})" for w in sorted(found))
if hits:
    print(f"NOTYET|named {names}; {len(hits)} bare PHP mention(s), first {hits[0]}")
else:
    print(f"PASS|named {names}, and every PHP mention is backticked")
PY
)" || unknown "the schema could not be read"

case "$out" in
  PASS\|*)    pass "${out#*|}" ;;
  NOTYET\|*)  not_yet "${out#*|}" ;;
  UNKNOWN\|*) unknown "${out#*|}" ;;
  *)          unknown "the check printed nothing it understands" ;;
esac
