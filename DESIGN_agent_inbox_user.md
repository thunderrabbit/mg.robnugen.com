# Design: Agent Inbox User Identity

## Problem

The `agent_inbox` table is a flat queue scoped to `user_id`. All API keys under an account have equal access. With multiple agents (Carrie, Grove, Boss Claude) plus the human (Rob), there's no way to:

- Route messages to a specific actor
- Know who sent a message (without parsing "From Carrie" text prefixes)
- Restrict an agent's inbox visibility to only its own messages
- Define per-actor permissions for inbox, todos, sessions, emotions

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
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_user_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Modified table: `api_keys`

Add a non-nullable FK linking each API key to an actor.

```sql
ALTER TABLE api_keys
    ADD COLUMN aiu_id INT UNSIGNED NOT NULL AFTER user_id,
    ADD FOREIGN KEY (aiu_id) REFERENCES agent_inbox_user(aiu_id);
```

### Migration for existing data

When this schema is applied:

1. For each distinct `user_id` in `api_keys`, create an `agent_inbox_user` row:
   - `name` = username from `users` table
   - `actor_type` = 'human'
   - All permissions = 1
2. Update all existing `api_keys` rows to point to the new human `aiu_id`

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

Declares which actors' messages each actor can see. This is the access control layer — the API reads this table to build the WHERE clause, so visibility rules are data-driven, inspectable, and changeable without code deploys.

```sql
CREATE TABLE inbox_visibility (
    viewer_aiu_id    INT UNSIGNED NOT NULL,
    viewable_aiu_id  INT UNSIGNED NULL COMMENT 'NULL = can see all actors (supervisor)',
    UNIQUE KEY uniq_viewer_viewable (viewer_aiu_id, viewable_aiu_id),
    FOREIGN KEY (viewer_aiu_id) REFERENCES agent_inbox_user(aiu_id) ON DELETE CASCADE,
    FOREIGN KEY (viewable_aiu_id) REFERENCES agent_inbox_user(aiu_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Rules:**
- A row with `viewable_aiu_id = NULL` means the viewer can see all messages (supervisor access)
- A row with `viewable_aiu_id = X` means the viewer can see messages where `recipient_aiu = X`
- Actors always see broadcast messages (`recipient_aiu IS NULL`) regardless of visibility rules
- Humans (`actor_type = 'human'`) get a NULL row by default (see everything)
- Agents get a self-referencing row by default (see own messages only)

**Example for Rob's setup:**

```
inbox_visibility
┌────────────────┬──────────────────┐
│ viewer_aiu_id  │ viewable_aiu_id  │
├────────────────┼──────────────────┤
│ 1 (Rob)        │ NULL  ← see all  │
│ 2 (Boss Claude)│ NULL  ← see all  │
│ 3 (Carrie)     │ 3     ← own only │
│ 4 (Grove)      │ 4     ← own only │
│ 5 (OtherHuman) │ NULL  ← see all  │
└────────────────┴──────────────────┘
```

**Adding a new agent:** Insert the agent into `agent_inbox_user`, then insert a self-referencing visibility row. Supervisors with a NULL row automatically see the new agent's messages — no extra visibility rows needed.

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
-- Build viewable set from inbox_visibility
-- If any row has viewable_aiu_id IS NULL → no recipient filter (supervisor)
-- Otherwise → WHERE recipient_aiu IN (viewable set) OR recipient_aiu IS NULL

-- Default query for a scoped agent (e.g., Carrie):
WHERE (recipient_aiu IN (SELECT viewable_aiu_id FROM inbox_visibility
                          WHERE viewer_aiu_id = :caller_aiu)
       OR recipient_aiu IS NULL)

-- With include_sent=1, also show outgoing messages:
WHERE (recipient_aiu IN (...) OR recipient_aiu IS NULL
       OR sender_aiu = :caller_aiu)
```

Optional parameters:
- `sender_aiu` — filter by sender
- `include_sent` — also return messages where the caller is the sender (default 0)

### `send_inbox`

Auto-populate `sender_aiu` from the calling key's `aiu_id`.

New optional parameter `recipient_aiu` — if omitted, message is broadcast (NULL).

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

Inbox visibility is controlled by the `inbox_visibility` table (see above). This is data-driven — rules are inspectable via SQL and changeable without code deploys.

**What the visibility table controls:**
- Which actors' incoming messages you can see
- Supervisors (NULL viewable row) see everything; workers see only their own
- Broadcast messages (NULL recipient) are always visible to all actors

**What it does NOT control (yet):**
- Write restrictions (who can send to whom) — currently anyone can send to anyone
- Todo/session/emotion access — all agents can read all todos for now; scoping these is a separate project

**Enforcement:** The API reads `inbox_visibility` to build the WHERE clause for `list_inbox`. No hardcoded role checks — the table is the single source of truth for inbox access control.

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
