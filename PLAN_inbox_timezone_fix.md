# Plan: Fix agent_inbox Timezone Handling

## Problem

`agent_inbox.created_at` stores timestamps using `CURRENT_TIMESTAMP`, which uses the DB session timezone. DreamHost MySQL defaults to US Pacific (UTC-7 PDT / UTC-8 PST). All API writes go through DreamHost PHP, so all inbox timestamps are consistently Pacific time — but there's no timezone indicator on the column. Carrie reads `created_at` and misinterprets it as Adelaide time, causing journal entries to be filed on the wrong date.

## Current state

- `agent_inbox`: `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` — no TZ info
- `todos`: `created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` — named `_utc` but actually Pacific (same `Base.php` connection timezone)
- `todo_logs`: has a `timezone VARCHAR(64) NULL` column storing IANA timezone string (e.g. `'Asia/Tokyo'`)
- `activity_kai` (sessions): has `timezone_id SMALLINT UNSIGNED` FK to `timezones` table, plus `created_at_utc DATETIME`
- Web UI: detects browser timezone via `Intl.DateTimeFormat().resolvedOptions().timeZone` (see `dashboard.js:240`)
- Jikan `send_inbox`: no timezone parameter

## Root cause

`Base.php` sets `SET time_zone` to the connecting PHP process's local timezone (DreamHost = Pacific). So `CURRENT_TIMESTAMP` and `NOW()` store Pacific time. Every `_utc` column across the whole app (todos, sessions, etc.) is actually Pacific despite the name. Carrie assumes the times are in her timezone (Adelaide).

## Decision: Option D — fix at the source + backfill everything

### Step 0: Fix `Base.php` to use UTC (one-line change)

Change `classes/Database/Base.php` line ~35 from:

```php
$offset = sprintf('%+d:%02d', $hrs*$sgn, $mins);
```

to:

```php
$offset = '+00:00';  // Always UTC — columns named _utc should contain UTC
```

(Do the same on the retry block ~line 51.)

After this change, all `CURRENT_TIMESTAMP`, `NOW()`, and `DEFAULT CURRENT_TIMESTAMP` calls store UTC. Every new row in every table is correct from this point forward.

**Audit of `NOW()` usage** — all safe to switch:

| File | Usage | Impact |
|------|-------|--------|
| `_inbox.php` | `seen_at`, `done_at`, `archived_at` = NOW() | Self-consistent — all shift together |
| `inbox/index.php` | `show_date <= NOW()` | DATE comparison, off by hours won't matter |
| `_stats.php` | `DATE_SUB(NOW(), 90 DAY)` | Negligible boundary shift |
| `Ledger.php` | `last_event_time` write + gap check | Self-consistent |
| `ApiKey.php` | `last_used = NOW()` | Cosmetic |
| `OmgAlerts.php` | `acknowledged_at = NOW()` | Cosmetic |

No `CURRENT_TIMESTAMP` or `CURDATE()` in PHP code — only in schema DEFAULTs.

### Step 1: Backfill all existing `_utc` columns across all tables

One migration that converts every Pacific timestamp to UTC. This covers the whole app, not just inbox.

**Verify offset at migration time** — run in PHPMyAdmin immediately before:

```sql
SELECT NOW(), UTC_TIMESTAMP(), TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS offset;
```

Confirmed as `-07:00` (PDT) on 2026-03-24. Use this value in the migration. If running during PST, it will be `-08:00`.

