# Design: Agent Inbox Encryption at Rest

## Problem
Agent inbox messages are stored as plaintext TEXT in the `agent_inbox` table. Anyone with database access can read them.

## Current State
- Decision Matrix already encrypts data client-side using AES-GCM (PBKDF2, 600k iterations)
- Passphrase verification blob stored in `dm_user_config.passphrase_verify`
- Crypto library: `wwwroot/js/dm_crypto.js`
- Passphrase cached in `sessionStorage` under key `dm_pass`

## Design

### 1. New table: `user_crypto_config` (schema 20)

```sql
CREATE TABLE IF NOT EXISTS user_crypto_config (
    user_id INT UNSIGNED PRIMARY KEY,
    passphrase_verify BLOB NOT NULL COMMENT 'encrypted known string for passphrase verification',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

This replaces `dm_user_config` as the single source of passphrase verification for ALL encrypted features. Migration step: copy existing rows from `dm_user_config` → `user_crypto_config`, then update Decision Matrix code to read from the new table.

### 2. Shared crypto library

Rename `dm_crypto.js` → `mg_crypto.js` (or keep both, with `dm_crypto.js` just importing from the shared one). Update the sessionStorage key from `dm_pass` to `mg_pass` so the name is feature-neutral.

The passphrase is entered **once** and works for both Decision Matrix and Inbox (and any future encrypted feature).

### 3. Inbox changes

#### Database
- Change `agent_inbox.message` from `TEXT` to `BLOB` (schema 21 migration)
- Change `agent_inbox.response` from `TEXT` to `BLOB`
- Add `is_encrypted TINYINT(1) NOT NULL DEFAULT 0` column — allows mixed plaintext/encrypted rows during migration rollout
- Add migration: `ALTER TABLE agent_inbox MODIFY message BLOB NOT NULL, MODIFY response BLOB DEFAULT NULL, ADD COLUMN is_encrypted TINYINT(1) NOT NULL DEFAULT 0;`

#### API (`wwwroot/api/v1/_inbox.php`)
- **Write path**: Accept base64-encoded encrypted message from client, `base64_decode()` before storing
- **Read path**: `base64_encode()` the BLOB before returning in JSON; include `is_encrypted` flag so clients know whether to decrypt
- No server-side encryption/decryption — the server never sees plaintext

#### Web UI (`templates/inbox/index.tpl.php`)
- On page load: check `sessionStorage` for cached passphrase
- If no cached passphrase: show passphrase prompt (same pattern as Decision Matrix)
- Verify against `user_crypto_config.passphrase_verify`
- On success: decrypt all messages client-side before rendering
- New message form: encrypt message client-side before POST

#### Jikan MCP (`~/jikan/server.py`)
- Jikan reads messages via the API — it will receive base64-encoded encrypted blobs
- Option A: Jikan decrypts using a stored passphrase (passphrase in our brain / openbrain)
- Option B: Jikan passes encrypted blobs to Claude, Claude decrypts (not practical)
- **Recommended: Option A** — store the passphrase in openbrain, Jikan retrieves it at startup

### 4. Migration plan (order of operations)

1. Create `user_crypto_config` table (schema 20)
2. Migrate data from `dm_user_config` → `user_crypto_config`
3. Update Decision Matrix PHP to read from `user_crypto_config`
4. Rename `dm_crypto.js` → `mg_crypto.js`, update sessionStorage key
5. Update Decision Matrix templates to use `mg_crypto.js`
6. Add passphrase prompt to inbox UI
7. Alter `agent_inbox` columns to BLOB (schema 21)
8. Update inbox API to handle base64 blobs
9. Update inbox UI to encrypt/decrypt client-side
10. Update Jikan to decrypt messages using stored passphrase
11. One-time migration: encrypt existing plaintext messages (run once from browser). Each row sets `is_encrypted = 1` after successful encryption, so an interrupted migration leaves a safe mixed state rather than corrupted data.

### 5. Passphrase setup flow

- If user has NO row in `user_crypto_config`: show setup page (create passphrase)
- If user already has DM config but no `user_crypto_config`: migrate automatically
- Once set up, passphrase works everywhere — enter once per browser session

### 6. What about Claude reading messages?

Claude reads inbox via Jikan MCP → mg.robnugen.com API. After encryption:
- API returns encrypted blobs
- Jikan needs the passphrase to decrypt before passing to Claude
- Store passphrase in openbrain (already has the DM password concept)
- Jikan calls openbrain at startup, caches passphrase in memory
- Alternative: store passphrase in Jikan's own config file (simpler but less secure)

### 7. Server-side search impact

Encrypted BLOBs cannot be queried with `WHERE message LIKE '%keyword%'`. In practice this is a non-issue:
- **Agents** (Carrie, Grove) only read messages addressed to them via `WHERE recipient_aiu = :me` — a small set decrypted in memory. They never do freetext search across all messages.
- **Rob via web UI** — client-side filtering after decryption is fine for the expected message volume.
- If freetext search ever becomes necessary at scale, a separate encrypted search index could be added later.

### 8. Passphrase loss

**There is no recovery path.** If the passphrase is lost, all encrypted messages are permanently unreadable. This is an intentional tradeoff of client-side encryption — the server has no key to recover with.

Mitigations:
- Rob knows the passphrase (it's the same one used for Decision Matrix)
- The passphrase is stored in OpenBrain for agent access — which also serves as a backup reference
- Optionally: generate a one-time recovery key at setup time, print it, store it offline (not implemented in v1)
