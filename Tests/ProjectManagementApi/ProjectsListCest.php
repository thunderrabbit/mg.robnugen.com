<?php

declare(strict_types=1);

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

class ProjectsListCest
{
    public function fullKeySeesOwnProject(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('X-API-Key', (string) getenv('MG_TEST_KEY_FULL'));
        $I->sendGET('/api/v1/projects/list');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('Test-Full API project');
    }

    public function missingKeyGets401(AcceptanceTester $I): void
    {
        $I->sendGET('/api/v1/projects/list');
        $I->seeResponseCodeIs(401);
    }
}
