# Proposal: Dynamic Menu Restructure and Timer Mode Integration

## Objective
Standardize the navigation menu across all pages (`/`, `/mg/`, `/profile/`, `/admin/`) based on user roles (Free, Paid, Admin) and implement a "Distraction Free" mode for the timer page (`/mg/`).

## Current State
- **Welcome Page (`/`)**: Uses `active_sessions.tpl.php` or `welcome.tpl.php` inside `welcome_base.tpl.php`. No top menu.
- **Paid Dashboard**: Uses `paid_dashboard.tpl.php` inside `welcome_base.tpl.php`. No top menu.
- **Admin Dashboard**: Uses `active_sessions.tpl.php` inside `welcome_base.tpl.php`. No top menu.
- **Standard Layout (`base.tpl.php`)**: Contains a hardcoded menu for Paid users.
- **Admin Layout (`admin_base.tpl.php`)**: Contains a hardcoded menu for Admin users.
- **Timer Page (`/mg/`)**: Standalone `index.php` file. No top menu. Uses custom HTML structure.

## Proposed Changes

1. **Centralize Menu Logic**
   Create a new template partial `templates/partials/menu.tpl.php` to handle rendering the menu. This avoids code duplication and ensures consistency.

2. **Define Menu Items by Role**
   The menu items will be determined dynamically based on the user's role:

   | Role | Menu Items | URLs |
   |------|------------|------|
   | **Free** | Welcome, Timer | `/`, `/mg/` |
   | **Paid** | Dashboard, Timer, Profile | `/`, `/mg/`, `/profile/` |
   | **Admin** | Dashboard, Timer, Profile, Admin | `/`, `/mg/`, `/profile/`, `/admin/` |

3. **Refactor Timer Page (`/mg/`)**
   - Refactor `wwwroot/mg/index.php` to use the standard `templates/layout/base.tpl.php` (or a unified layout) to automatically inherit the menu.
   - Alternatively, inject the `menu.tpl.php` partial directly if full refactoring is too risky for this task.

4. **Implement Distraction Free Mode**
   - Add a CSS class or data attribute to the timer page interactions.
   - Use JavaScript in `meisogambare.js` to toggle a `.distraction-free` class on the `<body>` or the menu container when the timer starts/stops.
   - CSS:
     ```css
     body.distraction-free .NavBar {
         display: none;
         /* or opacity: 0; pointer-events: none; transition: opacity 0.3s; */
     }
     ```

## Implementation Plan

### Step 1: Create `templates/partials/menu.tpl.php`
Create a file that accepts an array of menu items or checks user permissions to render the correct links.

```php
<div class="NavBar" id="main-menu">
    <?php if ($is_admin): ?>
        <!-- Admin Links -->
    <?php elseif ($is_paid): ?>
        <!-- Paid Links -->
    <?php else: ?>
        <!-- Free Links -->
    <?php endif; ?>
    <!-- Common user dropdowns -->
</div>
```

### Step 2: Update Layouts
- Modify `templates/layout/base.tpl.php` and `templates/layout/welcome_base.tpl.php` to include the new menu partial.
- Ensure `wwwroot/index.php` passes necessary variables (is_admin, is_paid) to these layouts.

### Step 3: Update `/mg/` Page
- Include the menu partial at the top of `wwwroot/mg/index.php`.
- Ensure it has access to the `$is_logged_in` object to determine which menu to show.

### Step 4: Add Distraction Free Logic
- Edit `wwwroot/mg/css/meisogambare.css` (or `menu.css`) to add the hiding style.
- Edit `wwwroot/mg/javascript/MESSAGE_VERSION/meisogambare.js` to add:
  ```javascript
  // When timer starts
  $('body').addClass('distraction-free');

  // When timer stops
  $('body').removeClass('distraction-free');
  ```

## Verification
- **Free User**: Visit `/`, check for "Welcome", "Timer". Visit `/mg/`, check for menu. Start timer, check menu disappears. Stop timer, check menu reappears.
- **Paid User**: Visit `/`, check for "Dashboard", "Timer", "Profile". Check `/mg/` behavior.
- **Admin User**: Visit `/admin/`, check for "Dashboard", "Timer", "Profile", "Admin". Check `/mg/` behavior.
