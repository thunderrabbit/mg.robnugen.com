#!/usr/bin/env bash
# old_config_file_deleted REPO: the copy of the old config file that the
# conversion kept in the server user's home is gone. It holds the database
# password in plain text.
#
# Asks the server as server_on_site_config does. The kept copy is
# ~/<checkout's name>.Config.php.old, or a ~/Config.php.old made by hand.
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
need_config_files

[ "$HAS_SITE_CONFIG" = yes ] && [ "$HAS_LEGACY" = no ] \
  || not_yet "the server is not on site_config.php yet; that step comes first"
[ "$HAS_KEPT" = no ] || not_yet "a kept copy of the old config file is still in the server user's home"
pass "the server runs on site_config.php and no copy of the old config file is kept"
