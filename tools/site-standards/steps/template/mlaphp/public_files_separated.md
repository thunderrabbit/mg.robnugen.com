---
title: Public files are separate from the rest
position: 130
status: idea
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