```sql
-- Backfill agent_inbox
UPDATE agent_inbox
SET created_at = CONVERT_TZ(created_at, '-07:00', '+00:00'),
    updated_at = CONVERT_TZ(updated_at, '-07:00', '+00:00');

-- Backfill agent_inbox status timestamps
UPDATE agent_inbox SET seen_at = CONVERT_TZ(seen_at, '-07:00', '+00:00') WHERE seen_at IS NOT NULL;
UPDATE agent_inbox SET done_at = CONVERT_TZ(done_at, '-07:00', '+00:00') WHERE done_at IS NOT NULL;
UPDATE agent_inbox SET archived_at = CONVERT_TZ(archived_at, '-07:00', '+00:00') WHERE archived_at IS NOT NULL;

-- Backfill todos
UPDATE todos
SET created_at_utc = CONVERT_TZ(created_at_utc, '-07:00', '+00:00'),
    updated_at_utc = CONVERT_TZ(updated_at_utc, '-07:00', '+00:00');

-- Backfill todo_logs
UPDATE todo_logs
SET created_at_utc = CONVERT_TZ(created_at_utc, '-07:00', '+00:00'),
    updated_at_utc = CONVERT_TZ(updated_at_utc, '-07:00', '+00:00');

-- Backfill activity_kai (sessions)
UPDATE activity_kai
SET created_at_utc = CONVERT_TZ(created_at_utc, '-07:00', '+00:00'),
    updated_at_utc = CONVERT_TZ(updated_at_utc, '-07:00', '+00:00');

-- Backfill activities
UPDATE activities
SET created_at_utc = CONVERT_TZ(created_at_utc, '-07:00', '+00:00');

-- Backfill activity_session_keys
UPDATE activity_session_keys
SET created_at_utc = CONVERT_TZ(created_at_utc, '-07:00', '+00:00');

-- Backfill emotional ledger
UPDATE interaction_sessions
SET last_event_time = CONVERT_TZ(last_event_time, '-07:00', '+00:00');

UPDATE interaction_events
SET event_timestamp = CONVERT_TZ(event_timestamp, '-07:00', '+00:00');

-- Backfill api_keys
UPDATE api_keys SET last_used = CONVERT_TZ(last_used, '-07:00', '+00:00') WHERE last_used IS NOT NULL;

-- Backfill omg_alerts
UPDATE omg_rob_this_happened
SET acknowledged_at = CONVERT_TZ(acknowledged_at, '-07:00', '+00:00') WHERE acknowledged_at IS NOT NULL;
```

**Important:** Run the `Base.php` change and this migration at the same time. If you deploy `Base.php` first without backfilling, new rows will be UTC while old rows are Pacific. If you backfill first without deploying `Base.php`, old rows become UTC but new rows are still Pacific.

### Step 2: Add `sender_timezone` and rename inbox columns

After the UTC fix is in place, this is the inbox-specific work:

```sql
-- Add sender_timezone
ALTER TABLE agent_inbox
    ADD COLUMN sender_timezone VARCHAR(64) NULL AFTER priority;

-- Rename columns to reflect they are now truly UTC
ALTER TABLE agent_inbox
    CHANGE COLUMN created_at created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CHANGE COLUMN updated_at updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);
```

Now `CURRENT_TIMESTAMP` stores UTC (because of the `Base.php` change), and the column names are truthful.

## Decision: follow todo_logs pattern for sender_timezone

Use a simple `VARCHAR(64)` for timezone (IANA string), matching `todo_logs`. Simpler than the `timezones` table FK used by sessions — no JOIN needed, and the timezone string is directly usable by PHP's `DateTimeZone`.

## Verify timezone offset at migration time

Run this in PHPMyAdmin immediately before running the migration:

```sql
SELECT NOW(), UTC_TIMESTAMP(), TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS offset;
```

Use that offset value in Step 2 instead of hardcoding `-07:00`.

## PHP changes

### `_inbox.php` — POST /send

Add `sender_timezone` to the INSERT (timestamps are now correct automatically via `Base.php` UTC fix):

```php
$sender_timezone = trim($input['sender_timezone'] ?? '');

$stmt = $pdo->prepare(
    "INSERT INTO agent_inbox (user_id, message, priority, show_date, sender_timezone)
     VALUES (?, ?, ?, ?, ?)"
);
$stmt->execute([$auth_user_id, $message, $priority, $show_date, $sender_timezone ?: null]);
```

### `wwwroot/inbox/index.php` — web form send

Add browser timezone detection to the send form (same pattern as `dashboard.js`):

```javascript
var senderTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
```

Include `sender_timezone` in the form POST data.

### `_inbox.php` — GET /list

