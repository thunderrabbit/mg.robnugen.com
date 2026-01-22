# Activity Selector for Pro/Admin Users

## Goal
Add a dropdown menu to the `/mg/` meditation timer page that allows Pro/admin users to select which activity they're tracking (Meditation, Sleep, Work, etc.). Free users will continue to see only "Meditation" (hardcoded to activity_id=1).

## User Review Required

> [!IMPORTANT]
> **Activity Selection Behavior**
> - Free users: No dropdown, always tracks "Meditation" (activity_id=1)
> - Pro/Admin users: Dropdown to select from available activities
> - Default selection: "Meditation" for all users

## Proposed Changes

### Backend Changes

#### [NEW] `/wwwroot/api/list-activities.php`
- Returns list of activities available to the logged-in user
- Free users: Only activity_id=1 (Meditation)
- Pro/Admin users: All active activities where `is_pro=0` OR user is Pro/admin
- Response format:
```json
{
  "activities": [
    {"activity_id": 1, "activity_name": "Meditation"},
    {"activity_id": 2, "activity_name": "Sleeping"},
    ...
  ]
}
```

---

#### [MODIFY] `/wwwroot/mg/index.php`
Convert from static HTML to PHP template to:
- Include `prepend.php` for authentication
- Check if user is Pro/admin
- Pass user tier to template
- Load activities list for dropdown

---

### Frontend Changes

#### [MODIFY] `/wwwroot/mg/index.php` (HTML structure)
Add activity selector (dropdown OR text):
```html
<div class="activity-selector-wrapper">
  <label for="activity_display">Activity:</label>
  <!-- Shown when 2+ activities available -->
  <select id="activity_select" style="display:none;">
  </select>
  <!-- Shown when 0-1 activities available -->
  <span id="activity_text"></span>
</div>
```

**Display Logic:**
- 0 activities: Show "Meditation" as text, use `activity_id: 1`
- 1 activity: Show activity name as text, use that activity's ID
- 2+ activities: Show dropdown, user selects

---

#### [MODIFY] `/wwwroot/mg/javascript/meisogambare.js`
Update JavaScript to:
1. Load activities from `/api/list-activities.php`
2. **If 0 activities**: Show "Meditation" text, use `activity_id: 1`
3. **If 1 activity**: Show activity name as text, use that `activity_id`
4. **If 2+ activities**: Show dropdown, populate with options
5. On "Start Clock": Send selected/default `activity_id` to API

```javascript
// Load activities on page load
$.get('/api/list-activities.php', function(response) {
    if (response.activities.length === 0) {
        $('#activity_text').text('Meditation').show();
        currentActivityId = 1;
    } else if (response.activities.length === 1) {
        $('#activity_text').text(response.activities[0].activity_name).show();
        currentActivityId = response.activities[0].activity_id;
    } else {
        // Populate dropdown
        response.activities.forEach(function(activity) {
            $('#activity_select').append(
                $('<option>').val(activity.activity_id).text(activity.activity_name)
            );
        });
        $('#activity_select').show();
    }
});
```

---

### Helper Class

#### [NEW] `/classes/ActivityTracking/Activity.php`
Helper class to fetch activities:
```php
public function getActivitiesForUser(int $user_id, bool $is_pro, bool $is_admin): array {
    // Returns activities based on user tier
}
```

---

## Verification Plan

### Manual Testing
1. **Test as Free User**:
   - Visit `/mg/` while logged in as free user
   - Verify NO activity dropdown is visible
   - Start timer, check console: `activity_id: 1` sent to API
   - Verify `activity_kai` record has `activity_id = 1`

2. **Test as Admin User**:
   - Visit `/mg/` while logged in as admin
   - Verify activity dropdown IS visible
   - Verify dropdown contains all activities (Meditation, Sleep, Work, etc.)
   - Select "Work", start timer
   - Check console: `activity_id: 4` sent to API
   - Verify `activity_kai` record has `activity_id = 4`

3. **Test API Endpoint**:
   ```bash
   # As free user
   curl -X GET https://mg.robnugen.com/api/list-activities.php
   # Should return only Meditation

   # As admin user
   curl -X GET https://mg.robnugen.com/api/list-activities.php
   # Should return all activities
   ```

### Database Verification
```sql
-- Check that activity_id is correctly saved
SELECT ak_id, user_id, activity_id, start_local_dt
FROM activity_kai
ORDER BY created_at_utc DESC
LIMIT 5;
```

---

## Implementation Steps

1. Create `Activity.php` helper class
2. Create `/api/list-activities.php` endpoint
3. Convert `/mg/index.php` to PHP (add prepend, check user tier)
4. Add activity dropdown HTML (hidden by default)
5. Update `meisogambare.js` to load activities and send selected ID
6. Test as free user (no dropdown, activity_id=1)
7. Test as admin user (dropdown visible, selectable activities)
