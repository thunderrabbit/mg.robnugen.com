# Todo/Activity MCP Server — Planning Document

## The Question: New MCP or Extend Jikan?

### Option A: Add to Jikan

**Pros:**
- One MCP server to manage, one process to restart
- Shared `_client()` helper, same base URL, same `X-API-Key` auth
- Activities and sessions are already in Jikan — todos are a natural extension

**Cons:**
- Jikan's name means "time" — todos aren't really about time
- Jikan would grow from ~20 tools to ~30+, making it harder to maintain
- **The real blocker: todo endpoints use cookie/session auth, not API
  keys.** Jikan's `_client()` sends `X-API-Key` headers. The todo
  endpoints (`/api/todos/*`) require a PHP session cookie from a
  browser login. Mixing two auth models in one MCP client is messy.

### Option B: New MCP server

**Pros:**
- Clean separation: timers/emotions vs. productivity/tasks
- Can use a different auth model (cookie-based or a new API key layer)
- Each server stays focused and manageable
- Easier to disable/swap one without affecting the other

**Cons:**
- Two MCP servers to configure and restart
- Some duplication (httpx client setup, base URL config)

### Option C: Migrate todo endpoints to API v1 first, then add to Jikan

**Pros:**
- Unifies auth under `X-API-Key` (the direction the app is heading)
- Todo tools would use the same `_client()` as everything else in Jikan
- Cleaner long-term architecture

**Cons:**
- Requires writing new v1 todo endpoints before the MCP can be built
- More upfront work, but pays off in consistency

### Recommendation: **Option C** (migrate to v1, then add to Jikan)

The todo endpoints are the last major feature still on legacy cookie auth.
Migrating them to v1 is the right architectural move regardless of MCP
plans. Once they're on `X-API-Key`, adding them to Jikan is trivial —
same pattern as every other tool.

If you want something working faster, **Option B** is the pragmatic
fallback — build a separate MCP now with cookie auth, replace it later
when v1 endpoints exist.

---

## Name Candidates (if building a separate MCP)

| Name | Meaning | Vibe |
|---|---|---|
| **Yarukoto** (やること) | "things to do" | Direct, Japanese, matches Jikan |
| **Shigoto** (仕事) | "work / tasks" | More formal, professional |
| **Tasuku** (タスク) | Japanese phonetic for "task" | Simple, obvious |
| **Katsudo** (活動) | "activity" — covers both todos and activities | Broader scope |
| **Mokuhyou** (目標) | "goal / objective" | Aspirational |
| **Ganbaru** (頑張る) | "to persevere / do your best" | Fits the Meiso Gambare brand |

**Recommendation:** **Yarukoto** — it means exactly what the MCP does
("things to do"), pairs well with Jikan ("time"), and is easy to type
as a server name.

---

## The Auth Problem (the key technical decision)

The todo endpoints live at `/api/todos/*` and use PHP session cookies.
To call them from an MCP server, you'd need to:

1. **Option: Cookie forwarding** — Log in via browser, extract the
   session cookie, configure the MCP with it. Fragile — cookies expire.

2. **Option: New v1 endpoints** — Write `/api/v1/todos/*` routes in
   `index.php` using `X-API-Key` auth (same as sessions/emotions).
   The todo PHP class already exists — it's mostly wiring.

3. **Option: Add API key auth to legacy endpoints** — Modify each
   `/api/todos/*.php` file to accept either cookie OR `X-API-Key`.
   Quick but ugly — two auth paths in every file.

**Recommendation:** Option 2. The v1 front controller (`index.php`)
already handles auth and passes `$auth_user_id` to sub-dispatchers.
Adding a `_todos.php` sub-dispatcher follows the exact same pattern as
`_emotions.php`. The existing `ActivityTracking\Todo` class does all
the heavy lifting.

---

## Phase 0: Encrypt Todos and Activities (Prerequisite)

Before exposing todos and activities via API keys, their text content
must be encrypted at rest — the same way the emotional ledger encrypts
`state` and `content`. Otherwise, DreamHost (or anyone with DB access)
can read users' todo titles and activity names in plaintext.

### What needs encrypting

| Table | Columns | Current usage |
|---|---|---|
| `todos` | `title`, `description` | Display only + secondary sort by title |
| `activities` | `activity_name`, `description` | Display, ORDER BY, duplicate check, JOINed into every session query |

### What currently relies on plaintext

