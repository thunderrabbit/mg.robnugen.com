---
title: Every JavaScript library is written down
position: 10
status: idea
whose_move: agent
check: js_inventory
---
The repo has a `JS_LIBRARIES.md` at its root that names every script the site
loads from somewhere else: what it is, its exact version, where it is loaded
(`file:line`), how (CDN, a copy in the repo, or npm), and what depends on it.
It also says where the site's own JavaScript lives, inline or in files.

Every later step in this track changes one library at a time, in an order set
by what depends on what. That order can only be worked out from a list. The
check passes when every `<script src>`, import-map entry and bare `import`
the site has is named in the file. A site that loads no scripts passes without
the file.

## How to do it

1. Find every place a script is loaded: `<script src>`, `<script
   type="importmap">`, `import ... from` in `.js`/`.mjs` files, and any script
   added from PHP. Leave `vendor/` and `node_modules/` out.
2. For each third-party library, write one entry with these lines:
   - **Version**: the exact one in use. Read it from the URL, the file's
     header comment, or the lockfile, not from memory.
   - **Loaded at**: every `file:line`, and the `src` or import string exactly
     as the code has it, so the check can find it.
   - **How**: CDN, a copy in the repo, or npm.
   - **Needs**: the libraries it depends on (a jQuery plugin needs jQuery;
     Bootstrap 4's JavaScript needs jQuery, Bootstrap 5's does not).
   - **Latest**: the newest release, and the newest that still supports what
     it depends on. Look these up; write down where you looked.
3. List the site's own scripts: each file, or each template with an inline
   `<script>` block, and what it does in a few words.
4. Write down anything you see that a later step will need: a CDN path that
   newer releases dropped, a library nobody maintains, a build step.
5. Commit and deploy. Nothing the site serves changes.
