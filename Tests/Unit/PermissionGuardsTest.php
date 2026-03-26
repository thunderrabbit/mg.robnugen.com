<?php

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * Integration tests for the 8-boolean permission guard system.
 *
 * Uses 4 test actors (all under user_id 30 / Dr_Hilbert_Space_mgTester):
 *
 *   FULL  — all permissions enabled (sanity: everything works)
 *   NONE  — all permissions disabled (sanity: everything blocked)
 *   ALPHA — read_inbox=1, write_inbox=0, read_todos=0, write_todos=1,
 *           read_sessions=1, write_sessions=0, read_emotions=0, write_emotions=1
 *   BETA  — complement of ALPHA
 *
 * Together ALPHA+BETA test every boolean in both states and catch
 * cross-resource bugs (e.g. _todos.php checking can_read_inbox).
 *
 * Requires env vars: MG_TEST_KEY_FULL, MG_TEST_KEY_NONE,
 *                    MG_TEST_KEY_ALPHA, MG_TEST_KEY_BETA
 *
 * HARD FAIL if any key is missing — these are integration tests that
 * must run fully or not at all.
 */
class PermissionGuardsTest extends Unit
{
    private static string $keyFull;
    private static string $keyNone;
    private static string $keyAlpha;
    private static string $keyBeta;

    private static string $baseUrl = 'https://mg.robnugen.com/api/v1';

    /** Track created resources for cleanup (keyed by type) */
    private static array $cleanup = [
        'inbox'    => [],  // message_ids
        'todos'    => [],  // todo_ids
        'sessions' => [],  // ak_ids
        'emotions' => [],  // my_ids
    ];

    // ── Setup / Teardown ─────────────────────────────────────────────────

    private static bool $keysLoaded = false;

    private static function loadKeys(): void
    {
        if (self::$keysLoaded) {
            return;
        }

        $required = [
            'MG_TEST_KEY_FULL',
            'MG_TEST_KEY_NONE',
            'MG_TEST_KEY_ALPHA',
            'MG_TEST_KEY_BETA',
        ];

        $missing = [];
        foreach ($required as $var) {
            if (!getenv($var)) {
                $missing[] = $var;
            }
        }

        if ($missing) {
            fwrite(STDERR, "\n\nABORT: Missing required env vars: " . implode(', ', $missing) . "\n");
            fwrite(STDERR, "Set them in .env and run: export \$(grep -v '^#' .env | xargs)\n\n");
            exit(1);
        }

        self::$keyFull  = getenv('MG_TEST_KEY_FULL');
        self::$keyNone  = getenv('MG_TEST_KEY_NONE');
        self::$keyAlpha = getenv('MG_TEST_KEY_ALPHA');
        self::$keyBeta  = getenv('MG_TEST_KEY_BETA');
        self::$keysLoaded = true;
    }

    public static function setUpBeforeClass(): void
    {
        self::loadKeys();
    }

    protected function _before(): void
    {
        self::loadKeys();
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up all created resources using the full-access key
        foreach (self::$cleanup['inbox'] as $id) {
            self::rawRequest(self::$keyFull, 'DELETE', '/inbox/delete', ['message_id' => $id]);
        }
        foreach (self::$cleanup['todos'] as $id) {
            self::rawRequest(self::$keyFull, 'DELETE', '/todos/delete', ['todo_id' => $id]);
        }
        foreach (self::$cleanup['sessions'] as $id) {
            self::rawRequest(self::$keyFull, 'DELETE', '/sessions/' . $id);
        }
        foreach (self::$cleanup['emotions'] as $id) {
            self::rawRequest(self::$keyFull, 'DELETE', '/emotions/vocab', ['my_id' => $id]);
        }
    }

    // ── HTTP helper ──────────────────────────────────────────────────────

    /**
     * @return array{status: int, body: array}
     */
    private static function rawRequest(string $apiKey, string $method, string $path, array $payload = []): array
    {
        $url = self::$baseUrl . $path;

        // Append query string for GET requests
        if ($method === 'GET' && $payload) {
            $url .= '?' . http_build_query($payload);
            $payload = [];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        if ($method !== 'GET' && $payload) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => json_decode($response, true) ?? [],
        ];
    }

    // ── Endpoint helpers ─────────────────────────────────────────────────

    private function readInbox(string $key): array
    {
        return self::rawRequest($key, 'GET', '/inbox/list');
    }

    private function writeInbox(string $key): array
    {
        $result = self::rawRequest($key, 'POST', '/inbox/send', [
            'message'  => 'Permission test ' . microtime(true),
            'priority' => 'low',
        ]);
        if (isset($result['body']['message_id'])) {
            self::$cleanup['inbox'][] = $result['body']['message_id'];
        }
        return $result;
    }

    private function readTodos(string $key): array
    {
        return self::rawRequest($key, 'GET', '/todos/list', ['timezone' => 'UTC']);
    }

