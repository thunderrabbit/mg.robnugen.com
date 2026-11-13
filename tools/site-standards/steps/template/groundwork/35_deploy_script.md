---
title: Deploys by git with its own deploy script
status: proven
whose_move: agent
check: deploy_script
---
The repo has a tracked, executable deploy script that does `git push`, then
`git pull` over ssh on the site's own server. Every later step ends with
Rob deploying and the scanner reading the commit the server runs. A site that
deploys with an scp watcher or by hand has no commit on its server to read,
so no step on it can be ticked, and the next agent has no answer to "how do
we deploy this?".

The script is named for its site: `deploy_<slug>_to_dh.sh`, where the slug
is the site's short name (`pond.example.com` is `pond`). The name tells Rob
the script was written for this repo, and tells him which repo he is in when
he types `deploy` and presses Tab.

The check reads what the script does, never what it is called or how it
spells its variables. A site that already has a script that pushes and pulls
by git to the right server has done this step. Leave that script as it is.

A deploy script runs `git restore .` on the server before it pulls, so any
edit made on the server itself is thrown away. That is safe only while
the site's `Config.php` and every data directory it writes are gitignored.

## How to do it

1. Look for a deploy script the site already has: `git ls-files 'deploy*.sh'`.
   - If it is named `deploy_<slug>_to_dh.sh`, keep the name and the text.
     Change only the lines the check names as missing. Do not replace it
     with base's.
   - If it has another name (`deploy_to_dh.sh`, `deploy_on_dh.sh`),
     `git mv` it to `deploy_<slug>_to_dh.sh` so its history follows, then
     fix every mention of the old name: `git grep -n <old name>`, and look
     in the site's `.claude/` too, since a skill there may run it. Keep the
     old name only when something outside the repo runs it by that name,
     and tell Rob what.
2. If the site has no deploy script, write `deploy_<slug>_to_dh.sh` from
   base's committed copy, not its filled-in working copy (base is the
   template's own checkout and hides that with skip-worktree):

       git -C ~/work/rob/base.robnugen.com show HEAD:deploy_to_dh.sh > deploy_<slug>_to_dh.sh
       chmod +x deploy_<slug>_to_dh.sh

   Fill in `DEST` with the site's ssh alias and `DEST_PATH` with its project
   root relative to `~`, from the site's server on mg. A server directory of
   `example.com/wwwroot` is a project root of `example.com`. Set
   `PULL_URL="origin"` if the server pulls with a deploy key, which is what
   new sites do. Leave it empty only for a public repo whose server has no
   GitHub key; it then pulls over https.
3. Whichever script the site ends with, it must: push the current branch
   with git, then run `git pull` over ssh after a `cd` to the project root
   on the site's ssh alias, and copy nothing with rsync or scp.
4. Check the site's `.gitignore` covers its `Config.php` and whatever the
   site writes on the server, because of `git restore .`.
5. Commit a new script as "Add a git deploy script", or a rename as its own
   commit. In a separate commit, make the deploy section of the site's
   `CLAUDE.md` and README name the script, and drop any mention of an scp
   watcher.
6. Do not run it. Deploying is Rob's. If the server is not a git checkout
   yet, the scanner skips the site with "could not ask its server which
   commit it runs"; turning it into one (git init, remote add, fetch,
   checkout in place, never rsync the tree) changes the server, so that is
   Rob's too, following base's README, *First install*.
