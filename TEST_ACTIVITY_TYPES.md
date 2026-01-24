# Test Plan: Activity Type ENUM Migration

Base URL: https://mg.robnugen.com/

## User Roles Summary

| Role | FREE | PUBLIC | PRIVATE | Create Activities | Session Codes | Dashboard |
|------|------|--------|---------|-------------------|---------------|-----------|
| Anonymous | Yes | No | No | No | No | No |
| Logged-in (Free) | Yes | Yes | No | No | No | History only |
| Pro (Admin) | Yes | Yes | Own only | Yes | Yes | Full |

## Test Scenarios

### 1. Anonymous User (Not Logged In)

**Setup:** Ensure you are logged out (clear cookies or use incognito)

**Steps:**
1. Navigate to https://mg.robnugen.com/
2. Look at the activity dropdown/selector

**Expected Result:**
- Only "Meditation" should be visible (FREE type)
- PUBLIC activities (Sleeping, Networking, Work, etc.) should NOT appear
- No dashboard access
- No session history

---

### 2. Logged-In Free User (Not Pro, Not Admin)

**Setup:** Create a new user account or log in as a non-admin user

**Steps:**
1. Navigate to https://mg.robnugen.com/
2. Click register and make an account
3. Log in with a free user account
4. Look at the activity dropdown/selector

**Expected Result:**
- "Meditation" should be visible (FREE type)
- PUBLIC activities should be visible: Sleeping, Work, Physical activity, Creativity
- "Add new activity" option should NOT be available (cannot create PRIVATE activities)
- No PRIVATE activities visible (e.g., "Hard mode")

**Test activity history:**
1. Start a Meditation timer
2. Complete the timer
3. Navigate to view history/dashboard
4. Verify completed session appears in history
5. Delete an old activity from history

**Expected Result:**
- Can view completed activity history
- Can delete old activities

---

### 3. Edge Case: Zombie Activity Prevention

**Setup:** Logged-in free user with a running timer

**Steps:**
1. Log in as a free user
2. Start an activity timer (e.g., Meditation)
3. While timer is running (no session_code), navigate away to view history

**Expected Result:**
- The running activity_kai without a session_code should be automatically deleted
- This prevents orphaned/zombie activity_kai entries in the database
- User cannot "leave the countdown screen and pollute the database"

---

### 4. Pro User (Admin)

**Setup:** Log in as an admin user (serves as Pro for testing)

**Steps:**
1. Navigate to https://mg.robnugen.com/
2. Log in with an admin account
3. Look at the activity dropdown/selector

**Expected Result:**
- "Meditation" visible (FREE type)
- All PUBLIC activities visible: Sleeping, Work, Physical activity, Creativity
- "Add new activity" option IS available
- Can see their own PRIVATE activities (custom created)
- Each activity timer gets a session_code (enabling stacking)

**Test activity creation:**
1. Create a new custom activity named "Test Pro Activity"
2. Verify it appears in dropdown
3. Start multiple activities simultaneously (stacking via session_codes)

**Test dashboard:**
1. Navigate to dashboard
2. Verify running activities are shown
3. Verify past/completed activities are shown

---

### 5. API Endpoint Verification

**Steps:**
1. While logged out, fetch https://mg.robnugen.com/api/list-activities.php
2. While logged in as free user, fetch https://mg.robnugen.com/api/list-activities.php
3. While logged in as admin, fetch https://mg.robnugen.com/api/list-activities.php

**Expected Results:**
- Anonymous: Only Meditation
- Free user: Meditation + all PUBLIC activities
- Admin: Meditation + all PUBLIC activities + admin's own PRIVATE activities

---

### 6. PRIVATE Activity Isolation

**Setup:** Two admin users

**Steps:**
1. Log in as Admin User A
2. Create a PRIVATE activity named "Admin A Private"
3. Log out
4. Log in as Admin User B
5. Check activity dropdown

**Expected Result:**
- Admin User B should NOT see "Admin A Private"
- PRIVATE activities are only visible to their creator

---

## Notes for Antigravity

- Create a new user account during testing for the "Logged-in Free User" scenarios
- Let me know the username/email of the account you want promoted to Pro/Admin for testing scenario 4
- The edge case in scenario 3 may require code inspection to verify the cleanup behavior

