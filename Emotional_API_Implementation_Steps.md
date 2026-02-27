# Emotional API v3: Step-by-Step Implementation Guide

This guide augments the original `Emotional_API_Implementation_Strategy.md`, laying out every task across all components in a strictly logical progression. It resolves temporal paradoxes in the original sequence (such as relying on the `omg_rob_this_happened` table before it was created) and includes defensive fixes and admin UI scaffolding so they are properly committed when needed.

---

## Suggested Coding Order and Commit Points

Work through this in order. Commit after each numbered step — small commits make it easy to roll back a broken step without losing everything else.

### Phase 1: Foundations & Admin Scaffolding

**1. [COMMIT] Defensive SQL fix in Database Class**
- **File:** `classes/Database/Base.php` (method `executeMultipleSQL`)
- **Action:** Update `Database\Base::executeMultipleSQL` to strip single-line SQL comments before splitting on semicolons.
  ```php
  // Strip single-line SQL comments before splitting on semicolons
  $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
  ```
- **Why:** Protects against schema parsing errors caused by inline `--` comments in future migrations.

**2. [COMMIT] Create Admin Alerts Database Table**
- **File:** `db_schemas/11_admin_alerts/create_admin_alerts.sql`
- **Action:** Create the `omg_rob_this_happened` table to track critical human-attention failures. Include an initial test insert:
  ```sql
  INSERT INTO omg_rob_this_happened (context, message)
  VALUES ('system/setup', 'We created a system to alert you to important messages!');
  ```
- **Test:** Run the migration via `/admin/migrate_tables.php` and verify the table exists and the row is present.

**3. [COMMIT] Admin Dashboard UI for Alerts** *(already implemented)*
- **Files:** `classes/Admin/OmgAlerts.php`, `wwwroot/admin/index.php`, `templates/admin/index.tpl.php`, `wwwroot/css/styles.css`
- **What was done:** `OmgAlerts::getUnread()` queries for unread alerts (fails safely if table is missing). `OmgAlerts::dismissAll()` sets `acknowledged_at = NOW()`. Admin index POSTs to `/admin/` with CSRF token to dismiss. Banner rendered in template when `!empty($omg_alerts)`.
- **Test:** Verify the admin dashboard banner appears for the manually-inserted alert from Step 2, and that dismissing it clears the banner.


### Phase 2: Emotional API Database & Crypto

**4. [COMMIT] Emotional API Core Database Schema**
- **File:** `db_schemas/12_emotional_api/create_emotional_api.sql`
- **Action:** Create the 3 core tables in dependency order: `my_ids_for_my_users_state`, then `interaction_sessions`, then `interaction_events`. Use MySQL `COMMENT 'text'` syntax for column annotations — do **not** use `--` inline comments (risk of false semicolon splits in the importer).
- **Test:** Run the migration via `/admin/migrate_tables.php` and verify all three tables are created.

**5. [COMMIT] API Authentication Foundation**
- **File:** `classes/Emotional/ApiAuth.php`
- **Action:** Validate `X-API-Key` header against `api_keys` table. Compute `hash('sha256', $submittedKey)` and compare to `api_key_hash` column (added in migration `10_hash_api_keys`) WHERE `is_active = 1`. Return `['api_key_id' => ..., 'user_id' => ..., 'raw_key' => ...]`. Update `api_keys.last_used = NOW()` on success. Return `false` on failure (caller exits 401).
- **Test:** Hit `/api/emotional/vocab` with a valid key and ensure no 401; verify a request without a key returns HTTP 401.

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
- **File:** `wwwroot/api/emotional/vocab.php` (POST method)
- **Action:** Set up the file with standard `prepend.php` include pattern. Route by `$_SERVER['REQUEST_METHOD']`. Parse incoming JSON body for `state`. Call `ApiAuth::authenticate()` to validate the key and get `[api_key_id, user_id, raw_key]`. Return 405 for unsupported methods.
- **Test:** Hit the endpoint with `X-API-Key` and `{"state": "test"}` and verify it does not return 401.

**9. [COMMIT] API Endpoint: POST Vocab DB Insertion & Encryption**
- **File:** `wwwroot/api/emotional/vocab.php` (POST method)
- **Action:** Derive encryption key from `raw_key`. Encrypt the `state` string. Generate a random `my_id`: `random_int(100000, 999999999)`. Execute `INSERT INTO my_ids_for_my_users_state (api_key_id, my_id, state) VALUES (?, ?, ?)`. Return `{"my_id": <int>}`.
- **Test:** POST a new state. Check the database directly to ensure the row is inserted with the correct `api_key_id`, generated `my_id`, and an encrypted blob in `state`.

**10. [COMMIT] API Endpoint: POST Vocab Collision Retry Loop**
- **File:** `wwwroot/api/emotional/vocab.php` (POST method)
- **Action:** Wrap the `my_id` generation and `INSERT` in a loop (max 5 attempts) to handle potential `UNIQUE` constraint violations on `(api_key_id, my_id)`.
- **Test:** Temporarily force a collision in the code to ensure the loop successfully retries and eventually inserts.

