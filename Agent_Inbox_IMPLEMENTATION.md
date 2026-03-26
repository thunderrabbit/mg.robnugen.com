# Implementation Plan: Agent Inbox Users

Reference: `DESIGN_agent_inbox_user.md`

Each checkbox describes at least one commit. Once all related commits are finished, check the box as completed and include with the final commit.
Each session ends with deployable code.
Tests marked [Unit] are Codeception unit tests. Tests marked [WD] are Webdriver tests. Tests marked [Manual] are one-time verification via curl/PHPMyAdmin.

---

## Prerequisite: Timezone Fix (DONE — deployed on `master`)

Completed 2026-03-24/25. See `PLAN_inbox_timezone_fix.md` for details.

- [x] `Base.php` changed to always use UTC for DB session timezone
- [x] All existing timestamps backfilled from Pacific to UTC across all tables
- [x] Schema 21 (`db_schemas/21_inbox_timezone/`): added `sender_timezone VARCHAR(64)` to `agent_inbox`, renamed `created_at`/`updated_at` to `_utc`
- [x] PHP: `_inbox.php` and `wwwroot/inbox/index.php` accept and validate `sender_timezone`
- [x] JS: browser timezone auto-detected via `Intl.DateTimeFormat().resolvedOptions().timeZone`
- [x] Jikan: `send_inbox` auto-detects `sender_timezone`
- [x] OpenBrain: seeded "Rob's current timezone: Australia/Adelaide"
- [x] Carrie prompt: uses `created_at_utc` + `sender_timezone` for journal dates

**Impact on this plan:** Schema 21 is now taken by the timezone fix. The `agent_inbox_user` schema must use **22** (or higher). Session B references below are updated accordingly.

---

## Session A: Security Hardening

Independent of identity work. Can deploy immediately.

- [x] **A1.** Add UTF-8 character counter JS to main send textarea (`templates/inbox/index.tpl.php`)
  - Show "X / 10,240 bytes" below textarea, warn visually (red) near limit
  - Count bytes via `new Blob([str]).size` for accuracy
- [x] **A2.** Add character counter to reply textarea and edit textarea (same file)
- [x] **A4.** Add 10KB server-side length check on `message` in `/send` and `/edit` (both `_inbox.php` and `wwwroot/inbox/index.php`)
  - `strlen()` not `mb_strlen()`
  - Return 400 with `error: "message exceeds 10240 byte limit (N bytes)"`
- [x] **A5.** Add 10KB server-side length check on `response` in `/mark-done` (`_inbox.php`)
- [x] **A7.** Add rate limiting: 1 credit charged per `POST /send` in `_inbox.php`
- [x] **A3.** [WD] Test written by mgTester — InboxByteCounterCest.php (all passing)
- [x] **A6.** [Unit] Test written by mgTester — InboxMessageLimitTest.php (all passing)
- [ ] **A8.** [Manual] Verify rate limit: send 3 messages rapidly via curl, confirm credit deduction or throttle
- [x] Deploy session A.

---

## Session B: Schema + Migration

- [x] **B1.** Review `db_schemas/22_agent_inbox_user/create_agent_inbox_user.sql` one final time against design doc
- [x] **B2.** Run migration via PHPMyAdmin
- [x] **B3.** [Manual] Verified all tables, columns, defaults, visibility rows, key assignments
- [x] **B4.** Created agent actors: Boss Claude (8), Carrie (9), mgTester (10)
- [x] **B5.** Created `inbox_visibility` rows for each agent
- [x] **B6.** Carrie separated to own API key (key_id 18) with own MCP config (`carrie-mcp.json`)
- [x] **B7.** Boss Claude key (key_id 13) assigned to aiu_id 8, mgTester (key_id 16) to aiu_id 10
- [x] **B8.** Verified all assignments in PHPMyAdmin
- [x] Deploy session B.

---

## Session C: API — Actor Lookup in index.php

- [x] **C1.** Add `$auth_actor` fetch in `wwwroot/api/v1/index.php` — JOIN `api_keys.aiu_id` → `agent_inbox_user` row.
- [x] **C2.** Verified: Boss Claude, Carrie, and Grove all work with the new actor lookup.
- [x] Deploy session C.

---

## Session D: API — Boolean Permission Guards

- [x] **D1.** Add read/write guard to `_inbox.php` (can_read_inbox / can_write_inbox, with special inbox read/write split)
- [x] **D2.** Add read/write guard to `_todos.php` (can_read_todos / can_write_todos)
- [x] **D3.** Add read/write guard to `_sessions.php` (can_read_sessions / can_write_sessions)
- [x] **D4.** Add read/write guard to `_emotions.php` (can_read_emotions / can_write_emotions)
- [x] **D5-D6.** [Unit] PermissionGuardsTest.php — 32 tests, all passing (full/none/alpha/beta actors)
- [x] **D7.** Carrie verified working after deploy.
- [x] Deploy session D.

---

## Session E: API — Inbox Visibility Filtering

- [x] **E1.** Update `list_inbox` in `_inbox.php` to query `inbox_visibility` for the caller's readable set. Supervisor (NULL peer) skips filter. Scoped agents get `WHERE recipient_aiu IN (readable set) OR recipient_aiu IS NULL`.
- [x] **E2.** Add `include_sent` parameter support: `OR sender_aiu = :caller_aiu`
- [x] **E3.** Add `sender_aiu` filter parameter: `AND sender_aiu = :sender_aiu`
- [x] **E4.** [Unit] VisibilityAndRoutingTest.php — 11 tests, all passing (visibility + routing combined)
- [x] **E5.** Verified via tests: supervisor sees all, scoped agents see own + broadcasts, include_sent works.
- [x] Deploy session E.

