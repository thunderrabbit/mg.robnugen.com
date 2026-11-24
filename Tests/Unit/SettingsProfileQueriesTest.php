<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\Actors;
use Auth\ApiKey;
use Codeception\Test\Unit;
use Database\UserSettings;

/**
 * The queries the settings page (wwwroot/settings/index.php) and the profile
 * page (wwwroot/profile/index.php) run through Auth\Actors, Auth\ApiKey and
 * Database\UserSettings, over an in-memory SQLite (no server, gone with the
 * process). Every one of them runs there.
 *
 * Account 1 has actors 11 (made second) and 12 (made first) and key 100;
 * account 2 has actor 20 and key 200. User 1 has site settings; user 2 has
 * none.
 */
class SettingsProfileQueriesTest extends Unit
{
    private \PDO $pdo;

    protected function _before(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE agent_inbox_user (
                aiu_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT NULL,
                actor_type TEXT NOT NULL DEFAULT 'agent',
                can_read_inbox INTEGER NOT NULL DEFAULT 0,
                can_write_inbox INTEGER NOT NULL DEFAULT 0,
                can_read_todos INTEGER NOT NULL DEFAULT 0,
                can_write_todos INTEGER NOT NULL DEFAULT 0,
                can_read_sessions INTEGER NOT NULL DEFAULT 0,
                can_write_sessions INTEGER NOT NULL DEFAULT 0,
                can_read_emotions INTEGER NOT NULL DEFAULT 0,
                can_write_emotions INTEGER NOT NULL DEFAULT 0,
                can_read_project INTEGER NOT NULL DEFAULT 0,
                can_write_project INTEGER NOT NULL DEFAULT 0,
                can_ping_phone INTEGER NOT NULL DEFAULT 0,
                created_at_utc TEXT NOT NULL DEFAULT '2026-03-01 00:00:00'
            )"
        );
        $this->pdo->exec("CREATE TABLE api_keys (key_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, aiu_id INTEGER NULL)");
        $this->pdo->exec(
            "CREATE TABLE user_settings (
                setting_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                site_title TEXT NULL,
                site_subtitle TEXT NULL
            )"
        );
        $this->pdo->exec(
            "INSERT INTO agent_inbox_user (aiu_id, user_id, name, created_at_utc) VALUES
                (11, 1, 'Second', '2026-02-01 00:00:00'),
                (12, 1, 'First', '2026-01-01 00:00:00'),
                (20, 2, 'Other', '2026-01-15 00:00:00')"
        );
        $this->pdo->exec("INSERT INTO api_keys (key_id, user_id, aiu_id) VALUES (100, 1, NULL), (200, 2, NULL)");
        $this->pdo->exec("INSERT INTO user_settings (user_id, site_title, site_subtitle) VALUES (1, 'Old title', 'Old subtitle')");
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

    public function testGetActorRowsOldestFirstAreTheAccountsWholeRows(): void
    {
        $actors = new Actors($this->pdo)->getActorRowsOldestFirst(1);

        $this->assertSame([12, 11], array_column($actors, 'aiu_id'));
        $this->assertArrayHasKey('can_ping_phone', $actors[0]);
        $this->assertArrayHasKey('created_at_utc', $actors[0]);
    }

    /**
     * The settings form offers nine permissions and no project ones. Its
     * agent is stored through the same addAgent the API uses, with the two
     * project bits given as 0, which is what the column default gave it.
     */
    public function testAddAgentWithTheProjectBitsOffStoresWhatTheSettingsFormAsks(): void
    {
        $perms = [
            'can_read_inbox' => 1, 'can_write_inbox' => 1,
            'can_read_todos' => 0, 'can_write_todos' => 0,
            'can_read_sessions' => 1, 'can_write_sessions' => 0,
            'can_read_emotions' => 0, 'can_write_emotions' => 1,
            'can_read_project' => 0, 'can_write_project' => 0,
            'can_ping_phone' => 1,
        ];

        $aiu_id = new Actors($this->pdo)->addAgent(1, 'From settings', null, $perms);

        $row = $this->allRows("SELECT * FROM agent_inbox_user WHERE aiu_id = $aiu_id")[0];
        $this->assertSame($perms, array_intersect_key($row, $perms));
        $this->assertSame([1, 'From settings', null, 'agent'], [$row['user_id'], $row['name'], $row['description'], $row['actor_type']]);
    }

    public function testAssignActorSetsTheKeysActorInTheAccountOnly(): void
    {
        $keys = new ApiKey($this->pdo);

        $keys->assignActor(100, 1, 11);
        $keys->assignActor(200, 1, 11);

        $this->assertSame(
            [['key_id' => 100, 'aiu_id' => 11], ['key_id' => 200, 'aiu_id' => null]],
            $this->allRows('SELECT key_id, aiu_id FROM api_keys ORDER BY key_id')
        );
    }

    public function testHasSettings(): void
    {
        $settings = new UserSettings($this->pdo);

        $this->assertTrue($settings->hasSettings(1));
        $this->assertFalse($settings->hasSettings(2));
    }

    public function testUpdateSiteChangesOnlyThatUsersTitles(): void
    {
        $this->pdo->exec("INSERT INTO user_settings (user_id, site_title, site_subtitle) VALUES (2, 'Theirs', 'Also theirs')");

        new UserSettings($this->pdo)->updateSite(1, 'New title', 'New subtitle');

        $this->assertSame(
            [
                ['user_id' => 1, 'site_title' => 'New title', 'site_subtitle' => 'New subtitle'],
                ['user_id' => 2, 'site_title' => 'Theirs', 'site_subtitle' => 'Also theirs'],
            ],
            $this->allRows('SELECT user_id, site_title, site_subtitle FROM user_settings ORDER BY user_id')
        );
    }

    public function testAddSiteStoresAUsersFirstTitles(): void
    {
        new UserSettings($this->pdo)->addSite(2, 'Title', 'Subtitle');

        $this->assertSame(
            [['user_id' => 2, 'site_title' => 'Title', 'site_subtitle' => 'Subtitle']],
            $this->allRows('SELECT user_id, site_title, site_subtitle FROM user_settings WHERE user_id = 2')
        );
    }
}
