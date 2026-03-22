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
    can_read_inbox      TINYINT(1) NOT NULL DEFAULT 1,
    can_write_inbox     TINYINT(1) NOT NULL DEFAULT 1,
    can_read_todos      TINYINT(1) NOT NULL DEFAULT 1,
    can_write_todos     TINYINT(1) NOT NULL DEFAULT 1,
    can_read_sessions   TINYINT(1) NOT NULL DEFAULT 1,
    can_write_sessions  TINYINT(1) NOT NULL DEFAULT 1,
    can_read_emotions   TINYINT(1) NOT NULL DEFAULT 1,
    can_write_emotions  TINYINT(1) NOT NULL DEFAULT 1,
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

Auto-filter based on the calling key's `aiu_id`:

```
WHERE (recipient_aiu = :caller_aiu OR recipient_aiu IS NULL)
```

Agent only sees messages addressed to it or broadcast to everyone.

Optional parameter `sender_aiu` for filtering by sender.

### `send_inbox`

Auto-populate `sender_aiu` from the calling key's `aiu_id`.

New optional parameter `recipient_aiu` — if omitted, message is broadcast (NULL).

### `mark_inbox_done`, `mark_inbox_seen`, `edit_inbox`

Only allowed if the caller's `aiu_id` matches `recipient_aiu` or `recipient_aiu IS NULL`.

## MCP server changes

### Jikan `server.py`

- `send_inbox` gains optional `recipient_aiu` parameter
- `list_inbox` gains optional `sender_aiu` filter parameter
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

The boolean columns on `agent_inbox_user` define what each actor can access:

| Permission | Controls |
|-----------|----------|
| `can_read_inbox` | Can list/see inbox messages |
| `can_write_inbox` | Can send inbox messages |
| `can_read_todos` | Can list todos |
| `can_write_todos` | Can create/complete/update todos |
| `can_read_sessions` | Can list timer sessions |
| `can_write_sessions` | Can start/stop timer sessions |
| `can_read_emotions` | Can query emotion events/vocab |
| `can_write_emotions` | Can log emotion events, create vocab |

**Enforcement is incremental.** The columns exist from day one but the API only needs to enforce inbox routing (sender/recipient filtering) initially. Permission checks for todos, sessions, and emotions can be added later without schema changes.

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
1. **Schema**: Create `agent_inbox_user` table, migrate existing keys, add columns to `agent_inbox`
2. **API**: Auto-populate `sender_aiu` on `send_inbox`, filter `list_inbox` by `recipient_aiu`
3. **MCP**: Add `recipient_aiu` param to `send_inbox`, `sender_aiu` filter to `list_inbox`
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
