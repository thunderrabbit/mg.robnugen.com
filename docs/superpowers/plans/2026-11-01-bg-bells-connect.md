# BG Bells Connect Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the BG Bells Android app connect to mg once via OAuth + PKCE and then log Meditation sessions and tick the Meditate todo, and nothing else.

**Architecture:** A per-actor todo grant list (`aiu_todo_grants` + `agent_inbox_user.todos_limited`) limits what an actor may touch. `/api/v1` accepts `Authorization: Bearer` tokens from `mcp_tokens`, but only for limited actors, and gates them through a route allow-list. `/authorize` learns a second kind of client (`bgbells`, custom-scheme redirect, pinned actor, no remembered consent), and `/mcp/token.php` gives limited actors a 31-day sliding refresh token.

**Tech Stack:** PHP 8.5, PDO/MySQL, Codeception Unit (in-memory SQLite), phpcs PSR-12, PHPStan.

**Spec:** `docs/superpowers/specs/2026-11-01-bg-bells-connect-design.md`

## Global Constraints

- Branch `bg-bells-login`, already opened by `BEGIN allowing BG Bells to mark meditations complete` (2b1ce82). Never commit on master.
- Before reporting any task done, all three gates pass: `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`, `run-phpcs.sh`, `run-phpstan.sh` (same arg), and `check_tests(repo="mg")` is `pass`. The pre-commit hook must pass; no `--no-verify`.
- `declare(strict_types=1);` in every new PHP file. New entry scripts list their prepend.php globals in a `@var` block.
- PK names unique across the project (`atg_id`). Migration files under `db_schemas/41_aiu_todo_grants/` named `[a-z]_*.sql` (letter prefixes, never digits).
- Commit messages: subject ≤50 chars, imperative, capitalized, no period; body says why; multiple `-m`, never HEREDOC; end with the Co-Authored-By and Claude-Session lines.
- The web UI (`wwwroot/api/todos/complete-with-session.php`, `/todos/` pages) and v1 `/todos/complete` behaviour for unlimited actors must not change.
- Every refusal of an ungranted todo reads exactly `Todo not found or access denied` (403), identical to not-owned.
- Values: `client_id=bgbells`, redirect `bgbells://oauth/callback`, pinned actor name `BG Bells`, limited refresh life 31 days, normal 400 days, access token 86400 s, logical day = `date_logged − 3 hours`.
- Reference mg #801 in a comment wherever the code pins BG Bells to one hand-made actor.

## Review Focus

- **A Bearer token from Claude Phone (unlimited actor) hits v1** → 401 `{"error":"Use X-API-Key"}`, never a 200. Test in Task 5.
- **Sit ending between 00:00 and 03:00** counts toward the *previous* logical day, both for `nth` and the cap. Test in Task 3.
- **Limited actor calls `DELETE /sessions/{id}` or `POST /activities`** → 403 regardless of `can_write_sessions`. Test in Task 1 (route allow-list).
- **Granted todo was archived (`is_active = 0`) or deleted** → `/todos/granted` omits it; tick refused. Test in Task 1/3.
- **`bgbells` client_id paired with a claude.ai redirect, or a claude.ai client with the `bgbells://` redirect** → refused before any code is issued. Test in Task 7.

---

### Task 1: Grant tables and pure grant rules

**Files:**
- Create: `db_schemas/41_aiu_todo_grants/a_add_todos_limited.sql`, `db_schemas/41_aiu_todo_grants/b_create_aiu_todo_grants.sql`
- Create: `classes/Auth/TodoGrants.php`
- Modify: `classes/Auth/Guards.php`
- Test: `Tests/Unit/TodoGrantsTest.php`, `Tests/Unit/GuardsTest.php`

