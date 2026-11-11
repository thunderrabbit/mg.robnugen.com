# JavaScript this site loads

Every script mg loads, written down for mg's standards page (JavaScript
track). No build step, no npm, no import map and no `import` statements:
every script is a plain `<script src>` or an inline block in a template.

Versions were read from the URLs in the code. "Latest" was looked up on
2026-11-11 at `https://registry.npmjs.org/<package>` (`dist-tags.latest`).
Each integrity hash is the SHA-384 of the file its URL served that day; the
`<script>` tags carry the same hash, so the two change together.

## Third-party libraries

### jQuery

- **Version**: 3.7.1
- **Loaded at**:
  - `templates/dashboard/paid_dashboard.tpl.php:68`
    `https://code.jquery.com/jquery-3.7.1.min.js`
  - `templates/layout/mg_base.tpl.php:43`
    `https://code.jquery.com/jquery-3.7.1.min.js`
- **How**: CDN (code.jquery.com)
- **Integrity**: `sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs`
- **Needs**: nothing
- **Needed by**: FlipClock 0.7.8, `wwwroot/dashboard/dashboard.js`,
  `wwwroot/mg/javascript/meisogambare.js`
- **Latest**: 4.0.0 (2026-01-18). The newest 3.x is 3.7.1, the one in use.
  FlipClock 0.7.8 asks for `jquery >=1.7`, which 4.0.0 satisfies on paper;
  nobody has tried it here.

### SortableJS

- **Version**: 1.15.2
- **Loaded at**:
  - `templates/admin/dh/standards.tpl.php:203`
    `https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js`
  - `templates/dashboard/paid_dashboard.tpl.php:71`
    `https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js`
- **How**: CDN (jsDelivr, from npm)
- **Integrity**: `sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM`
- **Needs**: nothing
- **Needed by**: `wwwroot/js/standards-order.js`,
  `wwwroot/dashboard/dashboard.js`
- **Latest**: 1.15.7 (2026-02-11)

### FlipClock

- **Version**: 0.7.8
- **Loaded at**:
  - `templates/layout/mg_base.tpl.php:46`
    `https://cdn.jsdelivr.net/npm/flipclock@0.7.8/compiled/flipclock.min.js`
  - its stylesheet, `templates/layout/mg_base.tpl.php:33`
    `https://cdn.jsdelivr.net/npm/flipclock@0.7.8/compiled/flipclock.min.css`
- **How**: CDN (jsDelivr, from npm)
- **Integrity**: `sha384-Ef+QgU6pxDHG+o4sKcv/pDbh8n/llvn7Bq9TLYv/o37WRuEhAYJkUdly42AMb38+`
- **Needs**: jQuery (`>=1.7`); it is a jQuery plugin, `$('.clock').FlipClock(...)`
- **Needed by**: `wwwroot/mg/javascript/meisogambare.js`
- **Latest**: 1.0.1 (2025-08-24). 0.7.8 is the newest 0.x on npm, so it is
  also the newest that still works as a jQuery plugin.

## The site's own scripts

A template writes these as `/js/<?= SEMVER ?>/name.js`; the `.htaccess` in
each directory drops the version, which is only there to get a new release
past DreamHost's 30-day cache.

| File | What it does | Loaded by |
|---|---|---|
| `wwwroot/js/sortable-table.js` | Sorts a `.sortable-table` by a clicked column header | `templates/admin/dh/databases.tpl.php:64`, `templates/admin/dh/domains.tpl.php:45`, `templates/admin/dh/users.tpl.php:37`, `templates/issues/view.tpl.php:147`, `templates/projects/view.tpl.php:121` |
| `wwwroot/js/standards-order.js` | Drag a site on the standards board to reorder or pin it (SortableJS) | `templates/admin/dh/standards.tpl.php:206` |
| `wwwroot/js/dm_crypto.js` | Decision Matrix encryption in the browser (Web Crypto) | `templates/matrix/add_evidence.tpl.php:39`, `templates/matrix/add_opposite.tpl.php:32`, `templates/matrix/add_problem.tpl.php:26`, `templates/matrix/setup.tpl.php:27`, `templates/matrix/view.tpl.php:30` |
| `wwwroot/dashboard/dashboard.js` | Dashboard: active sessions and todos (jQuery, SortableJS) | `templates/dashboard/paid_dashboard.tpl.php:74` |
| `wwwroot/mg/javascript/meisoprefs.js` | Meditation timer preferences | `templates/layout/mg_base.tpl.php:49` |
| `wwwroot/mg/javascript/meisogambare.js` | Meditation timer (jQuery, FlipClock) | `templates/layout/mg_base.tpl.php:50` |

## Inline `<script>` blocks

None of these uses a library, except where noted.

| Template | What it does |
|---|---|
| `templates/partials/csrf_head.tpl.php` | Adds the `X-CSRF-Token` header to every same-origin `fetch()` and XMLHttpRequest, jQuery's included |
| `templates/layout/base.tpl.php`, `templates/layout/mg_base.tpl.php`, `templates/layout/welcome_base.tpl.php` | Light/dark theme toggle, kept in `localStorage` |
| `templates/admin/migrate_tables.tpl.php` | Applies a migration with `fetch()` |
| `templates/inbox/index.tpl.php` | Sender timezone, byte counters, edit/reply toggles, send-to-agent |
| `templates/issues/view.tpl.php` | Sub-issue status filter |
| `templates/journal/edit.tpl.php` | Journal editor: save, check, status pill |
| `templates/journal/index.tpl.php` | Shows times in the reader's timezone |
| `templates/login/login_content.tpl.php` | Show/hide password |
| `templates/login/register_content.tpl.php` | Disables the button after submit |
| `templates/matrix/add_evidence.tpl.php`, `add_opposite.tpl.php`, `add_problem.tpl.php`, `setup.tpl.php`, `view.tpl.php` | Unlock with the passphrase, encrypt and decrypt through `dm_crypto.js` |
| `templates/profile/devices.tpl.php` | Opens the device rename form in place |
| `templates/settings/index.tpl.php` | Agent tabs |
| `templates/todos/create.tpl.php` | Minutes to seconds, field help and validation |
| `templates/todos/index.tpl.php` | Archive a todo with `fetch()` |

## Notes for later steps

- FlipClock 1.x is a rewrite that depends on `solid-js`, `date-fns` and
  `goober` and ships as UMD/ES modules, not as a jQuery plugin. Moving to it
  means rewriting the clock code in `meisogambare.js`, not a version bump.
- jQuery is on mg only for the meditation timer and the dashboard. FlipClock
  0.7.8 holds the timer to it.
- jQuery comes from code.jquery.com and the other two from jsDelivr: two CDN
  hosts for any Content-Security-Policy to name.
- There is no browser smoke test for the JavaScript yet.
