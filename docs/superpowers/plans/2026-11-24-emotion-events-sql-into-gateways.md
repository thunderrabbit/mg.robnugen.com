# Emotion events and sessions SQL into gateways: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the 11 query calls left in `wwwroot/api/v1/_emotions.php` into `Emotional\Events` and `Emotional\Sessions`, with no change in behaviour.

**Architecture:** One gateway object per table, `\PDO` in the constructor, built like `classes/Emotional/Vocab.php`. The page keeps validation, transactions, encryption (`Emotional\Ledger`) and the JSON it echoes; it only stops holding SQL.

**Tech Stack:** PHP 8.5, PDO (MySQL in production, in-memory SQLite in the Unit suite), Codeception Unit suite, phpcs PSR-12, PHPStan, Rector.

**Spec:** `docs/superpowers/specs/2026-11-24-sql-into-gateways-rest-design.md` (group 1).

## Global Constraints

- Branch `emotion-events-sql-into-gateway`; the BEGIN commit and the design commit are already on it. Never push, never check out or merge into `master`, never run `git close-bubble` (the session does that after `next-step.sh --confirm`).
- The SQL is copied exactly from the page: same keywords, columns, order, placeholders and parameter order. Only the indentation inside a multi-line string may differ.
- Transactions stay in the page. `Emotional\Ledger` is not touched (`getOrCreateSession()` keeps its SQL).
- Tests come before any page uses a gateway. A query SQLite can run is run on in-memory SQLite, as `Tests/Unit/EmotionVocabQueriesTest.php` does. A query it cannot run is pinned: a PHPUnit mock of `\PDO` and `\PDOStatement` asserts the SQL passed to `prepare()` (runs of whitespace collapsed to one space on both sides) and the array passed to `execute()`. Each test's docblock says which kind it is.
- Before each commit: `~/work/rob/standards-mcp/run-rector.sh ~/work/rob/mg.robnugen.com` (exit 2 means it would change something: take Rector's form in the same commit), then `run-unit.sh`, `run-phpcs.sh`, `run-phpstan.sh` with the same argument. The pre-commit hook has the last word.
- Commits: subject at most 50 characters, imperative, in the voice of `git log` (`Events POST: insert via Events`); body says why; several `-m` flags, never a heredoc; last `-m` is `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`.
- This repo is public. No warning or alarm wording anywhere.

## Review Focus

1. `from`, `to` or `event_type` arrives as an array (`?from[]=x`): the page must hand the gateway a string or null, never the raw value. (Task 5, Task 8: convert with `\Field::toString` in the page; noted in the calls list as the one input whose handling is now defined.)
2. `my_id` filter with a handle that belongs to another key: no events come back. (Task 1 test.)
3. `updateEvent` with `mifmus_id => null`: the event is untagged, which is different from the key being absent. (Task 1 test.)
4. `updateEvent` with no changes, or with a column other than the two allowed: `\InvalidArgumentException`, no query run. (Task 1 test.)
5. `deleteEvent` and `updateEvent` with another key's `event_id`: nothing changes, 0 and false. (Task 1 test.)

---

### Task 1: `Emotional\Events` with its test

**Files:**
- Create: `classes/Emotional/Events.php`
- Create: `Tests/Unit/EmotionEventQueriesTest.php`

**Interfaces (produces):**

```php
namespace Emotional;

class Events
{
    public function __construct(private readonly \PDO $pdo)

    /** How many events carry a state (by its row id). */
    public function getCountTaggedWith(int $mifmus_id): int

    /** The next sequence number of a session; locks its rows. Call inside the caller's transaction. */
    public function nextSequenceNum(int $session_id): int

    /** @return int the new event_id */
    public function addEvent(
        int $session_id,
        int $api_key_id,
        int $user_id,
        ?int $mifmus_id,
        string $event_type,
        int $sequence_num,
        string $encrypted_content
    ): int

    /**
     * A key's events, oldest first, content still encrypted.
     * @return list<array<mixed>> event_id, session_id, sequence_num, event_timestamp, event_type, my_id, encrypted_content
     */
    public function getEvents(
        int $api_key_id,
        ?int $my_id,
        ?int $session_id,
        ?string $from,
        ?string $to,
        ?string $event_type,
        int $limit
    ): array

    /** @return int rows deleted (0 or 1) */
    public function deleteEvent(int $event_id, int $api_key_id): int

    /**
     * @param array<string, string|int|null> $changes only 'encrypted_content' and/or 'mifmus_id'
     * @return bool false when the key has no such event
     * @throws \InvalidArgumentException for no changes or any other column
     */
    public function updateEvent(int $event_id, int $api_key_id, array $changes): bool

    public function getCount(int $api_key_id): int
    public function deleteAll(int $api_key_id): void
}
```

SQL sources in `wwwroot/api/v1/_emotions.php` (line numbers at commit `2cc5db3`): `getCountTaggedWith` 165; `nextSequenceNum` 210; `addEvent` 218; `getEvents` 261-298; `deleteEvent` 329; `updateEvent` 378; `getCount` 468; `deleteAll` 480.

Decisions the signatures leave open:
- `getEvents` builds `$join`, `$where` and `$params` exactly as the page does (LEFT JOIN by default; INNER JOIN with `m.my_id = ?` and the handle as the first parameter when `$my_id` is given; then session, from, to, event_type in that order; `$limit` last) and calls `execute($params)`. The page's `array_unshift($params, ...)` line is dead (the next line overwrites it) and is not copied; the commit body says so.
- `updateEvent` writes its `SET` in the fixed order `encrypted_content`, then `mifmus_id`, which is the order the page uses.
- `deleteEvent` and `updateEvent` return from `rowCount()`, as the page does.

- [ ] **Step 1: Write the failing test.** `EmotionEventQueriesTest` extends `Codeception\Test\Unit`; `_before()` makes SQLite tables `my_ids_for_my_users_state` (as in `EmotionVocabQueriesTest`), `interaction_sessions (session_id INTEGER PRIMARY KEY, api_key_id, user_id, start_time TEXT, last_event_time TEXT)` and `interaction_events (event_id INTEGER PRIMARY KEY, session_id, api_key_id, user_id, mifmus_id NULL, event_timestamp TEXT, event_type TEXT, sequence_num, encrypted_content TEXT)`. Seed: vocab rows 1 (key 7, handle 111), 2 (key 7, handle 222), 3 (key 8, handle 111); sessions 10 (key 7) and 20 (key 8); events

  | event_id | session | key | mifmus_id | timestamp | type | seq | content |
  |---|---|---|---|---|---|---|---|
  | 1 | 10 | 7 | 1 | 2026-01-01 10:00:00 | agent_action | 1 | c1 |
  | 2 | 10 | 7 | NULL | 2026-01-01 10:05:00 | user_input | 2 | c2 |
  | 3 | 10 | 7 | 2 | 2026-01-02 09:00:00 | user_reaction | 3 | c3 |
  | 4 | 20 | 8 | 3 | 2026-01-01 10:01:00 | agent_action | 1 | c4 |

  Tests (run on SQLite unless marked pinned):
  - `testGetCountTaggedWithCountsOnlyThatState`: `(1)` is 1, `(99)` is 0.
  - `testNextSequenceNumIsPinned` (pinned): SQL `SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM interaction_events WHERE session_id = ? FOR UPDATE`, params `[10]`, `fetchColumn` returns `'4'`, result `4`.
  - `testAddEventStoresTheRowAndReturnsItsId`: `addEvent(10, 7, 1, null, 'user_input', 4, 'c5')` returns 5; row 5 has those values and `mifmus_id` null.
  - `testGetEventsAreTheKeysOwnOldestFirst`: `getEvents(7, null, null, null, null, null, 50)` gives event ids `[1, 2, 3]`, `my_id` values `[111, null, 222]`, and the first row's keys are exactly `event_id, session_id, sequence_num, event_timestamp, event_type, my_id, encrypted_content`.
  - `testGetEventsByHandle`: `my_id` 111 for key 7 gives `[1]`; `my_id` 111 for key 8 gives `[4]`; `my_id` 222 for key 8 gives `[]` (Review Focus 2).
  - `testGetEventsBySessionRangeAndType`: session 10 with `from` `2026-01-01 10:05:00` gives `[2, 3]`; adding `to` `2026-01-01 23:59:59` gives `[2]`; `event_type` `agent_action` for key 7 gives `[1]`.
  - `testGetEventsStopsAtTheLimit`: limit 2 for key 7 gives `[1, 2]`.
  - `testDeleteEventOnlyOfTheKeysOwn`: `deleteEvent(1, 8)` is 0 and 4 rows remain; `deleteEvent(1, 7)` is 1 and 3 remain (Review Focus 5).
  - `testUpdateEventChangesContentAndTag`: `updateEvent(2, 7, ['encrypted_content' => 'c2b', 'mifmus_id' => 2])` is true; row 2 has both.
  - `testUpdateEventUntagsWithNull`: `updateEvent(1, 7, ['mifmus_id' => null])` is true; row 1 `mifmus_id` is null and its content is still `c1` (Review Focus 3).
  - `testUpdateEventIsFalseForAnotherKeysEvent`: `updateEvent(4, 7, ['encrypted_content' => 'x'])` is false; row 4 content is still `c4` (Review Focus 5).
  - `testUpdateEventRefusesNoChangesAndUnknownColumns`: `[]` and `['user_id' => 9]` each throw `\InvalidArgumentException`; row 1 is unchanged (Review Focus 4).
  - `testGetCountAndDeleteAllAreOnlyTheKeysOwn`: `getCount(7)` is 3; after `deleteAll(7)`, `getCount(7)` is 0 and `getCount(8)` is 1.

- [ ] **Step 2: Run** `~/work/rob/standards-mcp/run-unit.sh ~/work/rob/mg.robnugen.com`. Expected: the new tests fail with class `Emotional\Events` not found.
- [ ] **Step 3: Write `classes/Emotional/Events.php`** to the interface above, with a class docblock in `Vocab`'s manner (which table, that `encrypted_content` is ciphertext and `Ledger` does the encrypting).
- [ ] **Step 4: Run** the Unit suite again. Expected: all pass.
- [ ] **Step 5: Gates and commit** `Add the emotion event queries to a gateway`.

### Task 2: `Emotional\Sessions` with its test

**Files:**
- Create: `classes/Emotional/Sessions.php`
- Create: `Tests/Unit/EmotionSessionQueriesTest.php`

**Interfaces (produces):**

```php
namespace Emotional;

class Sessions
{
    public function __construct(private readonly \PDO $pdo)

    /**
     * A key's sessions, newest first, each with its length and event count.
     * @return list<array<mixed>> session_id, start_time, last_event_time, duration_minutes, event_count
     */
    public function getSessions(int $api_key_id, ?string $from, ?string $to, int $limit): array

    public function getCount(int $api_key_id): int
    public function deleteAll(int $api_key_id): void
}
```

SQL sources: `getSessions` 411-437 (where and params built as the page does: key, then from, then to, limit last); `getCount` 472; `deleteAll` 481.

- [ ] **Step 1: Write the failing test.**
  - `testGetSessionsIsPinned` (pinned, `TIMESTAMPDIFF`): with `(7, null, null, 20)` the SQL is the page's `SELECT s.session_id, ... LIMIT ?` with `WHERE s.api_key_id = ?` and params `[7, 20]`; `fetchAll` returns one row and the method returns it.
  - `testGetSessionsWithARangeIsPinned` (pinned): `(7, '2026-01-01', '2026-01-31', 5)` has `WHERE s.api_key_id = ? AND s.start_time >= ? AND s.start_time <= ?` and params `[7, '2026-01-01', '2026-01-31', 5]`.
  - `testGetCountAndDeleteAllAreOnlyTheKeysOwn` (SQLite; sessions 10 and 11 for key 7, 20 for key 8): `getCount(7)` is 2; after `deleteAll(7)` it is 0 and `getCount(8)` is 1.
- [ ] **Step 2: Run** the Unit suite. Expected: fails, class `Emotional\Sessions` not found.
- [ ] **Step 3: Write `classes/Emotional/Sessions.php`.**
- [ ] **Step 4: Run** the Unit suite. Expected: all pass.
- [ ] **Step 5: Gates and commit** `Add the emotion session queries to a gateway`.

### Tasks 3 to 9: the page, one endpoint per commit

All modify `wwwroot/api/v1/_emotions.php` only, and consume the interfaces of Tasks 1 and 2. In each: replace the `prepare`/`execute`/fetch lines with the gateway call, leave every response, status code, log line and transaction boundary as it is, run the gates, and check that this line's count drops by the number shown:

    git grep -cE -e '->(prepare|query|exec)\s*\(' -- wwwroot/api/v1/_emotions.php

| Task | Endpoint | Change | Calls gone | Commit subject |
|---|---|---|---|---|
| 3 | vocab DELETE | `$events_untagged = new \Emotional\Events($pdo)->getCountTaggedWith($mifmus_id);` | 1 | `Vocab DELETE: count events via Events` |
| 4 | events POST | inside the existing transaction: `nextSequenceNum($session_id)`, then `addEvent(...)` in place of the insert and `lastInsertId()` | 2 | `Events POST: insert via Events` |
| 5 | events GET | drop the page's `$where`/`$join`/`$params`/`$sql`; call `getEvents($auth_key_id, my_id, session_id, from, to, event_type, $q_limit)` with `\Field::toInt` on a given `my_id` and `session_id` (as now) and `\Field::toString` on a given `from`, `to`, `event_type`; null stays null. The decrypt loop is unchanged. | 1 | `Events GET: query via Events` |
| 6 | events DELETE | `echo json_encode(['deleted' => $events->deleteEvent(\Field::toInt($event_id), $auth_key_id)]);` | 1 | `Events DELETE: delete via Events` |
| 7 | events PATCH | build `$changes` (`encrypted_content`, `mifmus_id`) where the page built `$sets`/`$params`; the "No fields to update" 400 stays in the page, tested on `$changes === []`; `updateEvent(...)` false gives the 404 | 1 | `Events PATCH: update via Events` |
| 8 | sessions GET | drop the page's `$where`/`$params`/`$sql`; call `new \Emotional\Sessions($pdo)->getSessions($auth_key_id, from, to, $q_limit)` with `\Field::toString` on a given `from` and `to` | 1 | `Sessions GET: query via Sessions` |
| 9 | delete everything | counts via `getCount`, deletes via `deleteAll`, in the same order (events, sessions, vocab), inside the existing transaction | 4 | `Delete everything: all via gateways` |

After Task 9 the count is 0 (grep prints nothing for the file).

### Task 10: Finish

- [ ] Run the step's check exactly as `next-step.sh` gave it (`tools/site-standards/checks/mlaphp_sql_in_gateways.sh`); expected: exit 1 with 98 query calls in 23 page scripts.
- [ ] Run `run-unit.sh`, `run-phpcs.sh`, `run-phpstan.sh`; note each exit code.
- [ ] Write the "calls I made" list for Rob: the two classes and their method names; the two pinned queries (`nextSequenceNum`, `getSessions`); the dead `array_unshift` line dropped; `from`/`to`/`event_type` now go through `\Field::toString`; `Ledger::getOrCreateSession()` left alone; no live check of the endpoints was made (this session's key may not call the emotion endpoints).
- [ ] Leave the bubble open and report. The session runs `next-step.sh --confirm --base=ff53d0bdd599dfc8a7b5904460b50dd2d82f53d3`.
