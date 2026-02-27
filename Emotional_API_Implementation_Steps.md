# Emotional API v3: Step-by-Step Implementation Guide

This guide augments `Emotional_API_Implementation_Strategy.md`, laying out every task in a strictly logical progression. The strategy doc is the authoritative reference for full schema definitions, encryption spec, API request/response shapes, and security properties. This doc answers: *in what order do I write the code?*

It resolves temporal dependencies (e.g. `omg_rob_this_happened` must exist before vocab POST can escalate to it) and notes which steps are already implemented.

---

## PHP Code Already Written

The PHP/CSS code for these features is already in the repo — no new code required. But each one depends on a DB migration that has not been written or applied yet. They silently do nothing until their tables exist. Verification steps appear in the numbered list below at the appropriate points.

**Admin Dashboard UI for Alerts**
- **Files:** `classes/Admin/OmgAlerts.php`, `wwwroot/admin/index.php`, `templates/admin/index.tpl.php`, `wwwroot/css/styles.css`
- `OmgAlerts::getUnread()` queries `omg_rob_this_happened` and catches the PDOException if the table is missing (returns `[]` silently). `OmgAlerts::dismissAll()` is a no-op until the table exists. Admin index handles form POST to `/admin/` with CSRF token. Banner only renders when `!empty($omg_alerts)` — currently always empty.
- **Depends on:** Step 2 (creates `omg_rob_this_happened` table). Verified in Step 3.

**HTTP `DELETE` endpoint: `/api/v1/emotions/everything`**
- **File:** `wwwroot/api/v1/emotions/everything.php` (handles the HTTP DELETE method)
- Accepts `{"confirm": "delete everything"}` in body (returns 400 if missing or wrong). In a transaction: counts then deletes `interaction_events`, `interaction_sessions`, and `my_ids_for_my_users_state` in FK-safe order for the authenticated `api_key_id`. Returns `{"deleted": {"events": N, "sessions": M, "vocab_entries": K}}`.
- **Note:** Written in standalone pattern (includes `prepend.php`, calls `\Emotional\ApiAuth::authenticate()`). Under Option A (front controller), this logic will be folded into `_emotions.php`'s `/everything` branch in Step 18. The standalone file will be deleted once migrated.
- **Depends on:** Step 4 (creates the three emotional tables). Verified in Step 18.

---

## Suggested Coding Order and Commit Points

Work through this in order. Commit after each numbered step — small commits make it easy to roll back a broken step without losing everything else.

### Jikan Integration Pattern

After each endpoint step that has an HTTP test, three sub-steps follow:

1. **[Jikan] Add tool** — add a `@mcp.tool()` function to `~/jikan/server.py`
2. **[Restart]** Exit and reopen Claude Code. MCP servers load at startup — new tools only appear after restart. ⚠️ **This ends your current conversation session.**
3. **[Jikan verify]** — In the new session, ask Claude to call the new tool and confirm the expected response.

The emotional endpoints live at `/api/v1/emotions/` — the same base URL as the existing sessions tools. Use the existing `_client()` helper for all emotional tools. No separate client, URL constant, or environment variable needed.

`JIKAN_API_KEY` (already in `HEADERS`) works for both the existing sessions API and the emotional API — no new configuration needed.

### Phase 1: Foundations & Admin Scaffolding

**1. ~~[COMMIT] Defensive SQL fix in Database Class~~ DONE**
- **File:** `classes/Database/Base.php` (method `executeMultipleSQL`)
- **Action:** Update `Database\Base::executeMultipleSQL` to strip single-line SQL comments before splitting on semicolons.
  ```php
  // Strip single-line SQL comments before splitting on semicolons
  $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
  ```
- **Why:** Protects against schema parsing errors caused by inline `--` comments in future migrations.

**2. ~~[COMMIT] Create Admin Alerts Database Table~~ DONE**
- **File:** `db_schemas/11_admin_alerts/create_admin_alerts.sql`
- **Action:** Create the `omg_rob_this_happened` table to track critical human-attention failures. Include an initial test insert:
  ```sql
  INSERT INTO omg_rob_this_happened (context, message)
  VALUES ('system/setup', 'We created a system to alert you to important messages!');
  ```
- **Test:** Run the migration via `/admin/migrate_tables.php` and verify the table exists and the row is present.