**Todos — title:**
1. `Todo.php:58` — `ORDER BY t.title ASC` (secondary sort)
2. `list.php:161` — `strcasecmp($a['title'], $b['title'])` (PHP sort)
3. `dashboard.js:369` — `titleA.localeCompare(titleB)` (JS re-sort)

**Activities — activity_name:**
1. `Activity.php:62-68` — `WHERE user_id = ? AND activity_name = ?`
   (duplicate check on create — **cannot work on encrypted data**)
2. `Activity.php:28` — `ORDER BY activity_name` (listing sort)
3. Every session query (7+ locations) — `JOIN activities` to display
   `activity_name` alongside session data
4. `list-activities.php:22` — `ORDER BY activity_name` (legacy listing)
5. `_activities.php:18` — calls `getActivitiesForUser()` which sorts by name

**No LIKE/FULLTEXT searches exist on any of these columns.** Only exact
match (duplicate check) and ORDER BY.

### The encryption key question

The emotional ledger derives its key from the raw API key:
```php
$encKey = hash_hmac('sha256', 'emotional_v1', $rawApiKey, true);
```

This works because emotional data is only accessed via `X-API-Key` auth.
But todos and activities are currently accessed via **cookie/session auth**
(the browser dashboard), where the raw API key is not available.

**Options:**

1. **Encrypt per-user with a user-derived key** — derive from user's
   password hash or a stored per-user secret. Available in both cookie
   and API-key auth contexts. But password changes would require
   re-encryption.

2. **Encrypt per-API-key (same as emotions)** — only decrypt when
   accessed via API key. The browser dashboard would need to be updated
   to call v1 endpoints with an API key (stored in a JS cookie or
   localStorage). This is the cleanest long-term approach but requires
   dashboard refactoring.

3. **Server-side encryption with a shared app secret** — encrypt with
   a key from `Config.php`. Protects against DB-only breaches (hosting
   provider, backups) but not against app-server compromise. Simpler:
   works with both auth models without changes.

**Recommendation:** Option 3 for now (app-secret encryption). It solves
the immediate problem (data at rest) without requiring a dashboard
rewrite. Upgrade to per-key encryption later when the dashboard moves
to v1 endpoints.

### Coding order

**0a. [COMMIT] Fix secondary sorts to not depend on plaintext**

Replace title-based sorts with `todo_id` or `created_at_utc`:

- **`Todo.php:58`** — change `ORDER BY t.do_time ASC, t.title ASC`
  to `ORDER BY t.do_time ASC, t.todo_id ASC`
- **`list.php:161`** — change `strcasecmp($a['title'], $b['title'])`
  to `$a['todo_id'] - $b['todo_id']`
- **`dashboard.js:369`** — change `titleA.localeCompare(titleB)` to
  compare by `data-todo-id` attribute instead

Similarly for activities:
- **`Activity.php:28`** — change `ORDER BY activity_name` to
  `ORDER BY activity_id`
- **`list-activities.php:22`** — same change

> ```
> Replace plaintext-dependent sorts with ID-based sorts
> ```

**0b. [COMMIT] Fix activity duplicate check**

The duplicate check (`WHERE activity_name = ?`) cannot work on encrypted
data. Options:
1. Store a **keyed hash** of the name alongside the encrypted blob:
   `name_hash = hash_hmac('sha256', strtolower(trim($name)), $appSecret)`
   Check uniqueness against the hash. This preserves the duplicate check
   without exposing plaintext.
2. Remove the duplicate check entirely (allow duplicate names).

Recommendation: Option 1 (hash-based uniqueness).

- **`activities` table:** Add column `name_hash CHAR(64) NULL`
- **`Activity.php`:** Change duplicate check to `WHERE user_id = ? AND name_hash = ?`
- **`Activity.php`:** On create, compute and store the hash

> ```
> Add hash-based activity duplicate check for encryption compatibility
> ```

**0c. [COMMIT] Add encryption helpers for app-secret encryption**

- **File:** `classes/Encryption/AppSecret.php` (new class)
- **Action:** Similar to `Emotional\Ledger` but derives key from
  `Config::APP_ENCRYPTION_KEY` (a new config constant) instead of
  a raw API key. Same XSalsa20-Poly1305 algorithm.
- **File:** `classes/ConfigSample.php` — add `APP_ENCRYPTION_KEY`
  placeholder

> ```
> Add app-secret encryption helpers for todo/activity encryption
> ```

**0d. [COMMIT] Encrypt todo title and description**

- **`Todo.php`:** Encrypt `title` and `description` on
  `createTodo()` and `updateTodo()`. Decrypt on all read methods.
