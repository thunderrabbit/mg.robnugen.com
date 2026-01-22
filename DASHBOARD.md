# Dashboard: Activity Session Overview

## Goal
Create a dashboard at `/` that shows Pro/admin users their active and completed activity sessions, allowing quick access to multiple concurrent timers and historical data.

## User Flow Changes

### Current Behavior
- **Anonymous users**: See welcome page at `/`
- **Free users**: Redirected from `/` to `/mg/`
- **Admin users**: Redirected from `/` to `/mg/`

### New Behavior
- **Anonymous users**: See welcome page at `/` (unchanged)
- **Free users**: Stay at `/` and see welcome page with upgrade messaging (unchanged)
- **Admin/Pro users**: Stay at `/` and see dashboard with active/completed sessions

## Phase 1: Active Sessions Dashboard

### Features
- Display all active (not yet stopped) sessions for current user
- Each session shown in a widget/card
- Click session to navigate to `/mg/{session_key}`
- "Start New Timer" button redirects to `/mg/`
- Real-time updates (optional: could refresh every 30 seconds or use polling)

### Session Widget Display
Each active session widget shows:
- **Activity name** (e.g., "Meditation", "Work", "Sleep")
- **Intended duration** (e.g., "5 minutes")
- **Elapsed time** (live counting, e.g., "3:42 elapsed")
- **Status indicator**:
  - 🟡 Yellow/Countdown phase (before reaching intended time)
  - 🟢 Green/Bonus phase (after reaching intended time)
- **Session key link** (clickable to open full timer)

### API Endpoint: Get Active Sessions

**Endpoint**: `/api/list-active-sessions.php`

**Response**:
```json
{
  "success": true,
  "active_sessions": [
    {
      "session_key": "dQw4w9WgXcQ",
      "ak_id": 42,
      "activity_id": 4,
      "activity_name": "Work",
      "start_local_dt": "2026-01-22 09:00:00",
      "intended_sec": 3600,
      "timezone_id": 1,
      "timezone_iana": "America/Los_Angeles"
    },
    {
      "session_key": "jNQXAC9IVRw",
      "ak_id": 43,
      "activity_id": 1,
      "activity_name": "Meditation",
      "start_local_dt": "2026-01-22 09:15:00",
      "intended_sec": 300,
      "timezone_id": 1,
      "timezone_iana": "America/Los_Angeles"
    }
  ]
}
```

### Dashboard Template Structure

```php
// /templates/dashboard/active_sessions.tpl.php
<div class="dashboard-container">
  <header>
    <h1>My Active Sessions</h1>
    <a href="/mg/" class="btn-new-timer">+ Start New Timer</a>
  </header>

  <div class="active-sessions-grid" id="active-sessions">
    <!-- Populated by JavaScript -->
  </div>

  <div class="empty-state" style="display:none;">
    <p>No active sessions</p>
    <a href="/mg/" class="btn-primary">Start Your First Timer</a>
  </div>
</div>
```

### JavaScript: Dashboard Controller

```javascript
// /wwwroot/dashboard/dashboard.js

// Load active sessions
function loadActiveSessions() {
  $.get('/api/list-active-sessions.php', function(response) {
    if (response.success && response.active_sessions.length > 0) {
      renderActiveSessions(response.active_sessions);
    } else {
      showEmptyState();
    }
  });
}

// Render session widgets
function renderActiveSessions(sessions) {
  var container = $('#active-sessions');
  container.empty();

  sessions.forEach(function(session) {
    var widget = createSessionWidget(session);
    container.append(widget);
  });

  // Start live elapsed time updates
  startElapsedTimeUpdates();
}

// Create widget HTML
function createSessionWidget(session) {
  var elapsedSec = calculateElapsed(session.start_local_dt);
  var statusClass = elapsedSec >= session.intended_sec ? 'bonus' : 'countdown';

  return `
    <a href="/mg/${session.session_key}" class="session-widget ${statusClass}">
      <div class="activity-name">${session.activity_name}</div>
      <div class="intended-time">Intended: ${formatDuration(session.intended_sec)}</div>
      <div class="elapsed-time" data-start="${session.start_local_dt}">
        Elapsed: <span class="elapsed-value">${formatElapsed(elapsedSec)}</span>
      </div>
      <div class="status-indicator">
        ${statusClass === 'bonus' ? '🟢 Bonus Time' : '🟡 Counting Down'}
      </div>
    </a>
  `;
}

// Update elapsed times every second
function startElapsedTimeUpdates() {
  setInterval(function() {
    $('.elapsed-time').each(function() {
      var startDt = $(this).data('start');
      var elapsedSec = calculateElapsed(startDt);
      $(this).find('.elapsed-value').text(formatElapsed(elapsedSec));
    });
  }, 1000);
}
```