**2b. ~~[COMMIT] Write Admin Alerts PHP Layer~~ DONE** *(was already in repo; per-item dismiss added)*
- **Files:** `classes/Admin/OmgAlerts.php`, `wwwroot/admin/index.php`, `templates/admin/index.tpl.php`, `wwwroot/css/styles.css`
- All four files already exist (see "PHP Code Already Written" above), so no action required. This step is here to document what they do and why they appear between Step 2 and Step 3:

  **`classes/Admin/OmgAlerts.php`** — three static methods, all wrapped in `try/catch (\PDOException $e)`. The catch blocks are intentional: the code is written before the migration is applied, so the table may not exist yet. Catching the exception lets the admin dashboard load silently with no banner rather than crashing.
  - `getUnread(\PDO $pdo): array` — `SELECT omg_id, context, message, created_at FROM omg_rob_this_happened WHERE acknowledged_at IS NULL ORDER BY created_at DESC`. Returns `[]` on exception.
  - `dismissOne(\PDO $pdo, int $omg_id): void` — `UPDATE ... SET acknowledged_at = NOW() WHERE omg_id = ? AND acknowledged_at IS NULL`. No-op on exception.
  - `dismissAll(\PDO $pdo): void` — `UPDATE omg_rob_this_happened SET acknowledged_at = NOW() WHERE acknowledged_at IS NULL`. No-op on exception.

  **`wwwroot/admin/index.php`** — POST handler at the top handles both `action=dismiss_alerts` (bulk) and `action=dismiss_alert` with `omg_id` (single). Both call the appropriate `OmgAlerts` method then redirect. CSRF check on all POSTs. Also calls `OmgAlerts::getUnread()` and passes the result plus `$_SESSION['csrf_token']` to the template.

  **`templates/admin/index.tpl.php`** — renders an amber banner `<div class="OmgAlertBanner">` only when `!empty($omg_alerts)`. Each alert has an inline `×` dismiss button (`OmgDismissOne`). "Dismiss all" button (`OmgDismissButton`) at the bottom.

  **`wwwroot/css/styles.css`** — styles for `.OmgAlertBanner`, `.OmgAlertContext`, `.OmgAlertTime`, `.OmgDismissButton`, `.OmgDismissInline`, `.OmgDismissOne`.

**3. ~~[VERIFY] Admin Dashboard Alerts End-to-End~~ DONE**
- Verified: amber banner appears, "Dismiss all" works, per-item `×` dismiss works. Tested with multiple manually inserted rows.


### Phase 2: Emotional API Database & Crypto

**4. [COMMIT] Emotional API Core Database Schema**
- **File:** `db_schemas/12_emotional_api/create_emotional_api.sql`
- **Action:** Create the 3 core tables in dependency order: `my_ids_for_my_users_state`, then `interaction_sessions`, then `interaction_events`. Full schema in the strategy doc. Use MySQL `COMMENT 'text'` syntax for column annotations — do **not** use `--` inline comments (risk of false semicolon splits in the importer). All three tables FK-reference `api_keys(key_id)`.
- **Test:** Run the migration via `/admin/migrate_tables.php` and verify all three tables are created.

**5. [COMMIT] Route `/emotions/*` Through the Front Controller**
- **Files:** `wwwroot/api/v1/index.php` and `wwwroot/api/v1/_emotions.php`
- **Action:** In `index.php`, add an `elseif` branch before the final `else { 404 }`:
  ```php
  } elseif ($path === '/emotions' || preg_match('#^/emotions(/|$)#', $path)) {
      include __DIR__ . '/_emotions.php';
  }
  ```
  Create `wwwroot/api/v1/_emotions.php` as a stub sub-dispatcher. The following variables are already in scope from `index.php` — **no auth code needed here**:
  - `$raw_key` — raw API key string (needed by `Ledger.php` for encryption key derivation)
  - `$auth_user_id` — authenticated user ID
  - `$auth_key_id` — key ID (FK to `api_keys.key_id`)
  - `$pdo` — PDO connection (`\Database\Base::getPDO()`)
  - `$method` — HTTP method (`$_SERVER['REQUEST_METHOD']`)
  - `$path` — URL path relative to `/api/v1` (e.g. `/emotions/vocab`)

  `Emotional\ApiAuth` class is **not needed** — `index.php` already performs full authentication via `Auth\ApiKey`.

  Stub structure for `_emotions.php`:
  ```php
  <?php
  // $raw_key, $auth_user_id, $auth_key_id, $pdo, $method, $path
  // — all in scope from index.php

  $emotions_path = rtrim(preg_replace('#^/emotions#', '', $path), '/') ?: '/';

  if ($emotions_path === '/vocab' || $emotions_path === '/') {
      // Steps 8–12: vocab GET/POST/DELETE
      http_response_code(404);
      echo json_encode(['error' => 'vocab endpoint not yet implemented']);
  } elseif ($emotions_path === '/events') {
      // Steps 14–15, 17: events GET/POST/DELETE
      http_response_code(404);
      echo json_encode(['error' => 'events endpoint not yet implemented']);
  } elseif ($emotions_path === '/sessions') {
      // Step 16: sessions GET
      http_response_code(404);
      echo json_encode(['error' => 'sessions endpoint not yet implemented']);
  } elseif ($emotions_path === '/everything') {
      // Step 18: everything DELETE (migrated from everything.php)
      http_response_code(404);
      echo json_encode(['error' => 'everything endpoint not yet implemented']);
  } else {
      http_response_code(404);
      echo json_encode(['error' => 'Not found']);
  }
  ```
