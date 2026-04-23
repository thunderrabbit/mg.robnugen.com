<?php

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

/**
 * Verifies /api/v1/inbox/list ordering per issue #98.
 *
 * Strategy: POST N messages with a shared unique marker in the body, then GET
 * /inbox/list with each `order` value. Because `message_id` is monotonically
 * increasing and included as a tiebreaker in every order_clause, back-to-back
 * POSTs give deterministic ordering without needing distinct-second timestamps.
 * Tests filter the response to messages containing the marker and assert the
 * `message_id` sequence matches expectations.
 */
class InboxListCest
{
    private string $marker;
    /** @var int[] message_ids created in the current test, in POST order */
    private array $created = [];

    public function _before(AcceptanceTester $I): void
    {
        $this->marker = '__InboxListCest:' . bin2hex(random_bytes(6)) . '__';
        $this->created = [];
    }

    public function _after(AcceptanceTester $I): void
    {
        // Best-effort cleanup: DELETE each message we created so the test
        // leaves the inbox as it found it. Ignore response codes — if a row
        // is already gone or auth failed, we still want the next test to run.
        foreach ($this->created as $id) {
            $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
            $I->haveHttpHeader('Content-Type', 'application/json');
            $I->sendDELETE('/api/v1/inbox/delete', ['message_id' => $id]);
        }
    }

    /**
     * POSTs a message and records the resulting message_id.
     * Returns the message_id so tests can assert on it.
     */
    private function postMarked(AcceptanceTester $I, string $suffix, string $priority = 'normal'): int
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/inbox/send', json_encode([
            'message'  => $this->marker . ' ' . $suffix,
            'priority' => $priority,
        ]));
        $I->seeResponseCodeIs(201);
        $raw = $I->grabResponse();
        $decoded = json_decode($raw, true);
        $id = (int)($decoded['message_id'] ?? 0);
        if ($id <= 0) {
            $I->fail('Expected message_id in POST /inbox/send response, got: ' . $raw);
        }
        $this->created[] = $id;
        return $id;
    }

    /**
     * GET /inbox/list and return the message_ids of rows whose body contains
     * the current test's marker, preserving response order.
     *
     * @return int[]
     */
    private function grabMarkedIds(AcceptanceTester $I, string $query): array
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->sendGET('/api/v1/inbox/list?' . $query);
        $I->seeResponseCodeIs(200);
        $decoded = json_decode($I->grabResponse(), true);
        $ids = [];
        foreach (($decoded['messages'] ?? []) as $m) {
            if (isset($m['message']) && str_contains($m['message'], $this->marker)) {
                $ids[] = (int)$m['message_id'];
            }
        }
        return $ids;
    }

    public function listDefaultsToNewestFirst(AcceptanceTester $I): void
    {
        $first  = $this->postMarked($I, 'first');
        $second = $this->postMarked($I, 'second');
        $third  = $this->postMarked($I, 'third');

        $ids = $this->grabMarkedIds($I, 'limit=100');
        \PHPUnit\Framework\Assert::assertSame([$third, $second, $first], $ids, 'Default order should be newest-first (reverse of insertion order).');
    }

    public function listOrderUrgentPutsHighPriorityFirst(AcceptanceTester $I): void
    {
        $lowFirst   = $this->postMarked($I, 'low-first',  'low');
        $highMiddle = $this->postMarked($I, 'high',       'high');
        $normalLast = $this->postMarked($I, 'normal-last','normal');

        $urgent = $this->grabMarkedIds($I, 'order=urgent&limit=100');
        \PHPUnit\Framework\Assert::assertSame(
            [$highMiddle, $normalLast, $lowFirst],
            $urgent,
            'order=urgent should group by priority (high, normal, low) and within each tier newest-first.'
        );

        $newest = $this->grabMarkedIds($I, 'limit=100');
        \PHPUnit\Framework\Assert::assertSame(
            [$normalLast, $highMiddle, $lowFirst],
            $newest,
            'Default (newest) should ignore priority and return strictly in reverse insertion order.'
        );
    }

    public function listOrderOldestReversesNewest(AcceptanceTester $I): void
    {
        $a = $this->postMarked($I, 'a');
        $b = $this->postMarked($I, 'b');
        $c = $this->postMarked($I, 'c');

        $newest = $this->grabMarkedIds($I, 'order=newest&limit=100');
        $oldest = $this->grabMarkedIds($I, 'order=oldest&limit=100');

        \PHPUnit\Framework\Assert::assertSame([$c, $b, $a], $newest);
        \PHPUnit\Framework\Assert::assertSame([$a, $b, $c], $oldest);
        \PHPUnit\Framework\Assert::assertSame(array_reverse($newest), $oldest, 'order=oldest should be the exact reverse of order=newest on the same data.');
    }

    public function listInvalidOrderFallsBackToNewest(AcceptanceTester $I): void
    {
        $x = $this->postMarked($I, 'x');
        $y = $this->postMarked($I, 'y');

        $ids = $this->grabMarkedIds($I, 'order=nonsense&limit=100');
        \PHPUnit\Framework\Assert::assertSame([$y, $x], $ids, 'Invalid order values should silently fall back to newest (not crash, not use some undefined order).');
    }
}
