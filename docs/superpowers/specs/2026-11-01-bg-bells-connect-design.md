# BG Bells: connect to mg and mark meditations complete

Date: 2026-11-01. Rob, with mgClaude. Follow-up for other users: mg #801.

## Goal

When a sit ends in **BG Bells** (Rob's Android meditation timer, repo `~/work/rob/sitnow/`),
mg records two things:

1. **The sit, as a timed Meditation session** (activity 1) with its real start and end.
   Any number per day.
2. **A tick on the Meditate todo**, up to the todo's daily target (`todos.target_count`;
   Rob's Meditate is 2). A sit after the target is reached still logs a session but
   does not tick.

The phone gets in by pressing **Allow** on mg once, with no key copied by hand, and
what it can then do is limited to exactly that: Meditation sessions and the Meditate
todo. Nothing else of Rob's.

This spec is **mg's side only**. BG Bells gets its own spec in `sitnow` once this is
live and curl-testable. Modelled on games.chatforest.com's connected sites
(`docs/superpowers/specs/2026-10-31-connected-sites-design.md` there), but reusing
mg's existing OAuth + PKCE flow instead of a pairing code.

## Non-goals

- Other mg users connecting BG Bells (mg #801). Today: Rob only, one hand-made actor.
- A UI for choosing which todos an app may touch (mg #801). Grants are inserted by hand.
- Any change to the claude.ai / Exterminal connector's behaviour.

## Anti-goals

- The phone ever holding a credential that can read or write anything beyond its grants.
- The long-lived credential travelling in a redirect URL.
- A retry from the phone's queue double-logging a sit or double-ticking a todo.

## The connect flow (existing, adapted)

mg already has OAuth Authorization Code + PKCE: `wwwroot/authorize/index.php` issues a
10-minute single-use code (`oauth_auth_codes`), `wwwroot/mcp/token.php` swaps it for a
24-hour access token plus a 400-day sliding refresh token (`mcp_tokens`, rotated on each
refresh), and `Auth\ConnectedApps` lists/revokes those connections in settings and
revokes them all on password change.

1. BG Bells generates a PKCE verifier, opens mg in a **Chrome Custom Tab** (real browser,
   Rob's mg login already present) at
   `/authorize/?response_type=code&client_id=bgbells&redirect_uri=bgbells://oauth/callback&code_challenge=…&code_challenge_method=S256&state=…`
2. Rob presses **Allow**. mg redirects to `bgbells://oauth/callback?code=…&state=…`.
3. BG Bells POSTs `grant_type=authorization_code` + code + verifier + redirect_uri to
   `/mcp/token.php` and receives `access_token` and `refresh_token`.
4. BG Bells calls `/api/v1/…` with `Authorization: Bearer <access_token>`, refreshing
   via `grant_type=refresh_token` when it expires.

Why a custom scheme is safe: any Android app can claim `bgbells://`, but an
interceptor only gets a code it cannot redeem without the verifier, which never left
BG Bells.

### Changes to `/authorize`

- Add `bgbells://oauth/callback` to `$allowed_redirect_uris`.
- **Pin client `bgbells` to one actor.** For `client_id=bgbells` the approval page shows
  no actor picker: it says "Let **BG Bells** log meditation sessions and tick
  **Meditate** for you?" (titles of the actor's granted todos) with Allow / Deny, and the
  POST issues the code for the pinned actor only, ignoring any posted `aiu_id`. Without
  the pin, one slip on the picker would hand the phone Boss Claude's permissions.
  The pin is a constant mapping `bgbells` → the BG Bells actor's `aiu_id` (comment
  referencing mg #801, which replaces it with per-user actors). If the user has no such
  actor, the page says BG Bells isn't set up for this account.
- The approval page's wording today says "MCP connection"; the bgbells branch uses its
  own wording.
- Deny redirects to the callback with `error=access_denied&state=…` (standard OAuth),
  so the app can tell the user nothing was shared.
- **No remembered consent for `bgbells`:** the Allow page is always shown. `client_id` is
  self-asserted and any app can claim `bgbells://`, so a remembered consent would let a
  rogue app start its own flow (with its own verifier) and get a code silently. Connecting
  is rare (the refresh token lives 400 days), so the extra tap is cheap. My call.
- Only `client_id=bgbells` may use the `bgbells://oauth/callback` redirect, and vice versa.
- The pinned actor bypasses the approval page's existing actor filter (which lists only
  actors with `can_read_project`).

`/mcp/index.php` (the Exterminal MCP server) must **not** accept a BG Bells token: the
MCP tools are not grant-aware. Refuse tokens whose actor has `todos_limited = 1` there
(401/403). My call: simplest correct rule.

## `/api/v1` accepts Bearer tokens (limited actors only)

Today `wwwroot/api/v1/index.php` only reads `X-API-Key` and looks up the actor via
`api_keys.aiu_id`. Add: if there is no `X-API-Key` and there is an
`Authorization: Bearer` header (also check `REDIRECT_HTTP_AUTHORIZATION`, as
`mcp/index.php` does for DreamHost FastCGI), look the token up in `mcp_tokens`
(`token_hash = sha256`, `expires_at_utc > NOW()`) and take `user_id` and `aiu_id` from
the row; `$auth_actor` is that `agent_inbox_user` row (joined on both `aiu_id` and
`user_id`). **Only actors with `todos_limited = 1` may use Bearer on v1**; any other
token gets 401 `{"error":"Use X-API-Key"}`. `mcp_tokens` also holds the claude.ai /
Exterminal connector's tokens, bound to broadly-permissioned actors (Claude Phone);
without this rule they would suddenly reach all of v1, and subsystems keyed on the raw API
key (`_emotions.php` builds `Emotional\Ledger` from it) would break. My call. Expired or unknown → 401
`{"error":"Invalid or expired token"}` so the app knows to refresh. No fallback to
treating the Bearer value as an API key (unlike `/mcp/`): v1 already has `X-API-Key`
for that.

**Credits.** `require_credit()` logs to `api_usage`, whose `key_id` is `NOT NULL` with a
foreign key to `api_keys`. Migration: make `api_usage.key_id` nullable and add a nullable
`token_id` (FK `mcp_tokens`, `ON DELETE SET NULL`), so a Bearer call logs its token.
`require_credit()` takes a nullable key id plus a nullable token id.
BG Bells' writes cost credits like any other client (Rob's account has plenty). My call.

## Limiting an actor to specific todos

Migration `db_schemas/41_aiu_todo_grants/` (applied by hand via `/admin/migrate_tables.php`):

`a_add_todos_limited.sql`
- `ALTER TABLE agent_inbox_user ADD COLUMN todos_limited TINYINT(1) NOT NULL DEFAULT 0`
  after `can_write_todos`. 0 = unchanged behaviour for every existing actor.

`b_create_aiu_todo_grants.sql`
- `aiu_todo_grants`: `atg_id` PK, `aiu_id` (FK `agent_inbox_user`, CASCADE), `todo_id`
  (FK `todos`, CASCADE), `created_at_utc DATETIME(6)`, UNIQUE (`aiu_id`, `todo_id`).

`c_api_usage_token.sql` — the credits change above.

A flag rather than "no grant rows means all todos": with that rule, a limited actor
whose last grant was deleted would silently gain every todo.

### Rules, for an actor with `todos_limited = 1`

- **Todos:** may read and tick only granted todos. One helper,
  `Guards::todo(array $auth_actor, int $todo_id)` (or a small class, builder's call),
  called beside the existing `verifyOwnership($todo_id, …)` in
  `/complete-with-session`. Refusal is the same 403 "Todo not found or access denied" as not-owned, so
  grants can't be probed.
- Of the todo endpoints, a limited actor may use only `GET /todos/granted` (new, below)
  and `POST /todos/complete-with-session`. Everything else (`/list`, `/history`,
  `/complete`, `/uncomplete`, `/create`, `/update`, `/archive`, `/restore`, `/delete`)
  is 403. Keeping it to one ticking path means the daily cap lives in one place. My call.
- **Sessions:** may only start, backfill or stop sessions whose `activity_id` is the
  `activity_id` of one of its granted todos (Meditate has `activity_id = 1`, so BG Bells
  gets Meditation only). Checked beside the existing `userMayUse()` calls in
  `_sessions.php`; `PATCH /sessions/{ak_id}/stop` has no such call today, so it looks up
  the session's activity first. No `can_read_sessions` is granted.
- **No deleting sessions** (Rob, 2026-11-01): `DELETE /sessions/{ak_id}` is 403 for a
  limited actor, whatever the activity. Today it needs only `can_write_sessions`.
- **No creating activities:** `POST /activities` is 403 for a limited actor (today it
  needs only `can_write_sessions`).
- Every other subsystem is closed by the actor's other `can_*` bits being 0; the builder
  should confirm that by reading each `_*.php` handler's gate, since `can_write_sessions`
  turned out to open more than sessions.

## Marking a sit complete

Two calls, both existing endpoints:

1. `POST /api/v1/sessions/backfill`
   `{"activity_id":1,"start_time":"2026-11-01 06:00:00","end_time":"2026-11-01 06:20:00","timezone":"Australia/Sydney"}`
   → 201 `{"session":{"ak_id":…}}`
2. `POST /api/v1/todos/complete-with-session`
   `{"todo_id":<Meditate>,"ak_id":<from 1>,"duration_seconds":1200,"timezone":"Australia/Sydney","date_logged":"2026-11-01 06:20:00"}`

The app finds the Meditate todo id with a new **`GET /api/v1/todos/granted`**, which
returns the actor's granted, active todos regardless of schedule or today's completions:
`{"todos":[{"todo_id","title","activity_id","target_count","completed_today"}]}`
(`completed_today` counted by the same 3am-shifted day as below). `/todos/list` won't do:
it shows only todos scheduled today and hides one fully completed more than a minute ago.
If more than one comes back, the app uses the one whose `activity_id` matches the
session's.

*When* to post a sit (at the end bell, after a minimum length, after the user confirms…)
is for the BG Bells spec: Rob will decide it with the BG Bells designer. mg accepts
whatever start/end it is sent.

### Changes

- **Daily cap**, in `/api/v1/todos/complete-with-session` only. It sets `nth = count + 1`
  with no ceiling today. Change: if the day's completion count is already at or above the
  todo's `target_count` (NULL → 1), log nothing and answer 200
  `{"success":true,"capped":true,"log_id":0}`. **The web UI is left alone** (Rob,
  2026-11-01): its own copy in `wwwroot/api/todos/complete-with-session.php` is not
  touched, nor is v1 `/todos/complete`.
- **Which day.** `getCompletionCount()` counts by mg's 3am-shifted day
  (`DATE(DATE_SUB(date_logged, INTERVAL 3 HOUR))`), but the endpoint passes it the plain
  calendar date of `date_logged`. For a sit between midnight and 3am those disagree. The
  cap (and the `nth` this endpoint computes) uses the 3am-shifted day, to match how
  completions are already counted and displayed. My call.
- **Idempotent backfill.** The phone's queue retries, so the same sit may arrive twice.
  If a session already exists for (`user_id`, `activity_id`, start local datetime,
  same duration), return 200 with that session (no new row, no credit charged: the
  duplicate check runs before `require_credit()`) instead of 201. `complete-with-session` is already idempotent per (`todo_id`, `ak_id`) via
  `uq_todo_ak`.

## Setup for Rob (by hand, after the migration)

```sql
INSERT INTO agent_inbox_user (user_id, name, description, actor_type,
    can_read_todos, can_write_todos, can_write_sessions, todos_limited)
VALUES (1, 'BG Bells', 'Android meditation timer', 'agent', 1, 1, 1, 1);
INSERT INTO aiu_todo_grants (aiu_id, todo_id) VALUES (<new aiu_id>, <Meditate todo_id>);
```

Then set the `bgbells` → `aiu_id` constant in `/authorize`.

## Testing

Unit (hermetic, `check_tests(repo="mg")`): the grant rules as a pure function or with
in-memory SQLite, as existing auth tests do: limited actor sees only granted todos; an
ungranted todo is refused; `todos_limited = 0` sees all; session activity limited to the
granted todos' activities; the daily cap (at target, NULL target, below target); backfill
duplicate detection.

By hand on production after deploy (curl as BG Bells, using a token obtained by running
the flow once from a browser with a hand-made verifier):

```sh
H=https://mg.robnugen.com/api/v1
curl -s -H "Authorization: Bearer $T" "$H/todos/granted"         # only Meditate
curl -s -X POST -H "Authorization: Bearer $T" "$H/sessions/backfill" -d '{…}'   # 201, then 200 on repeat
curl -s -X POST -H "Authorization: Bearer $T" "$H/todos/complete-with-session" -d '{…}'  # nth 1, 2, then capped
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $T" "$H/todos/list"   # 403
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE -H "Authorization: Bearer $T" "$H/sessions/<ak_id>"  # 403
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer <a Claude Phone token>" "$H/todos/list"  # 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST -H "Authorization: Bearer $T" "$H/sessions/backfill" -d '{"activity_id":2,…}'  # 403/404
```

Revoke BG Bells in settings → the same calls give 401.

## Order of work

1. Migration 41 + grant rules (`Guards::todo`, endpoint allow-list, session activity
   check, no delete, no activity create) with unit tests.
2. `GET /todos/granted`, daily cap (3am day) + idempotent backfill, with unit tests.
3. `/api/v1` Bearer auth + `api_usage.token_id`.
4. `/authorize` bgbells client: redirect URI, pinned actor, own wording, Deny;
   `/mcp/` refuses limited actors.
5. Hand setup on production, curl test above.
6. Then the BG Bells spec in `sitnow`.

## Decided (Rob, 2026-11-01)

- Both a session per sit and a todo tick; ticks up to the todo's daily target (2 for Meditate).
- Adapt mg's existing `/authorize` flow rather than a games-style pairing code.
- Limit by a per-actor todo grant list with an opt-in `todos_limited` flag; sessions
  limited to the granted todos' activities.
- Rob only for now; other users in mg #801, referenced in code near the pin.
- A limited actor may not delete sessions. When the app posts a sit is decided in the
  BG Bells spec, with its designer.
- The daily cap does not touch the web UI.

## My calls (mgClaude), for Rob to check

- `bgbells://oauth/callback` as the redirect; `client_id=bgbells`.
- `/mcp/` refuses limited actors.
- Bearer on v1 only for limited actors; connector tokens stay MCP-only.
- No remembered consent for `bgbells`: Allow every time.
- BG Bells' writes cost credits; `api_usage` gains `token_id`; duplicates are free.
- New `GET /todos/granted`; limited actors may use only it and `complete-with-session`
  (so they cannot create, edit or archive todos, or use `/complete`).
- The cap and `nth` use the 3am-shifted day.
- Backfill duplicates detected by (user, activity, start, duration).
- No creating activities for limited actors.
