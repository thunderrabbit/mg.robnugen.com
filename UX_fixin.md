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

### 3. Make edit form AJAX like send/archive
- **Where**: `templates/inbox/index.tpl.php` lines 253-270
- **Problem**: Send and archive use AJAX (smooth, no reload). Edit does a full page POST, causing reload and scroll-to-top. Editing a message near the bottom of the list is jarring.
- **Fix**: Convert edit form to fetch() with inline status, same pattern as sendReply().

### 4. Add pagination controls
- **Where**: `wwwroot/inbox/index.php` line 152 (`LIMIT 100`), `templates/inbox/index.tpl.php`
- **Problem**: No pagination. Once there are 100+ messages, older ones silently disappear with no indication there are more.
- **Fix**: Add total count query, show "Page 1 of N" with prev/next links.

## Medium effort

### 5. Remove delete from default view, add undo
- **Where**: `templates/inbox/index.tpl.php` lines 238-243
- **Problem**: Delete is a hard delete with just a `confirm()` dialog. Archive and Delete buttons are adjacent — one misclick and the message is gone forever.
- **Fix**: Only show Delete on archived messages. Or replace confirm() with a brief "Undo" toast that delays the actual deletion.

### 6. Fix success flash/reload race
- **Where**: `templates/inbox/index.tpl.php` lines 87-92
- **Problem**: After successful send, shows success message for 800ms then fires `window.location.reload()`. On fast connections it's a brief flash; on slow connections the message sits there during page load.
- **Fix**: Either insert the new message into the DOM without reloading, or reload immediately without the flash.

## Deferred (until agent_inbox_user is built)

### 7. No way to know if a message was meant for a specific agent
- Covered by `DESIGN_agent_inbox_user.md`. Agents currently read all messages and parse text prefixes like "From Carrie" to skip their own.

### 8. Reply has no parent linkage
- Reply prefills `re: #123` as text but there's no `parent_id` or threading. If the user edits out the prefix, the relationship is lost. Will matter more once routing is in place.

### 9. Mark-seen returns `{updated: 0}` silently if already seen
- Agent can't distinguish "already seen" from "message doesn't exist" without a separate lookup. Could return `{updated: 0, reason: "already_seen"}` or similar.

### 10. No bulk operations for agents
- Carrie processes 5+ messages per run, each mark-seen/mark-done is a separate HTTP request. A batch endpoint (`POST /inbox/mark-seen` with `message_ids: [1,2,3]`) would reduce round-trips.

### 11. "Show after" date picker has no visible placeholder
- `placeholder` attribute doesn't display on `<input type="date">` in most browsers. The label "Show after" is clear enough but the empty state looks like a bug rather than "visible immediately."