**Interfaces:**
- Produces:
  - `Auth\TodoGrants::__construct(\PDO $di_pdo)`
  - `TodoGrants::todoIds(int $aiu_id): list<int>` — granted todo ids whose todo `is_active = 1`.
  - `TodoGrants::activityIds(int $aiu_id): list<int>` — distinct non-null `activity_id` of those todos.
  - `Guards::isLimited(array $auth_actor): bool` — `!empty($auth_actor['todos_limited'])`.
  - `Guards::limitedRoute(array $auth_actor, string $method, string $path): ?array` — null if actor unlimited or route allowed; else `['code'=>403,'error'=>'This app may not use this endpoint']`. Allowed for limited: `GET /todos/granted`, `POST /todos/complete-with-session`, `POST /sessions`, `POST /sessions/backfill`, `PATCH /sessions/{digits}/stop`. Nothing else.
  - `Guards::todo(array $auth_actor, int $todo_id, list<int> $granted_todo_ids): ?array` — null if unlimited or granted; else `['code'=>403,'error'=>'Todo not found or access denied']`.
  - `Guards::activity(array $auth_actor, int $activity_id, list<int> $granted_activity_ids): ?array` — null if unlimited or granted; else `['code'=>403,'error'=>'This app may not log that activity']`.

- [ ] **Step 1: Write the migrations**

`a_add_todos_limited.sql`: `ALTER TABLE agent_inbox_user ADD COLUMN todos_limited TINYINT(1) NOT NULL DEFAULT 0 AFTER can_write_todos;` with a header comment (0 = all todos, 1 = only `aiu_todo_grants`; why a flag, not "no rows = all").
`b_create_aiu_todo_grants.sql`: `atg_id INT UNSIGNED AUTO_INCREMENT PK`, `aiu_id INT UNSIGNED NOT NULL`, `todo_id BIGINT UNSIGNED NOT NULL`, `created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`, `UNIQUE KEY uniq_aiu_todo (aiu_id, todo_id)`, FKs `fk_atg_aiu` → `agent_inbox_user(aiu_id)` and `fk_atg_todo` → `todos(todo_id)`, both `ON DELETE CASCADE`. InnoDB utf8mb4.

- [ ] **Step 2: Write failing tests**

`TodoGrantsTest` (SQLite tables `todos(todo_id, user_id, activity_id, is_active)`, `aiu_todo_grants(atg_id, aiu_id, todo_id)`; seed todo 7 activity 1 active, todo 8 activity 2 inactive, todo 9 activity NULL active; grant aiu 70 → 7, 8, 9; aiu 71 → none):
- `testTodoIdsSkipsInactiveTodos`: `todoIds(70) === [7, 9]`
- `testActivityIdsAreDistinctAndNonNull`: `activityIds(70) === [1]`
- `testActorWithNoGrantsGetsEmptyLists`: both `=== []` for 71

`GuardsTest` additions (`$lim = ['todos_limited'=>1]`, `$full = ['todos_limited'=>0]`):
- `testLimitedRouteAllowsOnlyTheFiveRoutes`: null for each of the five; for `PATCH /sessions/12/stop` null; `['code'=>403,...]` for `GET /todos/list`, `POST /todos/complete`, `DELETE /sessions/12`, `POST /activities`, `GET /sessions`, `GET /inbox`, `PATCH /sessions/12/pause`.
- `testLimitedRouteIgnoresUnlimitedActors`: null for `$full` on `DELETE /sessions/12`.
- `testTodoGuardRefusesUngrantedWithNotFoundWording`: `todo($lim, 5, [7])['error'] === 'Todo not found or access denied'`, code 403; `todo($lim, 7, [7]) === null`; `todo($full, 5, []) === null`.
- `testActivityGuard`: `activity($lim, 2, [1])['code'] === 403`; `activity($lim, 1, [1]) === null`; `activity($full, 2, []) === null`.

- [ ] **Step 3: Run unit suite; expect the new tests to fail** (`run-unit.sh`): class/method not found.

- [ ] **Step 4: Implement `Auth\TodoGrants` and the four `Guards` methods** per Interfaces. Guards stay pure (no PDO). Route matching for stop: `#^/sessions/\d+/stop$#`.

- [ ] **Step 5: Run all three gates; expect pass.**

- [ ] **Step 6: Commit** — `git add db_schemas/41_aiu_todo_grants classes/Auth/TodoGrants.php classes/Auth/Guards.php Tests/Unit/TodoGrantsTest.php Tests/Unit/GuardsTest.php` — subject `Let an actor be limited to granted todos`.

---

### Task 2: Enforce the grant rules in /api/v1

