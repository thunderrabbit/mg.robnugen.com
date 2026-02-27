CREATE TABLE omg_rob_this_happened (
    omg_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    context         VARCHAR(255) NOT NULL  COMMENT 'e.g. emotional/vocab, billing/webhook',
    message         TEXT NOT NULL          COMMENT 'plain language description of the failure',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at DATETIME NULL          COMMENT 'NULL = unread; set when admin dismisses',

    PRIMARY KEY (omg_id),
    KEY idx_unread (acknowledged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO omg_rob_this_happened (context, message)
VALUES ('system/setup', 'We created a system to alert you to important messages!');
