-- Site standards: which written-up steps each site has done (see
-- docs/superpowers/specs/2026-11-06-dh-standards-design.md).
--
-- These three tables are a copy of the step files under
-- tools/site-standards/steps/ on master. The files are the truth; an import
-- matches rows by slug and updates them in place, so ids stay stable. A step
-- removed from the files is retired (retired_on), never deleted, so its
-- history stays. Nothing here references a sweep id.
--
-- A step's slug must be unique within its standard, not just its track: an
-- issue title names the step without its track. The importer enforces that.

CREATE TABLE std_standards (
    std_standard_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(32) NOT NULL,
    name            VARCHAR(64) NOT NULL,
    UNIQUE KEY uq_standard_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE std_tracks (
    std_track_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_standard_id INT UNSIGNED NOT NULL,
    slug            VARCHAR(32) NOT NULL,
    name            VARCHAR(64) NOT NULL,
    position        SMALLINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_track (std_standard_id, slug),
    FOREIGN KEY (std_standard_id) REFERENCES std_standards(std_standard_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE std_steps (
    std_step_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_track_id INT UNSIGNED NOT NULL,
    slug         VARCHAR(64) NOT NULL,
    position     SMALLINT UNSIGNED NOT NULL,
    title        VARCHAR(160) NOT NULL,
    explanation  TEXT NOT NULL,
    how_to       MEDIUMTEXT NOT NULL,
    status       ENUM('idea','proven') NOT NULL,
    whose_move   ENUM('rob','agent') NOT NULL,
    check_name   VARCHAR(64) NOT NULL,
    check_param  VARCHAR(32) NULL,
    retired_on   DATE NULL,
    UNIQUE KEY uq_step (std_track_id, slug),
    FOREIGN KEY (std_track_id) REFERENCES std_tracks(std_track_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
