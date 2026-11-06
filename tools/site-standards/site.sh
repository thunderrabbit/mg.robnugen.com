#!/usr/bin/env bash
# Keep the hand-entered facts about a site, from Lemur 13. Passes its
# arguments to tools/site_standards.php on the server; see that file, or
# classes/Inventory/Standards/SiteCommand.php, for the commands.
#
#   tools/site-standards/site.sh sites
#   tools/site-standards/site.sh site-add --shortcode=example --standard=template --project=7
#   tools/site-standards/site.sh site-set --shortcode=example --checkout=rob/example.test
#   tools/site-standards/site.sh site-link --shortcode=example --domain=example.test
set -euo pipefail

remote="/usr/local/php85/bin/php ~/mg.robnugen.com/tools/site_standards.php"
for arg in "$@"; do
  remote+=" $(printf '%q' "$arg")"
done
exec ssh mg "$remote"
