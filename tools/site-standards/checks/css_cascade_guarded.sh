#!/usr/bin/env bash
# css_cascade_guarded REPO: JS_LIBRARIES.md names a "CSS guard: <command>"
# whose first word is a tracked file, and the tracked commit-msg or
# pre-commit hook runs it. A site with no stylesheets of its own passes.
# Names in ../NAMES.md; the hooks directory per need_hooks in ../lib.sh.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

own_css="$(git -C "$REPO" ls-files -- '*.css' | grep -vE '(^|/)(vendor|node_modules)/' | head -1)"
[ -n "$own_css" ] || pass "the site has no stylesheets of its own"
[ -f "$REPO/JS_LIBRARIES.md" ] || not_yet "no JS_LIBRARIES.md to name the CSS guard in"
command="$(grep -E '^CSS guard:[[:space:]]*\S' "$REPO/JS_LIBRARIES.md" | tail -1 | sed -E 's/^CSS guard:[[:space:]]*//; s/[[:space:]]+$//')"
[ -n "$command" ] || not_yet "JS_LIBRARIES.md has no \"CSS guard: <command>\" line"
tool="${command%% *}"
[[ "$tool" != /* && "$tool" != *..* ]] || not_yet "the CSS guard $tool is not a path inside the repo"
[ -n "$(git -C "$REPO" ls-files -- "$tool" | head -1)" ] || not_yet "the CSS guard $tool is not a tracked file"
need_hooks
name="$(basename "$tool")"
runs=""
for hook in commit-msg pre-commit; do
  [ -f "$HOOKS/$hook" ] && grep -qF "$name" "$HOOKS/$hook" && runs="$hook"
done
[ -n "$runs" ] || not_yet "neither commit-msg nor pre-commit in ${HOOKS#"$REPO/"}/ runs $name"
pass "$tool guards the cascade from the $runs hook"
