<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

/**
 * Webdriver tests for the Create Agent UI (tasks I3, I5).
 *
 * Creates agents under Dr_Hilbert_Space_mgTester (user_id 30).
 * No cleanup — this is test data under our own account.
 */
class CreateAgentCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->loginAsTester();
    }

    public function canCreateAgentViaUI(AcceptanceTester $I)
    {
        $agentName = 'test_ui_agent_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $agentDesc = 'Webdriver test ' . date('Y M d');

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

    public function canCreateAgentWithSendToPermissions(AcceptanceTester $I)
    {
        $agentName = 'test_sendto_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $agentDesc = 'I5 send-to test ' . date('Y M d');

        $I->amOnPage('/settings/');

        // Click the "+ Create Agent" tab
        $I->click('#tab-create');
        $I->waitForElementVisible('#agent-panel-create', 5);

        // Fill in the form
        $I->fillField('#agent_name', $agentName);
        $I->fillField('#agent_description', $agentDesc);

        // Check Read Inbox (already checked by default)
        // Check at least 2 "can send to" actors — select the first two checkboxes
        $I->executeJS("
            var boxes = document.querySelectorAll('input[name=\"can_send_to[]\"]');
            if (boxes.length >= 2) { boxes[0].checked = true; boxes[1].checked = true; }
        ");

        // Submit
        $I->click('input[value="Create Agent"]');

        // Should see success message
        $I->waitForText("Agent '$agentName' created", 5);

        // Verify agent appears in the Existing Agents table
        $I->see($agentName, '#agent-panel-existing');
        $I->see($agentDesc, '#agent-panel-existing');

        // Verify agent appears in the actor dropdown on settings page
        $I->see($agentName, '#gen_aiu_id');

        // Verify agent appears as a selectable recipient on the inbox page
        $I->amOnPage('/inbox/');
        $I->seeInTitle('Agent Inbox');
        $I->waitForElementVisible('#recipient_aiu', 5);
        $I->see($agentName, '#recipient_aiu');
    }
}