- **Test:** With a valid key: `curl -H "X-API-Key: sk_..." https://mg.robnugen.com/api/v1/emotions/vocab` → HTTP 404 "not yet implemented". Without a key: → HTTP 401 (rejected by `index.php` before reaching `_emotions.php`).

**6. [COMMIT] Ledger Encryption Foundation: Key Derivation**
- **File:** `classes/Emotional/Ledger.php`
- **Action:** Create the class. Implement derivation of a 32-byte encryption key using:
  ```php
  $encKey = hash_hmac('sha256', 'emotional_v1', $rawApiKey, true); // 32 bytes, binary
  ```
  Use `sodium_crypto_secretbox` (XSalsa20-Poly1305) — software-only, no hardware AES-NI required, available on DreamHost shared hosting.
- **Test:** Write a temporary simple script to output a derived key from a known input and verify it produces exactly 32 bytes.

**7. [COMMIT] Ledger Encryption Foundation: Encrypt/Decrypt Functions**
- **File:** `classes/Emotional/Ledger.php`
- **Action:** Implement `emotional_encrypt()` and `emotional_decrypt()`:
  ```php
  function emotional_encrypt(string $plaintext, string $encKey): string {
      $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); // 24 bytes
      $cipher = sodium_crypto_secretbox($plaintext, $nonce, $encKey);
      return base64_encode($nonce . $cipher);
  }

  function emotional_decrypt(string $stored, string $encKey): string|false {
      $decoded = base64_decode($stored);
      $nonce   = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
      $cipher  = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
      return sodium_crypto_secretbox_open($cipher, $nonce, $encKey);
  }
  ```
  Each call generates a fresh random nonce — identical plaintext produces different ciphertext every time.
- **Test:** Encrypt a string, decrypt it, assert equal. Verify that encrypting the same string twice produces different base64 blobs.

**8. [COMMIT] API Endpoint: POST Vocab Routing & Auth**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/vocab` branch)
- **Action:** Replace the `/vocab` stub with a real handler. Route by `$method` (already set by `index.php`). Parse incoming JSON body for `state`. Return 405 for unsupported methods. Auth context (`$raw_key`, `$auth_key_id`, `$auth_user_id`) is already in scope — no `ApiAuth` call needed.
- **Test:** With a valid key: expect HTTP 200 `{"status": "auth ok"}` (placeholder until Step 9 adds real insertion). With no key: expect HTTP 401.
- **[Jikan]** Add the first emotional tool to `~/jikan/server.py` (uses the existing `_client()` — no new helper needed):
  ```python
  @mcp.tool()
  def emotional_post_vocab(state: str) -> dict:
      """Add a new state label to the agent's private vocabulary.
      Returns my_id: the agent's private numeric handle for this state.
      Call when the state is not yet in the loaded vocab.

      Args:
          state: Label for this emotional state (any string, e.g. 'frustration_at_jargon')
      """
      with _client() as client:
          response = client.post("/emotions/vocab", json={"state": state})
      return response.json()
  ```
- **[Restart]** Exit and reopen Claude Code to reload Jikan with the new tool. ⚠️ This ends your current session.
- **[Jikan verify]** In the new session, ask Claude to call `emotional_post_vocab` with a test state. At this stage it returns `{"status": "auth ok"}` — real `my_id` response comes after Step 9.

**9. [COMMIT] API Endpoint: POST Vocab DB Insertion & Encryption**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/vocab` branch, POST method)
- **Action:** Derive encryption key from `raw_key`. Encrypt the `state` string. Generate a random `my_id`: `random_int(100000, 999999999)`. Execute `INSERT INTO my_ids_for_my_users_state (api_key_id, my_id, state) VALUES (?, ?, ?)`. Return `{"my_id": <int>}`.
- **Test:** POST a new state. Check the database directly to ensure the row is inserted with the correct `api_key_id`, generated `my_id`, and an encrypted blob in `state`.
- **[Jikan verify — no restart needed]** The `emotional_post_vocab` tool added in Step 8 now returns `{"my_id": N}`. Ask Claude to call it with a test state and confirm the response contains a valid integer `my_id`.

