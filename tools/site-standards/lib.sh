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
