# The rest of the page SQL into gateway classes

Date: 2026-11-24. Step: `sql_into_gateways` (MLAPHP chapter 7).

## Intent

The check counts 109 query calls in 24 page scripts under `wwwroot/`. Three
groups are already moved (Exterminal pages, timer lists, emotion vocab). This
is how the rest gets moved without taking much of Rob's time: the agent makes
the naming and shape calls in the style those three groups set, and writes
them down for Rob to read instead of asking.

## Goals

- Every query call in a page script moves into a gateway class, until the
  check passes.
- Behaviour does not change: same SQL, same parameters, same responses.
- Rob's part per bubble is reading a short list of calls and deploying.

## Not goals

- Removing or redesigning the features Rob no longer uses (emotions, timers,
  Decision Matrix). They are moved as they are.
- Moving SQL that already sits in a class (`Emotional\Ledger::getOrCreateSession()`
  and the like). The check does not count it.
- Fixing anything a move happens to reveal. It is noted in the calls list and
  left for Rob, except dead lines that never ran, which are dropped and named
  in the commit.

## Would make it a failure

- A moved query that differs from the original by a character.
- A query moved with no test.
- Rob being asked for names and shapes one group at a time.

## Approaches considered

- **A. One group per bubble, deploy between each.** Safest, and about seven
  deploys.
- **B. Every group stacked, one deploy at the end.** Least of Rob's time, and
  a slip in an early group sits under all the later ones. Goes against the
  step's "deploy before the next group".
- **C. Split by whether Rob uses the feature.** Chosen by Rob, 2026-11-24.
  The features he no longer uses go stacked on the agent's calls; the ones
  every agent session runs through go one at a time with a deploy between.

## The groups, in order

Stacked, no deploy needed between them (unused features):

1. **Emotion events and sessions**: the 11 calls left in
   `wwwroot/api/v1/_emotions.php`. `Emotional\Events` (`interaction_events`)
   and `Emotional\Sessions` (`interaction_sessions`).
2. **Decision Matrix**: 17 calls in the five `wwwroot/matrix/` scripts, over
   `dm_user_config`, `dm_my_problems`, `dm_my_opposites`, `dm_my_evidence`.

One at a time, Rob deploys before the next starts (features in use):

3. **Actors**: `agent_inbox_user` in `api/v1/_agents.php` and the single
   actor lookup that `api/v1/index.php`, `mcp/index.php`, `mcp/token.php` and
   `authorize/index.php` each repeat.
4. **Inbox**: `api/v1/_inbox.php` and `inbox/index.php` (`agent_inbox`,
   `inbox_visibility`).
5. **OAuth and tokens**: what is left in `mcp/token.php`, `authorize/index.php`
   and `mcp/index.php`.
6. **Repositories**: `api/v1/_repositories.php` and `repositories/*`.
7. **The rest**: settings, profile, billing, register, `_stats`, `_sessions`,
   `_todos`. Split in two if it comes to more than one bubble reads well.

The agent lists a group's calls again when it starts that group; the counts
above are from 2026-11-24.

## How each group is done

- **One bubble per group**, branched from the previous group's closed tip.
  BEGIN is the version bump alone. Then one commit adding the gateways with
  their test, then one commit per endpoint or page. A group that comes to one
  or two commits gets no bubble.
- **A gateway is one class per table**, in the namespace of its feature, an
  object with `\PDO` passed to the constructor. Method names follow
  `Emotional\Vocab` and `Exterm\Items` (`addEntry`, `getEntries`, `getCount`,
  `deleteAll`, `...Of`). Rows come back through `\Database\Rows`.
- **The SQL is copied exactly.** A query the page built at run time (a
  `WHERE` or `SET` assembled from the request) moves into the gateway whole;
  the page passes typed values, and the gateway refuses a column it does not
  know.
- **Transactions stay in the page.** A gateway method that must run inside
  one says so in its docblock.
- **Tests come in the gateway commit, before any page uses it.** Each query
  is run on in-memory SQLite, as `EmotionVocabQueriesTest` does. A query
  SQLite cannot run (`FOR UPDATE`, `TIMESTAMPDIFF`, `NOW()` arithmetic) gets
  a test that pins its exact SQL text and parameters through a recording PDO.
  The test says which kind it is.
- **Gates before each commit**: Rector dry run, then the Unit suite, phpcs and
  PHPStan; the pre-commit hook has the last word.
- **Each bubble ends with a "calls I made" list** for Rob: the classes and
  method names chosen, any query pinned instead of run, and anything noticed
  and left alone.
- **Nothing is pushed.** The agent closes a bubble only after
  `next-step.sh --confirm` calls it clean.

## Left to the builder

Method names and return shapes within the style above; how a group's commits
are split; whether a helper for the recording PDO is shared between tests.
