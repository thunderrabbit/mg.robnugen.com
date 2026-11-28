"""Tests for convert_site_config.py: run with
python3 -I -m unittest discover -s tools/site-standards/tests
from the mg checkout.

A scratch directory plays the server's home: the stand-in for ssh runs each
command there with bash, so the files really move. PHP is a stand-in too. It
answers from a line `// values: <token>` in whichever config file the site's
loader would read, which is all the script may learn about a config: a hash.
Site names, hosts and paths are made up."""

import hashlib
import json
import os
import stat
import subprocess
import sys
import unittest

HERE_TESTS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE_TESTS, ".."))
sys.path.insert(0, HERE_TESTS)
import convert_site_config as convert  # noqa: E402
from next_step_case import NextStepCase, GIT, write  # noqa: E402

SECRET = "hunter2-do-not-print"
LEGACY = "classes/Config/Config.php"
LOADER = ("<?php\nfinal class Loader\n{\n    public const string LEGACY_FILE = 'classes/Config/Config.php';\n"
          "    private const string LEGACY_CLASS = 'Config\\Config';\n}\n")

# PHP, as far as the remote script uses it. Knobs are files in $KNOBS.
FAKE_PHP = r"""#!/usr/bin/env bash
knob() { [ -f "$KNOBS/$1" ]; }
token() { sed -n 's|^// values: ||p' "$1" | head -1; }
args=()
while [ "$#" -gt 0 ]; do
  case "$1" in -d) shift 2 ;; *) args+=("$1"); shift ;; esac
done
set -- "${args[@]}"
echo "$*" >> "$KNOBS/php.log"
case "$1" in
  -r) printf '%s' "$(cat "$KNOBS/php-version")" ;;
  -l) knob lint-fails && exit 255; exit 0 ;;
  /dev/stdin)
    cat > /dev/null
    op="$2"; root="$3"
    case "$op" in
      keys)
        knob no-class && { echo "problem=no-class"; exit 3; }
        echo "unknown=$(cat "$KNOBS/unknown-keys" 2>/dev/null)" ;;
      generate)
        knob generate-fails && exit 1
        t="$(token "$root/$4")"
        knob generate-differs && t="other"
        knob stray-file && : > "$root/stray.txt"
        printf '<?php\n// values: %s\nreturn [];\n' "$t" ;;
      candidate)
        knob candidate-refused && { echo "refused=Config 'cookie_name' must be letters"; exit 4; }
        echo "candidate=$(printf '%s' "$(token "$4")" | sha256sum | cut -c1-64)" ;;
      fingerprint)
        knob refused && { echo "refused=Config 'dbPass' must not be empty"; exit 4; }
        if [ -f "$root/site_config.php" ]; then
          source=site_config.php; t="$(token "$root/site_config.php")"
          knob differs-once-placed && t="drifted"
          [ -f "$root/$4" ] || { knob differs-once-moved && t="drifted"; }
        elif [ -f "$root/$4" ]; then
          source=legacy; t="$(token "$root/$4")"
          knob differs-once-back && [ -f "$KNOBS/was-placed" ] && t="drifted"
        else
          echo "refused=No site_config.php in $root"; exit 4
        fi
        [ "$source" = site_config.php ] && : > "$KNOBS/was-placed"
        echo "source=$source"
        echo "fingerprint=$(printf '%s' "$t" | sha256sum | cut -c1-64)" ;;
    esac ;;
esac
"""


