#!/usr/bin/env bash
# Open a site's production database in DBeaver, from Lemur 13. Run it from
# anywhere inside the site's checkout. It took over from the per-site
# phpmyadmin-open.sh scripts when DreamHost retired phpMyAdmin.
#
#   tools/db-open/db-open.sh                      open DBeaver, connected
#   tools/db-open/db-open.sh --mysql              open the mysql client instead
#   tools/db-open/db-open.sh --dry-run            say what it found; open nothing
#   tools/db-open/db-open.sh --close              close the site's ssh tunnel
#   tools/db-open/db-open.sh --shortcode=example  when a checkout holds several sites
#
# mg says which server the site is on (`site.sh next-step`, as /next-mg-step
# asks). PHP on that server reads the four database values from the site's
# site_config.php, or from its old config class where the site has not
# converted, so a site's conversion changes nothing here.
#
# The password is never on a command line and never printed: DBeaver and
# mysql each read it from a mode-600 file in $XDG_RUNTIME_DIR (memory, not
# disk) that is removed again. The connection goes through an ssh tunnel to
# the site's server, bound to 127.0.0.1: the database accepts only the hosts
# in the panel's "Allowable Hosts", a laptop's address changes, and the
# server is already allowed.
set -euo pipefail
umask 077

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
# How long DBeaver gets to start and read the password file.
VARS_SECONDS="${DB_OPEN_VARS_SECONDS:-90}"

stop () {
    echo "$1" >&2
    exit 1
}

MODE=""
SHORTCODE=""
for arg in "$@"; do
    case "$arg" in
        --mysql | --dry-run | --close)
            [ -z "$MODE" ] || stop "Give one of --mysql, --dry-run and --close, not two."
            MODE="$arg"
            ;;
        --shortcode=*) SHORTCODE="${arg#--shortcode=}" ;;
        *) stop "Usage: db-open.sh [--mysql | --dry-run | --close] [--shortcode=<code>]" ;;
    esac
done

DBEAVER=""
if [ -z "$MODE" ]; then
    DBEAVER=$(command -v dbeaver-ce || command -v dbeaver || true)
    [ -n "$DBEAVER" ] || stop "DBeaver is not installed (no dbeaver-ce or dbeaver on PATH)."
fi

