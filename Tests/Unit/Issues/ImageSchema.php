<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

/**
 * The tables the image store touches, in SQLite, with only the columns its
 * queries read. Two accounts: user 1 owns projects 7 and 8, user 2 owns 9.
 *
 * Not named *Test, so Codeception treats it as support rather than a suite.
 */
final class ImageSchema
{
    public static function create(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE projects (project_id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT)');
        $pdo->exec('CREATE TABLE issues (issue_id INTEGER PRIMARY KEY, project_id INTEGER)');
        $pdo->exec(
            'CREATE TABLE agent_inbox_user (aiu_id INTEGER PRIMARY KEY, user_id INTEGER, actor_type TEXT)'
        );
        $pdo->exec(
            'CREATE TABLE issue_images (issue_image_id INTEGER PRIMARY KEY AUTOINCREMENT,
             home_project_id INTEGER, home_issue_id INTEGER, home_year INTEGER, filename TEXT,
             mime_type TEXT, byte_size INTEGER, width INTEGER, height INTEGER, sha256 TEXT,
             uploaded_by_aiu INTEGER, uploaded_at_utc TEXT,
             UNIQUE (home_issue_id, home_year, filename))'
        );
        $pdo->exec(
            'CREATE TABLE issue_image_links (issue_image_link_id INTEGER PRIMARY KEY AUTOINCREMENT,
             issue_image_id INTEGER, issue_id INTEGER, linked_by_aiu INTEGER, linked_at_utc TEXT,
             UNIQUE (issue_image_id, issue_id))'
        );
        $pdo->exec("INSERT INTO projects VALUES (7, 1, 'jikan'), (8, 1, 'other one'), (9, 2, 'theirs')");
        $pdo->exec('INSERT INTO issues VALUES (12, 7), (13, 7), (20, 8), (30, 9)');
        $pdo->exec(
            "INSERT INTO agent_inbox_user VALUES (1, 1, 'human'), (35, 1, 'agent'), (36, 1, 'agent'),
             (37, 1, 'agent'), (2, 2, 'human')"
        );
        return $pdo;
    }
}
