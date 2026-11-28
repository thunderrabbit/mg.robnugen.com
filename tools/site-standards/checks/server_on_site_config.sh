#!/usr/bin/env bash
# server_on_site_config REPO: the site's server runs on site_config.php, and
# the old config file is out of its checkout.
#
# Asks the server (SITE_SERVER_SSH_ALIAS, SITE_SERVER_DIRECTORY) through
# server_facts.py, which looks with `test -f` and never reads a file. The old
# file is the one LEGACY_FILE names in the checkout's classes/Config/Loader.php.
# DreamHost servers only; any other gets "could not tell".
set -uo pipefail
HERE="$(dirname "${BASH_SOURCE[0]}")"
. "$HERE/../lib.sh"
need_repo "${1:-}"
need_config_files

[ "$HAS_LEGACY" = no ] || not_yet "the old config file ($LEGACY_FILE) is still in the server's checkout"
[ "$HAS_SITE_CONFIG" = yes ] || not_yet "the server has no site_config.php"
pass "the server runs on site_config.php; $LEGACY_FILE is out of its checkout"
