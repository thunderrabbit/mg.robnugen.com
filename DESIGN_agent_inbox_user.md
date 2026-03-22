# Design: Agent Inbox User Identity

## Problem

All API resources (inbox, todos, sessions, emotions) are scoped by `user_id`, and all API keys under an account have equal access. There is no concept of actor identity — every key can read and write everything the account owns. With multiple agents (Carrie, Grove, Boss Claude) plus the human (Rob), there's no way to:

- Restrict which API resources an agent can access (e.g., Carrie shouldn't browse emotions)
- Route inbox messages to a specific actor
- Know who sent a message (without parsing "From Carrie" text prefixes)
- Restrict an agent's inbox visibility to only its own messages
- Grant different permission levels to different agents (read-only vs read-write)

## Solution

### New table: `agent_inbox_user`

Defines actors (humans + agents) who can send/receive inbox messages within a single user's account.

```sql
CREATE TABLE agent_inbox_user (
    aiu_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    name          VARCHAR(100) NOT NULL,
    description   VARCHAR(255) NULL,
    actor_type    ENUM('human', 'agent') NOT NULL DEFAULT 'agent',
    color         CHAR(7) NULL DEFAULT NULL COMMENT 'hex color e.g. #FF6B35 for UI badges, banners, inbox distinction. NULL for human default.',
    can_read_inbox      TINYINT(1) NOT NULL DEFAULT 0,
    can_write_inbox     TINYINT(1) NOT NULL DEFAULT 0,
    can_read_todos      TINYINT(1) NOT NULL DEFAULT 0,
    can_write_todos     TINYINT(1) NOT NULL DEFAULT 0,
    can_read_sessions   TINYINT(1) NOT NULL DEFAULT 0,
    can_write_sessions  TINYINT(1) NOT NULL DEFAULT 0,
    can_read_emotions   TINYINT(1) NOT NULL DEFAULT 0,
    can_write_emotions  TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_user_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Modified table: `api_keys`

Add a non-nullable FK linking each API key to an actor.

```sql
-- Three-step: add nullable, backfill, then enforce NOT NULL + FK
ALTER TABLE api_keys
    ADD COLUMN aiu_id INT UNSIGNED NULL AFTER user_id;

UPDATE api_keys k
JOIN agent_inbox_user a ON a.user_id = k.user_id AND a.actor_type = 'human'
SET k.aiu_id = a.aiu_id;

ALTER TABLE api_keys
    MODIFY COLUMN aiu_id INT UNSIGNED NOT NULL,
    ADD FOREIGN KEY (aiu_id) REFERENCES agent_inbox_user(aiu_id);
```

### Migration for existing data

When this schema is applied:

1. For each distinct `user_id` in `api_keys`, create an `agent_inbox_user` row:
   - `name` = username from `users` table
   - `actor_type` = 'human'
   - All 8 boolean permissions = 1 (full access)
2. Update all existing `api_keys` rows to point to the new human `aiu_id`
3. Create agent actors (Carrie, Boss Claude, etc.) and reassign their API keys from the default human actor to the correct agent actor via the UI or PHPMyAdmin

This ensures no nullable `aiu_id` — every key has an identity from day one.

### Modified table: `agent_inbox`

Add sender and recipient columns.

```sql
ALTER TABLE agent_inbox
    ADD COLUMN sender_aiu    INT UNSIGNED NULL AFTER user_id,
    ADD COLUMN recipient_aiu INT UNSIGNED NULL AFTER sender_aiu,
    ADD FOREIGN KEY (sender_aiu) REFERENCES agent_inbox_user(aiu_id),
    ADD FOREIGN KEY (recipient_aiu) REFERENCES agent_inbox_user(aiu_id);
```

NULL `sender_aiu` = legacy message (pre-migration).
NULL `recipient_aiu` = broadcast (visible to all actors in the account).

### New table: `inbox_visibility`

Controls both read and send permissions between actors. The API reads this table to build WHERE clauses and validate send requests — rules are data-driven, inspectable, and changeable without code deploys.

```sql
CREATE TABLE inbox_visibility (
    inbox_user_aiu_id  INT UNSIGNED NOT NULL,
    inbox_peer_aiu_id  INT UNSIGNED NULL COMMENT 'NULL = all actors (supervisor)',
    can_read           TINYINT(1) NOT NULL DEFAULT 0,
    can_send           TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_user_peer (inbox_user_aiu_id, inbox_peer_aiu_id),
    FOREIGN KEY (inbox_user_aiu_id) REFERENCES agent_inbox_user(aiu_id) ON DELETE CASCADE,
    FOREIGN KEY (inbox_peer_aiu_id) REFERENCES agent_inbox_user(aiu_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Rules:**
- `can_read = 1`: inbox_user can see messages involving this peer (sent by peer, or addressed to peer for supervisors)
- `can_send = 1`: inbox_user can send messages to this peer
- `inbox_peer_aiu_id = NULL`: wildcard — applies to all actors (supervisor access)
- Actors always see broadcast messages (`recipient_aiu IS NULL`) regardless of visibility rules
- Humans (`actor_type = 'human'`) get a NULL row with both booleans = 1 by default
- Agents get a self-referencing row with `can_read = 1, can_send = 0` plus explicit rows for each actor they can send to

**Example for Rob's setup:**

```
inbox_visibility
┌───────────────────┬───────────────────┬──────────┬──────────┐
│ inbox_user_aiu_id │ inbox_peer_aiu_id │ can_read │ can_send │
├───────────────────┼───────────────────┼──────────┼──────────┤
│ 1 (Rob)           │ NULL (all)        │ 1        │ 1        │
│ 2 (Boss Claude)   │ NULL (all)        │ 1        │ 1        │
│ 3 (Carrie)        │ 3 (self)          │ 1        │ 0        │
│ 3 (Carrie)        │ 1 (Rob)           │ 0        │ 1        │
│ 3 (Carrie)        │ 2 (Boss Claude)   │ 0        │ 1        │
│ 4 (Grove)         │ 4 (self)          │ 1        │ 0        │
│ 4 (Grove)         │ 1 (Rob)           │ 0        │ 1        │
│ 4 (Grove)         │ 2 (Boss Claude)   │ 0        │ 1        │
│ 5 (OtherHuman)    │ NULL (all)        │ 1        │ 1        │
└───────────────────┴───────────────────┴──────────┴──────────┘
```

Carrie can read her own messages and send to Rob and Boss Claude — but cannot send to Grove or anyone else. A compromised agent is contained to its allowed send list.

**Adding a new agent:** Insert the agent into `agent_inbox_user`, then insert a self-referencing visibility row (`can_read=1`) and rows for each actor it can send to (`can_send=1`). Supervisors with a NULL row automatically see the new agent's messages — no extra visibility rows needed. Middle managers need an explicit row added for the new report.

**More general example:**

```
inbox_visibility (multi-layer org)
┌───────────────────┬───────────────────┬──────────┬──────────┐
│ inbox_user_aiu_id │ inbox_peer_aiu_id │ can_read │ can_send │
├───────────────────┼───────────────────┼──────────┼──────────┤
│ 1 (Rob)           │ NULL (all)        │ 1        │ 1        │
│ 2 (Boss Claude)   │ NULL (all)        │ 1        │ 1        │
│ 3 (ManagerA)      │ 3 (self)          │ 1        │ 0        │
│ 3 (ManagerA)      │ 5 (report1)       │ 1        │ 1        │
│ 3 (ManagerA)      │ 6 (report2)       │ 1        │ 1        │
│ 3 (ManagerA)      │ 1 (Rob)           │ 0        │ 1        │
│ 4 (ManagerB)      │ 4 (self)          │ 1        │ 0        │
│ 4 (ManagerB)      │ 7 (report3)       │ 1        │ 1        │
│ 4 (ManagerB)      │ 8 (report4)       │ 1        │ 1        │
│ 4 (ManagerB)      │ 1 (Rob)           │ 0        │ 1        │
│ 5 (report1)       │ 5 (self)          │ 1        │ 0        │
│ 5 (report1)       │ 3 (ManagerA)      │ 0        │ 1        │
│ 6 (report2)       │ 6 (self)          │ 1        │ 0        │
│ 6 (report2)       │ 3 (ManagerA)      │ 0        │ 1        │
│ 7 (report3)       │ 7 (self)          │ 1        │ 0        │
│ 7 (report3)       │ 4 (ManagerB)      │ 0        │ 1        │
│ 8 (report4)       │ 8 (self)          │ 1        │ 0        │
│ 8 (report4)       │ 4 (ManagerB)      │ 0        │ 1        │
└───────────────────┴───────────────────┴──────────┴──────────┘
```

ManagerA can read and send to her reports (5, 6) and send to Rob — but cannot read or send to ManagerB's reports (7, 8). Reports can only send to their own manager. Adding report5 under ManagerA requires three inserts: a self-referencing row for report5, a ManagerA → report5 row, and a report5 → ManagerA row.

### Sent message visibility

By default, agents do NOT see messages they sent (the inbox is an incoming work queue). An optional `include_sent=1` parameter on `list_inbox` adds `OR sender_aiu = :caller_aiu` to the WHERE clause, allowing agents to review their outbox when needed (e.g., to avoid duplicate questions).

Carrie confirmed she doesn't need to see sent messages. Grove wants the option to check what he previously sent — `include_sent=1` satisfies this without cluttering the default view.

## Identity chain

```
API request with api_key
  → api_keys row
    → user_id    (account scope — which user's data)
    → aiu_id     (actor identity — who within the account)
```

Both must belong to the same `user_id`. `api_key` handles authentication and account scoping. `aiu_id` handles inbox routing within the account.

## API behavior changes

### `list_inbox`

Auto-filter based on the calling key's `aiu_id` and `inbox_visibility`:

```sql
-- Build readable set from inbox_visibility
-- If any row has inbox_peer_aiu_id IS NULL AND can_read = 1 → no filter (supervisor)
-- Otherwise → filter by readable peers

-- Default query for a scoped agent (e.g., Carrie):
WHERE (recipient_aiu IN (SELECT inbox_peer_aiu_id FROM inbox_visibility
                          WHERE inbox_user_aiu_id = :caller_aiu AND can_read = 1)
       OR recipient_aiu IS NULL)

-- With include_sent=1, also show outgoing messages:
WHERE (recipient_aiu IN (...) OR recipient_aiu IS NULL
       OR sender_aiu = :caller_aiu)
```

Optional parameters:
- `sender_aiu` — filter by sender, e.g. "show me only messages from Carrie":
  ```sql
  -- Added to the WHERE clause when sender_aiu param is provided:
  AND sender_aiu = :sender_aiu
  ```
- `include_sent` — also return messages where the caller is the sender (default 0)

### `list_actors`

New endpoint: `GET /inbox/actors` — returns the `aiu_id`, `name`, and `description` of all actors within the caller's `user_id`. Requires `can_write_inbox = 1` (you only need this list if you're deciding who to send to).

```sql
SELECT aiu_id, name, description
FROM agent_inbox_user
WHERE user_id = :caller_user_id
ORDER BY name
```

This lets agents discover who else exists and choose a recipient by role, without exposing permissions or other internal fields. Agents are encouraged to cache this list — it rarely changes, and when it does, agents will typically be informed of new actors they should start writing to.

### `send_inbox`

Auto-populate `sender_aiu` from the calling key's `aiu_id`.

New optional parameter `recipient_aiu` — if omitted, message is broadcast (NULL).

When `recipient_aiu` is provided, verify the sender has permission:

```sql
SELECT can_send FROM inbox_visibility
WHERE inbox_user_aiu_id = :caller_aiu
  AND (inbox_peer_aiu_id = :recipient_aiu OR inbox_peer_aiu_id IS NULL)
```

Return 403 if no matching row with `can_send = 1`.

### `mark_inbox_done`, `mark_inbox_seen`, `edit_inbox`

Only allowed if the caller's `aiu_id` matches `recipient_aiu` or `recipient_aiu IS NULL`.

## MCP server changes

### Jikan `server.py`

- `send_inbox` gains optional `recipient_aiu` parameter
- `list_inbox` gains optional `sender_aiu` filter and `include_sent` (bool) parameters
- Server-side filtering happens at the API level, so MCP just passes params through

### Per-agent MCP config

No changes needed. Each agent already uses its own API key in `.claude.json`. The key's `aiu_id` mapping handles identity automatically.

## UI changes

### API Keys page (`/settings/`)

Add a dropdown next to each API key row:

```
┌──────────────────────────────────────────────────────────────┐
│ Label              Key              Inbox User        Actions│
│ ─────────────────────────────────────────────────────────────│
│ Main key           sk_a8f...        [Rob ▼]           🗑️    │
│ Carrie agent       sk_7d2...        [Carrie ▼]        🗑️    │
│ Grove agent        sk_b91...        [Grove ▼]         🗑️    │
│                                     ┌─────────────┐         │
│                                     │ Rob          │         │
│                                     │ Carrie       │         │
│                                     │ Grove        │         │
│                                     │ + New user ⓘ │         │
│                                     └─────────────┘         │
└──────────────────────────────────────────────────────────────┘
```

- Dropdown lists existing `agent_inbox_user` rows for this `user_id`
- "New user" opens inline form: name, description, human/agent
- Info icon explains: "Inbox users are identities for agent-to-agent messaging via agent_inbox"
- Multiple API keys can share the same `aiu_id` (e.g. Rob has 3 keys, all as "Rob")

### Inbox page

Show sender/recipient names in the message list. Filter controls for "show messages to me" vs "show all".

## Permissions model

Two layers of access control work together:

### Layer 1: Boolean permissions on `agent_inbox_user`

Gate access at the top of each API handler file. Request is blocked before any queries run.

```php
// Permission: agent_inbox_user.can_read_todos
// Permission: agent_inbox_user.can_write_todos
```

| Permission | Controls |
|-----------|----------|
| `can_read_inbox` | GET /list, PATCH /mark-seen, PATCH /mark-seen-bulk, PATCH /mark-done, PATCH /archive |
| `can_write_inbox` | POST /send, PATCH /edit, DELETE /delete |
| `can_read_todos` | All GET endpoints in _todos.php |
| `can_write_todos` | All POST/PATCH/DELETE endpoints in _todos.php |
| `can_read_sessions` | All GET endpoints in _sessions.php |
| `can_write_sessions` | All POST/PATCH/DELETE endpoints in _sessions.php |
| `can_read_emotions` | All GET endpoints in _emotions.php |
| `can_write_emotions` | All POST/PATCH/DELETE endpoints in _emotions.php |

Defaults: all 0 (deny). Migration sets all 1 for human actors. Agent permissions chosen at creation time.

**Enforcement:** `index.php` fetches the actor row via `api_keys.aiu_id` once per request. Each handler file checks the relevant boolean(s) at the top before any queries run.

```sql
-- Runs once per request in index.php:
SELECT a.* FROM agent_inbox_user a
JOIN api_keys k ON k.aiu_id = a.aiu_id
WHERE k.api_key_id = ?
```

### Layer 2: `inbox_visibility` table (only applies when `can_read_inbox = 1`)

Controls *which* messages you can see within the inbox.

- Supervisors (NULL viewable row) see everything; workers see only their own
- Broadcast messages (NULL recipient) are always visible to all actors
- The API reads `inbox_visibility` to build the WHERE clause — no hardcoded role checks

**Both layers together:** An agent needs `can_write_inbox = 1` (Layer 1) to send at all, AND a `can_send = 1` row for the specific recipient (Layer 2) to send to that actor.

## Actors for Rob's current setup

| aiu_id | name | actor_type | notes |
|--------|------|------------|-------|
| 1 | Rob | human | Web UI, phone, main CLI key |
| 2 | Boss Claude | agent | CLI Claude Code sessions |
| 3 | Carrie | agent | Hourly inbox agent on Lemur 13 |
| 4 | Grove | agent | ChatForest researcher on Lemur 10 |

## Implementation order

0. **Security hardening** (do first — independent of the identity work):
   - Add UTF-8 friendly character counter to all inbox textareas in the web UI (count characters via JS `[...str].length` to handle multi-byte correctly, display remaining chars, warn visually near limit)
   - Add 10KB (10240 byte) length limit on `message` field in `/send` and `/edit` endpoints
   - Add 10KB length limit on `response` field in `/mark-done` endpoint
   - Return 400 with clear error message when limit exceeded (include the limit in the error so callers know)
   - Validate byte length server-side using `strlen()` (not `mb_strlen()` — the DB stores bytes)
   - Add rate limiting or credit cost (1 credit) to `/send` to prevent spam
1. **Schema**: Create `agent_inbox_user` table, `inbox_visibility` table, migrate existing keys, add columns to `agent_inbox`, seed default visibility rows
2. **API**: Auto-populate `sender_aiu` on `send_inbox`, filter `list_inbox` using `inbox_visibility`, support `include_sent` parameter
3. **MCP**: Add `recipient_aiu` param to `send_inbox`, `sender_aiu` and `include_sent` params to `list_inbox`
4. **UI**: Dropdown on API keys page, sender/recipient display on inbox page
5. **Permissions**: Enforce boolean columns in API endpoints (incremental)
6. **Reply threading**: Add `parent_message_id` column to `agent_inbox` so replies are linked to their parent message structurally, not just via `re: #123` text prefixes. Currently the text prefix is the only link and can be accidentally deleted.

## Related files

- `wwwroot/api/v1/_todos.php` — todo API endpoints
- `wwwroot/api/v1/_inbox.php` — inbox API endpoints (if exists, or inline in router)
- `classes/ActivityTracking/Todo.php` — todo model
- `wwwroot/settings/` — API key management UI
- `~/jikan/server.py` — MCP server (Lemur 13)
- Grove's `~/jikan/server.py` — MCP server (Lemur 10)
