-- A site is one codebase. These rows are kept by hand (tools/site_standards.php).
-- std_sites says nothing about hosting: where a site is served is said only by
-- a link table, dh_site_domains for DreamHost, so a site on another host needs
-- no change here. A domain carrying two kinds of code is two sites, told apart
-- by path_prefix. Nothing here references a sweep id.
--
-- project_id is the mg project holding the site's notes: one issue per step,
-- titled <step slug>.<shortcode>. No table links a note to its step; the title
-- does.

CREATE TABLE std_sites (
    std_site_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_standard_id INT UNSIGNED NOT NULL,
    shortcode       VARCHAR(24) NOT NULL,
    project_id      INT UNSIGNED NULL,
    checkout_path   VARCHAR(255) NULL,
    trunk_branch    VARCHAR(100) NOT NULL DEFAULT 'master',
    github_repo     VARCHAR(255) NULL,
    autonomy        ENUM('paired','reviewed','solo') NOT NULL DEFAULT 'paired',
    UNIQUE KEY uq_site_shortcode (shortcode),
    FOREIGN KEY (std_standard_id) REFERENCES std_standards(std_standard_id),
    FOREIGN KEY (project_id) REFERENCES projects(project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE dh_site_domains (
    dh_site_domain_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    std_site_id       INT UNSIGNED NOT NULL,
    dh_domain_id      INT UNSIGNED NOT NULL,
    path_prefix       VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_domain_path (dh_domain_id, path_prefix),
    FOREIGN KEY (std_site_id)  REFERENCES std_sites(std_site_id),
    FOREIGN KEY (dh_domain_id) REFERENCES dh_domains(dh_domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