    private function writeTodos(string $key): array
    {
        $result = self::rawRequest($key, 'POST', '/todos/create', [
            'title' => 'Permission test ' . microtime(true),
        ]);
        if (isset($result['body']['todo_id'])) {
            self::$cleanup['todos'][] = $result['body']['todo_id'];
        }
        return $result;
    }

    private function readSessions(string $key): array
    {
        return self::rawRequest($key, 'GET', '/sessions');
    }

    private function writeSessions(string $key): array
    {
        $result = self::rawRequest($key, 'POST', '/sessions', [
            'activity_id' => 1,
            'timezone'    => 'UTC',
        ]);
        if (isset($result['body']['session']['ak_id'])) {
            self::$cleanup['sessions'][] = $result['body']['session']['ak_id'];
        }
        return $result;
    }

    private function readEmotions(string $key): array
    {
        return self::rawRequest($key, 'GET', '/emotions/vocab');
    }

    private function writeEmotions(string $key): array
    {
        $result = self::rawRequest($key, 'POST', '/emotions/vocab', [
            'state' => 'perm_test_' . bin2hex(random_bytes(4)),
        ]);
        if (isset($result['body']['my_id'])) {
            self::$cleanup['emotions'][] = $result['body']['my_id'];
        }
        return $result;
    }

    // ── Assertion helpers ────────────────────────────────────────────────

    private function assertAllowed(array $result, string $context): void
    {
        $this->assertNotEquals(
            403,
            $result['status'],
            "$context: expected permission granted, got 403: " . json_encode($result['body'])
        );
    }

