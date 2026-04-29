<?php

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * Integration test for POST /api/v1/inbox/send message-length validation.
 *
 * Hits the real API endpoint — each successful send costs 1 credit.
 * Requires MG_API_KEY env var set to a valid sk_... key.
 */
class InboxMessageLimitTest extends Unit
{
    private string $baseUrl = 'https://mg.robnugen.com/api/v1/inbox';
    private string $apiKey;

    /** message_ids created during the test run, deleted in tearDown */
    private array $createdIds = [];

    protected function _before(): void
    {
        $key = getenv('MG_API_KEY');
        if (!$key) {
            $this->markTestSkipped('MG_API_KEY env var is not set — skipping inbox API tests');
        }
        $this->apiKey = $key;
    }

    protected function _after(): void
    {
        // Clean up: delete every message we created
        foreach ($this->createdIds as $id) {
            $this->apiRequest('DELETE', '/delete', ['message_id' => $id]);
        }
    }

    // ── Helper ────────────────────────────────────────────────────────────

    /**
     * Make an HTTP request to the inbox API.
     *
     * @return array{status: int, body: array}
     */
    private function apiRequest(string $method, string $subPath, array $payload = []): array
    {
        $url = $this->baseUrl . $subPath;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => json_decode($response, true) ?? [],
        ];
    }

    /**
     * POST /send and track the message_id for cleanup.
     */
    private function sendMessage(string $message, string $priority = 'normal'): array
    {
        $result = $this->apiRequest('POST', '/send', [
            'message'  => $message,
            'priority' => $priority,
        ]);

        if (isset($result['body']['message_id'])) {
            $this->createdIds[] = $result['body']['message_id'];
        }

        return $result;
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    public function testMessageAtExactLimitIsAccepted()
    {
        $result = $this->sendMessage(str_repeat('x', 10240));

        $this->assertEquals(201, $result['status'], 'A 10,240-byte message should be accepted');
        $this->assertTrue($result['body']['success'] ?? false);
        $this->assertArrayHasKey('message_id', $result['body']);
    }

    public function testMessageOneByteOverLimitIsRejected()
    {
        $result = $this->sendMessage(str_repeat('x', 10241));

        $this->assertEquals(400, $result['status'], 'A 10,241-byte message should be rejected');
        $this->assertStringContainsString(
            'message exceeds 10240 byte limit (10241 bytes)',
            $result['body']['error'] ?? ''
        );
    }

    public function testMultiByteOverLimit()
    {
        // 3,414 × あ (3 bytes each) = 10,242 bytes
        $message = str_repeat('あ', 3414);
        $this->assertEquals(10242, strlen($message), 'Sanity: 3414 × 3 = 10242 bytes');

        $result = $this->sendMessage($message);

        $this->assertEquals(400, $result['status'], 'Multi-byte message over byte limit should be rejected');
        $this->assertStringContainsString('10242 bytes', $result['body']['error'] ?? '');
    }

    public function testMultiByteJustUnderLimit()
    {
        // 3,413 × あ (3 bytes each) = 10,239 bytes
        $message = str_repeat('あ', 3413);
        $this->assertEquals(10239, strlen($message), 'Sanity: 3413 × 3 = 10239 bytes');

        $result = $this->sendMessage($message);

        $this->assertEquals(201, $result['status'], 'Multi-byte message under byte limit should be accepted');
        $this->assertTrue($result['body']['success'] ?? false);
    }
}
