<?php

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

class AgentsListCest
{
    public function agentsListIncludesProjectPermBits(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->sendGET('/api/v1/agents');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('can_read_project');
        $I->seeResponseContains('can_write_project');
    }
}
