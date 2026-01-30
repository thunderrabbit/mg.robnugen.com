# Codeception Setup Request

Please add Codeception to this project. You should use `~/andbeyond-backend/` as a basis/reference for the setup and configuration.

## Objectives

Codeception test classes should verify the functionality of the site based on the user's role.

## Authentication Helpers

We need to implement specific login helpers for different user tiers. Similar to `loginAsAdmin`, please provide:

*   `loginAsFree`
*   `loginAsPro`
*   `loginAsAdmin`

## Scenarios to Test

Different tests should log in as these different users and check the following functionality:

*   **Free User:**
    *   Starting timers.
    *   Stopping timers.
*   **Pro User:**
    *   Creating new activities.
    *   Can see their Dashboard at `/`.
*   **Admin User:**
    *   Can see the Admin Dashboard at `/`.
    *   See login history.

## Negative Tests (Restrictions)

Please also check that users CANNOT do things they aren't supposed to.

*   **Free User:**
    *   **Cannot** access `/admin/`. Should see "Access Denied" or be redirected.
    *   **Cannot** see any Dashboard at `/`. Should see the Welcome Page instead.
*   **Pro User:**
    *   **Cannot** access `/admin/`. Should see "Access Denied" or be redirected.
*   **Admin User:**
    *   **Don't** see the Welcome Page at `/`.

---

## Progress

### Completed

1. **Directory structure created:**
   ```
   codeception.yml                         # Main Codeception config
   composer.json                           # Dependencies (Codeception)
   run_tests.sh                            # Test runner script
   .env                                    # Test credentials (gitignored)
   Tests/
   ├── Webdriver.suite.yml                 # WebDriver suite config
   ├── Webdriver/
   │   └── ExampleCest.php                 # Example test file
   ├── Support/
   │   ├── AcceptanceTester.php            # Actor with login helpers
   │   ├── Helper/
   │   │   └── Acceptance.php              # Custom helper methods
   │   ├── _generated/.gitkeep
   │   └── Data/.gitkeep
   ├── _output/.gitkeep
   └── _envs/.gitkeep
   ```

2. **Login helpers implemented** in `Tests/Support/AcceptanceTester.php`:
   * `$I->loginAsAdmin()`
   * `$I->loginAsPro()`
   * `$I->loginAsFree()`

3. **Test credentials** are in `.env`:

4. **Updated `.gitignore`** to exclude `.env`, `Tests/_output/*`, `Tests/Support/_generated/*`, and `vendor/`

### TODO

1. **Create test users on the site** with the credentials above and appropriate roles

2. **Install Codeception:**
   ```bash
   composer install
   vendor/bin/codecept build
   ```

3. **Start Selenium server** (required for WebDriver tests):
   ```bash
   xvfb-run java -Dwebdriver.chrome.driver=/usr/bin/chromedriver -jar /usr/local/bin/selenium-server-*.jar standalone
   ```

4. **Run tests:**
   ```bash
   ./run_tests.sh
   ```

5. **Write actual test cases** in `Tests/Webdriver/` for:
   * Free user: timer start/stop, cannot access `/admin/`, sees Welcome Page at `/`
   * Pro user: create activities, sees Dashboard at `/`, cannot access `/admin/`
   * Admin user: sees Admin Dashboard at `/`, sees login history, does not see Welcome Page

---

## Apparent problems to be resolved with guidance from Rob

### 2. Dashboard access logic only checks for admin

**File:** `wwwroot/index.php:17`

```php
if($is_logged_in->isLoggedIn() && $is_logged_in->isAdmin()){
    // Admin users - show dashboard
    ...
} else {
    // Anonymous or free users - show welcome page
    ...
}
```

**Current behavior:**
- Admin → sees Dashboard ("Today's Todos")
- Everyone else (including logged-in `user` role) → sees Welcome Page

**Expected per requirements:**
- Admin → Admin Dashboard
- Pro → User Dashboard
- Free → Welcome Page

**Question:** Should logged-in non-admin users (`user` role) see the dashboard? Or do we need a separate "pro" role first?

---

### 3. Timer start/stop button tests failing

**Tests:** `FreeUserCest::canStartTimer`, `FreeUserCest::canStopTimer`

**Error:** `TimeoutException` - the `.stop` button never becomes visible after clicking `.start`

**Observed:** After clicking the `.start` button, the test waits for `.stop` to appear but it times out after 5 seconds.

**Possible causes:**
- JavaScript error preventing the button swap
- The `.stop` button uses different CSS class or selector
- Timer requires additional setup (e.g., activity selection) before it can start

**Question:** What should happen when clicking the Start button? Does it require a countdown value > 0?

---

### 4. Activity select element not found for pro user

**Test:** `ProUserCest::canCreateActivity`

**Error:** `#activity_select` element not found on `/mg/`

**From exploration:** The `#activity_select` dropdown is "hidden by default, shown when 2+ activities exist"

**Question:** For a brand new user with no activities, how should activity creation work? Is there a different flow for creating the first activity?

---

### 5. Test user role assignments need verification

Based on test results:
- `testadmin` → Sees dashboard, so likely has `admin` role ✓
- `testpro` → Sees welcome page, suggesting they have `user` role (not special "pro" status)
- `testfree` → Sees welcome page (expected)

**Question:** How should `testpro` be distinguished from `testfree` in the database? Both currently appear to behave the same way.
