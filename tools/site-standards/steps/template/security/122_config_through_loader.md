---
title: The site reads its config through Config\Loader
status: proven
whose_move: agent
check: config_through_loader
deploys: agent
---
A site's configuration was a class under `classes/`, edited by hand on each
server. A misspelled property was a new property nobody read, so a typo in
`allow_registration` left sign-up at its default without a word. A new setting
had to be added to every server's copy, or read with `$config->x ?? default`
in the code, with the default repeated wherever it was read.

After this step the server's values are an array in `site_config.php`, in the
project root above the web root, and `Config\Loader` turns it into a final,
read-only `Config\Settings`. Settings holds every default in one place and
refuses an unknown key, a wrong type, an empty credential or a bad cookie
name. A refused config answers 503 and the error log names the key, never its
value.

This step changes code only. The loader still reads the old class file while
a server has no `site_config.php`, so deploying it changes nothing a visitor
sees. The next step moves the server over.

The Config classes use PHP 8.4 syntax. A site served on anything older waits
for its PHP before it takes this step.

The check reads tracked files: `classes/Config/Loader.php` and
`classes/Config/Settings.php` exist, `site_config.sample.php` exists and
`.gitignore` lists `site_config.php`, no PHP outside the tests builds or
imports the old class (the one `Loader`'s `LEGACY_CLASS` names), and no PHP
reads `$config->something ??`.

## How to do it

The template is `thunderrabbit/new-DH-user-site`; this is its bubble
`BEGIN validated, immutable site config` on the branch `site-config` (commits
85f91be to c591379). One bubble, opened by a BEGIN commit that only raises the
site's version.

1. **Compare first.** List what this site's old config class holds that the
   template's did not: its own properties, where its file is, and what its
   class is called. A site that grew its own settings is the usual case, and
   the next step refuses to convert a server whose old config has a key
   `Settings` does not know.
2. Bring in `Config\Settings`, `Config\Loader` and `Config\EConfigInvalid`
   from the template with their tests (`SettingsTest`, `LoaderTest`, and the
   `TestSettings` helper the other tests build a config with). Keep
   `Settings::KEYS` and the three file names: the next step's script reads
   them.
3. Make them this site's:
   - Add each of the site's own settings to `Settings`: a constructor
     property with its type and default, its name in `KEYS`, and its line in
     `fromArray()`. A secret gets `#[\SensitiveParameter]` and a line in
     `__debugInfo()`. Give a default to anything a server may leave out, and
     none to what every server must set.
   - Set `Loader::LEGACY_FILE` and `LEGACY_CLASS` to where this site's old
     file and class really are. Write them as the template does, one
     single-quoted string each, because the checks and the script read those
     two lines.
   - `app_path` is no longer a key. The loader passes the project root.
4. Replace the sample class with `site_config.sample.php`, listing the
   required keys and, commented out, the optional ones with their defaults.
   Add `/site_config.php` to `.gitignore`.
5. Load the config through the loader everywhere:
   - every `new` of the old class becomes `\Config\Loader::load($root)`, in
     `prepend.php`, in the pages that run without it, and in every
     command-line tool;
   - every type hint and `@var` of the old class becomes `\Config\Settings`;
   - every `$config->x ?? y` loses its default, which now lives in Settings;
   - an `EConfigInvalid` at startup answers 503 and logs its message, as the
     template's `prepend.php` does.
6. Code that must also run on an older PHP elsewhere cannot load these
   classes. Leave it reading plain values it is handed, and say so in the
   site's CLAUDE.md.
7. Document it in the site's CLAUDE.md and README: `site_config.php`, the
   sample, and that the old file still works until the server converts.
8. Run the site's gates, then this step's check.

Leave the fallback to the old file in. It is what makes this deploy safe, and
it goes only when every site has converted.
