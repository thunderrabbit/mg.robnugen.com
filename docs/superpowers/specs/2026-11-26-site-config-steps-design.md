# Steps: a site's config moves out of the class tree

2026-11-26, built 2026-11-28. Source: jikan message 2977 (the template
session's hand-over). Where the build differs from the first design, this
says what was built.

## Intent

Rob is raising the security of his sites. The template
(`thunderrabbit/new-DH-user-site`, branch `site-config`, commits
`85f91be..c591379`) moved a site's configuration from a class under
`classes/` to `site_config.php` in the project root, read through
`Config\Loader` into a final readonly `Config\Settings`. A typo, an unknown
key, a wrong type or an empty credential now answers 503 and names the key in
the log, never its value. It was proven on one site on 2026-11-26.

This design turns that upgrade into mg steps any site's session can apply
through `/next-mg-step`, with a script doing everything that can be scripted
and a model overseeing. mg is the first practice run after the steps exist.

## Goals

- Three steps in the Security track that take a site from the old class file
  to `site_config.php`, each ticked by a check.
- The server-side conversion is one script that either converts the site or
  puts it back exactly as it was. No model types a command on a server.
- For these steps the session deploys by itself (Rob chose this, 2026-11-26).
- The steps work for a site that differs from the template: its own config
  keys, its old file somewhere else, its old class under another name. mg is
  such a site.

## Non-goals

- Applying the steps to mg or any site. That is the practice run, through
  `/next-mg-step`, after this bubble is deployed.
- Sites on EC2. The conversion script handles a DreamHost server only and
  says so for any other.
- Updating the `setup-this-new-site` skill. It follows once the template's
  `site-config` bubble reaches the template's master.
- Letting sessions deploy for every step. Only a step that asks for it.

## Anti-goals

- A config value (a password, a key) appearing in any output, log, memo,
  issue or commit. Scripts print file names, key names and hashes only.
- A site left half converted. Any failed comparison ends with the site on its
  old config.
- Reading the copies Rob made under `unused/2026_nov_26/` on mg's server, or
  any other backup of a config file.

## Constraints

- This repo is public: step files, tests and comments name no site but `mg`.
- A check prints one line, exits 0, 1 or 2, and never writes.
- A check that asks a server carries no `# memo: commit` line.
- The script and the checks run on Lemur 13 and reach a server over ssh by the
  alias and directory mg holds for the site (`SITE_SERVER_SSH_ALIAS`,
  `SITE_SERVER_DIRECTORY`).
- The served PHP on DreamHost is `/usr/local/php<NN>/bin/php`; the shell's
  `php` is older and must not be used.
- `next-step.sh` itself never writes to a repo or a server. It prints what to
  run; the session runs it.

## Approaches

**Who deploys, and who converts the server.** Three were put to Rob:

- A. The session stops after the code step; Rob deploys; the session runs the
  conversion script.
- B. The session deploys too, as the template's session did on its own site.
- C. Rob runs the script himself; no session ever writes on a server.

Rob picked **B**.

**How the server work is done** (my call, since Rob asked for determinism):

- A how-to listing shell commands for the model to type. This is what message
  2977 proposes. Rejected: six steps with a rollback is exactly where a model
  skips a comparison.
- One script that does all of it and rolls back by itself. **Chosen.**
- Putting the conversion in the site's own code (a page or a CLI tool shipped
  by the template). Rejected: every site would need the tool before it could
  convert, and it would have to be removed afterwards.

**How "the session deploys" is scoped** (my call):

- Change the brief's "never push" for every step. Rejected: Rob's choice was
  made for this upgrade, and "Rob approves by deploying" still describes
  every other step.
- A line in a step's front matter, `deploys: agent`. Only such a step tells
  the session to deploy. **Chosen.**

## The three steps

All in `tools/site-standards/steps/template/security/`, numbered between
`120_no_debug_parameter` and `130_host_serves_php_8_6`: none needs PHP 8.6.
The names after the number are permanent.

| File | Step | Whose move | Model | Status |
|---|---|---|---|---|
| `122_config_through_loader.md` | The site reads its config through `Config\Loader` | agent, `deploys: agent` | track's (opus) | proven |
| `124_server_on_site_config.md` | The server runs on `site_config.php` | agent | track's (opus) | proven |
| `126_old_config_file_deleted.md` | The old config file is gone from the server | rob | none | idea |

126 is an idea until Rob deletes the kept copy on the site that proved the
other two; then it becomes proven like any step.

### 122 `config_through_loader` (code, in the checkout)

How-to, in outline: one bubble; bring in `Config\Settings`, `Config\Loader`
and `Config\EConfigInvalid` from the template's commits with their tests;
`site_config.sample.php` replaces the sample class; `site_config.php` goes in
`.gitignore`; every `new` of the old class becomes `Config\Loader::load()`;
every type hint of the old class becomes `\Config\Settings`; every
`$config->x ?? y` read loses its default because `Settings` holds it;
`app_path` is no longer a key. Then the two things a site that differs must
do: add its own keys to `Settings` (with types and defaults), and set
`Loader::LEGACY_FILE` and `LEGACY_CLASS` to where its old file and class
really are. The fallback to the old file stays in, so deploying this step
changes nothing a visitor sees.