- **Schema change:** `ALTER TABLE todos MODIFY title TEXT NOT NULL`
  (encrypted blobs are longer than 255 chars).
- **Migration script:** Encrypt all existing plaintext rows in place.

> ```
> Encrypt todo title and description at rest
> ```

**0e. [COMMIT] Encrypt activity name and description**

- **`Activity.php`:** Encrypt `activity_name` and `description` on
  create. Decrypt on all read methods.
- **Schema change:** `ALTER TABLE activities MODIFY activity_name TEXT NOT NULL`
- **Migration script:** Encrypt all existing plaintext rows, compute
  and store `name_hash` for each.
- **Session queries:** All 7+ queries that JOIN `a.activity_name` will
  now get encrypted blobs. Add decryption in the PHP code that processes
  these results (in `_sessions.php`, `list-completed-sessions.php`,
  `list-active-sessions.php`, `SessionKey.php`, `ActivityKai.php`,
  `Todo.php` JOIN results).

This is the largest step — many files touch `activity_name` via JOINs.

> ```
> Encrypt activity name and description at rest
> ```

**0f. [COMMIT] Verify dashboard still works end-to-end**

- Load the dashboard, verify todos display with decrypted titles
- List activities, verify names display correctly
- Create a new todo, verify it encrypts and displays
- Create a new activity, verify duplicate check works via hash
- List sessions, verify activity names display correctly
- Complete a todo, verify completion log works

> ```
> Verify encryption does not break dashboard functionality
> ```

### Impact on Jikan (existing activity tools)

Jikan's `create_activity` and `list_activities` tools currently send
and receive **plaintext** activity names via the v1 API. After
encryption:

- **`POST /api/v1/activities`** — the endpoint will encrypt the name
  before storage. **No change needed in Jikan** — the API accepts
  plaintext and handles encryption server-side.
- **`GET /api/v1/activities`** — the endpoint will decrypt names before
  returning them. **No change needed in Jikan** — the API returns
  plaintext after server-side decryption.
- The encryption is transparent to API consumers.

---

## Phase 1: Migrate Todo Endpoints to API v1

### Suggested Coding Order

**1. [COMMIT] Create `_todos.php` sub-dispatcher stub**

- **File:** `wwwroot/api/v1/_todos.php`
- **Action:** Create stub, same pattern as `_emotions.php`. Route to
  sub-paths: `/list`, `/complete`, `/uncomplete`, `/create`, `/update`,
  `/archive`.
- **File:** `wwwroot/api/v1/index.php`
- **Action:** Add routing branch:
  ```php
  } elseif ($path === '/todos' || preg_match('#^/todos(/|$)#', $path)) {
      include __DIR__ . '/_todos.php';
  }
  ```
- **Test:** `curl -H "X-API-Key: sk_..." .../api/v1/todos/list` → 404
  "not yet implemented"

> ```
> Add v1 todos sub-dispatcher stub with routing
> ```

**2. [COMMIT] GET /todos/list — today's todos**

- **Action:** Port logic from `/api/todos/list.php` into the `/list`
  branch. Replace `$_SESSION['user_id']` with `$auth_user_id` (from
  `index.php` auth context). Accept `timezone` as query param.
- **Returns:** Same JSON shape as the legacy endpoint.
- **Test:** Compare output of legacy and v1 endpoints side-by-side.

> ```
> Add GET /api/v1/todos/list endpoint
> ```

**3. [COMMIT] POST /todos/complete — log completion**

- **Action:** Port from `/api/todos/complete.php`. Accept JSON body
  `{todo_id, nth, timezone}`.
- **Returns:** `{log_id, date_logged}`

> ```
> Add POST /api/v1/todos/complete endpoint
> ```

**4. [COMMIT] POST /todos/uncomplete — remove completion**

- **Action:** Port from `/api/todos/uncomplete.php`. Accept JSON body
  `{todo_id, nth, timezone}`.

> ```
> Add POST /api/v1/todos/uncomplete endpoint
> ```

**5. [COMMIT] POST /todos/create — create a todo**

- **Action:** Port from `/api/todos/create_batch.php` (single-item
  mode) or write a cleaner version. Accept JSON body with todo fields.
- **Validation:** Whitelist fields same as `createTodo()`. Validate
  `do_every_n_days` range (1-365) if the days-between feature is
  merged by then.
- **Returns:** `{todo_id, title, ...}`

> ```
> Add POST /api/v1/todos/create endpoint
> ```

**6. [COMMIT] PATCH /todos/update — update a todo field**