**Files:**
- Modify: `wwwroot/api/v1/index.php` (before Route dispatch)
- Modify: `wwwroot/api/v1/_todos.php` (`/complete-with-session`)
- Modify: `wwwroot/api/v1/_sessions.php` (POST `/sessions`, POST `/sessions/backfill`, PATCH `/sessions/{id}/stop`)

**Interfaces:**
- Consumes: `Guards::limitedRoute`, `Guards::todo`, `Guards::activity`, `TodoGrants::todoIds`, `TodoGrants::activityIds` (Task 1).
- Produces: `$todo_grants` (`\Auth\TodoGrants`) available to handler includes, declared in their `@var` blocks.

- [ ] **Step 1: In `index.php`**, after `$auth_actor` and `$path` are known and before dispatch, emit `$guards->limitedRoute($auth_actor, $method, $path)` if non-null (http code + JSON + exit), and create `$todo_grants = new \Auth\TodoGrants($pdo);`.
- [ ] **Step 2: In `/complete-with-session`**, right after the `verifyOwnership($todo_id, …)` check, emit `$guards->todo($auth_actor, $todo_id, $todo_grants->todoIds(aiu))` when non-null. `aiu` = `\Field::int($auth_actor, 'aiu_id')`.
- [ ] **Step 3: In `_sessions.php`**, beside both `userMayUse($activity_id, …)` calls, emit `$guards->activity(...)` with `activityIds(aiu)`. In PATCH stop, look up the session's `activity_id` (add `ActivityKai::activityIdOf(int $ak_id, int $user_id): ?int` if no such getter exists) after `verifyOwnership`, and apply the same guard. Only query grants when `$guards->isLimited($auth_actor)`.
- [ ] **Step 4: Run all three gates; expect pass.** (Endpoint behaviour is verified by curl after deploy, see Handoff.)
- [ ] **Step 5: Commit** — subject `Hold limited actors to their granted todos`.

---

### Task 3: Granted-todo list and the daily cap

**Files:**
- Create: `classes/ActivityTracking/DailyCap.php`
- Modify: `classes/Auth/TodoGrants.php`
- Modify: `wwwroot/api/v1/_todos.php` (new `/granted`; cap in `/complete-with-session`)
- Modify: `wwwroot/api/v1/openapi.yaml`
- Test: `Tests/Unit/DailyCapTest.php`, `Tests/Unit/TodoGrantsTest.php`

**Interfaces:**
- Consumes: `TodoGrants` (Task 1).
- Produces:
  - `ActivityTracking\DailyCap::logicalDay(\DateTimeInterface $at): string` — `Y-m-d` of `$at − 3 hours`.
  - `DailyCap::reached(int $completed, ?int $target_count): bool` — `$completed >= ($target_count ?? 1)`; a target of 0 counts as 1.
  - `TodoGrants::listGranted(int $aiu_id, int $user_id, string $logical_day): list<array{todo_id:int,title:string,activity_id:?int,target_count:int,completed_today:int}>` — active granted todos owned by `$user_id`, `completed_today` counted from `todo_logs` with `DATE(DATE_SUB(date_logged, INTERVAL 3 HOUR)) = $logical_day` (compute the shift in PHP-side SQL that also runs on SQLite, or pass precomputed bounds `[$day 03:00, $day+1 03:00)` — bounds preferred, portable).

- [ ] **Step 1: Write failing tests**

`DailyCapTest`:
- `testLogicalDayShiftsThreeHours`: `logicalDay(new DateTimeImmutable('2026-11-02 02:59:00')) === '2026-11-01'`; `'2026-11-02 03:00:00'` → `'2026-11-02'`.
- `testReached`: `reached(1, 2) === false`, `reached(2, 2) === true`, `reached(1, null) === true`, `reached(0, null) === false`, `reached(1, 0) === true`.

`TodoGrantsTest` addition (add `todo_logs(log_id, todo_id, date_logged)`, `title`, `target_count`, `user_id` columns; todo 7 user 1 target 2 with logs at `2026-11-01 06:00:00` and `2026-11-02 01:30:00`):
- `testListGrantedCountsByLogicalDay`: `listGranted(70, 1, '2026-11-01')` has one row for todo 7 with `completed_today === 2`, `target_count === 2`, `activity_id === 1`; todo 8 (inactive) absent.
- `testListGrantedIsScopedToUser`: `listGranted(70, 2, '2026-11-01') === []`.

