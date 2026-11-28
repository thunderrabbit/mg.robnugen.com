"""db-open.sh against a made-up server: a fake ssh that runs the PHP reader
here, a fake mg, and stand-ins for DBeaver and mysql that record how they
were started. No test reaches a real server or holds a real password.

    python3 -I -m unittest discover -s tools/db-open/tests
"""
import json
import os
import shutil
import stat
import subprocess
import tempfile
import time
import unittest

SCRIPT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "db-open.sh")

# Awkward on purpose: every character here has broken a script before.
PASSWORD = "it's pa$s w\\ord|x=y \"q\""

ARRAY_CONFIG = """<?php
return [
    'dbHost' => 'db.example.test',
    'dbName' => 'exampledb',
    'dbUser' => "example_user",
    'dbPass' => 'it\\'s pa$s w\\\\ord|x=y "q"',
    'domain_name' => 'example.test',
];
"""

CLASS_CONFIG = """<?php
%s
class Config
{
    public string $dbHost = 'db.example.test';
    public string $dbName = 'exampledb';
    public string $dbUser = "example_user";
    public string $dbPass = 'it\\'s pa$s w\\\\ord|x=y "q"';
    public string $app_path = '/home/someone/example.test';
}
"""

FAKE_SSH = """#!/bin/bash
# Plays the site's server: logs the call, and runs a PHP command in its home.
printf 'ssh %s\\n' "$*" >> "$DB_OPEN_LOG"
shift
case "$1" in
    -*) exit 0 ;;   # the tunnel: -f -N ... ; nothing to do
esac
cd "$FAKE_HOME" && bash -c "${1/#\\/usr\\/local\\/php[0-9]*\\/bin\\/php/php}"
"""

FAKE_DBEAVER = """#!/bin/bash
{
    echo "dbeaver"
    printf 'arg %s\\n' "$@"
    echo "mode $(stat -c %a "$2")"
    echo "file $(cat "$2")"
} >> "$DB_OPEN_LOG"
"""

FAKE_MYSQL = """#!/bin/bash
{
    echo "mysql"
    printf 'arg %s\\n' "$@"
    file="${1#--defaults-extra-file=}"
    echo "mode $(stat -c %a "$file")"
    sed 's/^/file /' "$file"
} >> "$DB_OPEN_LOG"
"""