# Which site is this? The checkout's path under ~/work is what mg knows it by.
TOP=$(git rev-parse --show-toplevel 2> /dev/null) || stop "This is not inside a git checkout."
WORK_ROOT="${WORK_ROOT:-$HOME/work}"
case "$TOP" in
    "$WORK_ROOT"/*) ;;
    *) stop "This checkout is not under $WORK_ROOT." ;;
esac
ASKED="--checkout=${TOP#"$WORK_ROOT"/}"
[ -z "$SHORTCODE" ] || ASKED="--shortcode=$SHORTCODE"

echo "Asking mg where this site is..." >&2
SITE_CMD="${SCAN_SITE_CMD:-$HERE/../site-standards/site.sh}"
REPLY=$($SITE_CMD next-step "$ASKED") || stop "mg did not answer for this checkout."

# One line per fact, or one line starting with "stop:" and a sentence.
mapfile -t FACTS < <(printf '%s' "$REPLY" | python3 -I -c '
import json, sys
try:
    reply = json.load(sys.stdin)
except ValueError:
    sys.exit("stop:mg answered with something that is not JSON.")
codes = reply.get("shortcodes") or []
if len(codes) > 1:
    sys.exit("stop:This checkout holds several sites. Add --shortcode= with one of: " + ", ".join(codes))
facts = reply.get("facts") or {}
server = facts.get("server") or {}
if reply.get("site") is None or not facts.get("shortcode"):
    sys.exit("stop:This checkout is not a site on the standards page.")
if not server.get("ssh_alias") or not server.get("directory"):
    sys.exit("stop:mg has no server for this site: " + str(facts.get("server_problem") or "no server link"))
if server["directory"].startswith("/"):
    sys.exit("stop:This site is not on DreamHost, and this opens DreamHost databases only.")
print(facts["shortcode"])
print(server["ssh_alias"])
print(server["directory"])
print(facts.get("php_current") or "")
' 2>&1)

case "${FACTS[0]:-}" in
    stop:*) stop "${FACTS[0]#stop:}" ;;
    "") stop "mg's answer could not be read." ;;
esac
SITE="${FACTS[0]}"
ALIAS="${FACTS[1]}"
DIRECTORY="${FACTS[2]}"
PHP_CURRENT="${FACTS[3]:-}"

# Each site gets its own local port, worked out from its shortcode, so two
# sites can be open at once and a tunnel is only ever reused for its own site.
LOCAL_PORT=$((13306 + $(printf '%s' "$SITE" | cksum | cut -d' ' -f1) % 1000))
TUNNEL="127.0.0.1:$LOCAL_PORT"

if [ "$MODE" = "--close" ]; then
    if pkill -f -- "-L $TUNNEL:"; then
        echo "Closed the tunnel for $SITE."
    else
        echo "No tunnel was open for $SITE."
    fi
    exit 0
fi

# The served PHP where a sweep has seen which it is: the shell's own `php`
# on DreamHost is older and may not parse the site's config class.
REMOTE_PHP="php"
if [[ "$PHP_CURRENT" =~ ^([0-9]+)\.([0-9]+) ]]; then
    REMOTE_PHP="/usr/local/php${BASH_REMATCH[1]}${BASH_REMATCH[2]}/bin/php"
fi

echo "Reading the database settings on the server..." >&2

# PHP reads the config, so the quoting in it is PHP's to understand. mg holds
# the site's web directory; the project root is it or a directory above it.
# The places are tried in the order the site's own Config\Loader uses. Each
# value comes back base64 on a line of its own, then the file it came from.
ANSWER_TEXT=$(ssh "$ALIAS" "$REMOTE_PHP -- $(printf '%q' "$DIRECTORY")" << 'PHP'
<?php
$places = [
    ['site_config.php', null],
    ['classes/Config/Config.php', 'Config\Config'],
    ['classes/Config.php', 'Config'],
];
$found = null;
$root = $argv[1];
for ($up = 0; $up < 3 && $found === null; $up++, $root = dirname($root)) {
    foreach ($places as [$file, $class]) {
        if (is_file("$root/$file")) {
            $found = [$file, $class, $root];
            break;
        }
    }
}
if ($found === null) {
    // Not STDERR: PHP does not define it for a script read from stdin.
    file_put_contents('php://stderr', "No site_config.php or old config class at or above {$argv[1]}\n");
    exit(1);
}
[$file, $class, $root] = $found;
try {
    $config = require "$root/$file";
    if ($class !== null) {
        $config = get_object_vars(new $class());
    }
} catch (\Throwable $e) {
    // Only where: PHP's own message may quote the line, and so the password.
    file_put_contents('php://stderr', "$file could not be read, line " . $e->getLine() . "\n");
    exit(1);
}
foreach (['dbHost', 'dbUser', 'dbPass', 'dbName'] as $key) {
    echo base64_encode(is_array($config) && is_scalar($config[$key] ?? null) ? (string) $config[$key] : ''), "\n";
}
echo $file, "\n";
PHP
) || stop "The site's server gave no database settings."
mapfile -t ANSWER <<< "$ANSWER_TEXT"
unset ANSWER_TEXT

DB_HOST=$(printf '%s' "${ANSWER[0]:-}" | base64 -d)
DB_USER=$(printf '%s' "${ANSWER[1]:-}" | base64 -d)
DB_PASS=$(printf '%s' "${ANSWER[2]:-}" | base64 -d)
DB_NAME=$(printf '%s' "${ANSWER[3]:-}" | base64 -d)
FROM="${ANSWER[4]:-}"
unset ANSWER

MISSING=""
[ -n "$DB_HOST" ] || MISSING="$MISSING dbHost"
[ -n "$DB_USER" ] || MISSING="$MISSING dbUser"
[ -n "$DB_PASS" ] || MISSING="$MISSING dbPass"
[ -n "$DB_NAME" ] || MISSING="$MISSING dbName"
[ -z "$MISSING" ] || stop "$SITE: ${FROM:-the config} has no value for:$MISSING"

if [ "$MODE" = "--dry-run" ]; then
    echo "$SITE: dbHost, dbUser, dbPass and dbName read from $FROM. Would open $DB_NAME through $TUNNEL."
    exit 0
fi

# The tunnel: this machine's port leads to the database, as seen from the
# site's server. One left open by an earlier run is used again.
if pgrep -f -- "-L $TUNNEL:$DB_HOST:3306" > /dev/null; then
    echo "Using the tunnel already open on $TUNNEL." >&2
else
    ssh -f -N -o ExitOnForwardFailure=yes -L "$TUNNEL:$DB_HOST:3306" "$ALIAS"
    echo "Tunnel open on $TUNNEL (--close closes it)." >&2
fi

# Both file formats take a backslash as an escape, so double any in the password.
ESCAPED_PASS=${DB_PASS//\\/\\\\}

if [ "$MODE" = "--mysql" ]; then
    SECRETS=$(mktemp "$RUNTIME/db-open-XXXXXX.cnf")
    trap 'rm -f "$SECRETS"' EXIT
    printf '[client]\nuser="%s"\npassword="%s"\n' "$DB_USER" "${ESCAPED_PASS//\"/\\\"}" > "$SECRETS"
    mysql --defaults-extra-file="$SECRETS" -A -h 127.0.0.1 -P "$LOCAL_PORT" "$DB_NAME"
    exit 0
fi

# DBeaver replaces ${db_open_password} in -con with the value in the file
# that -vars names, so `ps` shows the file's path and not the password.
SECRETS=$(mktemp "$RUNTIME/db-open-XXXXXX.properties")
printf 'db_open_password=%s\n' "$ESCAPED_PASS" > "$SECRETS"

# save=false: a temporary connection, which DBeaver forgets when it closes.
CONNECTION="driver=mysql|host=127.0.0.1|port=$LOCAL_PORT|database=$DB_NAME|user=$DB_USER"
CONNECTION="$CONNECTION|password=\${db_open_password}|name=$SITE|save=false|connect=true"

setsid "$DBEAVER" -vars "$SECRETS" -con "$CONNECTION" > /dev/null 2>&1 &

# Remove the password file once DBeaver has had time to read it.
(
    sleep "$VARS_SECONDS"
    rm -f "$SECRETS"
) > /dev/null 2>&1 &
disown -a

echo "$SITE: DBeaver is opening $DB_NAME (settings from $FROM). The password file is removed in $VARS_SECONDS seconds."
