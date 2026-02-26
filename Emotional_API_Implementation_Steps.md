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

**3b. [COMMIT] Implement Alert Presentation and Dismissal Handlers**
- **Files:** Required scripts or endpoints to handle the frontend interaction of dismissing an alert (e.g., an AJAX endpoint `wwwroot/admin/api/dismiss_alert.php` or similar).
- **Action:** Since the strategy document mentions "Dismissal sets `acknowledged_at = NOW()`", write the backend logic to accept a dismissal request and execute the `UPDATE` query.
- **Test:** Use the UI (or cURL) to trigger the dismissal and verify the database record updates `acknowledged_at` and the banner disappears.


### Phase 2: Emotional API Database & Crypto

**4. [COMMIT] Emotional API Core Database Schema**
- **File:** `db_schemas/13_emotional_api/create_emotional_api.sql`
- **Action:** Create the 3 core tables in dependency order: `my_ids_for_my_users_state`, then `interaction_sessions`, then `interaction_events`.
- **Test:** Run the migration via `/admin/migrate_tables.php` and verify all three tables are created.

**5. [COMMIT] API Authentication Foundation**
- **File:** `classes/Emotional/ApiAuth.php`
- **Action:** Validate `X-API-Key` header against `api_keys` table. Return `[api_key_id, user_id, rawKey]`. Compute `hash('sha256', $submittedKey)` for lookups and set `api_keys.last_used = NOW()`.
- **Test:** Make a test request with a valid key and ensure success; verify a request without a key returns HTTP 401. This test needs to happen through the MCP at `~/jikan/` so we need to update the `wwwroot/api/v1/openapi.yaml` and potentially the code in `~/jikan/` to support the new Emotional API endpoints.

**6. [COMMIT] Ledger Encryption Foundation: Key Derivation**
- **File:** `classes/Emotional/Ledger.php`
- **Action:** Create the class. Implement derivation of a 32-byte AES key using `hash_hmac('sha256', 'emotional_v1', $rawApiKey, true)`.
- **Test:** Write a temporary simple script to output a derived key from a known input and ensure it produces 32 bytes.

**7. [COMMIT] Ledger Encryption Foundation: Encrypt Function**
- **File:** `classes/Emotional/Ledger.php`
- **Action:** Implement `emotional_encrypt()` using `sodium_crypto_secretbox` and the derived key.
- **Test:** Write a temporary script to encrypt a known string with a known key and successfully output the base64 blob.

**8. [COMMIT] API Endpoint: POST Vocab Routing & Auth**
- **File:** `wwwroot/api/emotional/vocab.php` (POST method)
- **Action:** Setup the file and routing for the POST method. Parse incoming JSON body for `state`. Call `ApiAuth` to validate the key and get `api_key_id`. Ensure it correctly extracts the `state` payload.
- **Test:** Hit the endpoint with `X-API-Key` and `{ "state": "test" }` and verify it does not return a 401.

**9. [COMMIT] API Endpoint: POST Vocab DB Insertion & Encryption**
- **File:** `wwwroot/api/emotional/vocab.php` (POST method)
- **Action:** Generate a random `my_id` (100000 to 999999999). Use the Ledger class to derive the encryption key and encrypt the `state` string. Execute the `INSERT INTO my_ids_for_my_users_state` query. Return `{ "my_id": <int> }`.
- **Test:** POST a new state. Check the database directly to ensure the row is inserted with the correct `api_key_id`, generated `my_id`, and encrypted blob.

**10. [COMMIT] API Endpoint: POST Vocab Collision Retry Loop**
- **File:** `wwwroot/api/emotional/vocab.php` (POST method)
- **Action:** Wrap the `my_id` generation and `INSERT` statement in a loop (max 5 attempts) to handle potential `UNIQUE` constraint violations on `my_id`.
- **Test:** Temporarily force a collision in the code to ensure the loop successfully retries and eventually inserts.

**11. [COMMIT] API Endpoint: POST Vocab Alert Escalation**
- **File:** `wwwroot/api/emotional/vocab.php` (POST method)
- **Action:** If all 5 collision attempts fail, trigger a `print_roblog` entry, execute `INSERT INTO omg_rob_this_happened`, and return HTTP 500.
- **Test:** Temporarily force the loop to fail all 5 times. Verify the HTTP 500 response and check the Admin Dashboard banner for the new alert.

**12. [COMMIT] API Endpoint: GET Vocab & Ledger Decryption Foundations**
- **Files:** `wwwroot/api/emotional/vocab.php` (GET method), `classes/Emotional/Ledger.php`
- **Action:** Implement `emotional_decrypt()` in `Ledger.php`. In `vocab.php`, call `ApiAuth`, query `my_ids_for_my_users_state`, and use the decrypt function to return the plaintext vocabulary for the authenticated key.
- **Test:** Update `openapi.yaml` and add POST/GET vocab tools to the `jikan` MCP server. Make a GET request via the MCP server. The response should successfully return the decrypted label we POSTed earlier. This inherently tests the full cryptographic round-trip.

