-- Create the agent_inbox_user table
CREATE TABLE IF NOT EXISTS agent_inbox_user (
    aiu_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    name          VARCHAR(100) NOT NULL,
    description   VARCHAR(255) NULL,
    actor_type    ENUM('human', 'agent') NOT NULL DEFAULT 'agent',
    color         CHAR(7) NULL DEFAULT NULL COMMENT 'hex color e.g. #FF6B35 for UI badges',
    can_read_inbox      TINYINT(1) NOT NULL DEFAULT 0,
    can_write_inbox     TINYINT(1) NOT NULL DEFAULT 0,
    can_read_todos      TINYINT(1) NOT NULL DEFAULT 0,
    can_write_todos     TINYINT(1) NOT NULL DEFAULT 0,
    can_read_sessions   TINYINT(1) NOT NULL DEFAULT 0,
    can_write_sessions  TINYINT(1) NOT NULL DEFAULT 0,
    can_read_emotions   TINYINT(1) NOT NULL DEFAULT 0,
    can_write_emotions  TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_user_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create the inbox_visibility table
CREATE TABLE IF NOT EXISTS inbox_visibility (
    inbox_user_aiu_id  INT UNSIGNED NOT NULL,
    inbox_peer_aiu_id  INT UNSIGNED NULL COMMENT 'NULL = all actors (supervisor)',
    can_read           TINYINT(1) NOT NULL DEFAULT 0,
    can_send           TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_user_peer (inbox_user_aiu_id, inbox_peer_aiu_id),
    FOREIGN KEY (inbox_user_aiu_id) REFERENCES agent_inbox_user(aiu_id) ON DELETE CASCADE,
    FOREIGN KEY (inbox_peer_aiu_id) REFERENCES agent_inbox_user(aiu_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed a default human actor for each existing user (all permissions granted)
INSERT INTO agent_inbox_user (user_id, name, actor_type,
    can_read_inbox, can_write_inbox, can_read_todos, can_write_todos,
    can_read_sessions, can_write_sessions, can_read_emotions, can_write_emotions)
SELECT user_id, username, 'human', 1, 1, 1, 1, 1, 1, 1, 1
FROM users
WHERE user_id IN (SELECT DISTINCT user_id FROM api_keys)
ON DUPLICATE KEY UPDATE name = name;

-- Give all human actors supervisor visibility (NULL = read all, send to all)
INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send)
SELECT aiu_id, NULL, 1, 1
FROM agent_inbox_user
WHERE actor_type = 'human';

-- Add aiu_id to api_keys (nullable first so we can backfill)
ALTER TABLE api_keys
    ADD COLUMN aiu_id INT UNSIGNED NULL AFTER user_id;

-- Point all existing keys to their user's default human actor
UPDATE api_keys k
JOIN agent_inbox_user a ON a.user_id = k.user_id AND a.actor_type = 'human'
SET k.aiu_id = a.aiu_id;

-- Now make it NOT NULL and add the foreign key
ALTER TABLE api_keys
    MODIFY COLUMN aiu_id INT UNSIGNED NOT NULL,
    ADD FOREIGN KEY (aiu_id) REFERENCES agent_inbox_user(aiu_id);

-- Add sender and recipient columns to agent_inbox
ALTER TABLE agent_inbox
    ADD COLUMN sender_aiu    INT UNSIGNED NULL AFTER user_id,
    ADD COLUMN recipient_aiu INT UNSIGNED NULL AFTER sender_aiu,
    ADD FOREIGN KEY (sender_aiu) REFERENCES agent_inbox_user(aiu_id),
    ADD FOREIGN KEY (recipient_aiu) REFERENCES agent_inbox_user(aiu_id);
