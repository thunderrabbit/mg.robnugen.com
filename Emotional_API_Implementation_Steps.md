# Emotional API v3: Step-by-Step Implementation Guide

This guide augments the original `Emotional_API_Implementation_Strategy.md`, laying out every task across all components in a strictly logical progression. It resolves temporal paradoxes in the original sequence (such as relying on the `omg_rob_this_happened` table before it was created) and includes defensive fixes and admin UI scaffolding so they are properly committed when needed.

---

## Suggested Coding Order and Commit Points

Work through this in order. Commit after each numbered step — small commits make it easy to roll back a broken step without losing everything else.

### Phase 1: Foundations & Admin Scaffolding

**1. [COMMIT] Defensive SQL fix in Database Class**
- **File:** `classes/Database/Base.php` (or wherever `executeMultipleSQL` is defined)
- **Action:** Update `Database\Base::executeMultipleSQL` to strip single-line SQL comments before splitting on semicolons.
  ```php
  // Strip single-line SQL comments before splitting on semicolons
  $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
  ```
- **Why:** Protects against schema parsing errors caused by inline `--` comments in future migrations.

**2. [COMMIT] Create Admin Alerts Database Table**
- **File:** `db_schemas/12_admin_alerts/create_admin_alerts.sql` (or similar sequential naming)
- **Action:** Create the `omg_rob_this_happened` table to track critical human-attention failures. Include an initial test insert:
  ```sql
  INSERT INTO omg_rob_this_happened (context, message)
  VALUES ('system/setup', 'We created a system to alert you to important messages!');
  ```
- **Test:** Run the migration via `/admin/migrate_tables.php` and verify the table exists and the row is present.

**3. [COMMIT] Admin Dashboard UI for Alerts**
- **Files:** `classes/Admin/OmgAlerts.php`, `wwwroot/admin/index.php`, `templates/admin/index.tpl.php`
- **Action:** Implement queries to check `SELECT COUNT(*) FROM omg_rob_this_happened WHERE acknowledged_at IS NULL`. Display a banner on the admin index when count > 0. Add dismissal logic to set `acknowledged_at = NOW()`.
- **Test:** Verify the admin dashboard banner appears for the manually-inserted alert from Step 2, and that dismissing it removes the banner.

### Phase 2: Emotional API Database & Crypto

**4. [COMMIT] Emotional API Core Database Schema**
- **File:** `db_schemas/13_emotional_api/create_emotional_api.sql`
- **Action:** Create the 3 core tables in dependency order: `my_ids_for_my_users_state`, then `interaction_sessions`, then `interaction_events`.
- **Test:** Run the migration via `/admin/migrate_tables.php` and verify all three tables are created.

**5. [COMMIT] API Authentication Foundation**
- **File:** `classes/Emotional/ApiAuth.php`
- **Action:** Validate `X-API-Key` header against `api_keys` table. Return `[api_key_id, user_id, rawKey]`. Compute `hash('sha256', $submittedKey)` for lookups and set `api_keys.last_used = NOW()`.
- **Test:** Make a test request with a valid key and ensure success; verify a request without a key returns HTTP 401.

**6. [COMMIT] Emotional Ledger Encryption Logic**
- **File:** `classes/Emotional/Ledger.php`
- **Action:** Implement `emotional_encrypt()` and `emotional_decrypt()` using `sodium_crypto_secretbox`. Add derivation of a 32-byte AES key using `hash_hmac('sha256', 'emotional_v1', $rawApiKey, true)`. No DB code yet.
- **Test:** Write a quick script to encrypt a string, decrypt it, and assert that the decrypted value matches the original.

### Phase 3: Vocabulary & Event Endpoints

**7. [COMMIT] API Endpoint: GET Vocab**
- **File:** `wwwroot/api/emotional/vocab.php` (GET method)
- **Action:** Call `ApiAuth`, query `my_ids_for_my_users_state`, and return the decrypted vocabulary for the authenticated key.
- **Test:** GET with valid key, expect `[]` on a fresh install.