    private function assertDenied(array $result, string $expectedError, string $context): void
    {
        $this->assertEquals(
            403,
            $result['status'],
            "$context: expected 403, got {$result['status']}: " . json_encode($result['body'])
        );
        $this->assertEquals(
            $expectedError,
            $result['body']['error'] ?? '',
            "$context: wrong error message"
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  FULL ACCESS — sanity: every endpoint should succeed
    // ═══════════════════════════════════════════════════════════════════════

    public function testFullAccessCanReadInbox()
    {
        $this->assertAllowed($this->readInbox(self::$keyFull), 'full/read_inbox');
    }

    public function testFullAccessCanWriteInbox()
    {
        $this->assertAllowed($this->writeInbox(self::$keyFull), 'full/write_inbox');
    }

    public function testFullAccessCanReadTodos()
    {
        $this->assertAllowed($this->readTodos(self::$keyFull), 'full/read_todos');
    }

    public function testFullAccessCanWriteTodos()
    {
        $this->assertAllowed($this->writeTodos(self::$keyFull), 'full/write_todos');
    }

    public function testFullAccessCanReadSessions()
    {
        $this->assertAllowed($this->readSessions(self::$keyFull), 'full/read_sessions');
    }

    public function testFullAccessCanWriteSessions()
    {
        $this->assertAllowed($this->writeSessions(self::$keyFull), 'full/write_sessions');
    }

    public function testFullAccessCanReadEmotions()
    {
        $this->assertAllowed($this->readEmotions(self::$keyFull), 'full/read_emotions');
    }

    public function testFullAccessCanWriteEmotions()
    {
        $this->assertAllowed($this->writeEmotions(self::$keyFull), 'full/write_emotions');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ZERO ACCESS — sanity: every endpoint should return 403
    // ═══════════════════════════════════════════════════════════════════════

    public function testNoAccessBlockedFromReadInbox()
    {
        $this->assertDenied(
            $this->readInbox(self::$keyNone),
            'This API key does not have permission to read inbox',
            'none/read_inbox'
        );
    }

    public function testNoAccessBlockedFromWriteInbox()
    {
        $this->assertDenied(
            $this->writeInbox(self::$keyNone),
            'This API key does not have permission to write inbox',
            'none/write_inbox'
        );
    }

    public function testNoAccessBlockedFromReadTodos()
    {
        $this->assertDenied(
            $this->readTodos(self::$keyNone),
            'This API key does not have permission to read todos',
            'none/read_todos'
        );
    }

    public function testNoAccessBlockedFromWriteTodos()
    {
        $this->assertDenied(
            $this->writeTodos(self::$keyNone),
            'This API key does not have permission to modify todos',
            'none/write_todos'
        );
    }

    public function testNoAccessBlockedFromReadSessions()
    {
        $this->assertDenied(
            $this->readSessions(self::$keyNone),
            'This API key does not have permission to read sessions',
            'none/read_sessions'
        );
    }

    public function testNoAccessBlockedFromWriteSessions()
    {
        $this->assertDenied(
            $this->writeSessions(self::$keyNone),
            'This API key does not have permission to modify sessions',
            'none/write_sessions'
        );
    }

    public function testNoAccessBlockedFromReadEmotions()
    {
        $this->assertDenied(
            $this->readEmotions(self::$keyNone),
            'This API key does not have permission to read emotions',
            'none/read_emotions'
        );
    }

    public function testNoAccessBlockedFromWriteEmotions()
    {
        $this->assertDenied(
            $this->writeEmotions(self::$keyNone),
            'This API key does not have permission to modify emotions',
            'none/write_emotions'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ALPHA — read_inbox=1, write_inbox=0, read_todos=0, write_todos=1,
    //          read_sessions=1, write_sessions=0, read_emotions=0, write_emotions=1
    // ═══════════════════════════════════════════════════════════════════════

    public function testAlphaCanReadInbox()
    {
        $this->assertAllowed($this->readInbox(self::$keyAlpha), 'alpha/read_inbox');
    }

    public function testAlphaBlockedFromWriteInbox()
    {
        $this->assertDenied(
            $this->writeInbox(self::$keyAlpha),
            'This API key does not have permission to write inbox',
            'alpha/write_inbox'
        );
    }

    public function testAlphaBlockedFromReadTodos()
    {
        $this->assertDenied(
            $this->readTodos(self::$keyAlpha),
            'This API key does not have permission to read todos',
            'alpha/read_todos'
        );
    }

    public function testAlphaCanWriteTodos()
    {
        $this->assertAllowed($this->writeTodos(self::$keyAlpha), 'alpha/write_todos');
    }

    public function testAlphaCanReadSessions()
    {
        $this->assertAllowed($this->readSessions(self::$keyAlpha), 'alpha/read_sessions');
    }

    public function testAlphaBlockedFromWriteSessions()
    {
        $this->assertDenied(
            $this->writeSessions(self::$keyAlpha),
            'This API key does not have permission to modify sessions',
            'alpha/write_sessions'
        );
    }

    public function testAlphaBlockedFromReadEmotions()
    {
        $this->assertDenied(
            $this->readEmotions(self::$keyAlpha),
            'This API key does not have permission to read emotions',
            'alpha/read_emotions'
        );
    }

    public function testAlphaCanWriteEmotions()
    {
        $this->assertAllowed($this->writeEmotions(self::$keyAlpha), 'alpha/write_emotions');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  BETA — complement of ALPHA:
    //         read_inbox=0, write_inbox=1, read_todos=1, write_todos=0,
    //         read_sessions=0, write_sessions=1, read_emotions=1, write_emotions=0
    // ═══════════════════════════════════════════════════════════════════════

    public function testBetaBlockedFromReadInbox()
    {
        $this->assertDenied(
            $this->readInbox(self::$keyBeta),
            'This API key does not have permission to read inbox',
            'beta/read_inbox'
        );
    }

    public function testBetaCanWriteInbox()
    {
        $this->assertAllowed($this->writeInbox(self::$keyBeta), 'beta/write_inbox');
    }

    public function testBetaCanReadTodos()
    {
        $this->assertAllowed($this->readTodos(self::$keyBeta), 'beta/read_todos');
    }

    public function testBetaBlockedFromWriteTodos()
    {
        $this->assertDenied(
            $this->writeTodos(self::$keyBeta),
            'This API key does not have permission to modify todos',
            'beta/write_todos'
        );
    }

    public function testBetaBlockedFromReadSessions()
    {
        $this->assertDenied(
            $this->readSessions(self::$keyBeta),
            'This API key does not have permission to read sessions',
            'beta/read_sessions'
        );
    }

    public function testBetaCanWriteSessions()
    {
        $this->assertAllowed($this->writeSessions(self::$keyBeta), 'beta/write_sessions');
    }

    public function testBetaCanReadEmotions()
    {
        $this->assertAllowed($this->readEmotions(self::$keyBeta), 'beta/read_emotions');
    }

    public function testBetaBlockedFromWriteEmotions()
    {
        $this->assertDenied(
            $this->writeEmotions(self::$keyBeta),
            'This API key does not have permission to modify emotions',
            'beta/write_emotions'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  GET /inbox/actors — requires can_write_inbox
    // ═══════════════════════════════════════════════════════════════════════

    public function testFullAccessCanListActors()
    {
        $result = self::rawRequest(self::$keyFull, 'GET', '/inbox/actors');
        $this->assertAllowed($result, 'full/inbox_actors');
        $this->assertEquals(200, $result['status'], 'Expected 200 from /inbox/actors');
        $this->assertArrayHasKey('actors', $result['body'], 'Response should contain "actors" array');
        $this->assertIsArray($result['body']['actors']);
        $this->assertNotEmpty($result['body']['actors'], 'Actors list should not be empty');

        // Each actor should have aiu_id, name, description
        $first = $result['body']['actors'][0];
        $this->assertArrayHasKey('aiu_id', $first, 'Actor should have aiu_id');
        $this->assertArrayHasKey('name', $first, 'Actor should have name');
        $this->assertArrayHasKey('description', $first, 'Actor should have description');
    }

    public function testAlphaBlockedFromListActors()
    {
        $this->assertDenied(
            self::rawRequest(self::$keyAlpha, 'GET', '/inbox/actors'),
            'This API key does not have permission to list actors',
            'alpha/inbox_actors'
        );
    }
}
