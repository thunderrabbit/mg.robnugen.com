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
- [ ] **A3.** [WD] Test: type multi-byte characters (emoji, Japanese), verify counter reflects byte count not char count (needs Vagrant)
- [x] **A4.** Add 10KB server-side length check on `message` in `/send` and `/edit` (both `_inbox.php` and `wwwroot/inbox/index.php`)
  - `strlen()` not `mb_strlen()`
  - Return 400 with `error: "message exceeds 10240 byte limit (N bytes)"`
- [x] **A5.** Add 10KB server-side length check on `response` in `/mark-done` (`_inbox.php`)
- [ ] **A6.** [Unit] Test: POST to `/send` with 10241-byte message returns 400. POST with 10240 bytes succeeds. (needs Vagrant)
- [x] **A7.** Add rate limiting: 1 credit charged per `POST /send` in `_inbox.php`
- [ ] **A8.** [Manual] Verify rate limit: send 3 messages rapidly via curl, confirm credit deduction or throttle
- [ ] Deploy session A. Update `UX_fixin.md` to mark security items done.

---

## Session B: Schema + Migration

- [ ] **B1.** Review `db_schemas/22_agent_inbox_user/create_agent_inbox_user.sql` one final time against design doc (renumbered from 21 — schema 21 is now the timezone fix)
- [ ] **B2.** Run migration via `/admin/migrate_tables.php`
- [ ] **B3.** [Manual] Verify in PHPMyAdmin:
  - `agent_inbox_user` table exists with correct columns and defaults (booleans = 0)
  - One human actor per existing user with all booleans = 1
  - `inbox_visibility` has NULL-peer rows for each human (can_read=1, can_send=1)
  - `api_keys` has `aiu_id` column, all keys point to their user's human actor
  - `agent_inbox` has `sender_aiu` and `recipient_aiu` columns (all NULL for existing rows)
- [ ] **B4.** Create agent actors via PHPMyAdmin: Boss Claude, Carrie (with appropriate booleans)
- [ ] **B5.** Create `inbox_visibility` rows for each agent (self can_read, explicit can_send to Rob/Boss Claude)
- [ ] **B6.** Reassign Carrie's API key from human actor to Carrie actor in `api_keys.aiu_id`
- [ ] **B7.** Reassign Boss Claude's API key similarly
- [ ] **B8.** [Manual] Verify: `SELECT * FROM agent_inbox_user` shows correct actors. `SELECT * FROM inbox_visibility` shows correct permissions. `SELECT api_key_id, aiu_id FROM api_keys` shows correct assignments.
- [ ] Deploy session B (schema only — API doesn't use the new columns yet, so nothing breaks).

---

## Session C: API — Actor Lookup in index.php

- [ ] **C1.** Add `$auth_actor` fetch in `wwwroot/api/v1/index.php` — JOIN `api_keys.aiu_id` → `agent_inbox_user` row. Make it available to all handler files.
- [ ] **C2.** [Manual] Verify: add temporary `echo json_encode($auth_actor); exit;` at top of `_inbox.php`, call via curl, confirm actor row is returned. Remove debug line.
- [ ] Deploy session C.

---

## Session D: API — Boolean Permission Guards

- [ ] **D1.** Add read/write guard to `_inbox.php` (check `can_read_inbox` / `can_write_inbox`)
- [ ] **D2.** Add read/write guard to `_todos.php` (check `can_read_todos` / `can_write_todos`)
- [ ] **D3.** Add read/write guard to `_sessions.php` (check `can_read_sessions` / `can_write_sessions`)
- [ ] **D4.** Add read/write guard to `_emotions.php` (check `can_read_emotions` / `can_write_emotions`)
- [ ] **D5.** [Unit] Test: create a mock actor with `can_read_todos=0`, verify GET /todos returns 403
- [ ] **D6.** [Unit] Test: same actor with `can_write_todos=0`, verify POST /todos returns 403
- [ ] **D7.** [Manual] Verify Carrie can still list inbox and send (her booleans should allow it). Verify she gets 403 on emotions if you set `can_read_emotions=0`.
- [ ] Deploy session D. Existing behavior unchanged for human keys (all booleans = 1).

---

## Session E: API — Inbox Visibility Filtering

- [x] **E1.** Update `list_inbox` in `_inbox.php` to query `inbox_visibility` for the caller's readable set. Supervisor (NULL peer) skips filter. Scoped agents get `WHERE recipient_aiu IN (readable set) OR recipient_aiu IS NULL`.
- [x] **E2.** Add `include_sent` parameter support: `OR sender_aiu = :caller_aiu`
- [x] **E3.** Add `sender_aiu` filter parameter: `AND sender_aiu = :sender_aiu`
- [ ] **E4.** [Unit] Test: supervisor sees all messages. Scoped agent sees only own + broadcasts. `include_sent=1` adds sent messages.
- [ ] **E5.** [Manual] Call `list_inbox` with Carrie's API key — verify she only sees messages addressed to her and broadcasts.
- [ ] Deploy session E.

---

## Session F: API — Send with Routing

- [ ] **F1.** Auto-populate `sender_aiu` from `$auth_actor['aiu_id']` on `POST /send` in `_inbox.php`
- [ ] **F2.** Accept optional `recipient_aiu` parameter on `POST /send`
- [ ] **F3.** Add send permission check: query `inbox_visibility` for `can_send=1` on the target peer. Return 403 if not allowed.
- [ ] **F4.** Same changes in `wwwroot/inbox/index.php` (web form): auto-populate sender, accept recipient dropdown (placeholder — UI comes in session I)
- [ ] **F5.** [Unit] Test: agent with `can_send=1` for peer X can send to X. Agent without `can_send` for peer Y gets 403.
- [ ] **F6.** [Manual] Send a message via curl with Carrie's key and `recipient_aiu` for Rob — verify it saves with correct sender/recipient. Try sending to Grove — verify 403.
- [ ] Deploy session F.

---

## Session G: API — list_actors Endpoint

- [x] **G1.** Add `GET /inbox/actors` endpoint in `_inbox.php` — returns `aiu_id`, `name`, `description` for all actors in caller's `user_id`. Gated by `can_write_inbox`.
- [ ] **G2.** [Unit] Test: agent with `can_write_inbox=1` gets actor list. Agent with `can_write_inbox=0` gets 403.
- [x] **G3.** Update API 404 hint to include `/inbox/actors`
- [ ] Deploy session G.

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
- [ ] **J5.** [WD] Test: send a message with a recipient, verify sender/recipient names display correctly
- [ ] Deploy session J.

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