**10. [COMMIT] API Endpoint: POST Vocab Collision Retry Loop**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/vocab` branch, POST method)
- **Action:** Wrap the `my_id` generation and `INSERT` in a loop (max 5 attempts) to handle potential `UNIQUE` constraint violations on `(api_key_id, my_id)`.
- **Test:** Temporarily force a collision in the code to ensure the loop successfully retries and eventually inserts.

**11. [COMMIT] API Endpoint: POST Vocab Alert Escalation**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/vocab` branch, POST method)
- **Action:** If all 5 collision attempts fail, call `print_roblog` with context (api_key_id, attempted my_ids), then execute:
  ```sql
  INSERT INTO omg_rob_this_happened (context, message)
  VALUES ('emotional/vocab', '5 my_id collisions exhausted for api_key_id X — see rob.log for details')
  ```
  Return HTTP 500.

  When this alert fires, the response depends on when it happens:
  - **Early (first few years, < ~10,000 vocab entries per key):** likely a data anomaly or bug in the retry loop — investigate before changing anything.
  - **At scale (millions of entries per key):** expand the range in the `random_int()` call. `random_int(100000, 9999999999)` (10 nines, ~10 billion unique IDs) is safe — PHP's `PHP_INT_MAX` on 64-bit is ~9.2 quintillion and MySQL's `BIGINT UNSIGNED` holds ~18.4 quintillion, so there is no overflow risk.

- **Test:** Temporarily force the loop to fail all 5 times. Verify HTTP 500 response and check the Admin Dashboard banner for the new alert.

**12. [COMMIT] API Endpoint: GET Vocab**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/vocab` branch, GET method)
- **Action:** Add GET handler to the `/vocab` branch. Query `my_ids_for_my_users_state WHERE api_key_id = ?` (using `$auth_key_id` from `index.php`), decrypt each `state` value using `emotional_decrypt()` (already in `Ledger.php` from Step 7), return array of `[{my_id, state}]`. If decryption returns `false` for any row: call `print_roblog`, return 500.
- **Test:** `curl -H "X-API-Key: sk_..." https://mg.robnugen.com/api/v1/emotions/vocab` — expect the decrypted label POSTed in Step 9. This tests the full cryptographic round-trip end to end.
- **[Jikan]** Add `emotional_get_vocab` to `~/jikan/server.py`:
  ```python
  @mcp.tool()
  def emotional_get_vocab() -> list:
      """Load the agent's full private vocabulary. Call once at session start.
      Returns a list of {my_id, state} pairs. Hold in context for the session.
      Returns [] on a fresh install.
      """
      with _client() as client:
          response = client.get("/emotions/vocab")
      return response.json()
  ```
- **[Restart]** Exit and reopen Claude Code. ⚠️ This ends your current session.
- **[Jikan verify]** Ask Claude to call `emotional_get_vocab`. Expect the state label from Step 9 to appear decrypted.


### Phase 3: Core Endpoints

