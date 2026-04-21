<?php

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

class ProjectsListCest
{
    public function fullKeySeesOwnProject(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->sendGET('/api/v1/projects/list');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('Test-Full API project');
    }

    public function missingKeyGets401(AcceptanceTester $I)
    {
        $I->sendGET('/api/v1/projects/list');
        $I->seeResponseCodeIs(401);
    }
}
