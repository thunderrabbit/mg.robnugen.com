#!/usr/bin/env bash
# Move a site's server from its old config class file to site_config.php, or
# leave it exactly as it was. Run it from inside the site's checkout, once the
# server runs the code of the step `config_through_loader`.
#
#   tools/site-standards/convert-site-config.sh --dry-run     check everything; change nothing
#   tools/site-standards/convert-site-config.sh               convert, comparing after every change
#   tools/site-standards/convert-site-config.sh --test        the site's test domain instead
#   tools/site-standards/convert-site-config.sh --delete-old  remove the kept copy of the old file
#   ... --shortcode=example                                   when a checkout holds several sites
#
# Prints one JSON object: `outcome` (would_convert, converted, not_ready,
# put_back, needs_a_person, deleted_old) and `say`. It never prints a config
# value. A session runs --delete-old only on Rob's word: the kept copy is the
# way back. The logic is in convert_site_config.py.
set -euo pipefail
exec python3 -I -B "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/convert_site_config.py" "$@"
