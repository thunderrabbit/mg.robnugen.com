-- Remove duplicate (todo_id, ak_id) rows, keeping the earliest completion.
-- This retains the row with the lowest log_id for each duplicate pair.
DELETE tl FROM todo_logs tl
INNER JOIN (
    SELECT todo_id, ak_id, MIN(log_id) AS keep_id
    FROM todo_logs
    WHERE ak_id IS NOT NULL
    GROUP BY todo_id, ak_id
    HAVING COUNT(*) > 1
) dups ON tl.todo_id = dups.todo_id AND tl.ak_id = dups.ak_id AND tl.log_id != dups.keep_id;

-- Prevent duplicate todo completions for the same activity session.
-- MySQL allows multiple NULLs in a unique index, so completions
-- without a session (ak_id IS NULL) are unaffected.
ALTER TABLE todo_logs
    ADD UNIQUE INDEX uq_todo_ak (todo_id, ak_id);
