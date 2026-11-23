<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * The queries Exterm\Items runs for the pages under wwwroot/exterm/, over an
 * in-memory SQLite (no server, gone with the process), as
 * AdminNotificationsTest does.
 *
 * Account 1 owns projects 10 (Alpha), 11 (Beta, archived) and 12 (Gamma).
 * Its human actor is 100 and its agent is 101. Account 2 owns project 20 and
 * has human actor 200.
 */
class ExtermItemsPagesTest extends Unit
{
    private \PDO $pdo;

    private \Exterm\Items $items;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE agent_inbox_user (
                aiu_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                actor_type TEXT NOT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE projects (
                project_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                is_archived INTEGER NOT NULL DEFAULT 0
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE project_members (
                project_id INTEGER NOT NULL,
                member_aiu INTEGER NOT NULL,
                can_read INTEGER NOT NULL,
                can_write INTEGER NOT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE exterm_items (
                exterm_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                author_aiu INTEGER NOT NULL,
                title TEXT NOT NULL,
                body TEXT,
                created_at_utc TEXT NOT NULL DEFAULT '2026-11-01 00:00:00',
                updated_at_utc TEXT NOT NULL DEFAULT '2026-11-01 00:00:00'
            )"
        );
        $this->pdo->exec(
            "INSERT INTO agent_inbox_user (aiu_id, user_id, name, actor_type) VALUES
                (101, 1, 'Agent One', 'agent'),
                (100, 1, 'Human One', 'human'),
                (200, 2, 'Human Two', 'human'),
                (300, 3, 'Agent Three', 'agent')"
        );
        $this->pdo->exec(
            "INSERT INTO projects (project_id, user_id, name, is_archived) VALUES
                (12, 1, 'Gamma', 0),
                (10, 1, 'Alpha', 0),
                (11, 1, 'Beta', 1),
                (20, 2, 'Other', 0)"
        );
        $this->pdo->exec(
            "INSERT INTO project_members (project_id, member_aiu, can_read, can_write) VALUES
                (10, 100, 1, 1),
                (12, 100, 1, 0),
                (20, 200, 1, 1)"
        );
        $this->items = new \Exterm\Items($this->pdo);
    }

    private function note(int $project_id, int $author_aiu, string $title, string $updated_at_utc): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO exterm_items (project_id, author_aiu, title, body, updated_at_utc)
             VALUES (?, ?, ?, 'body', ?)"
        );
        $stmt->execute([$project_id, $author_aiu, $title, $updated_at_utc]);
        return (int) $this->pdo->lastInsertId();
    }

    /** One value, as a string: SQLite hands every column back as one here. */
    private function scalar(string $sql): string
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return is_scalar($value) ? (string) $value : '';
    }

    public function testHumanAiuIsTheAccountsHumanActor(): void
    {
        $this->assertSame(100, $this->items->humanAiu(1));
        $this->assertSame(200, $this->items->humanAiu(2));
    }

    public function testHumanAiuIsZeroWithoutAHumanActor(): void
    {
        $this->assertSame(0, $this->items->humanAiu(3));
        $this->assertSame(0, $this->items->humanAiu(99));
    }

    public function testProjectForWritingGivesCanWriteAndName(): void
    {
        $row = $this->items->projectForWriting(10, 1, 100);
        $this->assertNotNull($row);
        $this->assertSame(1, \Field::int($row, 'can_write'));
        $this->assertSame('Alpha', \Field::string($row, 'name'));

        $read_only = $this->items->projectForWriting(12, 1, 100);
        $this->assertNotNull($read_only);
        $this->assertSame(0, \Field::int($read_only, 'can_write'));
    }

    public function testProjectForWritingIsNullForANonMemberOrAnotherAccount(): void
    {
        $this->assertNull($this->items->projectForWriting(10, 1, 101));
        $this->assertNull($this->items->projectForWriting(20, 1, 200));
        $this->assertNull($this->items->projectForWriting(99, 1, 100));
    }

    public function testInsertItemStoresTheNoteAndReturnsItsId(): void
    {
        $first  = $this->items->insertItem(10, 100, 'First', 'words');
        $second = $this->items->insertItem(10, 100, 'Second', null);

        $this->assertGreaterThan($first, $second);
        $this->assertSame(
            '10|100|First|words',
            $this->scalar(
                "SELECT project_id || '|' || author_aiu || '|' || title || '|' || body
                 FROM exterm_items WHERE exterm_item_id = {$first}"
            )
        );
        $this->assertSame(
            '1',
            $this->scalar("SELECT body IS NULL FROM exterm_items WHERE exterm_item_id = {$second}")
        );
    }

    public function testItemForEditGivesTheNoteItsProjectNameAndCanWrite(): void
    {
        $id = $this->note(10, 101, 'Idea', '2026-11-02 00:00:00');

        $item = $this->items->itemForEdit($id, 1, 100);
        $this->assertNotNull($item);
        $this->assertSame($id, \Field::int($item, 'exterm_item_id'));
        $this->assertSame('Idea', \Field::string($item, 'title'));
        $this->assertSame('body', \Field::string($item, 'body'));
        $this->assertSame('Alpha', \Field::string($item, 'project_name'));
        $this->assertSame(1, \Field::int($item, 'can_write'));
    }

    public function testItemForEditIsNullForANonMemberOrAnotherAccount(): void
    {
        $mine   = $this->note(10, 100, 'Mine', '2026-11-02 00:00:00');
        $theirs = $this->note(20, 200, 'Theirs', '2026-11-02 00:00:00');

        $this->assertNull($this->items->itemForEdit($mine, 1, 101));
        $this->assertNull($this->items->itemForEdit($theirs, 1, 200));
        $this->assertNull($this->items->itemForEdit(999, 1, 100));
    }

    public function testSaveTitleAndBodyChangesOnlyThatNote(): void
    {
        $changed = $this->note(10, 100, 'Old', '2026-11-02 00:00:00');
        $kept    = $this->note(10, 100, 'Kept', '2026-11-02 00:00:00');

        $this->items->saveTitleAndBody($changed, 'New', null);

        $this->assertSame(
            'New|1',
            $this->scalar(
                "SELECT title || '|' || (body IS NULL) FROM exterm_items WHERE exterm_item_id = {$changed}"
            )
        );
        $this->assertSame(
            'Kept|body',
            $this->scalar("SELECT title || '|' || body FROM exterm_items WHERE exterm_item_id = {$kept}")
        );
    }

    public function testOwnedProjectsAreTheAccountsUnarchivedOnesByName(): void
    {
        $projects = $this->items->ownedProjects(1);

        $this->assertSame(['Alpha', 'Gamma'], array_map(
            static fn (array $row): string => \Field::string($row, 'name'),
            $projects
        ));
        $this->assertSame([10, 12], array_map(
            static fn (array $row): int => \Field::int($row, 'project_id'),
            $projects
        ));
        $this->assertSame([], $this->items->ownedProjects(99));
    }

    public function testOwnedProjectNameIsEmptyForAnotherAccountsProject(): void
    {
        $this->assertSame('Alpha', $this->items->ownedProjectName(10, 1));
        $this->assertSame('Beta', $this->items->ownedProjectName(11, 1));
        $this->assertSame('', $this->items->ownedProjectName(20, 1));
        $this->assertSame('', $this->items->ownedProjectName(99, 1));
    }

    public function testProjectItemsAreThatProjectsNotesNewestFirst(): void
    {
        $older = $this->note(10, 100, 'Older', '2026-11-02 00:00:00');
        $newer = $this->note(10, 101, 'Newer', '2026-11-03 00:00:00');
        $this->note(12, 100, 'Elsewhere', '2026-11-04 00:00:00');

        $rows = $this->items->projectItems(10);

        $this->assertSame([$newer, $older], array_map(
            static fn (array $row): int => \Field::int($row, 'exterm_item_id'),
            $rows
        ));
        $this->assertSame('Newer', \Field::string($rows[0], 'title'));
        $this->assertSame(101, \Field::int($rows[0], 'author_aiu'));
        $this->assertSame('Agent One', \Field::string($rows[0], 'author_name'));
        $this->assertSame('2026-11-03 00:00:00', \Field::string($rows[0], 'updated_at_utc'));
        $this->assertSame([], $this->items->projectItems(11));
    }

    public function testProjectItemsStopAtOneHundred(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->note(10, 100, "Note {$i}", '2026-11-02 00:00:00');
        }

        $this->assertCount(100, $this->items->projectItems(10));
    }

    public function testItemForViewGivesTheNoteWithProjectAuthorAndCanWrite(): void
    {
        $id = $this->note(12, 101, 'Idea', '2026-11-02 00:00:00');

        $item = $this->items->itemForView($id, 1, 100);
        $this->assertNotNull($item);
        $this->assertSame($id, \Field::int($item, 'exterm_item_id'));
        $this->assertSame('Idea', \Field::string($item, 'title'));
        $this->assertSame(12, \Field::int($item, 'project_id'));
        $this->assertSame('Gamma', \Field::string($item, 'project_name'));
        $this->assertSame('Agent One', \Field::string($item, 'author_name'));
        $this->assertSame(0, \Field::int($item, 'can_write'));
    }

    public function testItemForViewIsNullForANonMemberOrAnotherAccount(): void
    {
        $mine   = $this->note(10, 100, 'Mine', '2026-11-02 00:00:00');
        $theirs = $this->note(20, 200, 'Theirs', '2026-11-02 00:00:00');

        $this->assertNull($this->items->itemForView($mine, 1, 101));
        $this->assertNull($this->items->itemForView($theirs, 1, 200));
        $this->assertNull($this->items->itemForView(999, 1, 100));
    }

    public function testRemoveItemDeletesOnlyThatNote(): void
    {
        $gone = $this->note(10, 100, 'Gone', '2026-11-02 00:00:00');
        $kept = $this->note(10, 100, 'Kept', '2026-11-02 00:00:00');

        $this->items->removeItem($gone);

        $this->assertSame((string) $kept, $this->scalar("SELECT GROUP_CONCAT(exterm_item_id) FROM exterm_items"));
    }
}