Check `config_through_loader.sh` (reads tracked files, `# memo: commit`):

- `classes/Config/Loader.php` and `classes/Config/Settings.php` are tracked;
- `site_config.sample.php` is tracked and `site_config.php` is ignored;
- no tracked PHP outside the tests instantiates the old class (the class
  `Loader::LEGACY_CLASS` names);
- no tracked PHP reads `$config->something ??`.

After `--confirm` is clean and the bubble is closed, the session deploys
(below) and runs the scan, because step 124 needs this code on the server.

### 124 `server_on_site_config` (the server, by script)

No commit in the site's repo. The how-to is: run
`tools/site-standards/convert-site-config.sh --dry-run`, read its line, run it
without `--dry-run`, read its line, then the check, then
`tools/site-standards/scan.sh <shortcode>` so the page shows it. Nothing is
deployed in this step, so `next_step.py` needs no change for it.

Check `server_on_site_config.sh` (asks the server, no memo): done when
`site_config.php` is a file in the server directory and the file
`Loader::LEGACY_FILE` names is not. Not yet when the old file is still there.
Could not tell when the server gives no answer or is not a DreamHost one. It
asks through a new read-only `server_facts.py config ALIAS DIRECTORY
LEGACY_FILE`, which prints two words (`site_config: yes|no`, `legacy:
yes|no`) from `test -f`, never file contents.

A site whose `Loader` no longer names a `LEGACY_FILE` gets "could not tell":
the fallback's removal, once every site has converted, changes this check.

### 126 `old_config_file_deleted` (Rob's)

The conversion keeps the old file in the server user's home (mode 600, named
for the checkout; see the script) so a site can be put back. It holds a plaintext database password. Rob deletes it once
he is satisfied, by hand or with `convert-site-config.sh --delete-old`, which
a session runs only on his say-so.

Check `old_config_file_deleted.sh` (asks the server, no memo): done when the
server has `site_config.php` and no kept file. Not yet otherwise.
The same `server_facts.py config` answers it with a third word (`kept:
yes|no`), which is yes for the script's kept file and also for a
`~/Config.php.old` left by a conversion done by hand. The first site
converted has one, and without this the step would tick there with the
password still in the home directory.

## The conversion script

`tools/site-standards/convert-site-config.sh [--dry-run | --delete-old]`,
logic in `convert_site_config.py`, run from inside the site's checkout like
`next-step.sh`. It finds the site, its server alias, directory, domain and
served PHP the way `next_step.py` does, and reads `LEGACY_FILE` and
`LEGACY_CLASS` from the checkout's `classes/Config/Loader.php`. mg's facts
gained the server's `domain` for this (`ServerChoice::$domain`).

What runs on the server is `convert_site_config_remote.sh`, sent as the
argument of `bash -c`, one operation a call (`look`, `keys`, `fingerprint`,
`generate`, `place`, `move`, `put-back`, `delete-old`). It prints `name=value`
lines of yes, no, key names, hashes, a PHP version and a file mode. The
Python reads those lines and drops anything else the server printed. PHP
runs there with `display_errors` off, since a parse error quotes the code it
stopped on.

It stops before touching anything unless all of these hold:

1. the checkout is clean and the server runs exactly its commit;
2. the server is a DreamHost one (a home-relative directory);
3. on the server: `classes/Config/Loader.php` exists, the legacy file exists,
   `site_config.php` does not, the kept file (below) does not;
4. the server's checkout has no change to a tracked file. Untracked files are
   allowed; `git status --porcelain` is recorded to compare later;
5. every property of the old class except `app_path` is a key `Settings`
   knows. The loader forgives an unknown one in the old file but refuses it
   in the new, so the script stops here and names the key (never its value):
   the site's step 122 missed it;
6. the fingerprint (below) can be taken, which proves the old config passes
   every check `Settings` makes, `cookie_name` included;
