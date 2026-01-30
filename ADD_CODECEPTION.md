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
