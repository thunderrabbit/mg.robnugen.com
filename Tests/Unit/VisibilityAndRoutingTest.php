<?php

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * Integration tests for inbox visibility filtering (Session E) and
 * send routing with recipient_aiu (Session F).
 *
 * Actors (all under user_id 30 / Dr_Hilbert_Space_mgTester):
 *
 *   FULL  (aiu 11) — supervisor (NULL peer), sees all, can send anywhere
 *   ALPHA (aiu 13) — can_read_inbox=1, can_write_inbox=0,
 *                     scoped visibility, can send to Beta(14) and Full(11)
 *   BETA  (aiu 14) — can_read_inbox=0, can_write_inbox=1,
 *                     scoped visibility, can send to Alpha(13) and Full(11)
 *   NONE  (aiu 12) — no permissions, no visibility rows
 *
 * Setup sends 4 messages:
 *   #1 Full → broadcast (no recipient_aiu)
 *   #2 Full → Alpha (recipient_aiu=13)
 *   #3 Full → Beta  (recipient_aiu=14)
 *   #4 Beta → Alpha (recipient_aiu=13)
 *
 * IMPORTANT: These tests will FAIL until Session F is deployed.
 *
 * Requires env vars: MG_TEST_KEY_FULL, MG_TEST_KEY_NONE,
 *   MG_TEST_KEY_ALPHA, MG_TEST_KEY_BETA,
 *   MG_TEST_AIU_FULL, MG_TEST_AIU_ALPHA, MG_TEST_AIU_BETA
 */
class VisibilityAndRoutingTest extends Unit
{
    private static string $keyFull;
    private static string $keyNone;
    private static string $keyAlpha;
    private static string $keyBeta;

    private static int $aiuFull;
    private static int $aiuAlpha;
    private static int $aiuBeta;

    private static string $baseUrl = 'https://mg.robnugen.com/api/v1';

    /** Message IDs created during setup, for assertions and cleanup */
    private static int $msgBroadcast;      // #1 Full → broadcast
    private static int $msgToAlpha;         // #2 Full → Alpha
    private static int $msgToBeta;          // #3 Full → Beta
    private static int $msgBetaToAlpha;     // #4 Beta → Alpha

    /** All message IDs to clean up */
    private static array $cleanupIds = [];

    /** Unique tag to identify this test run's messages */
    private static string $runTag;

    // ── Setup / Teardown ─────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        $required = [
            'MG_TEST_KEY_FULL',
            'MG_TEST_KEY_NONE',
            'MG_TEST_KEY_ALPHA',
            'MG_TEST_KEY_BETA',
            'MG_TEST_AIU_FULL',
            'MG_TEST_AIU_ALPHA',
            'MG_TEST_AIU_BETA',
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

        self::$aiuFull  = (int) getenv('MG_TEST_AIU_FULL');
        self::$aiuAlpha = (int) getenv('MG_TEST_AIU_ALPHA');
        self::$aiuBeta  = (int) getenv('MG_TEST_AIU_BETA');

        self::$runTag = 'vrt_' . bin2hex(random_bytes(4));

        // ── Send 4 setup messages ────────────────────────────────────

        // #1 Full → broadcast (no recipient_aiu)
        $r = self::send(self::$keyFull, self::$runTag . ' broadcast');
        self::assertSetupOk($r, '#1 broadcast');
        self::$msgBroadcast = $r['body']['message_id'];

        // #2 Full → Alpha
        $r = self::send(self::$keyFull, self::$runTag . ' to_alpha', self::$aiuAlpha);
        self::assertSetupOk($r, '#2 to Alpha');
        self::$msgToAlpha = $r['body']['message_id'];

        // #3 Full → Beta
        $r = self::send(self::$keyFull, self::$runTag . ' to_beta', self::$aiuBeta);
        self::assertSetupOk($r, '#3 to Beta');
        self::$msgToBeta = $r['body']['message_id'];

