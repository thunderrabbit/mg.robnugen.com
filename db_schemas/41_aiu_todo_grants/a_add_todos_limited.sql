-- Let an actor be limited to the todos it has been granted.
-- 0 = the actor sees every todo its account owns (all existing actors).
-- 1 = the actor may only touch todos listed in aiu_todo_grants.
-- A flag rather than "no grant rows = all todos": a limited actor that has
-- just been created, or has had every grant removed, must see nothing, not
-- everything.

ALTER TABLE agent_inbox_user
    ADD COLUMN todos_limited TINYINT(1) NOT NULL DEFAULT 0 AFTER can_write_todos;