**13. [COMMIT] Session Auto-Detection Logic**
- **File:** `classes/Emotional/Ledger.php`
- **Action:** Implement `getOrCreateSession($api_key_id, $user_id)`. Within a transaction:
  1. Query `interaction_sessions` for the most recent session within `EMOTIONAL_SESSION_GAP_MINUTES`:
     ```php
     // PDO cannot use named params inside INTERVAL — embed as validated integer literal:
     $gap = intval(EMOTIONAL_SESSION_GAP_MINUTES); // e.g. 30
     $sql = "SELECT session_id FROM interaction_sessions
             WHERE api_key_id = ?
               AND last_event_time > DATE_SUB(NOW(), INTERVAL {$gap} MINUTE)
             ORDER BY last_event_time DESC LIMIT 1 FOR UPDATE";
     ```
  2. If found: `UPDATE interaction_sessions SET last_event_time = NOW() WHERE session_id = ?`
  3. If not found: `INSERT INTO interaction_sessions (api_key_id, user_id) VALUES (?, ?)`
  4. Return `session_id`

  Add to `classes/Config.php`:
  ```php
  define('EMOTIONAL_SESSION_GAP_MINUTES', 30);
  ```
- **Test:** Call twice within the gap — same `session_id` returned. Force timestamp to exceed gap, then verify a new `session_id` is generated.

**14. [COMMIT] API Endpoint: POST Events**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/events` branch, POST method)
- **Action:** Replace the `/events` stub with a real handler. Accept event payload (`my_id` optional, `event_type`, `content`). Within a transaction:
  1. Resolve `my_id` → `mifmus_id`: `SELECT mifmus_id FROM my_ids_for_my_users_state WHERE api_key_id=? AND my_id=?` (NULL if no `my_id` supplied)
  2. Call `getOrCreateSession()` to get `session_id`
  3. Assign `sequence_num` atomically: `SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM interaction_events WHERE session_id = ? FOR UPDATE`
  4. Encrypt `content` via `emotional_encrypt()`
  5. `INSERT INTO interaction_events`
  6. Return `{"event_id": ..., "session_id": ..., "sequence_num": ...}`
- **Test:** `curl -X POST -H "X-API-Key: sk_..." -d '{"my_id":N,"event_type":"user_reaction","content":"test"}' https://mg.robnugen.com/api/v1/emotions/events` — expect `{"event_id":...,"session_id":...,"sequence_num":1}`. Verify the row in DB has an unreadable blob in `encrypted_content`.
- **[Jikan]** Add `emotional_log_event` to `~/jikan/server.py`:
  ```python
  @mcp.tool()
  def emotional_log_event(
      event_type: str,
      content: str,
      my_id: int | None = None,
  ) -> dict:
      """Log an interaction event to the emotional ledger.

      Args:
          event_type: 'user_reaction', 'user_input', or 'agent_action'
          content: Specific honest observation about what happened
          my_id: Vocab my_id for this state (omit if event has no state tag)
      """
      payload: dict = {"event_type": event_type, "content": content}
      if my_id is not None:
          payload["my_id"] = my_id
      with _client() as client:
          response = client.post("/emotions/events", json=payload)
      return response.json()
  ```
- **[Restart]** Exit and reopen Claude Code. ⚠️ This ends your current session.
- **[Jikan verify]** Ask Claude to call `emotional_log_event` with a test observation. Expect `event_id`, `session_id`, and `sequence_num` in the response.

**15. [COMMIT] API Endpoint: GET Events**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/events` branch, GET method)
- **Action:** Add GET handler to the `/events` branch. Require at least one of `my_id`, `session_id`, or `from` — return 400 if none supplied. Apply filters to query `interaction_events`. If `my_id` filter supplied: INNER JOIN `my_ids_for_my_users_state` WHERE `m.my_id = ?`. Otherwise: LEFT JOIN to map `mifmus_id` → `my_id` in response. Decrypt `encrypted_content` for each row. If decryption returns `false`: call `print_roblog`, skip the row. Return event array with `my_id` (null if untagged).
- **Test:** `curl -H "X-API-Key: sk_..." "https://mg.robnugen.com/api/v1/emotions/events?my_id=N"` — expect a list with the event from Step 14, content decrypted and matching the original.
- **[Jikan]** Add `emotional_get_events` to `~/jikan/server.py`:
  ```python
  @mcp.tool()
  def emotional_get_events(
      my_id: int | None = None,
      session_id: int | None = None,
      from_date: str = "",
      to_date: str = "",
      event_type: str = "",
      limit: int = 50,
  ) -> list:
      """Query past interaction events. At least one of my_id, session_id, or from_date required.

      Args:
          my_id: Filter by emotional state (agent's private vocab ID)
          session_id: Filter to a specific session
          from_date: ISO datetime start of range
          to_date: ISO datetime end of range
          event_type: 'agent_action', 'user_input', or 'user_reaction'
          limit: Max results (default 50, max 200)
      """
      params: dict = {"limit": limit}
      if my_id is not None:
          params["my_id"] = my_id
      if session_id is not None:
          params["session_id"] = session_id
      if from_date:
          params["from"] = from_date
      if to_date:
          params["to"] = to_date
      if event_type:
          params["event_type"] = event_type
      with _client() as client:
          response = client.get("/emotions/events", params=params)
      return response.json()
  ```
- **[Restart]** Exit and reopen Claude Code. ⚠️ This ends your current session.
- **[Jikan verify]** Ask Claude to call `emotional_get_events` with the `my_id` from Step 9. Expect decrypted content matching the event logged in Step 14.


### Phase 4: Sessions & Deletion Logic

**16. [COMMIT] API Endpoint: GET Sessions**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/sessions` branch, GET method)
- **Action:** Replace the `/sessions` stub with a GET handler. List sessions with purely computed fields — no decryption required:
  ```sql
  SELECT
      s.session_id, s.start_time, s.last_event_time,
      TIMESTAMPDIFF(MINUTE, s.start_time, s.last_event_time) AS duration_minutes,
      COUNT(e.event_id) AS event_count
  FROM interaction_sessions s
  LEFT JOIN interaction_events e ON e.session_id = s.session_id
  WHERE s.api_key_id = ?
  GROUP BY s.session_id
  ORDER BY s.start_time DESC
  LIMIT ?
  ```
  Support optional `from`, `to`, `limit` (default 20, max 100) query params.
