# UX Fixes: Agent Inbox

## Quick wins

### ~~1. Consistent response field names (`ok` vs `success`)~~ FIXED 2026-03-22
- **Where**: `sendReply()` in `templates/inbox/index.tpl.php` line 383 checks `data.ok`; main send form line 86 checks `data.success`; web controller `wwwroot/inbox/index.php` line 63 returns `{ok: true}`; API `_inbox.php` line 114 returns `{success: true}`
- **Problem**: Two code paths, two different field names. Works by accident today.
- **Fix**: Pick one field name and use it everywhere.

### ~~2. Add `has_more` to API list response~~ FIXED 2026-03-22
- **Where**: `wwwroot/api/v1/_inbox.php` lines 68-73
- **Problem**: API returns `total`, `limit`, `offset` but no `has_more`. Agents have to do math to know if there are more pages.
- **Fix**: Add `'has_more' => ($offset + $limit) < $total` to the JSON response.

## Low effort

### ~~3. Make edit form AJAX like send/archive~~ FIXED 2026-03-22
- **Where**: `templates/inbox/index.tpl.php` lines 253-270
- **Problem**: Send and archive use AJAX (smooth, no reload). Edit does a full page POST, causing reload and scroll-to-top. Editing a message near the bottom of the list is jarring.
- **Fix**: Convert edit form to fetch() with inline status, same pattern as sendReply().

### ~~4. Add pagination controls~~ FIXED 2026-03-22
- **Where**: `wwwroot/inbox/index.php` line 152 (`LIMIT 100`), `templates/inbox/index.tpl.php`
- **Problem**: No pagination. Once there are 100+ messages, older ones silently disappear with no indication there are more.
- **Fix**: Add total count query, show "Page 1 of N" with prev/next links.

## Medium effort

### 6. Fix success flash/reload race
- **Where**: `templates/inbox/index.tpl.php` lines 87-92
- **Problem**: After successful send, shows success message for 800ms then fires `window.location.reload()`. On fast connections it's a brief flash; on slow connections the message sits there during page load.
- **Fix**: Either insert the new message into the DOM without reloading, or reload immediately without the flash.

## Lower priority

### 9. Mark-seen returns `{updated: 0}` silently if already seen
- Agent can't distinguish "already seen" from "message doesn't exist" without a separate lookup. Could return `{updated: 0, reason: "already_seen"}` or similar.

### ~~10. No bulk operations for agents~~ FIXED 2026-03-22 (mark-seen-bulk only)
- Added `PATCH /inbox/mark-seen-bulk` accepting `message_ids` array, plus `mark_inbox_seen_bulk` Jikan tool. Bulk mark-done intentionally omitted — each done message should get its own agent response.

### 11. "Show after" date picker has no visible placeholder
- `placeholder` attribute doesn't display on `<input type="date">` in most browsers. The label "Show after" is clear enough but the empty state looks like a bug rather than "visible immediately."

### 12. Soft delete via `deleted_after` datetime
- **Where**: `agent_inbox` table, `_inbox.php`, `wwwroot/inbox/index.php`
- **Problem**: Delete is a hard delete — one misclick and the message is gone forever.
- **Fix**: Add a `deleted_after` DATETIME column to `agent_inbox`. When the user clicks Delete, set `deleted_after` to NOW() + a grace period (e.g. 5 minutes). A cron job or scheduled query purges rows where `deleted_after < NOW()`. All list queries filter out rows where `deleted_after IS NOT NULL`. This gives a brief window to "undelete" by clearing the field, without changing the current UI flow.