**11. [COMMIT] API Endpoint: POST Vocab Alert Escalation**
- **File:** `wwwroot/api/emotional/vocab.php` (POST method)
- **Action:** If all 5 collision attempts fail, call `print_roblog` with context (api_key_id, attempted my_ids), then execute:
  ```sql
  INSERT INTO omg_rob_this_happened (context, message)
  VALUES ('emotional/vocab', '5 my_id collisions exhausted for api_key_id X')
  ```
  Return HTTP 500.
- **Test:** Temporarily force the loop to fail all 5 times. Verify HTTP 500 response and check the Admin Dashboard banner for the new alert.

**12. [COMMIT] API Endpoint: GET Vocab & Ledger Decryption**
- **Files:** `wwwroot/api/emotional/vocab.php` (GET method), `classes/Emotional/Ledger.php`
- **Action:** Implement `emotional_decrypt()` (already done in Step 7 if combined, otherwise add here). In `vocab.php` GET handler: call `ApiAuth`, query `my_ids_for_my_users_state WHERE api_key_id = ?`, decrypt each `state` value, return array of `[{my_id, state}]`. If decryption returns `false` for a row, call `print_roblog` and return 500.
- **Test:** GET the vocab endpoint. Response should return the decrypted label POSTed in Step 9. Tests the full cryptographic round-trip.


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
- **File:** `wwwroot/api/emotional/events.php` (POST method)
- **Action:** Accept event payload (`my_id` optional, `event_type`, `content`). Within a transaction:
  1. Resolve `my_id` → `mifmus_id`: `SELECT mifmus_id FROM my_ids_for_my_users_state WHERE api_key_id=? AND my_id=?` (NULL if no `my_id` supplied)
  2. Call `getOrCreateSession()` to get `session_id`
  3. Assign `sequence_num` atomically: `SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM interaction_events WHERE session_id = ? FOR UPDATE`
  4. Encrypt `content` via `emotional_encrypt()`
  5. `INSERT INTO interaction_events`
  6. Return `{"event_id": ..., "session_id": ..., "sequence_num": ...}`
- **Test:** POST an event. Verify the row exists in the DB and that `encrypted_content` is an unreadable blob.

**15. [COMMIT] API Endpoint: GET Events**
- **File:** `wwwroot/api/emotional/events.php` (GET method)
- **Action:** Require at least one of `my_id`, `session_id`, or `from` — return 400 if none supplied. Apply filters to query `interaction_events`. If `my_id` filter supplied: INNER JOIN `my_ids_for_my_users_state` WHERE `m.my_id = ?`. Otherwise: LEFT JOIN to map `mifmus_id` → `my_id` in response. Decrypt `encrypted_content` for each row. If decryption returns `false`: call `print_roblog`, skip the row. Return event array with `my_id` (null if untagged).
- **Test:** GET with `my_id` filter and assert decrypted content matches original input.


### Phase 4: Sessions & Deletion Logic

**16. [COMMIT] API Endpoint: GET Sessions**
- **File:** `wwwroot/api/emotional/sessions.php` (GET method)
- **Action:** List sessions with purely computed fields — no decryption required:
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
- **Test:** Call the endpoint and assert that a session created in Step 14 appears with correct duration and event count.

**17. [COMMIT] API Endpoints: DELETE Handlers**
- **Files:** `wwwroot/api/emotional/events.php` and `wwwroot/api/emotional/vocab.php` (DELETE methods)
- **Action:**
  - `events.php DELETE`: Accept `{"event_id": N}` in body. Execute `DELETE FROM interaction_events WHERE event_id = ? AND api_key_id = ?` (ownership check). Return `{"deleted": 1}` or `{"deleted": 0}` — never 404 (avoids leaking whether an ID exists).
  - `vocab.php DELETE`: Accept `{"my_id": N}` in body. First `SELECT mifmus_id` and `COUNT(*)` of associated events. Then `DELETE FROM my_ids_for_my_users_state WHERE mifmus_id = ?` (FK cascade sets events' `mifmus_id` to NULL via `ON DELETE SET NULL`). Return `{"deleted": 1, "events_untagged": N}`.
- **Test:** Delete an event — verify it's removed. Delete a vocab entry — verify count returned and that corresponding event rows still exist with `mifmus_id = NULL`.

**18. [COMMIT] API Endpoint: DELETE everything** *(file already created)*
- **File:** `wwwroot/api/emotional/everything.php` (DELETE method)
- **What exists:** File is implemented. Accepts `{"confirm": "delete everything"}` in body (returns 400 if missing or wrong). In a transaction: counts then deletes `interaction_events`, `interaction_sessions`, and `my_ids_for_my_users_state` in FK-safe order for the authenticated `api_key_id`. Returns `{"deleted": {"events": N, "sessions": M, "vocab_entries": K}}`.
- **Test:** Send DELETE without confirm → 400. Send DELETE with `{"confirm": "delete everything"}` → 200 with counts, all rows gone.