Include `sender_timezone` in the SELECT output so Carrie can read it. Column is already renamed to `created_at_utc` by the migration.

## Jikan changes

### `server.py` — `send_inbox`

Add optional `sender_timezone` parameter:

```python
def send_inbox(message: str, priority: str = "normal", sender_timezone: str = "") -> dict:
```

Jikan's default: detect from the system running Jikan (`time.tzname` or `tzlocal`). This means:
- When Rob sends from phone via web → browser detects timezone → correct
- When Carrie sends via Jikan on Lemur 13 → Lemur 13's timezone (Adelaide) → correct for Carrie's location
- When Boss Claude sends via Jikan on Lemur 13 → Adelaide → correct if Rob is in Adelaide

For the "Rob's current timezone" problem: Jikan could accept an explicit timezone override, or read it from OpenBrain/a config value. For now, defaulting to the system timezone covers the common case. When Rob travels, he can pass `sender_timezone` explicitly, or we update a config value.

## Carrie prompt changes

Update Carrie's journal writing logic:

1. Read `created_at_utc` (UTC) and `sender_timezone` from the inbox message
2. If `sender_timezone` is set: convert `created_at_utc` to that timezone for the journal date
3. If `sender_timezone` is NULL (legacy messages): fall back to Rob's current timezone from OpenBrain

This replaces the current approach of using `created_at` (Pacific time) and assuming it's Adelaide.

## Rob's current timezone (Option B)

Store in OpenBrain:
```
Rob's current timezone: Australia/Adelaide (updated 2026-03-19)
```

Rob updates this when he moves cities. Carrie reads it at the start of each run as a fallback for messages without `sender_timezone`.

Future enhancement: store it as a user preference in `user_settings` on mg.robnugen.com so the web UI can also use it.

## Migration ordering

Current IMPLEMENTATION.md has schema 21 (agent_inbox_user) as step 1. This timezone fix should be:

- **New schema 22** (or renumber 21 to 22 and make this 21)
- Run BEFORE agent_inbox_user because both alter `agent_inbox`
- The agent_inbox_user migration's `ALTER TABLE agent_inbox ADD COLUMN sender_aiu...` should run after the timezone columns exist

## Commits — Status

### Done (2026-03-24)

- [x] **1. `Base.php`**: changed session timezone to `+00:00` (commit af49937)
- [x] **2. Backfill**: `DATE_ADD(..., INTERVAL 7 HOUR)` on all tables — agent_inbox, todos, todo_logs, activity_kai, activities, activity_session_keys, interaction_sessions, interaction_events, api_keys, omg_rob_this_happened. Run manually via PHPMyAdmin.
- [x] **Verified**: new inbox message #247 stored as `11:59 UTC` (correct). Old message #245 shifted from `03:05` Pacific to `10:05` UTC (correct).

### Next session

- [ ] **3. Schema migration**: add `sender_timezone VARCHAR(64)` to `agent_inbox`, rename `created_at`/`updated_at` to `_utc`
- [ ] **4. PHP**: `_inbox.php` POST /send — accept `sender_timezone`, include in INSERT
- [ ] **5. PHP**: `_inbox.php` GET /list — include `sender_timezone` in SELECT output
- [ ] **6. PHP**: `wwwroot/inbox/index.php` — add browser timezone detection via JS, include in form POST
- [ ] **7. Jikan**: `send_inbox` — add optional `sender_timezone` parameter
- [ ] **8. OpenBrain**: seed "Rob's current timezone: Australia/Adelaide" entry
- [ ] **9. Carrie prompt**: use `created_at_utc` + `sender_timezone` for journal dates
- [ ] **10. Update IMPLEMENTATION.md** ordering (timezone fix before agent_inbox_user)

### Testing (after steps 3-9)

- [ ] [Manual] Send inbox message via web, verify `sender_timezone` and `created_at_utc` are stored correctly
- [ ] [Manual] Send via Jikan, verify same
- [ ] [Manual] Verify Carrie creates a journal entry with the correct date after the changes
- [ ] [Manual] Check existing messages still display correctly (legacy NULL `sender_timezone` falls back to OpenBrain timezone)