---

## Session F: API — Send with Routing

- [x] **F1.** Auto-populate `sender_aiu` from `$auth_actor['aiu_id']` on `POST /send` in `_inbox.php`
- [x] **F2.** Accept optional `recipient_aiu` parameter on `POST /send`
- [x] **F3.** Add send permission check: query `inbox_visibility` for `can_send=1`. Returns 403 if not allowed.
- [x] **F4.** Web form: auto-populate sender_aiu, recipient dropdown added in Session J
- [x] **F5.** [Unit] Covered by VisibilityAndRoutingTest.php (Beta→Alpha allowed, Beta→None blocked)
- [x] **F6.** Verified: msg #285 sent with sender_aiu=8, recipient_aiu=1. Send permission enforced.
- [x] Deploy session F.

---

## Session G: API — list_actors Endpoint

- [x] **G1.** Add `GET /inbox/actors` endpoint in `_inbox.php` — returns `aiu_id`, `name`, `description` for all actors in caller's `user_id`. Gated by `can_write_inbox`.
- [x] **G2.** [Unit] Test: full-access gets actor list (200), alpha blocked (403). Added to PermissionGuardsTest.php by mgTester.
- [x] **G3.** Update API 404 hint to include `/inbox/actors`
- [x] Deploy session G.

---

## Session H: MCP — Jikan + gkan

- [x] **H1.** Add `recipient_aiu` optional param to `send_inbox` in `~/jikan/server.py`
- [x] **H2.** Add `sender_aiu` and `include_sent` optional params to `list_inbox` in `~/jikan/server.py`
- [x] **H3.** Add `list_actors` tool in `~/jikan/server.py`
- [x] **H4.** Repeat H1-H3 for Grove's `~/jikan/server.py` on Lemur 10 (deployed via scp)
- [x] **H5.** [Manual] Verified: `list_actors()` returns 4 actors, `send_inbox(recipient_aiu=1)` creates msg #285 with sender_aiu=8, `list_inbox(include_sent=1)` shows sent messages. gkan `list_actors()` returns Grove's account.
- [x] Deploy session H.

---

## Session I: UI — Create Agent + API Key Assignment

- [x] **I1.** Add "Create Agent" form to `/settings/` page: name, description, 8 boolean checkboxes, tabbed UI
  - On submit: INSERT into `agent_inbox_user`, INSERT self-referencing `inbox_visibility` row (can_read=1)
- [x] **I2.** Add "Inbox User" dropdown to each API key row on `/settings/` + actor dropdown on generate form
  - On change: UPDATE `api_keys.aiu_id`
- [ ] **I3.** [WD] Test: create a new agent via the form, verify it appears in the dropdown, assign a key to it (deferred to Monday)
- [x] **I4.** Add inbox visibility management: when creating an agent, show checkboxes for "can send to [each existing actor]"
  - On submit: INSERT `inbox_visibility` rows for each selected peer with `can_send=1`
- [ ] **I5.** [WD] Test: create agent, set send permissions, verify `inbox_visibility` rows exist (deferred to Monday)
- [x] Deploy session I.

---

## Session J: UI — Inbox Page Sender/Recipient Display

- [x] **J1.** JOIN `agent_inbox_user` in the inbox page query to get sender/recipient names
- [x] **J2.** Display sender name and recipient name (or "Broadcast") on each message in the inbox list
- [x] **J3.** Add recipient dropdown to the send form (populated from inline query)
- [ ] **J4.** Add "Filter by sender" and "Show messages to me / all" controls (deferred)
- [ ] **J5.** [WD] Test: send a message with a recipient, verify sender/recipient names display correctly (deferred to Monday)
- [x] Deploy session J.

---

## Session K: Agent Prompt Updates

- [x] **K1.** Update Carrie's prompt: remove "From Carrie" skip hack, use `recipient_aiu` when sending, `sender_aiu` for skip check
- [x] **K2.** Grove: no changes needed (own user_id, no other agents). `include_sent` available via updated Jikan.
- [x] **K3.** Carrie run verified: msg #286 received, reply #289 sent with sender_aiu=9, recipient_aiu=8. No text prefix. Clean.
- [x] **K4.** Grove verified earlier — API works with his key after permission guards deployed.
- [x] Deploy session K.

---

## Session L: Reply Threading (optional, lower priority)

- [ ] **L1.** Add `parent_message_id` column to `agent_inbox` (new schema 23)
- [ ] **L2.** Update `POST /send` to accept optional `parent_message_id`
- [ ] **L3.** Update reply JS to include `parent_message_id` when sending from the reply form
- [ ] **L4.** Update MCP `send_inbox` to accept `parent_message_id`
- [ ] **L5.** Display threading indicator in inbox UI (e.g., indent or "in reply to #N")
- [ ] **L6.** [WD] Test: send a reply, verify `parent_message_id` is set, verify UI shows link
- [ ] Deploy session L.

### Forwarding (future, complex)

The reply form now auto-sets `recipient_aiu` to the original sender. A "forward" would let you change that recipient — but it's messy:
- The reply text says `re: #285` implying context, but the new recipient has no context
- The message body might say "hello Bill" but you forward to Sally
- Options to explore:
  - Show the recipient dropdown on replies (let user override the auto-set recipient)
  - A separate "Forward" button that quotes the original message and lets you pick a new recipient
  - Or: just edit the "To" dropdown on the existing send form and paste content manually
- Not worth building until there's a real use case driving it.
