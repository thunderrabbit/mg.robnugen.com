# Cleanup: welcome_base.tpl.php → base.tpl.php

## Goal

Eliminate `welcome_base.tpl.php` so all pages use `base.tpl.php`.
Three pages currently use it: `/` (welcome), `/login/`, `/login/register.php`.

## What's wrong with welcome_base today

1. **`max-width: 800px` on `<body>`** — constrains the NavBar. Should be on
   a wrapper div inside body, not body itself.
2. **~100 lines of inline CSS** — should live in a CSS file.
3. **Content baked into the layout** — the `<h1>`, `.subtitle`, and footer
   text belong in the page template, not the layout.
4. **Duplicate `.btn` styles** — `buttons.css` already defines `.btn-primary`
   and `.btn-secondary`. The inline `<style>` redefines them with slightly
   different values (different padding, font-weight). Need to pick one.
5. **Duplicate form styles** — `.form-row`, `.form-container` etc. are only
   used by login/register. Could go in a `welcome.css` or `forms.css` file.

## Also: standalone login/register templates

`templates/login/index.tpl.php` and `templates/login/register.tpl.php` are
full standalone HTML pages (their own `<!DOCTYPE>`, `<head>`, `<body>`). They
don't use the layout system, have no NavBar, no theme toggle, no `theme.css`.
These are NOT currently used — the actual login/register pages use
`welcome_base.tpl.php` with `login_content.tpl.php` / `register_content.tpl.php`.
The standalone templates can likely be deleted (verify first).

## Proposed steps

### Step 1 — Create `wwwroot/css/welcome.css` ✅ DONE AUTONOMOUSLY

Extract the inline styles from `welcome_base.tpl.php` into a CSS file.
But first, deduplicate:

- **Remove** `.btn`, `.btn-primary`, `.btn-secondary` — already in `buttons.css`
- **Remove** `body` rule — `styles.css` already handles background/color;
  the `font-family` is `system-ui` everywhere. Only unique thing is
  `line-height: 1.6` which could go on a `.welcome-page` wrapper class.
- **Keep** in `welcome.css`: `.subtitle`, `.description`, `.features`,
  `.features li`, `.cta`, `.form-container`, `.form-row` styles

> Body layout properties (`max-width`, `margin`, `padding`, `line-height`)
> moved to a `.welcome-page` wrapper class. The duplicate `:hover` rule for
> `input[type="submit"]` (lines 128-133) was also fixed (was declared twice).
> The `h1` color rule was scoped to `.welcome-page h1` to avoid affecting
> other layouts.

### Step 2 — Delete standalone login templates (if unused)

Verify that `templates/login/index.tpl.php` and
`templates/login/register.tpl.php` are not referenced anywhere, then delete.

> **Moved up from Step 5.** This has no dependencies on the other steps —
> these are orphaned full-HTML templates that duplicate functionality already
> handled by `login_content.tpl.php` / `register_content.tpl.php` via the
> layout system. Deleting dead code early reduces confusion.

### Step 3 — Move content out of the layout

Move these from `welcome_base.tpl.php` into `welcome.tpl.php`:

```php
<h1>🧘 <?= $is_logged_in->isLoggedIn() ? htmlspecialchars($is_logged_in->getSiteTitle()) : 'Meiso Gambare' ?></h1>
<p class="subtitle">...</p>
```

And the footer text:

```php
<?php if (!$is_logged_in->isLoggedIn()): ?>
    <p style="...">Free to get started • No email or credit card required</p>
<?php elseif ...
```

The login/register content templates don't need the h1/subtitle, so this
content should only be in `welcome.tpl.php`, not in the layout.

### Step 4 — Switch pages to base.tpl.php

Update these files to use `base.tpl.php` instead of `welcome_base.tpl.php`:
- `wwwroot/index.php` (welcome page)
- `wwwroot/login/index.php`
- `wwwroot/login/register.php`

Each needs to load `welcome.css` somehow. Options:
- (a) Add a `$page_css` mechanism to `base.tpl.php` for page-specific CSS
- (b) Just add `<link>` to `welcome.css` in `base.tpl.php` (wasteful but simple)
- (c) Include the CSS link in the page content templates themselves

Option (c) is simplest and doesn't require changing `base.tpl.php`. Just put
`<link rel="stylesheet" href="/css/welcome.css">` at the top of
`welcome.tpl.php`, `login_content.tpl.php`, and `register_content.tpl.php`.
It's valid HTML inside `<body>` and works in all browsers.

Actually, a cleaner version of (a): add an optional `$page_head` variable
to `base.tpl.php` that gets echoed inside `<head>`. Then pages can inject
their own CSS links. This is also how `mg_base.tpl.php` could eventually
merge into `base.tpl.php` (injecting FlipClock CSS/JS).

### Step 5 — Delete welcome_base.tpl.php

Once all three pages use `base.tpl.php`, delete `welcome_base.tpl.php`.

### Step 6 (optional, future) — Merge mg_base.tpl.php into base.tpl.php

If we add a `$page_head` / `$page_scripts` mechanism to `base.tpl.php`,
then `mg_base.tpl.php` could also be eliminated. The timer page would inject
its FlipClock CSS/JS via those variables. This would leave us with a single
layout template.

## Risk areas

- **Button style differences**: The inline `.btn` in welcome_base has
  `padding: 12px 24px; font-weight: 500` while `buttons.css` has
  `padding: 12px 30px; font-weight: 600`. After switching to `buttons.css`,
  buttons will be slightly wider and bolder. Probably fine, but worth eyeballing.
- **PageWrapper width**: `base.tpl.php` uses `PageWrapper` (max-width: 900px).
  The welcome page currently uses 800px on body. The content will be slightly
  wider. Again, probably fine.
- **Login/register form styling**: The `.form-row` and `.form-container`
  styles only exist in the inline block. They MUST be moved to a CSS file
  before deleting `welcome_base.tpl.php`, or the forms will lose all styling.

## Suggested commit sequence

1. "Create welcome.css from welcome_base inline styles"
2. "Delete unused standalone login/register templates"
3. "Move h1, subtitle, footer from welcome_base layout into welcome.tpl.php"
4. "Switch welcome/login/register pages to base.tpl.php"
5. "Delete welcome_base.tpl.php"
