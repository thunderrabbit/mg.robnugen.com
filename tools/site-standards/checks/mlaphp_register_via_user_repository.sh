#!/usr/bin/env bash
# memo: commit
# mlaphp_register_via_user_repository REPO: the register page's one INSERT
# lives in \Database\UserRepository::create() (MLAPHP chapter 8, the
# template's one SQL call in a page script, ported).
#
# Done when wwwroot/login/register.php says no `INSERT INTO users` and
# classes/Database/UserRepository.php defines create(). A site with no
# wwwroot/login/register.php has nothing to move and passes.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

page='wwwroot/login/register.php'
class='classes/Database/UserRepository.php'
git -C "$REPO" ls-files --error-unmatch -- "$page" >/dev/null 2>&1 \
  || pass "no $page to move"

if grep -Eiq 'INSERT[[:space:]]+INTO[[:space:]]+`?users' "$REPO/$page"; then
  not_yet "$page still runs INSERT INTO users"
fi
git -C "$REPO" ls-files --error-unmatch -- "$class" >/dev/null 2>&1 \
  || not_yet "$class does not exist"
grep -Eq 'public[[:space:]]+function[[:space:]]+create[[:space:]]*\(' "$REPO/$class" \
  || not_yet "$class does not define create()"
pass "register.php registers through \\Database\\UserRepository::create()"
