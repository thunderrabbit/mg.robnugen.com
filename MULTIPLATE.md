# MULTIPLATE: Multiple Concurrent Activity Tracking

## Problem Statement
Pro users need to track multiple activities simultaneously (e.g., meditation timer while also tracking a sleep session, or multiple meditation sessions across different devices/tabs).

## Design Approaches

### Option 1: URL-Based Session Keys (Your Suggestion)
**Pattern**: `/mg/{session_key}` where `session_key` is a unique 8-character identifier

#### How It Works
1. User clicks "Start Clock" → API creates `activity_kai` record
2. If Pro user, server generates random 8-char key (e.g., `abc123de`)
3. Response includes: `{ "ak_id": 42, "session_key": "abc123de" }`
4. JavaScript redirects to `/mg/abc123de`
5. Timer page loads with session key in URL
6. Stop button sends `session_key` to API instead of relying on session storage

#### Pros
- ✅ Each timer has unique URL → can open multiple tabs
- ✅ Shareable/bookmarkable URLs
- ✅ Works across devices (copy URL to phone)
- ✅ No session storage conflicts
- ✅ Can resume timer after browser crash (URL persists)

#### Cons
- ❌ Session key visible in URL (minor security concern)
- ❌ Need to map `session_key` → `ak_id` in database
- ❌ Requires URL routing logic

---

### PHASE 2: Dashboard with Multiple Timers
**Pattern**: `/mg/dashboard` with multiple timer widgets

#### How It Works
1. Single page with multiple timer components
2. Each timer widget tracks its own `ak_id` in component state
3. "Add Timer" button creates new widget
4. Each widget independently calls start/stop API

#### Pros
- ✅ All timers visible on one screen
- ✅ Easy to manage multiple activities
- ✅ No URL complexity
- ✅ Modern SPA feel

#### Cons
- ❌ Requires significant JavaScript refactoring
- ❌ Can't open timers in separate tabs/windows
- ❌ Lose state on page refresh (unless using localStorage array)

---

## Recommended Approach: **Option 1 (URL-Based Session Keys)**

### Why?
- Most flexible for Pro users
- Works across devices and tabs
- Minimal JavaScript changes (just redirect after start)
- Resilient to browser crashes/refreshes
- Future-proof for sharing/collaboration features

### Implementation Phases

#### Step 1: Database Schema
```sql
-- Separate table for session keys (Pro/admin users only)
CREATE TABLE activity_session_keys (
  session_key VARCHAR(11) NOT NULL,           -- YouTube-style: "dQw4w9WgXcQ"
  ak_id BIGINT UNSIGNED NOT NULL UNIQUE,      -- FK to activity_kai
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (session_key),
  UNIQUE KEY uk_ak_id (ak_id),

  CONSTRAINT fk_session_key_activity
    FOREIGN KEY (ak_id) REFERENCES activity_kai(ak_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;
```

**Why separate table?**
- Most users (free tier) won't have session keys → no wasted space in `activity_kai`
- Session keys are optional feature for Pro/admin users only
- Clean separation of concerns
- Easy to query "all sessions with keys" vs "all sessions"

#### Step 2: API Updates
**start-activity.php**:
- Check if user is admin (Pro check comes later)
- Generate human-readable session key (e.g., "dQw4w9WgXcQ")
- Create `activity_kai` record
- If admin (or Pro, later): Insert into `activity_session_keys` table
- Return `session_key` in response (only for admin (or Pro, later))

**stop-activity.php**:
- Accept `session_key` OR `ak_id`
- If `session_key` provided: look up `ak_id` from `activity_session_keys`
- Verify ownership before stopping

#### Step 3: Frontend Updates
**meisogambare.js**:
- After successful start, check if `session_key` in response
- If admin AND has session_key: redirect to `/mg/{session_key}`
- If free user: stay on `/mg/` (no redirect)
- On page load, check URL for session key
- If session key exists, load that timer state
- Send session key to stop endpoint

**URL Routing** (wwwroot/mg/index.php):
```php
// Parse URL: /mg/dQw4w9WgXcQ
if (preg_match('#^/mg/([a-z0-9-]+)$#', $_SERVER['REQUEST_URI'], $matches)) {
    $session_key = $matches[1];
    // Verify ownership via API
    // If owner: load timer page with session_key
    // If not owner: show timer state, but no controls or user information
}
```

