<?php

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

class RepositoriesListCest
{
    public function fullKeySeesOwnRepo(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->sendGET('/api/v1/repositories/list');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('test-full-repo');
    }

    public function missingKeyGets401(AcceptanceTester $I)
    {
        $I->sendGET('/api/v1/repositories/list');
        $I->seeResponseCodeIs(401);
    }
}
