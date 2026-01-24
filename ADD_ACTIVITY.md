# Adding user_id to Activities Table

## Implementation Status

| Section | Status |
|---------|--------|
| 1. Database Schema | DONE |
| 2. Access Control Logic | DONE |
| 3. Code Changes (Activity.php) | DONE |
| 4. API: create-activity.php | DONE |
| 5. Migration Script | DONE |
| UI: Add activity on timer page | DONE |
| Phase 2: Activity Management UI | NOT DONE |
| Phase 3: Activity Sharing | NOT DONE |
| Phase 4: Activity Analytics | NOT DONE |

---

## Problem Statement

Currently, the `activities` table contains only system-defined activities (Meditation, Sleeping, Networking, etc.). We need to enable users to create their own custom activities while maintaining access to the default system activities.

## Design Decision: NULL vs 0 for System Activities

**Recommendation: Use `NULL` for system activities**

### Why NULL is better than 0:

1. **Semantic Clarity**: NULL explicitly means "no owner / system-wide", while 0 could be confused with a real user_id
2. **Database Best Practice**: NULL is the standard way to represent "not applicable" or "system-level"
3. **Simpler Queries**: `WHERE user_id IS NULL OR user_id = ?` is clearer than `WHERE user_id = 0 OR user_id = ?`
4. **No Collision Risk**: If you ever have a user with ID 0 (unlikely but possible), it won't conflict
5. **Indexing**: MySQL handles NULL efficiently in indexes

## Proposed Changes

### 1. Database Schema - DONE

#### Modify `activities` table

Add `user_id` column to track ownership:

```sql
-- Add user_id column (nullable for system activities)
ALTER TABLE activities
  ADD COLUMN user_id INT UNSIGNED NULL AFTER activity_id,
  ADD KEY idx_user_activities (user_id, is_active);

-- Add foreign key constraint
ALTER TABLE activities
  ADD CONSTRAINT fk_activities_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE;
```

**Schema after migration:**
```sql
CREATE TABLE activities (
  activity_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,  -- NULL = system activity, non-NULL = user-created
  activity_name VARCHAR(64) NOT NULL,
  description TEXT NULL,
  is_pro TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (activity_id),
  KEY idx_user_activities (user_id, is_active),

  CONSTRAINT fk_activities_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;
```

**Existing data**: All current activities will have `user_id = NULL` (system activities)

---

### 2. Access Control Logic - DONE

#### Activity Visibility Rules

**Free Users:**
- See system activities where `is_pro = 0` AND `user_id IS NULL`
- See their own custom activities where `user_id = <their_id>`
- Cannot see other users' custom activities

**Pro Users:**
- See ALL system activities where `user_id IS NULL` (regardless of `is_pro`)
- See their own custom activities where `user_id = <their_id>`
- Cannot see other users' custom activities

