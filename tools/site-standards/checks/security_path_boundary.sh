#!/usr/bin/env bash
# memo: commit
# security_path_boundary REPO: a "is this path inside that directory" check
# ends at a directory boundary.
#
# Not yet while tracked PHP compares two realpath() results (variables named
# $real...) with str_starts_with(), strpos() or strncmp() and nothing after
# the base: db_schemas_old "starts with" db_schemas. Appending a separator to
# the base, as in `$realBase . DIRECTORY_SEPARATOR`, is what passes.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

real='\$real[A-Za-z0-9_]*'
loose="$(php_hits "(str_starts_with|strpos|strncmp)[[:space:]]*\\([[:space:]]*${real}[[:space:]]*,[[:space:]]*${real}[[:space:]]*[,)]")"
[ -z "$loose" ] \
  || not_yet "$(first_hit "$loose") compares two real paths with no separator after the base, so a longer-named sibling counts as inside"
pass "no containment check stops short of a directory boundary"
