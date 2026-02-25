# Dark Mode FTW — Implementation Plan

## Approach: CSS Custom Properties

Every hardcoded color becomes a `var(--token-name)`. Two theme definitions
(light and dark) live in one place. A toggle flips `data-theme="dark"` on
`<html>`. Preference is saved in `localStorage` (and optionally the DB for
cross-device persistence).

> **Commit often!** Each step below is a natural commit point. Don't wait
> until the end — small commits make it easy to bisect if something looks
> wrong in the browser, and each step is independently useful even if you
> stop partway through.

---

## Step 1 — Create `wwwroot/css/theme.css`

This is the single source of truth for all colors. Load it first in every
layout (`base.tpl.php`, `welcome_base.tpl.php`, `mg_base.tpl.php`).

```css
/* ─── Light theme (default) ──────────────────────────────────── */
:root {
    --bg-page:          #eef7fc;
    --bg-card:          #ffffff;
    --bg-panel:         #f3faff;
    --bg-nav:           #d0eaff;

    --border-color:     #cde5f6;
    --border-nav:       #b0d4ec;

    --text-primary:     #222222;
    --text-muted:       #555555;
    --text-nav-link:    #0366a8;

    --shadow-sm:        0 1px 3px rgba(0,0,0,0.08);
    --shadow-md:        0 2px 6px rgba(0,0,0,0.10);

    --danger:           #dc3545;
    --success:          #4CAF50;
    --success-hover:    #45a049;
    --info:             #2196F3;
    --info-hover:       #1976D2;
    --neutral:          #95a5a6;
    --neutral-hover:    #7f8c8d;
}

/* ─── Dark theme ──────────────────────────────────────────────── */
[data-theme="dark"] {
    --bg-page:          #0f0f0f;
    --bg-card:          #1e1e1e;
    --bg-panel:         #2a2a2a;
    --bg-nav:           #1a1a2e;

    --border-color:     #3a3a3a;
    --border-nav:       #2a2a4a;

    --text-primary:     #e8e8e8;
    --text-muted:       #aaaaaa;
    --text-nav-link:    #7eb8e8;

    --shadow-sm:        0 1px 3px rgba(0,0,0,0.4);
    --shadow-md:        0 2px 6px rgba(0,0,0,0.5);

    /* accent colors stay the same but can be tweaked */
    --danger:           #f87171;
    --success:          #4CAF50;
    --success-hover:    #45a049;
    --info:             #2196F3;
    --info-hover:       #1976D2;
    --neutral:          #6b7280;
    --neutral-hover:    #4b5563;
}
```

Also add `<link rel="stylesheet" href="/css/theme.css">` as the **first**
stylesheet in `base.tpl.php`, `welcome_base.tpl.php`, and `mg_base.tpl.php`.

> **Commit after Step 1:**
> ```
> Add theme.css with CSS custom property tokens for light and dark themes
> ```

---

## Step 2 — Refactor CSS files

Replace every hardcoded color with the matching token. Commit after each
file so a bad substitution is easy to spot and revert.

### `wwwroot/css/styles.css`
| Hardcoded | Replace with |
|---|---|
| `#eef7fc` (body bg) | `var(--bg-page)` |
| `#ffffff` (.PageWrapper bg) | `var(--bg-card)` |
| `#f3faff` (.PagePanel bg) | `var(--bg-panel)` |
| `#d0eaff` (.NavBar bg) | `var(--bg-nav)` |
| `#b0d4ec` (.NavBar border) | `var(--border-nav)` |
| `#0366a8` (nav links) | `var(--text-nav-link)` |
| `#222` (body text) | `var(--text-primary)` |
| `#555` (.fix text) | `var(--text-muted)` |
| `#cde5f6` (.PagePanel border) | `var(--border-color)` |
| box-shadow rgba | `var(--shadow-md)` |

> **Commit after styles.css:**
> ```
> Refactor styles.css to use CSS custom property tokens
> ```

### `wwwroot/css/buttons.css`
Accent colors (`--info`, `--neutral`, etc.) are already tokenized above —
just swap the hex values.

> **Commit after buttons.css:**
> ```
> Refactor buttons.css to use CSS custom property tokens
> ```

### `wwwroot/css/menu.css`
Audit for any hardcoded colors and replace with nav tokens.

> **Commit after menu.css:**
> ```
> Refactor menu.css to use CSS custom property tokens
> ```

### `wwwroot/dashboard/dashboard.css`
Many hardcoded values — key ones:
| Hardcoded | Replace with |
|---|---|
| `#333` (headings) | `var(--text-primary)` |
| `#e0e0e0` (borders) | `var(--border-color)` |
| `white` card backgrounds | `var(--bg-card)` |
| `#f8f9fa` panel backgrounds | `var(--bg-panel)` |

> **Commit after dashboard.css:**
> ```
> Refactor dashboard.css to use CSS custom property tokens
> ```

