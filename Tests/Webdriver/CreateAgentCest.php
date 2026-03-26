<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

/**
 * Webdriver test for the Create Agent UI (task I3).
 *
 * Creates an agent under Dr_Hilbert_Space_mgTester (user_id 30).
 * No cleanup — this is test data under our own account.
 */
class CreateAgentCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->loginAsPaid();
    }

    public function canCreateAgentViaUI(AcceptanceTester $I)
    {
        $agentName = 'test_ui_agent_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $agentDesc = 'Created by Webdriver test';

        $I->amOnPage('/settings/');

        // Click the "+ Create Agent" tab
        $I->click('#tab-create');
        $I->waitForElementVisible('#agent-panel-create', 5);

        // Fill in the form
        $I->fillField('#agent_name', $agentName);
        $I->fillField('#agent_description', $agentDesc);

        // Check Write Inbox (Read Inbox is checked by default)
        $I->checkOption('input[name=can_write_inbox]');

        // Submit
        $I->click('input[value="Create Agent"]');

        // Should see success message after page reload
        $I->waitForText("Agent '$agentName' created", 5);

        // Verify agent appears in the Existing Agents table
        $I->see($agentName, '#agent-panel-existing');
        $I->see($agentDesc, '#agent-panel-existing');

        // Verify agent appears in the actor dropdown in the API Keys section
        $I->see($agentName, '#gen_aiu_id');
    }
}