- **Test:** `curl -H "X-API-Key: sk_..." https://mg.robnugen.com/api/v1/emotions/sessions` — expect the session from Step 14 with correct `duration_minutes` and `event_count`.
- **[Jikan]** Add `emotional_get_sessions` to `~/jikan/server.py`:
  ```python
  @mcp.tool()
  def emotional_get_sessions(
      from_date: str = "",
      to_date: str = "",
      limit: int = 20,
  ) -> list:
      """List past interaction sessions with duration and event count. No decryption required.

      Args:
          from_date: ISO datetime start of range
          to_date: ISO datetime end of range
          limit: Max results (default 20, max 100)
      """
      params: dict = {"limit": limit}
      if from_date:
          params["from"] = from_date
      if to_date:
          params["to"] = to_date
      with _client() as client:
          response = client.get("/emotions/sessions", params=params)
      return response.json()
  ```
- **[Restart]** Exit and reopen Claude Code. ⚠️ This ends your current session.
- **[Jikan verify]** Ask Claude to call `emotional_get_sessions`. Expect the session from Step 14 with correct metadata.

**17. [COMMIT] API Endpoints: DELETE Handlers**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/events` and `/vocab` branches, DELETE method)
- **Action:**
  - `/events` branch DELETE: Accept `{"event_id": N}` in body. Execute `DELETE FROM interaction_events WHERE event_id = ? AND api_key_id = ?` (ownership check). Return `{"deleted": 1}` or `{"deleted": 0}` — never 404 (avoids leaking whether an ID exists).
  - `/vocab` branch DELETE: Accept `{"my_id": N}` in body. First `SELECT mifmus_id` and `COUNT(*)` of associated events. Then `DELETE FROM my_ids_for_my_users_state WHERE mifmus_id = ?` (FK cascade sets events' `mifmus_id` to NULL via `ON DELETE SET NULL`). Return `{"deleted": 1, "events_untagged": N}`.
- **Test:** `curl -X DELETE -H "X-API-Key: sk_..." -d '{"event_id":N}' https://mg.robnugen.com/api/v1/emotions/events` — expect `{"deleted":1}`. Then `curl -X DELETE ... -d '{"my_id":N}' .../vocab` — expect `{"deleted":1,"events_untagged":M}`. Verify event rows still exist with `mifmus_id = NULL`.
- **[Jikan]** Add `emotional_delete_event` and `emotional_delete_vocab` to `~/jikan/server.py`:
  ```python
  @mcp.tool()
  def emotional_delete_event(event_id: int) -> dict:
      """Delete a single event by event_id. Returns {"deleted": 1} or {"deleted": 0}.
      Never returns 404 — avoids leaking whether an ID exists.
      """
      with _client() as client:
          response = client.delete("/emotions/events", json={"event_id": event_id})
      return response.json()

  @mcp.tool()
  def emotional_delete_vocab(my_id: int) -> dict:
      """Delete a vocab entry by my_id. Associated events are preserved but lose
      their state tag (mifmus_id set to NULL). Returns {"deleted": 1, "events_untagged": N}.
      """
      with _client() as client:
          response = client.delete("/emotions/vocab", json={"my_id": my_id})
      return response.json()
  ```
