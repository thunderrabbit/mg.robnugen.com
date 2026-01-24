# Test Plan: Activity Type ENUM Migration

Base URL: https://mg.robnugen.com/

## Test Scenarios

### 1. Anonymous User (Not Logged In)

**Setup:** Ensure you are logged out (no session cookies)

**Steps:**
1. Navigate to https://mg.robnugen.com/
2. Look at the activity dropdown/selector

**Expected Result:**
- Only "Meditation" should be visible
- No other activities (Sleeping, Networking, Work, etc.) should appear

---

### 2. Logged-In Free User

**Setup:** Log in as a non-admin, non-pro user

**Steps:**
1. Navigate to https://mg.robnugen.com/
2. Click register and make an account.
3. Log in with a free user account
4. Look at the activity dropdown/selector

**Expected Result:**
- "Meditation" should be visible (FREE type)
- Pro activities should NOT be visible: Sleeping, Networking, Work, Physical activity, Hard mode, Creativity, Minecraft (PUBLIC type)
- Any custom activities created by this user should be visible (PRIVATE type)

---

### 3. Admin User (also serves as Pro user for testing)

**Setup:** Log in as an admin user

**Steps:**
1. Navigate to https://mg.robnugen.com/
2. Log in with an admin account
3. Look at the activity dropdown/selector

**Expected Result:**
- ALL activities should be visible:
  - Meditation (FREE)
  - Sleeping, Networking, Work, Physical activity, Hard mode, Creativity, Minecraft (PUBLIC)
  - All PRIVATE activities from all users

---

### 4. API Endpoint Verification

**Steps:**
1. While logged out, fetch https://mg.robnugen.com/api/list-activities.php
2. While logged in as free user, fetch https://mg.robnugen.com/api/list-activities.php
3. While logged in as admin, fetch https://mg.robnugen.com/api/list-activities.php

**Expected Results:**
- Anonymous: Only Meditation in response
- Free user: Meditation + user's own custom activities
- Admin: All activities (FREE + PUBLIC + all PRIVATE)

---

### 5. Create Custom Activity (PRIVATE type)

**Setup:** Log in as any logged-in user

**Steps:**
1. Navigate to https://mg.robnugen.com/
2. Find the "Add new activity" option (if available in UI)
3. Create a new custom activity with name "Test Private Activity"
4. Verify the new activity appears in the dropdown
5. Log out and log in as a DIFFERENT user
6. Check if "Test Private Activity" is visible

**Expected Result:**
- The custom activity should only be visible to the user who created it
- Other users should NOT see it

---

## Database Verification (Manual)

After running tests, verify in database:

```sql
SELECT activity_id, activity_name, type, user_id
FROM activities
ORDER BY type, activity_name;
```

**Expected:**
| activity_name | type | user_id |
|---------------|------|---------|
| Meditation | FREE | NULL |
| Creativity | PUBLIC | NULL |
| Hard mode | PUBLIC | NULL |
| Minecraft | PUBLIC | NULL |
| Networking | PUBLIC | NULL |
| Physical activity | PUBLIC | NULL |
| Sleeping | PUBLIC | NULL |
| Work | PUBLIC | NULL |
| (user-created) | PRIVATE | (user_id) |
