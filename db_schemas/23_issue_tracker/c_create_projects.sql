-- A project is the unit that groups issues and scopes agent access.
-- Single-account: project.user_id fixes the account; every project_members row
-- must point to an aiu whose user_id matches. API-layer enforced.
-- Creation/archive is gated to humans (actor_type='human') in the API.

CREATE TABLE projects (
    project_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    name           VARCHAR(100) NOT NULL,
    description    TEXT         NULL,
    is_archived    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at_utc DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uniq_user_project_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
