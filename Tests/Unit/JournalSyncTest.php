<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Journal\Sync;

/**
 * Lemur 13's side of the sync, against a temp folder and a fake mg. The
 * checks that matter: an edit is never written over a changed file, and
 * every outcome is reported back.
 */
class JournalSyncTest extends Unit
{
    private const P = '2026/11/05dream-theater.md';

    private string $dir;

    /** @var list<array{0: string, 1: string, 2: array<mixed>|null}> */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $edits = [];

    /** @var list<array<string, mixed>> */
    private array $held = [];

    /** @var array<string, string> */
    private array $status = [];

    /** @var list<string> */
    private array $tracked = [];

    protected function _before(): void
    {
        $this->dir = sys_get_temp_dir() . '/journal-sync-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/2026/11', 0777, true);
    }

    protected function _after(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function entry(string $date = '2026-11-05', string $body = 'Words.'): string
    {
        return "---\ntitle: \"Dream: Theater\"\ntags: [ \"2026\" ]\ndate: {$date}T05:44:00+11:00\n---\n\n{$body}\n";
    }

    private function put(string $path, string $content): void
    {
        @mkdir(dirname($this->dir . '/' . $path), 0777, true);
        file_put_contents($this->dir . '/' . $path, $content);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->dir . '/' . $path);
    }

    /** @param array<string, mixed> $over */
    private function edit(string $content, array $over = []): void
    {
        $this->edits[] = array_merge([
            'path' => self::P,
            'edit_path' => self::P,
            'content' => base64_encode($content),
            'edited_at' => 'e1',
            'base_hash' => hash('sha256', $this->entry()),
            'git_state' => 'untracked',
            'status' => 'edit_waiting',
        ], $over);
    }

    /** @return list<string> */
    private function sync(string $today = '2026-11-05'): array
    {
        $api = function (string $method, string $route, ?array $body): array {
            $this->calls[] = [$method, $route, $body];
            if ($route === '/journal-sync/edits') {
                return ['edits' => $this->edits];
            }
            if ($route === '/journal-sync/files' && $method === 'GET') {
                return ['files' => $this->held];
            }
            return ['ok' => true];
        };
        $sync = new Sync(
            $this->dir,
            \Closure::fromCallable($api),
            fn(): array => $this->status,
            fn(string $p): bool => in_array($p, $this->tracked, true),
            $today,
        );
        return $sync->run();
    }

    /** @return list<array<mixed>|null> */
    private function bodies(string $route): array
    {
        $out = [];
        foreach ($this->calls as [$method, $r, $body]) {
            if ($r === $route && $method === 'POST') {
                $out[] = $body;
            }
        }
        return $out;
    }

    /** @return array<mixed> */
    private function onlyResult(): array
    {
        $results = $this->bodies('/journal-sync/results');
        $this->assertCount(1, $results);
        $this->assertIsArray($results[0]);
        return $results[0];
    }

    public function testEditIsWrittenWhenTheFileIsUnchanged(): void
    {
        $this->put(self::P, $this->entry());
        $this->edit($this->entry('2026-11-05', 'Better words.'));
        $this->sync();
        $this->assertStringContainsString('Better words.', $this->read(self::P));
        $result = $this->onlyResult();
        $this->assertSame('applied', $result['outcome']);
        $this->assertSame(self::P, $result['applied_path']);
        $this->assertFileDoesNotExist($this->dir . '/' . self::P . '.from-mg');
    }

    public function testChangedFileIsKeptAndTheEditSavedBeside(): void
    {
        $this->put(self::P, $this->entry('2026-11-05', 'Carrie appended this.'));
        $this->edit($this->entry('2026-11-05', 'Better words.'));
        $this->sync();
        $this->assertStringContainsString('Carrie appended this.', $this->read(self::P));
        $this->assertStringContainsString('Better words.', $this->read(self::P . '.from-mg'));
        $this->assertSame('conflict', $this->onlyResult()['outcome']);
    }

    public function testDeletedFileGetsTheEditBeside(): void
    {
        $this->edit($this->entry('2026-11-05', 'Better words.'));
        $this->sync();
        $this->assertFileDoesNotExist($this->dir . '/' . self::P);
        $this->assertStringContainsString('Better words.', $this->read(self::P . '.from-mg'));
        $this->assertSame('conflict', $this->onlyResult()['outcome']);
    }

    public function testBrokenEditIsRefusedAndNothingWritten(): void
    {
        $this->put(self::P, $this->entry());
        $this->edit($this->entry('2026-11-04'));
        $this->sync();
        $this->assertSame($this->entry(), $this->read(self::P));
        $result = $this->onlyResult();
        $this->assertSame('refused', $result['outcome']);
        $this->assertStringContainsString('must match', \Field::string($result, 'note'));
    }

    public function testRenameMovesThenWrites(): void
    {
        $this->put(self::P, $this->entry());
        $this->edit($this->entry('2026-11-04', 'Moved.'), ['edit_path' => '2026/11/04dream-stage.md']);
        $this->sync();
        $this->assertFileDoesNotExist($this->dir . '/' . self::P);
        $this->assertStringContainsString('Moved.', $this->read('2026/11/04dream-stage.md'));
        $this->assertSame('2026/11/04dream-stage.md', $this->onlyResult()['applied_path']);
    }

    public function testRenameIntoANewMonthMakesTheFolder(): void
    {
        $this->put(self::P, $this->entry());
        $this->edit($this->entry('2026-10-31', 'Moved.'), ['edit_path' => '2026/10/31dream-stage.md']);
        $this->sync();
        $this->assertStringContainsString('Moved.', $this->read('2026/10/31dream-stage.md'));
    }

    public function testRenameOfATrackedFileIsRefused(): void
    {
        $this->put(self::P, $this->entry());
        $this->tracked = [self::P];
        $this->edit($this->entry('2026-11-04'), ['edit_path' => '2026/11/04dream-stage.md']);
        $this->sync();
        $this->assertSame($this->entry(), $this->read(self::P));
        $this->assertSame('refused', $this->onlyResult()['outcome']);
    }

    public function testRenameOntoAnExistingFileIsRefused(): void
    {
        $this->put(self::P, $this->entry());
        $this->put('2026/11/04dream-stage.md', 'someone else');
        $this->edit($this->entry('2026-11-04'), ['edit_path' => '2026/11/04dream-stage.md']);
        $this->sync();
        $this->assertSame('someone else', $this->read('2026/11/04dream-stage.md'));
        $this->assertSame('refused', $this->onlyResult()['outcome']);
    }

    public function testConflictResolvesOnceTheBesideFileIsGone(): void
    {
        $this->put(self::P, $this->entry());
        $this->edit('x', ['status' => 'conflict']);
        $this->put(self::P . '.from-mg', 'still merging');
        $this->sync();
        $this->assertSame([], $this->bodies('/journal-sync/results'));
        unlink($this->dir . '/' . self::P . '.from-mg');
        $this->sync();
        $this->assertSame('resolved', $this->onlyResult()['outcome']);
    }

    public function testEligibleIsUncommittedOrRecent(): void
    {
        $this->put(self::P, $this->entry());
        $this->put('2026/10/22old-but-recent.md', 'x');
        $this->put('2026/10/21too-old.md', 'x');
        $this->put('2025/01/02old-and-modified.md', 'x');
        $this->put('2026/11/05notes.txt', 'x');
        $this->status = ['2025/01/02old-and-modified.md' => 'modified', '2026/11/05notes.txt' => 'untracked'];
        $this->sync();
        $uploaded = [];
        foreach ($this->bodies('/journal-sync/files') as $body) {
            $uploaded[\Field::string($body, 'path')] = \Field::string($body, 'git_state');
        }
        $this->assertSame([
            '2025/01/02old-and-modified.md' => 'modified',
            '2026/10/22old-but-recent.md' => 'committed',
            self::P => 'committed',
        ], $uploaded);
    }

    public function testUnchangedFilesAreNotUploadedAgain(): void
    {
        $this->put(self::P, $this->entry());
        $this->held = [['path' => self::P, 'base_hash' => hash('sha256', $this->entry()), 'git_state' => 'committed',
            'status' => 'synced']];
        $this->sync();
        $this->assertSame([], $this->bodies('/journal-sync/files'));
    }

    public function testSettledAndDeletedFilesAreRemoved(): void
    {
        $this->put('2026/10/01settled.md', 'x');
        $this->held = [
            ['path' => '2026/10/01settled.md', 'base_hash' => 'h', 'git_state' => 'committed', 'status' => 'synced'],
            ['path' => '2026/11/01gone.md', 'base_hash' => 'h', 'git_state' => 'untracked', 'status' => 'synced'],
        ];
        $this->sync();
        $this->assertSame([
            ['path' => '2026/10/01settled.md', 'deleted' => false],
            ['path' => '2026/11/01gone.md', 'deleted' => true],
        ], $this->bodies('/journal-sync/files/remove'));
    }

    public function testEditsAreAppliedBeforeUploads(): void
    {
        $this->put(self::P, $this->entry());
        $this->edit($this->entry('2026-11-05', 'Better words.'));
        $this->sync();
        $routes = array_map(fn(array $c): string => $c[1], $this->calls);
        $this->assertLessThan(
            array_search('/journal-sync/files', $routes, true),
            array_search('/journal-sync/results', $routes, true),
        );
        $this->assertSame('/journal-sync/done', end($routes));
    }
}
