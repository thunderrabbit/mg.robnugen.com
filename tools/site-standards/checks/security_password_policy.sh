#!/usr/bin/env bash
# memo: commit
# security_password_policy REPO: new passwords are held to one written rule,
# at least 15 characters.
#
# Done when a tracked PasswordPolicy.php sets a MIN_... constant of 15 or
# more and some other tracked PHP asks PasswordPolicy:: before taking a new
# password.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

policy="$(git -C "$REPO" ls-files -- '*PasswordPolicy.php' 2>/dev/null | grep -Ev '^(vendor|Tests|tests)/' | head -1)"
[ -n "$policy" ] || not_yet "no PasswordPolicy class: new passwords are held to no written rule"

min="$(grep -Eo 'MIN_[A-Z_]*[[:space:]]*=[[:space:]]*[0-9]+' "$REPO/$policy" | head -1 | grep -Eo '[0-9]+$' || true)"
[ -n "$min" ] || unknown "$policy has no MIN_... constant to read the minimum from"
[ "$min" -ge 15 ] \
  || not_yet "$policy asks for $min characters; NIST SP 800-63B-4 asks for 15 where the password is the only factor"

users="$(php_hits 'PasswordPolicy::' | grep -v "^$policy:" || true)"
[ -n "$users" ] || not_yet "$policy is there, but nothing asks it about a new password"
pass "$policy asks for $min characters, and $(files_of "$users" | wc -l) file(s) ask it"
