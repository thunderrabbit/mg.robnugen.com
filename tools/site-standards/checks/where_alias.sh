#!/usr/bin/env bash
# where_alias REPO: the server user's ~/.bash_aliases defines the alias
# `where`, which says which server a shell is on.
#
# Asks the server (SITE_SERVER_SSH_ALIAS) through server_facts.py, which looks
# with `grep -q` and never prints the file. The checkout is not read.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

alias_="${SITE_SERVER_SSH_ALIAS:-}"
[[ "$alias_" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || unknown "no ssh login for the server to ask about its where alias"

answer="$(python3 -I -B "$HERE/../server_facts.py" where "$alias_" 2>&1 </dev/null)" \
  || unknown "${answer:-the server did not answer}"
[ "$answer" = "where: yes" ] || not_yet "the server user's ~/.bash_aliases has no line that opens: alias where="
pass "the server user's ~/.bash_aliases defines where"
