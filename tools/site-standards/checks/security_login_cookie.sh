#!/usr/bin/env bash
# memo: commit
# security_login_cookie REPO: the login cookie belongs to the one host that
# set it.
#
# Done when the options setcookie() is given (the tracked PHP that has a
# 'samesite' key) carry no 'domain' key, and the cookie's name is given the
# __Host- prefix in that file or in a file that calls setcookie(). A site
# that sets no cookie from PHP passes.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

q="['\"]"
calls="$(php_hits '(^|[^A-Za-z0-9_>:$])setcookie[[:space:]]*\(')"
[ -n "$calls" ] || pass "no cookie is set from PHP"

options="$(php_hits "${q}samesite${q}[[:space:]]*=>")"
[ -n "$options" ] \
  || not_yet "$(first_hit "$calls") sets a cookie without an options array (no 'samesite' key anywhere)"

mapfile -t option_files < <(files_of "$options")
domain="$(php_hits "${q}domain${q}[[:space:]]*=>" "${option_files[@]}")"
[ -z "$domain" ] \
  || not_yet "$(first_hit "$domain") gives the cookie a Domain, so every subdomain receives it and may overwrite it"

mapfile -t named_in < <(printf '%s\n%s\n' "$(files_of "$options")" "$(files_of "$calls")" | sort -u | grep -v '^$')
prefix="$(php_hits '__Host-' "${named_in[@]}")"
[ -n "$prefix" ] \
  || not_yet "the login cookie's name has no __Host- prefix, so a sibling site can set one of the same name"
pass "the login cookie has no Domain and a __Host- name ($(first_hit "$prefix"))"
