---
title: Every third-party script names its exact version
position: 20
status: proven
whose_move: agent
check: js_pinned
---
Every script loaded from another server names an exact version and carries an
`integrity` hash, so the browser runs exactly the file that was tested. A copy
in the repo has its version in its path. A URL like `jquery.min.js` or
`three@latest` can change under the site with no commit and no deploy; after
this step, nothing the site runs changes unless a commit changes it.

The check reads every `<script src>` and import-map entry that points to
another host. Each needs a full `x.y.z` version in the URL. A `<script src>`
also needs `integrity` and `crossorigin`; an import map needs an `integrity`
entry for each of its URLs that names one file (a prefix ending in `/` can't
carry a hash, only a version). A copy under a `vendor/`, `lib/` or `libs/`
directory the site serves needs `x.y.z` in its path.

## How to do it

1. Use the versions `JS_LIBRARIES.md` lists. Pin the version the site
   runs today, never a newer one: upgrading is a later step.
2. A URL with no version, or only a major (`three@0`, `jquery-3.min.js`): find
   which exact release it serves today, and put that release in the URL.
3. Add the hash: `curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A`,
   then `integrity="sha384-<hash>" crossorigin="anonymous"` on the tag. For
   an import map, add an `"integrity": { "<url>": "sha384-<hash>" }` block.
4. A copy kept in the repo: rename it to have its version in its path
   (`js/vendor/jquery-3.7.1.min.js`), and update every place that loads it.
5. Load every page that runs scripts and look at the browser console. A wrong
   hash blocks the script and says so there.
6. Update `JS_LIBRARIES.md`, commit, and deploy.
