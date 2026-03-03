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
layout template (**all four**: `base.tpl.php`, `welcome_base.tpl.php`,
`mg_base.tpl.php`, `admin_base.tpl.php`).

```css
/* ─── Light theme (default) ──────────────────────────────────── */
:root {
    /* page structure */
    --bg-page:          #eef7fc;
    --bg-card:          #ffffff;
    --bg-panel:         #f3faff;
    --bg-nav:           #d0eaff;
    --bg-muted:         #f0f0f0;

    /* borders */
    --border-color:     #cde5f6;
    --border-nav:       #b0d4ec;
    --border-light:     #e0e0e0;
    --border-input:     #ddd;

    /* text */
    --text-primary:     #222222;
    --text-secondary:   #333333;
    --text-muted:       #555555;
    --text-faint:       #666666;
    --text-nav-link:    #0366a8;

    /* shadows */
    --shadow-sm:        0 1px 3px rgba(0,0,0,0.08);
    --shadow-md:        0 2px 6px rgba(0,0,0,0.10);

    /* accent colors */
    --danger:           #dc3545;
    --success:          #4CAF50;
    --success-hover:    #45a049;
    --info:             #2196F3;
    --info-hover:       #1976D2;
    --info-deep:        #1565C0;
    --neutral:          #95a5a6;
    --neutral-hover:    #7f8c8d;

    /* alerts & feedback */
    --alert-warn-bg:    #fff3cd;
    --alert-warn-border:#ffc107;
    --alert-warn-text:  #856404;
    --alert-success-bg: #e8f5e8;
    --alert-success-border: #c3e6cb;
    --alert-success-text: #155724;
    --alert-error-bg:   #ffe8e8;
    --alert-error-border: red;
    --alert-error-text: red;

    /* forms */
    --input-bg:         #ffffff;
    --input-border-focus: #3498db;

    /* radii (not theme-dependent but consumed by templates) */
    --radius-sm:        4px;
    --radius-md:        6px;
    --radius-lg:        8px;
}

/* ─── Dark theme ──────────────────────────────────────────────── */
[data-theme="dark"] {
    --bg-page:          #0f0f0f;
    --bg-card:          #1e1e1e;
    --bg-panel:         #2a2a2a;
    --bg-nav:           #1a1a2e;
    --bg-muted:         #2a2a2a;

    --border-color:     #3a3a3a;
    --border-nav:       #2a2a4a;
    --border-light:     #3a3a3a;
    --border-input:     #4a4a4a;

    --text-primary:     #e8e8e8;
    --text-secondary:   #d0d0d0;
    --text-muted:       #aaaaaa;
    --text-faint:       #888888;
    --text-nav-link:    #7eb8e8;

    --shadow-sm:        0 1px 3px rgba(0,0,0,0.4);
    --shadow-md:        0 2px 6px rgba(0,0,0,0.5);

    --danger:           #f87171;
    --success:          #4CAF50;
    --success-hover:    #45a049;
    --info:             #2196F3;
    --info-hover:       #1976D2;
    --info-deep:        #1565C0;
    --neutral:          #6b7280;
    --neutral-hover:    #4b5563;

    --alert-warn-bg:    #3d3520;
    --alert-warn-border:#6b5a1e;
    --alert-warn-text:  #f0d060;
    --alert-success-bg: #1a2e1a;
    --alert-success-border: #2e5a2e;
    --alert-success-text: #7ec87e;
    --alert-error-bg:   #2e1a1a;
    --alert-error-border: #5a2e2e;
    --alert-error-text: #f87171;

    --input-bg:         #2a2a2a;
    --input-border-focus: #7eb8e8;
}
```

Add `<link rel="stylesheet" href="/css/theme.css">` as the **first**
stylesheet in all four layout templates:
- `templates/layout/base.tpl.php`
- `templates/layout/welcome_base.tpl.php`
- `templates/layout/mg_base.tpl.php`
- `templates/layout/admin_base.tpl.php`

> **Commit after Step 1:**
> ```
> Add theme.css with CSS custom property tokens for light and dark themes
> ```

---

