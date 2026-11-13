---
title: Public files are separate from the rest
status: proven
whose_move: agent
check: mlaphp_public_split
---
Chapter 13. The web server serves one directory, `wwwroot/`, and only what a
browser needs is in it: entry scripts, CSS, JavaScript, images. Classes,
templates, configuration, schemas and dependencies sit beside it, where no URL
reaches them.

The check passes when `wwwroot/` exists and holds no class directory, no
templates, no `.sql`, no `Config.php`, no `.env`, no `composer.json` and no
`vendor/`.

## How to do it

1. Make `wwwroot/` and move into it only what a browser asks for by URL: entry
   scripts, CSS, JavaScript, images.
2. Keep `classes/`, `templates/`, `db_schemas/`, `vendor/`, `prepend.php` and
   `Config.php` beside it, in the project root.
3. Point the domain's web directory at `wwwroot/` (on DreamHost, in the panel:
   Rob's move), and fix include paths so entry scripts find `prepend.php` one
   level up.
4. Load the site and confirm a URL that used to reach a class or config file
   now returns 404.
