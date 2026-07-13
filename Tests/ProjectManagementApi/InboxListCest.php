<?php

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

/**
 * Verifies /api/v1/inbox/list ordering per issue #98.
 *
 * Strategy: POST N messages (directed to ALPHA — see note below) with a
 * shared unique marker in the body, then GET /inbox/list with each `order`
 * value. Because `message_id` is monotonically increasing and included as a
 * tiebreaker in every order_clause, back-to-back POSTs give deterministic
 * ordering without needing distinct-second timestamps. Tests filter the
 * response to messages containing the marker and assert the `message_id`
 * sequence matches expectations.
 *
 * Directed to ALPHA, not broadcast: BETA (the writer key here) intentionally
 * lacks can_broadcast_inbox in VisibilityAndRoutingTest's fixture — that
 * absence is what proves the broadcast permission gate works. Sending
 * broadcasts from BETA here would either fail (403) or require weakening
 * that fixture. BETA already has a can_send grant straight to ALPHA, and
 * ALPHA already reads messages addressed to itself (see
 * VisibilityAndRoutingTest Test 2/7), so a directed send exercises the same
 * ordering behavior without touching broadcast at all.
 *
 * ── Three-key dance (intentional, read before "simplifying") ────────────────
 *
 * POST + DELETE run under MG_TEST_KEY_BETA (test_beta, aiu 14). GET runs under
 * MG_TEST_KEY_ALPHA (test_alpha, aiu 13). Neither key has supervisor
 * inbox_visibility rows, and each has exactly one of (can_write_inbox,
 * can_read_inbox) set — so neither standalone can do both sides. That's on
 * purpose here, not a blocker to work around:
 *
 *   - BETA as writer means every row this test inserts has `sender_aiu = 14`.
 *     /inbox/delete's auth clause is `sender_aiu = caller OR recipient_aiu =
 *     caller OR (caller is supervisor)`. With BETA non-supervisor, the ONLY
 *     branch that fires is `sender_aiu = 14`. If a stray id ever leaked into
 *     $this->created (future refactor, server bug, cosmic ray) the server
 *     refuses to DELETE any row we didn't send. Server-side safety net.
 *
 *   - ALPHA as reader means GET /inbox/list runs without supervisor bypass.
 *     ALPHA reads messages addressed to itself via its own peer-read grant
 *     (recipient_aiu = ALPHA passes the visibility filter), which is all
 *     directed BETA→ALPHA sends need — no broadcast involved. Both actors
 *     live on user_id=30 (the Dr-Hilbert test sandbox), so the whole thing
 *     stays inside an account separate from Rob's primary user_id=1 —
 *     additional isolation at the account level.
 *
 * MG_TEST_KEY_FULL (Dr_Hilbert_Space_mgTester, aiu 11) is NOT used here even
 * though it's more convenient, because it has a NULL-peer supervisor row on
 * inbox_visibility. Under FULL, /inbox/delete's EXISTS-supervisor branch
 * always fires, and the only remaining WHERE filter is `message_id = ?` —
 * meaning a single bad id in $this->created would delete someone else's
 * message (bounded to user_id=30, but still). BETA+ALPHA removes that footgun.
 *
 * Supervisor access legitimately matters for triage/admin use cases; it just
 * shouldn't be what guards a destructive test teardown.
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
        // Cleanup: DELETE each message we created. Server authorizes on
        // sender_aiu = caller (BETA = 14) since BETA has no supervisor row,
        // so a leaked foreign id would 404-silently rather than succeed.
        // That's the second layer of defense — the first is that
        // $this->created is only written by postMarked() from 201 responses
        // to our own POSTs, so in practice the array never holds a foreign id.
        foreach ($this->created as $id) {
            $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_BETA'));
            $I->haveHttpHeader('Content-Type', 'application/json');
            $I->sendDELETE('/api/v1/inbox/delete', ['message_id' => $id]);
        }
    }

    /**
     * POSTs a message from BETA directed to ALPHA, records the resulting
     * message_id. Directed (not broadcast) — see class docblock for why.
     */
    private function postMarked(AcceptanceTester $I, string $suffix, string $priority = 'normal'): int
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_BETA'));
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/inbox/send', json_encode([
            'message'       => $this->marker . ' ' . $suffix,
            'priority'      => $priority,
            'recipient_aiu' => (int) getenv('MG_TEST_AIU_ALPHA'),
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
     * GETs /inbox/list as ALPHA (non-supervisor reader) and returns
     * message_ids of rows whose body contains the current test's marker,
     * preserving response order.
     *
     * @return int[]
     */
    private function grabMarkedIds(AcceptanceTester $I, string $query): array
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_ALPHA'));
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
