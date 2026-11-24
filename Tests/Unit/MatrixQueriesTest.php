<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Matrix\Evidence;
use Matrix\Opposites;
use Matrix\Problems;
use Matrix\UserConfig;

/**
 * The queries the Decision Matrix pages (wwwroot/matrix/) run through the
 * Matrix gateways, over an in-memory SQLite (no server, gone with the
 * process), as EmotionVocabQueriesTest does. Every one of them runs there.
 *
 * User 1 has set a passphrase and has problems 1 (made second) and 2 (made
 * first). Problem 1 has opposite 5 with evidence 8 (made second) and 9 (made
 * first); problem 2 has no opposite. User 2 has a passphrase, problem 3,
 * opposite 6 and evidence 10. User 3 has nothing.
 */
class MatrixQueriesTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE dm_user_config (
                user_id INTEGER PRIMARY KEY,
                passphrase_verify BLOB NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE dm_my_problems (
                dm_mp INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                problem BLOB NOT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE dm_my_opposites (
                dm_mo INTEGER PRIMARY KEY,
                dm_mp INTEGER NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                opposite BLOB NOT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE dm_my_evidence (
                dm_me INTEGER PRIMARY KEY,
                dm_mo INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                evidence BLOB NOT NULL
            )"
        );
        $this->pdo->exec("INSERT INTO dm_user_config (user_id, passphrase_verify) VALUES (1, 'verify-1'), (2, 'verify-2')");
        $this->pdo->exec(
            "INSERT INTO dm_my_problems (dm_mp, user_id, created_at, problem) VALUES
                (1, 1, '2026-01-02 00:00:00', 'problem-1'),
                (2, 1, '2026-01-01 00:00:00', 'problem-2'),
                (3, 2, '2026-01-03 00:00:00', 'problem-3')"
        );
        $this->pdo->exec(
            "INSERT INTO dm_my_opposites (dm_mo, dm_mp, user_id, opposite) VALUES
                (5, 1, 1, 'opposite-5'),
                (6, 3, 2, 'opposite-6')"
        );
        $this->pdo->exec(
            "INSERT INTO dm_my_evidence (dm_me, dm_mo, user_id, created_at, evidence) VALUES
                (8, 5, 1, '2026-01-02 00:00:00', 'evidence-8'),
                (9, 5, 1, '2026-01-01 00:00:00', 'evidence-9'),
                (10, 6, 2, '2026-01-03 00:00:00', 'evidence-10')"
        );
    }

    /**
     * @return list<array<mixed>>
     */
    private function allRows(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $this->assertNotFalse($stmt);
        return \Database\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testUserConfigGetCountIsOneOnlyOnceSetUp(): void
    {
        $config = new UserConfig($this->pdo);

        $this->assertSame(1, $config->getCount(1));
        $this->assertSame(0, $config->getCount(3));
    }

    public function testPassphraseVerifyOfIsTheUsersOwnBlobOrNull(): void
    {
        $config = new UserConfig($this->pdo);

        $this->assertSame('verify-1', $config->passphraseVerifyOf(1));
        $this->assertSame('verify-2', $config->passphraseVerifyOf(2));
        $this->assertNull($config->passphraseVerifyOf(3));
    }

    public function testPassphraseVerifyOfKeepsAnEmptyBlobApartFromNoRow(): void
    {
        $this->pdo->exec("INSERT INTO dm_user_config (user_id, passphrase_verify) VALUES (3, '')");

        $this->assertSame('', new UserConfig($this->pdo)->passphraseVerifyOf(3));
    }

    public function testAddConfigStoresTheUsersBlob(): void
    {
        new UserConfig($this->pdo)->addConfig(3, 'verify-3');

        $this->assertSame(
            [['user_id' => 3, 'passphrase_verify' => 'verify-3']],
            $this->allRows('SELECT user_id, passphrase_verify FROM dm_user_config WHERE user_id = 3')
        );
    }

    public function testAddProblemStoresItAndReturnsItsId(): void
    {
        $dm_mp = new Problems($this->pdo)->addProblem(3, 'problem-new');

        $this->assertSame(4, $dm_mp);
        $this->assertSame(
            [['dm_mp' => 4, 'user_id' => 3, 'problem' => 'problem-new']],
            $this->allRows('SELECT dm_mp, user_id, problem FROM dm_my_problems WHERE dm_mp = 4')
        );
    }

    public function testGetProblemsAreTheUsersOwnInTheOrderMade(): void
    {
        $problems = new Problems($this->pdo);

        $this->assertSame(
            [
                ['dm_mp' => 2, 'problem' => 'problem-2'],
                ['dm_mp' => 1, 'problem' => 'problem-1'],
            ],
            $problems->getProblems(1)
        );
        $this->assertSame([], $problems->getProblems(3));
    }

    public function testGetProblemIsNullForAnotherUsersProblem(): void
    {
        $problems = new Problems($this->pdo);

        $this->assertSame(['dm_mp' => 1, 'problem' => 'problem-1'], $problems->getProblem(1, 1));
        $this->assertNull($problems->getProblem(1, 2));
        $this->assertNull($problems->getProblem(99, 1));
    }

    public function testGetOppositeIsTheUsersOwnForThatProblem(): void
    {
        $opposites = new Opposites($this->pdo);

        $this->assertSame(['dm_mo' => 5, 'opposite' => 'opposite-5'], $opposites->getOpposite(1, 1));
        $this->assertNull($opposites->getOpposite(2, 1));
        $this->assertNull($opposites->getOpposite(1, 2));
    }

    public function testGetOppositeWithProblemJoinsTheProblemItAnswers(): void
    {
        $opposites = new Opposites($this->pdo);

        $this->assertSame(
            ['dm_mo' => 5, 'opposite' => 'opposite-5', 'dm_mp' => 1, 'problem' => 'problem-1'],
            $opposites->getOppositeWithProblem(5, 1)
        );
        $this->assertNull($opposites->getOppositeWithProblem(5, 2));
        $this->assertNull($opposites->getOppositeWithProblem(99, 1));
    }

    public function testGetCountForProblemIsOneOnceItHasAnOpposite(): void
    {
        $opposites = new Opposites($this->pdo);

        $this->assertSame(1, $opposites->getCountForProblem(1));
        $this->assertSame(0, $opposites->getCountForProblem(2));
    }

    public function testAddOppositeStoresItAndReturnsItsId(): void
    {
        $dm_mo = new Opposites($this->pdo)->addOpposite(2, 1, 'opposite-new');

        $this->assertSame(7, $dm_mo);
        $this->assertSame(
            [['dm_mo' => 7, 'dm_mp' => 2, 'user_id' => 1, 'opposite' => 'opposite-new']],
            $this->allRows('SELECT dm_mo, dm_mp, user_id, opposite FROM dm_my_opposites WHERE dm_mo = 7')
        );
    }

    public function testGetEvidenceIsTheUsersOwnInTheOrderMade(): void
    {
        $evidence = new Evidence($this->pdo);

        $this->assertSame(
            [
                ['dm_me' => 9, 'evidence' => 'evidence-9'],
                ['dm_me' => 8, 'evidence' => 'evidence-8'],
            ],
            $evidence->getEvidence(5, 1)
        );
        $this->assertSame([], $evidence->getEvidence(5, 2));
    }

    public function testAddEvidenceStoresItUnderTheOpposite(): void
    {
        new Evidence($this->pdo)->addEvidence(5, 1, 'evidence-new');

        $this->assertSame(
            [['dm_me' => 11, 'dm_mo' => 5, 'user_id' => 1, 'evidence' => 'evidence-new']],
            $this->allRows('SELECT dm_me, dm_mo, user_id, evidence FROM dm_my_evidence WHERE dm_me = 11')
        );
    }

    public function testEvidenceGetCountIsTheUsersOwnForThatOpposite(): void
    {
        $evidence = new Evidence($this->pdo);

        $this->assertSame(2, $evidence->getCount(5, 1));
        $this->assertSame(0, $evidence->getCount(5, 2));
        $this->assertSame(1, $evidence->getCount(6, 2));
    }
}
