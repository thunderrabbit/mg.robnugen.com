<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Journal\Files;

/**
 * The journal mirror's bookkeeping, against SQLite in memory. The point of
 * most of these: an edit of Rob's is never lost or written over a change.
 */
class JournalFilesTest extends Unit
{
    private const string P = '2026/11/05dream-theater.md';
    private Files $files;
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE journal_files (jf_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, path TEXT,
             base_content TEXT, base_hash TEXT, git_state TEXT, uploaded_at_utc TEXT, edit_content TEXT,
             edit_path TEXT, edited_at_utc TEXT, status TEXT DEFAULT \'synced\', status_note TEXT,
             UNIQUE (user_id, path))'
        );
        $this->pdo->exec(
            'CREATE TABLE journal_sync_runs (jsr_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER UNIQUE,
             synced_at_utc TEXT)'
        );
        $this->files = new Files($this->pdo);
    }

    /** @return array<string, mixed> */
    private function row(string $path = self::P): array
    {
        $row = $this->files->findByPath(1, $path);
        $this->assertNotNull($row);
        return $row;
    }

    private function idOf(string $path = self::P): int
    {
        return \Field::int($this->row($path), 'jf_id');
    }

    public function testUploadCreatesThenUpdates(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->upload(1, self::P, 'two', 'modified', 't2');
        $row = $this->row();
        $this->assertSame('two', $row['base_content']);
        $this->assertSame(hash('sha256', 'two'), $row['base_hash']);
        $this->assertSame('modified', $row['git_state']);
        $this->assertCount(1, $this->files->all(1));
    }

    public function testUploadNeverMovesTheBaseUnderAWaitingEdit(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'mine', self::P, 'e1');
        $this->files->upload(1, self::P, 'carrie appended', 'modified', 't2');
        $row = $this->row();
        $this->assertSame(hash('sha256', 'one'), $row['base_hash']);
        $this->assertSame('mine', $row['edit_content']);
        $this->assertSame('modified', $row['git_state']);
        $this->assertSame(Files::STATUS_WAITING, $row['status']);
    }

    public function testPendingListsWaitingEdits(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->upload(1, '2026/11/05b.md', 'x', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'mine', self::P, 'e1');
        $pending = $this->files->pending(1);
        $this->assertCount(1, $pending);
        $this->assertSame('mine', $pending[0]['edit_content']);
        $this->assertSame([], $this->files->pending(2));
    }

    public function testAppliedEditBecomesTheBase(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'mine', self::P, 'e1');
        $this->assertTrue($this->files->result(1, self::P, 'applied', 'e1', '', self::P, 'mine', 't2'));
        $row = $this->row();
        $this->assertSame('mine', $row['base_content']);
        $this->assertNull($row['edit_content']);
        $this->assertSame(Files::STATUS_SYNCED, $row['status']);
    }

    public function testAppliedRenameMovesThePath(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'mine', '2026/11/04dream-stage.md', 'e1');
        $this->files->result(1, self::P, 'applied', 'e1', '', '2026/11/04dream-stage.md', 'mine', 't2');
        $this->assertNull($this->files->findByPath(1, self::P));
        $this->assertSame('mine', $this->row('2026/11/04dream-stage.md')['base_content']);
    }

    public function testANewerSaveSurvivesTheOlderOneBeingApplied(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'first', self::P, 'e1');
        $this->files->saveEdit(1, $this->idOf(), 'second', self::P, 'e2');
        $this->files->result(1, self::P, 'applied', 'e1', '', self::P, 'first', 't2');
        $row = $this->row();
        $this->assertSame('first', $row['base_content']);
        $this->assertSame('second', $row['edit_content']);
        $this->assertSame(Files::STATUS_WAITING, $row['status']);
    }

    public function testConflictKeepsTheEdit(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'mine', self::P, 'e1');
        $this->files->result(1, self::P, 'conflict', 'e1', 'saved beside it', null, null, 't2');
        $row = $this->row();
        $this->assertSame(Files::STATUS_CONFLICT, $row['status']);
        $this->assertSame('mine', $row['edit_content']);
        $this->assertSame('saved beside it', $row['status_note']);
    }

    public function testResolvedConflictClearsTheEdit(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'mine', self::P, 'e1');
        $this->files->result(1, self::P, 'conflict', 'e1', 'x', null, null, 't2');
        $this->files->result(1, self::P, 'resolved', 'e1', '', null, null, 't3');
        $this->assertNull($this->row()['edit_content']);
        $this->assertSame(Files::STATUS_SYNCED, $this->row()['status']);
    }

    public function testRefusedLeavesNoteAndEditButIsNotPending(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'mine', self::P, 'e1');
        $this->files->result(1, self::P, 'refused', 'e1', 'bad date', null, null, 't2');
        $this->assertSame(Files::STATUS_REFUSED, $this->row()['status']);
        $this->assertSame([], $this->files->pending(1));
        $this->files->saveEdit(1, $this->idOf(), 'fixed', self::P, 'e2');
        $this->assertCount(1, $this->files->pending(1));
        $this->assertNull($this->row()['status_note']);
    }

    public function testRemoveDeletesOnlyWithoutAWaitingEdit(): void
    {
        $this->files->upload(1, self::P, 'one', 'committed', 't1');
        $this->files->upload(1, '2026/11/05b.md', 'x', 'committed', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'mine', self::P, 'e1');
        $this->files->remove(1, self::P, false);
        $this->files->remove(1, '2026/11/05b.md', false);
        $this->assertSame('mine', $this->row()['edit_content']);
        $this->assertNull($this->files->findByPath(1, '2026/11/05b.md'));
    }

    public function testDeletedOnLemurWithAnEditIsFlagged(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf(), 'mine', self::P, 'e1');
        $this->files->remove(1, self::P, true);
        $this->assertSame(Files::STATUS_CONFLICT, $this->row()['status']);
    }

    public function testPathTakenSeesPathsAndWaitingRenames(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->files->upload(1, '2026/11/05b.md', 'x', 'untracked', 't1');
        $this->files->saveEdit(1, $this->idOf('2026/11/05b.md'), 'x', '2026/11/05c.md', 'e1');
        $id = $this->idOf();
        $this->assertTrue($this->files->pathTaken(1, '2026/11/05b.md', $id));
        $this->assertTrue($this->files->pathTaken(1, '2026/11/05c.md', $id));
        $this->assertFalse($this->files->pathTaken(1, self::P, $id));
        $this->assertFalse($this->files->pathTaken(2, '2026/11/05b.md', $id));
    }

    public function testFilesBelongToTheirUser(): void
    {
        $this->files->upload(1, self::P, 'one', 'untracked', 't1');
        $this->assertNull($this->files->find(2, $this->idOf()));
        $this->files->saveEdit(2, $this->idOf(), 'not mine', self::P, 'e1');
        $this->assertNull($this->row()['edit_content']);
    }

    public function testSyncTimeIsRemembered(): void
    {
        $this->assertNull($this->files->lastSynced(1));
        $this->files->markSynced(1, 't1');
        $this->files->markSynced(1, 't2');
        $this->assertSame('t2', $this->files->lastSynced(1));
    }
}