**8. [COMMIT] API Endpoint: POST Vocab & Collision Alerting**
- **File:** `wwwroot/api/emotional/vocab.php` (POST method)
- **Action:** Parse incoming `state` string, generate a random `my_id` (100000 to 999999999), encrypt state, and `INSERT`.
- **Error Path:** Add a retry loop for `my_id` collisions. Escalate to the `omg_rob_this_happened` table and trigger a `print_roblog` entry after 5 failures, returning HTTP 500.
- **Test:** POST `{"state":"test_anger"}`, expect `{"my_id": <int>}`. Make a GET request to verify the new entry exists and decrypts. Verify a forced collision exhaustion displays on the Admin Banner.

**9. [COMMIT] Session Auto-Detection Logic**
- **File:** `classes/Emotional/Ledger.php`
- **Action:** Implement `getOrCreateSession($api_key_id, $user_id)`. Query `interaction_sessions` for the most recent session within `EMOTIONAL_SESSION_GAP_MINUTES` using `FOR UPDATE` lock. Update `last_event_time` if found, or insert a new row if not.
- **Test:** Call twice within the gap to verify the identical `session_id` is updated. Trick the timestamp so it exceeds the gap, then verify a new `session_id` is generated.

**10. [COMMIT] API Endpoint: POST Events**
- **File:** `wwwroot/api/emotional/events.php` (POST method)
- **Action:** Accept an event payload, optionally resolve `my_id` to its `mifmus_id`, call the auto-session, obtain a `sequence_num` atomically (using `SELECT MAX+1 ... FOR UPDATE`), encrypt content, and insert into `interaction_events`.
- **Test:** POST an event. Verify the row exists in the DB and that the content field is an unreadable blob.

**11. [COMMIT] API Endpoint: GET Events**
- **File:** `wwwroot/api/emotional/events.php` (GET method)
- **Action:** Apply filters (`my_id`, `session_id`, `from`, etc) to query `interaction_events`. Require at least one filter. Join with `my_ids_for_my_users_state` to map back to `my_id`. Decrypt `encrypted_content`. Catch decryption failures: omit row, log using `print_roblog`, return 500 on total failure.
- **Test:** GET with the `my_id` filter and assert decrypted content matches the original input.

### Phase 4: Sessions & Deletion Logic

**12. [COMMIT] API Endpoint: GET Sessions**
- **File:** `wwwroot/api/emotional/sessions.php` (GET method)
- **Action:** List sessions returning purely calculated fields: `duration_minutes` and `event_count`. Handled entirely server-side (no decryption overhead needed).
- **Test:** Call the endpoint and assert that a session created in Step 10 appears correctly.

**13. [COMMIT] API Endpoints: DELETE Handlers**
- **Files:** `events.php` and `vocab.php` (DELETE methods)
- **Action:** Add single-item deletion validation.
  - Sub-step A: Verify `events.php` delete executes `WHERE event_id=? AND api_key_id=?` ownership check.
  - Sub-step B: Verify `vocab.php` delete removes entry and returns total of `events_untagged`.
- **Test:** Delete an event, verify it's removed; delete a vocab entry, verify count and that corresponding event rows persist with a now-null `mifmus_id`.

**14. [COMMIT] API Endpoint: Complete Wipe (DELETE)**
- **File:** `wwwroot/api/emotional/everything.php` (POST method - taking DELETE-like behavior)
- **Action:** Demand `{"confirm": "delete everything"}` in body; otherwise 400. In an SQL transaction, count all relevant rows for `interaction_events`, `interaction_sessions`, and `my_ids_for_my_users_state`, then DELETE rows per api_key securely traversing strictly in FK order. Return counts.
- **Test:** Send request without confirm -> 400. Send request with confirm -> 200, verify all data associated to api_key is wiped.