- **Action:** Port from `/api/todos/update_field.php`. Accept JSON body
  `{todo_id, field, value}`.
- **Security:** Whitelist allowed fields. Validate ownership.

> ```
> Add PATCH /api/v1/todos/update endpoint
> ```

**7. [COMMIT] DELETE /todos/archive — soft-delete a todo**

- **Action:** Port from `/api/todos/archive.php`. Accept JSON body
  `{todo_id}`. Sets `is_active = 0`.
- **Security:** Validate ownership.

> ```
> Add DELETE /api/v1/todos/archive endpoint
> ```

**8. [COMMIT] POST /todos/complete-with-session — link completion to timer**

- **Action:** Port from `/api/todos/complete-with-session.php`. Accept
  JSON body `{todo_id, ak_id, duration_seconds, timezone}`.
- **Returns:** `{log_id, nth, date_logged}`

> ```
> Add POST /api/v1/todos/complete-with-session endpoint
> ```

**9. [COMMIT] GET /todos/history — completion history**

- **Action:** Port from `/api/list-fully-completed-todos.php`. Accept
  query params `limit`, `offset`.

> ```
> Add GET /api/v1/todos/history endpoint
> ```

---

## Phase 2: Add Todo Tools to Jikan

Once the v1 endpoints exist, add tools to `~/jikan/server.py`. Each
tool follows the existing pattern: `@mcp.tool()` decorator, docstring
with Args, `_client()` context manager.

**10. [COMMIT] Add `list_todos` tool**

```python
@mcp.tool()
def list_todos(timezone: str = "UTC") -> dict:
    """Get today's todos based on recurrence rules and timezone.

    Args:
        timezone: IANA timezone name, e.g. 'Asia/Tokyo'
    """
    with _client() as client:
        response = client.get("/todos/list", params={"timezone": timezone})
    return response.json()
```

> ```
> Add list_todos tool to Jikan MCP
> ```

**11. [COMMIT] Add `complete_todo` and `uncomplete_todo` tools**

```python
@mcp.tool()
def complete_todo(todo_id: int, nth: int = 1, timezone: str = "UTC") -> dict:
    """Mark a todo as completed for today.

    Args:
        todo_id: The todo to complete
        nth: Which occurrence (1 for first, 2 for second, etc.)
        timezone: IANA timezone name
    """
    with _client() as client:
        response = client.post("/todos/complete", json={
            "todo_id": todo_id, "nth": nth, "timezone": timezone
        })
    return response.json()

@mcp.tool()
def uncomplete_todo(todo_id: int, nth: int = 1, timezone: str = "UTC") -> dict:
    """Remove a todo completion for today.

    Args:
        todo_id: The todo to uncomplete
        nth: Which occurrence to remove
        timezone: IANA timezone name
    """
    with _client() as client:
        response = client.post("/todos/uncomplete", json={
            "todo_id": todo_id, "nth": nth, "timezone": timezone
        })
    return response.json()
```

> ```
> Add complete_todo and uncomplete_todo tools to Jikan MCP
> ```

**12. [COMMIT] Add `create_todo` tool**

```python
@mcp.tool()
def create_todo(
    title: str,
    do_days: str = "",
    do_dates: str = "",
    do_every_n_days: int | None = None,
    due_date: str = "",
    do_time: str = "",
    target_count: int = 1,
    activity_id: int | None = None,
    description: str = "",
) -> dict:
    """Create a new todo.

    Args:
        title: Todo title
        do_days: Comma-separated days of week (e.g. 'Mon,Wed,Fri')
        do_dates: Comma-separated dates of month (e.g. '1,15,30')
        do_every_n_days: Repeat every N days after completion (1-365)
        due_date: One-time due date (YYYY-MM-DD)
        do_time: Time of day (HH:MM)
        target_count: How many times per day (default 1)
        activity_id: Link to an activity for timed todos
        description: Optional description
    """
    payload: dict = {"title": title}
    if do_days: payload["do_days"] = do_days
    if do_dates: payload["do_dates"] = do_dates
    if do_every_n_days is not None: payload["do_every_n_days"] = do_every_n_days
    if due_date: payload["due_date"] = due_date
    if do_time: payload["do_time"] = do_time
    if target_count != 1: payload["target_count"] = target_count
    if activity_id is not None: payload["activity_id"] = activity_id
    if description: payload["description"] = description
    with _client() as client:
        response = client.post("/todos/create", json=payload)
    return response.json()
```

> ```
> Add create_todo tool to Jikan MCP
> ```

**13. [COMMIT] Add `update_todo` and `archive_todo` tools**