---

## Phase 2: Completed Sessions History

### Features
- Display recent completed sessions (last 10-20)
- Each session shown in a card/widget
- Click to view read-only/static timer page
- Shows summary: activity, duration, bonus time, completion date

### Completed Session Widget Display
Each completed session shows:
- **Activity name**
- **Completion date** (e.g., "Jan 22, 2026 at 3:45 PM")
- **Actual duration** (e.g., "7 minutes")
- **Bonus time** (e.g., "+2 minutes bonus" or "Stopped early")
- **Session key link** (clickable to view static/archived timer)

### API Endpoint: Get Completed Sessions

**Endpoint**: `/api/list-completed-sessions.php`

**Query params**:
- `limit` (default: 10, max: 50)
- `offset` (for pagination)

**Response**:
```json
{
  "success": true,
  "completed_sessions": [
    {
      "session_key": "abc123defgh",
      "ak_id": 40,
      "activity_id": 1,
      "activity_name": "Meditation",
      "start_local_dt": "2026-01-22 08:00:00",
      "intended_sec": 300,
      "actual_sec": 420,
      "bonus_sec": 120,
      "timezone_id": 1,
      "updated_at_utc": "2026-01-22 15:07:00"
    }
  ],
  "total_count": 42
}
```

### Dashboard Template Update

```php
// Add to dashboard template
<section class="completed-sessions-section">
  <h2>Recent Completed Sessions</h2>
  <div class="completed-sessions-list" id="completed-sessions">
    <!-- Populated by JavaScript -->
  </div>
  <button class="load-more" id="load-more-sessions">Load More</button>
</section>
```

### Completed Session Widget

```javascript
function createCompletedSessionWidget(session) {
  var bonusDisplay = session.bonus_sec > 0
    ? `+${formatDuration(session.bonus_sec)} bonus`
    : 'Stopped early';

  return `
    <a href="/mg/${session.session_key}" class="session-widget completed">
      <div class="activity-name">${session.activity_name}</div>
      <div class="completion-date">${formatDate(session.updated_at_utc)}</div>
      <div class="duration">
        Duration: ${formatDuration(session.actual_sec)}
        <span class="bonus">(${bonusDisplay})</span>
      </div>
    </a>
  `;
}
```

---

## Read-Only Timer View for Completed Sessions

When clicking a completed session, `/mg/{session_key}` should detect that `actual_sec` is set and show a static/read-only view:

### Changes to `/mg/index.php` or JavaScript

```javascript
// In loadAndResumeSession():
if (session.actual_sec !== null) {
  // Session is completed - show read-only view
  console.log('Session completed - showing static view');

  // Set timer to final bonus time
  clock.setTime(session.bonus_sec || 0);
  changePageColor(successBGColor);

  // Hide controls
  $('.start').hide();
  $('.stop').hide();
  $('.duration-field-wrapper').hide();

  // Show completion message
  showCompletionSummary(session);
}
```

### Completion Summary Display

```html
<div class="completion-summary">
  <h3>Session Completed</h3>
  <p><strong>Activity:</strong> Meditation</p>
  <p><strong>Started:</strong> Jan 22, 2026 at 9:00 AM</p>
  <p><strong>Intended:</strong> 5 minutes</p>
  <p><strong>Actual:</strong> 7 minutes</p>
  <p><strong>Bonus:</strong> 2 minutes</p>
  <p class="completed-at">Completed at 9:07 AM</p>
</div>
```

---

## Implementation Steps

