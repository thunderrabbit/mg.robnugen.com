-- Add todo_id to activity_kai table
ALTER TABLE activity_kai
ADD COLUMN todo_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER activity_id;

-- Add foreign key constraint
ALTER TABLE activity_kai
ADD CONSTRAINT fk_activity_kai_todo
FOREIGN KEY (todo_id) REFERENCES todos(todo_id)
ON DELETE SET NULL;