7. the served PHP is 8.4 or newer (the `Config\` classes use 8.4 syntax);
8. `https://<domain><prefix>/` and `.../login/` each answer 200 or a
   redirect, never a 5xx. Redirects are not followed; the two status codes
   are recorded to compare later.

The project root is the git top level of the server directory, since a
site's web directory may be a subdirectory of its checkout. The domain and
prefix are those of the site's server link.

**The kept file** is `~/<project root's name>.Config.php.old`, mode 600, in
the server user's home. Named for the checkout so that two checkouts under
one DreamHost user (a site and its test domain, or two sites) never share
one.

`--dry-run` stops here and says what it would do.

Then, each step followed by a comparison:

1. Generate the new file with the served PHP from the old class's values
   without `app_path`, as a candidate in the home directory
   (`~/<project root's name>.site_config.php.candidate`, `noclobber`,
   `umask 077`); lint it; hash the settings `Settings::fromArray()` builds
   from it. That hash must equal the first fingerprint, so a file that would
   load differently never reaches the checkout.
2. Put the candidate in as `site_config.php` (a hard link, so it appears
   whole). Fingerprint: equal, and read from `site_config.php`.
3. Move the legacy file to the kept file.
4. Fingerprint again (equal, read from `site_config.php`), both URLs answer
   with the status codes recorded before, and a hash of
   `git status --porcelain` is what it was before.

On any failure after step 1 began: remove the candidate and
`site_config.php`, move the kept file back if it was moved (with the mode it
had), take the fingerprint once more, and
report that the site is on its old config and which comparison failed. If
the put-back itself fails, say so in plain words and stop; that is the one
case a person must look at.

**The fingerprint** is a PHP script piped to the served PHP over ssh. It
requires the three `classes/Config/` files by hand (no autoloader, so it does
not depend on how a site loads classes), calls `Config\Loader::load()` on the
project root, and prints which file it read and
`sha256(serialize(get_object_vars($settings)))`. Never a value.

**Output:** one JSON object, `outcome` one of `converted`, `would_convert`,
`not_ready`, `put_back`, `needs_a_person`, `deleted_old`, with a `say`
sentence. Sentences are pinned word for word in tests, as `next_step.py`'s
are.

`--delete-old` removes the kept file only when the server has
`site_config.php`, has no legacy file, and both URLs answer without a 5xx.
It never deletes a `~/Config.php.old` made by hand; it says one is there.

`--test` does all of the above to the site's test domain's checkout instead
of production's, for a site that has one. Step 124's how-to says to convert
the test domain first. The checks look at production only.

## The session deploys (`deploys: agent`)

`StepTree` accepts an optional front-matter line `deploys: agent` on a step
whose `whose_move` is `agent`, and refuses any other value. For such a step:

- The brief gets one more rule, from `next_step_brief.md`: this step ends
  with a deploy, done by the session and never by a subagent, only after
  `--confirm` says clean and any bubble is closed.
- `next-step.sh --confirm`, when clean, adds a `deploy` list to its answer:
  the repo's tracked, executable `deploy_to_dh.sh` or `deploy_<name>_to_dh.sh`
  in its root, when there is exactly one, then
  `tools/site-standards/scan.sh <shortcode>`. Its `say` names what will be
  run. The file's name decides, not the `deploy_script` check: mg's own
  script works and that step is NAh for mg.
- The skill gains one short section: when a confirm answer has `deploy` and
  the bubble is closed, run those commands in order and show each one's last
  line. The skill's last line stays "Never push" with "except through a
  `deploy` the script gave".
- A site with no such script, or more than one, gets no `deploy`; the answer
  says Rob deploys, as today.
- A site whose served PHP (as the latest sweep saw it) is below 8.4 gets no
  `deploy` either, and the answer says why: this code would not run there.
  Step 122's explanation says the same, so such a site waits for its PHP.

The deploy script pushes the current branch and pulls it on the server. The
trunk is still only Rob's to advance; the session deploys the topic branch.

## Who deploys this bubble itself

Rob. A step counts only once its file is deployed on mg, and that deploy is
his approval of the step. The session's own deploys start with the practice
run that follows.

## The practice run on mg (what follows, not built here)

mg's old file is `classes/Config.php` and its class is `\Config`, in the root
namespace. It has keys the template lacks (Stripe, Telegram,
`image_store_path`), about a hundred files name the class, six command-line
tools and two pages build it with `new \Config()`, and `classes/Journal/*`
must stay runnable on PHP 8.1. So step 122 on mg is a real port, which is the
point of practising here. What the practice run teaches goes back into the
how-to before any other site takes the step.

## Tests

- `tests/test_convert_site_config.py`: a fake ssh (`SCAN_SSH`) plays a
  server; one test per stop, one per failed comparison showing the put-back,
  one for the full conversion, one for `--delete-old`, and one that no output
  ever contains a planted secret value.
- `tests/test_server_facts.py`: the `config` fact.
- `tests/test_next_step_confirm.py`: the `deploy` list appears only for a
  `deploys: agent` step with a clean result and an accepted deploy script.
- `tests/test_memo_headers.py`: the two server-asking checks are named as
  having no memo.
- Codeception Unit: `StepTree` reads and validates `deploys:`.
- Each check is run by hand against the template's checkout on its
  `site-config` branch (pass) and against mg (not yet).

## Decided while building

- `deploys:` has no database column. `StepTree` checks the line and
  `next_step.py` reads it from the step file at the scanned commit, as it
  reads `model:`.
- A clean confirm that lists a `deploy` leaves out `after`: the session runs
  the scan, so there is nothing to remind Rob of.
- `convert_site_config.py` imports `next_step`, `scan` and `server_facts` for
  the lookup, the git calls and the server's commit, and has its own ssh call
  for the remote script.
- The tests move real files on a scratch server with a stand-in PHP
  (`CONVERT_PHP_BIN`, `CONVERT_HTTP` and `SCAN_SSH` are the stand-ins' way
  in). One run by hand used real PHP 8.5 in Docker on a copy of the template.

## Not built

- EC2 servers. The two server-asking checks answer "could not tell" there.
- The practice run on mg, which follows this bubble's deploy.
