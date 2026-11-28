#!/usr/bin/env bash
# memo: commit
# config_through_loader REPO: the site reads its configuration through
# Config\Loader, which builds a read-only Config\Settings from the server's
# site_config.php and still falls back to the old class file.
#
# Done when, in tracked files:
#   - classes/Config/Loader.php and classes/Config/Settings.php are there;
#   - site_config.sample.php is there, and .gitignore lists site_config.php;
#   - no PHP outside the tests builds the old class with `new`, or imports it
#     (the class is the one Loader's LEGACY_CLASS names);
#   - no PHP reads a setting with a default of its own ($config->x ?? y):
#     Settings holds every default.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"

tracked() { git -C "$REPO" ls-files --error-unmatch -- "$1" >/dev/null 2>&1; }

for file in classes/Config/Loader.php classes/Config/Settings.php; do
  tracked "$file" || not_yet "no $file: the site still builds its config class itself"
done
tracked site_config.sample.php || not_yet "no site_config.sample.php for a new server to copy"
tracked .gitignore && grep -qxE '/?site_config\.php' "$REPO/.gitignore" \
  || not_yet ".gitignore does not list site_config.php, which holds the database password"

# The old class, as Loader names it: 'Config\Config' in the template, any
# other name on a site that differs. No LEGACY_CLASS means the fallback has
# been removed, and then nothing can build the old class.
old="$(sed -nE "s/^.*const( string)? LEGACY_CLASS = '([A-Za-z_][A-Za-z0-9_\\\\]*)';.*$/\2/p" \
  "$REPO/classes/Config/Loader.php" | head -1)"
old="${old//\\\\/\\}"
if [ -n "$old" ]; then
  name="${old//\\/\\\\}"
  hits="$(php_hits "(new[[:space:]]+\\\\?${name}([^A-Za-z0-9_\\\\]|\$)|^[[:space:]]*use[[:space:]]+\\\\?${name}[[:space:]]*;)")"
  [ -z "$hits" ] || not_yet "$(first_hit "$hits") still uses the old class $old; Config\\Loader::load() builds the settings"
fi

hits="$(php_hits 'config->[A-Za-z_][A-Za-z0-9_]*[[:space:]]*\?\?')"
[ -z "$hits" ] || not_yet "$(first_hit "$hits") reads a setting with its own default; Config\\Settings holds the defaults"

pass "the config is read through Config\\Loader into Config\\Settings"
