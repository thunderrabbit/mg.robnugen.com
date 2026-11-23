-- One row per picture file on the server's disk (mg issue images). The file
-- lives under Config::$image_store_path at
--   projects/<home_project_id>/<home_year>/issues/<home_issue_id>/<filename>
-- which is the issue it was first uploaded to, and it never moves.
-- home_* deliberately carry no foreign key: deleting an issue or a project
-- must not delete this row or the file. Which issues show a picture is in
-- issue_image_links.
-- uploaded_at_utc is one value for a whole batch, so a batch sorts together.

CREATE TABLE issue_images (
    issue_image_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    home_project_id  INT UNSIGNED NOT NULL,
    home_issue_id    INT UNSIGNED NOT NULL,
    home_year        SMALLINT UNSIGNED NOT NULL,
    filename         VARCHAR(100) NOT NULL,
    mime_type        VARCHAR(20)  NOT NULL,
    byte_size        INT UNSIGNED NOT NULL,
    width            INT UNSIGNED NOT NULL,
    height           INT UNSIGNED NOT NULL,
    sha256           CHAR(64)     NOT NULL,
    uploaded_by_aiu  INT UNSIGNED NOT NULL,
    uploaded_at_utc  DATETIME(6)  NOT NULL,
    UNIQUE KEY uniq_home_file (home_issue_id, home_year, filename),
    INDEX idx_uploaded (uploaded_at_utc),
    FOREIGN KEY (uploaded_by_aiu) REFERENCES agent_inbox_user(aiu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
