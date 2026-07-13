<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

/**
 * Webdriver test for inbox sender/recipient display (task J5).
 *
 * Verifies that sent messages show correct "from" and "to" badges,
 * and that broadcast messages show a "broadcast" label.
 *
 * loginAsTester logs in as Dr_Hilbert_Space_mgTester (aiu 11 — same actor as
 * VisibilityAndRoutingTest's FULL), which requires can_broadcast_inbox=1 for
 * canSeeBroadcastLabelOnBroadcastMessage() to have a "Broadcast (all)"
 * option to select in the first place.
 *
 * No cleanup — this is test data under our own account.
 */
class InboxRoutingDisplayCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->loginAsTester();
    }

    public function canSeeFromAndToBadgesOnDirectMessage(AcceptanceTester $I)
    {
        $tag = 'routing_display_' . bin2hex(random_bytes(4));

        $I->amOnPage('/inbox/');
        $I->seeInTitle('Agent Inbox');
        $I->waitForElementVisible('#send-form', 5);

        // Pick a specific recipient (not broadcast) — select the 2nd option (index 1)
        $I->executeJS("document.getElementById('recipient_aiu').selectedIndex = 1;");

        $I->fillField('#message', "Test direct message $tag");
        $I->click('#send-btn');

        // Page reloads after send
        $I->waitForText($tag, 10);

        // Verify sender badge
        $I->see('from', '.inbox-sender');

        // Verify recipient badge shows "to [name]"
        // Find the message containing our tag and check its badges
        $I->see($tag, '.inbox-message');
        $I->see('to', '.inbox-recipient');
    }

    public function canSeeBroadcastLabelOnBroadcastMessage(AcceptanceTester $I)
    {
        $tag = 'routing_broadcast_' . bin2hex(random_bytes(4));

        $I->amOnPage('/inbox/');
        $I->seeInTitle('Agent Inbox');
        $I->waitForElementVisible('#send-form', 5);

        // Leave recipient as broadcast (default empty value)
        $I->selectOption('#recipient_aiu', '');

        $I->fillField('#message', "Test broadcast message $tag");
        $I->click('#send-btn');

        // Page reloads after send
        $I->waitForText($tag, 10);

        // Verify broadcast label appears instead of a recipient name
        $I->see('broadcast', '.inbox-broadcast');
    }
}