## Step 2 — Refactor CSS files

Replace every hardcoded color with the matching token. Commit after each
file so a bad substitution is easy to spot and revert.

**Deploy Step 1 first and verify `theme.css` is live before touching
other files** — otherwise `var(--token)` references will resolve to nothing
and the site will lose its colors.

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

Also replace these **OmgAlertBanner** colors (lines 50-103):

| Hardcoded | Replace with |
|---|---|
| `#fff3cd` (.OmgAlertBanner bg) | `var(--alert-warn-bg)` |
| `#ffc107` (.OmgAlertBanner border, .OmgDismissButton bg) | `var(--alert-warn-border)` |
| `#856404` (.OmgAlertBanner text, .OmgDismissOne:hover) | `var(--alert-warn-text)` |
| `#a07800` (.OmgAlertTime, .OmgDismissOne) | `var(--alert-warn-text)` |
| `#e0a800` (.OmgDismissButton border) | `var(--alert-warn-border)` |
| `#212529` (.OmgDismissButton text) | `var(--text-primary)` |

> **Commit after styles.css:**
> ```
> Refactor styles.css to use CSS custom property tokens
> ```

### `wwwroot/css/buttons.css`

| Hardcoded | Replace with |
|---|---|
| `#2196F3` | `var(--info)` |
| `#1976D2` | `var(--info-hover)` |
| `#95a5a6` | `var(--neutral)` |
| `#7f8c8d` | `var(--neutral-hover)` |

Note: `color: white` on buttons is fine to leave hardcoded — accent
backgrounds are similar in both themes and white text works on both.

> **Commit after buttons.css:**
> ```
> Refactor buttons.css to use CSS custom property tokens
> ```

### `wwwroot/css/menu.css`

| Hardcoded | Replace with |
|---|---|
| `#ffffff` (.dropdown-menu bg) | `var(--bg-card)` |
| `#cde5f6` (.dropdown-menu border) | `var(--border-color)` |
| `0 2px 6px rgba(...)` (.dropdown-menu shadow) | `var(--shadow-md)` |
| `#0366a8` (.dropdown-menu a) | `var(--text-nav-link)` |
| `#f3faff` (.dropdown-menu a:hover bg) | `var(--bg-panel)` |

Leave `.nav-arrow` `color: white` as-is (text on user-configurable
arrow backgrounds).

> **Commit after menu.css:**
> ```
> Refactor menu.css to use CSS custom property tokens
> ```

### `wwwroot/dashboard/dashboard.css` (759 lines — the big one)

This file has **60+ hardcoded colors**. Work through them methodically:

**Structural colors** (highest impact):

| Hardcoded | Replace with | Count |
|---|---|---|
| `white` (card/panel bg) | `var(--bg-card)` | ~10 |
| `#333` (headings) | `var(--text-secondary)` | ~6 |
| `#666` (secondary text) | `var(--text-faint)` | ~6 |
| `#555` (muted text) | `var(--text-muted)` | ~2 |
| `#e0e0e0` (borders) | `var(--border-light)` | ~5 |
| `#ccc` (light borders) | `var(--border-light)` | ~2 |
| `#f5f5f5`, `#f9f9f9`, `#f0f0f0` (panel bg) | `var(--bg-panel)` | ~4 |
| `rgba(...)` shadow values | `var(--shadow-sm)` / `var(--shadow-md)` | several |

**Accent colors** (map to existing tokens):

| Hardcoded | Replace with |
|---|---|
| `#4CAF50` | `var(--success)` |
| `#45a049` | `var(--success-hover)` |
| `#2196F3` | `var(--info)` |
| `#1976D2` | `var(--info-hover)` |
| `#1565C0` | `var(--info-deep)` |
| `#F44336` | `var(--danger)` |
| `#9E9E9E`, `#bdbdbd` | `var(--neutral)` |
| `#999`, `#888`, `#757575` | `var(--text-faint)` or `var(--neutral)` |

**Status-specific colors** (alert banners, success/warning rows):

