---
title: A build, if there is one, can be repeated
position: 70
status: idea
whose_move: agent
check: js_build_reproducible
---
A site with no `package.json` needs no build, and passes. A site with one has
its lockfile committed, says which Node version builds it, and has a `build`
script, so anyone can build again and get the same files. Without the lockfile,
the same `npm install` gives different code each month.

Native modules and import maps do most of what a bundler did when these sites
were written. Don't add a build to a site that has none.

## How to do it

1. If the site has a `package.json` only for development tools (ESLint),
   commit its lockfile, add `.node-version`, and you are done.
2. If the site has a build:
   1. Commit the lockfile (`package-lock.json`, `yarn.lock` or
      `pnpm-lock.yaml`), and name the exact Node version in `.node-version`
      or `engines.node`.
   2. Make `npm run build` the only way to build. Run it in a pinned Node
      image: `docker run --rm -v "$PWD":/app -w /app node:<exact version> npm
      ci && npm run build`.
   3. Write in `JS_LIBRARIES.md` whether the built files are committed or
      built when deploying, and where they land.
3. If a site truly needs a build it does not have (TypeScript, JSX), add it
   alongside the old code: the build writes to new paths, the old files keep
   being served, and one commit moves the `<script>` tags over. Undoing it
   means reverting that one commit. Ask Rob before adding a build.