#### Step 4: Helper Methods
```php
// In ActivityKai.php or new SessionKey.php helper
// Owner view: full details with controls
public function getSessionByKey(string $session_key, int $user_id): ?array {
    $stmt = $this->pdo->prepare("
        SELECT ak.ak_id, ak.user_id, ak.activity_id, ak.start_local_dt,
               ak.intended_sec, ak.actual_sec, ak.bonus_sec
        FROM activity_session_keys ask
        JOIN activity_kai ak ON ask.ak_id = ak.ak_id
        WHERE ask.session_key = ? AND ak.user_id = ?
    ");
    $stmt->execute([$session_key, $user_id]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}

// Public view: privacy-safe summary (like YouTube videos/live streams)
public function getPublicSessionByKey(string $session_key): ?array {
    $stmt = $this->pdo->prepare("
        SELECT ak.intended_sec, ak.actual_sec, ak.bonus_sec,
               ak.start_local_dt, ak.is_public,
               a.activity_name
        FROM activity_session_keys ask
        JOIN activity_kai ak ON ask.ak_id = ak.ak_id
        JOIN activities a ON ak.activity_id = a.activity_id
        WHERE ask.session_key = ?
          AND ak.is_public = 1  -- Only show if user marked as public
    ");
    $stmt->execute([$session_key]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}

public function generateSessionKey(): string {
    // Generate YouTube-style ID: base64url encoding (a-z, A-Z, 0-9, _, -)
    // 11 chars = 64^11 = 73.7 quadrillion combinations
    $chars = '_-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $key = '';
    for ($i = 0; $i < 11; $i++) {
        $key .= $chars[random_int(0, 63)]; // 64 chars total
    }
    return $key;
}
```

---

## Alternative: Hybrid Approach

Combine Options 1 and 2:
- **Free users**: `/mg/` (single timer, session-based)
- **Pro users**: `/mg/{session_key}` (multiple timers, URL-based)

This allows gradual rollout and keeps free tier simple.

---

## Security Considerations

### Session Key Generation
- **YouTube-style format**: 11 random chars (e.g., "dQw4w9WgXcQ")
- Base64url encoding: `a-z`, `A-Z`, `0-9`, `_`, `-` (64 chars total)
- Readable in database (plain ASCII, not binary)
- URL-safe (no encoding needed)
- 11 chars = 64^11 = 73.7 quadrillion combinations
- **No expiration**: Keys exist forever for historical lookup
- Collision handling: Check uniqueness before insert, retry if exists

### Ownership Verification
**CRITICAL**: Always verify `user_id` matches logged-in user:
```php
$session = $activityHelper->getSessionByKey($session_key, $user_id);
if (!$session) {
    // Either doesn't exist OR user doesn't own it
    header('Location: /mg/');
    exit;
}
```

### Double-Click Protection
If user tries to start timer at existing session key URL:
```php
// In /mg/{session_key} route
$session = $activityHelper->getSessionByKey($session_key, $user_id);
if ($session) {
    // Session exists and user owns it → show existing timer
    // This handles double-clicks and page refreshes
} else {
    // Session doesn't exist OR user doesn't own it → redirect to /mg/
    header('Location: /mg/');
    exit;
}
```

### Double-Click Protection
If user tries to start timer at existing session key URL:
```php
// In /mg/{session_key} route
$session = $activityHelper->getSessionByKey($session_key, $user_id);
if ($session) {
    // Session exists and user owns it → show existing timer
    // This handles double-clicks and page refreshes
} else {
    // Session doesn't exist OR user doesn't own it → redirect to /mg/
    header('Location: /mg/');
    exit;
}
```

### Public Viewing (Like YouTube)
Anyone can view sessions via session key URL (both **LIVE** and completed):
```php
// In /mg/{session_key} route
if (!$is_logged_in->isLoggedIn() || !$activityHelper->isOwner($session_key, $user_id)) {
    // Not owner - show public view
    $publicSession = $activityHelper->getPublicSessionByKey($session_key);
    if ($publicSession) {
        // Show session summary:
        // - "Someone is doing an activity" (if active) or "Someone completed an activity"
        // - Intended time
        // - Elapsed time (if active) or Actual time + Bonus (if completed)
        // - Start date/time
        // - NO user information, NO activity name, NO controls
    } else {
        // Session not found
        header('Location: /mg/');
        exit;
    }
}
```

