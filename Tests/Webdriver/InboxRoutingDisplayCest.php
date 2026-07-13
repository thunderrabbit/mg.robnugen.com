<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

/**
 * Webdriver test for inbox sender/recipient display (task J5).
 *
 * Verifies that sent messages show correct "from" and "to" badges.
 *
 * Used to also cover the "broadcast" label on broadcast messages, but that
 * coverage was dropped along with can_broadcast_inbox gating: there's no
 * staging system, so this suite runs against production, and the broadcast
 * option no longer appears in the UI for accounts without the (now
 * default-off) permission — see VisibilityAndRoutingTest for the
 * broadcast-denial coverage that replaced it.
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
}