        // #4 Beta → Alpha
        $r = self::send(self::$keyBeta, self::$runTag . ' beta_to_alpha', self::$aiuAlpha);
        self::assertSetupOk($r, '#4 Beta to Alpha');
        self::$msgBetaToAlpha = $r['body']['message_id'];
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up all tracked messages using the full-access key
        foreach (self::$cleanupIds as $id) {
            self::rawRequest(self::$keyFull, 'DELETE', '/inbox/delete', ['message_id' => $id]);
        }
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────

    /**
     * @return array{status: int, body: array}
     */
    private static function rawRequest(string $apiKey, string $method, string $path, array $payload = []): array
    {
        $url = self::$baseUrl . $path;

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

    /**
     * POST /inbox/send and track the message_id for cleanup.
     */
    private static function send(string $key, string $message, ?int $recipientAiu = null): array
    {
        $payload = [
            'message'  => $message,
            'priority' => 'low',
        ];
        if ($recipientAiu !== null) {
            $payload['recipient_aiu'] = $recipientAiu;
        }

        $result = self::rawRequest($key, 'POST', '/inbox/send', $payload);

        if (isset($result['body']['message_id'])) {
            self::$cleanupIds[] = $result['body']['message_id'];
        }

        return $result;
    }

    /**
     * GET /inbox/list with optional query params.
     */
    private function listInbox(string $key, array $params = []): array
    {
        return self::rawRequest($key, 'GET', '/inbox/list', $params);
    }

    /**
     * Extract message_ids from a list response.
     */
    private function extractIds(array $result): array
    {
        return array_column($result['body']['messages'] ?? [], 'message_id');
    }

    private static function assertSetupOk(array $result, string $label): void
    {
        if ($result['status'] !== 201 || !isset($result['body']['message_id'])) {
            fwrite(STDERR, "\n\nABORT: Setup message $label failed: "
                . "status={$result['status']} body=" . json_encode($result['body']) . "\n\n");
            exit(1);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  VISIBILITY — GET /inbox/list filtering
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Test 1: Full (supervisor) sees all 4 messages.
     */
    public function testFullSeesAllMessages()
    {
        $result = $this->listInbox(self::$keyFull);
        $this->assertEquals(200, $result['status']);

        $ids = $this->extractIds($result);
        $expected = [self::$msgBroadcast, self::$msgToAlpha, self::$msgToBeta, self::$msgBetaToAlpha];

        foreach ($expected as $msgId) {
            $this->assertContains($msgId, $ids, "Supervisor should see message $msgId");
        }
    }

    /**
     * Test 2: Alpha sees broadcast (#1) + messages addressed to Alpha (#2, #4),
     *         but NOT #3 (addressed to Beta).
     */
    public function testAlphaSeesOwnAndBroadcastOnly()
    {
        $result = $this->listInbox(self::$keyAlpha);
        $this->assertEquals(200, $result['status']);

        $ids = $this->extractIds($result);

        $this->assertContains(self::$msgBroadcast, $ids, 'Alpha should see broadcast');
        $this->assertContains(self::$msgToAlpha, $ids, 'Alpha should see message addressed to Alpha');
        $this->assertContains(self::$msgBetaToAlpha, $ids, 'Alpha should see Beta→Alpha message');
        $this->assertNotContains(self::$msgToBeta, $ids, 'Alpha should NOT see message addressed to Beta');
    }

    /**
     * Test 3: Beta cannot call list — can_read_inbox=0 → 403.
     */
    public function testBetaCannotListInbox()
    {
        $result = $this->listInbox(self::$keyBeta);
        $this->assertEquals(403, $result['status']);
        $this->assertEquals(
            'This API key does not have permission to read inbox',
            $result['body']['error'] ?? ''
        );
    }

    /**
     * Test 4: Full with sender_aiu filter sees only message #4 (sent by Beta).
     */
    public function testFullFilterBySender()
    {
        $result = $this->listInbox(self::$keyFull, ['sender_aiu' => self::$aiuBeta]);
        $this->assertEquals(200, $result['status']);

        $ids = $this->extractIds($result);

        $this->assertContains(self::$msgBetaToAlpha, $ids, 'Should include Beta-sent message');
        $this->assertNotContains(self::$msgBroadcast, $ids, 'Should exclude Full-sent broadcast');
        $this->assertNotContains(self::$msgToAlpha, $ids, 'Should exclude Full-sent to Alpha');
        $this->assertNotContains(self::$msgToBeta, $ids, 'Should exclude Full-sent to Beta');
    }

    /**
     * Test 5: Full with include_sent=1 still sees all 4 (supervisor sees all anyway).
     */
    public function testFullWithIncludeSentSeesAll()
    {
        $result = $this->listInbox(self::$keyFull, ['include_sent' => 1]);
        $this->assertEquals(200, $result['status']);

        $ids = $this->extractIds($result);
        $expected = [self::$msgBroadcast, self::$msgToAlpha, self::$msgToBeta, self::$msgBetaToAlpha];

        foreach ($expected as $msgId) {
            $this->assertContains($msgId, $ids, "Supervisor with include_sent should see message $msgId");
        }
    }

    /**
     * Test 6: Alpha with include_sent=1 still sees #1, #2, #4 —
     *         Alpha hasn't sent anything, no change.
     */
    public function testAlphaWithIncludeSentNoChange()
    {
        $result = $this->listInbox(self::$keyAlpha, ['include_sent' => 1]);
        $this->assertEquals(200, $result['status']);

        $ids = $this->extractIds($result);

        $this->assertContains(self::$msgBroadcast, $ids, 'Alpha should see broadcast');
        $this->assertContains(self::$msgToAlpha, $ids, 'Alpha should see message to Alpha');
        $this->assertContains(self::$msgBetaToAlpha, $ids, 'Alpha should see Beta→Alpha');
        $this->assertNotContains(self::$msgToBeta, $ids, 'Alpha should NOT see message to Beta');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  SEND ROUTING — POST /inbox/send with recipient_aiu
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Test 7: Beta sends to Alpha (recipient_aiu=13) — allowed by visibility.
     */
    public function testBetaCanSendToAlpha()
    {
        $result = self::send(self::$keyBeta, self::$runTag . ' routing_test_7', self::$aiuAlpha);
        $this->assertEquals(201, $result['status'], 'Beta→Alpha should be allowed');
        $this->assertArrayHasKey('message_id', $result['body']);
    }

    /**
     * Test 8: Beta sends broadcast (no recipient_aiu) — allowed.
     */
    public function testBetaCanSendBroadcast()
    {
        $result = self::send(self::$keyBeta, self::$runTag . ' routing_test_8');
        $this->assertEquals(201, $result['status'], 'Beta broadcast should be allowed');
        $this->assertArrayHasKey('message_id', $result['body']);
    }

    /**
     * Test 9: Beta sends to None (recipient_aiu=12) — no can_send row → 403.
     */
    public function testBetaCannotSendToNone()
    {
        $aiuNone = 12;
        $result = self::send(self::$keyBeta, self::$runTag . ' routing_test_9', $aiuNone);
        $this->assertEquals(403, $result['status'], 'Beta→None should be denied (no visibility row)');
    }

    /**
     * Test 10: Alpha cannot send at all — can_write_inbox=0 → 403.
     */
    public function testAlphaCannotSend()
    {
        $result = self::send(self::$keyAlpha, self::$runTag . ' routing_test_10');
        $this->assertEquals(403, $result['status'], 'Alpha has can_write_inbox=0');
        $this->assertEquals(
            'This API key does not have permission to write inbox',
            $result['body']['error'] ?? ''
        );
    }

    /**
     * Test 11: Verify sender_aiu is auto-populated — read back message #4,
     *          check sender_aiu=14 (Beta).
     */
    public function testSenderAiuAutoPopulated()
    {
        // Use Full key to list and find message #4
        $result = $this->listInbox(self::$keyFull);
        $this->assertEquals(200, $result['status']);

        $msg4 = null;
        foreach ($result['body']['messages'] ?? [] as $msg) {
            if ($msg['message_id'] === self::$msgBetaToAlpha) {
                $msg4 = $msg;
                break;
            }
        }

        $this->assertNotNull($msg4, 'Message #4 should exist in list');
        $this->assertEquals(
            self::$aiuBeta,
            $msg4['sender_aiu'] ?? null,
            'sender_aiu should be auto-populated with Beta aiu_id (14)'
        );
    }
}