| Hardcoded | Replace with |
|---|---|
| `#d1e7dd`, `#badbcc` (alert-success) | `var(--alert-success-bg)` / `var(--alert-success-border)` |
| `#0f5132` (alert-success text) | `var(--alert-success-text)` |
| `#FFF9C4`, `#F57F17` (warning) | `var(--alert-warn-bg)` / `var(--alert-warn-text)` |
| `#C8E6C9`, `#2E7D32` (success) | `var(--alert-success-bg)` / `var(--alert-success-text)` |

**Gradients** (~8 `linear-gradient(...)` values): Replace gradient
color-stop values with tokens, e.g.:
```css
/* before */  background: linear-gradient(135deg, #a5d6a7, #558b55);
/* after  */  background: linear-gradient(135deg, var(--success), var(--success-hover));
```

Note: `#f8f9fa` is listed nowhere in this file (the old plan was wrong).

> **Commit after dashboard.css:**
> ```
> Refactor dashboard.css to use CSS custom property tokens
> ```

### `wwwroot/mg/css/meisogambare.css`

| Hardcoded | Replace with |
|---|---|
| `white` (.completion-summary bg) | `var(--bg-card)` |
| `#e0e0e0` (.completion-summary border, .readonly-note border) | `var(--border-light)` |
| `#333` (.completion-summary h3, strong) | `var(--text-secondary)` |
| `#555` (.completion-summary p) | `var(--text-muted)` |
| `rgb(100,100,100)` (label) | `var(--text-faint)` |
| `#4CAF50` (#save_new_activity) | `var(--success)` |
| `#45a049` (#save_new_activity:hover) | `var(--success-hover)` |
| `#888` (#cancel_new_activity) | `var(--neutral)` |
| `#666` (#cancel_new_activity:hover) | `var(--neutral-hover)` |
| `#2196F3` (.post-timer-link) | `var(--info)` |
| `#1976D2` (.post-timer-link:hover) | `var(--info-hover)` |

> **Commit after meisogambare.css:**
> ```
> Refactor meisogambare.css to use CSS custom property tokens
> ```

### Templates with inline `<style>` blocks

**`templates/todos/create.tpl.php`** — Already uses `var(--bg-card)`,
`var(--border-color)`, `var(--text-muted)`, `var(--text-primary)`,
`var(--danger)`. Also uses `var(--radius-lg)`, `var(--radius-md)`, and
`var(--bg-muted)` — these will work once `theme.css` defines them (Step 1).
No changes needed.

**`templates/layout/welcome_base.tpl.php`** — Has extensive inline styles:

| Hardcoded | Replace with |
|---|---|
| `#2c3e50` (h1, labels) | `var(--text-secondary)` |
| `#7f8c8d` (.subtitle) | `var(--neutral)` |
| `#f8f9fa` (.description, .form-container bg) | `var(--bg-panel)` |
| `#3498db` (.btn-primary, input:focus, submit) | `var(--info)` |
| `#2980b9` (.btn-primary:hover, submit:hover) | `var(--info-hover)` |
| `#95a5a6` (.btn-secondary) | `var(--neutral)` |
| `#7f8c8d` (.btn-secondary:hover) | `var(--neutral-hover)` |
| `#ddd` (input borders) | `var(--border-input)` |

Note: welcome_base uses `#3498db` for primary buttons while buttons.css
uses `#2196F3`. Tokenizing both to `var(--info)` will unify them.

Also inline `style=` attributes on `<p>` elements at lines ~131-133
with `color: #7f8c8d` — replace with `var(--neutral)`.

**`templates/login/login_content.tpl.php`** — Inline styles:

| Hardcoded | Replace with |
|---|---|
| `#d4edda` / `#c3e6cb` / `#155724` (success msg) | `var(--alert-success-*)` |
| `#7f8c8d` (account prompt) | `var(--neutral)` |
| `#3498db` ("Create one" link) | `var(--info)` |

**`templates/login/register_content.tpl.php`** — Same pattern:

| Hardcoded | Replace with |
|---|---|
| `#7f8c8d` (account prompt) | `var(--neutral)` |
| `#3498db` ("Log in" link) | `var(--info)` |

**`templates/billing/index.tpl.php`** — Inline styles:

| Hardcoded | Replace with |
|---|---|
| `red` / `#ffe8e8` (error) | `var(--alert-error-*)` |
| `#666` (not configured msg) | `var(--text-faint)` |
| `#ccc` (developer card border) | `var(--border-light)` |
| `#5a9` (growth card border/btn) | `var(--success)` |
| `#888` (footer text) | `var(--text-faint)` |

**`templates/settings/index.tpl.php`** — Inline styles:

| Hardcoded | Replace with |
|---|---|
| `green` / `#e8f5e8` (success) | `var(--alert-success-*)` |
| `red` / `#ffe8e8` (error) | `var(--alert-error-*)` |
| `#ccc` (table border) | `var(--border-light)` |
| `#c00` (revoke button) | `var(--danger)` |
| `#666`, `#888` (muted text) | `var(--text-faint)` |

**`templates/profile/index.tpl.php`** — Same success/error patterns as settings.

**`templates/welcome.tpl.php`** — Inline styles:

| Hardcoded | Replace with |
|---|---|
| `#f0f8ff` (upgrade box bg) | `var(--bg-panel)` |
| `#4CAF50` (upgrade box border) | `var(--success)` |

**`templates/admin/auth_log.tpl.php`** — `background: #f5f5f5` → `var(--bg-panel)`

> **Commit after template inline styles:**
> ```
> Replace inline hardcoded colors in templates with CSS custom property tokens
> ```

---

## Step 2b — JavaScript files with hardcoded colors

These won't respond to CSS variables automatically. Options:
1. Read the CSS variable at runtime: `getComputedStyle(document.documentElement).getPropertyValue('--success')`
2. Leave them for now and address in a follow-up

Files affected:
- `wwwroot/dashboard/dashboard.js` — `#e8f5e9`, `#ffebee`, `1px solid #2196F3`
- `wwwroot/mg/javascript/meisoprefs.js` — `#0B6138`, `#232323`
- `wwwroot/mg/javascript/meisogambare.js` — `rgba(...)`, `#999`, `#4CAF50`, `#F44336`, `#2196F3`

> Recommend: defer to a follow-up commit. JS color changes are lower
> priority than CSS — most JS-set colors are transient highlights.

---

## Step 3 — Add the toggle to the menu

In `templates/partials/menu.tpl.php`, add a button just before the
closing `</div>` of the NavBar:

```html
<button id="theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">
    🌙
</button>
```

Style it to look like part of the nav (no border, transparent bg, cursor
pointer). This partial is included in all four layout templates, so the
toggle will appear everywhere.

> **Commit after Step 3:**
> ```
> Add dark mode toggle button to nav menu
> ```

---

## Step 4 — Add the toggle JavaScript

**CRITICAL: This must be an inline `<script>` in `<head>`, NOT an external
file.** An external file would need a network fetch, causing a flash of the
wrong theme before the script executes.

Add this inline script to the `<head>` of **all four layout templates**:
`base.tpl.php`, `welcome_base.tpl.php`, `mg_base.tpl.php`, `admin_base.tpl.php`.

```html
<script>
(function() {
    // Apply saved theme immediately to avoid flash
    var saved = localStorage.getItem('theme');
    if (saved === 'dark' || saved === 'light') {
        document.documentElement.setAttribute('data-theme', saved);
    }

    document.addEventListener('DOMContentLoaded', function() {
        var btn = document.getElementById('theme-toggle');
        if (!btn) return;

        function updateIcon() {
            btn.textContent = document.documentElement.getAttribute('data-theme') === 'dark'
                ? '☀️' : '🌙';
        }
        updateIcon();

        btn.addEventListener('click', function() {
            var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            var next = isDark ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            updateIcon();
        });
    });
})();
</script>
```

Note: validates localStorage value to only accept 'light' or 'dark'.

> **Commit after Step 4:**
> ```
> Add dark mode toggle JS with localStorage persistence (no flash on load)
> ```

---

## Step 5 (Optional) — Persist preference to the database

Add a `theme` column to `users` table: `ENUM('light','dark') DEFAULT 'light'`.
On toggle, fire a small AJAX POST to a `/api/set-theme.php` endpoint.

Security requirements for the endpoint:
- Validate value is exactly `'light'` or `'dark'` (reject anything else)
- Verify logged-in user via session cookie
- Use existing cookie-based auth (SameSite=Lax provides baseline CSRF protection)

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

Note: `mg_base.tpl.php` currently doesn't load `styles.css` — only
FlipClock CSS, `meisogambare.css`, and `menu.css`. Adding `theme.css`
is sufficient; adding `styles.css` is optional depending on whether you
want the full page structure styles on the timer page.

> **Commit after Step 6:**
> ```
> Default timer page to dark theme; respect user preference if set
> ```

---

## Files touched summary

| File | Work |
|---|---|
| **CSS** | |
| `wwwroot/css/theme.css` | **Create** (new file) |
| `wwwroot/css/styles.css` | Replace hardcoded colors (incl. OmgAlertBanner) |
| `wwwroot/css/buttons.css` | Replace hardcoded colors |
| `wwwroot/css/menu.css` | Replace hardcoded colors |
| `wwwroot/dashboard/dashboard.css` | Replace hardcoded colors (**most work — 60+ values**) |
| `wwwroot/mg/css/meisogambare.css` | Replace hardcoded colors (~14 values) |
| **Layout templates** (add `theme.css` link + inline toggle JS) | |
| `templates/layout/base.tpl.php` | Add `theme.css` + inline JS |
| `templates/layout/welcome_base.tpl.php` | Add `theme.css` + inline JS + replace inline colors |
| `templates/layout/mg_base.tpl.php` | Add `theme.css` + inline JS + set dark default |
| `templates/layout/admin_base.tpl.php` | Add `theme.css` + inline JS |
| **Other templates** (replace inline `style=` colors) | |
| `templates/partials/menu.tpl.php` | Add toggle button |
| `templates/todos/create.tpl.php` | Already uses tokens — no change needed |
| `templates/todos/index.tpl.php` | Already uses tokens — no change needed |
| `templates/login/login_content.tpl.php` | Replace inline colors |
| `templates/login/register_content.tpl.php` | Replace inline colors |
| `templates/billing/index.tpl.php` | Replace inline colors |
| `templates/settings/index.tpl.php` | Replace inline colors |
| `templates/profile/index.tpl.php` | Replace inline colors |
| `templates/welcome.tpl.php` | Replace inline colors |
| `templates/admin/auth_log.tpl.php` | Replace inline colors |
| **JS (deferred)** | |
| `wwwroot/dashboard/dashboard.js` | Hardcoded colors — follow-up |
| `wwwroot/mg/javascript/meisoprefs.js` | Hardcoded colors — follow-up |
| `wwwroot/mg/javascript/meisogambare.js` | Hardcoded colors — follow-up |
| **DB (optional)** | |
| `db_schemas/` | Optional: add `theme` column to `users` |

---

## Known issues (pre-existing, not caused by dark mode)

- **`greyishBtn`** class is used in 6 templates but has no CSS definition
  anywhere. These buttons rely on browser defaults.
- **`templates/login/index.tpl.php`** and **`templates/login/register.tpl.php`**
  are orphaned full-HTML templates not referenced anywhere. Dead code — ignore.
- **Arrow nav colors** in `base.tpl.php` and `welcome_base.tpl.php` are
  PHP-generated from user preferences (`getArrowColorOlder()` /
  `getArrowColorNewer()`). Leave these as-is — they're user-configurable.

---

## Notes

- Start with Step 1 (define tokens) + Step 2 (styles.css only) to validate
  the approach before touching every file.
- `create.tpl.php` and `index.tpl.php` will start looking correct as soon as
  `theme.css` is loaded — they already reference the tokens.
- The dark color values above are starting points — tweak after seeing them
  in the browser.
- Commit after each file in Step 2 — it makes it trivial to revert a single
  bad substitution without losing all the other work.
- Deploy `theme.css` first before deploying any refactored CSS/templates,
  to avoid a window where `var(--token)` references resolve to nothing.