**Privacy Control:**
- **Per-session privacy**: User chooses to share each session individually
- Default: Private (not publicly viewable)
- User can toggle `is_public` flag when starting/stopping timer
- Only sessions with `is_public = 1` are viewable by others
- Activity name shown in public view (e.g., "Meditation")
- No user information shown
- No controls (can't stop someone else's timer)
- Shows elapsed time for active sessions
- Shows final time for completed sessions

**Example Public View (Completed):**
```
Someone completed Meditation
Intended: 5 minutes
Actual: 7 minutes
Bonus: 2 minutes
Started: 2026-01-22 09:00:00
```

**Example Public View (Live/Active):**
```
🔴 LIVE - Someone is doing Meditation
Intended: 5 minutes
Elapsed: 3 minutes 42 seconds
Started: 2026-01-22 09:00:00
```

**UI for Privacy Toggle:**
```
Timer page:
Countdown minutes: [5]
☐ Share this timer publicly (read-only)
[Start Clock]

Or after stopping:
Great job! You meditated for 7 minutes!
☐ Make this timer public (read-only)
[Copy shareable link]
```

### Privacy
- Session keys reveal timer only if owner allows.  (no public index of sessions)

---

## User Experience Flow

### Starting a New Timer
1. User visits `/mg/` or clicks "New Timer"
2. Enters countdown minutes, clicks "Start Clock"
3. API creates record, returns `session_key`
4. Browser redirects to `/mg/abc123de`
5. Timer starts counting

### Managing Multiple Timers
1. User opens new tab, visits `/mg/`
2. Starts another timer → redirects to `/mg/xyz789gh`
3. Now has two tabs with independent timers
4. Each tab shows its own session key in URL
5. Stopping one doesn't affect the other

### Resuming After Crash
1. Browser crashes while timer is running
2. User reopens browser, checks history
3. Navigates to `/mg/abc123de`
4. Page loads, checks if session is still active
5. If `actual_sec IS NULL`, timer is still running
6. Calculate elapsed time, resume display

---

## Pro User Features (Future)

- **Timer Dashboard**: `/mg/dashboard` lists all active sessions with links
- **Named Sessions**: "Morning Meditation", "Afternoon Nap"
- **Session History**: View past sessions by session key
- **Cross-Device Sync**: Start on desktop, stop on mobile
- **Sharing**: Send timer URL to accountability partner

---

## Migration Path

1. ✅ **Current**: Single timer, session-based (FREE users)
2. 🔄 **Phase 1**: Add `session_key` column, generate for all new sessions
3. 🔄 **Phase 2**: Implement URL routing for `/mg/{session_key}`
4. 🔄 **Phase 3**: Enable for Pro users only
5. 🔄 **Phase 4**: Build dashboard for managing multiple timers
6. ✅ **Future**: Public sharing, cross-device sync

---

## Design Decisions (FINAL)

1. **Should free users see session keys?**
   - ✅ **NO** - Free users stay on `/mg/` (no redirect, no session keys)
   - ✅ Admin users get session keys (in lieu of Pro check)
   - 🔄 Later: Pro users get session keys

2. **How long should session keys remain valid?**
   - ✅ **Forever** - Keys never expire
   - ✅ Historical lookup: can view past sessions by key
   - ✅ Helps with debugging and user support

3. **Should we show a "My Timers" dashboard?**
   - 🔄 Later: Yes for Pro/admin users
   - Lists all active sessions with quick links

4. **What happens if user tries to start timer at `/mg/{existing_key}`?**
   - ✅ **Show existing timer** (if they own it)
   - ✅ Handles double-clicks gracefully
   - ✅ If not owner: redirect to `/mg/`

5. **Session key format?**
   - ✅ **YouTube-style**: Base64url encoding (a-z, A-Z, 0-9, _, -)
   - ✅ Plain ASCII in database (not binary)
   - ✅ Examples: "dQw4w9WgXcQ", "jNQXAC9IVRw"

---

## Conclusion

**Recommended**: URL-based session keys (`/mg/{session_key}`)

This provides maximum flexibility for Pro users while maintaining simplicity. The implementation is straightforward and builds on existing infrastructure.

**Next Steps**:
1. Review this document
2. Decide on free vs Pro tier behavior
3. Implement Step 1 (database schema)
4. Test with single timer before enabling multiple