class ConvertSiteConfigTest(NextStepCase):
    def setUp(self):
        super().setUp()
        root = self.root
        write(os.path.join(self.repo, "classes/Config/Loader.php"), LOADER)
        write(os.path.join(self.repo, ".gitignore"), "/site_config.php\nclasses/Config/Config.php\n")
        for name in ("Settings", "EConfigInvalid"):
            write(os.path.join(self.repo, f"classes/Config/{name}.php"), "<?php\n")
        self.head = self.commit_all(self.repo, "Read the config through the loader")

        # The server: a home directory with the site's checkout in it.
        self.home_there = os.path.join(root, "server-home")
        self.there = os.path.join(self.home_there, "demo.example")
        os.makedirs(self.home_there)
        subprocess.run([*GIT, "clone", "-q", self.repo, self.there], check=True)
        self.old = os.path.join(self.there, LEGACY)
        write(self.old, f"<?php\n// values: {SECRET}\n")
        os.chmod(self.old, 0o644)
        self.new = os.path.join(self.there, "site_config.php")
        self.kept = os.path.join(self.home_there, "demo.example.Config.php.old")

        self.knobs = os.path.join(root, "knobs")
        os.makedirs(self.knobs)
        write(os.path.join(self.knobs, "php-version"), "8.4")
        php = os.path.join(root, "fake-php")
        write(php, FAKE_PHP, executable=True)
        ssh = os.path.join(root, "fake-server-ssh")
        write(ssh, "#!/usr/bin/env bash\n"
                   f"[ ! -f '{self.knobs}/unreachable' ] || {{ echo 'no route to host' >&2; exit 255; }}\n"
                   f"printf '%s\\n' \"$1\" >> '{self.knobs}/ssh.log'\n"
                   f"cd '{self.home_there}' && HOME='{self.home_there}' KNOBS='{self.knobs}' bash -c \"$2\"\n",
              executable=True)
        http = os.path.join(root, "fake-http")
        write(http, "#!/usr/bin/env bash\n"
                    f"printf '%s\\n' \"$1\" >> '{self.knobs}/http.log'\n"
                    f"n=$(grep -c . '{self.knobs}/http.log')\n"
                    f"if [ -f '{self.knobs}/http-'\"$n\" ]; then cat '{self.knobs}/http-'\"$n\"; else echo 200; fi\n",
              executable=True)
        patches = {"SCAN_SSH": ssh, "CONVERT_PHP_BIN": php, "CONVERT_HTTP": http}
        saved = {name: os.environ.get(name) for name in patches}
        os.environ.update(patches)
        self.addCleanup(self.restore, saved)

        self.mg_says(self.site([]), self.facts(
            server={"ssh_alias": "prod", "directory": "demo.example", "domain": "demo.example"}))
        self.fingerprint = hashlib.sha256(SECRET.encode()).hexdigest()[:8]

    def knob(self, name, text=""):
        write(os.path.join(self.knobs, name), text)

    def run_it(self, *argv, cwd=None):
        return convert.answer(list(argv), cwd=cwd or self.repo)

    def assert_untouched(self):
        self.assertTrue(os.path.isfile(self.old))
        self.assertFalse(os.path.exists(self.new))
        self.assertFalse(os.path.exists(self.kept))
        self.assertEqual(os.listdir(self.home_there), ["demo.example"])
        self.assertEqual(stat.S_IMODE(os.stat(self.old).st_mode), 0o644)
        with open(self.old, encoding="utf-8") as handle:
            self.assertEqual(handle.read(), f"<?php\n// values: {SECRET}\n")

    def not_ready(self, sentence, *argv):
        got = self.run_it(*argv)
        self.assertEqual(got, {"outcome": "not_ready", "say": "Nothing was changed. " + sentence})
        return got

    # ---- the whole conversion -----------------------------------------
    def test_a_dry_run_says_what_it_would_do_and_changes_nothing(self):
        got = self.run_it("--dry-run")
        self.assertEqual(got, {"outcome": "would_convert", "say": (
            "Ready. It would write `site_config.php` in `demo.example`'s checkout from the values of "
            "`classes/Config/Config.php`, compare the settings the site loads before and after, and move "
            "`classes/Config/Config.php` to `~/demo.example.Config.php.old`. Nothing was changed.")})
        self.assert_untouched()

    def test_it_converts_and_keeps_the_old_file(self):
        got = self.run_it()
        self.assertEqual(got, {"outcome": "converted", "say": (
            "`demo.example` now runs on `site_config.php`. The settings it loads are the same as before "
            f"(fingerprint `{self.fingerprint}`), `https://demo.example/` answers 200 as before and "
            "`https://demo.example/login/` answers 200 as before, and its checkout's `git status` is unchanged. "
            "`classes/Config/Config.php` is kept at `~/demo.example.Config.php.old`, mode 600. It holds the "
            "database password in plain text; deleting it is Rob's call.")})
        self.assertFalse(os.path.exists(self.old))
        self.assertEqual(stat.S_IMODE(os.stat(self.new).st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(os.stat(self.kept).st_mode), 0o600)
        with open(self.new, encoding="utf-8") as handle:
            self.assertIn(SECRET, handle.read())
        self.assertEqual(sorted(os.listdir(self.home_there)), ["demo.example", "demo.example.Config.php.old"])
        status = subprocess.run([*GIT, "-C", self.there, "status", "--porcelain"], capture_output=True, text=True)
        self.assertEqual(status.stdout, "")

    def test_no_output_ever_holds_a_config_value(self):
        script = os.path.join(HERE_TESTS, "..", "convert-site-config.sh")
        for argv, knob in ((["--dry-run"], None), ([], "differs-once-moved"), ([], None), (["--delete-old"], None)):
            if knob:
                self.knob(knob)
            done = subprocess.run([script, *argv], cwd=self.repo, capture_output=True, text=True)
            if knob:
                os.remove(os.path.join(self.knobs, knob))
            self.assertEqual(done.returncode, 0, done.stderr)
            self.assertNotIn(SECRET, done.stdout + done.stderr)
            self.assertIn(json.loads(done.stdout)["outcome"], ("would_convert", "put_back", "converted", "deleted_old"))

    def test_the_test_domain_is_converted_with_test(self):
        self.mg_says(self.site([]), self.facts(
            server={"ssh_alias": "elsewhere", "directory": "live.example", "domain": "live.example"},
            test={"domain": "demo.example", "php_version": "8.4", "ssh_alias": "prod", "directory": "demo.example"}))
        self.assertEqual(self.run_it("--test")["outcome"], "converted")
        with open(os.path.join(self.knobs, "ssh.log"), encoding="utf-8") as handle:
            self.assertEqual(set(handle.read().split()), {"prod"})

    # ---- what stops it before any change -------------------------------
    def test_a_site_with_no_test_domain_has_nothing_for_test(self):
        self.not_ready("`demo` has no test domain on mg, so `--test` has nothing to convert.", "--test")

    def test_several_sites_in_one_checkout_need_a_shortcode(self):
        self.mg_says(None, shortcodes=["demo", "demo-two"])
        self.not_ready("More than one site uses this checkout: `demo`, `demo-two`. Add `--shortcode=<code>`.")

    def test_a_repo_mg_does_not_know(self):
        self.mg_says(None)
        self.not_ready("This repo isn't on mg's standards page, so /next-mg-step has nothing for it.")

    def test_a_site_with_no_server(self):
        self.mg_says(self.site([]), self.facts(server=None, server_problem="no domain or hostname is linked to it"))
        self.not_ready("mg knows no server for `demo` (`no domain or hostname is linked to it`).")

    def test_a_server_that_is_not_dreamhost(self):
        self.mg_says(self.site([]), self.facts(
            server={"ssh_alias": "box", "directory": "/var/www/demo", "domain": "demo.example"}))
        self.not_ready("This script converts a DreamHost server only, and `demo`'s is not one.")

    def test_an_mg_that_names_no_domain(self):
        self.mg_says(self.site([]), self.facts())
        self.not_ready("mg did not say which domain `demo`'s server answers at, so its pages cannot be loaded.")

    def test_a_checkout_without_the_loader(self):
        os.remove(os.path.join(self.repo, "classes/Config/Loader.php"))
        self.not_ready("This checkout's `classes/Config/Loader.php` does not name a LEGACY_FILE and a LEGACY_CLASS; "
                       "the code step comes first.")

    def test_a_loader_naming_a_file_outside_the_checkout(self):
        write(os.path.join(self.repo, "classes/Config/Loader.php"), LOADER.replace("classes/Config/Config.php",
                                                                                  "../Config.php"))
        self.assertIn("does not name a LEGACY_FILE", self.run_it()["say"])

    def test_an_unknown_served_php(self):
        self.mg_says(self.site([]), self.facts(
            server={"ssh_alias": "prod", "directory": "demo.example", "domain": "demo.example"}, php_current=None))
        self.not_ready("mg has no sweep saying which PHP serves `demo.example`.")

    def test_a_served_php_below_8_4(self):
        self.mg_says(self.site([]), self.facts(
            server={"ssh_alias": "prod", "directory": "demo.example", "domain": "demo.example"}, php_current="8.3"))
        self.not_ready("`demo.example` is served on PHP `8.3`; the Config classes need 8.4 or newer.")
        self.assertFalse(os.path.exists(os.path.join(self.knobs, "ssh.log")))

    def test_uncommitted_changes_here(self):
        write(os.path.join(self.repo, "stray.txt"), "x\n")
        self.not_ready("This checkout has uncommitted changes.")

    def test_a_server_that_cannot_be_reached(self):
        self.knob("unreachable")
        self.not_ready("Its server did not say what it runs (`could not ask its server which commit it runs: "
                       "no route to host`).")

    def test_a_server_on_another_commit(self):
        self.commit(self.repo, "one more")
        local = subprocess.run([*GIT, "-C", self.repo, "rev-parse", "HEAD"], capture_output=True, text=True).stdout
        self.not_ready(f"`demo.example` runs `{self.head[:7]}` and this checkout is at `{local[:7]}`; "
                       "the server must run the code this checkout holds.")
        self.assert_untouched()

    def test_a_server_whose_php_is_not_the_served_one(self):
        self.knob("php-version", "8.2")
        php = os.environ["CONVERT_PHP_BIN"]
        self.not_ready(f"The server has no `{php}`, the PHP mg says serves `demo.example`.")

    def test_a_server_already_converted(self):
        write(self.new, "<?php\nreturn [];\n")
        self.not_ready("The server already has `site_config.php`.")

    def test_a_server_with_no_old_file(self):
        os.remove(self.old)
        self.not_ready("The server has no `classes/Config/Config.php` to convert.")

    def test_a_kept_copy_already_there(self):
        write(self.kept, "<?php\n")
        self.not_ready("`~/demo.example.Config.php.old` is already on the server, and a conversion would need "
                       "that name for the old file.")

    def test_tracked_changes_on_the_server(self):
        write(os.path.join(self.there, "classes/Config/Settings.php"), "<?php\n// edited on the server\n")
        self.not_ready("The server's checkout has changes to tracked files.")

    def test_untracked_files_on_the_server_are_allowed(self):
        write(os.path.join(self.there, "bootstrap_notes.txt"), "x\n")
        self.assertEqual(self.run_it()["outcome"], "converted")

    def test_keys_settings_does_not_know_are_named_and_stop_it(self):
        self.knob("unknown-keys", "stripe_key,telegram_token")
        self.not_ready("The old config has keys that `Config\\Settings` does not know: `stripe_key`, "
                       "`telegram_token`. The new file would be refused for them, so add them to Settings in the "
                       "code step first.")
        self.assert_untouched()

    def test_an_old_file_without_the_class(self):
        self.knob("no-class")
        self.not_ready("On the server, `classes/Config/Config.php` does not declare the class `Config\\Config` "
                       "that the loader names.")

    def test_an_old_config_the_loader_refuses(self):
        self.knob("refused")
        self.not_ready("The site's own loader refuses the old config as it stands: "
                       "`Config 'dbPass' must not be empty`.")

    def test_a_page_that_fails_before_anything_changes(self):
        self.knob("http-2", "503")
        self.not_ready("`https://demo.example/login/` answered 503.")
        self.assert_untouched()

    def test_a_page_that_does_not_answer(self):
        self.knob("http-1", "")
        self.not_ready("`https://demo.example/` did not answer.")

    def test_a_redirect_is_an_answer(self):
        self.knob("http-1", "302")
        self.knob("http-3", "302")
        self.assertIn("`https://demo.example/` answers 302 as before", self.run_it()["say"])

    # ---- a comparison that fails puts the site back --------------------
    def put_back(self, why):
        got = self.run_it()
        self.assertEqual(got, {"outcome": "put_back", "say": (
            f"{why} So the site was put back: `demo.example` runs on `classes/Config/Config.php` again, loading "
            f"the same settings as before (fingerprint `{self.fingerprint}`), and `site_config.php` is gone again.")})
        self.assert_untouched()

    def test_a_new_file_that_cannot_be_written(self):
        self.knob("generate-fails")
        self.put_back("The new file could not be written beside the checkout (not-written).")

    def test_a_new_file_that_does_not_parse(self):
        self.knob("lint-fails")
        self.put_back("The new file could not be written beside the checkout (does-not-parse).")

    def test_a_new_file_the_loader_refuses(self):
        self.knob("candidate-refused")
        self.put_back("The site's loader refuses the new file: `Config 'cookie_name' must be letters`.")

    def test_a_new_file_that_loads_differently_never_goes_in(self):
        self.knob("generate-differs")
        self.put_back("The new file would load to different settings than the old one.")
        with open(os.path.join(self.knobs, "php.log"), encoding="utf-8") as handle:
            self.assertEqual(handle.read().count("fingerprint"), 2)   # before, and after the put-back

    def test_settings_that_differ_once_the_file_is_in(self):
        self.knob("differs-once-placed")
        self.put_back("With `site_config.php` in place the site did not load the same settings from it.")

    def test_settings_that_differ_once_the_old_file_is_out(self):
        self.knob("differs-once-moved")
        self.put_back("With the old file moved out the site did not load the same settings.")

    def test_a_page_that_answers_differently_afterwards(self):
        self.knob("http-4", "503")
        self.put_back("`https://demo.example/login/` answered 200 before and 503 after.")

    def test_a_checkout_whose_git_status_changed(self):
        self.knob("stray-file")
        got = self.run_it()
        self.assertEqual(got["outcome"], "put_back")
        self.assertTrue(got["say"].startswith("The checkout's `git status` changed. So the site was put back:"))
        self.assertTrue(os.path.isfile(self.old))
        self.assertFalse(os.path.exists(self.new))
        self.assertFalse(os.path.exists(self.kept))

    def test_a_put_back_that_does_not_hold_needs_a_person(self):
        self.knob("differs-once-moved")
        self.knob("differs-once-back")
        got = self.run_it()
        self.assertEqual(got, {"outcome": "needs_a_person", "say": (
            "With the old file moved out the site did not load the same settings. Putting the site back did not "
            "work either (the settings it loads are not the ones from before). Someone must look at "
            "`demo.example`'s server now: `site_config.php` and `classes/Config/Config.php` in its checkout, and "
            "`~/demo.example.Config.php.old`.")})

    # ---- --delete-old --------------------------------------------------
    def test_delete_old_removes_the_kept_copy_of_a_converted_site(self):
        self.run_it()
        got = self.run_it("--delete-old")
        self.assertEqual(got, {"outcome": "deleted_old",
                               "say": "`~/demo.example.Config.php.old` is deleted from `demo.example`'s server."})
        self.assertFalse(os.path.exists(self.kept))
        self.assertTrue(os.path.isfile(self.new))

    def test_delete_old_leaves_a_site_that_is_not_converted(self):
        write(self.kept, "<?php\n")
        self.not_ready("The server is not on `site_config.php` alone yet, so the kept copy stays.", "--delete-old")
        self.assertTrue(os.path.isfile(self.kept))

    def test_delete_old_leaves_a_copy_made_by_hand_and_says_so(self):
        self.run_it()
        os.rename(self.kept, os.path.join(self.home_there, "Config.php.old"))
        self.not_ready("There is no `~/demo.example.Config.php.old` on `demo.example`'s server. A "
                       "`~/Config.php.old` made by hand is there; this script leaves it, so deleting it is yours.",
                       "--delete-old")
        self.assertTrue(os.path.isfile(os.path.join(self.home_there, "Config.php.old")))

    def test_delete_old_waits_for_pages_that_answer(self):
        self.run_it()
        self.knob("http-5", "500")
        self.not_ready("`https://demo.example/` answered 500.", "--delete-old")
        self.assertTrue(os.path.isfile(self.kept))

    def test_two_options_that_do_not_go_together_are_a_usage_error(self):
        with self.assertRaises(convert.next_step.Usage):
            self.run_it("--dry-run", "--delete-old")
        with self.assertRaises(convert.next_step.Usage):
            self.run_it("--force")


if __name__ == "__main__":
    unittest.main()
