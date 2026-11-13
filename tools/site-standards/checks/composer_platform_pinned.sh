#!/usr/bin/env bash
# memo: commit
# composer_platform_pinned REPO: composer.json pins Composer's platform PHP
# (config.platform.php) to the PHP the site is served on today
# (SITE_PHP_CURRENT, from the sweep), and composer.lock was resolved with that
# pin. Composer then picks only packages the server can run, whichever PHP
# the machine running Composer has: a lock made on a newer PHP can't sneak in a
# package the server would fail on.
#
# Same major.minor is enough: a pin of 8.1.2 or 8.1 both hold to 8.1. When
# the server moves to a newer PHP, the pin moves with it, and this step opens
# again until it does.
#
# A site with no composer.json, or one that requires only PHP and extensions,
# deploys nothing from Composer and passes with a line saying so.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
need_repo "${1:-}"

[ -f "$REPO/composer.json" ] || pass "no composer.json, so the server runs nothing Composer installed"

# Only a site that deploys Composer packages needs the pin. A site whose
# "require" names nothing but PHP and its extensions keeps vendor/ for dev
# tools, which run in the runner image and never on the server.
runtime="$(python3 -I -c '
import json, sys
try:
    data = json.load(open(sys.argv[1]))
except Exception:
    sys.exit(3)
require = data.get("require", {}) if isinstance(data, dict) else {}
if not isinstance(require, dict):
    sys.exit(3)
print(sum(1 for name in require if name != "php" and not name.startswith("ext-")))
' "$REPO/composer.json")" || unknown "composer.json could not be read"
[ "$runtime" -gt 0 ] || pass "composer.json requires no packages, so the server runs nothing Composer installed"

current="$(printf '%s' "${SITE_PHP_CURRENT:-}" | grep -Eo '^[0-9]+\.[0-9]+' || true)"
[ -n "$current" ] || unknown "its current PHP is not in the sweep"

read_pin() {
  python3 -I -c '
import json, sys
try:
    data = json.load(open(sys.argv[1]))
except Exception:
    sys.exit(3)
if not isinstance(data, dict):
    sys.exit(3)
if sys.argv[2] == "json":
    config = data.get("config", {})
    platform = config.get("platform", {}) if isinstance(config, dict) else {}
else:
    platform = data.get("platform-overrides", {})
value = platform.get("php", "") if isinstance(platform, dict) else ""
print(value if isinstance(value, str) else "")
' "$1" "$2"
}

pin="$(read_pin "$REPO/composer.json" json)" || unknown "composer.json could not be read"
[ -n "$pin" ] || not_yet "composer.json pins no platform PHP (config.platform.php); the server serves $current"
pinned="$(printf '%s' "$pin" | grep -Eo '^[0-9]+\.[0-9]+' || true)"
[ "$pinned" = "$current" ] || not_yet "composer.json pins PHP $pin, but the server serves $current"

[ -f "$REPO/composer.lock" ] || not_yet "no composer.lock"
locked="$(read_pin "$REPO/composer.lock" lock)" || unknown "composer.lock could not be read"
[ "$locked" = "$pin" ] \
  || not_yet "composer.lock was resolved with PHP ${locked:-no pin}, not the pinned $pin; run composer update --lock"
pass "Composer resolves for PHP $pin, the PHP the server serves"
