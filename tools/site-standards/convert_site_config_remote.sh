# The part of convert_site_config.py that runs on a site's server. Never run
# by hand: the script sends this text over ssh as `bash -c <text> convert OP
# DIRECTORY PHP LEGACY_FILE LEGACY_CLASS [PLACED MODE]` and reads the
# `name=value` lines it prints.
#
# It never prints a config value: only yes and no, names of keys, hashes, a
# PHP version and a file mode. PHP runs with display_errors off, because a
# parse error quotes the code it stopped on, and that may be the password.
#
#   look         which files are there, the checkout's git state, the PHP
#   keys         keys of the old config that Config\Settings does not know
#   fingerprint  a hash of the settings the site's own loader builds, and the
#                file it read them from
#   generate     write the new file as a candidate in the home directory,
#                lint it, and hash the settings it would give
#   place        put the candidate in the checkout as site_config.php
#   move         move the old config file to the kept copy in the home directory
#   put-back     undo place and move
#   delete-old   remove the kept copy
set -u
op="${1:-}"
dir="${2:-}"
php="${3:-}"
legacy="${4:-}"
class="${5:-}"
placed="${6:-no}"
mode="${7:-}"

root="$(git -c 'safe.directory=*' --no-optional-locks -C "$dir" rev-parse --show-toplevel 2>/dev/null)" || {
  echo "problem=no-checkout"
  exit 3
}
name="$(basename "$root")"
config="$root/site_config.php"
old="$root/$legacy"
kept="$HOME/$name.Config.php.old"
candidate="$HOME/$name.site_config.php.candidate"

yn() { if "$@"; then echo yes; else echo no; fi; }
quiet_php() { "$php" -d display_errors=0 -d log_errors=0 "$@"; }
has_classes() {
  test -f "$root/classes/Config/Loader.php" && test -f "$root/classes/Config/Settings.php" \
    && test -f "$root/classes/Config/EConfigInvalid.php"
}

case "$op" in
  look)
    echo "root_name=$name"
    echo "loader=$(yn has_classes)"
    echo "legacy=$(yn test -f "$old")"
    echo "site_config=$(yn test -e "$config")"
    echo "kept=$(yn test -e "$kept")"
    echo "kept_by_hand=$(yn test -e "$HOME/Config.php.old")"
    changes="$(git -c 'safe.directory=*' --no-optional-locks -C "$root" status --porcelain --untracked-files=no)"
    echo "tracked_changes=$(yn test -n "$changes")"
    echo "status=$(git -c 'safe.directory=*' --no-optional-locks -C "$root" status --porcelain | sha256sum | cut -c1-64)"
    echo "legacy_mode=$(stat -c %a "$old" 2>/dev/null)"
    echo "php=$(quiet_php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;' 2>/dev/null)"
    ;;

  keys)
    quiet_php /dev/stdin keys "$root" "$legacy" "$class" 2>/dev/null <<'PHP'
<?php
foreach (['EConfigInvalid', 'Settings', 'Loader'] as $n) {
    require $argv[2] . '/classes/Config/' . $n . '.php';
}
require $argv[2] . '/' . $argv[3];
$class = $argv[4];
if (!class_exists($class, false)) {
    echo "problem=no-class\n";
    exit(3);
}
$unknown = array_diff(array_keys(get_object_vars(new $class())), Config\Settings::KEYS, ['app_path']);
echo 'unknown=', implode(',', $unknown), "\n";
PHP
    ;;

  fingerprint)
    quiet_php /dev/stdin fingerprint "$root" "$legacy" 2>/dev/null <<'PHP'
<?php
foreach (['EConfigInvalid', 'Settings', 'Loader'] as $n) {
    require $argv[2] . '/classes/Config/' . $n . '.php';
}
try {
    $settings = Config\Loader::load($argv[2]);
} catch (Config\EConfigInvalid $e) {
    // Its message names the key, never the value: that is the class's promise.
    echo 'refused=', $e->getMessage(), "\n";
    exit(4);
} catch (Throwable $e) {
    echo 'refused=', get_class($e), "\n";
    exit(4);
}
echo 'source=', is_file($argv[2] . '/site_config.php') ? 'site_config.php' : 'legacy', "\n";
echo 'fingerprint=', hash('sha256', serialize(get_object_vars($settings))), "\n";
PHP
    ;;

  generate)
    rm -f "$candidate"
    (
      umask 077
      set -o noclobber
      quiet_php /dev/stdin generate "$root" "$legacy" "$class" 2>/dev/null > "$candidate"
    ) <<'PHP'
<?php
require $argv[2] . '/' . $argv[3];
$class = $argv[4];
$values = get_object_vars(new $class());
unset($values['app_path']);
echo "<?php\n\nreturn ", var_export($values, true), ";\n";
PHP
    # shellcheck disable=SC2181  # the subshell's exit is the one wanted
    [ "$?" -eq 0 ] && [ -s "$candidate" ] || {
      rm -f "$candidate"
      echo "problem=not-written"
      exit 5
    }
    quiet_php -l "$candidate" >/dev/null 2>&1 || {
      rm -f "$candidate"
      echo "problem=does-not-parse"
      exit 5
    }
    quiet_php /dev/stdin candidate "$root" "$candidate" 2>/dev/null <<'PHP'
<?php
foreach (['EConfigInvalid', 'Settings', 'Loader'] as $n) {
    require $argv[2] . '/classes/Config/' . $n . '.php';
}
try {
    $settings = Config\Settings::fromArray(require $argv[3], $argv[2]);
} catch (Config\EConfigInvalid $e) {
    echo 'refused=', $e->getMessage(), "\n";
    exit(4);
} catch (Throwable $e) {
    echo 'refused=', get_class($e), "\n";
    exit(4);
}
echo 'candidate=', hash('sha256', serialize(get_object_vars($settings))), "\n";
PHP
    ;;

  place)
    test -f "$candidate" && test ! -e "$config" || {
      echo "problem=not-placed"
      exit 5
    }
    # A hard link appears whole or not at all; the copy is for a home
    # directory on another filesystem.
    ln "$candidate" "$config" 2>/dev/null || (
      umask 077
      set -o noclobber
      cat "$candidate" > "$config"
    ) || {
      echo "problem=not-placed"
      exit 5
    }
    rm -f "$candidate"
    echo "placed=yes"
    ;;

  move)
    test ! -e "$kept" && test -f "$old" && mv "$old" "$kept" && chmod 600 "$kept" || {
      echo "problem=not-moved"
      exit 5
    }
    echo "moved=yes"
    ;;

  put-back)
    rm -f "$candidate"
    if [ "$placed" = yes ]; then
      rm -f "$config" || {
        echo "problem=new-file-not-removed"
        exit 5
      }
    fi
    if test -e "$kept" && test ! -e "$old"; then
      mv "$kept" "$old" && chmod "$mode" "$old" || {
        echo "problem=old-file-not-moved-back"
        exit 5
      }
    fi
    echo "back=yes"
    ;;

  delete-old)
    test -e "$config" && test ! -e "$old" || {
      echo "problem=not-converted"
      exit 5
    }
    if test -e "$kept"; then
      rm -f "$kept" || {
        echo "problem=not-deleted"
        exit 5
      }
      echo "deleted=yes"
    else
      echo "deleted=none"
    fi
    echo "kept_by_hand=$(yn test -e "$HOME/Config.php.old")"
    ;;

  *)
    echo "problem=unknown-op"
    exit 2
    ;;
esac
