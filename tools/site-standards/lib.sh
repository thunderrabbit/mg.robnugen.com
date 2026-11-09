#!/usr/bin/env bash
# Shared by the check scripts in checks/. Sourced, never run.
#
# A check is `checks/<name>.sh REPO [PARAM]`. It reads the repo, changes
# nothing in it, prints exactly one line, and exits:
#   0  the step is done
#   1  not yet (the line says what is missing)
#   2  could not tell (the line says why)
# A check may run the site's code only through the standards-mcp runner
# scripts, which mount the repo read-only with no network.
#
# A line `# memo: commit` in a check's first 20 lines promises that its answer
# depends only on the repo at the commit being scanned, the SITE_* facts it is
# given, lib.sh, the helper files beside the checks and the standards-mcp
# tools. The scanner then reuses a pass or fail until one of those changes
# (memo.py; `scan.sh --fresh` reruns anyway). Without the line a check runs on
# every scan. Never add it to a check that asks a server or the network, reads
# the clock, runs another check, or reads git history beyond the commit or the
# checkout's own .git/config. One accepted gap: files git ignores (vendor/)
# are not in the commit, so they are not in the key. tests/test_memo_headers.py
# keeps this honest.

STANDARDS_MCP="${STANDARDS_MCP:-$HOME/work/rob/standards-mcp}"

# One line, at most 255 characters: that is all the page has room for.
say() {
  local line="${1//$'\n'/ }"
  printf '%s\n' "${line:0:255}"
}

pass()    { say "$1"; exit 0; }
not_yet() { say "$1"; exit 1; }
unknown() { say "$1"; exit 2; }

# need_repo PATH: sets REPO to the checkout's absolute path, or stops the
# check. A function that sets a variable, not one that prints, because a
# command substitution would swallow the "could not tell" exit.
need_repo() {
  [ -n "${1:-}" ] && [ -d "$1" ] || unknown "no such checkout"
  REPO="$(cd "$1" && pwd)"
}

# need_server_dir VALUE WHOSE: sets SERVER_DIR to a directory on a server the
# check may ask about, or stops the check with "could not tell". A DreamHost
# directory is relative to the user's home; an EC2 one is absolute. Either
# way: plain segments, no "..", no trailing slash.
need_server_dir() {
  [[ "${1:-}" =~ ^/?[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9._-]+)*$ && "$1" != *..* ]] \
    || unknown "$2 directory is not one this check will use"
  SERVER_DIR="$1"
}

# need_hooks: sets HOOKS to the repo's tracked hooks directory, or stops the
# check with "not yet". The directory is whatever core.hooksPath names, so
# long as that is a relative path inside the repo with tracked files in it:
# .githooks/ by convention, hooks/ on older sites. Hooks under .git/ are not
# tracked, so nothing switches them on for the next clone.
need_hooks() {
  local path
  path="$(git -C "$REPO" config core.hooksPath 2>/dev/null || true)"
  [ -n "$path" ] || not_yet "no tracked hooks: git config core.hooksPath .githooks, with the hooks committed there"
  case "$path" in
    /*|*..*) not_yet "core.hooksPath is $path, which is outside the repo; the hooks must be tracked in it" ;;
  esac
  path="${path%/}"
  [ -n "$(git -C "$REPO" ls-files -- "$path" 2>/dev/null | head -1)" ] \
    || not_yet "core.hooksPath is $path, but nothing is tracked there"
  HOOKS="$REPO/$path"
}

# Rector's dry run over the repo's own rector.php, run once per repo per scan
# and remembered under $SCAN_TMP. Sets RECTOR_RC and RECTOR_OUT.
rector_dry_run() {
  local repo="$1" key
  if [ -z "${SCAN_TMP:-}" ]; then
    # Run by hand, outside a scan: remember nothing beyond this one check.
    SCAN_TMP="$(mktemp -d)"
    trap 'rm -rf "$SCAN_TMP"' EXIT
  fi
  key="$SCAN_TMP/rector-$(printf '%s' "$repo" | sha1sum | cut -c1-12)"
  if [ ! -f "$key.rc" ]; then
    local rc=0
    "$STANDARDS_MCP/run-rector.sh" "$repo" > "$key.out" 2>&1 || rc=$?
    printf '%s' "$rc" > "$key.rc"
  fi
  RECTOR_RC="$(cat "$key.rc")"
  RECTOR_OUT="$(cat "$key.out")"
}

# rector_verdict LABEL: turn the remembered dry run into the check's answer.
rector_verdict() {
  case "$RECTOR_OUT" in *"GATE SKIP"*) unknown "Rector is not installed in vendor/" ;; esac
  case "$RECTOR_RC" in
    0) pass "$1: nothing left to change" ;;
    2) not_yet "Rector would still change files" ;;
    *) unknown "Rector did not run (exit $RECTOR_RC)" ;;
  esac
}
