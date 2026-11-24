<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\Actors;
use Codeception\Test\Unit;
use Issues\Repositories;

/**
 * The queries the repositories endpoint (wwwroot/api/v1/_repositories.php)
 * and the two repository pages (wwwroot/repositories/) run through
 * Issues\Repositories and Auth\Actors, over an in-memory SQLite (no server,
 * gone with the process). Every one of them runs there.
 *
 * Account 1 has repositories 1 (zeta), 2 (alpha) and 3 (mid, inactive);
 * account 2 has repository 4, also named alpha. Account 1 has a human actor;
 * account 2 has only an agent.
 */
class RepositoryQueriesTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE repositories (
                repo_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                url TEXT NULL,
                default_branch TEXT NULL DEFAULT 'master',
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at_utc TEXT NOT NULL DEFAULT '2026-01-01 00:00:00',
                UNIQUE (user_id, name)
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE agent_inbox_user (
                aiu_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                actor_type TEXT NOT NULL
            )"
        );
        $this->pdo->exec(
            "INSERT INTO repositories (repo_id, user_id, name, url, default_branch, is_active) VALUES
                (1, 1, 'zeta', 'https://example.test/zeta', 'main', 1),
                (2, 1, 'alpha', NULL, 'master', 1),
                (3, 1, 'mid', NULL, 'master', 0),
                (4, 2, 'alpha', NULL, 'master', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO agent_inbox_user (aiu_id, user_id, actor_type) VALUES (10, 1, 'human'), (11, 1, 'agent'), (20, 2, 'agent')"
        );
    }

    /**
     * @return array<mixed>
     */
    private function repo(int $repo_id): array
    {
        $stmt = $this->pdo->query("SELECT * FROM repositories WHERE repo_id = $repo_id");
        $this->assertNotFalse($stmt);
        $rows = \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    public function testGetRepositoriesAreTheAccountsActiveOnesByName(): void
    {
        $repos = new Repositories($this->pdo)->getRepositories(1, false);

        $this->assertSame([2, 1], array_column($repos, 'repo_id'));
        $this->assertSame(
            ['repo_id', 'name', 'url', 'default_branch', 'is_active', 'created_at_utc'],
            array_keys($repos[0])
        );
    }

    public function testGetRepositoriesCanIncludeTheInactive(): void
    {
        $this->assertSame([2, 3, 1], array_column(new Repositories($this->pdo)->getRepositories(1, true), 'repo_id'));
        $this->assertSame([4], array_column(new Repositories($this->pdo)->getRepositories(2, true), 'repo_id'));
    }

    public function testRepoIdOfNameIsWithinTheAccountAndCountsInactiveOnes(): void
    {
        $repos = new Repositories($this->pdo);

        $this->assertSame(2, $repos->repoIdOfName(1, 'alpha'));
        $this->assertSame(4, $repos->repoIdOfName(2, 'alpha'));
        $this->assertSame(3, $repos->repoIdOfName(1, 'mid'));
        $this->assertNull($repos->repoIdOfName(2, 'zeta'));
    }

    public function testAddRepositoryStoresItAndReturnsItsId(): void
    {
        $repo_id = new Repositories($this->pdo)->addRepository(2, 'fresh', 'https://example.test/fresh', 'trunk');

        $this->assertSame(5, $repo_id);
        $row = $this->repo(5);
        $this->assertSame(
            [2, 'fresh', 'https://example.test/fresh', 'trunk', 1],
            [$row['user_id'], $row['name'], $row['url'], $row['default_branch'], $row['is_active']]
        );
    }

    public function testAddRepositoryKeepsANullUrl(): void
    {
        $repo_id = new Repositories($this->pdo)->addRepository(2, 'no-url', null, 'master');

        $this->assertNull($this->repo($repo_id)['url']);
    }

    public function testUpdateRepositoryChangesWhatItIsGivenInTheAccountOnly(): void
    {
        $repos = new Repositories($this->pdo);

        $this->assertSame(
            1,
            $repos->updateRepository(1, 1, ['name' => 'zeta2', 'url' => 'https://example.test/z2', 'default_branch' => 'dev'])
        );
        $row = $this->repo(1);
        $this->assertSame(['zeta2', 'https://example.test/z2', 'dev'], [$row['name'], $row['url'], $row['default_branch']]);

        $this->assertSame(0, $repos->updateRepository(4, 1, ['name' => 'reached across']));
        $this->assertSame('alpha', $this->repo(4)['name']);
    }

    public function testUpdateRepositoryClearsTheUrlWithNullAndLeavesTheRestAlone(): void
    {
        $this->assertSame(1, new Repositories($this->pdo)->updateRepository(1, 1, ['url' => null]));

        $row = $this->repo(1);
        $this->assertNull($row['url']);
        $this->assertSame(['zeta', 'main'], [$row['name'], $row['default_branch']]);
    }

    public function testUpdateRepositoryRefusesNoChangesAndUnknownColumns(): void
    {
        $repos = new Repositories($this->pdo);
        $before = $this->repo(1);

        foreach ([[], ['is_active' => '0'], ['user_id' => '2']] as $changes) {
            try {
                $repos->updateRepository(1, 1, $changes);
                $this->fail('Only name, url and default_branch may be changed, and one must be.');
            } catch (\InvalidArgumentException) {
                $this->assertSame($before, $this->repo(1));
            }
        }
    }

    public function testDeactivateTurnsOffOneRepositoryInTheAccountOnly(): void
    {
        $repos = new Repositories($this->pdo);

        $this->assertSame(1, $repos->deactivate(2, 1));
        $this->assertSame(0, $this->repo(2)['is_active']);
        $this->assertSame(1, $this->repo(1)['is_active']);

        $this->assertSame(0, $repos->deactivate(4, 1));
        $this->assertSame(1, $this->repo(4)['is_active']);
    }

    public function testHasHumanActor(): void
    {
        $actors = new Actors($this->pdo);

        $this->assertTrue($actors->hasHumanActor(1));
        $this->assertFalse($actors->hasHumanActor(2));
        $this->assertFalse($actors->hasHumanActor(3));
    }
}
