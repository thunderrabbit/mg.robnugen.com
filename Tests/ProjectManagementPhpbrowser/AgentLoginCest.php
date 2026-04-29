<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage for /login/agent/ (issue #122).
 *
 * Requires env vars (set up via scripts/set_aiu_password.php on a test aiu):
 *   MG_AGENT_USER_ID, MG_AGENT_AIU_ID, MG_AGENT_PASS
 */
class AgentLoginCest
{
    public function formRendersForGuest(AcceptanceTester $I)
    {
        $I->amOnPage('/login/agent/');
        $I->see('Agent Log In', 'h2');
        $I->seeElement('input[name="user_id"]');
        $I->seeElement('input[name="aiu_id"]');
        $I->seeElement('input[name="password"]');
    }

    public function rejectsMissingFields(AcceptanceTester $I)
    {
        $I->amOnPage('/login/agent/');
        // Bypass the browser's required attribute by submitting via direct POST
        $I->submitForm('form', [
            'user_id'  => '',
            'aiu_id'   => '',
            'password' => '',
        ]);
        $I->see('All fields are required');
    }

    public function rejectsBadCredentials(AcceptanceTester $I)
    {
        $I->loginAgent(99999999, 99999999, str_repeat('z', 100), false);
        $I->see('Invalid credentials');
        $I->seeInCurrentUrl('/login/agent/');
    }

    public function acceptsGoodCredentialsAndRedirects(AcceptanceTester $I)
    {
        $I->loginAsAgent();
        $I->seeInCurrentUrl('/projects/');
    }

    public function agentSessionIsNotAdmin(AcceptanceTester $I)
    {
        // Agents must never inherit admin powers, even though their parent
        // user_id may be role='admin'. Hitting an admin-only page should
        // redirect rather than render.
        $I->loginAsAgent();
        $I->amOnPage('/admin/');
        $I->dontSeeInCurrentUrl('/admin/');
    }
}