- [ ] **Step 2: Run unit suite; expect failures.**
- [ ] **Step 3: Implement `DailyCap` and `listGranted`.**
- [ ] **Step 4: Add `GET /todos/granted`** in `_todos.php`: 403 via `permission(..., 'can_read_todos', ...)` already covers reads; respond `{"todos": listGranted(aiu, $auth_user_id, DailyCap::logicalDay(now in ?timezone=, default UTC))}`. For an unlimited actor return 400 `{"error":"Only for apps limited to granted todos"}` (my call: it has no meaning without grants).
- [ ] **Step 5: Cap and logical day in `/complete-with-session`**: replace `$today = $dt->format('Y-m-d')` with `DailyCap::logicalDay($dt)`; before `logCompletion`, if `DailyCap::reached($completionCount, target_count of the todo)` respond 200 `{"success":true,"capped":true,"log_id":0}` and return. Read `target_count` via an existing Todo getter or add `Todo::targetCount(int $todo_id): ?int`. Do not touch `wwwroot/api/todos/complete-with-session.php` or `/complete`.
- [ ] **Step 6: Document `/todos/granted` and the `capped` field in `openapi.yaml`.**
- [ ] **Step 7: Run all three gates; expect pass.**
- [ ] **Step 8: Commit** — subject `Cap ticks at the target and list granted todos`.

---

### Task 4: Idempotent backfill

**Files:**
- Modify: `classes/ActivityTracking/ActivityKai.php`
- Modify: `wwwroot/api/v1/_sessions.php` (POST `/sessions/backfill`)
- Test: `Tests/Unit/BackfillDuplicateTest.php`

**Interfaces:**
- Produces: `ActivityKai::findBackfill(int $user_id, int $activity_id, string $start_local_dt, int $actual_sec): ?int` — `ak_id` of an existing row in `activity_kai` with those four values, else null.

- [ ] **Step 1: Write failing test** (SQLite `activity_kai(ak_id, user_id, activity_id, start_local_dt, actual_sec)` — check the real column name for duration in `stopActivity` and mirror it): seed `(5, 1, 1, '2026-11-01 06:00:00', 1200)`.
  - `testFindsExactMatch`: `findBackfill(1, 1, '2026-11-01 06:00:00', 1200) === 5`
  - `testDifferentDurationIsNotADuplicate`: `…, 1199) === null`
  - `testOtherUserIsNotADuplicate`: `findBackfill(2, 1, …, 1200) === null`
- [ ] **Step 2: Run; expect failure.**
- [ ] **Step 3: Implement `findBackfill`.**
- [ ] **Step 4: In the backfill handler**, move `require_credit()` after input parsing and the duplicate check. On a hit respond 200 with the same `session` shape (fetch `session_key` via `SessionKey` if one exists for that `ak_id`; else omit it) and exit without charging.
- [ ] **Step 5: Run all three gates; expect pass.**
- [ ] **Step 6: Commit** — subject `Make a repeated backfill return the first session`.

---

### Task 5: Bearer tokens on /api/v1 for limited actors

**Files:**
- Create: `db_schemas/41_aiu_todo_grants/c_api_usage_token.sql`
- Create: `classes/Auth/BearerToken.php`
- Modify: `wwwroot/api/v1/index.php` (auth block, `require_credit`)
- Modify: every `require_credit(` call site (grep `wwwroot/api/v1`)
- Test: `Tests/Unit/BearerTokenTest.php`

**Interfaces:**
- Produces:
  - `Auth\BearerToken::__construct(\PDO $di_pdo)`
  - `BearerToken::fromHeaders(array $server): ?string` — raw token from `HTTP_AUTHORIZATION` or `REDIRECT_HTTP_AUTHORIZATION` matching `/^Bearer\s+(\S+)$/i`.
  - `BearerToken::resolve(string $raw): ?array{token_id:int,user_id:int,aiu_id:int}` — live row (`token_hash = sha256(raw)`, `expires_at_utc > now`) whose `aiu_id` belongs to `user_id` in `agent_inbox_user`. Compare expiry against a PHP-supplied UTC timestamp so SQLite tests work.
  - `require_credit(\PDO $pdo, int $user_id, ?int $key_id, string $endpoint, ?int $token_id = null): void`
  - Globals in v1: `$auth_key_id` becomes `?int`; new `$auth_token_id` (`?int`).

