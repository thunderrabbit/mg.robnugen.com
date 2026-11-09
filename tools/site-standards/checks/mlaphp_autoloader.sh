#!/usr/bin/env bash
# memo: commit
# mlaphp_autoloader REPO: classes come from an autoloader (MLAPHP chapter 3).
#
# Done when classes/ exists, some tracked PHP file registers an autoloader with
# spl_autoload_register(), and every PHP file that includes a file under
# classes/ registers one itself: a bootstrap may load its autoloader, or a
# class it needs before the autoloader, by hand; a page script may not.
# Tests are left out: a test suite has its own bootstrap, and a test may load
# a fixture class by hand.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

[ -d "$REPO/classes" ] || not_yet "no classes/ directory to autoload from"

mapfile -t php < <(git -C "$REPO" ls-files -- '*.php' ':!:vendor/**' ':!:Tests/**' ':!:tests/**' 2>/dev/null)
[ "${#php[@]}" -gt 0 ] || unknown "no tracked PHP files"

includes_class='\b(include|require)(_once)?\b.*classes/'
registers='spl_autoload_register[[:space:]]*\('

# The file's lines that are code: a line starting a comment is left out.
code() { grep -Ev '^[[:space:]]*(//|#|/?\*)' "$1"; }

bootstraps=0
offenders=()
for f in "${php[@]}"; do
  path="$REPO/$f"
  [ -f "$path" ] || continue
  if code "$path" | grep -Eq "$registers"; then
    bootstraps=$((bootstraps + 1))
  elif code "$path" | grep -Eq "$includes_class"; then
    offenders+=("$f")
  fi
done

[ "$bootstraps" -gt 0 ] || not_yet "nothing calls spl_autoload_register()"
[ "${#offenders[@]}" -eq 0 ] \
  || not_yet "${#offenders[@]} file(s) include a class file by hand without an autoloader of their own, first ${offenders[0]}"
pass "classes autoload; $bootstraps bootstrap(s) register an autoloader"
