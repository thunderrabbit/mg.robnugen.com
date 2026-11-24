#!/usr/bin/env bash
# memo: commit
# security_unknown_user_timing REPO: a username that does not exist takes as
# long to refuse as a wrong password.
#
# Done when the file that checks a password for a username calls
# password_verify() twice: once for the stored hash, once against a stand-in
# hash when there is no such user. The file is the tracked PHP that calls
# password_verify() and names a username. A site with no password_verify()
# passes.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

calls="$(php_hits '(^|[^A-Za-z0-9_>:$])password_verify[[:space:]]*\(')"
[ -n "$calls" ] || pass "no password is checked here"

login=''
while IFS= read -r file; do
  grep -qi 'username' "$REPO/$file" || continue
  login="${login:-$file}"
  [ "$(printf '%s\n' "$calls" | grep -c "^$file:")" -ge 2 ] \
    && pass "$file checks a stand-in hash when the username is unknown"
done < <(files_of "$calls")
[ -n "$login" ] || unknown "could not find the file that checks a password for a username"
not_yet "$login checks a password only when the username exists, so a made-up name is refused faster"
