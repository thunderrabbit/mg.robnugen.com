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

3. **Test credentials generated** in `.env`:
   | Role | Username | Password |
   |------|----------|----------|
   | Admin | `testadmin` | `JIITa7W7ctOrAcvjk6pY` |
   | Pro | `testpro` | `llFnWkvt37dcmZipt6g8` |
   | Free | `testfree` | `YgsiUt0HP1mh2mdoOITA` |

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