**Admin Users:**
- See ALL activities (system + all users' custom activities)

#### SQL Queries

**Free user query:**
```sql
SELECT activity_id, activity_name, description
FROM activities
WHERE is_active = 1
  AND (
    (user_id IS NULL AND is_pro = 0)  -- System free activities
    OR user_id = ?                     -- User's own activities
  )
ORDER BY user_id IS NULL DESC, activity_name;  -- System first, then user's
```

**Pro user query:**
```sql
SELECT activity_id, activity_name, description
FROM activities
WHERE is_active = 1
  AND (
    user_id IS NULL      -- All system activities
    OR user_id = ?       -- User's own activities
  )
ORDER BY user_id IS NULL DESC, activity_name;  -- System first, then user's
```

**Admin user query:**
```sql
SELECT activity_id, activity_name, description, user_id
FROM activities
WHERE is_active = 1
ORDER BY user_id IS NULL DESC, user_id, activity_name;
```

---

### 3. Code Changes - DONE

#### Update `Activity.php` class

File: [`classes/ActivityTracking/Activity.php`](file:///home/thunderrabbit/work/rob/mg.robnugen.com/classes/ActivityTracking/Activity.php)

**Current method** (lines 19-42):
```php
public function getActivitiesForUser(int $user_id, bool $is_admin): array
```

**Updated method:**
```php
/**
 * Get activities available to a user based on their role
 *
 * @param int $user_id User ID
 * @param bool $is_admin Whether the user is an admin
 * @param bool $is_pro Whether the user is a Pro subscriber
 * @return array Array of activities with activity_id, activity_name, and user_id
 */
public function getActivitiesForUser(int $user_id, bool $is_admin, bool $is_pro = false): array {
    if ($is_admin) {
        // Admin users see ALL activities (system + all users' custom)
        $stmt = $this->pdo->prepare("
            SELECT activity_id, activity_name, description, user_id
            FROM activities
            WHERE is_active = 1
            ORDER BY user_id IS NULL DESC, user_id, activity_name
        ");
        $stmt->execute();
    } elseif ($is_pro) {
        // Pro users see all system activities + their own custom activities
        $stmt = $this->pdo->prepare("
            SELECT activity_id, activity_name, description, user_id
            FROM activities
            WHERE is_active = 1
              AND (user_id IS NULL OR user_id = ?)
            ORDER BY user_id IS NULL DESC, activity_name
        ");
        $stmt->execute([$user_id]);
    } else {
        // Free users see only free system activities + their own custom activities
        $stmt = $this->pdo->prepare("
            SELECT activity_id, activity_name, description, user_id
            FROM activities
            WHERE is_active = 1
              AND ((user_id IS NULL AND is_pro = 0) OR user_id = ?)
            ORDER BY user_id IS NULL DESC, activity_name
        ");
        $stmt->execute([$user_id]);
    }

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}
```

**New method for creating custom activities:**
```php
/**
 * Create a custom activity for a user
 *
 * @param int $user_id User ID
 * @param string $activity_name Activity name
 * @param string|null $description Optional description
 * @return int|false The new activity_id or false on failure
 */
public function createUserActivity(int $user_id, string $activity_name, ?string $description = null) {
    // Validate activity name
    $activity_name = trim($activity_name);
    if (empty($activity_name) || strlen($activity_name) > 64) {
        return false;
    }

    // Check for duplicate name for this user
    $stmt = $this->pdo->prepare("
        SELECT activity_id
        FROM activities
        WHERE user_id = ? AND activity_name = ?
    ");
    $stmt->execute([$user_id, $activity_name]);
    if ($stmt->fetch()) {
        return false; // Duplicate name
    }

    // Insert new activity
    $stmt = $this->pdo->prepare("
        INSERT INTO activities (user_id, activity_name, description, is_pro, is_active)
        VALUES (?, ?, ?, 1, 1)
    ");

    if ($stmt->execute([$user_id, $activity_name, $description])) {
        return (int) $this->pdo->lastInsertId();
    }

    return false;
}
```

#### Update `list-activities.php` API endpoint

File: [`wwwroot/api/list-activities.php`](file:///home/thunderrabbit/work/rob/mg.robnugen.com/wwwroot/api/list-activities.php)

**Current code** (lines 25-32):
```php
$pdo = \Database\Base::getPDO($config);
$user_id = $is_logged_in->loggedInID();
$is_admin = $is_logged_in->isAdmin();

// Get activities based on user role
$activityHelper = new \ActivityTracking\Activity($pdo);
$activities = $activityHelper->getActivitiesForUser($user_id, $is_admin);
```

**Updated code:**
```php
$pdo = \Database\Base::getPDO($config);
$user_id = $is_logged_in->loggedInID();
$is_admin = $is_logged_in->isAdmin();
$is_pro = false; // TODO: Implement Pro check via Stripe subscription

// Get activities based on user role
$activityHelper = new \ActivityTracking\Activity($pdo);
$activities = $activityHelper->getActivitiesForUser($user_id, $is_admin, $is_pro);
```

---

### 4. New API Endpoint: Create Custom Activity - DONE

Create new file: `wwwroot/api/create-activity.php`

```php
<?php
/**
 * API Endpoint: Create Custom Activity
 * Allows logged-in users to create their own custom activities
 */

# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Must be logged in to create activities']);
    exit;
}

// Validate input
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['activity_name']) || empty(trim($input['activity_name']))) {
    http_response_code(400);
    echo json_encode(['error' => 'Activity name is required']);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    $activityHelper = new \ActivityTracking\Activity($pdo);
    $activity_id = $activityHelper->createUserActivity(
        $user_id,
        $input['activity_name'],
        $input['description'] ?? null
    );

    if ($activity_id === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to create activity (duplicate name or invalid input)']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'activity_id' => $activity_id,
        'activity_name' => trim($input['activity_name'])
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create activity: ' . $e->getMessage()]);
}
```

---

### 5. Migration Script - DONE

Created: `db_schemas/02_activity_tracking/alter_activities_add_user_id.sql`

```sql
-- Migration: Add user_id to activities table
-- Date: 2026-01-22
-- Purpose: Enable users to create custom activities

-- Add user_id column (nullable for system activities)
ALTER TABLE activities
  ADD COLUMN user_id INT UNSIGNED NULL AFTER activity_id;

-- Add index for efficient querying
ALTER TABLE activities
  ADD KEY idx_user_activities (user_id, is_active);

-- Add foreign key constraint
ALTER TABLE activities
  ADD CONSTRAINT fk_activities_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE;

-- Verify: All existing activities should have user_id = NULL
SELECT
  activity_id,
  activity_name,
  user_id,
  CASE WHEN user_id IS NULL THEN 'System' ELSE 'User' END AS activity_type
FROM activities
ORDER BY activity_id;
```

---

## Testing Plan

### 1. Database Migration Testing

```sql
-- Before migration: Verify current state
SELECT COUNT(*) AS total_activities FROM activities;
SELECT * FROM activities ORDER BY activity_id;

-- Run update script
SOURCE db_schemas/02_activity_tracking/update_activities.sql;

-- After migration: Verify all activities have user_id = NULL
SELECT
  activity_id,
  activity_name,
  user_id,
  CASE WHEN user_id IS NULL THEN '✓ System' ELSE '✗ ERROR' END AS status
FROM activities;

-- Test index exists
SHOW INDEX FROM activities WHERE Key_name = 'idx_user_activities';

-- Test foreign key exists
SELECT
  CONSTRAINT_NAME,
  TABLE_NAME,
  REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_NAME = 'activities' AND CONSTRAINT_NAME = 'fk_activities_user';
```

### 2. API Testing

**Test 1: Free user sees only free system activities**
```bash
# Login as free user, then:
curl -X GET https://mg.robnugen.com/api/list-activities.php \
  -H "Cookie: session_cookie_here"

# Expected: Only Meditation (activity_id=1)
```

**Test 2: Pro user sees all system activities**
```bash
# Login as Pro user, then:
curl -X GET https://mg.robnugen.com/api/list-activities.php \
  -H "Cookie: session_cookie_here"

# Expected: All 8 system activities
```

**Test 3: Create custom activity**
```bash
curl -X POST https://mg.robnugen.com/api/create-activity.php \
  -H "Content-Type: application/json" \
  -H "Cookie: session_cookie_here" \
  -d '{"activity_name": "Reading", "description": "Book reading time"}'

# Expected: {"success": true, "activity_id": 9, "activity_name": "Reading"}
```

**Test 4: User sees their own custom activity**
```bash
curl -X GET https://mg.robnugen.com/api/list-activities.php \
  -H "Cookie: session_cookie_here"

# Expected: System activities + "Reading"
```

**Test 5: Other users cannot see custom activity**
```bash
# Login as different user, then:
curl -X GET https://mg.robnugen.com/api/list-activities.php \
  -H "Cookie: different_session_cookie"

# Expected: System activities only (no "Reading")
```

### 3. Edge Cases

- [x] Attempt to create activity with duplicate name (should fail) - HANDLED in createUserActivity()
- [x] Attempt to create activity with empty name (should fail) - HANDLED in createUserActivity()
- [x] Attempt to create activity with name > 64 chars (should fail) - HANDLED in createUserActivity() + HTML maxlength
- [x] Delete user and verify their custom activities are cascade-deleted - HANDLED via FK constraint
- [x] Verify system activities (user_id=NULL) cannot be deleted by users - HANDLED (users only see/modify their own)

---

## Future Enhancements - NOT DONE

### Phase 2: Activity Management UI - NOT DONE

- **List custom activities**: Show user's custom activities with edit/delete options
- **Edit activity**: Update name/description
- **Delete activity**: Soft delete (set `is_active = 0`) or hard delete
- **Activity icons**: Allow users to choose icons for their activities

### Phase 3: Activity Sharing - NOT DONE

- **Public activities**: Users can mark activities as public for others to copy
- **Activity templates**: Curated list of popular user-created activities
- **Import activity**: Copy another user's public activity to your account

### Phase 4: Activity Analytics - NOT DONE

- **Most used activities**: Show which custom activities are most tracked
- **Activity streaks**: Track consecutive days using specific activities
- **Activity goals**: Set weekly/monthly goals per activity

---

## Rollback Plan

If issues arise, rollback with:

```sql
-- Remove foreign key constraint
ALTER TABLE activities DROP FOREIGN KEY fk_activities_user;

-- Remove index
ALTER TABLE activities DROP KEY idx_user_activities;

-- Remove column
ALTER TABLE activities DROP COLUMN user_id;
```

---

## Summary

This design enables users to create custom activities while maintaining backward compatibility with system activities. The use of `NULL` for system activities provides semantic clarity and follows database best practices. The access control logic ensures proper visibility based on user roles (Free, Pro, Admin).

**Key Benefits:**
- ✅ Users can create unlimited custom activities
- ✅ System activities remain available to all appropriate users
- ✅ Clear ownership model (NULL = system, non-NULL = user-owned)
- ✅ Efficient queries with proper indexing
- ✅ Cascade deletion prevents orphaned activities
- ✅ Future-proof for activity sharing and templates
