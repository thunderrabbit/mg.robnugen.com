#!/usr/bin/env bash
# memo: commit
# security_argon2id REPO: passwords are hashed with argon2id where the PHP
# has it, and an older hash is replaced at the next login.
#
# Done when every file of tracked PHP that calls password_hash() names
# argon2id, and something calls password_needs_rehash(). A site that hashes
# no password passes.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

calls="$(php_hits '(^|[^A-Za-z0-9_>:$])password_hash[[:space:]]*\(')"
[ -n "$calls" ] || pass "no password is hashed here"

while IFS= read -r file; do
  grep -qi 'argon2id' "$REPO/$file" \
    || not_yet "$(first_hit "$(printf '%s\n' "$calls" | grep "^$file:")") hashes a password without naming argon2id"
done < <(files_of "$calls")

rehash="$(php_hits '(^|[^A-Za-z0-9_>:$])password_needs_rehash[[:space:]]*\(')"
[ -n "$rehash" ] || not_yet "nothing calls password_needs_rehash(), so an old bcrypt hash is never replaced"
pass "passwords are hashed with argon2id, and $(first_hit "$rehash") replaces an older hash"
