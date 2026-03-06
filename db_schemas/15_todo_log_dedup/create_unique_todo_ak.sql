-- Prevent duplicate todo completions for the same activity session.
-- MySQL allows multiple NULLs in a unique index, so completions
-- without a session (ak_id IS NULL) are unaffected.
ALTER TABLE todo_logs
    ADD UNIQUE INDEX uq_todo_ak (todo_id, ak_id);
