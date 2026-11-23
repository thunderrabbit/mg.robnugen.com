-- Which issues show a picture. An upload links the picture to its home issue;
-- more links can be added so one file shows on several issues, in any project
-- of the same account. A link is what lets someone see a picture: whoever can
-- read one linked issue can read the file. A picture with no links is seen by
-- nobody and stays on disk until purged.

CREATE TABLE issue_image_links (
    issue_image_link_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issue_image_id      INT UNSIGNED NOT NULL,
    issue_id            INT UNSIGNED NOT NULL,
    linked_by_aiu       INT UNSIGNED NOT NULL,
    linked_at_utc       DATETIME(6)  NOT NULL,
    UNIQUE KEY uniq_image_issue (issue_image_id, issue_id),
    INDEX idx_issue (issue_id),
    FOREIGN KEY (issue_image_id) REFERENCES issue_images(issue_image_id) ON DELETE CASCADE,
    FOREIGN KEY (issue_id)       REFERENCES issues(issue_id)             ON DELETE CASCADE,
    FOREIGN KEY (linked_by_aiu)  REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