- **[Restart]** Exit and reopen Claude Code. ⚠️ This ends your current session.
- **[Jikan verify]** Ask Claude to delete a test event and a test vocab entry. Confirm expected responses and DB state.

**18. [COMMIT + VERIFY] HTTP `DELETE` endpoint: `/api/v1/emotions/everything`**
- **File:** `wwwroot/api/v1/_emotions.php` (in the `/everything` branch, DELETE method); also delete `wwwroot/api/v1/emotions/everything.php`
- **Action:** Migrate the logic from `everything.php` (see "PHP Code Already Written" above) into the `/everything` branch of `_emotions.php`. Remove the `prepend.php` include and `\Emotional\ApiAuth::authenticate()` call — those are replaced by the variables already in scope from `index.php` (`$auth_key_id`, `$pdo`). Then delete the standalone `wwwroot/api/v1/emotions/everything.php`. The business logic (confirm check, transaction, FK-safe deletion, response shape) is identical.
- **Test:** `curl -X DELETE -H "X-API-Key: sk_..." -d '{}' .../everything` → 400. `curl -X DELETE ... -d '{"confirm":"delete everything"}' .../everything` → 200 with correct counts, all rows gone.
- **[Jikan]** Add `emotional_delete_everything` to `~/jikan/server.py`:
  ```python
  @mcp.tool()
  def emotional_delete_everything() -> dict:
      """Wipe ALL emotional data for this API key: all events, sessions, and vocab.
      This is irreversible. The confirmation string is handled automatically.
      Returns counts of deleted rows.
      """
      with _client() as client:
          response = client.delete("/emotions/everything", json={"confirm": "delete everything"})
      return response.json()
  ```
- **[Restart]** Exit and reopen Claude Code. ⚠️ This ends your current session.
- **[Jikan verify]** Ask Claude to call `emotional_delete_everything`. Expect `{"deleted": {"events": N, "sessions": M, "vocab_entries": K}}` with the test data counts from Steps 14–17.

**19. [COMMIT] Paying Client Milestone Alerts**
- **File:** `classes/Billing/StripeWebhook.php`
- **Depends on:** Step 2 (`omg_rob_this_happened` table must exist before this is meaningful, though the code is safe before that — see try/catch note below).
- **Action:** At the end of `handleCheckoutComplete()`, after `addCredits()`:
  1. Count total paying clients: `SELECT COUNT(*) FROM users WHERE stripe_customer_id IS NOT NULL`
     This is the right measure — `stripe_customer_id` is set exactly once on first payment and never removed. Using `role = 'paid'` would miss admins who paid (their role is preserved as `'admin'`).
  2. Call a private `isPayingClientMilestone(int $n): bool` method.
  3. If it returns true, insert into `omg_rob_this_happened`.

  Add the milestone helper method with a comment explaining the schedule:
  ```php
  // Milestone schedule:
  //   1–10:        every new client  (the exciting early ones)
  //   11–100:      every tenth       (20, 30, … 100)
  //   101–1,000:   every hundredth   (200, 300, … 1,000)
  //   1,001+:      every thousandth  (2,000, 3,000, …)
  private function isPayingClientMilestone(int $n): bool {
      if ($n <= 10)   return true;
      if ($n <= 100)  return $n % 10  === 0;
      if ($n <= 1000) return $n % 100 === 0;
      return                 $n % 1000 === 0;
  }
  ```

  Wrap the count query and insert in a try/catch with a comment explaining why:
  ```php
  // try/catch because omg_rob_this_happened may not exist yet if the
  // migration (Step 2) hasn't been applied. We must not let a missing
  // table throw an exception here — a 500 from this webhook causes
  // Stripe to retry the event, which would double-credit the user.
  try {
      ...
  } catch (\PDOException $e) {
      // Table not yet created — milestone silently skipped.
  }
  ```

- **Test:** Temporarily hard-code the count query to return 1, 10, 11, 20, 100, 101, 200, 1000, 1001, 2000 and assert `isPayingClientMilestone()` returns the right value for each. Verify the insert fires for milestone counts and is skipped for non-milestones (e.g. 11, 21, 101).
