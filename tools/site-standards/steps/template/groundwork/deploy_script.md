---
title: Deploys with deploy_to_dh.sh
position: 35
status: proven
whose_move: agent
check: deploy_script
---
The repo has base.robnugen.com's `deploy_to_dh.sh`, filled in for the site's
own server: `git push`, then `git pull` over ssh. Every later step ends with
Rob deploying and the scanner reading the commit the server runs. A site that
deploys with an scp watcher or by hand has no commit on its server to read,
so no step on it can be ticked, and the next agent has no answer to "how do
we deploy this?".

The script runs `git restore .` on the server before it pulls, so any edit
made on the server itself is thrown away. That is safe only while
the site's `Config.php` and every data directory it writes are gitignored.

## How to do it

1. If the site already has a deploy script under another name
   (`deploy_on_dh.sh`, `deploy_<site>_to_dh.sh`), `git mv` it to
   `deploy_to_dh.sh` first, so its history follows.
2. Copy base's committed script, not its filled-in working copy (base is the
   template's own checkout and hides that with skip-worktree):

       git -C ~/work/rob/base.robnugen.com show HEAD:deploy_to_dh.sh > deploy_to_dh.sh
       chmod +x deploy_to_dh.sh

3. Fill in `DEST` with the site's ssh alias and `DEST_PATH` with its project
   root relative to `~`, from the site's server on mg. A server directory of
   `example.com/wwwroot` is a project root of `example.com`.
4. Set `PULL_URL="origin"` if the server pulls with a deploy key, which is
   what new sites do. Leave it empty only for a public repo whose server has
   no GitHub key; it then pulls over https.
5. Check the site's `.gitignore` covers its `Config.php` and whatever the
   site writes on the server, because of `git restore .`.
6. Commit it as "Add a git deploy script". In a separate commit, rewrite the
   deploy section of the site's `CLAUDE.md` and README to name
   `./deploy_to_dh.sh`, and drop any mention of an scp watcher.
7. Do not run it. Deploying is Rob's. If the server is not a git checkout
   yet, the scanner skips the site with "could not ask its server which
   commit it runs"; turning it into one (git init, remote add, fetch,
   checkout in place, never rsync the tree) changes the server, so that is
   Rob's too, following base's README, *First install*.