### Phase 1: Active Sessions
1. ✅ Create `/api/list-active-sessions.php` endpoint
2. ✅ Update `/` (wwwroot/index.php) to show dashboard for admin users
3. ✅ Create dashboard template (`templates/dashboard/active_sessions.tpl.php`)
4. ✅ Create dashboard JavaScript (`wwwroot/dashboard/dashboard.js`)
5. ✅ Create dashboard CSS (`wwwroot/dashboard/dashboard.css`)
6. ✅ Add "Start New Timer" button
7. ✅ Test with multiple active sessions

### Phase 2: Completed Sessions
1. ✅ Create `/api/list-completed-sessions.php` endpoint
2. ✅ Update dashboard template to include completed sessions section
3. ✅ Add JavaScript to load and render completed sessions
4. ✅ Update `/mg/{session_key}` to show read-only view for completed sessions
5. ✅ Add pagination/load-more functionality
6. ✅ Test viewing archived sessions

---

## Design Decisions

### 1. **Should we auto-refresh active sessions?**
- ✅ **YES** - Refresh every 30 seconds to show new sessions started in other tabs
- Alternative: Use WebSockets for real-time updates (future enhancement)

### 2. **How many completed sessions to show initially?**
- ✅ **10 sessions** - with "Load More" button
- Can paginate in increments of 10

### 3. **Should completed sessions be deletable?**
- 🔄 **LATER** - Add delete/archive functionality in future version
- For now: view-only

### 4. **Should we show sessions from all devices?**
- ✅ **YES** - Show all sessions for user_id, regardless of device
- Helps with cross-device tracking

### 5. **What happens if admin starts timer on dashboard?**
- ✅ Clicking "Start New Timer" redirects to `/mg/`
- Starting timer at `/mg/` creates session and redirects to `/mg/{session_key}`
- Dashboard refreshes automatically every 30s and picks up new session

### 6. **Should we show public sessions from other users?**
- 🔄 **LATER** - Future feature: public activity feed
- For now: only show current user's sessions

---

## UI/UX Mockup

```
┌─────────────────────────────────────────────────────────┐
│  My Active Sessions                  [+ Start New Timer]│
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │ 🟡 Work      │  │ 🟢 Meditation│  │ 🟡 Sleeping  │  │
│  │ Intended: 1h │  │ Intended: 5m │  │ Intended: 8h │  │
│  │ Elapsed: 42m │  │ Elapsed: 7m  │  │ Elapsed: 3h  │  │
│  │ Counting Down│  │ Bonus Time   │  │ Counting Down│  │
│  │ dQw4w9WgXcQ  │  │ jNQXAC9IVRw  │  │ abc123defgh  │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  │
│                                                          │
├─────────────────────────────────────────────────────────┤
│  Recent Completed Sessions                               │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ┌──────────────────────────────────┐                   │
│  │ ✅ Meditation                    │                   │
│  │ Jan 22, 2026 at 8:07 AM          │                   │
│  │ Duration: 7 minutes (+2m bonus)  │                   │
│  └──────────────────────────────────┘                   │
│                                                          │
│  ┌──────────────────────────────────┐                   │
│  │ ✅ Work                          │                   │
│  │ Jan 21, 2026 at 5:30 PM          │                   │
│  │ Duration: 2h 15m (+15m bonus)    │                   │
│  └──────────────────────────────────┘                   │
│                                                          │
│              [Load More Sessions]                        │
└─────────────────────────────────────────────────────────┘
```

---

## Future Enhancements (Phase 3+)

- **Statistics**: Total time per activity, streaks, charts
- **Filters**: Filter by activity type, date range
- **Search**: Search sessions by date or activity
- **Export**: Export session data to CSV/JSON
- **Public Feed**: View other users' public sessions
- **Social Features**: Follow friends, compete on leaderboards
- **Calendar View**: See sessions on a calendar
- **Tags**: Add custom tags to sessions
- **Notes**: Add notes/reflections to completed sessions

---

## Conclusion

This two-phase approach provides:
1. **Phase 1**: Immediate value for Pro/admin users with multiple concurrent timers
2. **Phase 2**: Historical tracking and session review

Both phases maintain the simple, minimal aesthetic while adding powerful multi-session management.
