# Todo "Days Between" Recurrence — Implementation Plan

## Feature Summary

Add a new recurrence type: **"Days Between"**. When a todo has
`do_every_n_days = 10`, it appears on the dashboard **10 days after
its last completion**. Each time you complete it, the clock resets.

This answers: *"How often should I do this irregular thing?"*

### How it differs from existing recurrence

| Type | Column | Logic | Example |
|---|---|---|---|
| Weekly | `do_days` | Pattern match: `FIND_IN_SET('Wed', do_days)` | Every Mon, Wed, Fri |
| Monthly | `do_dates` | Pattern match: `FIND_IN_SET(15, do_dates)` | 1st and 15th of month |
| One-time | `due_date` | Exact date match | Due March 15 |
| **Days Between** | **`do_every_n_days`** | **Completion-based: show when N days since last completion** | **Every 10 days** |

Key difference: existing recurrence is **schedule-based** (static pattern
matching against today's date). "Days Between" is **completion-based**
(dynamic, depends on `todo_logs` history).

---

## Suggested Coding Order and Commit Points

Work through this in order. Commit after each numbered step.

### Phase 1: Database

**1. [COMMIT] Add `do_every_n_days` column to `todos` table**

- **File:** `db_schemas/13_todo_days_between/create_todo_days_between.sql`
- **Action:** Create a new migration directory and SQL file:
  ```sql
  ALTER TABLE todos
    ADD COLUMN do_every_n_days SMALLINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Interval-based recurrence: show todo N days after last completion'
    AFTER do_dates;
  ```
- **Why `SMALLINT UNSIGNED`:** Range 0-65535. Even 365 (yearly) is
  well within range. `NULL` means this recurrence type is not active.
  `SMALLINT` uses 2 bytes vs 4 for `INT` — minor but there's no reason
  to use more.
- **Why `AFTER do_dates`:** Groups all recurrence columns together in
  the table definition for readability.
- **Validation:** Value must be >= 1 when set (0 or negative makes no
  sense — "every 0 days" is meaningless). Enforced in PHP, not as a
  DB constraint, to match the existing pattern where validation lives
  in application code.
- **Test:** Run the migration via `/admin/migrate_tables.php`. Verify
  the column exists: `DESCRIBE todos` shows `do_every_n_days` as
  `SMALLINT UNSIGNED`, nullable, default NULL.

> ```
> Add do_every_n_days column to todos table for interval-based recurrence
> ```

---

### Phase 2: Backend — Query Logic

**2. [COMMIT] Add "Days Between" branch to `getTodaysTodos()` query**

- **File:** `classes/ActivityTracking/Todo.php` (method `getTodaysTodos`)
- **Action:** Add a new OR branch to the WHERE clause (after the
  "Unscheduled / Anytime" branch). This is the core logic:

  ```sql
  OR
  (
      t.do_every_n_days IS NOT NULL
      AND (
          -- Show if never completed (new todo)
          NOT EXISTS (
              SELECT 1 FROM todo_logs tl
              WHERE tl.todo_id = t.todo_id
          )
          OR
          -- Show if N or more days since last completion
          (
              SELECT DATEDIFF(?, DATE(MAX(tl.date_logged)))
              FROM todo_logs tl
              WHERE tl.todo_id = t.todo_id
          ) >= t.do_every_n_days
      )
  )
  ```

  The `?` parameter is `$todayDate` (already passed to the method).

- **Why `NOT EXISTS` for never-completed:** A newly created "every 10
  days" todo should appear immediately — the user just created it, they
  presumably want to do it. Without this, a new todo with no `todo_logs`
  rows would never appear (the `DATEDIFF` subquery returns NULL).

- **Why `DATEDIFF` on `MAX(date_logged)`:** We want the most recent
  completion date, regardless of `nth` (multiple completions on the
  same day don't reset the interval multiple times). `DATEDIFF` returns
  whole days, which matches the user's mental model of "every 10 days."

- **Performance note:** The correlated subquery runs once per
  `do_every_n_days` todo. With typical todo counts (tens, not thousands),
  this is fine. If it ever becomes a bottleneck, a materialized
  `last_completed_date` column could be added — but that's premature
  optimization for a personal productivity app.

- **Test:** Manually insert a todo with `do_every_n_days = 1` and no
  completions. Verify it appears on today's dashboard. Complete it.
  Verify it disappears (the 1-minute hide logic handles this). Verify
  it reappears the next day.

> ```
> Add days-between recurrence branch to getTodaysTodos query
> ```

**3. [COMMIT] Mark "Days Between" todos as recurring in `list.php`**

- **File:** `wwwroot/api/todos/list.php`
- **Action:** Update the `$isRecurring` check (currently line 40-41):
  ```php
  // Before:
  $isRecurring = !empty($todo['do_days']) || !empty($todo['do_dates']);

  // After:
  $isRecurring = !empty($todo['do_days']) || !empty($todo['do_dates']) || !empty($todo['do_every_n_days']);
  ```

- **Why this matters:** Non-recurring (one-time) todos are hidden
  permanently after completion (lines 56-85). Without this fix, a
  completed "days between" todo would be treated as one-time and hidden
  forever.

- **Test:** Create a "days between" todo, complete it, wait 1 minute.
  Verify it's hidden from today's list (correct — completed for today).
  Advance the clock (or wait N days). Verify it reappears.

> ```
> Treat days-between todos as recurring in list.php filtering
> ```

**4. [COMMIT] Add `do_every_n_days` to the `upcoming.php` page**

- **File:** `wwwroot/todos/upcoming.php`
- **Action:** The upcoming page shows future scheduled todos. Add a
  section or integrate "days between" todos by querying `todo_logs` for
  the last completion and computing the next due date:
  ```php
  $next_due = date('Y-m-d', strtotime($last_completed . " + {$n} days"));
  ```
  If never completed, show as "Due: today" or "Due: anytime."

- **Test:** Verify "days between" todos appear on the upcoming page with
  correct next-due dates.

> ```
> Show days-between todos on upcoming page with computed next-due date
> ```

---

### Phase 3: Backend — CRUD

**5. [COMMIT] Add `do_every_n_days` to `createTodo()` field whitelist**

- **File:** `classes/ActivityTracking/Todo.php` (method `createTodo`)
- **Action:** Add `'do_every_n_days'` to the `$allowed_fields` array
  (currently lines 274-287). The existing dynamic INSERT builder will
  handle it automatically.

- **Test:** Insert a todo via the PHP class with `do_every_n_days => 7`.
  Verify the column is set in the database.

> ```
> Allow do_every_n_days in createTodo field whitelist
> ```

**6. [COMMIT] Add `do_every_n_days` to `updateTodo()` field whitelist**

- **File:** `classes/ActivityTracking/Todo.php` (method `updateTodo`)
- **Action:** Add `'do_every_n_days'` to the `$allowed_fields` array
  (currently lines 331-344).

- **Test:** Update an existing todo to set `do_every_n_days = 14`.
  Verify the column is updated. Update it to `NULL` to clear. Verify.

> ```
> Allow do_every_n_days in updateTodo field whitelist
> ```

**7. [COMMIT] Add `do_every_n_days` to inline edit API**

- **File:** `wwwroot/api/todos/update_field.php`
- **Action:** Add `'do_every_n_days'` to the allowed fields list. This
  lets the dashboard's inline editor modify the interval. Add validation:
  ```php
  if ($field === 'do_every_n_days') {
      if ($value !== null && $value !== '') {
          $value = (int) $value;
          if ($value < 1) {
              http_response_code(400);
              echo json_encode(['error' => 'do_every_n_days must be >= 1']);
              exit;
          }
      } else {
          $value = null; // Clear interval
      }
  }
  ```

- **Security:** The `update_field.php` endpoint already validates
  ownership (`WHERE todo_id = ? AND user_id = ?`) and uses parameterized
  queries. Adding to the whitelist is safe. The cast to `(int)` prevents
  injection via the value.

- **Test:** Call the API to set, change, and clear `do_every_n_days`.
  Verify each state in the database.

> ```
> Allow inline editing of do_every_n_days with validation
> ```

---

### Phase 4: Frontend — Create/Edit Form

**8. [COMMIT] Add "Days Between" field to the create form template**

- **File:** `templates/todos/create.tpl.php`
- **Action:** Add a new input inside the Recurrence fieldset, after the
  "Dates of Month" group and before the "or, for a one-time todo"
  divider:

  ```html
  <div class="form-group">
      <label for="do_every_n_days">Days Between</label>
      <input type="number"
             id="do_every_n_days"
             name="do_every_n_days"
             min="1"
             max="365"
             placeholder="e.g., 10"
             value="<?= htmlspecialchars($todo['do_every_n_days'] ?? '') ?>">
      <small class="form-help">
          Repeats this many days after each completion
      </small>
  </div>
  ```

- **Security:** `htmlspecialchars()` on the value attribute prevents XSS
  from stored data. `type="number"` with `min="1"` provides client-side
  validation (server-side is the real gate — Step 9).

- **UX consideration:** "Days Between" is a third recurrence mode. The
  form currently doesn't enforce mutual exclusivity between do_days,
  do_dates, and due_date either, so adding a fourth option without
  enforcement is consistent with the existing pattern. A future
  improvement could add JS to disable other recurrence fields when one
  is active — but that's a separate task.

- **Test:** Load the create form. Verify the field appears. Enter a
  value, inspect the POST data.

> ```
> Add days-between input field to todo create/edit form
> ```

**9. [COMMIT] Process `do_every_n_days` in the form handler**

- **File:** `wwwroot/todos/create.php`
- **Action:** Add processing after the existing `do_dates` block
  (around line 106):

  ```php
  // Days Between (interval recurrence)
  if (!empty($_POST['do_every_n_days'])) {
      $n = (int) $_POST['do_every_n_days'];
      if ($n >= 1 && $n <= 365) {
          $data['do_every_n_days'] = $n;
      }
      // Invalid values silently ignored (same pattern as do_dates)
  } else {
      $data['do_every_n_days'] = null;
  }
  ```

- **Security:** Cast to `(int)` prevents type confusion. Range check
  prevents unreasonable values. The existing `createTodo()` /
  `updateTodo()` methods use parameterized queries, so the value is
  safely bound.

- **Why max 365:** A yearly interval is the practical upper bound.
  Users wanting "every 2 years" can set 730, but 365 as a UI max
  prevents accidental huge values. The DB column (`SMALLINT UNSIGNED`,
  max 65535) allows more if the UI max is raised later.

- **Test:** Create a todo with `do_every_n_days = 7` via the form.
  Verify it's saved correctly. Edit it, change to 14. Verify.
  Clear the field, verify it's set to NULL.

> ```
> Process do_every_n_days in form handler with validation
> ```

---

### Phase 5: Frontend — Dashboard Display

**10. [COMMIT] Show "days between" info in the todo list table**

- **File:** `templates/todos/index.tpl.php`
- **Action:** In the table that lists all todos, add a display for the
  interval. Where recurrence is currently shown (do_days as day chips,
  do_dates as date numbers), add:

  ```php
  <?php if (!empty($todo['do_every_n_days'])): ?>
      <span class="recurrence-badge">
          Every <?= (int) $todo['do_every_n_days'] ?> days
      </span>
  <?php endif; ?>
  ```

- **Security:** Cast to `(int)` ensures only a number is rendered. No
  risk of XSS from this column since it's an integer, but the cast is
  cheap insurance.

- **Test:** Verify the badge appears for "days between" todos in the
  `/todos/` list view.

> ```
> Display days-between interval in todo list table
> ```

**11. [COMMIT] Show next-due date on the dashboard widget**

- **File:** `wwwroot/api/todos/list.php`
- **Action:** For "days between" todos, compute and include
  `next_due_date` in the API response so the dashboard can show when
  it's next expected:

  ```php
  if (!empty($todo['do_every_n_days'])) {
      // Find last completion date for this todo
      $logStmt = $pdo->prepare(
          'SELECT MAX(DATE(date_logged)) FROM todo_logs WHERE todo_id = ?'
      );
      $logStmt->execute([$todo['todo_id']]);
      $lastDone = $logStmt->fetchColumn();

      if ($lastDone) {
          $next = new DateTime($lastDone, $userTz);
          $next->modify('+' . (int) $todo['do_every_n_days'] . ' days');
          $todo['next_due_date'] = $next->format('Y-m-d');
          $todo['days_until_due'] = (int) $now->diff($next)->format('%r%a');
      } else {
          $todo['next_due_date'] = $today;
          $todo['days_until_due'] = 0;
      }
  }
  ```

- **Security:** `(int)` cast on `do_every_n_days` before interpolation
  into `modify()` string. The `$todo['todo_id']` is already validated
  as belonging to the user (the query in `getTodaysTodos` is scoped to
  `user_id`). The `MAX(DATE(date_logged))` subquery uses a parameterized
  `todo_id`.

- **Performance:** This adds one query per "days between" todo in the
  list. Acceptable for personal use. If needed later, the completion
  date could be denormalized.

- **File:** `wwwroot/dashboard/dashboard.js` (in `createTodoWidget`)
- **Action:** Optionally show a subtle indicator like "due in 3 days"
  or "overdue by 2 days" on the widget. This is cosmetic — skip if
  pressed for time.

- **Test:** Verify the API response includes `next_due_date` and
  `days_until_due` for "days between" todos. Verify it shows "today"
  for never-completed todos.

> ```
> Compute and return next-due date for days-between todos
> ```

---

### Phase 6: Edge Cases

**12. [COMMIT] Handle "days between" in batch create (quickadd)**

- **File:** `wwwroot/api/todos/create_batch.php`
- **Action:** Check if `do_every_n_days` is handled in the batch
  create endpoint. If quickadd supports recurrence fields, add
  `do_every_n_days` to the processing. If quickadd only creates
  simple todos (no recurrence), no change needed — document this.

- **Test:** Verify batch-created todos don't accidentally get
  `do_every_n_days` set.

> ```
> Handle do_every_n_days in batch create endpoint (or verify exclusion)
> ```

**13. [COMMIT] Handle "days between" in the completed-todos history**

- **File:** `wwwroot/api/list-fully-completed-todos.php`
- **Action:** This endpoint lists todos that are fully completed for
  today. "Days between" todos that are completed should appear here
  with the same treatment as other recurring todos. Verify the
  `$isRecurring` logic matches Step 3's update. If not, update.

- **Test:** Complete a "days between" todo. Verify it appears in the
  completed list for today.

> ```
> Ensure days-between todos appear correctly in completed history
> ```

**14. [COMMIT] Handle "days between" in the archive endpoint**

- **File:** `wwwroot/todos/archive.php`
- **Action:** Verify that archiving a "days between" todo works
  correctly. The archive endpoint sets `archived_at` — this should
  prevent it from appearing in `getTodaysTodos()` (verify the query
  filters on `archived_at IS NULL` or equivalent).

- **Test:** Archive a "days between" todo. Verify it no longer appears
  on the dashboard. Unarchive it. Verify it reappears when due.

> ```
> Verify archive/unarchive works correctly for days-between todos
> ```

---

### Phase 7: Mutual Exclusivity (Optional Enhancement)

**15. [COMMIT] Add client-side mutual exclusivity hints**

- **File:** `templates/todos/create.tpl.php` (or a linked JS file)
- **Action:** Add JavaScript to visually indicate that recurrence types
  are alternatives. When the user fills in "Days Between", dim or
  disable the "Days of Week" and "Dates of Month" fields (and vice
  versa). This is a UX improvement, not a hard requirement — the
  server should handle todos with multiple recurrence types set by
  using the first non-null one in priority order:
  1. `do_every_n_days` (highest — most specific intent)
  2. `do_days` (weekly pattern)
  3. `do_dates` (monthly pattern)
  4. `due_date` (one-time)

  **Do NOT enforce mutual exclusivity server-side at this stage.**
  Existing todos may have both `do_days` and `do_dates` set (the
  current query ORs them), and retroactively breaking that would be
  a regression.

- **Alternative:** Skip this step entirely. The form already doesn't
  enforce exclusivity between the existing three types.

> ```
> Add optional client-side hints for recurrence type exclusivity
> ```

---

## Security Checklist

All security considerations for this feature:

| Concern | Mitigation | Where |
|---|---|---|
| **SQL injection via `do_every_n_days`** | Parameterized queries in `createTodo()` and `updateTodo()` | `Todo.php` |
| **SQL injection in subquery** | `$todayDate` already parameterized; `do_every_n_days` comes from DB, not user input | `Todo.php:getTodaysTodos` |
| **XSS in form value** | `htmlspecialchars()` on the `value` attribute | `create.tpl.php` |
| **XSS in display** | `(int)` cast when rendering | `index.tpl.php`, `list.php` |
| **Integer overflow** | `SMALLINT UNSIGNED` (max 65535); PHP validates 1-365 | `create.php`, `update_field.php` |
| **Type confusion** | `(int)` cast before use everywhere | All files |
| **Negative values** | PHP validates `>= 1`; `UNSIGNED` in DB rejects negatives | `create.php`, `update_field.php`, schema |
| **Ownership bypass** | All queries scoped to `user_id` (existing pattern) | `Todo.php`, all API files |
| **IDOR on inline edit** | `update_field.php` verifies `user_id` ownership | `update_field.php` |
| **`modify('+N days')` injection** | `(int)` cast before string interpolation | `list.php` Step 11 |
| **Denial of service (huge interval)** | UI max 365, server validates range | `create.php`, `update_field.php` |

### Performance considerations

- The `NOT EXISTS` / `DATEDIFF` subquery in `getTodaysTodos()` is
  correlated (runs once per `do_every_n_days` todo). This is fine for
  personal use (tens of todos).
- The `MAX(DATE(date_logged))` query in `list.php` Step 11 runs once per
  "days between" todo in today's list. Again fine for personal use.
- If performance ever becomes an issue, add a denormalized
  `last_completed_date` column to `todos` and update it in
  `logCompletion()`. But don't do this now — it adds write-side
  complexity for a problem that doesn't exist yet.

---

## Files Touched Summary

| File | Work |
|---|---|
| `db_schemas/13_todo_days_between/create_todo_days_between.sql` | **Create** (new migration) |
| `classes/ActivityTracking/Todo.php` | Add query branch + field whitelists |
| `wwwroot/api/todos/list.php` | Update `$isRecurring`, add next-due computation |
| `wwwroot/api/todos/update_field.php` | Add to allowed fields with validation |
| `wwwroot/api/todos/create_batch.php` | Verify/update for new field |
| `wwwroot/api/list-fully-completed-todos.php` | Verify recurring detection |
| `wwwroot/todos/create.php` | Process new form field |
| `wwwroot/todos/upcoming.php` | Show days-between todos with next-due |
| `wwwroot/todos/archive.php` | Verify compatibility |
| `templates/todos/create.tpl.php` | Add "Days Between" input |
| `templates/todos/index.tpl.php` | Display interval badge |
| `wwwroot/dashboard/dashboard.js` | Optional: show next-due indicator |

---

## Notes

- The existing recurrence model is **schedule-based** (pattern matching
  against today's date). "Days Between" introduces **completion-based**
  recurrence (depends on `todo_logs` history). This is a meaningful
  architectural addition, but it slots cleanly into the existing
  `getTodaysTodos()` query as just another OR branch.
- Completing a "days between" todo does NOT update the `todos` row.
  There is no write-side reschedule logic. The "next due" date is
  computed at read time from `todo_logs`. This is simpler and means
  existing completion/uncomplete endpoints need zero changes.
- The 1-minute hide logic in `list.php` (lines 91-102) already handles
  the "completed today, hide from dashboard" behavior for recurring
  items. No changes needed there.
- Start with Steps 1-3 to get the core working, then eyeball it on the
  dashboard before continuing to the form/display steps.
