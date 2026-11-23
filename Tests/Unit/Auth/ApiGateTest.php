<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Auth\ApiGate;
use Codeception\Test\Unit;

/**
 * Hermetic unit tests for Auth\ApiGate, against SQLite in memory. The two
 * refusals (402 and 403) end the request, so what is run here is the parts
 * that decide them and the path of a call that is let through.
 */
final class ApiGateTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE api_credits (user_id INTEGER PRIMARY KEY, credits_remaining INTEGER NOT NULL)');
        $this->pdo->exec(
            'CREATE TABLE api_usage (usage_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL,'
            . ' key_id INTEGER, token_id INTEGER, endpoint TEXT NOT NULL)'
        );
        $this->pdo->exec('INSERT INTO api_credits (user_id, credits_remaining) VALUES (1, 2), (2, 0), (3, 5)');
    }

    public function testTakingACreditLowersOnlyThatUsersBalance(): void
    {
        $this->assertTrue(ApiGate::takeCredit($this->pdo, 1));

        $this->assertSame(1, $this->credits(1));
        $this->assertSame(0, $this->credits(2));
        $this->assertSame(5, $this->credits(3));
    }

    public function testTheLastCreditCanBeTakenAndThenNoMore(): void
    {
        $this->assertTrue(ApiGate::takeCredit($this->pdo, 1));
        $this->assertTrue(ApiGate::takeCredit($this->pdo, 1));
        $this->assertFalse(ApiGate::takeCredit($this->pdo, 1));

        $this->assertSame(0, $this->credits(1));
    }

    public function testAUserWithNoCreditsIsRefusedAndStaysAtZero(): void
    {
        $this->assertFalse(ApiGate::takeCredit($this->pdo, 2));

        $this->assertSame(0, $this->credits(2));
    }

    public function testAUserWithNoCreditRowIsRefused(): void
    {
        $this->assertFalse(ApiGate::takeCredit($this->pdo, 99));
    }

    public function testACallThatIsLetThroughCostsOneCreditAndIsLogged(): void
    {
        ApiGate::requireCredit($this->pdo, 1, 40, 'inbox/send');
        ApiGate::requireCredit($this->pdo, 3, null, '/exterm/create', 8);

        $this->assertSame(1, $this->credits(1));
        $this->assertSame(4, $this->credits(3));
        $this->assertSame(
            [
                ['user_id' => 1, 'key_id' => 40, 'token_id' => null, 'endpoint' => 'inbox/send'],
                ['user_id' => 3, 'key_id' => null, 'token_id' => 8, 'endpoint' => '/exterm/create'],
            ],
            $this->logged(),
        );
    }

    public function testALogThatCannotBeWrittenDoesNotFailTheCall(): void
    {
        $this->pdo->exec('DROP TABLE api_usage');

        ApiGate::requireCredit($this->pdo, 1, 40, 'inbox/send');

        $this->assertSame(1, $this->credits(1));
    }

    public function testReadingNeedsTheReadFlagAndEverythingElseTheWriteFlag(): void
    {
        $this->assertSame('can_read_project', ApiGate::projectPermNeeded('GET'));
        $this->assertSame('can_write_project', ApiGate::projectPermNeeded('POST'));
        $this->assertSame('can_write_project', ApiGate::projectPermNeeded('PATCH'));
        $this->assertSame('can_write_project', ApiGate::projectPermNeeded('DELETE'));
        $this->assertSame('can_write_project', ApiGate::projectPermNeeded('get'));
        $this->assertSame('can_write_project', ApiGate::projectPermNeeded(''));
    }

    public function testAnActorWithTheFlagIsLetThrough(): void
    {
        $this->assertTrue(ApiGate::hasProjectPerm(['can_read_project' => 1, 'can_write_project' => 0], 'GET'));
        $this->assertTrue(ApiGate::hasProjectPerm(['can_read_project' => 0, 'can_write_project' => 1], 'POST'));
        $this->assertTrue(ApiGate::hasProjectPerm(['can_read_project' => '1'], 'GET'));

        // Let through means requireProjectPerm returns and prints nothing.
        ob_start();
        ApiGate::requireProjectPerm(['can_read_project' => 1], 'GET');
        ApiGate::requireProjectPerm(['can_write_project' => 1], 'DELETE');
        $this->assertSame('', ob_get_clean());
    }

    public function testAnActorWithoutTheFlagIsNot(): void
    {
        // The read flag does not let a write through, nor the write flag a read.
        $this->assertFalse(ApiGate::hasProjectPerm(['can_read_project' => 1, 'can_write_project' => 0], 'POST'));
        $this->assertFalse(ApiGate::hasProjectPerm(['can_read_project' => 0, 'can_write_project' => 1], 'GET'));

        // Missing, 0, '0', '', false and null all read as not granted.
        $this->assertFalse(ApiGate::hasProjectPerm([], 'GET'));
        $this->assertFalse(ApiGate::hasProjectPerm(['can_read_project' => 0], 'GET'));
        $this->assertFalse(ApiGate::hasProjectPerm(['can_read_project' => '0'], 'GET'));
        $this->assertFalse(ApiGate::hasProjectPerm(['can_read_project' => ''], 'GET'));
        $this->assertFalse(ApiGate::hasProjectPerm(['can_read_project' => false], 'GET'));
        $this->assertFalse(ApiGate::hasProjectPerm(['can_read_project' => null], 'GET'));
    }

    /**
     * @return array<mixed>
     */
    private function logged(): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id, key_id, token_id, endpoint FROM api_usage ORDER BY usage_id');
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function credits(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT credits_remaining FROM api_credits WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }
}
