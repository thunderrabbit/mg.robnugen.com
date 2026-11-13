---
title: No compatibility shims left
status: proven
whose_move: agent
check: js_shims_removed
---
The site loads none of the shims that kept old code working during the
upgrades: jQuery Migrate, Vue's `@vue/compat`, `core-js`, `babel-polyfill`, or
anything from `polyfill.io` or its copies. A shim does nothing for code that no
longer needs it, makes every page heavier, and hides the next deprecation
behind its own warning. `polyfill.io` was taken over in 2024 and sent harmful
code to the sites that loaded it.

The check reads the same scripts as the inventory step, and passes when none
of them is a known shim. It cannot know a shim the site wrote itself: a
`$.browser` polyfill kept for one old plugin, a console-capture script left
in from a sweep. The site declares each of those in `JS_LIBRARIES.md` as
`Shim: <path or url> -- <why>`, and the step stays open while that file is
tracked or that script is loaded. The names are described in
`tools/site-standards/NAMES.md`.

## How to do it

1. Do this only after the smoke test and the live console have been clean
   since the last upgrade was deployed.
   While writing the inventory, declare every shim of the site's own with a
   `Shim:` line, so this step knows to wait for it.
2. Remove one shim per commit. Run the smoke test on the test domain, read the
   console, deploy.
3. A polyfill for something every browser the site supports already has:
   remove it. A polyfill the site still needs: copy the one function into the
   site's own code, with a comment saying which browser needs it.
4. Update `JS_LIBRARIES.md`.