```python
@mcp.tool()
def update_todo(todo_id: int, field: str, value: str) -> dict:
    """Update a single field on a todo.

    Args:
        todo_id: The todo to update
        field: Field name (title, do_time, due_date, target_duration_seconds, do_every_n_days)
        value: New value for the field
    """
    with _client() as client:
        response = client.patch("/todos/update", json={
            "todo_id": todo_id, "field": field, "value": value
        })
    return response.json()

@mcp.tool()
def archive_todo(todo_id: int) -> dict:
    """Soft-delete a todo (sets is_active = 0).

    Args:
        todo_id: The todo to archive
    """
    with _client() as client:
        response = client.request("DELETE", "/todos/archive", json={"todo_id": todo_id})
    return response.json()
```

> ```
> Add update_todo and archive_todo tools to Jikan MCP
> ```

**14. [COMMIT] Add `todo_history` tool**

```python
@mcp.tool()
def todo_history(limit: int = 20, offset: int = 0) -> dict:
    """List completed todos with pagination.

    Args:
        limit: Max results (default 20)
        offset: Pagination offset
    """
    with _client() as client:
        response = client.get("/todos/history", params={
            "limit": limit, "offset": offset
        })
    return response.json()
```

> ```
> Add todo_history tool to Jikan MCP
> ```

---

## Phase 3: Update OpenAPI Spec

**15. [COMMIT] Document v1 todo endpoints in `openapi.yaml`**

Add all `/todos/*` paths to `wwwroot/api/v1/openapi.yaml`.

> ```
> Document v1 todo endpoints in openapi.yaml
> ```

---

## Files Touched Summary

| File | Phase | Work |
|---|---|---|
| **Phase 0: Encryption** | | |
| `classes/Encryption/AppSecret.php` | 0 | **Create** (encryption helper) |
| `classes/ConfigSample.php` | 0 | Add `APP_ENCRYPTION_KEY` |
| `classes/ActivityTracking/Todo.php` | 0 | Encrypt/decrypt + fix sorts |
| `classes/ActivityTracking/Activity.php` | 0 | Encrypt/decrypt + hash-based duplicate check + fix sorts |
| `classes/ActivityTracking/ActivityKai.php` | 0 | Decrypt activity_name in session results |
| `classes/ActivityTracking/SessionKey.php` | 0 | Decrypt activity_name in session results |
| `wwwroot/api/todos/list.php` | 0 | Fix title sort |
| `wwwroot/api/list-activities.php` | 0 | Fix name sort |
| `wwwroot/api/list-completed-sessions.php` | 0 | Decrypt activity_name |
| `wwwroot/api/list-active-sessions.php` | 0 | Decrypt activity_name |
| `wwwroot/api/v1/_sessions.php` | 0 | Decrypt activity_name |
| `wwwroot/api/v1/_activities.php` | 0 | Decrypt activity_name |
| `wwwroot/dashboard/dashboard.js` | 0 | Fix title sort |
| `db_schemas/` | 0 | Migration: widen columns + encrypt rows + add name_hash |
| **Phase 1: v1 Endpoints** | | |
| `wwwroot/api/v1/index.php` | 1 | Add `/todos` routing |
| `wwwroot/api/v1/_todos.php` | 1 | **Create** (new sub-dispatcher) |
| **Phase 2: Jikan Tools** | | |
| `~/jikan/server.py` | 2 | Add ~7 todo tools |
| **Phase 3: Docs** | | |
| `wwwroot/api/v1/openapi.yaml` | 3 | Document new endpoints |

---

## Security Notes

- All v1 todo endpoints inherit `X-API-Key` auth from `index.php` —
  no additional auth code needed in `_todos.php`.
- Ownership checks must use `$auth_user_id` (from the API key's linked
  user), not `$_SESSION['user_id']`.
- The `createTodo()` and `updateTodo()` methods already use field
  whitelists and parameterized queries — safe to call from v1.
- Rate limiting: todo operations are free (no credit cost). If abuse
  becomes a concern, add rate limiting later. For now, the API key
  itself is the gate.

---

## Decision Log

- **Separate MCP vs extend Jikan:** Extend Jikan (after migrating to v1)
- **Auth model:** Reuse `X-API-Key` via v1 front controller
- **Credit cost for todo endpoints:** Free (todos are a core feature,
  not a premium API)
- **Legacy endpoint deprecation:** Keep legacy endpoints working for the
  browser dashboard. They can be deprecated later once the dashboard is
  updated to use v1 endpoints (separate project).
