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

`Base.php` sets `SET time_zone` to the connecting PHP process's local timezone (DreamHost = Pacific). So `CURRENT_TIMESTAMP` stores Pacific time. The column has no indication of this. Carrie assumes the times are in her timezone (Adelaide).

## Decision: follow todo_logs pattern

Use a simple `VARCHAR(64)` for timezone (IANA string), matching `todo_logs`. Simpler than the `timezones` table FK used by sessions — no JOIN needed, and the timezone string is directly usable by PHP's `DateTimeZone`.

## Schema migration (22_inbox_timezone)

**Must run BEFORE schema 21 (agent_inbox_user).** Update the IMPLEMENTATION.md to reflect this ordering.

```sql
-- Step 1: Add timezone column and UTC timestamps
ALTER TABLE agent_inbox
    ADD COLUMN sender_timezone VARCHAR(64) NULL AFTER priority,
    ADD COLUMN created_at_utc DATETIME(6) NULL AFTER created_at,
    ADD COLUMN updated_at_utc DATETIME(6) NULL AFTER updated_at;

-- Step 2: Backfill UTC values from existing Pacific timestamps
-- DreamHost MySQL is UTC-7 (PDT). Convert existing values:
UPDATE agent_inbox
SET created_at_utc = CONVERT_TZ(created_at, '-07:00', '+00:00'),
    updated_at_utc = CONVERT_TZ(updated_at, '-07:00', '+00:00');

-- Step 3: Make UTC columns NOT NULL with defaults
ALTER TABLE agent_inbox
    MODIFY COLUMN created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
    MODIFY COLUMN updated_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)) ON UPDATE CURRENT_TIMESTAMP(6);
```

**Note:** Step 2 uses `-07:00` (PDT). Verify this is correct at migration time — DreamHost might be PST (`-08:00`) depending on daylight saving. The query `SELECT NOW(), UTC_TIMESTAMP()` at migration time will confirm.

**Note:** Keep the old `created_at` and `updated_at` columns for now — don't drop them until everything is verified working. They can be removed in a later migration.

## Verify timezone offset at migration time

Run this in PHPMyAdmin immediately before running the migration:

```sql
SELECT NOW(), UTC_TIMESTAMP(), TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS offset;
```

Use that offset value in Step 2 instead of hardcoding `-07:00`.

## PHP changes

### `_inbox.php` — POST /send

Currently:
```php
$stmt = $pdo->prepare(
    "INSERT INTO agent_inbox (user_id, message, priority, show_date)
     VALUES (?, ?, ?, ?)"
);
$stmt->execute([$auth_user_id, $message, $priority, $show_date]);
```

Change to:
```php
$sender_timezone = trim($input['sender_timezone'] ?? '');

$stmt = $pdo->prepare(
    "INSERT INTO agent_inbox (user_id, message, priority, show_date, sender_timezone, created_at_utc, updated_at_utc)
     VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
);
$stmt->execute([$auth_user_id, $message, $priority, $show_date, $sender_timezone ?: null]);
```

### `wwwroot/inbox/index.php` — web form send

Add browser timezone detection to the send form (same pattern as `dashboard.js`):

```javascript
// Add to the send form JS
var senderTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
```

Include `sender_timezone` in the form POST data.

### `_inbox.php` — GET /list

Include `sender_timezone` and `created_at_utc` in the SELECT output so Carrie can read them.

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

## Commits

1. Schema migration file (`db_schemas/22_inbox_timezone/`)
2. PHP: `_inbox.php` send + list changes
3. PHP: `wwwroot/inbox/index.php` web form + JS timezone detection
4. Jikan: `send_inbox` timezone parameter
5. OpenBrain: seed "Rob's current timezone" entry
6. Carrie prompt: use `created_at_utc` + `sender_timezone` for journal dates
7. Update IMPLEMENTATION.md ordering

## Testing

- [Manual] Send inbox message via web, verify `sender_timezone` and `created_at_utc` are stored correctly
- [Manual] Send via Jikan, verify same
- [Manual] Verify Carrie creates a journal entry with the correct date after the changes
- [Manual] Check existing messages still display correctly (legacy NULL `sender_timezone` falls back to OpenBrain timezone)