### `wwwroot/mg/css/meisogambare.css`
The timer page was intentionally dark — once the dark theme tokens exist,
adding `data-theme="dark"` to `<html>` in `mg_base.tpl.php` by default
will make it dark automatically without any extra CSS.
The `white` and `#333` hardcoded values in `.completion-summary` should
be replaced with `var(--bg-card)` and `var(--text-primary)`.

> **Commit after meisogambare.css:**
> ```
> Refactor meisogambare.css to use CSS custom property tokens
> ```

### Templates with inline `<style>` blocks
- `templates/todos/create.tpl.php` — already uses `var(--bg-card)`,
  `var(--border-color)`, `var(--text-muted)`, `var(--text-primary)`,
  `var(--danger)` — these will **just work** once `theme.css` is loaded.
- `templates/layout/welcome_base.tpl.php` — has lots of inline hardcoded
  colors. Either replace them with tokens, or migrate to external CSS.

> **Commit after template inline styles:**
> ```
> Replace inline hardcoded colors in templates with CSS custom property tokens
> ```

---

## Step 3 — Add the toggle to the menu

In `templates/partials/menu.tpl.php`, add a button after the nav links:

```html
<button id="theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">
    🌙
</button>
```

Style it to look like part of the nav (no border, transparent bg, cursor pointer).

> **Commit after Step 3:**
> ```
> Add dark mode toggle button to nav menu
> ```

---

## Step 4 — Add the toggle JavaScript

Add to `base.tpl.php` (or a new `wwwroot/js/theme.js`):

```js
(function() {
    // Apply saved theme immediately to avoid flash
    const saved = localStorage.getItem('theme');
    if (saved) document.documentElement.setAttribute('data-theme', saved);

    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('theme-toggle');
        if (!btn) return;

        function updateIcon() {
            btn.textContent = document.documentElement.getAttribute('data-theme') === 'dark'
                ? '☀️' : '🌙';
        }
        updateIcon();

        btn.addEventListener('click', function() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const next = isDark ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            updateIcon();
            // Optional: save to server for cross-device persistence (see Step 5)
        });
    });
})();
```

The IIFE at the top runs before the DOM renders — this prevents the
"flash of wrong theme" (FOWT) that happens if you wait for DOMContentLoaded.

> **Commit after Step 4:**
> ```
> Add dark mode toggle JS with localStorage persistence (no flash on load)
> ```

---

## Step 5 (Optional) — Persist preference to the database

Add a `theme` column to `users` table (`VARCHAR(10) DEFAULT 'light'`).
On toggle, fire a small AJAX POST to a `/api/set-theme.php` endpoint.
On page load in PHP, read the user's saved theme and output:
```php
<html data-theme="<?= htmlspecialchars($is_logged_in->getTheme()) ?>">
```
This means logged-in users get their theme on first paint even before JS runs.

> **Commit after Step 5:**
> ```
> Persist dark mode preference to database for cross-device consistency
> ```

---

## Step 6 — Timer page default

`mg_base.tpl.php` can default to dark by setting `data-theme="dark"` on
`<html>` in PHP, but still respect the user's toggle if they override it.

```php
$default_theme = 'dark'; // timer page prefers dark
$user_theme = /* read from user prefs or null */;
$theme = $user_theme ?? $default_theme;
```

> **Commit after Step 6:**
> ```
> Default timer page to dark theme; respect user preference if set
> ```

---

## Files touched summary

| File | Work |
|---|---|
| `wwwroot/css/theme.css` | **Create** (new file) |
| `wwwroot/css/styles.css` | Replace hardcoded colors |
| `wwwroot/css/buttons.css` | Replace hardcoded colors |
| `wwwroot/css/menu.css` | Replace hardcoded colors |
| `wwwroot/dashboard/dashboard.css` | Replace hardcoded colors (most work) |
| `wwwroot/mg/css/meisogambare.css` | Replace hardcoded colors |
| `templates/layout/base.tpl.php` | Add `theme.css` link + toggle JS |
| `templates/layout/welcome_base.tpl.php` | Add `theme.css` link, replace inline colors |
| `templates/layout/mg_base.tpl.php` | Add `theme.css` link, set dark default |
| `templates/partials/menu.tpl.php` | Add toggle button |
| `templates/todos/create.tpl.php` | Already uses tokens — no change needed! |
| `db_schemas/` | Optional: add `theme` column to `users` |

---

## Notes

- Start with Step 1 (define tokens) + Step 2 (styles.css only) to validate
  the approach before touching every file.
- `create.tpl.php` will start looking correct as soon as `theme.css` is
  loaded — it's already written correctly.
- The dark color values above are starting points — tweak after seeing them
  in the browser.
- Commit after each file in Step 2 — it makes it trivial to revert a single
  bad substitution without losing all the other work.
