# Remove Arrow Colors

User-configurable arrow colors were experimental. The nav arrows on todo
pagination pages will use theme-aware CSS instead.

## Step 1 — Replace arrow color CSS with theme variables

**Files:**
- `wwwroot/css/menu.css` — give `.nav-arrow` theme-aware background colors
  directly (e.g. older = `var(--info)`, newer = `var(--success)`) and
  `color: var(--text-on-accent)`. Remove the `.arrow-older` / `.arrow-newer`
  distinction if a single style is fine, or keep two classes with fixed theme
  colors.

**Commit:** "Replace user-configurable arrow colors with theme variables"

## Step 2 — Remove arrow color `<style>` blocks from layouts

**Files:**
- `templates/layout/base.tpl.php` — delete the entire `<style>` block
  (lines 35-44) that generates `.arrow-older` and `.arrow-newer` from PHP
- `templates/layout/welcome_base.tpl.php` — delete the second `<style>` block
  (lines 136-144) — same thing, and never used on this page anyway

**Commit:** "Remove arrow color style blocks from layout templates"

## Step 3 — Remove arrow color from Profile page (UI + save logic)

**Files:**
- `templates/profile/index.tpl.php` — delete the "Navigation Arrow Colors"
  fieldset (lines 20-37)
- `wwwroot/profile/index.php` — remove `arrow_color_older` and
  `arrow_color_newer` from the POST handling, UPDATE, and INSERT statements
  (lines 70-71, 80-81, 83-84). Also remove the template variables
  (lines 103-104).

**Commit:** "Remove arrow color settings from Profile page"

## Step 4 — Remove arrow color from Auth class

**Files:**
- `classes/Auth/IsLoggedIn.php`:
  - Delete properties `$arrowColorOlder` and `$arrowColorNewer` (lines 78-79)
  - Remove `arrow_color_older, arrow_color_newer` from the SELECT query (line 90)
  - Delete the two `if (!empty(...))` blocks that set them (lines 104-109)
  - Delete `getArrowColorOlder()` and `getArrowColorNewer()` methods (lines 132-140)

**Commit:** "Remove arrow color properties and methods from IsLoggedIn"

## Step 5 — Remove `Utilities::getContrastColor()` if unused

`getContrastColor()` is only called by the arrow color `<style>` blocks
removed in Step 2. After those are gone, check for other callers. If none,
delete the method from `classes/Utilities.php` (line 66+).

**Commit:** "Remove unused getContrastColor utility method"

## Step 6 — Drop database columns

Create a new migration `db_schemas/14_remove_arrow_colors/drop_arrow_colors.sql`:

```sql
ALTER TABLE `user_settings`
DROP COLUMN `arrow_color_older`,
DROP COLUMN `arrow_color_newer`;
```

Do NOT delete the original migration `05_user_settings/add_arrow_colors.sql` —
it's already been applied and is part of the schema history.

**Commit:** "Add migration to drop arrow color columns from user_settings"

## Step 7 — Clean up todo templates (optional)

If Step 1 collapsed `.arrow-older` / `.arrow-newer` into a single `.nav-arrow`
style, update the todo templates to remove the now-meaningless classes:
- `templates/todos/upcoming.tpl.php`
- `templates/todos/history.tpl.php`

If we kept two distinct classes with theme colors, no change needed here.

**Commit (if needed):** "Remove unused arrow-older/newer classes from todo templates"

## Notes

- The `nav-arrow` elements and pagination stay — only the user-configurable
  colors go away.
- The existing migration `05_user_settings/add_arrow_colors.sql` stays in
  `db_schemas/` untouched.
- Deploy the code changes (Steps 1-5) before running the migration (Step 6)
  so the app never tries to read columns that don't exist yet.