**13. [COMMIT] Session Auto-Detection Logic**
- **File:** `classes/Emotional/Ledger.php`
- **Action:** Implement `getOrCreateSession($api_key_id, $user_id)`. Query `interaction_sessions` for the most recent session within `EMOTIONAL_SESSION_GAP_MINUTES` using `FOR UPDATE` lock. Update `last_event_time` if found, or insert a new row if not.
- **Test:** Call twice within the gap to verify the identical `session_id` is updated. Trick the timestamp so it exceeds the gap, then verify a new `session_id` is generated.

**14. [COMMIT] API Endpoint: POST Events**
- **File:** `wwwroot/api/emotional/events.php` (POST method)
- **Action:** Accept an event payload, optionally resolve `my_id` to its `mifmus_id`, call the auto-session, obtain a `sequence_num` atomically (using `SELECT MAX+1 ... FOR UPDATE`), encrypt content via `emotional_encrypt()`, and insert into `interaction_events`.
- **Test:** POST an event. Verify the row exists in the DB and that the content field is an unreadable blob.

**15. [COMMIT] API Endpoint: GET Events**
- **File:** `wwwroot/api/emotional/events.php` (GET method)
- **Action:** Apply filters (`my_id`, `session_id`, `from`, etc) to query `interaction_events`. Require at least one filter. Join with `my_ids_for_my_users_state` to map back to `my_id`. Decrypt `encrypted_content` via `emotional_decrypt()`. Catch decryption failures: omit row, log using `print_roblog`, return 500 on total failure.
- **Test:** GET with the `my_id` filter and assert decrypted content matches the original input.

### Phase 4: Sessions & Deletion Logic

**16. [COMMIT] API Endpoint: GET Sessions**
- **File:** `wwwroot/api/emotional/sessions.php` (GET method)
- **Action:** List sessions returning purely calculated fields: `duration_minutes` and `event_count`. Handled entirely server-side (no decryption overhead needed).
- **Test:** Call the endpoint and assert that a session created in Step 14 appears correctly.

**17. [COMMIT] API Endpoints: DELETE Handlers**
- **Files:** `events.php` and `vocab.php` (DELETE methods)
- **Action:** Add single-item deletion validation.
  - Sub-step A: Verify `events.php` delete executes `WHERE event_id=? AND api_key_id=?` ownership check.
  - Sub-step B: Verify `vocab.php` delete removes entry and returns total of `events_untagged`.
- **Test:** Delete an event, verify it's removed; delete a vocab entry, verify count and that corresponding event rows persist with a now-null `mifmus_id`.

**18. [COMMIT] API Endpoint: Wipe Emotional Content (DELETE)**
- **File:** `wwwroot/api/emotional/wipe_emotional.php` (POST method)
- **Action:** Demand `{"confirm": "delete emotional content"}` in body; otherwise 400. In an SQL transaction, count all relevant rows for `interaction_events`, `interaction_sessions`, and `my_ids_for_my_users_state`, then DELETE these rows per `api_key_id` securely traversing strictly in FK order. Return counts.
- **Test:** Send request without confirm -> 400. Send request with confirm -> 200, verify all emotional data associated with the api_key is wiped, but Jikan timer sessions/activities remain untouched.

**19. [COMMIT] API Endpoint: Wipe Timers (DELETE)**
- **File:** `wwwroot/api/emotional/wipe_timers.php` (POST method)
- **Action:** Demand `{"confirm": "delete timers"}` in body; otherwise 400. In an SQL transaction, count all relevant rows for Jikan timer sessions (`meisou_sessions` or equivalent table based on schema) and activities, then DELETE these rows per `api_key_id`. Return counts.
- **Test:** Send request without confirm -> 400. Send request with confirm -> 200, verify all timer sessions/activities associated with the api_key are wiped, but emotional interaction data remains untouched.

**20. [COMMIT] API Endpoint: Complete Wipe (DELETE everything)**
- **File:** `wwwroot/api/emotional/everything.php` (POST method)
- **Action:** Demand `{"confirm": "delete everything"}` in body; otherwise 400. In an SQL transaction, execute the deletion logic from *both* Step 18 and Step 19. Wipe `interaction_events`, `interaction_sessions`, `my_ids_for_my_users_state`, and all Jikan timer schemas. Return combined counts.
- **Test:** Send request without confirm -> 400. Send request with confirm -> 200, verify all data (emotional and timers) associated with the api_key is completely wiped.