@unittest.skipUnless(shutil.which("php"), "needs a php to play the server's")
class DbOpenTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="db-open-test-")
        self.addCleanup(shutil.rmtree, self.tmp, ignore_errors=True)
        self.bin = self.path("bin")
        self.home = self.path("home")
        self.runtime = self.path("runtime")
        self.log = self.path("log")
        self.checkout = self.path("work", "rob", "example.test")
        for directory in (self.bin, self.runtime, self.checkout, os.path.join(self.home, "example.test", "wwwroot")):
            os.makedirs(directory)
        subprocess.run(["git", "init", "-q", self.checkout], check=True)
        # dbeaver-ce is the name the script looks for first: a stand-in under any
        # other name would lose to a real DBeaver installed on this machine.
        for name, text in (("ssh", FAKE_SSH), ("dbeaver-ce", FAKE_DBEAVER), ("mysql", FAKE_MYSQL)):
            self.executable(os.path.join(self.bin, name), text)
        self.mg_says(self.facts())

    def path(self, *parts):
        return os.path.join(self.tmp, *parts)

    def executable(self, path, text):
        with open(path, "w") as handle:
            handle.write(text)
        os.chmod(path, os.stat(path).st_mode | stat.S_IXUSR)

    def facts(self, directory="example.test/wwwroot", php_current="", shortcodes=(), site=True):
        return {
            "site": {"shortcode": "ex"} if site else None,
            "shortcodes": list(shortcodes),
            "facts": {"shortcode": "ex", "server": {"ssh_alias": "example-alias", "directory": directory},
                      "server_problem": None, "php_current": php_current},
            "steps_commit": "0" * 40,
        }

    def mg_says(self, reply):
        self.executable(self.path("fake-mg"), "#!/bin/bash\ncat <<'JSON'\n" + json.dumps(reply) + "\nJSON\n")

    def server_has(self, relative, text):
        path = os.path.join(self.home, "example.test", relative)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w") as handle:
            handle.write(text)

    def array_config(self):
        self.server_has("site_config.php", ARRAY_CONFIG)

    def old_class(self, relative="classes/Config/Config.php", namespace="namespace Config;"):
        self.server_has(relative, CLASS_CONFIG % namespace)

    def run_script(self, *arguments, wait=0.0):
        environment = {
            "PATH": self.bin + os.pathsep + os.environ["PATH"],
            "HOME": self.home,
            "WORK_ROOT": self.path("work"),
            "XDG_RUNTIME_DIR": self.runtime,
            "SCAN_SITE_CMD": self.path("fake-mg"),
            "FAKE_HOME": self.home,
            "DB_OPEN_LOG": self.log,
            "DB_OPEN_VARS_SECONDS": "1",
        }
        done = subprocess.run(["bash", SCRIPT, *arguments], cwd=self.checkout, env=environment,
                              capture_output=True, text=True, stdin=subprocess.DEVNULL, timeout=30)
        time.sleep(wait)
        return done

    def logged(self):
        if not os.path.exists(self.log):
            return []
        with open(self.log) as handle:
            return handle.read().splitlines()

    def started_dbeaver_with(self):
        """What DBeaver was given: its arguments, and the password file's mode and content."""
        lines = self.logged()
        self.assertIn("dbeaver", lines)
        return lines[lines.index("dbeaver") + 1:]

    def test_opens_dbeaver_from_the_array_file(self):
        self.array_config()
        done = self.run_script(wait=0.5)
        self.assertEqual(0, done.returncode, done.stderr)
        self.assertIn("settings from site_config.php", done.stdout)
        given = self.started_dbeaver_with()
        self.assertEqual("arg -vars", given[0])
        self.assertEqual("arg -con", given[2])
        self.assertRegex(given[3], r"^arg driver=mysql\|host=127\.0\.0\.1\|port=\d+\|database=exampledb"
                                   r"\|user=example_user\|password=\$\{db_open_password\}"
                                   r"\|name=ex\|save=false\|connect=true$")
        self.assertEqual("mode 600", given[4])

    def test_the_password_reaches_dbeaver_only_through_the_file(self):
        self.array_config()
        done = self.run_script(wait=0.5)
        given = self.started_dbeaver_with()
        # A backslash is doubled, as a properties file needs; nothing else changes.
        self.assertEqual("file db_open_password=" + PASSWORD.replace("\\", "\\\\"), given[5])
        for line in self.logged():
            if line.startswith(("arg ", "ssh ")):
                self.assertNotIn("pa$s", line)
        self.assertNotIn("pa$s", done.stdout + done.stderr)

    def test_the_password_file_is_removed(self):
        self.array_config()
        self.run_script(wait=0.3)
        self.assertEqual(1, len(os.listdir(self.runtime)))
        time.sleep(1.5)
        self.assertEqual([], os.listdir(self.runtime))

    def test_reads_the_old_class_where_there_is_no_array_file(self):
        self.old_class()
        done = self.run_script(wait=0.5)
        self.assertIn("settings from classes/Config/Config.php", done.stdout)
        self.assertIn("database=exampledb", self.started_dbeaver_with()[3])

    def test_reads_a_class_in_the_root_namespace(self):
        self.old_class("classes/Config.php", "")
        done = self.run_script(wait=0.5)
        self.assertIn("settings from classes/Config.php", done.stdout)
        self.assertIn("user=example_user", self.started_dbeaver_with()[3])

    def test_prefers_the_array_file_as_the_sites_loader_does(self):
        self.array_config()
        self.old_class()
        done = self.run_script("--dry-run")
        self.assertIn("read from site_config.php", done.stdout)

    def test_a_converted_site_opens_exactly_as_before(self):
        self.old_class()
        self.run_script(wait=0.5)
        before = self.started_dbeaver_with()
        os.remove(self.log)
        os.remove(os.path.join(self.home, "example.test", "classes/Config/Config.php"))
        self.array_config()
        self.run_script(wait=0.5)
        after = self.started_dbeaver_with()
        self.assertEqual(before[3:], after[3:])   # the connection, the mode, the password

    def test_dry_run_opens_nothing_and_names_no_value(self):
        self.array_config()
        done = self.run_script("--dry-run")
        self.assertEqual(0, done.returncode, done.stderr)
        self.assertRegex(done.stdout, r"^ex: dbHost, dbUser, dbPass and dbName read from site_config\.php\. "
                                      r"Would open exampledb through 127\.0\.0\.1:\d+\.\n$")
        self.assertNotIn("dbeaver", self.logged())
        self.assertEqual(1, len([line for line in self.logged() if line.startswith("ssh ")]))
        self.assertEqual([], os.listdir(self.runtime))

    def test_mysql_reads_the_password_from_a_file_it_removes(self):
        self.array_config()
        done = self.run_script("--mysql")
        self.assertEqual(0, done.returncode, done.stderr)
        lines = self.logged()
        given = lines[lines.index("mysql") + 1:]
        self.assertRegex(given[0], r"^arg --defaults-extra-file=.*\.cnf$")
        self.assertEqual(["arg -A", "arg -h", "arg 127.0.0.1", "arg -P"], given[1:5])
        self.assertEqual("arg exampledb", given[6])
        self.assertEqual("mode 600", given[7])
        self.assertIn('file password="' + PASSWORD.replace("\\", "\\\\").replace('"', '\\"') + '"', given)
        self.assertEqual([], os.listdir(self.runtime))

    def test_uses_the_served_php_when_mg_knows_it(self):
        self.array_config()
        self.mg_says(self.facts(php_current="8.5"))
        self.run_script("--dry-run")
        self.assertIn("ssh example-alias /usr/local/php85/bin/php -- example.test/wwwroot", self.logged())

    def test_names_a_missing_key_and_no_value(self):
        self.server_has("site_config.php", "<?php\nreturn ['dbHost' => 'db.example.test', 'dbUser' => 'u', 'dbPass' => ''];\n")
        done = self.run_script()
        self.assertEqual(1, done.returncode)
        self.assertEqual("ex: site_config.php has no value for: dbPass dbName", done.stderr.splitlines()[-1])
        self.assertNotIn("dbeaver", self.logged())

    def test_a_config_that_does_not_parse_says_only_where(self):
        self.server_has("site_config.php", "<?php\nreturn [\n    'dbPass' => 'secret-on-the-broken-line\n];\n")
        done = self.run_script()
        self.assertEqual(1, done.returncode)
        self.assertIn("site_config.php could not be read, line", done.stderr)
        self.assertNotIn("secret-on-the-broken-line", done.stdout + done.stderr)

    def test_stops_where_the_server_has_no_config(self):
        done = self.run_script()
        self.assertEqual(1, done.returncode)
        self.assertIn("No site_config.php or old config class at or above example.test/wwwroot", done.stderr)
        self.assertEqual("The site's server gave no database settings.", done.stderr.splitlines()[-1])

    def test_stops_for_a_checkout_that_is_no_site(self):
        self.mg_says(self.facts(site=False))
        done = self.run_script()
        self.assertEqual("This checkout is not a site on the standards page.", done.stderr.splitlines()[-1])

    def test_stops_for_a_site_that_is_not_on_dreamhost(self):
        self.mg_says(self.facts(directory="/var/www/example.test/current"))
        done = self.run_script()
        self.assertEqual("This site is not on DreamHost, and this opens DreamHost databases only.",
                         done.stderr.splitlines()[-1])
        self.assertEqual([], self.logged())

    def test_asks_which_site_when_a_checkout_holds_several(self):
        self.mg_says(self.facts(shortcodes=("ex", "ex2")))
        done = self.run_script()
        self.assertEqual("This checkout holds several sites. Add --shortcode= with one of: ex, ex2",
                         done.stderr.splitlines()[-1])

    def test_refuses_two_modes(self):
        done = self.run_script("--mysql", "--dry-run")
        self.assertEqual("Give one of --mysql, --dry-run and --close, not two.", done.stderr.splitlines()[-1])

    def test_close_asks_no_server_for_a_password(self):
        done = self.run_script("--close")
        self.assertEqual("No tunnel was open for ex.\n", done.stdout)
        self.assertEqual([], self.logged())


if __name__ == "__main__":
    unittest.main()