- [ ] **Step 1: Migration `c_api_usage_token.sql`**: `ALTER TABLE api_usage MODIFY key_id BIGINT UNSIGNED NULL, ADD COLUMN token_id BIGINT UNSIGNED NULL AFTER key_id, ADD KEY idx_token_usage (token_id, created_at), ADD CONSTRAINT fk_usage_token FOREIGN KEY (token_id) REFERENCES mcp_tokens(token_id) ON DELETE SET NULL;`
- [ ] **Step 2: Write failing tests** (SQLite `mcp_tokens`, `agent_inbox_user`; token `abc` → aiu 70 user 1 live; `old` expired; `stray` → aiu 70 but user 2):
  - `testFromHeadersReadsBothVariants`: `HTTP_AUTHORIZATION => 'Bearer abc'` → `'abc'`; `REDIRECT_HTTP_AUTHORIZATION => 'bearer abc'` → `'abc'`; `'Basic x'` → null; empty → null.
  - `testResolvesLiveToken`: `resolve('abc') === ['token_id'=>…, 'user_id'=>1, 'aiu_id'=>70]`
  - `testExpiredTokenIsNull`, `testTokenWhoseActorBelongsToAnotherUserIsNull`.
- [ ] **Step 3: Run; expect failure. Implement `BearerToken`. Run; expect pass.**
- [ ] **Step 4: Rewire the auth block in `index.php`**: `X-API-Key` path unchanged. Else if `fromHeaders($_SERVER)` yields a token: `resolve` it (null → 401 `{"error":"Invalid or expired token"}`), load the actor by `aiu_id` + `user_id`, and if `!$guards->isLimited($auth_actor)` → 401 `{"error":"Use X-API-Key"}`. Neither header → existing 401 `Missing X-API-Key header` (message becomes `Missing X-API-Key or Bearer token`).
- [ ] **Step 5: Update `require_credit`** to insert `key_id` and `token_id`; pass `$auth_token_id` at every call site. Confirm with PHPStan that no handler still types `$auth_key_id` as `int` (`_emotions.php` is unreachable for limited actors via `limitedRoute`, but its `@var` must still type-check).
- [ ] **Step 6: Run all three gates; expect pass.**
- [ ] **Step 7: Commit** — subject `Accept limited actors' Bearer tokens on the API`. (Migration file rides with it: same change.)

---

### Task 6: Token lifetimes and keeping limited actors out of /mcp/

**Files:**
- Create: `classes/Auth/TokenLifetime.php`
- Modify: `wwwroot/mcp/token.php` (`$refresh_ttl_days`)
- Modify: `wwwroot/mcp/index.php` (after actor lookup)
- Test: `Tests/Unit/TokenLifetimeTest.php`

**Interfaces:**
- Produces: `Auth\TokenLifetime::refreshDays(array $auth_actor): int` — 31 if `todos_limited`, else 400. Constants `LIMITED_REFRESH_DAYS = 31`, `REFRESH_DAYS = 400`.

- [ ] **Step 1: Failing test** `testRefreshDays`: `refreshDays(['todos_limited'=>1]) === 31`; `['todos_limited'=>0]` and `[]` → 400.
- [ ] **Step 2: Run; fail. Implement. Run; pass.**
- [ ] **Step 3: In `token.php`**, load the `agent_inbox_user` row for `$aiu_id`/`$auth_user_id` before issuing, set `$refresh_ttl_days = TokenLifetime::refreshDays($row)`. Applies to both the code exchange and every rotation, so each refresh restarts the 31 days.
- [ ] **Step 4: In `mcp/index.php`**, after `$auth_actor` is loaded, if `(new \Auth\Guards())->isLimited($auth_actor)` respond 403 JSON-RPC error `This app may not use MCP` and exit.
- [ ] **Step 5: Run all three gates; expect pass.**
- [ ] **Step 6: Commit** — subject `Expire limited apps after a month idle`.

---

### Task 7: The bgbells client on /authorize

**Files:**
- Create: `classes/Auth/OAuthClients.php`
- Modify: `wwwroot/authorize/index.php`
- Create: `templates/authorize/app.tpl.php` (the bgbells Allow / Deny page)
- Test: `Tests/Unit/OAuthClientsTest.php`

**Interfaces:**
- Produces (`Auth\OAuthClients`, pure, constants inside):
  - `redirectAllowed(string $client_id, string $redirect_uri): bool` — `bgbells` ⇔ `bgbells://oauth/callback` exactly; any other client_id may use only the two existing claude.ai/claude.com callbacks.
  - `pinnedActorName(string $client_id): ?string` — `'BG Bells'` for `bgbells`, null otherwise. Comment: hand-made actor, one per account, replaced by per-user actors in mg #801.
  - `remembersConsent(string $client_id): bool` — false for `bgbells`, true otherwise.
  - `displayName(string $client_id): ?string` — `'BG Bells'` for `bgbells`.

- [ ] **Step 1: Failing tests**:
  - `testBgbellsRedirectPairing`: `redirectAllowed('bgbells','bgbells://oauth/callback') === true`; `('bgbells','https://claude.ai/api/mcp/auth_callback') === false`; `('claude-x','bgbells://oauth/callback') === false`; `('claude-x','https://claude.ai/api/mcp/auth_callback') === true`; `('bgbells','bgbells://oauth/callback/evil') === false`.
  - `testPinnedActorAndConsent`: `pinnedActorName('bgbells') === 'BG Bells'`, `pinnedActorName('other') === null`, `remembersConsent('bgbells') === false`, `remembersConsent('other') === true`.
- [ ] **Step 2: Run; fail. Implement. Run; pass.**
- [ ] **Step 3: Rewire `authorize/index.php`**:
  - Replace `$allowed_redirect_uris` check with `OAuthClients::redirectAllowed`.
  - If `pinnedActorName($client_id)` is set, find the actor by `(user_id, name)` (unique key `uniq_user_name`), bypassing the `can_read_project` choice list; none → plain page "BG Bells isn't set up for this account." Load its granted todo titles via `TodoGrants::todoIds` + a title lookup for the page.
  - Skip the remembered-consent GET shortcut and the consent INSERT when `!remembersConsent($client_id)`.
  - Pinned client POST: `decision=allow` → issue the code for the pinned `aiu_id` (ignore any posted `aiu_id`); `decision=deny` → redirect `redirect_uri?error=access_denied&state=…`.
  - Render `authorize/app.tpl.php`: "Let **BG Bells** log meditation sessions and tick **{titles}** for you?" with Allow and Deny buttons, hidden OAuth params, and the page's existing CSRF field (copy how `authorize/index.tpl.php` does it). Page title `Connect BG Bells — Meiso Gambare`. Escape every interpolated value.
- [ ] **Step 4: Run all three gates; expect pass.**
- [ ] **Step 5: Commit** — subject `Let BG Bells connect through /authorize`.

---

### Task 8: Document it

**Files:**
- Modify: `CLAUDE.md` (Authentication Flow section)

- [ ] **Step 1:** Add a short "Connected apps (limited actors)" subsection: `todos_limited` + `aiu_todo_grants`; v1 accepts Bearer only for limited actors and only the five routes in `Guards::limitedRoute`; `bgbells` client pinned to the `BG Bells` actor (mg #801); 31-day sliding refresh; password change already revokes via `ConnectedApps::revokeAll`.
- [ ] **Step 2: Commit** — subject `Describe limited apps in CLAUDE.md`.

---

## Handoff (Rob, after the branch is reviewed)

1. Deploy, then apply `41_aiu_todo_grants` at `/admin/migrate_tables.php`.
2. Insert the actor and grant (`ssh mg "mysql mgrnc -e '…'"`), using the SQL in the spec's *Setup for Rob* with the real Meditate `todo_id`.
3. Run the spec's curl checks; get a token by running the flow once in a browser with a hand-made PKCE verifier (mgClaude can script the verifier/challenge pair).
4. Close the bubble with `git close-bubble` once Rob is happy.
5. Next: the BG Bells spec in `~/work/rob/sitnow/`, including when to post a sit.
